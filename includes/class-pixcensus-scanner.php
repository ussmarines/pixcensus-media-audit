<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scanner for image usage in content, meta, options and common builders.
 */
class PIXCENSUS_Scanner {
	private const ATTACHMENT_BATCH_SIZE = 200;
	private const OPTION_BATCH_SIZE     = 500;
	private const POST_BATCH_SIZE       = 200;
	private const TERM_BATCH_SIZE       = 200;
	private const MAX_WALK_DEPTH        = 64;
	private const MAX_WALK_NODES        = 10000;

	/**
	 * Provenance indexed by attachment ID.
	 *
	 * @var array<int, array<int, string>>
	 */
	private $provenance = array();

	/**
	 * CDN rewrite rules.
	 *
	 * @var array<int, array<string, string>>
	 */
	private $cdn_rewrites = array();

	/**
	 * CDN alias domains.
	 *
	 * @var array<int, string>
	 */
	private $cdn_aliases = array();

	/**
	 * Run the scan.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$pixcensus_include_drafts = '1' === get_option( 'pixcensus_include_drafts', '1' );

		$this->load_cdn_settings();

		$pixcensus_attachment_ids = $this->get_image_attachment_ids();

		if ( empty( $pixcensus_attachment_ids ) ) {
			return array(
				'used_ids'       => array(),
				'draft_only_ids' => array(),
				'unused_ids'     => array(),
				'orphans'        => array(),
				'scanned_at'     => time(),
				'include_drafts' => $pixcensus_include_drafts,
				'provenance'     => array(),
			);
		}

		$pixcensus_uploads = wp_get_upload_dir();
		$pixcensus_basedir = isset( $pixcensus_uploads['basedir'] ) ? (string) $pixcensus_uploads['basedir'] : '';
		$pixcensus_map     = $this->build_attachment_map( $pixcensus_attachment_ids );

		$pixcensus_used_in_published = array();
		$pixcensus_used_in_drafts    = array();

		$pixcensus_published_statuses = array( 'publish', 'private' );
		$pixcensus_draft_statuses     = array( 'draft', 'pending', 'future' );

		$this->scan_post_contents( $pixcensus_used_in_published, $pixcensus_published_statuses, $pixcensus_map );
		$this->scan_meta_references( $pixcensus_used_in_published, $pixcensus_published_statuses );
		$this->scan_builder_metas( $pixcensus_used_in_published, $pixcensus_published_statuses, $pixcensus_map );
		$this->scan_any_meta_uploads( $pixcensus_used_in_published, $pixcensus_published_statuses, $pixcensus_map );
		$this->scan_terms( $pixcensus_used_in_published, $pixcensus_map );
		$this->scan_options_for_uploads( $pixcensus_used_in_published, $pixcensus_map );
		$this->scan_site_identity( $pixcensus_used_in_published );

		if ( $pixcensus_include_drafts ) {
			$this->scan_post_contents( $pixcensus_used_in_drafts, $pixcensus_draft_statuses, $pixcensus_map );
			$this->scan_meta_references( $pixcensus_used_in_drafts, $pixcensus_draft_statuses );
			$this->scan_builder_metas( $pixcensus_used_in_drafts, $pixcensus_draft_statuses, $pixcensus_map );
			$this->scan_any_meta_uploads( $pixcensus_used_in_drafts, $pixcensus_draft_statuses, $pixcensus_map );
		}

		$pixcensus_all_ids   = array_map( 'intval', $pixcensus_attachment_ids );
		$pixcensus_used_ids  = array_values( array_intersect( array_map( 'intval', array_keys( $pixcensus_used_in_published ) ), $pixcensus_all_ids ) );
		$pixcensus_draft_ids = array_values( array_intersect( array_map( 'intval', array_keys( $pixcensus_used_in_drafts ) ), $pixcensus_all_ids ) );

		$pixcensus_draft_only_ids = array_values( array_diff( $pixcensus_draft_ids, $pixcensus_used_ids ) );
		$pixcensus_unused_ids     = array_values( array_diff( $pixcensus_all_ids, array_unique( array_merge( $pixcensus_used_ids, $pixcensus_draft_only_ids ) ) ) );

		$pixcensus_manual_ids = array_values(
			array_intersect(
				array_map( 'intval', (array) get_option( 'pixcensus_manual_used_ids', array() ) ),
				$pixcensus_all_ids
			)
		);

		if ( ! empty( $pixcensus_manual_ids ) ) {
			$pixcensus_used_ids       = array_values( array_unique( array_merge( $pixcensus_used_ids, $pixcensus_manual_ids ) ) );
			$pixcensus_draft_only_ids = array_values( array_diff( $pixcensus_draft_only_ids, $pixcensus_manual_ids ) );
			$pixcensus_unused_ids     = array_values( array_diff( $pixcensus_unused_ids, $pixcensus_manual_ids ) );
		}

		sort( $pixcensus_used_ids, SORT_NUMERIC );
		sort( $pixcensus_draft_only_ids, SORT_NUMERIC );
		sort( $pixcensus_unused_ids, SORT_NUMERIC );

		return array(
			'used_ids'       => $pixcensus_used_ids,
			'draft_only_ids' => $pixcensus_draft_only_ids,
			'unused_ids'     => $pixcensus_unused_ids,
			'orphans'        => $this->find_orphans( $pixcensus_attachment_ids, $pixcensus_basedir ),
			'scanned_at'     => time(),
			'include_drafts' => $pixcensus_include_drafts,
			'provenance'     => $this->get_provenance_output(),
		);
	}

	/**
	 * Load CDN-related settings.
	 *
	 * @return void
	 */
	private function load_cdn_settings(): void {
		$pixcensus_cdn_rewrites_raw = get_option( 'pixcensus_cdn_rewrites', '' );
		$pixcensus_cdn_aliases_raw  = get_option( 'pixcensus_cdn_aliases', '' );
		$pixcensus_validated        = PIXCENSUS_CDN_Settings::validate( (string) $pixcensus_cdn_aliases_raw, (string) $pixcensus_cdn_rewrites_raw );

		$this->cdn_rewrites = array();

		if ( $pixcensus_validated['valid'] && '' !== trim( $pixcensus_validated['rewrites'] ) ) {
			$pixcensus_lines = preg_split( '/\r?\n/', $pixcensus_validated['rewrites'] );

			if ( is_array( $pixcensus_lines ) ) {
				foreach ( $pixcensus_lines as $pixcensus_line ) {
					if ( false === strpos( $pixcensus_line, '=>' ) ) {
						continue;
					}

					list( $pixcensus_from, $pixcensus_to ) = array_map( 'trim', explode( '=>', $pixcensus_line, 2 ) );

					if ( '' === $pixcensus_from || '' === $pixcensus_to ) {
						continue;
					}

					$this->cdn_rewrites[] = array(
						'from' => $pixcensus_from,
						'to'   => $pixcensus_to,
					);
				}
			}
		}

		$this->cdn_aliases = array_values(
			array_filter(
				array_map(
					'trim',
					explode( ',', $pixcensus_validated['valid'] ? $pixcensus_validated['aliases'] : '' )
				)
			)
		);
	}

	/**
	 * Return all image attachment IDs.
	 *
	 * @return array<int, int>
	 */
	private function get_image_attachment_ids(): array {
		$pixcensus_image_mime_types = array_values(
			array_unique(
				array_filter(
					get_allowed_mime_types(),
					static function ( string $pixcensus_mime_type ): bool {
						return 0 === strpos( $pixcensus_mime_type, 'image/' );
					}
				)
			)
		);

		$pixcensus_attachment_ids = array();
		$pixcensus_page           = 1;

		do {
			$pixcensus_batch = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_mime_type'         => $pixcensus_image_mime_types,
					'posts_per_page'         => self::ATTACHMENT_BATCH_SIZE,
					'paged'                  => $pixcensus_page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			if ( ! is_array( $pixcensus_batch ) || empty( $pixcensus_batch ) ) {
				break;
			}

			$pixcensus_attachment_ids = array_merge( $pixcensus_attachment_ids, array_map( 'intval', $pixcensus_batch ) );
			$pixcensus_batch_count    = count( $pixcensus_batch );
			++$pixcensus_page;
		} while ( self::ATTACHMENT_BATCH_SIZE === $pixcensus_batch_count );

		return array_values( array_unique( $pixcensus_attachment_ids ) );
	}

	/**
	 * Build attachment path map for originals and generated sizes.
	 *
	 * @param array<int, int> $attachment_ids Attachment IDs.
	 * @return array<string, int>
	 */
	private function build_attachment_map( array $attachment_ids ): array {
		$pixcensus_map = array();

		foreach ( $attachment_ids as $pixcensus_attachment_id ) {
			$pixcensus_relative_file = get_post_meta( $pixcensus_attachment_id, '_wp_attached_file', true );

			if ( empty( $pixcensus_relative_file ) ) {
				continue;
			}

			$pixcensus_relative_file                   = ltrim( (string) $pixcensus_relative_file, '/\\' );
			$pixcensus_map[ $pixcensus_relative_file ] = $pixcensus_attachment_id;

			$pixcensus_metadata = wp_get_attachment_metadata( $pixcensus_attachment_id );

			if ( ! empty( $pixcensus_metadata['sizes'] ) && is_array( $pixcensus_metadata['sizes'] ) ) {
				$pixcensus_directory = dirname( $pixcensus_relative_file );
				$pixcensus_directory = ( '.' === $pixcensus_directory ) ? '' : trailingslashit( $pixcensus_directory );

				foreach ( $pixcensus_metadata['sizes'] as $pixcensus_size ) {
					if ( ! empty( $pixcensus_size['file'] ) ) {
						$pixcensus_map[ $pixcensus_directory . $pixcensus_size['file'] ] = $pixcensus_attachment_id;
					}
				}
			}
		}

		return $pixcensus_map;
	}

	/**
	 * Scan post_content for usage.
	 *
	 * @param array<int, bool>   $used_map Usage map.
	 * @param array<int, string> $statuses Allowed statuses.
	 * @param array<string, int> $path_map Attachment path map.
	 * @return void
	 */
	private function scan_post_contents( array &$used_map, array $statuses, array $path_map ): void {
		$pixcensus_page = 1;

		do {
			$pixcensus_posts = $this->get_scannable_posts(
				$statuses,
				array(
					'update_post_meta_cache' => false,
				),
				$pixcensus_page
			);

			foreach ( $pixcensus_posts as $pixcensus_post ) {
				$pixcensus_post_id = (int) $pixcensus_post->ID;
				$pixcensus_content = $this->normalize_text_rewrites( (string) $pixcensus_post->post_content );

				if ( '' === $pixcensus_content ) {
					continue;
				}

				if ( preg_match_all( '/wp-image-(\d+)/', $pixcensus_content, $pixcensus_matches ) ) {
					foreach ( $pixcensus_matches[1] as $pixcensus_match ) {
						$pixcensus_attachment_id = (int) $pixcensus_match;

						if ( $pixcensus_attachment_id > 0 ) {
							$used_map[ $pixcensus_attachment_id ] = true;
							$this->add_provenance( $pixcensus_attachment_id, 'post:' . $pixcensus_post_id . ' content:wp-image' );
						}
					}
				}

				$this->scan_text_for_attachment_ids( $pixcensus_content, $used_map, 'post:' . $pixcensus_post_id . ' content' );
				$this->scan_text_for_uploads( $pixcensus_content, $path_map, $used_map, 'post:' . $pixcensus_post_id . ' content:url' );
			}

			$pixcensus_batch_count = count( $pixcensus_posts );
			++$pixcensus_page;
		} while ( self::POST_BATCH_SIZE === $pixcensus_batch_count );
	}

	/**
	 * Scan featured image and common gallery references.
	 *
	 * @param array<int, bool>   $used_map Usage map.
	 * @param array<int, string> $statuses Allowed statuses.
	 * @return void
	 */
	private function scan_meta_references( array &$used_map, array $statuses ): void {
		$pixcensus_page = 1;

		do {
			$pixcensus_post_ids = $this->get_scannable_posts(
				$statuses,
				array(
					'fields'                 => 'ids',
					'update_post_meta_cache' => true,
				),
				$pixcensus_page
			);

			foreach ( $pixcensus_post_ids as $pixcensus_post_id ) {
				$pixcensus_post_id       = (int) $pixcensus_post_id;
				$pixcensus_attachment_id = (int) get_post_thumbnail_id( $pixcensus_post_id );

				if ( $pixcensus_attachment_id > 0 ) {
					$used_map[ $pixcensus_attachment_id ] = true;
					$this->add_provenance( $pixcensus_attachment_id, 'post:' . $pixcensus_post_id . ' meta:_thumbnail_id' );
				}

				$pixcensus_gallery_ids = array_filter(
					array_map(
						'intval',
						explode( ',', (string) get_post_meta( $pixcensus_post_id, '_product_image_gallery', true ) )
					)
				);

				foreach ( $pixcensus_gallery_ids as $pixcensus_gallery_id ) {
					$used_map[ $pixcensus_gallery_id ] = true;
					$this->add_provenance( $pixcensus_gallery_id, 'post:' . $pixcensus_post_id . ' meta:_product_image_gallery' );
				}
			}

			$pixcensus_batch_count = count( $pixcensus_post_ids );
			++$pixcensus_page;
		} while ( self::POST_BATCH_SIZE === $pixcensus_batch_count );
	}

	/**
	 * Scan builder-specific metas.
	 *
	 * @param array<int, bool>   $used_map Usage map.
	 * @param array<int, string> $statuses Allowed statuses.
	 * @param array<string, int> $path_map Attachment path map.
	 * @return void
	 */
	private function scan_builder_metas( array &$used_map, array $statuses, array $path_map ): void {
		$pixcensus_builder_keys = array(
			'_elementor_data',
			'_elementor_draft',
			'_elementor_page_settings',
			'_elementor_css',
			'_fl_builder_data',
			'_fl_builder_draft',
			'fl_builder_data',
			'fl_builder_data_settings',
			'ct_builder_shortcodes',
			'panels_data',
			'_bricks_page_content',
			'_bricks_page_content_2',
			'_wpb_shortcodes_custom_css',
			'_wpb_shortcodes_css',
			'_et_pb_shortcodes',
		);

		$pixcensus_page = 1;

		do {
			$pixcensus_post_ids = $this->get_scannable_posts(
				$statuses,
				array(
					'fields'                 => 'ids',
					'update_post_meta_cache' => true,
				),
				$pixcensus_page
			);

			foreach ( $pixcensus_post_ids as $pixcensus_post_id ) {
				$pixcensus_post_id = (int) $pixcensus_post_id;

				foreach ( $pixcensus_builder_keys as $pixcensus_meta_key ) {
					$pixcensus_values = get_post_meta( $pixcensus_post_id, $pixcensus_meta_key, false );

					if ( empty( $pixcensus_values ) ) {
						continue;
					}

					foreach ( $pixcensus_values as $pixcensus_value ) {
						$pixcensus_context = 'post:' . $pixcensus_post_id . ' meta:' . $pixcensus_meta_key;

						$this->scan_value_for_uploads( $pixcensus_value, $path_map, $used_map, $pixcensus_context );
						$this->scan_builder_value_for_ids( $pixcensus_value, $used_map, $pixcensus_context );
					}
				}
			}

			$pixcensus_batch_count = count( $pixcensus_post_ids );
			++$pixcensus_page;
		} while ( self::POST_BATCH_SIZE === $pixcensus_batch_count );
	}

	/**
	 * Scan all post meta likely to contain URLs.
	 *
	 * @param array<int, bool>   $used_map Usage map.
	 * @param array<int, string> $statuses Allowed statuses.
	 * @param array<string, int> $path_map Attachment path map.
	 * @return void
	 */
	private function scan_any_meta_uploads( array &$used_map, array $statuses, array $path_map ): void {
		$pixcensus_page = 1;

		do {
			$pixcensus_post_ids = $this->get_scannable_posts(
				$statuses,
				array(
					'fields'                 => 'ids',
					'update_post_meta_cache' => true,
				),
				$pixcensus_page
			);

			foreach ( $pixcensus_post_ids as $pixcensus_post_id ) {
				$pixcensus_all_meta = get_post_meta( (int) $pixcensus_post_id );

				if ( empty( $pixcensus_all_meta ) || ! is_array( $pixcensus_all_meta ) ) {
					continue;
				}

				foreach ( $pixcensus_all_meta as $pixcensus_meta_key => $pixcensus_meta_values ) {
					foreach ( (array) $pixcensus_meta_values as $pixcensus_meta_value ) {
						if ( ! $this->value_might_reference_uploads( $pixcensus_meta_value ) ) {
							continue;
						}

						$this->scan_value_for_uploads(
							$pixcensus_meta_value,
							$path_map,
							$used_map,
							'post:' . (int) $pixcensus_post_id . ' meta:' . (string) $pixcensus_meta_key
						);
					}
				}
			}

			$pixcensus_batch_count = count( $pixcensus_post_ids );
			++$pixcensus_page;
		} while ( self::POST_BATCH_SIZE === $pixcensus_batch_count );
	}

	/**
	 * Scan options for usage references.
	 *
	 * @param array<int, bool>   $used_map Usage map.
	 * @param array<string, int> $path_map Attachment path map.
	 * @return void
	 */
	private function scan_options_for_uploads( array &$used_map, array $path_map ): void {
		global $wpdb;
		$pixcensus_last_option_id = 0;
		$pixcensus_excluded       = array(
			'pixcensus_usage_results',
			'pixcensus_include_drafts',
			'pixcensus_manual_used_ids',
			'pixcensus_cdn_aliases',
			'pixcensus_cdn_rewrites',
			'pixcensus_scan_lock',
		);

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options enumeration is required for the audit and WordPress has no API to list all options.
			$pixcensus_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d ORDER BY option_id ASC LIMIT 500",
					$pixcensus_last_option_id
				)
			);

			if ( ! is_array( $pixcensus_rows ) || empty( $pixcensus_rows ) ) {
				break;
			}

			foreach ( $pixcensus_rows as $pixcensus_row ) {
				$pixcensus_last_option_id = isset( $pixcensus_row->option_id ) ? (int) $pixcensus_row->option_id : $pixcensus_last_option_id;
				$pixcensus_option_name    = isset( $pixcensus_row->option_name ) ? (string) $pixcensus_row->option_name : '';
				$pixcensus_option_value   = isset( $pixcensus_row->option_value ) ? (string) $pixcensus_row->option_value : '';

				if ( '' === $pixcensus_option_name || in_array( $pixcensus_option_name, $pixcensus_excluded, true ) || ! $this->text_might_reference_uploads( $pixcensus_option_value ) ) {
					continue;
				}

				$this->scan_text_for_uploads( $pixcensus_option_value, $path_map, $used_map, 'option:' . $pixcensus_option_name );
			}

			$pixcensus_row_count = count( $pixcensus_rows );
		} while ( self::OPTION_BATCH_SIZE === $pixcensus_row_count );
	}

	/**
	 * Scan term descriptions.
	 *
	 * @param array<int, bool>   $used_map Usage map.
	 * @param array<string, int> $path_map Attachment path map.
	 * @return void
	 */
	private function scan_terms( array &$used_map, array $path_map ): void {
		$pixcensus_offset = 0;

		do {
			$pixcensus_terms = get_terms(
				array(
					'taxonomy'   => get_taxonomies( array(), 'names' ),
					'hide_empty' => false,
					'number'     => self::TERM_BATCH_SIZE,
					'offset'     => $pixcensus_offset,
				)
			);

			if ( is_wp_error( $pixcensus_terms ) || ! is_array( $pixcensus_terms ) || empty( $pixcensus_terms ) ) {
				break;
			}

			foreach ( $pixcensus_terms as $pixcensus_term ) {
				if ( empty( $pixcensus_term->description ) ) {
					continue;
				}

				$this->scan_text_for_uploads( (string) $pixcensus_term->description, $path_map, $used_map, 'term:' . (int) $pixcensus_term->term_id . ' description' );
			}

			$pixcensus_term_count = count( $pixcensus_terms );
			$pixcensus_offset    += $pixcensus_term_count;
		} while ( self::TERM_BATCH_SIZE === $pixcensus_term_count );
	}

	/**
	 * Scan site identity settings.
	 *
	 * @param array<int, bool> $used_map Usage map.
	 * @return void
	 */
	private function scan_site_identity( array &$used_map ): void {
		$pixcensus_site_icon_id = (int) get_option( 'site_icon' );

		if ( $pixcensus_site_icon_id > 0 ) {
			$used_map[ $pixcensus_site_icon_id ] = true;
			$this->add_provenance( $pixcensus_site_icon_id, 'site_icon' );
		}

		$pixcensus_custom_logo_id = (int) get_theme_mod( 'custom_logo' );

		if ( $pixcensus_custom_logo_id > 0 ) {
			$used_map[ $pixcensus_custom_logo_id ] = true;
			$this->add_provenance( $pixcensus_custom_logo_id, 'custom_logo' );
		}
	}

	/**
	 * Return the post types relevant to the scan.
	 *
	 * @return array<int, string>
	 */
	private function get_scannable_post_types(): array {
		$pixcensus_post_types = array_map( 'strval', get_post_types( array(), 'names' ) );

		return array_values(
			array_diff(
				$pixcensus_post_types,
				array(
					'attachment',
					'revision',
					'nav_menu_item',
				)
			)
		);
	}

	/**
	 * Return posts relevant to the scan.
	 *
	 * @param array<int, string> $statuses Allowed statuses.
	 * @param array<string, mixed> $args Additional query arguments.
	 * @param int $page Page number.
	 * @return array<int, mixed>
	 */
	private function get_scannable_posts( array $statuses, array $args = array(), int $page = 1 ): array {
		$pixcensus_post_types = $this->get_scannable_post_types();
		$pixcensus_statuses   = array_values( array_unique( array_map( 'sanitize_key', $statuses ) ) );

		if ( empty( $pixcensus_post_types ) || empty( $pixcensus_statuses ) ) {
			return array();
		}

		$pixcensus_defaults = array(
			'post_type'              => $pixcensus_post_types,
			'post_status'            => $pixcensus_statuses,
			'posts_per_page'         => self::POST_BATCH_SIZE,
			'paged'                  => max( 1, $page ),
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
		);

		$pixcensus_posts = get_posts( array_merge( $pixcensus_defaults, $args ) );

		return is_array( $pixcensus_posts ) ? $pixcensus_posts : array();
	}

	/**
	 * Scan block comments and shortcodes for explicit attachment IDs.
	 *
	 * @param string           $text Text content.
	 * @param array<int, bool> $used_map Usage map.
	 * @param string           $context Provenance label prefix.
	 * @return void
	 */
	private function scan_text_for_attachment_ids( string $text, array &$used_map, string $context ): void {
		$pixcensus_patterns = array(
			'block:image' => '/<!--\s+wp:image\s+\{[^}]*"id"\s*:\s*(\d+)/i',
			'shortcode'   => '/\[[^\]]+\b(?:ids?|image_id|attachment_id)\s*=\s*["\']?([0-9,\s]+)/i',
		);

		foreach ( $pixcensus_patterns as $pixcensus_source => $pixcensus_pattern ) {
			if ( ! preg_match_all( $pixcensus_pattern, $text, $pixcensus_matches ) ) {
				continue;
			}

			foreach ( $pixcensus_matches[1] as $pixcensus_match ) {
				$pixcensus_raw_ids = preg_split( '/[\s,]+/', (string) $pixcensus_match );

				if ( ! is_array( $pixcensus_raw_ids ) ) {
					continue;
				}

				foreach ( $pixcensus_raw_ids as $pixcensus_raw_id ) {
					$pixcensus_attachment_id = (int) $pixcensus_raw_id;

					if ( $pixcensus_attachment_id <= 0 ) {
						continue;
					}

					$used_map[ $pixcensus_attachment_id ] = true;
					$this->add_provenance( $pixcensus_attachment_id, $context . ':' . $pixcensus_source );
				}
			}
		}
	}

	/**
	 * Scan arbitrary text for uploads references.
	 *
	 * @param string            $text Text content.
	 * @param array<string, int> $path_map Attachment path map.
	 * @param array<int, bool>  $used_map Usage map.
	 * @param string            $context Provenance label.
	 * @return void
	 */
	private function scan_text_for_uploads( string $text, array $path_map, array &$used_map, string $context = '' ): void {
		$pixcensus_text = $this->normalize_text_rewrites( $text );

		if ( '' === $pixcensus_text ) {
			return;
		}

		if ( preg_match_all( '#(?:^|[^A-Za-z0-9_-])/?wp-content/uploads/([^\s"\')<>,]+)#i', $pixcensus_text, $pixcensus_matches ) ) {
			foreach ( $pixcensus_matches[1] as $pixcensus_relative_file ) {
				$pixcensus_relative_file = preg_replace( '/[?#].*$/', '', (string) $pixcensus_relative_file );
				$pixcensus_relative_file = ltrim( rtrim( (string) $pixcensus_relative_file, '\\]}' ), '/\\' );

				if ( isset( $path_map[ $pixcensus_relative_file ] ) ) {
					$pixcensus_attachment_id              = $path_map[ $pixcensus_relative_file ];
					$used_map[ $pixcensus_attachment_id ] = true;

					if ( '' !== $context ) {
						$this->add_provenance( $pixcensus_attachment_id, $context );
					}
				}
			}
		}
	}

	/**
	 * Scan any meta or option value for upload references.
	 *
	 * @param mixed              $value Meta or option value.
	 * @param array<string, int> $path_map Attachment path map.
	 * @param array<int, bool>   $used_map Usage map.
	 * @param string             $context Provenance label.
	 * @return void
	 */
	private function scan_value_for_uploads( $value, array $path_map, array &$used_map, string $context ): void {
		foreach ( $this->flatten_scan_strings( $value ) as $pixcensus_text ) {
			if ( '' === $pixcensus_text || ! $this->text_might_reference_uploads( $pixcensus_text ) ) {
				continue;
			}

			$this->scan_text_for_uploads( $pixcensus_text, $path_map, $used_map, $context );
		}
	}

	/**
	 * Scan builder values for image IDs nested in JSON-like structures.
	 *
	 * @param mixed            $value Builder value.
	 * @param array<int, bool> $used_map Usage map.
	 * @param string           $context Provenance label.
	 * @return void
	 */
	private function scan_builder_value_for_ids( $value, array &$used_map, string $context ): void {
		foreach ( $this->walk_scan_values( $value ) as $pixcensus_entry ) {
			$pixcensus_key          = $pixcensus_entry['key'];
			$pixcensus_nested_value = $pixcensus_entry['value'];

			if ( null !== $pixcensus_key && 'id' === strtolower( (string) $pixcensus_key ) && is_scalar( $pixcensus_nested_value ) ) {
				$pixcensus_attachment_id = (int) $pixcensus_nested_value;

				if ( $pixcensus_attachment_id > 0 ) {
					$used_map[ $pixcensus_attachment_id ] = true;
					$this->add_provenance( $pixcensus_attachment_id, $context . ' array:id' );
				}
			}

			if ( ! is_string( $pixcensus_nested_value ) || '' === $pixcensus_nested_value ) {
				continue;
			}

			if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $pixcensus_nested_value, $pixcensus_matches ) ) {
				foreach ( $pixcensus_matches[1] as $pixcensus_match ) {
					$pixcensus_attachment_id = (int) $pixcensus_match;

					if ( $pixcensus_attachment_id > 0 ) {
						$used_map[ $pixcensus_attachment_id ] = true;
						$this->add_provenance( $pixcensus_attachment_id, $context . ' json:id' );
					}
				}
			}
		}
	}

	/**
	 * Flatten a value into searchable strings.
	 *
	 * @param mixed $value Value to flatten.
	 * @return array<int, string>
	 */
	private function flatten_scan_strings( $value ): array {
		$pixcensus_strings = array();

		foreach ( $this->walk_scan_values( $value ) as $pixcensus_entry ) {
			$pixcensus_nested_value = $pixcensus_entry['value'];

			if ( is_string( $pixcensus_nested_value ) ) {
				$pixcensus_strings[] = $pixcensus_nested_value;
			} elseif ( is_scalar( $pixcensus_nested_value ) ) {
				$pixcensus_strings[] = (string) $pixcensus_nested_value;
			}
		}

		return $pixcensus_strings;
	}

	/**
	 * Walk arbitrary scan values without recursive calls.
	 *
	 * The per-value limits retain ordinary builder payloads while bounding the
	 * queue, traversal work, and nesting of hostile third-party metadata.
	 *
	 * @param mixed $value Value to walk.
	 * @return Generator<int, array{key: int|string|null, value: mixed}>
	 */
	private function walk_scan_values( $value ): Generator {
		$pixcensus_queue           = new SplQueue();
		$pixcensus_seen_objects    = new SplObjectStorage();
		$pixcensus_seen_array_refs = array();
		$pixcensus_examined_nodes  = 1;
		$pixcensus_queue->enqueue(
			array(
				'key'   => null,
				'value' => $value,
				'depth' => 0,
			)
		);

		while ( ! $pixcensus_queue->isEmpty() ) {
			$pixcensus_entry         = $pixcensus_queue->dequeue();
			$pixcensus_current       = $pixcensus_entry['value'];
			$pixcensus_current_key   = $pixcensus_entry['key'];
			$pixcensus_current_depth = $pixcensus_entry['depth'];

			if ( is_object( $pixcensus_current ) ) {
				if ( $pixcensus_seen_objects->contains( $pixcensus_current ) ) {
					continue;
				}

				$pixcensus_seen_objects->attach( $pixcensus_current );
				$pixcensus_current = get_object_vars( $pixcensus_current );
			}

			if ( is_array( $pixcensus_current ) ) {
				if ( $pixcensus_current_depth >= self::MAX_WALK_DEPTH ) {
					continue;
				}

				foreach ( $pixcensus_current as $pixcensus_key => $pixcensus_nested_value ) {
					if ( $pixcensus_examined_nodes >= self::MAX_WALK_NODES ) {
						break;
					}

					++$pixcensus_examined_nodes;

					if ( is_array( $pixcensus_nested_value ) ) {
						$pixcensus_reference = ReflectionReference::fromArrayElement( $pixcensus_current, $pixcensus_key );

						if ( $pixcensus_reference instanceof ReflectionReference ) {
							$pixcensus_reference_id = $pixcensus_reference->getId();

							if ( isset( $pixcensus_seen_array_refs[ $pixcensus_reference_id ] ) ) {
								continue;
							}

							$pixcensus_seen_array_refs[ $pixcensus_reference_id ] = true;
						}
					}

					$pixcensus_queue->enqueue(
						array(
							'key'   => $pixcensus_key,
							'value' => $pixcensus_nested_value,
							'depth' => $pixcensus_current_depth + 1,
						)
					);
				}

				continue;
			}

			yield array(
				'key'   => $pixcensus_current_key,
				'value' => $pixcensus_current,
			);
		}
	}

	/**
	 * Determine whether a value is worth scanning for uploads.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	private function value_might_reference_uploads( $value ): bool {
		foreach ( $this->flatten_scan_strings( $value ) as $pixcensus_text ) {
			if ( $this->text_might_reference_uploads( $pixcensus_text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether text is worth scanning for uploads.
	 *
	 * @param string $text Text to inspect.
	 * @return bool
	 */
	private function text_might_reference_uploads( string $text ): bool {
		$pixcensus_text     = $this->normalize_text_rewrites( $text );
		$pixcensus_patterns = $this->get_search_patterns();

		foreach ( $pixcensus_patterns as $pixcensus_pattern ) {
			if ( false !== stripos( $pixcensus_text, $pixcensus_pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize aliases and rewrites in text.
	 *
	 * @param string $text Original text.
	 * @return string
	 */
	private function normalize_text_rewrites( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_ireplace( array( '\\/', '\\u002f' ), '/', $text );

		$pixcensus_decoded_text = rawurldecode( $text );

		if ( '' !== $pixcensus_decoded_text ) {
			$text = $pixcensus_decoded_text;
		}

		foreach ( $this->cdn_aliases as $pixcensus_alias ) {
			$pixcensus_alias = trim( $pixcensus_alias );

			if ( '' === $pixcensus_alias ) {
				continue;
			}

			$pixcensus_text = preg_replace( '#https?://' . preg_quote( $pixcensus_alias, '#' ) . '/#i', '/', $text );

			if ( is_string( $pixcensus_text ) ) {
				$text = $pixcensus_text;
			}
		}

		foreach ( $this->cdn_rewrites as $pixcensus_pair ) {
			$pixcensus_from = isset( $pixcensus_pair['from'] ) ? (string) $pixcensus_pair['from'] : '';
			$pixcensus_to   = isset( $pixcensus_pair['to'] ) ? (string) $pixcensus_pair['to'] : '';

			if ( '' === $pixcensus_from || '' === $pixcensus_to ) {
				continue;
			}

			$text = str_ireplace( $pixcensus_from, $pixcensus_to, $text );
		}

		return $text;
	}

	/**
	 * Record provenance for an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $label Provenance label.
	 * @return void
	 */
	private function add_provenance( int $attachment_id, string $label ): void {
		if ( $attachment_id <= 0 || '' === $label ) {
			return;
		}

		if ( ! isset( $this->provenance[ $attachment_id ] ) ) {
			$this->provenance[ $attachment_id ] = array();
		}

		if ( ! in_array( $label, $this->provenance[ $attachment_id ], true ) ) {
			$this->provenance[ $attachment_id ][] = $label;
		}
	}

	/**
	 * Return limited provenance output.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function get_provenance_output(): array {
		$pixcensus_output = array();

		foreach ( $this->provenance as $pixcensus_attachment_id => $pixcensus_labels ) {
			$pixcensus_output[ (int) $pixcensus_attachment_id ] = array_slice( array_values( $pixcensus_labels ), 0, 12 );
		}

		return $pixcensus_output;
	}

	/**
	 * Find orphan image files on disk.
	 *
	 * @param array<int, int> $attachment_ids Attachment IDs.
	 * @param string          $basedir Uploads base dir.
	 * @return array<int, string>
	 */
	private function find_orphans( array $attachment_ids, string $basedir ): array {
		$pixcensus_all_files   = $this->find_upload_images( $basedir );
		$pixcensus_attached    = $this->collect_all_attachment_files( $attachment_ids, $basedir );
		$pixcensus_base_prefix = trailingslashit( wp_normalize_path( $basedir ) );
		$pixcensus_orphans     = array();

		foreach ( array_diff( $pixcensus_all_files, $pixcensus_attached ) as $pixcensus_orphan_file ) {
			if ( 0 !== strpos( $pixcensus_orphan_file, $pixcensus_base_prefix ) ) {
				continue;
			}

			$pixcensus_relative_file = ltrim( substr( $pixcensus_orphan_file, strlen( $pixcensus_base_prefix ) ), '/\\' );

			if ( '' !== $pixcensus_relative_file ) {
				$pixcensus_orphans[] = $pixcensus_relative_file;
			}
		}

		return array_values( array_unique( $pixcensus_orphans ) );
	}

	/**
	 * Find image files in uploads.
	 *
	 * @param string $basedir Uploads base dir.
	 * @return array<int, string>
	 */
	private function find_upload_images( string $basedir ): array {
		$pixcensus_files      = array();
		$pixcensus_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif' );

		if ( ! is_dir( $basedir ) ) {
			return $pixcensus_files;
		}

		try {
			$pixcensus_iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $pixcensus_iterator as $pixcensus_file ) {
				if ( $pixcensus_file->isFile() ) {
					$pixcensus_extension = strtolower( pathinfo( $pixcensus_file->getFilename(), PATHINFO_EXTENSION ) );

					if ( in_array( $pixcensus_extension, $pixcensus_extensions, true ) ) {
						$pixcensus_files[] = wp_normalize_path( $pixcensus_file->getPathname() );
					}
				}
			}
		} catch ( UnexpectedValueException $pixcensus_exception ) {
			return $pixcensus_files;
		}

		return $pixcensus_files;
	}

	/**
	 * Collect all files referenced by attachments.
	 *
	 * @param array<int, int> $attachment_ids Attachment IDs.
	 * @param string          $basedir Uploads base dir.
	 * @return array<int, string>
	 */
	private function collect_all_attachment_files( array $attachment_ids, string $basedir ): array {
		$pixcensus_files = array();

		foreach ( $attachment_ids as $pixcensus_attachment_id ) {
			$pixcensus_relative_file = get_post_meta( $pixcensus_attachment_id, '_wp_attached_file', true );

			if ( empty( $pixcensus_relative_file ) ) {
				continue;
			}

			$pixcensus_original_file = wp_normalize_path( trailingslashit( $basedir ) . ltrim( (string) $pixcensus_relative_file, '/\\' ) );

			if ( file_exists( $pixcensus_original_file ) ) {
				$pixcensus_files[] = $pixcensus_original_file;
			}

			$pixcensus_metadata = wp_get_attachment_metadata( $pixcensus_attachment_id );

			if ( ! empty( $pixcensus_metadata['sizes'] ) && is_array( $pixcensus_metadata['sizes'] ) ) {
				$pixcensus_directory = trailingslashit( wp_normalize_path( dirname( $pixcensus_original_file ) ) );

				foreach ( $pixcensus_metadata['sizes'] as $pixcensus_size ) {
					if ( ! empty( $pixcensus_size['file'] ) ) {
						$pixcensus_resized_file = $pixcensus_directory . $pixcensus_size['file'];

						if ( file_exists( $pixcensus_resized_file ) ) {
							$pixcensus_files[] = $pixcensus_resized_file;
						}
					}
				}
			}
		}

		return array_values( array_unique( $pixcensus_files ) );
	}

	/**
	 * Get all patterns worth scanning in PHP first.
	 *
	 * @return array<int, string>
	 */
	private function get_search_patterns(): array {
		$pixcensus_patterns = array( '/wp-content/uploads/' );

		foreach ( $this->cdn_aliases as $pixcensus_alias ) {
			$pixcensus_alias = trim( $pixcensus_alias );

			if ( '' !== $pixcensus_alias ) {
				$pixcensus_patterns[] = $pixcensus_alias;
			}
		}

		foreach ( $this->cdn_rewrites as $pixcensus_pair ) {
			if ( ! empty( $pixcensus_pair['from'] ) ) {
				$pixcensus_patterns[] = (string) $pixcensus_pair['from'];
			}
		}

		return array_values( array_unique( array_filter( $pixcensus_patterns ) ) );
	}
}

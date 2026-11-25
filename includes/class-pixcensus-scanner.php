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

	/** @var bool Whether to process only one source batch. */
	private $incremental = false;
	/** @var int Current source page. */
	private $batch_page = 1;
	/** @var int Last processed batch size. */
	private $batch_count = 0;
	/** @var int Options keyset cursor. */
	private $option_cursor = 0;

	/**
	 * Read local file sizes without opening remote streams or changing media.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	public function image_details( int $id ): array {
		$uploads = wp_get_upload_dir();
		$meta    = wp_get_attachment_metadata( $id );
		$files   = array();
		$missing = 0;
		foreach ( $this->attachment_paths( $id ) as $path ) {
			$file = $this->resolve_existing_upload_file( (string) ( $uploads['basedir'] ?? '' ), $path );
			if ( '' === $file ) {
				++$missing;
				continue;
			}
			$bytes = filesize( $file );
			if ( false === $bytes ) {
				++$missing;
			} else {
				$files[ hash( 'sha256', $file ) ] = $bytes;
			}
		}
		return array(
			'bytes'      => array_sum( $files ),
			'files'      => $files,
			'incomplete' => $missing > 0 || ! $files,
			'width'      => (int) ( $meta['width'] ?? 0 ),
			'height'     => (int) ( $meta['height'] ?? 0 ),
			'mime'       => (string) get_post_mime_type( $id ),
			'date'       => (string) get_post_field( 'post_date', $id ),
		);
	}

	/**
	 * Process one durable scan batch. The caller serializes access with the scan lock.
	 *
	 * @param array $state Server-owned checkpoint, never accepted from the client.
	 * @return array
	 */
	public function step( array $state ): array {
		$this->incremental   = true;
		$this->batch_page    = (int) ( $state['page'] ?? 1 );
		$this->provenance    = $state['provenance'] ?? array();
		$this->batch_count   = 0;
		$this->option_cursor = (int) ( $state['cursor'] ?? 0 );
		$this->load_cdn_settings();
		$stage   = (int) ( $state['stage'] ?? 0 );
		$state  += array(
			'ids'     => array(),
			'map'     => array(),
			'known'   => array(),
			'used'    => array(),
			'draft'   => array(),
			'details' => array(),
			'orphans' => array(),
		);
		$uploads = wp_get_upload_dir();
		$base    = (string) ( $uploads['basedir'] ?? '' );
		$limit   = self::POST_BATCH_SIZE;
		if ( $stage < 2 ) {
			$ids = $this->get_image_attachment_ids( 0 === $stage ? 'inherit' : 'trash' );
			foreach ( $ids as $id ) {
				foreach ( $this->attachment_paths( $id ) as $path ) {
					$file = $this->resolve_existing_upload_file( $base, $path );
					if ( '' !== $file ) {
						$state['known'][ $file ] = true;
					}
					if ( 0 === $stage ) {
						$state['map'][ $path ] = $id;
					}
				}
				$state['details'][ $id ] = $this->image_details( $id );
				if ( 0 === $stage ) {
					$state['ids'][] = $id;
				}
			}
		} elseif ( $stage < 10 ) {
			$methods = array( 'scan_post_contents', 'scan_meta_references', 'scan_builder_metas', 'scan_any_meta_uploads' );
			$draft   = $stage >= 6;
			$method  = $methods[ ( $stage - 2 ) % 4 ];
			$bucket  = $draft ? 'draft' : 'used';
			if ( ! $draft || $state['include_drafts'] ) {
				$statuses = $draft ? array( 'draft', 'pending', 'future' ) : array( 'publish', 'private' );
				if ( 'scan_meta_references' === $method ) {
					$this->scan_meta_references( $state[ $bucket ], $statuses );
				} else {
					$this->$method( $state[ $bucket ], $statuses, $state['map'] );
				}
			}
		} elseif ( 10 === $stage ) {
			$this->scan_terms( $state['used'], $state['map'] );
		} elseif ( 11 === $stage ) {
			$this->scan_options_for_uploads( $state['used'], $state['map'] );
			$state['cursor'] = $this->option_cursor;
			$limit           = self::OPTION_BATCH_SIZE;
		} elseif ( 12 === $stage ) {
			$this->scan_site_identity( $state['used'] );
		} elseif ( 13 === $stage ) {
			$this->orphan_step( $state, $base );
			$this->batch_count = empty( $state['directories'] ) ? 0 : $limit;
		}
		$state['provenance'] = $this->get_provenance_output();
		$state['page']       = $this->batch_page + 1;
		if ( $this->batch_count < $limit ) {
			++$stage;
			$state['page'] = 1;
		}
		$state['stage']   = $stage;
		$state['updated'] = time();
		return $state;
	}

	/**
	 * Enumerate at most 200 directory entries, without following directory symlinks.
	 *
	 * @param array  $state Scan checkpoint.
	 * @param string $base Uploads directory.
	 * @return void
	 */
	private function orphan_step( array &$state, string $base ): void {
		$real = realpath( $base );
		if ( false === $real ) {
			$state['directories'] = array();
			return;
		}
		$real = wp_normalize_path( $real );
		if ( ! isset( $state['directories'] ) ) {
			$state['directories'] = array( array( $real, 0 ) );
		}
		$budget = 200;
		while ( $budget > 0 && $state['directories'] ) {
			$entry = array_shift( $state['directories'] );
			$dir   = realpath( $entry[0] );
			if ( false === $dir || ( wp_normalize_path( $dir ) !== $real && ! $this->path_is_within_uploads( $dir, $real ) ) || is_link( $entry[0] ) ) {
				continue;
			}
			$iterator = new DirectoryIterator( $dir );
			$iterator->seek( $entry[1] );
			while ( $iterator->valid() && $budget > 0 ) {
				--$budget;
				if ( ! $iterator->isDot() && ! $iterator->isLink() ) {
					$path = wp_normalize_path( $iterator->getPathname() );
					if ( $iterator->isDir() ) {
						$state['directories'][] = array( $path, 0 );
					} elseif ( $iterator->isFile() && ! isset( $state['known'][ $path ] ) && in_array( strtolower( $iterator->getExtension() ), array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif' ), true ) ) {
						$resolved = realpath( $path );
						if ( false !== $resolved && $this->path_is_within_uploads( $resolved, $real ) ) {
							$state['orphans'][] = substr( wp_normalize_path( $resolved ), strlen( trailingslashit( $real ) ) );
						}
					}
				}
				$iterator->next();
			}
			if ( $iterator->valid() ) {
				array_unshift( $state['directories'], array( $dir, $iterator->key() ) );
			}
		}
	}

	/**
	 * Classify only a completed checkpoint.
	 *
	 * @param array $state Completed scan state.
	 * @return array
	 */
	public function finish( array $state ): array {
		if ( (int) ( $state['stage'] ?? 0 ) < 14 ) {
			throw new LogicException( 'Only completed scans can be published.' );
		}
		$ids    = array_values( array_unique( $state['ids'] ) );
		$manual = array_intersect( $ids, (array) get_option( 'pixcensus_manual_used_ids', array() ) );
		$used   = array_values( array_intersect( $ids, array_unique( array_merge( array_keys( $state['used'] ), $manual ) ) ) );
		$draft  = array_values( array_diff( array_intersect( $ids, array_keys( $state['draft'] ) ), $used ) );
		return array(
			'used_ids'       => $used,
			'draft_only_ids' => $draft,
			'unused_ids'     => array_values( array_diff( $ids, $used, $draft ) ),
			'orphans'        => array_values( array_unique( $state['orphans'] ) ),
			'provenance'     => $state['provenance'],
			'details'        => $state['details'],
			'include_drafts' => $state['include_drafts'],
			'scanned_at'     => time(),
			'revision'       => $state['revision'],
		);
	}

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
	 * @param bool $force_drafts Include drafts during cleanup verification.
	 * @return array<string, mixed>
	 */
	public function run( bool $force_drafts = false ): array {
		$this->incremental        = false;
		$this->provenance         = array();
		$pixcensus_include_drafts = $force_drafts || '1' === get_option( 'pixcensus_include_drafts', '1' );

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
			'orphans'        => $this->find_orphans( array_merge( $pixcensus_attachment_ids, $this->get_image_attachment_ids( 'trash' ) ), $pixcensus_basedir ),
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
	 * @param string $status Attachment status to inventory.
	 * @return array<int, int>
	 */
	private function get_image_attachment_ids( string $status = 'inherit' ): array {
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

		if ( ! $pixcensus_image_mime_types ) {
			return array();
		}

		$pixcensus_attachment_ids = array();
		$pixcensus_page           = $this->incremental ? $this->batch_page : 1;

		do {
			$pixcensus_batch = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => $status,
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
			$this->batch_count        = $pixcensus_batch_count;
			++$pixcensus_page;
		} while ( ! $this->incremental && self::ATTACHMENT_BATCH_SIZE === $pixcensus_batch_count );

		return array_values( array_unique( $pixcensus_attachment_ids ) );
	}

	/**
	 * Relative paths for the registered image, its original and generated sizes.
	 *
	 * @param int $id Attachment ID.
	 * @return string[]
	 */
	private function attachment_paths( int $id ): array {
		$file = get_post_meta( $id, '_wp_attached_file', true );
		if ( ! is_string( $file ) || '' === $file ) {
			return array();
		}
		$file   = ltrim( wp_normalize_path( $file ), '/' );
		$paths  = array( $file );
		$dir    = dirname( $file );
		$prefix = '.' === $dir ? '' : trailingslashit( $dir );
		$meta   = wp_get_attachment_metadata( $id );
		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$paths[] = $prefix . $meta['original_image'];
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
				$paths[] = $prefix . $size['file'];
			}
		}
		return $paths;
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
			foreach ( $this->attachment_paths( $pixcensus_attachment_id ) as $path ) {
				$pixcensus_map[ $path ] = $pixcensus_attachment_id;
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
		$pixcensus_page = $this->incremental ? $this->batch_page : 1;

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
			$this->batch_count     = $pixcensus_batch_count;
			++$pixcensus_page;
		} while ( ! $this->incremental && self::POST_BATCH_SIZE === $pixcensus_batch_count );
	}

	/**
	 * Scan featured image and common gallery references.
	 *
	 * @param array<int, bool>   $used_map Usage map.
	 * @param array<int, string> $statuses Allowed statuses.
	 * @return void
	 */
	private function scan_meta_references( array &$used_map, array $statuses ): void {
		$pixcensus_page = $this->incremental ? $this->batch_page : 1;

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
			$this->batch_count     = $pixcensus_batch_count;
			++$pixcensus_page;
		} while ( ! $this->incremental && self::POST_BATCH_SIZE === $pixcensus_batch_count );
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

		$pixcensus_page = $this->incremental ? $this->batch_page : 1;

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
			$this->batch_count     = $pixcensus_batch_count;
			++$pixcensus_page;
		} while ( ! $this->incremental && self::POST_BATCH_SIZE === $pixcensus_batch_count );
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
		$pixcensus_page = $this->incremental ? $this->batch_page : 1;

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
			$this->batch_count     = $pixcensus_batch_count;
			++$pixcensus_page;
		} while ( ! $this->incremental && self::POST_BATCH_SIZE === $pixcensus_batch_count );
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
		$pixcensus_last_option_id = $this->incremental ? $this->option_cursor : 0;
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

				if ( '' === $pixcensus_option_name || 0 === strpos( $pixcensus_option_name, 'pixcensus_' ) || in_array( $pixcensus_option_name, $pixcensus_excluded, true ) || ! $this->text_might_reference_uploads( $pixcensus_option_value ) ) {
					continue;
				}

				$this->scan_text_for_uploads( $pixcensus_option_value, $path_map, $used_map, 'option:' . $pixcensus_option_name );
			}

			$pixcensus_row_count = count( $pixcensus_rows );
			$this->batch_count   = $pixcensus_row_count;
			$this->option_cursor = $pixcensus_last_option_id;
		} while ( ! $this->incremental && self::OPTION_BATCH_SIZE === $pixcensus_row_count );
	}

	/**
	 * Scan term descriptions.
	 *
	 * @param array<int, bool>   $used_map Usage map.
	 * @param array<string, int> $path_map Attachment path map.
	 * @return void
	 */
	private function scan_terms( array &$used_map, array $path_map ): void {
		$pixcensus_offset = $this->incremental ? ( $this->batch_page - 1 ) * self::TERM_BATCH_SIZE : 0;

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
			$this->batch_count    = $pixcensus_term_count;
			$pixcensus_offset    += $pixcensus_term_count;
		} while ( ! $this->incremental && self::TERM_BATCH_SIZE === $pixcensus_term_count );
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
		if ( function_exists( 'parse_blocks' ) ) {
			$queue = parse_blocks( $text );
			$nodes = 0;
			while ( $queue && ++$nodes <= self::MAX_WALK_NODES ) {
				$block = array_pop( $queue );
				$attrs = $block['attrs'] ?? array();
				$keys  = array(
					'core/image'      => 'id',
					'core/cover'      => 'id',
					'core/media-text' => 'mediaId',
					'core/gallery'    => 'ids',
					'core/site-logo'  => 'id',
				);
				$name  = $block['blockName'] ?? '';
				if ( isset( $keys[ $name ] ) && ( 'core/cover' !== $name || 'video' !== ( $attrs['backgroundType'] ?? '' ) ) && ( 'core/media-text' !== $name || 'video' !== ( $attrs['mediaType'] ?? '' ) ) ) {
					foreach ( (array) ( $attrs[ $keys[ $name ] ] ?? array() ) as $id ) {
						if ( is_numeric( $id ) && (int) $id > 0 ) {
							$used_map[ (int) $id ] = true;
							$this->add_provenance( (int) $id, $context . ':block:' . $name );
						}
					}
				}
				foreach ( $block['innerBlocks'] ?? array() as $child ) {
					$queue[] = $child;
				}
			}
		}
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
		$pixcensus_real_basedir = realpath( $basedir );

		if ( false === $pixcensus_real_basedir || ! is_dir( $pixcensus_real_basedir ) ) {
			return array();
		}

		$pixcensus_real_basedir = wp_normalize_path( $pixcensus_real_basedir );
		$pixcensus_all_files    = $this->find_upload_images( $pixcensus_real_basedir );
		$pixcensus_attached     = $this->collect_all_attachment_files( $attachment_ids, $pixcensus_real_basedir );
		$pixcensus_base_prefix  = trailingslashit( $pixcensus_real_basedir );
		$pixcensus_orphans      = array();

		foreach ( array_diff( $pixcensus_all_files, $pixcensus_attached ) as $pixcensus_orphan_file ) {
			if ( ! $this->path_is_within_uploads( $pixcensus_orphan_file, $pixcensus_real_basedir ) ) {
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
		$pixcensus_real_base  = realpath( $basedir );

		if ( false === $pixcensus_real_base || ! is_dir( $pixcensus_real_base ) ) {
			return $pixcensus_files;
		}

		$pixcensus_real_base = wp_normalize_path( $pixcensus_real_base );

		try {
			$pixcensus_iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $pixcensus_real_base, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $pixcensus_iterator as $pixcensus_file ) {
				if ( ! $pixcensus_file->isFile() ) {
					continue;
				}

				$pixcensus_extension = strtolower( pathinfo( $pixcensus_file->getFilename(), PATHINFO_EXTENSION ) );

				if ( ! in_array( $pixcensus_extension, $pixcensus_extensions, true ) ) {
					continue;
				}

				$pixcensus_real_file = realpath( $pixcensus_file->getPathname() );

				if ( false === $pixcensus_real_file ) {
					continue;
				}

				$pixcensus_real_file = wp_normalize_path( $pixcensus_real_file );

				if ( $this->path_is_within_uploads( $pixcensus_real_file, $pixcensus_real_base ) ) {
					$pixcensus_files[] = $pixcensus_real_file;
				}
			}
		} catch ( UnexpectedValueException $pixcensus_exception ) {
			return $pixcensus_files;
		}

		return array_values( array_unique( $pixcensus_files ) );
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
			foreach ( $this->attachment_paths( $pixcensus_attachment_id ) as $path ) {
				$file = $this->resolve_existing_upload_file( $basedir, $path );
				if ( '' !== $file ) {
					$pixcensus_files[] = $file;
				}
			}
		}

		return array_values( array_unique( $pixcensus_files ) );
	}

	/**
	 * Resolve an existing attachment-relative file and confine it to uploads.
	 *
	 * realpath() collapses traversal segments and follows symlinks. The resolved
	 * candidate must remain strictly below the resolved uploads directory before
	 * PixCensus performs any subsequent filesystem operation on it.
	 *
	 * @param string $basedir Uploads base dir.
	 * @param string $relative_file Attachment-relative file path.
	 * @return string Normalized resolved path, or an empty string when rejected.
	 */
	private function resolve_existing_upload_file( string $basedir, string $relative_file ): string {
		if ( '' === $basedir || '' === $relative_file || false !== strpos( $relative_file, "\0" ) ) {
			return '';
		}

		$pixcensus_real_base = realpath( $basedir );

		if ( false === $pixcensus_real_base || ! is_dir( $pixcensus_real_base ) ) {
			return '';
		}

		$pixcensus_real_base = wp_normalize_path( $pixcensus_real_base );
		$pixcensus_candidate = trailingslashit( $pixcensus_real_base ) . ltrim( wp_normalize_path( $relative_file ), '/\\' );
		$pixcensus_real_file = realpath( $pixcensus_candidate );

		if ( false === $pixcensus_real_file || ! is_file( $pixcensus_real_file ) ) {
			return '';
		}

		$pixcensus_real_file = wp_normalize_path( $pixcensus_real_file );

		return $this->path_is_within_uploads( $pixcensus_real_file, $pixcensus_real_base ) ? $pixcensus_real_file : '';
	}

	/**
	 * Check whether a resolved filesystem path is strictly inside uploads.
	 *
	 * @param string $path Resolved candidate path.
	 * @param string $basedir Resolved uploads base directory.
	 * @return bool
	 */
	private function path_is_within_uploads( string $path, string $basedir ): bool {
		$pixcensus_path        = wp_normalize_path( $path );
		$pixcensus_base_prefix = trailingslashit( wp_normalize_path( $basedir ) );

		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$pixcensus_path        = strtolower( $pixcensus_path );
			$pixcensus_base_prefix = strtolower( $pixcensus_base_prefix );
		}

		return 0 === strpos( $pixcensus_path, $pixcensus_base_prefix );
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

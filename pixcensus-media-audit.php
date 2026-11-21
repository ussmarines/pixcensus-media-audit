<?php
/**
 * Plugin Name: PixCensus — Media Usage Audit
 * Plugin URI: https://github.com/ussmarines/pixcensus-media-audit
 * Description: Inventory media usage with provenance, CSV export, manual review tools, and CDN rewrite support.
 * Version: 3.4.9
 * Author: ussmarines
 * Author URI: https://github.com/ussmarines
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pixcensus-media-audit
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PIXCENSUS_VERSION' ) ) {
	define( 'PIXCENSUS_VERSION', '3.4.9' );
}

if ( ! defined( 'PIXCENSUS_SLUG' ) ) {
	define( 'PIXCENSUS_SLUG', 'pixcensus-media-audit' );
}

if ( ! defined( 'PIXCENSUS_PATH' ) ) {
	define( 'PIXCENSUS_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'PIXCENSUS_URL' ) ) {
	define( 'PIXCENSUS_URL', plugin_dir_url( __FILE__ ) );
}

spl_autoload_register(
	static function ( $pixcensus_class ) {
		if ( 0 !== strpos( $pixcensus_class, 'PIXCENSUS_' ) ) {
			return;
		}

		$file = PIXCENSUS_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $pixcensus_class ) ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Main plugin bootstrap.
 */
final class PIXCENSUS_Plugin {
	private const MAX_BULK_IDS    = 500;
	private const SCAN_LOCK_TTL   = 900;
	private const SITE_BATCH_SIZE = 100;

	/**
	 * Singleton instance.
	 *
	 * @var PIXCENSUS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Token owned by the scan running in this request.
	 *
	 * @var string
	 */
	private $scan_lock_token = '';

	/**
	 * Get singleton instance.
	 *
	 * @return PIXCENSUS_Plugin
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		$network_wide = (bool) $network_wide;

		if ( $network_wide && is_multisite() ) {
			$pixcensus_offset = 0;

			do {
				$pixcensus_site_ids = get_sites(
					array(
						'fields' => 'ids',
						'number' => self::SITE_BATCH_SIZE,
						'offset' => $pixcensus_offset,
					)
				);

				if ( ! is_array( $pixcensus_site_ids ) || empty( $pixcensus_site_ids ) ) {
					break;
				}

				foreach ( $pixcensus_site_ids as $pixcensus_site_id ) {
					$pixcensus_switched = switch_to_blog( (int) $pixcensus_site_id );

					if ( ! $pixcensus_switched ) {
						continue;
					}

					try {
						self::ensure_default_options();
					} catch ( Throwable $pixcensus_exception ) {
						// Continue so one unavailable site does not block network activation.
						continue;
					} finally {
						restore_current_blog();
					}
				}

				$pixcensus_count   = count( $pixcensus_site_ids );
				$pixcensus_offset += $pixcensus_count;
			} while ( self::SITE_BATCH_SIZE === $pixcensus_count );

			return;
		}

		self::ensure_default_options();
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action(
			'init',
			static function () {
				// Older WordPress versions discover directory language packs but not bundled catalogs.
				if ( 'fr_FR' === determine_locale() && get_translations_for_domain( 'pixcensus-media-audit' ) instanceof NOOP_Translations ) {
					load_textdomain( 'pixcensus-media-audit', PIXCENSUS_PATH . 'languages/pixcensus-media-audit-fr_FR.mo' );
				}
			}
		);
		add_filter( 'pre_trash_post', array( $this, 'respect_manual_protection' ), 10, 2 );
		add_filter( 'pre_delete_attachment', array( $this, 'respect_manual_protection' ), 10, 2 );
		add_action( 'wp_scheduled_delete', array( $this, 'sync_trash_retention' ), 5 );
		add_action(
			'untrashed_post',
			static function ( $id ) {
				delete_post_meta( $id, '_pixcensus_trash_time' );
			}
		);
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );

		add_action( 'admin_post_pixcensus_save_settings', array( $this, 'handle_save_settings' ) );
		foreach ( array( 'restore', 'review_delete', 'review_empty', 'confirm_delete' ) as $operation ) {
			add_action( 'admin_post_pixcensus_' . $operation, array( $this, 'handle_image_trash' ) );
		}
		add_action( 'admin_post_pixcensus_trash_reviewed', array( $this, 'handle_cleanup' ) );
		add_action( 'admin_post_pixcensus_export_csv', array( $this, 'export_csv' ) );

		add_action( 'wp_ajax_pixcensus_run_scan', array( $this, 'ajax_run_scan' ) );
		add_action( 'wp_ajax_pixcensus_mark_manual_used', array( $this, 'ajax_mark_manual_used' ) );
		add_action( 'wp_ajax_pixcensus_unmark_manual_used', array( $this, 'ajax_unmark_manual_used' ) );
		add_action( 'wp_ajax_pixcensus_mark_manual_used_bulk', array( $this, 'ajax_mark_manual_used_bulk' ) );
		add_action( 'wp_ajax_pixcensus_unmark_manual_used_bulk', array( $this, 'ajax_unmark_manual_used_bulk' ) );

		foreach ( $this->get_ajax_actions() as $pixcensus_action ) {
			add_action( 'wp_ajax_nopriv_' . $pixcensus_action, array( $this, 'ajax_unauthorized' ) );
		}
	}

	/**
	 * Ensure option defaults exist.
	 *
	 * @return void
	 */
	private static function ensure_default_options(): void {
		if ( null === get_option( 'pixcensus_include_drafts', null ) ) {
			add_option( 'pixcensus_include_drafts', '1', '', false );
		}

		if ( null === get_option( 'pixcensus_manual_used_ids', null ) ) {
			add_option( 'pixcensus_manual_used_ids', array(), '', false );
		}

		if ( null === get_option( 'pixcensus_cdn_rewrites', null ) ) {
			add_option( 'pixcensus_cdn_rewrites', '', '', false );
		}

		if ( null === get_option( 'pixcensus_cdn_aliases', null ) ) {
			add_option( 'pixcensus_cdn_aliases', '', '', false );
		}
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_media_page(
			__( 'PixCensus — Media Usage Audit', 'pixcensus-media-audit' ),
			__( 'PixCensus', 'pixcensus-media-audit' ),
			'manage_options',
			'pixcensus-audit',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public function enqueue_admin( string $hook ): void {
		if ( 'media_page_pixcensus-audit' !== $hook ) {
			return;
		}

		$pixcensus_usage = get_option(
			'pixcensus_usage_results',
			array(
				'used_ids'       => array(),
				'draft_only_ids' => array(),
				'unused_ids'     => array(),
				'orphans'        => array(),
				'scanned_at'     => 0,
				'provenance'     => array(),
			)
		);

		wp_enqueue_style( 'pixcensus-admin', PIXCENSUS_URL . 'assets/admin.css', array(), PIXCENSUS_VERSION );
		wp_enqueue_script( 'pixcensus-admin', PIXCENSUS_URL . 'assets/admin.js', array( 'jquery' ), PIXCENSUS_VERSION, true );

		wp_localize_script(
			'pixcensus-admin',
			'PixCensusAdmin',
			array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonces'    => array(
					'run_scan'         => wp_create_nonce( 'pixcensus_run_scan' ),
					'mark_manual'      => wp_create_nonce( 'pixcensus_mark_manual_used' ),
					'unmark_manual'    => wp_create_nonce( 'pixcensus_unmark_manual_used' ),
					'mark_manual_bulk' => wp_create_nonce( 'pixcensus_mark_manual_used_bulk' ),
					'unmark_bulk'      => wp_create_nonce( 'pixcensus_unmark_manual_used_bulk' ),
				),
				'last_scan' => (int) ( $pixcensus_usage['scanned_at'] ?? 0 ),
				'urls'      => array(
					'base'   => admin_url( 'upload.php?page=pixcensus-audit' ),
					'used'   => admin_url( 'upload.php?page=pixcensus-audit&pixcensus_tab=used' ),
					'unused' => admin_url( 'upload.php?page=pixcensus-audit&pixcensus_tab=unused' ),
				),
				'i18n'      => array(
					'marked'         => __( 'Marked as used (manual).', 'pixcensus-media-audit' ),
					'unmarked'       => __( 'Unmarked (manual).', 'pixcensus-media-audit' ),
					'bulk_done'      => __( 'Bulk action completed.', 'pixcensus-media-audit' ),
					'error'          => __( 'An error occurred.', 'pixcensus-media-audit' ),
					'run_scan'       => __( 'Run scan', 'pixcensus-media-audit' ),
					'run_scan_again' => __( 'Run scan again', 'pixcensus-media-audit' ),
					'scanning'       => __( 'Scanning…', 'pixcensus-media-audit' ),
					'scan_error'     => __( 'Scan error.', 'pixcensus-media-audit' ),
					'none_selected'  => __( 'No items selected.', 'pixcensus-media-audit' ),
					'show_more'      => __( 'Show more', 'pixcensus-media-audit' ),
					'show_less'      => __( 'Show less', 'pixcensus-media-audit' ),
					/* translators: %d: number of rows currently shown after filtering. */
					'shown_count'    => __( '%d shown', 'pixcensus-media-audit' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'pixcensus-media-audit' ) );
		}

		$pixcensus_trash_ids = $this->image_trash_ids();
		$pixcensus_receipt   = $this->protected_receipt();
		include PIXCENSUS_PATH . 'views/admin-page.php';
	}

	/**
	 * Handle settings saves.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		$this->require_admin_post();
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'pixcensus-media-audit' ) );
		}

		$pixcensus_section     = '';
		$pixcensus_raw_section = filter_input( INPUT_POST, 'pixcensus_section', FILTER_UNSAFE_RAW );

		if ( is_string( $pixcensus_raw_section ) ) {
			$pixcensus_section = sanitize_key( $pixcensus_raw_section );
		}

		if ( 'scan' === $pixcensus_section ) {
			check_admin_referer( 'pixcensus_save_scan_settings', 'pixcensus_settings_nonce' );
			$pixcensus_include_drafts = isset( $_POST['pixcensus_include_drafts'] ) ? '1' : '0';
			update_option( 'pixcensus_include_drafts', $pixcensus_include_drafts );
		} elseif ( 'cdn' === $pixcensus_section ) {
			check_admin_referer( 'pixcensus_save_cdn_settings', 'pixcensus_settings_nonce' );

			$pixcensus_cdn_aliases_raw  = filter_input( INPUT_POST, 'pixcensus_cdn_aliases', FILTER_UNSAFE_RAW );
			$pixcensus_cdn_rewrites_raw = filter_input( INPUT_POST, 'pixcensus_cdn_rewrites', FILTER_UNSAFE_RAW );

			$pixcensus_cdn_aliases  = is_string( $pixcensus_cdn_aliases_raw ) ? sanitize_text_field( $pixcensus_cdn_aliases_raw ) : '';
			$pixcensus_cdn_rewrites = is_string( $pixcensus_cdn_rewrites_raw ) ? sanitize_textarea_field( $pixcensus_cdn_rewrites_raw ) : '';
			$pixcensus_validated    = PIXCENSUS_CDN_Settings::validate( $pixcensus_cdn_aliases, $pixcensus_cdn_rewrites );

			if ( ! $pixcensus_validated['valid'] ) {
				$this->redirect_to_admin_notice( 'invalid-cdn-settings' );
			}

			update_option( 'pixcensus_cdn_aliases', $pixcensus_validated['aliases'], false );
			update_option( 'pixcensus_cdn_rewrites', $pixcensus_validated['rewrites'], false );
		} else {
			wp_die( esc_html__( 'Invalid settings section.', 'pixcensus-media-audit' ), '', array( 'response' => 400 ) );
		}

		$this->redirect_to_admin_notice( 'settings-saved' );
	}

	/**
	 * Redirect back to the plugin page with an allow-listed notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_to_admin_notice( string $notice ): void {
		$pixcensus_notice       = in_array( $notice, array( 'settings-saved', 'invalid-cdn-settings' ), true ) ? $notice : '';
		$pixcensus_redirect_url = add_query_arg(
			array(
				'page'             => 'pixcensus-audit',
				'pixcensus_notice' => $pixcensus_notice,
			),
			admin_url( 'upload.php' )
		);

		wp_safe_redirect( $pixcensus_redirect_url );
		exit;
	}

	/**
	 * IDs in this site's native image Trash, most recent attachments first.
	 */
	private function image_trash_ids(): array {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'trash',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Restore selected images or confirm permanent deletion of a frozen selection.
	 */
	public function handle_image_trash(): void {
		$this->require_admin_post();
		$operation = substr( current_action(), strlen( 'admin_post_pixcensus_' ) );
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'Access denied.', 'pixcensus-media-audit' ) );
		}
		check_admin_referer( 'pixcensus_cleanup_' . $operation, 'pixcensus_nonce_' . $operation );
		$url = admin_url( 'upload.php?page=pixcensus-audit&pixcensus_tab=trash' );
		if ( ! $this->acquire_scan_lock() ) {
			wp_die( esc_html__( 'Another operation is running. Please retry.', 'pixcensus-media-audit' ) );
		}
		try {
			if ( 'confirm_delete' === $operation ) {
				$token = isset( $_POST['confirmation'] ) ? sanitize_key( wp_unslash( $_POST['confirmation'] ) ) : '';
				$key   = 'pixcensus_delete_' . get_current_user_id() . '_' . $token;
				$ids   = get_transient( $key );
				if ( ! is_array( $ids ) || ! delete_transient( $key ) ) {
					$this->release_scan_lock();
					wp_die( esc_html__( 'This confirmation expired. Review the Trash again.', 'pixcensus-media-audit' ) );
				}
			} elseif ( 'review_empty' === $operation ) {
				$ids = $this->image_trash_ids();
			} else {
				$selection = $this->get_posted_attachment_ids();
				$ids       = $selection['valid'] ? $selection['ids'] : array();
			}
			$eligible = array();
			$changed  = 0;
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post || 'attachment' !== $post->post_type || 'trash' !== $post->post_status || ! wp_attachment_is_image( $id ) || ! current_user_can( 'delete_post', $id ) ) {
					continue;
				}
				if ( 'restore' === $operation ) {
					if ( wp_untrash_post( $id ) ) {
						++$changed;
					}
				} elseif ( 'confirm_delete' === $operation ) {
					if ( wp_delete_attachment( $id, true ) ) {
						++$changed;
					}
				} else {
					$eligible[] = $id;
				}
			}
			if ( 'restore' === $operation && $changed ) {
				update_option( 'pixcensus_usage_results', ( new PIXCENSUS_Scanner() )->run( true ), false );
			}
			if ( $eligible ) {
				$token = wp_generate_uuid4();
				set_transient( 'pixcensus_delete_' . get_current_user_id() . '_' . $token, $eligible, 600 );
				$url = add_query_arg( 'confirmation', $token, $url );
			} else {
				$url = add_query_arg( 'pixcensus_changed', $changed, $url );
			}
		} finally {
			$this->release_scan_lock();
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Respect PixCensus protection in native WordPress media operations too.
	 *
	 * @param mixed   $result Previous filter result.
	 * @param WP_Post $post   Post about to be removed.
	 * @return mixed
	 */
	public function respect_manual_protection( $result, $post ) {
		if ( 'attachment' === $post->post_type && in_array( (int) $post->ID, array_map( 'intval', (array) get_option( 'pixcensus_manual_used_ids', array() ) ), true ) ) {
			return false;
		}
		return $result;
	}

	/**
	 * Transfer timestamps before WordPress runs its daily purge when retention changes.
	 */
	public function sync_trash_retention(): void {
		$keep = ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS <= 0;
		$from = $keep ? '_wp_trash_meta_time' : '_pixcensus_trash_time';
		$to   = $keep ? '_pixcensus_trash_time' : '_wp_trash_meta_time';
		$page = 1;
		do {
			$posts = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'trash',
					'post_mime_type'         => 'image',
					'posts_per_page'         => 100,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'update_post_term_cache' => false,
				)
			);
			foreach ( $posts as $post ) {
				$id    = $post->ID;
				$stamp = get_post_meta( $id, $from, true );
				if ( is_numeric( $stamp ) && (int) $stamp > 0 && 'trash' === get_post_status( $id ) ) {
					update_post_meta( $id, $to, $stamp );
					if ( get_post_meta( $id, $to, true ) === $stamp ) {
						delete_post_meta( $id, $from, $stamp );
					}
				}
			}
			$batch_size = count( $posts );
			++$page;
		} while ( 100 === $batch_size );
	}

	/**
	 * At zero retention wp_trash_post() deletes files immediately. Keep the
	 * attachment in native Trash with a separate timestamp for this case.
	 */
	private function trash_image( int $id ): bool {
		if ( defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS > 0 ) {
			return wp_trash_post( $id ) && 'trash' === get_post_status( $id );
		}
		if ( ! wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'trash',
			)
		) || 'trash' !== get_post_status( $id ) ) {
			return false;
		}
		update_post_meta( $id, '_wp_trash_meta_status', 'inherit' );
		update_post_meta( $id, '_pixcensus_trash_time', time() );
		wp_trash_post_comments( $id );
		return true;
	}

	/**
	 * Resolve a site- and user-specific cleanup receipt. Missing receipts match no images.
	 */
	private function protected_receipt(): array {
		$token = filter_input( INPUT_GET, 'pixcensus_receipt', FILTER_UNSAFE_RAW );
		if ( is_string( $token ) && wp_is_uuid( $token, 4 ) ) {
			$ids = get_transient( 'pixcensus_kept_' . get_current_user_id() . '_' . $token );
			if ( is_array( $ids ) ) {
				return array(
					'token' => $token,
					'ids'   => $ids,
				);
			}
		}
		return array(
			'token' => '',
			'ids'   => array(),
		);
	}

	/**
	 * Apply the requested operation only to the IDs checked on the review screen.
	 */
	public function handle_cleanup(): void {
		$this->require_admin_post();
		$operation = substr( (string) current_action(), strlen( 'admin_post_pixcensus_' ) );
		if ( ! $this->current_user_can_manage() || 'trash_reviewed' !== $operation ) {
			wp_die( esc_html__( 'Access denied.', 'pixcensus-media-audit' ) );
		}
		$tab    = isset( $_POST['pixcensus_tab'] ) ? sanitize_key( wp_unslash( $_POST['pixcensus_tab'] ) ) : '';
		$groups = array(
			'unused' => 'unused_ids',
			'draft'  => 'draft_only_ids',
			'used'   => 'used_ids',
		);
		if ( ! isset( $groups[ $tab ] ) ) {
			wp_die( esc_html__( 'Access denied.', 'pixcensus-media-audit' ) );
		}
		check_admin_referer( 'pixcensus_cleanup_' . $operation . '_' . $tab, 'pixcensus_nonce_' . $operation );
		$selection = $this->get_posted_attachment_ids();
		if ( ! $selection['valid'] ) {
			wp_die( esc_html__( 'Choose at least one image.', 'pixcensus-media-audit' ) );
		}
		if ( ! $this->acquire_scan_lock() ) {
			wp_die( esc_html__( 'Another operation is running. Please retry.', 'pixcensus-media-audit' ) );
		}

		$changed   = 0;
		$protected = array();
		try {
			$this->maybe_raise_resource_limits();
			$usage = ( new PIXCENSUS_Scanner() )->run( true );
			foreach ( $selection['ids'] as $id ) {
				$post = get_post( $id );
				if ( ! $post || 'attachment' !== $post->post_type || 'inherit' !== $post->post_status || ! wp_attachment_is_image( $id ) || ! current_user_can( 'delete_post', $id ) ) {
					continue;
				}
				if ( in_array( $id, array_map( 'intval', (array) get_option( 'pixcensus_manual_used_ids', array() ) ), true ) ) {
					$protected[] = $id;
					continue;
				}
				if ( ! in_array( $id, $usage[ $groups[ $tab ] ], true ) ) {
					continue;
				}
				if ( $this->trash_image( $id ) ) {
					++$changed;
					$usage[ $groups[ $tab ] ] = array_values( array_diff( $usage[ $groups[ $tab ] ], array( $id ) ) );
				}
			}
			update_option( 'pixcensus_usage_results', $usage, false );
		} finally {
			$this->release_scan_lock();
		}
		$receipt = null;
		if ( $protected ) {
			$token = wp_generate_uuid4();
			if ( set_transient( 'pixcensus_kept_' . get_current_user_id() . '_' . $token, $protected, 24 * 60 * 60 ) ) {
				$receipt = $token;
			}
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => 'pixcensus-audit',
					'pixcensus_tab'       => $changed ? 'trash' : $tab,
					'pixcensus_result'    => 'trash',
					'pixcensus_changed'   => $changed,
					'pixcensus_protected' => count( $protected ),
					'pixcensus_receipt'   => $receipt,
				),
				admin_url( 'upload.php' )
			)
		);
		exit;
	}

	/**
	 * Run the scanner by AJAX.
	 *
	 * @return void
	 */
	public function ajax_run_scan(): void {
		$this->verify_ajax_request( 'pixcensus_run_scan', 'pixcensus_run_scan' );

		if ( ! $this->acquire_scan_lock() ) {
			wp_send_json_error(
				array(
					'message' => __( 'A scan is already running. Try again later.', 'pixcensus-media-audit' ),
				),
				409
			);
		}

		$this->maybe_raise_resource_limits();
		$pixcensus_results = array();
		$pixcensus_failed  = false;

		try {
			$pixcensus_scanner = new PIXCENSUS_Scanner();
			$pixcensus_results = $pixcensus_scanner->run();

			update_option( 'pixcensus_usage_results', $pixcensus_results, false );
		} catch ( Throwable $pixcensus_exception ) {
			$pixcensus_failed = true;
		} finally {
			$this->release_scan_lock();
		}

		if ( $pixcensus_failed ) {
			wp_send_json_error(
				array(
					'message' => __( 'The scan stopped before completion. The previous complete results were preserved.', 'pixcensus-media-audit' ),
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Scan complete.', 'pixcensus-media-audit' ),
				'results' => $pixcensus_results,
			)
		);
	}

	/**
	 * Add or remove manual IDs.
	 *
	 * @param array $ids Attachment IDs.
	 * @param bool  $add Whether to add or remove.
	 * @return array<int, int>
	 */
	private function add_manual_ids( array $ids, bool $add = true ): array {
		if ( ! $this->acquire_scan_lock() ) {
			wp_send_json_error( array( 'message' => __( 'Another operation is running. Please retry.', 'pixcensus-media-audit' ) ), 409 );
		}
		try {
			$pixcensus_ids    = $this->filter_attachment_ids( $ids );
			$pixcensus_manual = array_map( 'intval', (array) get_option( 'pixcensus_manual_used_ids', array() ) );

			if ( $add ) {
				$pixcensus_manual = array_values( array_unique( array_merge( $pixcensus_manual, $pixcensus_ids ) ) );
			} else {
				$pixcensus_manual = array_values( array_diff( $pixcensus_manual, $pixcensus_ids ) );
			}

			sort( $pixcensus_manual, SORT_NUMERIC );
			update_option( 'pixcensus_manual_used_ids', $pixcensus_manual, false );

			return $pixcensus_manual;
		} finally {
			$this->release_scan_lock();
		}
	}

	/**
	 * Mark one attachment as manually used.
	 *
	 * @return void
	 */
	public function ajax_mark_manual_used(): void {
		$this->verify_ajax_request( 'pixcensus_mark_manual_used', 'pixcensus_mark_manual_used' );

		$pixcensus_id = $this->get_posted_attachment_id();

		if ( ! $this->is_valid_attachment_id( $pixcensus_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid attachment.', 'pixcensus-media-audit' ),
				),
				400
			);
		}

		$pixcensus_manual = $this->add_manual_ids( array( $pixcensus_id ), true );

		wp_send_json_success(
			array(
				'message' => __( 'Marked as used (manual).', 'pixcensus-media-audit' ),
				'id'      => $pixcensus_id,
				'manual'  => $pixcensus_manual,
			)
		);
	}

	/**
	 * Unmark one attachment as manually used.
	 *
	 * @return void
	 */
	public function ajax_unmark_manual_used(): void {
		$this->verify_ajax_request( 'pixcensus_unmark_manual_used', 'pixcensus_unmark_manual_used' );

		$pixcensus_id = $this->get_posted_attachment_id();

		if ( ! $this->is_valid_attachment_id( $pixcensus_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid attachment.', 'pixcensus-media-audit' ),
				),
				400
			);
		}

		$pixcensus_manual = $this->add_manual_ids( array( $pixcensus_id ), false );

		wp_send_json_success(
			array(
				'message' => __( 'Unmarked (manual).', 'pixcensus-media-audit' ),
				'id'      => $pixcensus_id,
				'manual'  => $pixcensus_manual,
			)
		);
	}

	/**
	 * Bulk mark manual usage.
	 *
	 * @return void
	 */
	public function ajax_mark_manual_used_bulk(): void {
		$this->verify_ajax_request( 'pixcensus_mark_manual_used_bulk', 'pixcensus_mark_manual_used_bulk' );

		$pixcensus_input = $this->get_posted_attachment_ids();

		if ( ! $pixcensus_input['valid'] ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment selection.', 'pixcensus-media-audit' ) ), 400 );
		}

		$pixcensus_ids    = $pixcensus_input['ids'];
		$pixcensus_manual = $this->add_manual_ids( $pixcensus_ids, true );

		wp_send_json_success(
			array(
				'message' => __( 'Bulk marked as used (manual).', 'pixcensus-media-audit' ),
				'ids'     => $this->filter_attachment_ids( $pixcensus_ids ),
				'manual'  => $pixcensus_manual,
			)
		);
	}

	/**
	 * Bulk unmark manual usage.
	 *
	 * @return void
	 */
	public function ajax_unmark_manual_used_bulk(): void {
		$this->verify_ajax_request( 'pixcensus_unmark_manual_used_bulk', 'pixcensus_unmark_manual_used_bulk' );

		$pixcensus_input = $this->get_posted_attachment_ids();

		if ( ! $pixcensus_input['valid'] ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment selection.', 'pixcensus-media-audit' ) ), 400 );
		}

		$pixcensus_ids    = $pixcensus_input['ids'];
		$pixcensus_manual = $this->add_manual_ids( $pixcensus_ids, false );

		wp_send_json_success(
			array(
				'message' => __( 'Bulk unmarked (manual).', 'pixcensus-media-audit' ),
				'ids'     => $this->filter_attachment_ids( $pixcensus_ids ),
				'manual'  => $pixcensus_manual,
			)
		);
	}

	/**
	 * Export CSV.
	 *
	 * @return void
	 */
	public function export_csv(): void {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'pixcensus-media-audit' ) );
		}

		check_admin_referer( 'pixcensus_export_csv' );

		$pixcensus_tab    = '';
		$pixcensus_filter = '';

		$pixcensus_raw_tab    = filter_input( INPUT_GET, 'tab', FILTER_UNSAFE_RAW );
		$pixcensus_raw_filter = filter_input( INPUT_GET, 'filter', FILTER_UNSAFE_RAW );

		if ( is_string( $pixcensus_raw_tab ) ) {
			$pixcensus_tab = sanitize_key( $pixcensus_raw_tab );
		}

		if ( is_string( $pixcensus_raw_filter ) ) {
			$pixcensus_filter = sanitize_key( $pixcensus_raw_filter );
		}

		if ( ! in_array( $pixcensus_tab, array( 'unused', 'draft', 'used', 'trash' ), true ) ) {
			$pixcensus_tab = 'unused';
		}

		if ( ! in_array( $pixcensus_filter, array( '', 'manual', 'selection' ), true ) ) {
			$pixcensus_filter = '';
		}

		$pixcensus_usage      = get_option( 'pixcensus_usage_results', array() );
		$pixcensus_manual     = array_map( 'intval', (array) get_option( 'pixcensus_manual_used_ids', array() ) );
		$pixcensus_provenance = isset( $pixcensus_usage['provenance'] ) && is_array( $pixcensus_usage['provenance'] ) ? $pixcensus_usage['provenance'] : array();

		$pixcensus_unused_ids = array_map( 'intval', (array) ( $pixcensus_usage['unused_ids'] ?? array() ) );
		$pixcensus_draft_ids  = array_map( 'intval', (array) ( $pixcensus_usage['draft_only_ids'] ?? array() ) );
		$pixcensus_used_ids   = array_map( 'intval', (array) ( $pixcensus_usage['used_ids'] ?? array() ) );

		$pixcensus_unused_ids = array_values( array_diff( $pixcensus_unused_ids, $pixcensus_manual ) );
		$pixcensus_draft_ids  = array_values( array_diff( $pixcensus_draft_ids, $pixcensus_manual ) );
		$pixcensus_used_ids   = array_values( array_unique( array_merge( $pixcensus_used_ids, $pixcensus_manual ) ) );

		$pixcensus_export_ids = array();

		if ( 'trash' === $pixcensus_tab ) {
			$pixcensus_export_ids = $this->image_trash_ids();
		} elseif ( 'unused' === $pixcensus_tab ) {
			$pixcensus_export_ids = $pixcensus_unused_ids;
		} elseif ( 'draft' === $pixcensus_tab ) {
			$pixcensus_export_ids = $pixcensus_draft_ids;
		} else {
			$pixcensus_export_ids = $pixcensus_used_ids;

		}
		if ( 'manual' === $pixcensus_filter ) {
			$pixcensus_export_ids = array_values( array_intersect( $pixcensus_export_ids, $pixcensus_manual ) );
		}

		if ( 'selection' === $pixcensus_filter ) {
			$receipt              = $this->protected_receipt();
			$pixcensus_export_ids = array_values( array_intersect( $pixcensus_export_ids, $pixcensus_manual, $receipt['ids'] ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		send_nosniff_header();
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'pixcensus-media-audit-' . $pixcensus_tab . '.csv' ) . '"' );

		$pixcensus_output = fopen( 'php://output', 'w' );

		if ( false === $pixcensus_output ) {
			wp_die( esc_html__( 'Unable to create the CSV export.', 'pixcensus-media-audit' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The CSV is streamed to php://output, not written to the filesystem.
		fwrite( $pixcensus_output, "\xEF\xBB\xBF" );

		fputcsv(
			$pixcensus_output,
			array(
				'ID',
				'Status',
				'File',
				'URL',
				'Uploaded',
				'Filesize (bytes)',
				'Manual',
				'Count',
				'Provenance',
			)
		);

		foreach ( $pixcensus_export_ids as $pixcensus_attachment_id ) {
			$pixcensus_file        = get_attached_file( $pixcensus_attachment_id );
			$pixcensus_url         = wp_get_attachment_url( $pixcensus_attachment_id );
			$pixcensus_uploaded_at = get_the_date( 'Y-m-d H:i', $pixcensus_attachment_id );
			$pixcensus_filesize    = ( $pixcensus_file && file_exists( $pixcensus_file ) ) ? (string) filesize( $pixcensus_file ) : '';
			$pixcensus_is_manual   = in_array( $pixcensus_attachment_id, $pixcensus_manual, true ) ? '1' : '0';
			$pixcensus_sources     = isset( $pixcensus_provenance[ $pixcensus_attachment_id ] ) && is_array( $pixcensus_provenance[ $pixcensus_attachment_id ] ) ? array_values( $pixcensus_provenance[ $pixcensus_attachment_id ] ) : array();

			fputcsv(
				$pixcensus_output,
				array(
					$pixcensus_attachment_id,
					$pixcensus_tab,
					PIXCENSUS_CSV::neutralize_formula( basename( (string) $pixcensus_file ) ),
					PIXCENSUS_CSV::neutralize_formula( $pixcensus_url ),
					PIXCENSUS_CSV::neutralize_formula( $pixcensus_uploaded_at ),
					$pixcensus_filesize,
					$pixcensus_is_manual,
					count( $pixcensus_sources ),
					PIXCENSUS_CSV::neutralize_formula( implode( ' | ', $pixcensus_sources ) ),
				)
			);
		}

		exit;
	}

	/**
	 * Return the AJAX action names exposed by the plugin.
	 *
	 * @return array<int, string>
	 */
	private function get_ajax_actions(): array {
		return array(
			'pixcensus_run_scan',
			'pixcensus_mark_manual_used',
			'pixcensus_unmark_manual_used',
			'pixcensus_mark_manual_used_bulk',
			'pixcensus_unmark_manual_used_bulk',
		);
	}

	/**
	 * Return a stable response for unauthenticated plugin AJAX requests.
	 *
	 * @return void
	 */
	public function ajax_unauthorized(): void {
		wp_send_json_error(
			array(
				'message' => __( 'Authentication required.', 'pixcensus-media-audit' ),
			),
			401
		);
	}

	/**
	 * Validate the common AJAX request envelope.
	 *
	 * @param string $nonce_action Nonce action.
	 * @param string $expected_action Expected AJAX action.
	 * @return void
	 */
	private function verify_ajax_request( string $nonce_action, string $expected_action ): void {
		$pixcensus_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';

		if ( 'POST' !== $pixcensus_method ) {
			wp_send_json_error(
				array(
					'message' => __( 'This action requires an HTTP POST request.', 'pixcensus-media-audit' ),
				),
				405
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The action and nonce envelope must be read before the action-specific nonce can be verified.
		$pixcensus_action = isset( $_POST['action'] ) && is_scalar( $_POST['action'] )
			? sanitize_key( wp_unslash( (string) $_POST['action'] ) )
			: '';

		if ( $expected_action !== $pixcensus_action ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid AJAX action.', 'pixcensus-media-audit' ),
				),
				400
			);
		}

		if ( ! $this->current_user_can_manage() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions.', 'pixcensus-media-audit' ),
				),
				403
			);
		}

		$pixcensus_nonce = isset( $_POST['nonce'] ) && is_scalar( $_POST['nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) )
			: '';

		if ( '' === $pixcensus_nonce || strlen( $pixcensus_nonce ) > 128 || ! wp_verify_nonce( $pixcensus_nonce, $nonce_action ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed.', 'pixcensus-media-audit' ),
				),
				403
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Require a form submission for administrative mutations.
	 */
	private function require_admin_post(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Access denied.', 'pixcensus-media-audit' ), '', array( 'response' => 405 ) );
		}
	}

	/**
	 * Check current capability.
	 *
	 * @return bool
	 */
	private function current_user_can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Read and sanitize a single posted attachment ID.
	 *
	 * @return int
	 */
	private function get_posted_attachment_id(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every caller completes verify_ajax_request() before reading the validated action payload.
		$pixcensus_raw_id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) )
			: '';
		$pixcensus_key_id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] )
			? sanitize_key( wp_unslash( (string) $_POST['id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Do not accept malformed text that either sanitizer would transform into digits.
		if ( $pixcensus_raw_id === $pixcensus_key_id && '' !== $pixcensus_raw_id && strlen( $pixcensus_raw_id ) <= 20 && ctype_digit( $pixcensus_raw_id ) ) {
			return absint( $pixcensus_raw_id );
		}

		return 0;
	}

	/**
	 * Read and sanitize posted attachment IDs.
	 *
	 * @return array{valid: bool, ids: array<int, int>}
	 */
	private function get_posted_attachment_ids(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every caller completes verify_ajax_request() before reading the validated action payload.
		$pixcensus_raw_ids = isset( $_POST['ids'] )
			? map_deep( wp_unslash( $_POST['ids'] ), 'sanitize_text_field' )
			: null;
		$pixcensus_key_ids = isset( $_POST['ids'] )
			? map_deep( wp_unslash( $_POST['ids'] ), 'sanitize_key' )
			: null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! is_array( $pixcensus_raw_ids ) || ! is_array( $pixcensus_key_ids ) || empty( $pixcensus_raw_ids ) || count( $pixcensus_raw_ids ) > self::MAX_BULK_IDS ) {
			return array(
				'valid' => false,
				'ids'   => array(),
			);
		}

		$pixcensus_ids = array();

		// Keep the whole selection invalid if sanitization would make any value numeric.
		foreach ( $pixcensus_raw_ids as $pixcensus_index => $pixcensus_raw_id ) {
			if ( ! is_string( $pixcensus_raw_id ) || ! isset( $pixcensus_key_ids[ $pixcensus_index ] ) || ! is_string( $pixcensus_key_ids[ $pixcensus_index ] ) ) {
				return array(
					'valid' => false,
					'ids'   => array(),
				);
			}

			if ( $pixcensus_raw_id !== $pixcensus_key_ids[ $pixcensus_index ] || '' === $pixcensus_raw_id || strlen( $pixcensus_raw_id ) > 20 || ! ctype_digit( $pixcensus_raw_id ) ) {
				return array(
					'valid' => false,
					'ids'   => array(),
				);
			}

			$pixcensus_id = absint( $pixcensus_raw_id );

			if ( $pixcensus_id > 0 ) {
				$pixcensus_ids[] = $pixcensus_id;
			}
		}

		return array(
			'valid' => ! empty( $pixcensus_ids ),
			'ids'   => array_values( array_unique( $pixcensus_ids ) ),
		);
	}

	/**
	 * Validate a single attachment ID.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_valid_attachment_id( int $attachment_id ): bool {
		return $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id );
	}

	/**
	 * Filter to valid attachment IDs only.
	 *
	 * @param array $ids Candidate IDs.
	 * @return array<int, int>
	 */
	private function filter_attachment_ids( array $ids ): array {
		$pixcensus_filtered_ids = array();

		foreach ( $ids as $id ) {
			$pixcensus_attachment_id = absint( $id );

			if ( $this->is_valid_attachment_id( $pixcensus_attachment_id ) ) {
				$pixcensus_filtered_ids[] = $pixcensus_attachment_id;
			}
		}

		return array_values( array_unique( $pixcensus_filtered_ids ) );
	}

	/**
	 * Raise limits for scans when possible.
	 *
	 * @return void
	 */
	private function maybe_raise_resource_limits(): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/**
	 * Acquire an atomic, expiring scan lock.
	 *
	 * @return bool
	 */
	private function acquire_scan_lock(): bool {
		$pixcensus_now      = time();
		$pixcensus_existing = get_option( 'pixcensus_scan_lock', false );
		$pixcensus_expires  = 0;

		if ( is_array( $pixcensus_existing ) && isset( $pixcensus_existing['expires_at'] ) ) {
			$pixcensus_expires = (int) $pixcensus_existing['expires_at'];
		} elseif ( is_numeric( $pixcensus_existing ) ) {
			$pixcensus_expires = (int) $pixcensus_existing + self::SCAN_LOCK_TTL;
		}

		if ( $pixcensus_expires > $pixcensus_now ) {
			return false;
		}

		if ( false !== $pixcensus_existing && ! $this->delete_matching_lock( $pixcensus_existing ) ) {
			return false;
		}

		$pixcensus_token = wp_generate_uuid4();
		$pixcensus_lock  = array(
			'token'      => $pixcensus_token,
			'expires_at' => $pixcensus_now + self::SCAN_LOCK_TTL,
		);

		if ( ! add_option( 'pixcensus_scan_lock', $pixcensus_lock, '', false ) ) {
			return false;
		}

		$this->scan_lock_token = $pixcensus_token;

		return true;
	}

	/**
	 * Delete only the observed lock, even if another request has replaced it.
	 *
	 * @param mixed $lock Stored lock value, including the legacy numeric format.
	 */
	private function delete_matching_lock( $lock ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The Options API has no compare-and-delete; invalidate its cache after the conditional write.
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => 'pixcensus_scan_lock',
				'option_value' => maybe_serialize( $lock ),
			),
			array( '%s', '%s' )
		);
		if ( 1 === $deleted ) {
			wp_cache_delete( 'pixcensus_scan_lock', 'options' );
			return true;
		}
		return false;
	}

	/**
	 * Release the scan lock.
	 *
	 * @return void
	 */
	private function release_scan_lock(): void {
		$pixcensus_lock = get_option( 'pixcensus_scan_lock', false );

		if (
			'' !== $this->scan_lock_token &&
			is_array( $pixcensus_lock ) &&
			isset( $pixcensus_lock['token'] ) &&
			hash_equals( $this->scan_lock_token, (string) $pixcensus_lock['token'] )
		) {
			$this->delete_matching_lock( $pixcensus_lock );
		}

		$this->scan_lock_token = '';
	}
}

register_activation_hook( __FILE__, array( 'PIXCENSUS_Plugin', 'activate' ) );
PIXCENSUS_Plugin::instance();

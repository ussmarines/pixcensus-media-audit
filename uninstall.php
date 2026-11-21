<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin options for the current site.
 *
 * @return void
 */
function pixcensus_delete_plugin_options(): void {
	delete_option( 'pixcensus_usage_results' );
	delete_option( 'pixcensus_include_drafts' );
	delete_option( 'pixcensus_manual_used_ids' );
	delete_option( 'pixcensus_cdn_aliases' );
	delete_option( 'pixcensus_cdn_rewrites' );
	delete_option( 'pixcensus_scan_lock' );
	delete_option( 'pixcensus_cleanup_queue' );
	delete_option( 'pixcensus_local_trash' );
	delete_option( 'pixcensus_direct_delete' );
}

pixcensus_delete_plugin_options();

if ( is_multisite() ) {
	$pixcensus_current_blog_id = get_current_blog_id();
	$pixcensus_offset          = 0;
	$pixcensus_batch_size      = 100;

	do {
		try {
			$pixcensus_blog_ids = get_sites(
				array(
					'fields'  => 'ids',
					'number'  => $pixcensus_batch_size,
					'offset'  => $pixcensus_offset,
					'deleted' => 0,
				)
			);
		} catch ( Throwable $pixcensus_exception ) {
			break;
		}

		if ( ! is_array( $pixcensus_blog_ids ) || empty( $pixcensus_blog_ids ) ) {
			break;
		}

		foreach ( $pixcensus_blog_ids as $pixcensus_blog_id ) {
			if ( $pixcensus_current_blog_id === (int) $pixcensus_blog_id ) {
				continue;
			}

			$pixcensus_switched = false;

			try {
				$pixcensus_switched = switch_to_blog( (int) $pixcensus_blog_id );

				if ( $pixcensus_switched ) {
					pixcensus_delete_plugin_options();
				}
			} catch ( Throwable $pixcensus_exception ) {
				// Continue so one unavailable site does not prevent cleanup elsewhere.
				continue;
			} finally {
				if ( $pixcensus_switched ) {
					restore_current_blog();
				}
			}
		}

		$pixcensus_count   = count( $pixcensus_blog_ids );
		$pixcensus_offset += $pixcensus_count;
	} while ( $pixcensus_count === $pixcensus_batch_size );
}

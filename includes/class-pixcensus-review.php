<?php
/**
 * Local freshness tracking and bounded audit history.
 *
 * @package PixCensus
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PIXCENSUS_Review {
	/** Register native change notifications. */
	public static function boot(): void {
		foreach ( array( 'wp_after_insert_post', 'deleted_post', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'created_term', 'edited_term', 'delete_term', 'switch_theme' ) as $hook ) {
			add_action( $hook, array( self::class, 'invalidate' ) );
		}
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( self::class, 'option_changed' ) );
		}
		add_action( 'attachment_updated', array( self::class, 'attachment_updated' ), 10, 3 );
		add_action( 'add_attachment', array( self::class, 'invalidate' ) );
		add_action( 'deleted_post', array( self::class, 'deleted' ), 10, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	/**
	 * Register with WordPress privacy tools; their controller checks authorization.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public static function exporters( array $exporters ): array {
		$exporters['pixcensus-media-audit'] = array(
			'exporter_friendly_name' => __( 'Recent image actions', 'pixcensus-media-audit' ),
			'callback'               => array( self::class, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the site-local history eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public static function erasers( array $erasers ): array {
		$erasers['pixcensus-media-audit'] = array(
			'eraser_friendly_name' => __( 'Recent image actions', 'pixcensus-media-audit' ),
			'callback'             => array( self::class, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export at most 500 site-local events for the requested account.
	 *
	 * @param string $email Account email supplied by the WordPress privacy controller.
	 * @param int    $page Export page.
	 * @return array
	 */
	public static function export_personal_data( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user && 1 === $page ) {
			foreach ( self::history() as $index => $row ) {
				if ( (int) $row['actor'] === (int) $user->ID ) {
					$data[] = array(
						'group_id'    => 'pixcensus-history',
						'group_label' => __( 'Recent image actions', 'pixcensus-media-audit' ),
						'item_id'     => 'pixcensus-event-' . $index,
						'data'        => array(
							array(
								'name'  => __( 'Recent image actions', 'pixcensus-media-audit' ),
								'value' => wp_json_encode( $row ),
							),
						),
					);
				}
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Remove matching events through the native privacy erasure workflow.
	 *
	 * @param string $email Account email supplied by the WordPress privacy controller.
	 * @param int    $page Erasure page.
	 * @return array
	 */
	public static function erase_personal_data( string $email, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user && 1 === $page ) {
			$history   = self::history();
			$remaining = array_values(
				array_filter(
					$history,
					static function ( $row ) use ( $user ) {
						return (int) $row['actor'] !== (int) $user->ID;
					}
				)
			);
			$removed   = $remaining !== $history;
			if ( $removed ) {
				update_option( 'pixcensus_history', $remaining, false );
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/** Mark results stale without scanning on content saves. */
	public static function invalidate(): void {
		update_option( 'pixcensus_revision', wp_generate_uuid4(), false );
	}

	/**
	 * Ignore operational options and our own bookkeeping.
	 *
	 * @param string $name Changed option name.
	 */
	public static function option_changed( string $name ): void {
		if ( in_array( $name, array( 'pixcensus_include_drafts', 'pixcensus_cdn_aliases', 'pixcensus_cdn_rewrites' ), true ) || ( 0 !== strpos( $name, 'pixcensus_' ) && 0 !== strpos( $name, '_transient_' ) && 0 !== strpos( $name, '_site_transient_' ) && ! in_array( $name, array( 'cron', 'rewrite_rules', 'recently_edited', 'auto_updater.lock' ), true ) ) ) {
			self::invalidate();
		}
	}

	/** Return recent records and prune expired entries on administrator access. */
	public static function history(): array {
		$stored = (array) get_option( 'pixcensus_history', array() );
		$recent = array_values(
			array_filter(
				$stored,
				static function ( $row ) {
					return (int) $row['time'] >= time() - 90 * 24 * 60 * 60;
				}
			)
		);
		$recent = array_slice( $recent, -500 );
		if ( $stored !== $recent ) {
			update_option( 'pixcensus_history', $recent, false );
		}
		return $recent;
	}

	/**
	 * Record completed image actions only. No paths, URLs or content are retained.
	 *
	 * @param string $action Action key.
	 * @param int    $id Attachment ID.
	 */
	public static function record( string $action, int $id ): void {
		$history   = (array) get_option( 'pixcensus_history', array() );
		$history[] = array(
			'time'   => time(),
			'actor'  => get_current_user_id(),
			'action' => $action,
			'id'     => $id,
		);
		$history   = array_filter(
			$history,
			static function ( $row ) {
				return (int) $row['time'] >= time() - 90 * 24 * 60 * 60;
			}
		);
		update_option( 'pixcensus_history', array_slice( array_values( $history ), -500 ), false );
	}

	/**
	 * Attachments use their dedicated update hook rather than post transitions.
	 *
	 * @param int     $id Attachment ID.
	 * @param WP_Post $after Updated attachment.
	 * @param WP_Post $before Previous attachment.
	 */
	public static function attachment_updated( int $id, $after, $before ): void {
		self::invalidate();
		self::transition( $after->post_status, $before->post_status, $after );
	}

	/**
	 * Observe completed status transitions, including zero-retention image Trash.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post Changed post.
	 */
	public static function transition( string $new_status, string $old_status, $post ): void {
		if ( $new_status === $old_status || 'attachment' !== $post->post_type || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return;
		}
		if ( 'trash' === $new_status ) {
			self::record( 'trash', (int) $post->ID );
		} elseif ( 'trash' === $old_status ) {
			self::record( 'restore', (int) $post->ID );
		}
	}

	/**
	 * Record a completed deletion using the original post object.
	 *
	 * @param int     $id Deleted post ID.
	 * @param WP_Post $post Deleted post.
	 */
	public static function deleted( int $id, $post ): void {
		if ( 'attachment' === $post->post_type && 0 === strpos( (string) $post->post_mime_type, 'image/' ) ) {
			self::record( 'delete', $id );
		}
	}
}

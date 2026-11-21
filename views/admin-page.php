<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pixcensus_include_drafts = '1' === get_option( 'pixcensus_include_drafts', '1' );
$pixcensus_cdn_aliases    = (string) get_option( 'pixcensus_cdn_aliases', '' );
$pixcensus_cdn_rewrites   = (string) get_option( 'pixcensus_cdn_rewrites', '' );

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

$pixcensus_manual_ids = array_map( 'intval', (array) get_option( 'pixcensus_manual_used_ids', array() ) );

$pixcensus_unused_ids = array_values(
	array_diff(
		array_map( 'intval', (array) ( $pixcensus_usage['unused_ids'] ?? array() ) ),
		$pixcensus_manual_ids
	)
);

$pixcensus_draft_ids = array_values(
	array_diff(
		array_map( 'intval', (array) ( $pixcensus_usage['draft_only_ids'] ?? array() ) ),
		$pixcensus_manual_ids
	)
);

$pixcensus_used_ids = array_values(
	array_unique(
		array_merge(
			array_map( 'intval', (array) ( $pixcensus_usage['used_ids'] ?? array() ) ),
			$pixcensus_manual_ids
		)
	)
);

$pixcensus_trash_ids      = $pixcensus_trash_ids ?? array();
$pixcensus_retention_days = defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 0;
$pixcensus_raw_tab        = filter_input( INPUT_GET, 'pixcensus_tab', FILTER_UNSAFE_RAW );
$pixcensus_tab            = is_string( $pixcensus_raw_tab ) ? sanitize_key( $pixcensus_raw_tab ) : 'unused';

if ( ! in_array( $pixcensus_tab, array( 'unused', 'draft', 'used', 'trash' ), true ) ) {
	$pixcensus_tab = 'unused';
}

$pixcensus_raw_filter = filter_input( INPUT_GET, 'pixcensus_filter', FILTER_UNSAFE_RAW );
$pixcensus_filter     = is_string( $pixcensus_raw_filter ) ? sanitize_key( $pixcensus_raw_filter ) : '';

if ( ! in_array( $pixcensus_filter, array( '', 'manual' ), true ) ) {
	$pixcensus_filter = '';
}

$pixcensus_manual_used_ids = array_values( array_intersect( $pixcensus_used_ids, $pixcensus_manual_ids ) );
$pixcensus_all_ids         = array();

if ( 'trash' === $pixcensus_tab ) {
	$pixcensus_all_ids = $pixcensus_trash_ids;
} elseif ( 'unused' === $pixcensus_tab ) {
	$pixcensus_all_ids = $pixcensus_unused_ids;
} elseif ( 'draft' === $pixcensus_tab ) {
	$pixcensus_all_ids = $pixcensus_draft_ids;
} else {
	$pixcensus_all_ids = ( 'manual' === $pixcensus_filter ) ? $pixcensus_manual_used_ids : $pixcensus_used_ids;
}

if ( 'manual' === $pixcensus_filter ) {
	$pixcensus_all_ids = array_values( array_intersect( $pixcensus_all_ids, $pixcensus_manual_ids ) );
}
$pixcensus_per_page = 50;
$pixcensus_total    = count( $pixcensus_all_ids );

$pixcensus_raw_page = filter_input( INPUT_GET, 'pixcensus_page', FILTER_VALIDATE_INT );
$pixcensus_page     = ( false !== $pixcensus_raw_page && null !== $pixcensus_raw_page ) ? max( 1, absint( $pixcensus_raw_page ) ) : 1;
$pixcensus_page     = min( $pixcensus_page, max( 1, (int) ceil( $pixcensus_total / $pixcensus_per_page ) ) );
$pixcensus_offset   = ( $pixcensus_page - 1 ) * $pixcensus_per_page;
$pixcensus_ids      = array_slice( $pixcensus_all_ids, $pixcensus_offset, $pixcensus_per_page );

$pixcensus_scanned_at = ! empty( $pixcensus_usage['scanned_at'] )
	? wp_date( 'Y-m-d H:i', (int) $pixcensus_usage['scanned_at'] )
	: __( 'Never', 'pixcensus-media-audit' );

$pixcensus_export_url = wp_nonce_url(
	add_query_arg(
		array(
			'action' => 'pixcensus_export_csv',
			'tab'    => $pixcensus_tab,
			'filter' => ( 'manual' === $pixcensus_filter ) ? 'manual' : null,
		),
		admin_url( 'admin-post.php' )
	),
	'pixcensus_export_csv'
);

$pixcensus_total_pages = max( 1, (int) ceil( $pixcensus_total / $pixcensus_per_page ) );

$pixcensus_raw_notice = filter_input( INPUT_GET, 'pixcensus_notice', FILTER_UNSAFE_RAW );
$pixcensus_notice     = is_string( $pixcensus_raw_notice ) ? sanitize_key( $pixcensus_raw_notice ) : '';
?>
<div class="wrap" id="pixcensus-admin">
	<?php if ( 'settings-saved' === $pixcensus_notice ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved.', 'pixcensus-media-audit' ); ?></p>
		</div>
	<?php elseif ( 'invalid-cdn-settings' === $pixcensus_notice ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'CDN settings were not saved. Use at most 20 host-only aliases and 20 FROM => /wp-content/uploads rewrite rules.', 'pixcensus-media-audit' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="pixcensus-hero">
		<img class="pixcensus-brand-mark" src="<?php echo esc_url( plugins_url( 'assets/pixcensus-media-audit-mark.svg', dirname( __DIR__ ) . '/pixcensus-media-audit.php' ) ); ?>" alt="" aria-hidden="true" />
		<div class="pixcensus-hero-copy"><h1><?php esc_html_e( 'PixCensus — Media Usage Audit', 'pixcensus-media-audit' ); ?></h1><div class="pixcensus-sub"><?php esc_html_e( 'Find where images appear and manage your image Trash.', 'pixcensus-media-audit' ); ?></div></div>
		<span class="pixcensus-version"><?php echo esc_html( 'v' . PIXCENSUS_VERSION ); ?></span>
	</div>

	<div class="pixcensus-scan-bar"><button type="button" class="button button-primary" id="pixcensus-run-scan">
						<?php echo esc_html( ! empty( $pixcensus_usage['scanned_at'] ) ? __( 'Run scan again', 'pixcensus-media-audit' ) : __( 'Run scan', 'pixcensus-media-audit' ) ); ?>
					</button> <span><?php esc_html_e( 'Last scan', 'pixcensus-media-audit' ); ?> · <?php echo esc_html( $pixcensus_scanned_at ); ?></span></div>
	<details class="pixcensus-settings"><summary><?php esc_html_e( 'Scan and CDN settings', 'pixcensus-media-audit' ); ?></summary>
	<div class="pixcensus-grid">
		<div class="pixcensus-card">
			<h2><?php esc_html_e( 'Scan', 'pixcensus-media-audit' ); ?></h2>
			<p class="pixcensus-help"><?php esc_html_e( 'The audit is not live. Re-run the scan to reflect content changes.', 'pixcensus-media-audit' ); ?></p>
			<p class="pixcensus-help"><?php esc_html_e( 'Tip: unchecking drafts makes the scan faster.', 'pixcensus-media-audit' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pixcensus_save_settings" />
				<input type="hidden" name="pixcensus_section" value="scan" />
				<?php wp_nonce_field( 'pixcensus_save_scan_settings', 'pixcensus_settings_nonce' ); ?>

				<label class="pixcensus-form-row">
					<input type="checkbox" name="pixcensus_include_drafts" value="1" <?php checked( $pixcensus_include_drafts, true ); ?> />
					<?php esc_html_e( 'Include drafts in scan (Draft-only list).', 'pixcensus-media-audit' ); ?>
				</label>

				<p class="submit">
					<button type="submit" class="button"><?php esc_html_e( 'Save settings', 'pixcensus-media-audit' ); ?></button>

				</p>

				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: last scan datetime. */
							__( 'Last scan: %s', 'pixcensus-media-audit' ),
							$pixcensus_scanned_at
						)
					);
					?>
				</p>
			</form>

			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'Disclaimer:', 'pixcensus-media-audit' ); ?></strong> <?php esc_html_e( 'Images referenced via custom CSS, HTML widgets, theme files, plugin files, or external/CDN domains may require manual review.', 'pixcensus-media-audit' ); ?></p>
			</div>

			<div class="notice notice-error inline">
				<p><strong><?php esc_html_e( 'Backup:', 'pixcensus-media-audit' ); ?></strong> <?php esc_html_e( 'Make a full backup before deleting any images from the Media Library.', 'pixcensus-media-audit' ); ?></p>
			</div>
		</div>

		<div class="pixcensus-card">
			<h2><?php esc_html_e( 'CDN support', 'pixcensus-media-audit' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pixcensus_save_settings" />
				<input type="hidden" name="pixcensus_section" value="cdn" />
				<?php wp_nonce_field( 'pixcensus_save_cdn_settings', 'pixcensus_settings_nonce' ); ?>

				<label class="pixcensus-form-row">
					<span><?php esc_html_e( 'CDN aliases (comma-separated)', 'pixcensus-media-audit' ); ?></span>
					<input type="text" name="pixcensus_cdn_aliases" value="<?php echo esc_attr( $pixcensus_cdn_aliases ); ?>" class="regular-text" placeholder="cdn.example.com, media.example.net" />
				</label>

				<label class="pixcensus-form-row">
					<span><?php esc_html_e( 'Advanced CDN rewrites (one per line, format: FROM => TO)', 'pixcensus-media-audit' ); ?></span>
					<textarea name="pixcensus_cdn_rewrites" rows="4" class="large-text code" placeholder="https://cdn.example.com/assets => /wp-content/uploads&#10;/media => /wp-content/uploads"><?php echo esc_textarea( $pixcensus_cdn_rewrites ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Each rule is applied read-only during the scan.', 'pixcensus-media-audit' ); ?></span>
				</label>

				<p class="submit">
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save CDN settings', 'pixcensus-media-audit' ); ?></button>
				</p>
			</form>
		</div>
	</div>

	</details>
	<?php
	$pixcensus_tabs = array(
		'unused' => array( __( 'To review', 'pixcensus-media-audit' ), count( $pixcensus_unused_ids ) ),
		'draft'  => array( __( 'Drafts', 'pixcensus-media-audit' ), count( $pixcensus_draft_ids ) ),
		'used'   => array( __( 'In use', 'pixcensus-media-audit' ), count( $pixcensus_used_ids ) ),
		'trash'  => array( __( 'Trash', 'pixcensus-media-audit' ), count( $pixcensus_trash_ids ) ),
	);
	?>
	<details class="pixcensus-settings">
		<summary><?php esc_html_e( 'Which images are protected?', 'pixcensus-media-audit' ); ?></summary>
		<p><?php esc_html_e( 'Mark as used gives an image manual protection against Trash. In the In use tab, filter for manually protected images, select them and choose Remove manual protection.', 'pixcensus-media-audit' ); ?></p>
		<p><?php esc_html_e( 'A detected use is listed under Where used. You can move a referenced image to Trash, but deleting it can break the linked page or draft. Replace or remove it in the content and rescan to clear that use.', 'pixcensus-media-audit' ); ?></p>
	</details>
	<nav class="nav-tab-wrapper pixcensus-tabs" aria-label="<?php esc_attr_e( 'Image categories', 'pixcensus-media-audit' ); ?>">
		<?php foreach ( $pixcensus_tabs as $pixcensus_slug => $pixcensus_category ) : ?>
			<a class="nav-tab <?php echo esc_attr( $pixcensus_slug === $pixcensus_tab ? 'nav-tab-active' : '' ); ?>"
			<?php
			if ( $pixcensus_slug === $pixcensus_tab ) {
				echo 'aria-current="page"';
			}
			?>
			href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page'          => 'pixcensus-audit',
						'pixcensus_tab' => $pixcensus_slug,
					),
					admin_url( 'upload.php' )
				)
			);
			?>
"><?php echo esc_html( $pixcensus_category[0] ); ?> <span>(<?php echo esc_html( (string) $pixcensus_category[1] ); ?>)</span></a>
		<?php endforeach; ?>
	</nav>
	<?php
	$pixcensus_changed = filter_input( INPUT_GET, 'pixcensus_changed', FILTER_VALIDATE_INT );
	if ( is_int( $pixcensus_changed ) ) :
		?>
		<p class="notice notice-info inline" role="status">
		<?php
			$pixcensus_messages = array(
				'trash' => __( 'Images moved to Trash:', 'pixcensus-media-audit' ),
			);
			$pixcensus_result   = filter_input( INPUT_GET, 'pixcensus_result', FILTER_UNSAFE_RAW );
			echo esc_html( $pixcensus_messages[ is_string( $pixcensus_result ) ? $pixcensus_result : '' ] ?? __( 'Images processed:', 'pixcensus-media-audit' ) );
			?>
			<?php echo esc_html( (string) $pixcensus_changed ); ?></p>
	<?php endif; ?>
	<?php
	$pixcensus_token = filter_input( INPUT_GET, 'confirmation', FILTER_UNSAFE_RAW );
	if ( 'trash' === $pixcensus_tab && is_string( $pixcensus_token ) && $pixcensus_token ) :
		$pixcensus_pending = get_transient( 'pixcensus_delete_' . get_current_user_id() . '_' . sanitize_key( $pixcensus_token ) );
		if ( is_array( $pixcensus_pending ) && $pixcensus_pending ) :
			?>
			<form class="notice notice-error inline pixcensus-confirm" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<h2><?php esc_html_e( 'Confirm permanent deletion', 'pixcensus-media-audit' ); ?></h2>
				<p><?php esc_html_e( 'WordPress will delete these images and their generated sizes. This cannot be undone.', 'pixcensus-media-audit' ); ?></p>
				<strong><?php esc_html_e( 'Images to delete:', 'pixcensus-media-audit' ); ?> <?php echo esc_html( (string) count( $pixcensus_pending ) ); ?></strong>
				<ul class="pixcensus-confirm-list">
				<?php
				foreach ( $pixcensus_pending as $pixcensus_id ) :
					?>
					<li><?php echo esc_html( get_the_title( $pixcensus_id ) . ' (#' . $pixcensus_id . ')' ); ?></li><?php endforeach; ?></ul>
				<input type="hidden" name="confirmation" value="<?php echo esc_attr( $pixcensus_token ); ?>" />
				<?php
				$pixcensus_confirm = 'confirm_delete';
				wp_nonce_field( 'pixcensus_cleanup_' . $pixcensus_confirm, 'pixcensus_nonce_' . $pixcensus_confirm, false );
				?>
				<a class="button" href="<?php echo esc_url( admin_url( 'upload.php?page=pixcensus-audit&pixcensus_tab=' . $pixcensus_tab ) ); ?>"><?php esc_html_e( 'Cancel', 'pixcensus-media-audit' ); ?></a>
				<button class="button pixcensus-danger" name="action" value="<?php echo esc_attr( 'pixcensus_' . $pixcensus_confirm ); ?>"><?php esc_html_e( 'Confirm permanent deletion', 'pixcensus-media-audit' ); ?></button>
			</form>
		<?php else : ?>
			<p role="status"><?php esc_html_e( 'This confirmation expired. Review the Trash again.', 'pixcensus-media-audit' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<div class="pixcensus-toolbar">
		<div class="pixcensus-right">
			<div class="pixcensus-columns">
				<button class="button" id="pixcensus-columns-toggle" aria-controls="pixcensus-columns-panel" aria-expanded="false"><?php esc_html_e( 'Columns', 'pixcensus-media-audit' ); ?></button>
				<div id="pixcensus-columns-panel" class="pixcensus-columns-panel" role="group" style="display:none;">
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-thumb" data-col="thumb" checked /> <?php esc_html_e( 'Preview', 'pixcensus-media-audit' ); ?></label>
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-id" data-col="id" checked /> <?php esc_html_e( 'ID', 'pixcensus-media-audit' ); ?></label>
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-file" data-col="file" checked disabled /> <?php esc_html_e( 'File', 'pixcensus-media-audit' ); ?></label>
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-uploaded" data-col="uploaded" checked /> <?php esc_html_e( 'Uploaded', 'pixcensus-media-audit' ); ?></label>
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-provenance" data-col="provenance" checked /> <?php esc_html_e( 'Where used', 'pixcensus-media-audit' ); ?></label>
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-count" data-col="count" checked /> <?php esc_html_e( 'Locations', 'pixcensus-media-audit' ); ?></label>
					<div class="pixcensus-columns-actions">
						<a href="#" id="pixcensus-columns-reset"><?php esc_html_e( 'Reset', 'pixcensus-media-audit' ); ?></a>
						<span class="description"><?php esc_html_e( 'Saved locally in your browser.', 'pixcensus-media-audit' ); ?></span>
					</div>
				</div>
			</div>

			<div class="pixcensus-quick">
				<input type="search" aria-label="<?php esc_attr_e( 'Search this page by file, ID or publication title', 'pixcensus-media-audit' ); ?>" id="pixcensus-quick-filter" placeholder="<?php esc_attr_e( 'Search this page…', 'pixcensus-media-audit' ); ?>" />
				<span id="pixcensus-quick-count" role="status" class="description"></span>
			</div>

				<form method="get" class="pixcensus-inline-form">
					<input type="hidden" name="page" value="pixcensus-audit" />
					<input type="hidden" name="pixcensus_tab" value="<?php echo esc_attr( $pixcensus_tab ); ?>" />
					<label>
						<?php esc_html_e( 'Filter:', 'pixcensus-media-audit' ); ?>
						<select name="pixcensus_filter">
							<option value="" <?php selected( $pixcensus_filter, '' ); ?>><?php esc_html_e( 'All images', 'pixcensus-media-audit' ); ?></option>
							<option value="manual" <?php selected( $pixcensus_filter, 'manual' ); ?>><?php esc_html_e( 'Manually protected', 'pixcensus-media-audit' ); ?></option>
						</select>
					</label> <button class="button"><?php esc_html_e( 'Filter', 'pixcensus-media-audit' ); ?></button>
				</form>
			<a class="button" href="<?php echo esc_url( $pixcensus_export_url ); ?>"><?php esc_html_e( 'Export CSV', 'pixcensus-media-audit' ); ?></a>
		</div>
	</div>

	<form id="pixcensus-cleanup" class="pixcensus-bulk" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<label><input type="checkbox" class="pixcensus-select-all-toggle" /> <?php esc_html_e( 'Select all visible', 'pixcensus-media-audit' ); ?></label>
		<output id="pixcensus-selected" aria-live="polite" data-label="<?php esc_attr_e( 'Selected', 'pixcensus-media-audit' ); ?>"></output>
		<?php
		$pixcensus_actions = array();
		if ( 'trash' !== $pixcensus_tab ) {
			$pixcensus_trash_action = 'trash_reviewed';
			wp_nonce_field( 'pixcensus_cleanup_' . $pixcensus_trash_action . '_' . $pixcensus_tab, 'pixcensus_nonce_' . $pixcensus_trash_action, false );
			?>
			<input type="hidden" name="pixcensus_tab" value="<?php echo esc_attr( $pixcensus_tab ); ?>" />
			<button class="button button-primary" name="action" value="pixcensus_trash_reviewed"
			<?php
			if ( $pixcensus_retention_days <= 0 ) :
				?>
				disabled data-unavailable aria-describedby="pixcensus-retention"<?php endif; ?>><?php esc_html_e( 'Move to Trash', 'pixcensus-media-audit' ); ?></button>
			<?php
		}
		if ( 'trash' === $pixcensus_tab ) {
			$pixcensus_actions['restore']       = __( 'Restore selected', 'pixcensus-media-audit' );
			$pixcensus_actions['review_delete'] = __( 'Delete selected permanently…', 'pixcensus-media-audit' );
			if ( $pixcensus_trash_ids ) {
				$pixcensus_actions['review_empty'] = __( 'Empty image Trash…', 'pixcensus-media-audit' );
			}
		}
		foreach ( $pixcensus_actions as $pixcensus_action => $pixcensus_label ) :
			wp_nonce_field( 'pixcensus_cleanup_' . $pixcensus_action, 'pixcensus_nonce_' . $pixcensus_action, false );
			?>
			<button class="button" name="action" value="<?php echo esc_attr( 'pixcensus_' . $pixcensus_action ); ?>"><?php echo esc_html( $pixcensus_label ); ?></button>
		<?php endforeach; ?>
		<?php if ( 'unused' === $pixcensus_tab || 'draft' === $pixcensus_tab ) : ?>
			<button type="button" class="button" id="pixcensus-bulk-mark"><?php esc_html_e( 'Mark as used', 'pixcensus-media-audit' ); ?></button>
		<?php elseif ( 'used' === $pixcensus_tab ) : ?>
			<button type="button" class="button" id="pixcensus-bulk-unmark" aria-describedby="pixcensus-protection-help"><?php esc_html_e( 'Remove manual protection', 'pixcensus-media-audit' ); ?></button>
		<?php endif; ?>
		<button type="button" class="button" id="pixcensus-open-media" disabled><?php esc_html_e( 'Open in Media', 'pixcensus-media-audit' ); ?></button>
	</form>
	<?php if ( 'used' === $pixcensus_tab ) : ?>
		<p id="pixcensus-protection-help" hidden><?php esc_html_e( 'Only manually protected images can have their protection removed. Open the linked publication to review a detected use.', 'pixcensus-media-audit' ); ?></p>
	<?php endif; ?>
	<?php if ( $pixcensus_retention_days <= 0 ) : ?>
		<p id="pixcensus-retention"><?php esc_html_e( 'WordPress retention is set to zero days. Moving images to Trash requires a positive retention period on this site.', 'pixcensus-media-audit' ); ?></p>
	<?php elseif ( 'trash' === $pixcensus_tab ) : ?>
		<p>
		<?php
		/* translators: %d: WordPress Trash retention in days. */
		echo esc_html( sprintf( _n( 'WordPress keeps images for %d day, then deletes them at its next scheduled cleanup. You can restore them or confirm permanent deletion here.', 'WordPress keeps images for %d days, then deletes them at its next scheduled cleanup. You can restore them or confirm permanent deletion here.', $pixcensus_retention_days, 'pixcensus-media-audit' ), $pixcensus_retention_days ) );
		?>
		</p>
	<?php endif; ?>
	<p class="pixcensus-page-summary">
	<?php
		echo esc_html(
			sprintf(
				/* translators: 1: first visible image, 2: last visible image, 3: total matching images. */
				__( 'Images %1$d–%2$d of %3$d', 'pixcensus-media-audit' ),
				$pixcensus_total ? $pixcensus_offset + 1 : 0,
				min( $pixcensus_offset + $pixcensus_per_page, $pixcensus_total ),
				$pixcensus_total
			)
		);
		?>
		</p>
	<?php if ( $pixcensus_total_pages > 1 ) : ?>
		<div class="tablenav top">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg(
								array(
									'page'             => 'pixcensus-audit',
									'pixcensus_tab'    => $pixcensus_tab,
									'pixcensus_filter' => $pixcensus_filter,
									'pixcensus_page'   => '%#%',
								),
								admin_url( 'upload.php' )
							),
							'format'    => '',
							'current'   => $pixcensus_page,
							'total'     => $pixcensus_total_pages,
							'prev_text' => esc_html__( '« Previous', 'pixcensus-media-audit' ),
							'next_text' => esc_html__( 'Next »', 'pixcensus-media-audit' ),
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<div class="pixcensus-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Image results', 'pixcensus-media-audit' ); ?>"><table class="widefat striped"><caption class="screen-reader-text"><?php echo esc_html( $pixcensus_tabs[ $pixcensus_tab ][0] ); ?></caption>
		<thead>
			<tr>
				<th scope="col" style="width:28px;"><input aria-label="<?php esc_attr_e( 'Select all images on this page', 'pixcensus-media-audit' ); ?>" type="checkbox" class="pixcensus-select-all-toggle" /></th>
				<th scope="col" data-col="thumb"><?php esc_html_e( 'Preview', 'pixcensus-media-audit' ); ?></th>
				<th scope="col" data-col="id"><?php esc_html_e( 'ID', 'pixcensus-media-audit' ); ?></th>
				<th scope="col" data-col="file"><?php esc_html_e( 'File', 'pixcensus-media-audit' ); ?></th>
				<th scope="col" data-col="uploaded"><?php esc_html_e( 'Uploaded', 'pixcensus-media-audit' ); ?></th>
				<th scope="col" data-col="provenance"><?php esc_html_e( 'Where used', 'pixcensus-media-audit' ); ?></th>
				<th scope="col" data-col="count"><?php esc_html_e( 'Locations', 'pixcensus-media-audit' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $pixcensus_ids ) ) : ?>
				<?php foreach ( $pixcensus_ids as $pixcensus_attachment_id ) : ?>
					<?php
					$pixcensus_file_path  = get_attached_file( $pixcensus_attachment_id );
					$pixcensus_thumb_html = wp_get_attachment_image( $pixcensus_attachment_id, array( 60, 60 ), true, array( 'style' => 'max-width:60px;height:auto' ) );
					$pixcensus_edit_url   = admin_url( 'post.php?post=' . $pixcensus_attachment_id . '&action=edit' );
					$pixcensus_provenance = ( isset( $pixcensus_usage['provenance'][ $pixcensus_attachment_id ] ) && is_array( $pixcensus_usage['provenance'][ $pixcensus_attachment_id ] ) ) ? array_values( $pixcensus_usage['provenance'][ $pixcensus_attachment_id ] ) : array();
					$pixcensus_locations  = PIXCENSUS_Usage::locations( $pixcensus_provenance );
					$pixcensus_count      = count( $pixcensus_locations );
					$pixcensus_haystack   = strtolower(
						implode(
							' ',
							array(
								(string) $pixcensus_attachment_id,
								$pixcensus_file_path ? basename( $pixcensus_file_path ) : '',
								implode( ' ', array_column( $pixcensus_locations, 'name' ) ),
							)
						)
					);
					?>
					<tr class="pixcensus-row" id="pixcensus-row-<?php echo esc_attr( $pixcensus_attachment_id ); ?>" data-pixcensus-haystack="<?php echo esc_attr( $pixcensus_haystack ); ?>">
						<td><input type="checkbox" class="pixcensus-select" data-media-url="<?php echo esc_url( 'trash' === $pixcensus_tab ? admin_url( 'upload.php?mode=list&attachment-filter=trash' ) : $pixcensus_edit_url ); ?>" data-manual="<?php echo in_array( $pixcensus_attachment_id, $pixcensus_manual_ids, true ) ? '1' : '0'; ?>" aria-label="<?php echo esc_attr( get_the_title( $pixcensus_attachment_id ) . ' (#' . $pixcensus_attachment_id . ')' ); ?>" name="ids[]" form="pixcensus-cleanup" value="<?php echo esc_attr( $pixcensus_attachment_id ); ?>" /></td>
						<td data-col="thumb" class="pixcensus-thumb"><?php echo $pixcensus_thumb_html ? wp_kses_post( $pixcensus_thumb_html ) : '&nbsp;'; ?></td>
						<td data-col="id"><?php echo esc_html( (string) $pixcensus_attachment_id ); ?></td>
						<td data-col="file"><?php echo esc_html( $pixcensus_file_path ? basename( $pixcensus_file_path ) : __( '(unknown)', 'pixcensus-media-audit' ) ); ?></td>
						<td data-col="uploaded"><?php echo esc_html( get_the_date( 'Y-m-d H:i', $pixcensus_attachment_id ) ); ?></td>
						<td data-col="provenance">
							<?php if ( in_array( $pixcensus_attachment_id, $pixcensus_manual_ids, true ) ) : ?>
								<strong><?php esc_html_e( 'Manually marked as used', 'pixcensus-media-audit' ); ?></strong>
							<?php endif; ?>
							<?php if ( $pixcensus_locations ) : ?>
								<ul class="pixcensus-locations">
								<?php foreach ( $pixcensus_locations as $pixcensus_location ) : ?>
									<li><?php if ( $pixcensus_location['url'] ) : ?>
										<a href="<?php echo esc_url( $pixcensus_location['url'] ); ?>"><?php echo esc_html( $pixcensus_location['name'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $pixcensus_location['name'] ); ?>
									<?php endif; ?></li>
								<?php endforeach; ?>
								</ul>
							<?php elseif ( ! in_array( $pixcensus_attachment_id, $pixcensus_manual_ids, true ) ) : ?>
								<?php esc_html_e( 'No known use', 'pixcensus-media-audit' ); ?>
							<?php endif; ?>
						</td>
						<td data-col="count"><?php echo esc_html( (string) $pixcensus_count ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No items.', 'pixcensus-media-audit' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table></div>

	<?php if ( $pixcensus_total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg(
								array(
									'page'             => 'pixcensus-audit',
									'pixcensus_tab'    => $pixcensus_tab,
									'pixcensus_filter' => $pixcensus_filter,
									'pixcensus_page'   => '%#%',
								),
								admin_url( 'upload.php' )
							),
							'format'    => '',
							'current'   => $pixcensus_page,
							'total'     => $pixcensus_total_pages,
							'prev_text' => esc_html__( '« Previous', 'pixcensus-media-audit' ),
							'next_text' => esc_html__( 'Next »', 'pixcensus-media-audit' ),
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<div class="pixcensus-card" style="margin-top: 20px;">
		<h2><?php esc_html_e( 'Support the project', 'pixcensus-media-audit' ); ?></h2>
		<p><?php esc_html_e( 'If PixCensus — Media Usage Audit has been useful to you, you can support its continued development with an optional donation.', 'pixcensus-media-audit' ); ?></p>
		<p>
			<a class="button button-secondary" href="<?php echo esc_url( 'https://paypal.me/ussmarinesdot' ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Support via PayPal', 'pixcensus-media-audit' ); ?>
			</a>
		</p>
	</div>

</div>

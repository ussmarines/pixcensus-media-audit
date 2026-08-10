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

$pixcensus_raw_tab = filter_input( INPUT_GET, 'pixcensus_tab', FILTER_UNSAFE_RAW );
$pixcensus_tab     = is_string( $pixcensus_raw_tab ) ? sanitize_key( $pixcensus_raw_tab ) : 'unused';

if ( ! in_array( $pixcensus_tab, array( 'unused', 'draft', 'used' ), true ) ) {
	$pixcensus_tab = 'unused';
}

$pixcensus_raw_filter = filter_input( INPUT_GET, 'pixcensus_filter', FILTER_UNSAFE_RAW );
$pixcensus_filter     = is_string( $pixcensus_raw_filter ) ? sanitize_key( $pixcensus_raw_filter ) : '';

if ( ! in_array( $pixcensus_filter, array( '', 'manual' ), true ) ) {
	$pixcensus_filter = '';
}

$pixcensus_manual_used_ids = array_values( array_intersect( $pixcensus_used_ids, $pixcensus_manual_ids ) );
$pixcensus_all_ids         = array();

if ( 'unused' === $pixcensus_tab ) {
	$pixcensus_all_ids = $pixcensus_unused_ids;
} elseif ( 'draft' === $pixcensus_tab ) {
	$pixcensus_all_ids = $pixcensus_draft_ids;
} else {
	$pixcensus_all_ids = ( 'manual' === $pixcensus_filter ) ? $pixcensus_manual_used_ids : $pixcensus_used_ids;
}

$pixcensus_per_page = 50;
$pixcensus_total    = count( $pixcensus_all_ids );

$pixcensus_raw_page = filter_input( INPUT_GET, 'pixcensus_page', FILTER_VALIDATE_INT );
$pixcensus_page     = ( false !== $pixcensus_raw_page && null !== $pixcensus_raw_page ) ? max( 1, absint( $pixcensus_raw_page ) ) : 1;
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
			'filter' => ( 'used' === $pixcensus_tab && 'manual' === $pixcensus_filter ) ? 'manual' : null,
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
		<div class="pixcensus-hero-copy"><h1><?php esc_html_e( 'PixCensus — Media Usage Audit', 'pixcensus-media-audit' ); ?></h1><div class="pixcensus-sub"><?php esc_html_e( 'Audit image usage with provenance and safe review tools.', 'pixcensus-media-audit' ); ?></div></div>
		<span class="pixcensus-version"><?php echo esc_html( 'v' . PIXCENSUS_VERSION ); ?></span>
	</div>

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
					<button type="button" class="button button-primary" id="pixcensus-run-scan">
						<?php echo esc_html( ! empty( $pixcensus_usage['scanned_at'] ) ? __( 'Run scan again', 'pixcensus-media-audit' ) : __( 'Run scan', 'pixcensus-media-audit' ) ); ?>
					</button>
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

	<h2 class="nav-tab-wrapper pixcensus-tabs">
		<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'page'          => 'pixcensus-audit',
					'pixcensus_tab' => 'unused',
				),
				admin_url( 'upload.php' )
			)
		);
		?>
		" class="nav-tab <?php echo esc_attr( 'unused' === $pixcensus_tab ? 'nav-tab-active' : '' ); ?>">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of unused images. */
					__( 'Unused (%d)', 'pixcensus-media-audit' ),
					count( $pixcensus_unused_ids )
				)
			);
			?>
		</a>
		<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'page'          => 'pixcensus-audit',
					'pixcensus_tab' => 'draft',
				),
				admin_url( 'upload.php' )
			)
		);
		?>
		" class="nav-tab <?php echo esc_attr( 'draft' === $pixcensus_tab ? 'nav-tab-active' : '' ); ?>">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of images used only in drafts. */
					__( 'Draft-only (%d)', 'pixcensus-media-audit' ),
					count( $pixcensus_draft_ids )
				)
			);
			?>
		</a>
		<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'page'          => 'pixcensus-audit',
					'pixcensus_tab' => 'used',
				),
				admin_url( 'upload.php' )
			)
		);
		?>
		" class="nav-tab <?php echo esc_attr( 'used' === $pixcensus_tab ? 'nav-tab-active' : '' ); ?>">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of used images. */
					__( 'Used (published) (%d)', 'pixcensus-media-audit' ),
					count( $pixcensus_used_ids )
				)
			);
			?>
		</a>
	</h2>

	<p>
		<a class="button" href="<?php echo esc_url( $pixcensus_export_url ); ?>"><?php esc_html_e( 'Export current tab (CSV with provenance)', 'pixcensus-media-audit' ); ?></a>
	</p>

	<div class="pixcensus-toolbar">
		<div class="pixcensus-left">
			<label><input type="checkbox" class="pixcensus-select-all-toggle" /> <?php esc_html_e( 'Select all visible', 'pixcensus-media-audit' ); ?></label>

			<?php if ( 'unused' === $pixcensus_tab ) : ?>
				<button class="button button-secondary" id="pixcensus-bulk-mark"><?php esc_html_e( 'Mark selected as Used (manual)', 'pixcensus-media-audit' ); ?></button>
			<?php elseif ( 'used' === $pixcensus_tab ) : ?>
				<button class="button button-secondary" id="pixcensus-bulk-unmark"><?php esc_html_e( 'Unmark selected (manual)', 'pixcensus-media-audit' ); ?></button>

				<?php if ( 'manual' === $pixcensus_filter ) : ?>
					<span class="pixcensus-chip"><?php esc_html_e( 'Manual only', 'pixcensus-media-audit' ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div class="pixcensus-right">
			<div class="pixcensus-columns">
				<button class="button" id="pixcensus-columns-toggle" aria-controls="pixcensus-columns-panel" aria-expanded="false"><?php esc_html_e( 'Columns', 'pixcensus-media-audit' ); ?></button>
				<div id="pixcensus-columns-panel" class="pixcensus-columns-panel" role="group" style="display:none;">
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-thumb" data-col="thumb" checked /> <?php esc_html_e( 'Thumb', 'pixcensus-media-audit' ); ?></label>
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-id" data-col="id" checked /> <?php esc_html_e( 'ID', 'pixcensus-media-audit' ); ?></label>
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-file" data-col="file" checked /> <?php esc_html_e( 'File', 'pixcensus-media-audit' ); ?></label>
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-uploaded" data-col="uploaded" checked /> <?php esc_html_e( 'Uploaded', 'pixcensus-media-audit' ); ?></label>
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-provenance" data-col="provenance" checked /> <?php esc_html_e( 'Where used', 'pixcensus-media-audit' ); ?></label>
					<label><input type="checkbox" class="pixcensus-col-toggle" id="pixcensus-col-count" data-col="count" checked /> <?php esc_html_e( 'Count', 'pixcensus-media-audit' ); ?></label>
					<div class="pixcensus-columns-actions">
						<a href="#" id="pixcensus-columns-reset"><?php esc_html_e( 'Reset', 'pixcensus-media-audit' ); ?></a>
						<span class="description"><?php esc_html_e( 'Saved locally in your browser.', 'pixcensus-media-audit' ); ?></span>
					</div>
				</div>
			</div>

			<div class="pixcensus-quick">
				<input type="search" id="pixcensus-quick-filter" placeholder="<?php esc_attr_e( 'Quick filter (ID, file, provenance)…', 'pixcensus-media-audit' ); ?>" />
				<span id="pixcensus-quick-count" class="description"></span>
			</div>

			<div class="pixcensus-density">
				<button class="button button-primary" data-pixcensus-density="comfortable" aria-pressed="true"><?php esc_html_e( 'Comfortable', 'pixcensus-media-audit' ); ?></button>
				<button class="button" data-pixcensus-density="compact" aria-pressed="false"><?php esc_html_e( 'Compact', 'pixcensus-media-audit' ); ?></button>
			</div>

			<?php if ( 'used' === $pixcensus_tab ) : ?>
				<form method="get" class="pixcensus-inline-form">
					<input type="hidden" name="page" value="pixcensus-audit" />
					<input type="hidden" name="pixcensus_tab" value="used" />
					<label>
						<?php esc_html_e( 'Filter:', 'pixcensus-media-audit' ); ?>
						<select name="pixcensus_filter" onchange="this.form.submit()">
							<option value="" <?php selected( $pixcensus_filter, '' ); ?>><?php esc_html_e( 'All used', 'pixcensus-media-audit' ); ?></option>
							<option value="manual" <?php selected( $pixcensus_filter, 'manual' ); ?>><?php esc_html_e( 'Only manual (false negatives)', 'pixcensus-media-audit' ); ?></option>
						</select>
					</label>
				</form>
			<?php endif; ?>
		</div>
	</div>

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

	<table class="widefat fixed striped">
		<thead>
			<tr>
				<th style="width:28px;"><input type="checkbox" class="pixcensus-select-all-toggle" /></th>
				<th data-col="thumb"><?php esc_html_e( 'Thumb', 'pixcensus-media-audit' ); ?></th>
				<th data-col="id"><?php esc_html_e( 'ID', 'pixcensus-media-audit' ); ?></th>
				<th data-col="file"><?php esc_html_e( 'File', 'pixcensus-media-audit' ); ?></th>
				<th data-col="uploaded"><?php esc_html_e( 'Uploaded', 'pixcensus-media-audit' ); ?></th>
				<th data-col="provenance"><?php esc_html_e( 'Where used (provenance)', 'pixcensus-media-audit' ); ?></th>
				<th data-col="count"><?php esc_html_e( 'Count', 'pixcensus-media-audit' ); ?></th>
				<th data-col="actions"><?php esc_html_e( 'Actions', 'pixcensus-media-audit' ); ?></th>
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
					$pixcensus_count      = count( $pixcensus_provenance );
					$pixcensus_haystack   = strtolower(
						implode(
							' ',
							array(
								(string) $pixcensus_attachment_id,
								$pixcensus_file_path ? basename( $pixcensus_file_path ) : '',
								implode( ' ', $pixcensus_provenance ),
							)
						)
					);
					?>
					<tr class="pixcensus-row" id="pixcensus-row-<?php echo esc_attr( $pixcensus_attachment_id ); ?>" data-pixcensus-haystack="<?php echo esc_attr( $pixcensus_haystack ); ?>">
						<td><input type="checkbox" class="pixcensus-select" value="<?php echo esc_attr( $pixcensus_attachment_id ); ?>" /></td>
						<td data-col="thumb" class="pixcensus-thumb"><?php echo $pixcensus_thumb_html ? wp_kses_post( $pixcensus_thumb_html ) : '&nbsp;'; ?></td>
						<td data-col="id"><?php echo esc_html( (string) $pixcensus_attachment_id ); ?></td>
						<td data-col="file"><?php echo esc_html( $pixcensus_file_path ? basename( $pixcensus_file_path ) : __( '(unknown)', 'pixcensus-media-audit' ) ); ?></td>
						<td data-col="uploaded"><?php echo esc_html( get_the_date( 'Y-m-d H:i', $pixcensus_attachment_id ) ); ?></td>
						<td data-col="provenance">
							<?php if ( ! empty( $pixcensus_provenance ) ) : ?>
								<?php if ( 'used' === $pixcensus_tab ) : ?>
									<?php
									$pixcensus_first_provenance = reset( $pixcensus_provenance );
									$pixcensus_other_provenance = array_slice( $pixcensus_provenance, 1 );
									?>
									<div class="pixcensus-prov-wrap">
										<?php if ( $pixcensus_first_provenance ) : ?>
											<code><?php echo esc_html( $pixcensus_first_provenance ); ?></code>
										<?php endif; ?>

										<?php if ( ! empty( $pixcensus_other_provenance ) ) : ?>
											<div class="pixcensus-prov-more" style="display:none;margin-top:6px;">
												<ul>
													<?php foreach ( $pixcensus_other_provenance as $pixcensus_provenance_item ) : ?>
														<li><code><?php echo esc_html( $pixcensus_provenance_item ); ?></code></li>
													<?php endforeach; ?>
												</ul>
											</div>
											<p class="pixcensus-provenance-toggle">
												<a href="#" class="button-link pixcensus-toggle-prov"><?php esc_html_e( 'Show more', 'pixcensus-media-audit' ); ?></a>
											</p>
										<?php endif; ?>
									</div>
								<?php else : ?>
									<ul>
										<?php foreach ( array_slice( $pixcensus_provenance, 0, 6 ) as $pixcensus_provenance_item ) : ?>
											<li><code><?php echo esc_html( $pixcensus_provenance_item ); ?></code></li>
										<?php endforeach; ?>

										<?php if ( $pixcensus_count > 6 ) : ?>
											<li>
												<?php
												echo esc_html(
													sprintf(
														/* translators: %d: hidden provenance count. */
														__( '…and %d more', 'pixcensus-media-audit' ),
														$pixcensus_count - 6
													)
												);
												?>
											</li>
										<?php endif; ?>
									</ul>
								<?php endif; ?>
							<?php else : ?>
								<span class="pixcensus-muted-dash"><?php esc_html_e( '—', 'pixcensus-media-audit' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-col="count"><?php echo esc_html( (string) $pixcensus_count ); ?></td>
						<td data-col="actions" class="pixcensus-actions">
							<a class="button" href="<?php echo esc_url( $pixcensus_edit_url ); ?>"><?php esc_html_e( 'Open in Media', 'pixcensus-media-audit' ); ?></a>

							<?php if ( 'unused' === $pixcensus_tab ) : ?>
								<a class="button button-secondary pixcensus-mark-used" data-id="<?php echo esc_attr( $pixcensus_attachment_id ); ?>" href="#"><?php esc_html_e( 'Mark as Used (manual)', 'pixcensus-media-audit' ); ?></a>
							<?php elseif ( in_array( $pixcensus_attachment_id, $pixcensus_manual_ids, true ) ) : ?>
								<span class="pixcensus-badge"><?php esc_html_e( 'manual', 'pixcensus-media-audit' ); ?></span>
								<a class="button button-link-delete pixcensus-unmark-used" data-id="<?php echo esc_attr( $pixcensus_attachment_id ); ?>" href="#"><?php esc_html_e( 'Unmark', 'pixcensus-media-audit' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="8"><?php esc_html_e( 'No items.', 'pixcensus-media-audit' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

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

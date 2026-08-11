<?php
/**
 * Prepare disposable fixtures for authenticated HTTP AJAX smoke tests.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'PIXCENSUS_Plugin' ) ) {
	throw new RuntimeException( 'PixCensus — Media Usage Audit is not active.' );
}

$pixcensus_restricted_roles = array( 'subscriber', 'contributor', 'author', 'editor' );

foreach ( $pixcensus_restricted_roles as $pixcensus_role ) {
	$pixcensus_login = 'pixcensus-ajax-' . $pixcensus_role;
	$pixcensus_user  = get_user_by( 'login', $pixcensus_login );

	if ( $pixcensus_user ) {
		$pixcensus_user->set_role( $pixcensus_role );
		continue;
	}

	$pixcensus_user_id = wp_insert_user(
		array(
			'user_login' => $pixcensus_login,
			'user_pass'  => $pixcensus_login . '-password',
			'user_email' => $pixcensus_login . '@example.test',
			'role'       => $pixcensus_role,
		)
	);

	if ( is_wp_error( $pixcensus_user_id ) ) {
		throw new RuntimeException( 'Could not create the AJAX ' . $pixcensus_role . ' fixture.' );
	}
}

$pixcensus_png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true );

if ( false === $pixcensus_png ) {
	throw new RuntimeException( 'PNG fixture decoding failed.' );
}

$pixcensus_upload = wp_upload_bits( 'pixcensus-ajax.png', null, $pixcensus_png );

if ( ! empty( $pixcensus_upload['error'] ) ) {
	throw new RuntimeException( 'AJAX fixture upload failed.' );
}

$pixcensus_attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/png',
		'post_title'     => 'IUA AJAX fixture',
		'post_status'    => 'inherit',
	),
	$pixcensus_upload['file']
);

if ( is_wp_error( $pixcensus_attachment_id ) || $pixcensus_attachment_id <= 0 ) {
	throw new RuntimeException( 'AJAX attachment fixture creation failed.' );
}

update_attached_file( $pixcensus_attachment_id, $pixcensus_upload['file'] );
update_option(
	'pixcensus_usage_results',
	array(
		'used_ids'       => array(),
		'draft_only_ids' => array(),
		'unused_ids'     => array( (int) $pixcensus_attachment_id ),
		'orphans'        => array(),
		'scanned_at'     => time(),
		'include_drafts' => true,
		'provenance'     => array(),
	),
	false
);

WP_CLI::success( 'AJAX HTTP fixtures prepared.' );

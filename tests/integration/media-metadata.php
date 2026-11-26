<?php
/**
 * Verify transformed-image metadata on a disposable local WordPress site.
 *
 * @package PixCensus
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Disposable local CLI fixture only.' );
}

$pixcensus_uploads = wp_upload_dir();
$pixcensus_dir     = 'pixcensus-metadata-' . wp_generate_uuid4();
$pixcensus_root    = $pixcensus_uploads['basedir'] . '/' . $pixcensus_dir;
wp_mkdir_p( $pixcensus_root );
$pixcensus_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNeUAAAAASUVORK5CYII=', true );
$pixcensus_files = array( 'registered.png', 'original.png', 'thumbnail.png' );
foreach ( $pixcensus_files as $pixcensus_file ) {
	file_put_contents( $pixcensus_root . '/' . $pixcensus_file, $pixcensus_bytes );
}
$pixcensus_id = wp_insert_attachment(
	array( 'post_title' => 'Metadata fixture', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ),
	$pixcensus_root . '/registered.png'
);
wp_update_attachment_metadata(
	$pixcensus_id,
	array(
		'file'           => $pixcensus_dir . '/registered.png',
		'width'          => 1,
		'height'         => 1,
		'original_image' => 'original.png',
		'sizes'          => array(
			'thumbnail' => array( 'file' => 'thumbnail.png', 'width' => 1, 'height' => 1, 'mime-type' => 'image/png' ),
		),
	)
);
$pixcensus_scanner = new PIXCENSUS_Scanner();
$pixcensus_detail  = $pixcensus_scanner->image_details( $pixcensus_id );
if ( 3 !== count( $pixcensus_detail['files'] ) || 3 * strlen( $pixcensus_bytes ) !== $pixcensus_detail['bytes'] || $pixcensus_detail['incomplete'] ) {
	throw new RuntimeException( 'Original and generated-size accounting failed.' );
}
foreach ( $pixcensus_files as $pixcensus_file ) {
	$pixcensus_post = wp_insert_post(
		array(
			'post_title'   => 'Metadata reference ' . $pixcensus_file,
			'post_status'  => 'publish',
			'post_content' => '<img src="' . esc_url( $pixcensus_uploads['baseurl'] . '/' . $pixcensus_dir . '/' . $pixcensus_file ) . '">',
		)
	);
	$pixcensus_result = ( new PIXCENSUS_Scanner() )->run();
	if ( ! in_array( $pixcensus_id, $pixcensus_result['used_ids'], true ) ) {
		throw new RuntimeException( 'Attachment reference lost: ' . $pixcensus_file );
	}
	wp_update_post( array( 'ID' => $pixcensus_post, 'post_content' => '' ) );
}
foreach ( $pixcensus_files as $pixcensus_file ) {
	if ( hash( 'sha256', $pixcensus_bytes ) !== hash_file( 'sha256', $pixcensus_root . '/' . $pixcensus_file ) ) {
		throw new RuntimeException( 'Scanning changed image bytes.' );
	}
}
WP_CLI::success( 'Original, registered and generated-size references detected; image bytes preserved.' );

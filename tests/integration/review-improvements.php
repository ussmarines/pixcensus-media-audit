<?php
/** Disposable real-WordPress fixture. Run only on a fresh local QA site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://127.0.0.1:8925' !== get_option( 'home' ) ) {
	exit( 'Disposable fixture only.' );
}
require_once WP_PLUGIN_DIR . '/pixcensus-media-audit/pixcensus-media-audit.php';
wp_set_current_user( 1 );
$checks = 0;
$expect = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$uploads = wp_upload_dir();
wp_mkdir_p( $uploads['basedir'] . '/fixture' );
$path = $uploads['basedir'] . '/fixture/shared.png';
file_put_contents( $path, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNeUAAAAASUVORK5CYII=' ) );
wp_mkdir_p( $uploads['basedir'] . '/fixture/orphans' );
for ( $i = 0; $i < 205; ++$i ) {
	file_put_contents( $uploads['basedir'] . '/fixture/orphans/image-' . $i . '.png', 'fixture-image' );
	wp_insert_term( 'Fixture term ' . wp_generate_uuid4(), 'category', array( 'description' => '/wp-content/uploads/fixture/shared.png' ) );
}
$ids = array();
for ( $i = 0; $i < 205; ++$i ) {
	$id = wp_insert_attachment( array( 'post_title' => 'Fixture ' . $i, 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $path );
	update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1, 'file' => 'fixture/shared.png', 'sizes' => array( 'test' => array( 'file' => 'shared.png', 'width' => 1, 'height' => 1 ) ) ) );
	$ids[] = $id;
}
$content = '<!-- wp:cover {"id":' . $ids[0] . ',"backgroundType":"image"} /-->'
	. '<!-- wp:media-text {"mediaId":' . $ids[1] . ',"mediaType":"image"} /-->'
	. '<!-- wp:gallery {"ids":[' . $ids[2] . ']} /-->'
	. '<!-- wp:paragraph {"id":' . $ids[3] . '} /-->';
for ( $i = 0; $i < 205; ++$i ) {
	wp_insert_post( array( 'post_title' => 'Source ' . $i, 'post_status' => 'publish', 'post_content' => 204 === $i ? $content : 'No image.' ) );
}
for ( $i = 0; $i < 505; ++$i ) { add_option( 'fixture_option_' . $i, 504 === $i ? '/wp-content/uploads/fixture/shared.png' : 'No image', '', false ); }
$draft = wp_insert_post( array( 'post_title' => 'Draft evidence', 'post_status' => 'draft', 'post_content' => '<!-- wp:image {"id":' . $ids[4] . '} /-->' ) );
$scanner = new PIXCENSUS_Scanner();
$detail = $scanner->image_details( $ids[0] );
$expect( filesize( $path ) === $detail['bytes'], 'Generated sizes sharing the original must be deduplicated.' );
$expect( 1 === count( $detail['files'] ), 'Canonical shared path should have one hashed key.' );
$expect( ! $detail['incomplete'], 'Existing files should be complete.' );
// Use a distinct missing image for missing-file accounting.
$missing = wp_insert_attachment( array( 'post_title' => 'Missing', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $uploads['basedir'] . '/fixture/missing.png' );
$expect( $scanner->image_details( $missing )['incomplete'], 'Missing file must not be presented as complete zero bytes.' );
$method = new ReflectionMethod( PIXCENSUS_Scanner::class, 'scan_text_for_attachment_ids' );
$method->setAccessible( true );
$used = array();
$method->invokeArgs( $scanner, array( $content, &$used, 'post:fixture content' ) );
foreach ( array_slice( $ids, 0, 3 ) as $id ) { $expect( isset( $used[ $id ] ), 'Native block image ID not detected.' ); }
$expect( ! isset( $used[ $ids[3] ] ), 'Unrelated block IDs must not become image evidence.' );
$before = hash_file( 'sha256', $path );
$sync = ( new PIXCENSUS_Scanner() )->run();
$state = array( 'include_drafts' => true, 'revision' => get_option( 'pixcensus_revision', '' ) );
$iterations = 0;
do {
	$state = ( new PIXCENSUS_Scanner() )->step( unserialize( serialize( $state ) ) );
	++$iterations;
	if ( $iterations > 100 ) { throw new RuntimeException( 'Scan did not converge.' ); }
} while ( $state['stage'] < 14 );
$result = ( new PIXCENSUS_Scanner() )->finish( $state );
foreach ( array( 'used_ids', 'draft_only_ids', 'unused_ids', 'orphans' ) as $key ) {
	$left = $sync[ $key ]; $right = $result[ $key ]; sort( $left ); sort( $right );
	$expect( $left === $right, 'Incremental and synchronous mismatch: ' . $key );
}
$expect( count( $result['orphans'] ) >= 205, 'Orphan batches lost files.' );
$expect( $iterations > 14, 'Fixture must cross multiple source batch boundaries.' );
$expect( $before === hash_file( 'sha256', $path ), 'Scanning changed media.' );
$expect( count( $result['details'] ) >= 206, 'Details missing after attachment pagination.' );
$revision = $result['revision'];
wp_update_post( array( 'ID' => $draft, 'post_content' => 'Changed' ) );
$expect( get_option( 'pixcensus_revision', '' ) !== $revision, 'Content save did not invalidate snapshot.' );
$revision = get_option( 'pixcensus_revision' );
update_option( 'pixcensus_scan_state', array( 'test' => true ), false );
$expect( get_option( 'pixcensus_revision' ) === $revision, 'Checkpoint invalidated its own snapshot.' );
update_option( 'pixcensus_cdn_aliases', 'cdn-' . wp_generate_uuid4() . '.example.invalid', false );
$expect( get_option( 'pixcensus_revision' ) !== $revision, 'CDN settings did not invalidate snapshot.' );
update_option( 'pixcensus_history', array(), false );
for ( $i = 0; $i < 505; ++$i ) { PIXCENSUS_Review::record( 'protect', $ids[0] ); }
$expect( 500 === count( get_option( 'pixcensus_history' ) ), 'History cap failed.' );
$trash = new ReflectionMethod( PIXCENSUS_Plugin::class, 'trash_image' );
$trash->setAccessible( true );
$trash->invoke( PIXCENSUS_Plugin::instance(), $ids[0] );
wp_untrash_post( $ids[0] );
wp_delete_attachment( $missing, true );
$actions = array_column( array_slice( get_option( 'pixcensus_history' ), -3 ), 'action' );
$expect( array( 'trash', 'restore', 'delete' ) === $actions, 'Native completed image actions not recorded.' );
$account = get_userdata( 1 );
$expect( count( PIXCENSUS_Review::export_personal_data( $account->user_email )['data'] ) > 0, 'Native privacy exporter returned no events.' );
$expect( array() === PIXCENSUS_Review::export_personal_data( 'unknown@example.invalid' )['data'], 'Exporter leaked another account history.' );
$expect( PIXCENSUS_Review::erase_personal_data( $account->user_email )['items_removed'], 'Native privacy eraser failed.' );
$expect( array() === PIXCENSUS_Review::export_personal_data( $account->user_email )['data'], 'Erased history remained exportable.' );
update_option( 'pixcensus_usage_results', $result, false );
delete_option( 'pixcensus_scan_state' );
echo wp_json_encode( array( 'checks' => $checks, 'batches' => $iterations, 'images' => count( $result['details'] ), 'result' => 'pass' ) );

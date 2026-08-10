<?php

use PHPUnit\Framework\TestCase;

final class ScannerNormalizationTest extends TestCase {
	protected function tearDown(): void {
		$GLOBALS['pixcensus_test_get_posts'] = null;
		parent::tearDown();
	}

	/**
	 * @return mixed
	 */
	private function call_private( PIXCENSUS_Scanner $scanner, string $method, array $arguments = array() ) {
		$reflection = new ReflectionMethod( PIXCENSUS_Scanner::class, $method );

		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->invokeArgs( $scanner, $arguments );
	}

	/**
	 * @return int
	 */
	private function get_private_constant( string $name ): int {
		$reflection = new ReflectionClassConstant( PIXCENSUS_Scanner::class, $name );

		return (int) $reflection->getValue();
	}

	public function test_cdn_aliases_and_rewrites_normalize_upload_urls() : void {
		$GLOBALS['pixcensus_test_options'] = array(
			'pixcensus_cdn_aliases'  => 'cdn.example.test, media.example.test',
			'pixcensus_cdn_rewrites' => 'https://assets.example.test/media => /wp-content/uploads',
		);
		$scanner = new PIXCENSUS_Scanner();

		$this->call_private( $scanner, 'load_cdn_settings' );

		$this->assertSame(
			'/wp-content/uploads/2024/image.jpg',
			$this->call_private( $scanner, 'normalize_text_rewrites', array( 'https://cdn.example.test/wp-content/uploads/2024/image.jpg' ) )
		);
		$this->assertSame(
			'/wp-content/uploads/2024/image.jpg',
			$this->call_private( $scanner, 'normalize_text_rewrites', array( 'https://assets.example.test/media/2024/image.jpg' ) )
		);
	}

	public function test_upload_urls_match_generated_sizes_and_record_provenance() : void {
		$scanner  = new PIXCENSUS_Scanner();
		$used     = array();
		$path_map = array(
			'2024/image.jpg'         => 11,
			'2024/image-300x200.jpg' => 11,
		);

		$this->call_private(
			$scanner,
			'scan_text_for_uploads',
			array( '<img src="/wp-content/uploads/2024/image-300x200.jpg?cache=1">', $path_map, &$used, 'post:42 content:url' )
		);

		$this->assertSame( array( 11 => true ), $used );
		$this->assertSame(
			array( 11 => array( 'post:42 content:url' ) ),
			$this->call_private( $scanner, 'get_provenance_output' )
		);
	}

	/**
	 * @dataProvider upload_reference_variants
	 */
	public function test_upload_reference_variants_are_normalized( string $reference ) : void {
		$scanner  = new PIXCENSUS_Scanner();
		$used     = array();
		$path_map = array( '2024/image.jpg' => 11 );

		$this->call_private( $scanner, 'scan_text_for_uploads', array( $reference, $path_map, &$used, 'fixture' ) );

		$this->assertSame( array( 11 => true ), $used );
	}

	public function upload_reference_variants(): array {
		return array(
			'full URL'          => array( 'https://example.test/wp-content/uploads/2024/image.jpg' ),
			'relative URL'      => array( 'wp-content/uploads/2024/image.jpg' ),
			'srcset'            => array( '<img srcset="/wp-content/uploads/2024/image.jpg 1x, /wp-content/uploads/2024/image-2.jpg 2x">' ),
			'lazy-load field'   => array( '<img data-src="/wp-content/uploads/2024/image.jpg">' ),
			'JSON escaped URL'  => array( '{"url":"https:\\/\\/example.test\\/wp-content\\/uploads\\/2024\\/image.jpg"}' ),
			'HTML escaped URL'  => array( 'https:&#47;&#47;example.test&#47;wp-content&#47;uploads&#47;2024&#47;image.jpg' ),
			'encoded URL'       => array( 'https%3A%2F%2Fexample.test%2Fwp-content%2Fuploads%2F2024%2Fimage.jpg' ),
			'CSS URL'           => array( 'background-image:url(/wp-content/uploads/2024/image.jpg)' ),
			'serialized data'   => array( 'a:1:{s:3:"url";s:46:"/wp-content/uploads/2024/image.jpg";}' ),
			'query and fragment'=> array( '/wp-content/uploads/2024/image.jpg?fit=100#hero' ),
		);
	}

	public function test_blocks_and_shortcodes_only_match_explicit_attachment_fields() : void {
		$scanner = new PIXCENSUS_Scanner();
		$used    = array();

		$this->call_private(
			$scanner,
			'scan_text_for_attachment_ids',
			array( '<!-- wp:image {"id":27} --><figure></figure> [gallery ids="28, 29"] plain id=30', &$used, 'post:5 content' )
		);

		$this->assertSame( array( 27 => true, 28 => true, 29 => true ), $used );
		$this->assertArrayNotHasKey( 30, $used );
	}

	public function test_close_filenames_and_duplicate_basenames_do_not_false_match() : void {
		$scanner  = new PIXCENSUS_Scanner();
		$used     = array();
		$path_map = array(
			'2024/image.jpg' => 11,
			'2025/image.jpg' => 12,
		);

		$this->call_private( $scanner, 'scan_text_for_uploads', array( '/wp-content/uploads/2024/image-copy.jpg', $path_map, &$used, 'fixture' ) );
		$this->assertSame( array(), $used );

		$this->call_private( $scanner, 'scan_text_for_uploads', array( '/wp-content/uploads/2025/image.jpg', $path_map, &$used, 'fixture' ) );
		$this->assertSame( array( 12 => true ), $used );
	}

	public function test_attachment_queries_are_batched(): void {
		$calls = array();
		$GLOBALS['pixcensus_test_get_posts'] = static function ( array $args ) use ( &$calls ): array {
			$calls[] = $args;

			return 1 === $args['paged'] ? range( 1, 200 ) : array( 201 );
		};

		$scanner = new PIXCENSUS_Scanner();
		$ids     = $this->call_private( $scanner, 'get_image_attachment_ids' );

		$this->assertCount( 201, $ids );
		$this->assertCount( 2, $calls );
		$this->assertSame( 200, $calls[0]['posts_per_page'] );
		$this->assertSame( 1, $calls[0]['paged'] );
		$this->assertSame( 2, $calls[1]['paged'] );
	}

	public function test_orphan_results_are_relative_to_uploads_directory(): void {
		$pixcensus_basedir = trailingslashit( sys_get_temp_dir() ) . 'pixcensus-orphans-' . bin2hex( random_bytes( 8 ) );
		$pixcensus_subdir  = $pixcensus_basedir . '/2026/08';
		$pixcensus_file    = $pixcensus_subdir . '/orphan.jpg';

		$this->assertTrue( mkdir( $pixcensus_subdir, 0700, true ) );
		$this->assertNotFalse( file_put_contents( $pixcensus_file, 'fixture' ) );

		try {
			$pixcensus_orphans = $this->call_private( new PIXCENSUS_Scanner(), 'find_orphans', array( array(), $pixcensus_basedir ) );

			$this->assertSame( array( '2026/08/orphan.jpg' ), $pixcensus_orphans );
			$this->assertStringNotContainsString( wp_normalize_path( $pixcensus_basedir ), $pixcensus_orphans[0] );
		} finally {
			unlink( $pixcensus_file );
			rmdir( $pixcensus_subdir );
			rmdir( dirname( $pixcensus_subdir ) );
			rmdir( $pixcensus_basedir );
		}
	}

	public function test_builder_ids_are_detected_and_provenance_is_capped() : void {
		$scanner = new PIXCENSUS_Scanner();
		$used    = array();

		$this->call_private( $scanner, 'scan_builder_value_for_ids', array( '{"id":27,"nested":{"id":28}}', &$used, 'post:8 meta:_elementor_data' ) );
		$this->assertSame( array( 27 => true, 28 => true ), $used );

		for ( $index = 0; $index < 14; $index++ ) {
			$this->call_private( $scanner, 'add_provenance', array( 27, 'source:' . $index ) );
		}

		$provenance = $this->call_private( $scanner, 'get_provenance_output' );
		$this->assertCount( 12, $provenance[27] );
		$this->assertSame( 'post:8 meta:_elementor_data json:id', $provenance[27][0] );
	}

	public function test_bounded_walker_preserves_normal_strings_scalars_ids_and_upload_urls() : void {
		$scanner  = new PIXCENSUS_Scanner();
		$used     = array();
		$path_map = array( '2026/normal.jpg' => 51 );
		$value    = array(
			'number' => 17,
			'flag'   => false,
			'url'    => '/wp-content/uploads/2026/normal.jpg',
			'nested' => array( 'id' => 52 ),
		);

		$this->assertSame( array( '17', '', '/wp-content/uploads/2026/normal.jpg', '52' ), $this->call_private( $scanner, 'flatten_scan_strings', array( $value ) ) );
		$this->call_private( $scanner, 'scan_value_for_uploads', array( $value, $path_map, &$used, 'normal' ) );
		$this->call_private( $scanner, 'scan_builder_value_for_ids', array( $value, &$used, 'normal-builder' ) );

		$this->assertSame( array( 51 => true, 52 => true ), $used );
	}

	public function test_bounded_walker_handles_a_self_referential_array() : void {
		$scanner  = new PIXCENSUS_Scanner();
		$used     = array();
		$path_map = array( '2026/cycle.jpg' => 61 );
		$value    = array(
			'id'  => 62,
			'url' => '/wp-content/uploads/2026/cycle.jpg',
		);
		$value['self'] =& $value;

		$this->call_private( $scanner, 'scan_value_for_uploads', array( $value, $path_map, &$used, 'array-cycle' ) );
		$this->call_private( $scanner, 'scan_builder_value_for_ids', array( $value, &$used, 'array-cycle' ) );

		$this->assertSame( array( 61 => true, 62 => true ), $used );
	}

	public function test_bounded_walker_handles_cyclic_objects() : void {
		$scanner = new PIXCENSUS_Scanner();
		$used    = array();
		$first   = (object) array( 'id' => 71 );
		$second  = (object) array( 'id' => 72 );
		$first->next = $second;
		$second->next = $first;

		$this->call_private( $scanner, 'scan_builder_value_for_ids', array( $first, &$used, 'object-cycle' ) );

		$this->assertSame( array( 71 => true, 72 => true ), $used );
	}

	public function test_bounded_walker_abandons_values_beyond_the_depth_limit() : void {
		$scanner   = new PIXCENSUS_Scanner();
		$used      = array();
		$path_map  = array(
			'2026/visible.jpg' => 81,
			'2026/deep.jpg'    => 82,
		);
		$deep_value = '/wp-content/uploads/2026/deep.jpg';

		for ( $depth = 0; $depth <= $this->get_private_constant( 'MAX_WALK_DEPTH' ); ++$depth ) {
			$deep_value = array( 'nested' => $deep_value );
		}

		$value = array(
			'visible' => '/wp-content/uploads/2026/visible.jpg',
			'deep'    => $deep_value,
		);

		$this->call_private( $scanner, 'scan_value_for_uploads', array( $value, $path_map, &$used, 'deep' ) );

		$this->assertSame( array( 81 => true ), $used );
	}

	public function test_bounded_walker_caps_extremely_wide_values() : void {
		$scanner = new PIXCENSUS_Scanner();
		$limit   = $this->get_private_constant( 'MAX_WALK_NODES' );
		$value   = array_fill( 0, $limit + 100, 'normal' );

		$this->assertCount( $limit - 1, $this->call_private( $scanner, 'flatten_scan_strings', array( $value ) ) );
	}

	/**
	 * @dataProvider dangerous_csv_values
	 */
	public function test_csv_formula_values_are_neutralized( string $value ) : void {
		$this->assertSame( "'" . $value, PIXCENSUS_CSV::neutralize_formula( $value ) );
	}

	public function dangerous_csv_values() : array {
		return array(
			'equals'             => array( '=1+1' ),
			'plus'               => array( '+SUM(1,2)' ),
			'minus'              => array( '-1+2' ),
			'at'                 => array( '@SUM(1,2)' ),
			'leading whitespace' => array( " \t=1+1" ),
			'tab'                => array( "\tplain" ),
			'carriage return'    => array( "\rplain" ),
		);
	}

	public function test_safe_csv_values_are_unchanged() : void {
		$this->assertSame( 'image.jpg', PIXCENSUS_CSV::neutralize_formula( 'image.jpg' ) );
		$this->assertSame( 'https://example.test/image.jpg', PIXCENSUS_CSV::neutralize_formula( 'https://example.test/image.jpg' ) );
	}

	public function test_cdn_settings_accept_bounded_hosts_and_upload_rewrites() : void {
		$result = PIXCENSUS_CDN_Settings::validate(
			'CDN.Example.test, 192.0.2.10',
			"https://assets.example.test/media => /wp-content/uploads\n/media => /wp-content/uploads"
		);

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'cdn.example.test, 192.0.2.10', $result['aliases'] );
		$this->assertSame( "https://assets.example.test/media => /wp-content/uploads\n/media => /wp-content/uploads", $result['rewrites'] );
	}

	/**
	 * @dataProvider invalid_cdn_settings
	 */
	public function test_cdn_settings_reject_malformed_or_dangerous_values( string $aliases, string $rewrites ) : void {
		$result = PIXCENSUS_CDN_Settings::validate( $aliases, $rewrites );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function invalid_cdn_settings() : array {
		return array(
			'alias with scheme'       => array( 'https://cdn.example.test', '' ),
			'alias with path'         => array( 'cdn.example.test/path', '' ),
			'missing separator'       => array( '', 'https://cdn.example.test/media' ),
			'non-http source'         => array( '', 'javascript:alert(1) => /wp-content/uploads' ),
			'unrecognized target'     => array( '', '/media => /tmp' ),
			'uploads prefix collision' => array( '', '/media => /wp-content/uploadswhatever' ),
			'overly broad source'     => array( '', '/ => /wp-content/uploads' ),
			'too many aliases'        => array( implode( ',', array_fill( 0, 21, 'cdn.example.test' ) ), '' ),
			'too many rewrite rules'  => array( '', implode( "\n", array_fill( 0, 21, '/media => /wp-content/uploads' ) ) ),
		);
	}
}

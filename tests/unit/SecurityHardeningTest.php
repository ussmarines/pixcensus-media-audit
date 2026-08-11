<?php

use PHPUnit\Framework\TestCase;

final class SecurityHardeningTest extends TestCase {
	/**
	 * Invoke a private scanner method.
	 *
	 * @param PIXCENSUS_Scanner $scanner Scanner instance.
	 * @param string            $method Method name.
	 * @param array<int, mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function call_private( PIXCENSUS_Scanner $scanner, string $method, array $arguments = array() ) {
		$reflection = new ReflectionMethod( PIXCENSUS_Scanner::class, $method );

		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->invokeArgs( $scanner, $arguments );
	}

	public function test_upload_file_resolution_accepts_inside_file_and_rejects_parent_traversal(): void {
		$pixcensus_root         = trailingslashit( sys_get_temp_dir() ) . 'pixcensus-path-' . bin2hex( random_bytes( 8 ) );
		$pixcensus_uploads      = $pixcensus_root . '/uploads';
		$pixcensus_inside_dir   = $pixcensus_uploads . '/2026/08';
		$pixcensus_outside_dir  = $pixcensus_root . '/outside';
		$pixcensus_inside_file  = $pixcensus_inside_dir . '/inside.jpg';
		$pixcensus_outside_file = $pixcensus_outside_dir . '/outside.jpg';

		$this->assertTrue( mkdir( $pixcensus_inside_dir, 0700, true ) );
		$this->assertTrue( mkdir( $pixcensus_outside_dir, 0700, true ) );
		$this->assertNotFalse( file_put_contents( $pixcensus_inside_file, 'inside' ) );
		$this->assertNotFalse( file_put_contents( $pixcensus_outside_file, 'outside' ) );

		try {
			$pixcensus_scanner = new PIXCENSUS_Scanner();
			$pixcensus_resolved_inside = $this->call_private(
				$pixcensus_scanner,
				'resolve_existing_upload_file',
				array( $pixcensus_uploads, '2026/08/inside.jpg' )
			);

			$this->assertSame( wp_normalize_path( (string) realpath( $pixcensus_inside_file ) ), $pixcensus_resolved_inside );
			$this->assertSame(
				'',
				$this->call_private(
					$pixcensus_scanner,
					'resolve_existing_upload_file',
					array( $pixcensus_uploads, '../outside/outside.jpg' )
				)
			);
		} finally {
			unlink( $pixcensus_inside_file );
			unlink( $pixcensus_outside_file );
			rmdir( $pixcensus_inside_dir );
			rmdir( dirname( $pixcensus_inside_dir ) );
			rmdir( $pixcensus_uploads );
			rmdir( $pixcensus_outside_dir );
			rmdir( $pixcensus_root );
		}
	}

	public function test_upload_file_resolution_rejects_symlink_escape(): void {
		if ( '\\' === DIRECTORY_SEPARATOR || ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'Symlink creation is not reliably available on this platform.' );
		}

		$pixcensus_root         = trailingslashit( sys_get_temp_dir() ) . 'pixcensus-symlink-' . bin2hex( random_bytes( 8 ) );
		$pixcensus_uploads      = $pixcensus_root . '/uploads';
		$pixcensus_outside_dir  = $pixcensus_root . '/outside';
		$pixcensus_outside_file = $pixcensus_outside_dir . '/outside.jpg';
		$pixcensus_link         = $pixcensus_uploads . '/linked.jpg';

		$this->assertTrue( mkdir( $pixcensus_uploads, 0700, true ) );
		$this->assertTrue( mkdir( $pixcensus_outside_dir, 0700, true ) );
		$this->assertNotFalse( file_put_contents( $pixcensus_outside_file, 'outside' ) );
		$this->assertTrue( symlink( $pixcensus_outside_file, $pixcensus_link ) );

		try {
			$this->assertSame(
				'',
				$this->call_private(
					new PIXCENSUS_Scanner(),
					'resolve_existing_upload_file',
					array( $pixcensus_uploads, 'linked.jpg' )
				)
			);
		} finally {
			unlink( $pixcensus_link );
			unlink( $pixcensus_outside_file );
			rmdir( $pixcensus_uploads );
			rmdir( $pixcensus_outside_dir );
			rmdir( $pixcensus_root );
		}
	}

	/**
	 * @dataProvider unicode_formula_prefixes
	 */
	public function test_unicode_obscured_csv_formulas_are_neutralized( string $value ): void {
		$this->assertSame( "'" . $value, PIXCENSUS_CSV::neutralize_formula( $value ) );
	}

	public function unicode_formula_prefixes(): array {
		return array(
			'non-breaking space' => array( "\u{00A0}=1+1" ),
			'zero-width space'   => array( "\u{200B}+SUM(1,2)" ),
			'line separator'     => array( "\u{2028}-1+2" ),
			'bidi override'      => array( "\u{202E}@SUM(1,2)" ),
			'word joiner'        => array( "\u{2060}=1+1" ),
			'ideographic space'  => array( "\u{3000}=1+1" ),
			'byte-order mark'    => array( "\u{FEFF}=1+1" ),
		);
	}

	public function test_safe_unicode_csv_value_is_unchanged(): void {
		$this->assertSame( 'média-équipe.jpg', PIXCENSUS_CSV::neutralize_formula( 'média-équipe.jpg' ) );
	}
}

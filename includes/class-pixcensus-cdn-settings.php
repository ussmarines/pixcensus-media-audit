<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate and normalize CDN scanner settings.
 */
final class PIXCENSUS_CDN_Settings {

	private const MAX_ALIASES        = 20;
	private const MAX_REWRITES       = 20;
	private const MAX_ALIASES_BYTES  = 4096;
	private const MAX_REWRITES_BYTES = 8192;

	/**
	 * Validate raw CDN settings.
	 *
	 * @param string $aliases_raw Comma-separated aliases.
	 * @param string $rewrites_raw Newline-separated rewrite rules.
	 * @return array{valid: bool, aliases: string, rewrites: string, errors: array<int, string>}
	 */
	public static function validate( string $aliases_raw, string $rewrites_raw ): array {
		$pixcensus_errors   = array();
		$pixcensus_aliases  = array();
		$pixcensus_rewrites = array();

		if ( strlen( $aliases_raw ) > self::MAX_ALIASES_BYTES ) {
			$pixcensus_errors[] = 'aliases_too_long';
		} else {
			$pixcensus_alias_candidates = array_filter( array_map( 'trim', explode( ',', $aliases_raw ) ), 'strlen' );

			if ( count( $pixcensus_alias_candidates ) > self::MAX_ALIASES ) {
				$pixcensus_errors[] = 'too_many_aliases';
			} else {
				foreach ( $pixcensus_alias_candidates as $pixcensus_alias ) {
					$pixcensus_alias = strtolower( rtrim( $pixcensus_alias, '.' ) );

					if ( ! self::is_valid_host( $pixcensus_alias ) ) {
						$pixcensus_errors[] = 'invalid_alias';
						continue;
					}

					$pixcensus_aliases[] = $pixcensus_alias;
				}
			}
		}

		if ( strlen( $rewrites_raw ) > self::MAX_REWRITES_BYTES ) {
			$pixcensus_errors[] = 'rewrites_too_long';
		} else {
			$pixcensus_lines = preg_split( '/\r?\n/', $rewrites_raw );
			$pixcensus_lines = is_array( $pixcensus_lines ) ? array_values( array_filter( array_map( 'trim', $pixcensus_lines ), 'strlen' ) ) : array();

			if ( count( $pixcensus_lines ) > self::MAX_REWRITES ) {
				$pixcensus_errors[] = 'too_many_rewrites';
			} else {
				foreach ( $pixcensus_lines as $pixcensus_line ) {
					if ( false === strpos( $pixcensus_line, '=>' ) ) {
						$pixcensus_errors[] = 'invalid_rewrite';
						continue;
					}

					list( $pixcensus_from, $pixcensus_to ) = array_map( 'trim', explode( '=>', $pixcensus_line, 2 ) );

					if ( ! self::is_valid_source( $pixcensus_from ) || ! self::is_valid_target( $pixcensus_to ) ) {
						$pixcensus_errors[] = 'invalid_rewrite';
						continue;
					}

					$pixcensus_rewrites[] = $pixcensus_from . ' => ' . $pixcensus_to;
				}
			}
		}

		$pixcensus_aliases  = array_values( array_unique( $pixcensus_aliases ) );
		$pixcensus_rewrites = array_values( array_unique( $pixcensus_rewrites ) );

		return array(
			'valid'    => empty( $pixcensus_errors ),
			'aliases'  => implode( ', ', $pixcensus_aliases ),
			'rewrites' => implode( "\n", $pixcensus_rewrites ),
			'errors'   => array_values( array_unique( $pixcensus_errors ) ),
		);
	}

	/**
	 * Check a host name or IP address without accepting schemes, paths, or ports.
	 *
	 * @param string $host Candidate host.
	 * @return bool
	 */
	private static function is_valid_host( string $host ): bool {
		if ( '' === $host || strlen( $host ) > 253 || preg_match( '/[\s\/:?#@]/', $host ) ) {
			return false;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		return 1 === preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/', $host );
	}

	/**
	 * Validate a rewrite source.
	 *
	 * @param string $source Source prefix.
	 * @return bool
	 */
	private static function is_valid_source( string $source ): bool {
		if ( strlen( $source ) < 3 || strlen( $source ) > 2048 || preg_match( '/[\x00-\x1F\x7F]/', $source ) ) {
			return false;
		}

		if ( 0 === strpos( $source, '/' ) ) {
			return true;
		}

		$pixcensus_parts = wp_parse_url( $source );

		return is_array( $pixcensus_parts )
			&& isset( $pixcensus_parts['scheme'], $pixcensus_parts['host'] )
			&& in_array( strtolower( (string) $pixcensus_parts['scheme'] ), array( 'http', 'https' ), true )
			&& self::is_valid_host( strtolower( (string) $pixcensus_parts['host'] ) );
	}

	/**
	 * Validate a rewrite target recognized by the scanner.
	 *
	 * @param string $target Target prefix.
	 * @return bool
	 */
	private static function is_valid_target( string $target ): bool {
		return strlen( $target ) <= 2048
			&& 1 === preg_match( '#^/wp-content/uploads(?:/|$)#', $target )
			&& ! preg_match( '/[\x00-\x1F\x7F]/', $target );
	}
}

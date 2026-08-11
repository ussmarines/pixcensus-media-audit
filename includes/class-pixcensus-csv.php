<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV output safety helpers.
 */
final class PIXCENSUS_CSV {

	/**
	 * Neutralize values that spreadsheet applications may interpret as formulas.
	 *
	 * Treat ASCII controls, Unicode whitespace, byte-order marks, zero-width
	 * characters and bidi/formatting controls as ignorable leading characters.
	 * This prevents a formula marker from being hidden behind characters that
	 * spreadsheet applications may trim or render invisibly.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function neutralize_formula( $value ): string {
		$pixcensus_value           = (string) $value;
		$pixcensus_formula_pattern = '/^[\x00-\x20\x7F\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}\x{2060}-\x{206F}\x{3000}\x{FEFF}]*[=+\-@]/u';

		if ( preg_match( $pixcensus_formula_pattern, $pixcensus_value ) || preg_match( '/^[\t\r\n]/', $pixcensus_value ) ) {
			return "'" . $pixcensus_value;
		}

		return $pixcensus_value;
	}
}

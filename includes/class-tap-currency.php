<?php
/**
 * Currency precision helpers.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for how many decimal places a currency uses.
 *
 * Version 2.x kept four separate, divergent lists of three-decimal currencies
 * (one of which omitted JOD). The result was that the amount used to build the
 * payment hash could differ from the amount actually charged, which made the
 * plugin treat a legitimate payment as a currency mismatch and auto-refund it.
 */
final class Tap_Currency {

	/**
	 * ISO 4217 currencies with three minor units.
	 *
	 * @var string[]
	 */
	private const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * ISO 4217 currencies with no minor units.
	 *
	 * @var string[]
	 */
	private const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * Number of decimal places Tap expects for a currency.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return int
	 */
	public static function decimals( string $currency ): int {
		$currency = strtoupper( trim( $currency ) );

		if ( in_array( $currency, self::THREE_DECIMAL, true ) ) {
			return 3;
		}
		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}
		return 2;
	}

	/**
	 * Format an amount as Tap expects it on the wire.
	 *
	 * @param float|int|string $amount   Amount.
	 * @param string           $currency ISO 4217 code.
	 * @return string
	 */
	public static function format( $amount, string $currency ): string {
		return number_format( (float) $amount, self::decimals( $currency ), '.', '' );
	}

	/**
	 * Compare two amounts at the currency's precision.
	 *
	 * Avoids the float equality problem in the 2.x code, where "10.5" was
	 * compared with 10.500 using a loose ==.
	 *
	 * @param float|int|string $a        First amount.
	 * @param float|int|string $b        Second amount.
	 * @param string           $currency ISO 4217 code.
	 * @return bool
	 */
	public static function equals( $a, $b, string $currency ): bool {
		$epsilon = 1 / pow( 10, self::decimals( $currency ) + 1 );
		return abs( (float) $a - (float) $b ) < $epsilon;
	}

	/**
	 * Map of currency code to decimal places, for handing to the front end.
	 *
	 * @return array<string, int>
	 */
	public static function decimal_map(): array {
		$map = array();
		foreach ( self::THREE_DECIMAL as $code ) {
			$map[ $code ] = 3;
		}
		foreach ( self::ZERO_DECIMAL as $code ) {
			$map[ $code ] = 0;
		}
		return $map;
	}
}

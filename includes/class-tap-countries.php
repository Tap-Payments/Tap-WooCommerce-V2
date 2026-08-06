<?php
/**
 * Country dialing code lookup.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an ISO 3166-1 alpha-2 country code to its international dialing
 * code.
 *
 * The 2.x implementation built a 230-entry array literal inside a gateway
 * method on every call and returned an undefined index (emitting a PHP warning
 * and yielding null) for any country not in the list.
 */
final class Tap_Countries {

	/**
	 * Cached dialing code map.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $dial_codes = null;

	/**
	 * Get the international dialing code for a country.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 code.
	 * @return string Dialing code without a leading "+", or an empty string when unknown.
	 */
	public static function dial_code( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );

		if ( '' === $country_code ) {
			return '';
		}

		$codes = self::get_dial_codes();

		if ( ! isset( $codes[ $country_code ] ) ) {
			Tap_Logger::debug( 'No dialing code mapped for country.', array( 'country' => $country_code ) );
			return '';
		}

		return $codes[ $country_code ];
	}

	/**
	 * Load the dialing code map.
	 *
	 * @return array<string, string>
	 */
	private static function get_dial_codes(): array {
		if ( null === self::$dial_codes ) {
			try {
				$codes = require TAP_GATEWAY_PATH . 'includes/data/country-dial-codes.php';
			} catch ( Throwable $e ) {
				// A missing or corrupt data file must not stop a payment: the
				// dialing code is a convenience field on the customer record.
				Tap_Logger::exception( 'Could not load the country dialing code data.', $e );
				self::$dial_codes = array();
				return self::$dial_codes;
			}

			if ( ! is_array( $codes ) ) {
				Tap_Logger::error( 'The country dialing code data file did not return an array.' );
				self::$dial_codes = array();
				return self::$dial_codes;
			}

			/**
			 * Filter the country dialing code map.
			 *
			 * @since 3.0.0
			 *
			 * @param array<string, string> $codes ISO alpha-2 code => dialing code.
			 */
			self::$dial_codes = (array) apply_filters( 'wc_tap_country_dial_codes', $codes );
		}

		return self::$dial_codes;
	}
}

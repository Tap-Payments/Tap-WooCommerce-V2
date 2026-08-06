<?php
/**
 * HMAC signature generation and verification.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Builds the outbound checkout hash string and verifies the inbound webhook
 * signature.
 *
 * Note on trust: webhook signature verification here is defence in depth only.
 * The authoritative check in Tap_Webhook_Handler is a server-to-server re-fetch
 * of the transaction from the Tap API using the merchant's secret key. That
 * makes the plugin's security independent of the exact canonical string Tap
 * signs, which is documented by Tap rather than derivable from this codebase.
 */
final class Tap_Signature {

	/**
	 * Header Tap sends its webhook signature in.
	 */
	public const WEBHOOK_HEADER = 'hashstring';

	/**
	 * Build the checkout hash string sent to the Tap SDK.
	 *
	 * The canonical string is
	 * x_publickey{pk}x_amount{amount}x_currency{currency}x_transaction{ref}x_post{post_url}
	 * signed with HMAC-SHA256 using the secret key.
	 *
	 * @param string $public_key Publishable key.
	 * @param string $amount     Amount, already formatted to the currency's precision.
	 * @param string $currency   ISO 4217 code.
	 * @param string $reference  Transaction reference (the order id).
	 * @param string $post_url   Webhook URL sent with the transaction.
	 * @param string $secret_key Secret key used as the HMAC key.
	 * @return string Hex-encoded HMAC.
	 */
	public static function checkout_hash(
		string $public_key,
		string $amount,
		string $currency,
		string $reference,
		string $post_url,
		string $secret_key
	): string {
		$canonical = 'x_publickey' . $public_key
			. 'x_amount' . $amount
			. 'x_currency' . $currency
			. 'x_transaction' . $reference
			. 'x_post' . $post_url;

		return hash_hmac( 'sha256', $canonical, $secret_key );
	}

	/**
	 * Verify the signature on an inbound webhook.
	 *
	 * Tap signs the canonical string
	 * x_id{id}x_amount{amount}x_currency{currency}x_gateway_reference{ref}x_payment_reference{ref}x_status{status}x_created{created}
	 * with HMAC-SHA256 using the secret key.
	 *
	 * @param array<string, mixed> $payload    Decoded webhook body.
	 * @param string               $signature  Signature supplied in the request header.
	 * @param string               $secret_key Secret key used as the HMAC key.
	 * @return bool True when the signature matches.
	 */
	public static function verify_webhook( array $payload, string $signature, string $secret_key, string $raw_body = '' ): bool {
		if ( '' === $signature || '' === $secret_key ) {
			return false;
		}

		foreach ( self::webhook_hash_candidates( $payload, $secret_key, $raw_body ) as $variant => $expected ) {
			// hash_equals() is required here: a plain === on a secret comparison
			// is vulnerable to timing analysis.
			if ( hash_equals( $expected, $signature ) ) {
				Tap_Logger::debug( 'Webhook signature matched.', array( 'variant' => $variant ) );
				return true;
			}
		}

		return false;
	}

	/**
	 * Compute the expected webhook signature for a payload.
	 *
	 * @param array<string, mixed> $payload    Decoded webhook body.
	 * @param string               $secret_key Secret key used as the HMAC key.
	 * @param string               $raw_body   Raw request body, when available.
	 * @return string Hex-encoded HMAC for the primary variant.
	 */
	public static function webhook_hash( array $payload, string $secret_key, string $raw_body = '' ): string {
		$candidates = self::webhook_hash_candidates( $payload, $secret_key, $raw_body );

		return (string) reset( $candidates );
	}

	/**
	 * Compute every plausible expected signature for a payload.
	 *
	 * Tap signs the canonical string
	 * x_id{id}x_amount{amount}x_currency{currency}x_gateway_reference{ref}x_payment_reference{ref}x_status{status}x_created{created}
	 * with HMAC-SHA256 using the secret key.
	 *
	 * The ambiguity is entirely in how the amount is rendered. JSON carries it
	 * as a number, so json_decode() turns "65.00" into float(65), and casting
	 * that back to a string gives "65" — which is not what Tap signed. Rather
	 * than guess, each plausible rendering is tried. Trying several costs
	 * nothing in security terms: an attacker still has to produce a valid
	 * HMAC without the secret key.
	 *
	 * @param array<string, mixed> $payload    Decoded webhook body.
	 * @param string               $secret_key Secret key used as the HMAC key.
	 * @param string               $raw_body   Raw request body, when available.
	 * @return array<string, string> Variant label => hex-encoded HMAC.
	 */
	public static function webhook_hash_candidates( array $payload, string $secret_key, string $raw_body = '' ): array {
		$reference = isset( $payload['reference'] ) && is_array( $payload['reference'] ) ? $payload['reference'] : array();

		$prefix = 'x_id' . self::scalar( $payload, 'id' );
		$suffix = 'x_currency' . self::scalar( $payload, 'currency' )
			. 'x_gateway_reference' . self::scalar( $reference, 'gateway' )
			. 'x_payment_reference' . self::scalar( $reference, 'payment' )
			. 'x_status' . self::scalar( $payload, 'status' )
			. 'x_created' . self::scalar( $payload, 'transaction', 'created' );

		$candidates = array();

		foreach ( self::amount_variants( $payload, $raw_body ) as $label => $amount ) {
			$canonical = $prefix . 'x_amount' . $amount . $suffix;

			/**
			 * Filter the canonical string used to verify webhook signatures.
			 *
			 * Provided so a change to Tap's signing scheme can be accommodated
			 * without patching the plugin. Note that webhook authenticity does
			 * not rest on this check: every webhook is independently verified
			 * by re-fetching the transaction from the Tap API.
			 *
			 * @since 3.0.0
			 *
			 * @param string               $canonical Canonical string to be signed.
			 * @param array<string, mixed> $payload   Decoded webhook body.
			 */
			$canonical = (string) apply_filters( 'wc_tap_webhook_canonical_string', $canonical, $payload );

			$candidates[ $label ] = hash_hmac( 'sha256', $canonical, $secret_key );
		}

		return $candidates;
	}

	/**
	 * Every plausible rendering of the payload's amount.
	 *
	 * @param array<string, mixed> $payload  Decoded webhook body.
	 * @param string               $raw_body Raw request body, when available.
	 * @return array<string, string> Variant label => amount as a string.
	 */
	private static function amount_variants( array $payload, string $raw_body ): array {
		$currency = self::scalar( $payload, 'currency' );
		$raw      = self::raw_amount( $raw_body );
		$decoded  = $payload['amount'] ?? null;

		$variants = array();

		// Most faithful: the literal characters Tap put on the wire.
		if ( '' !== $raw ) {
			$variants['raw_body'] = $raw;
		}

		// Formatted to the currency's own precision (65 -> "65.00", "10.5" -> "10.500").
		if ( is_scalar( $decoded ) && '' !== $currency ) {
			$variants['currency_decimals'] = Tap_Currency::format( $decoded, $currency );
		}

		// Plain cast, which is what a float that happens to be whole produces.
		if ( is_scalar( $decoded ) ) {
			$variants['plain'] = (string) $decoded;
		}

		if ( empty( $variants ) ) {
			$variants['plain'] = '';
		}

		return array_unique( $variants );
	}

	/**
	 * Extract the amount exactly as it appeared in the raw JSON body.
	 *
	 * json_decode() destroys the original formatting of a JSON number, and the
	 * original formatting is what was signed.
	 *
	 * @param string $raw_body Raw request body.
	 * @return string Literal amount text, or an empty string.
	 */
	private static function raw_amount( string $raw_body ): string {
		if ( '' === $raw_body ) {
			return '';
		}

		if ( preg_match( '/"amount"\s*:\s*"?(-?\d+(?:\.\d+)?)"?/', $raw_body, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Read a nested scalar out of a payload as a string.
	 *
	 * @param array<string, mixed> $data Source array.
	 * @param string               ...$keys Nested keys.
	 * @return string
	 */
	private static function scalar( array $data, string ...$keys ): string {
		$value = $data;

		foreach ( $keys as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return '';
			}
			$value = $value[ $key ];
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}

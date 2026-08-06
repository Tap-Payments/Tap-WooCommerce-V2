<?php
/**
 * Inbound webhook endpoint.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Handles POSTs from Tap at /wc-api/tap_webhook.
 *
 * The endpoint is public and unauthenticated by nature, so nothing in the
 * request body is trusted. Two independent gates apply:
 *
 * 1. The signature on the request is verified when Tap supplies one.
 * 2. Regardless of (1), the transaction is re-fetched from the Tap API using
 *    the merchant's secret key, and only that response is used to decide what
 *    happens to the order.
 *
 * Gate (2) is the authoritative one: an attacker cannot make Tap's own API
 * report a forged transaction as captured. 2.x had neither gate and would mark
 * any pending order as paid on request.
 */
final class Tap_Webhook_Handler {

	/**
	 * Handle an inbound webhook request.
	 *
	 * Always terminates the request with a JSON response, including when
	 * something unexpected is thrown: a PHP fatal here would give Tap an opaque
	 * 500 and leave the order unsettled with no record of why.
	 */
	public function handle(): void {
		Tap_Logger::set_request_id( Tap_Logger::generate_request_id() );

		try {
			list( $status_code, $success, $code ) = $this->process();
		} catch ( Tap_Api_Exception $e ) {
			Tap_Logger::exception( 'Webhook could not be verified.', $e );
			// Retryable: the failure is on our side or Tap's, not the caller's.
			list( $status_code, $success, $code ) = array( 502, false, 'verification_failed' );
		} catch ( Throwable $e ) {
			Tap_Logger::exception( 'Unhandled failure while processing a Tap webhook.', $e );
			list( $status_code, $success, $code ) = array( 500, false, 'internal_error' );
		}

		// Sent outside the try block: the response terminates the request, and
		// must not be swallowed by the catch.
		$this->respond( $status_code, $success, $code );
	}

	/**
	 * Verify and apply an inbound webhook.
	 *
	 * @return array{0:int,1:bool,2:string} HTTP status code, success flag, outcome code.
	 */
	private function process(): array {
		$raw_body = (string) file_get_contents( 'php://input' );
		$payload  = json_decode( $raw_body, true );

		if ( ! is_array( $payload ) ) {
			Tap_Logger::warning( 'Webhook rejected: body is not valid JSON.' );
			return array( 400, false, 'invalid_payload' );
		}

		$transaction_id = isset( $payload['id'] ) && is_scalar( $payload['id'] ) ? (string) $payload['id'] : '';

		if ( ! Tap_Validator::is_valid_transaction_id( $transaction_id ) ) {
			Tap_Logger::warning( 'Webhook rejected: missing or malformed transaction id.' );
			return array( 400, false, 'invalid_transaction_id' );
		}

		$gateway = Tap_Plugin::get_gateway();

		if ( ! $gateway instanceof WC_Tap_Gateway ) {
			Tap_Logger::error( 'Webhook received but the Tap gateway is unavailable.' );
			return array( 503, false, 'gateway_unavailable' );
		}

		$secret_key = $gateway->get_secret_key();

		if ( '' === $secret_key ) {
			Tap_Logger::error( 'Webhook received but no secret key is configured.' );
			return array( 503, false, 'gateway_not_configured' );
		}

		// Gate 1: signature, when one was supplied.
		//
		// Advisory by default. Tap's canonical string is documented by Tap
		// rather than derivable from this codebase, and a wrong guess here
		// would reject every genuine notification and silently strand paid
		// orders. Gate 2 below is what actually establishes authenticity, and
		// it does not depend on this check at all.
		//
		// Merchants who have confirmed the scheme against their own traffic can
		// make it binding with:
		//   add_filter( 'wc_tap_enforce_webhook_signature', '__return_true' );
		$signature = $this->get_signature_header();

		if ( '' === $signature ) {
			Tap_Logger::debug(
				'Webhook carried no signature header.',
				array( 'transaction' => $transaction_id )
			);
		} elseif ( ! Tap_Signature::verify_webhook( $payload, $signature, $secret_key, $raw_body ) ) {
			$this->log_signature_mismatch( $payload, $signature, $secret_key, $raw_body, $transaction_id );

			/**
			 * Filter whether a failed signature check rejects the webhook.
			 *
			 * @since 3.0.0
			 *
			 * @param bool                 $enforce Whether to reject. Default false.
			 * @param array<string, mixed> $payload Decoded webhook body.
			 */
			if ( apply_filters( 'wc_tap_enforce_webhook_signature', false, $payload ) ) {
				return array( 401, false, 'invalid_signature' );
			}
		}

		// Gate 2: re-fetch from Tap. This, not the posted body, decides what happens.
		$api         = new Tap_Api_Client( $secret_key );
		$transaction = $api->get_transaction( $transaction_id );

		if ( ! $transaction->is_success() ) {
			throw Tap_Api_Exception::from_response(
				$transaction,
				'Webhook verification',
				array( 'transaction' => $transaction_id )
			);
		}

		$order = $this->resolve_order( $transaction );

		if ( ! $order instanceof WC_Order ) {
			Tap_Logger::warning(
				'Webhook references an order that does not exist or is not a Tap order.',
				array(
					'transaction' => $transaction_id,
					'reference'   => $transaction->get_string( 'reference.order' ),
				)
			);
			return array( 404, false, 'order_not_found' );
		}

		$processor = new Tap_Order_Processor( $order, $api, $gateway->get_webhook_url() );
		$outcome   = $processor->apply( $transaction );

		Tap_Logger::info(
			'Webhook processed.',
			array(
				'order'       => $order->get_id(),
				'transaction' => $transaction_id,
				'outcome'     => $outcome,
			)
		);

		$accepted = in_array(
			$outcome,
			array(
				Tap_Order_Processor::OUTCOME_PAID,
				Tap_Order_Processor::OUTCOME_AUTHORIZED,
				Tap_Order_Processor::OUTCOME_DECLINED,
				Tap_Order_Processor::OUTCOME_ALREADY_PROCESSED,
				Tap_Order_Processor::OUTCOME_MISMATCH,
				Tap_Order_Processor::OUTCOME_LOCKED,
			),
			true
		);

		if ( $accepted ) {
			return array( 200, true, $outcome );
		}

		// OUTCOME_ERROR means something transient went wrong on our side, so
		// ask Tap to retry. The other outcomes are permanent rejections.
		$status_code = Tap_Order_Processor::OUTCOME_ERROR === $outcome ? 500 : 409;

		return array( $status_code, false, $outcome );
	}

	/**
	 * Record everything needed to work out Tap's actual signing scheme.
	 *
	 * Logs the signed fields and the candidate digests, never the secret key.
	 * Customer details from the payload are deliberately not logged.
	 *
	 * @param array<string, mixed> $payload        Decoded webhook body.
	 * @param string               $signature      Signature Tap supplied.
	 * @param string               $secret_key     Secret key used as the HMAC key.
	 * @param string               $raw_body       Raw request body.
	 * @param string               $transaction_id Tap transaction id.
	 */
	private function log_signature_mismatch(
		array $payload,
		string $signature,
		string $secret_key,
		string $raw_body,
		string $transaction_id
	): void {
		$reference = isset( $payload['reference'] ) && is_array( $payload['reference'] ) ? $payload['reference'] : array();

		$fields = array(
			'transaction'       => $transaction_id,
			'received'          => $signature,
			'id'                => $payload['id'] ?? '',
			'amount_decoded'    => var_export( $payload['amount'] ?? null, true ),
			'amount_raw'        => ( preg_match( '/"amount"\s*:\s*"?(-?\d+(?:\.\d+)?)"?/', $raw_body, $m ) ? $m[1] : '' ),
			'currency'          => $payload['currency'] ?? '',
			'gateway_reference' => $reference['gateway'] ?? '',
			'payment_reference' => $reference['payment'] ?? '',
			'status'            => $payload['status'] ?? '',
			'created'           => $payload['transaction']['created'] ?? '',
		);

		foreach ( Tap_Signature::webhook_hash_candidates( $payload, $secret_key, $raw_body ) as $variant => $digest ) {
			$fields[ 'candidate_' . $variant ] = $digest;
		}

		Tap_Logger::warning(
			'Webhook signature did not match any known variant; accepting on API verification instead. '
				. 'Send this line to whoever maintains the integration so the scheme can be pinned.',
			$fields
		);
	}

	/**
	 * Find the order a verified transaction belongs to.
	 *
	 * The reference is taken from the API response, never from the posted body.
	 *
	 * @param Tap_Response $transaction Verified transaction.
	 * @return WC_Order|null
	 */
	private function resolve_order( Tap_Response $transaction ): ?WC_Order {
		$reference = $transaction->get_string( 'reference.order' );

		if ( '' === $reference ) {
			$reference = $transaction->get_string( 'reference.transaction' );
		}

		$order_id = absint( $reference );

		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || 'tap' !== $order->get_payment_method() ) {
			return null;
		}

		return $order;
	}

	/**
	 * Read the signature header without relying on apache_request_headers().
	 *
	 * apache_request_headers() is not defined on every SAPI; calling it in 2.x
	 * risked a fatal error on the most critical endpoint in the plugin.
	 *
	 * @return string
	 */
	private function get_signature_header(): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', Tap_Signature::WEBHOOK_HEADER ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Compared with hash_equals, never rendered.
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}

		return trim( sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) );
	}

	/**
	 * Emit a JSON response and end the request.
	 *
	 * 2.x emitted nothing, leaving Tap unable to tell a processed notification
	 * from a rejected one, and so unable to retry usefully.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param bool   $success     Whether the notification was accepted.
	 * @param string $code        Machine-readable outcome code.
	 * @return never
	 */
	private function respond( int $status_code, bool $success, string $code ): void {
		nocache_headers();

		// wp_send_json() sets the status code and always terminates the request.
		wp_send_json(
			array(
				'success' => $success,
				'code'    => $code,
			),
			$status_code
		);
	}
}

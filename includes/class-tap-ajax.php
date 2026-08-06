<?php
/**
 * AJAX endpoints.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Records the Tap transaction id on an order as soon as the checkout script
 * receives it, so the order keeps a reference even if the customer closes the
 * browser before returning.
 *
 * The endpoint is reachable by logged-out customers, and the nonce that guards
 * it is printed on a page any visitor can load, so the nonce alone proves
 * nothing about ownership. The order key is therefore required as well. In 2.x
 * this endpoint let any visitor overwrite the transaction id on, and inject
 * order notes into, any order on the store.
 */
final class Tap_Ajax {

	public const ACTION       = 'tap_save_charge_id';
	public const NONCE_ACTION = 'tap_save_charge_id';

	/**
	 * Handle the save-transaction-id request.
	 *
	 * Always answers with JSON. The checkout script treats a failure here as
	 * non-fatal and redirects anyway, because the server re-verifies the payment
	 * against the Tap API on return.
	 */
	public function save_charge_id(): void {
		try {
			list( $status_code, $success, $data ) = $this->process();
		} catch ( Throwable $e ) {
			Tap_Logger::exception( 'Unhandled failure while recording a Tap transaction id.', $e );
			list( $status_code, $success, $data ) = array( 500, false, array( 'code' => 'internal_error' ) );
		}

		// Sent outside the try block: these terminate the request.
		if ( $success ) {
			wp_send_json_success( $data, $status_code );
		}

		wp_send_json_error( $data, $status_code );
	}

	/**
	 * Validate and record the transaction id.
	 *
	 * @return array{0:int,1:bool,2:array<string, mixed>} Status code, success flag, response data.
	 */
	private function process(): array {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			return array( 403, false, array( 'code' => 'bad_nonce' ) );
		}

		$order_id     = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key    = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$charge_id    = isset( $_POST['charge_id'] ) ? sanitize_text_field( wp_unslash( $_POST['charge_id'] ) ) : '';
		$client_error = isset( $_POST['client_error'] ) ? sanitize_text_field( wp_unslash( $_POST['client_error'] ) ) : '';

		if ( $order_id <= 0 || '' === $order_key ) {
			return array( 400, false, array( 'code' => 'missing_params' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order
			|| 'tap' !== $order->get_payment_method()
			|| ! hash_equals( $order->get_order_key(), $order_key )
		) {
			Tap_Logger::warning(
				'Rejected an unauthorized attempt to write to an order.',
				array( 'order' => $order_id )
			);
			return array( 403, false, array( 'code' => 'forbidden' ) );
		}

		// A checkout-script error. Recorded so SDK failures are diagnosable from
		// the WooCommerce log rather than only from the customer's console.
		if ( '' !== $client_error ) {
			Tap_Logger::error(
				'Tap checkout reported an error in the browser.',
				array(
					'order' => $order->get_id(),
					'error' => mb_substr( $client_error, 0, 500 ),
				)
			);

			$order->add_order_note(
				sprintf(
					/* translators: %s: error reported by the Tap checkout script. */
					esc_html__( 'Tap checkout could not start: %s', 'wc-tap-gateway' ),
					esc_html( mb_substr( $client_error, 0, 300 ) )
				)
			);
			$order->save();

			return array( 200, true, array( 'logged' => true ) );
		}

		if ( ! Tap_Validator::is_valid_transaction_id( $charge_id ) ) {
			return array( 400, false, array( 'code' => 'invalid_charge_id' ) );
		}

		$existing = (string) $order->get_meta( Tap_Order_Processor::META_CHARGE_ID );

		// Never overwrite an id that is already recorded: the order has moved on
		// and this call can only be stale or hostile.
		if ( '' !== $existing ) {
			return array( 200, true, array( 'charge_id' => $existing ) );
		}

		$order->update_meta_data( Tap_Order_Processor::META_CHARGE_ID, $charge_id );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Tap transaction id. */
				esc_html__( 'Tap payment started. Transaction ID: %s', 'wc-tap-gateway' ),
				esc_html( $charge_id )
			)
		);
		$order->save();

		Tap_Logger::debug(
			'Recorded transaction id from the checkout script.',
			array(
				'order'       => $order->get_id(),
				'transaction' => $charge_id,
			)
		);

		return array( 200, true, array( 'charge_id' => $charge_id ) );
	}
}

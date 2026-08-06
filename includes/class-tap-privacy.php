<?php
/**
 * Personal data export and erasure.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Registers the order meta this plugin writes with WooCommerce's personal data
 * exporter and eraser, so it is covered by GDPR data requests.
 *
 * 2.x stored the customer's IP address on cancelled orders indefinitely with no
 * export or erasure path.
 */
final class Tap_Privacy {

	/**
	 * Order meta keys that may contain personal data.
	 *
	 * @var array<string, string>
	 */
	private const PERSONAL_META = array(
		'_tap_cancelled_ip' => 'IP address recorded at cancellation',
	);

	/**
	 * Add the plugin's meta to WooCommerce's order data export.
	 *
	 * @param array<int, array<string, mixed>> $data  Existing export items.
	 * @param WC_Order|mixed                   $order Order being exported.
	 * @return array<int, array<string, mixed>>
	 */
	public function export_order_data( $data, $order ): array {
		$data = is_array( $data ) ? $data : array();

		try {
			if ( ! $order instanceof WC_Order || 'tap' !== $order->get_payment_method() ) {
				return $data;
			}

			foreach ( self::PERSONAL_META as $meta_key => $label ) {
				$value = (string) $order->get_meta( $meta_key );

				if ( '' === $value ) {
					continue;
				}

				$data[] = array(
					'name'  => __( 'Tap: ', 'wc-tap-gateway' ) . $label,
					'value' => $value,
				);
			}

			return $data;
		} catch ( Throwable $e ) {
			// Returns what it was given: breaking the export would deny the data
			// subject the rest of their WooCommerce data too.
			Tap_Logger::exception( 'Could not add Tap data to the personal data export.', $e );
			return $data;
		}
	}

	/**
	 * Remove the plugin's personal meta when an order is anonymized.
	 *
	 * @param WC_Order|mixed $order Order being erased.
	 */
	public function erase_order_data( $order ): void {
		try {
			if ( ! $order instanceof WC_Order || 'tap' !== $order->get_payment_method() ) {
				return;
			}

			$changed = false;

			foreach ( array_keys( self::PERSONAL_META ) as $meta_key ) {
				if ( '' !== (string) $order->get_meta( $meta_key ) ) {
					$order->delete_meta_data( $meta_key );
					$changed = true;
				}
			}

			if ( $changed ) {
				$order->save();
			}
		} catch ( Throwable $e ) {
			// Logged at error level: an erasure that silently did not happen is
			// a compliance problem, so it must be visible.
			Tap_Logger::exception( 'Could not erase Tap personal data from an order.', $e );
		}
	}

	/**
	 * Describe the plugin's data handling on the privacy policy screen.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		try {
			$this->register_privacy_policy_content();
		} catch ( Throwable $e ) {
			Tap_Logger::exception( 'Could not add the Tap privacy policy suggestion.', $e );
		}
	}

	/**
	 * Register the suggested privacy policy text.
	 */
	private function register_privacy_policy_content(): void {
		$content = '<p>' . __( 'When a customer pays with Tap, their name, email address, phone number, billing country, order total, and order line items are sent to Tap Payments in order to process the payment. Tap returns a transaction identifier, which is stored on the order.', 'wc-tap-gateway' ) . '</p>'
			. '<p>' . __( 'See the Tap Payments privacy policy for details of how they handle that data.', 'wc-tap-gateway' ) . '</p>';

		wp_add_privacy_policy_content( __( 'WooCommerce Tap Payment Gateway', 'wc-tap-gateway' ), wp_kses_post( $content ) );
	}
}

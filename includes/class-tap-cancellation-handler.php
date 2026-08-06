<?php
/**
 * Audit trail and safety net for cancelled Tap orders.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Two behaviours on the same transition:
 *
 * 1. Record who cancelled a Tap order and from where.
 * 2. Re-check the stored transaction against the Tap API and restore the order
 *    if it was in fact captured.
 *
 * Both are scoped to Tap orders. In 2.x the audit trail ran for every gateway's
 * orders, writing notes and meta onto orders this plugin has nothing to do with,
 * and the re-check ran a synchronous API call inside the status transition
 * itself, then transitioned the order again from within that same hook.
 */
final class Tap_Cancellation_Handler {

	public const RECHECK_HOOK = 'wc_tap_recheck_cancelled_order';

	private const META_CANCELLED_BY      = '_tap_cancelled_by';
	private const META_CANCELLED_CONTEXT = '_tap_cancelled_context';
	private const META_CANCELLED_IP      = '_tap_cancelled_ip';
	private const META_CANCELLED_TIME    = '_tap_cancelled_time';
	private const META_RECHECKED         = '_tap_cancel_rechecked';

	/**
	 * How recently the order must have been verified for the re-check to be
	 * considered redundant, in seconds.
	 */
	private const RECENT_VERIFICATION_WINDOW = 300;

	/**
	 * Handle a Tap order entering the cancelled or failed status.
	 *
	 * @param int|string    $order_id Order id.
	 * @param WC_Order|null $order    Order, when WooCommerce supplied it.
	 */
	public function handle( $order_id, $order = null ): void {
		try {
			if ( ! $order instanceof WC_Order ) {
				$order = wc_get_order( absint( $order_id ) );
			}

			if ( ! $order instanceof WC_Order || 'tap' !== $order->get_payment_method() ) {
				return;
			}

			$this->record_audit_trail( $order );
			$this->schedule_recheck( $order );
		} catch ( Throwable $e ) {
			// This runs inside a status transition. Throwing here would abort
			// the transition itself and could leave the order in limbo, so the
			// audit trail is treated as best-effort.
			Tap_Logger::exception(
				'Could not record the cancellation audit trail.',
				$e,
				array( 'order' => $order_id )
			);
		}
	}

	/**
	 * Record who cancelled the order and from where.
	 *
	 * @param WC_Order $order Order.
	 */
	private function record_audit_trail( WC_Order $order ): void {
		$user = wp_get_current_user();

		if ( $user instanceof WP_User && $user->ID > 0 ) {
			$roles = implode( ', ', (array) $user->roles );
			$who   = sprintf( '%s (#%d%s)', $user->display_name, $user->ID, '' !== $roles ? ', ' . $roles : '' );
		} else {
			$who = __( 'Guest or system (not logged in)', 'wc-tap-gateway' );
		}

		$context = $this->describe_context();

		$note = sprintf(
			/* translators: 1: who triggered the cancellation, 2: request context. */
			__( 'Order cancelled by %1$s via %2$s.', 'wc-tap-gateway' ),
			$who,
			$context
		);

		$order->add_order_note( esc_html( $note ) );
		$order->update_meta_data( self::META_CANCELLED_BY, $who );
		$order->update_meta_data( self::META_CANCELLED_CONTEXT, $context );
		$order->update_meta_data( self::META_CANCELLED_TIME, current_time( 'mysql' ) );

		/**
		 * Filter whether the customer's IP address is stored on cancelled orders.
		 *
		 * Storing it aids fraud investigation but retains personal data beyond
		 * what the order itself needs, so it is off by default. The address is
		 * covered by the plugin's personal data exporter and eraser when stored.
		 *
		 * @since 3.0.0
		 *
		 * @param bool     $store Whether to store the IP address. Default false.
		 * @param WC_Order $order Order being cancelled.
		 */
		if ( apply_filters( 'wc_tap_log_cancellation_ip', false, $order ) ) {
			$ip = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : '';
			if ( '' !== $ip ) {
				$order->update_meta_data( self::META_CANCELLED_IP, $ip );
			}
		}

		$order->save();
	}

	/**
	 * Describe how the current request was initiated.
	 *
	 * @return string
	 */
	private function describe_context(): string {
		if ( doing_action( 'woocommerce_cancel_unpaid_orders' ) ) {
			return __( 'the unpaid-order hold-stock timeout', 'wc-tap-gateway' );
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return __( 'a scheduled task', 'wc-tap-gateway' );
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return __( 'an AJAX request', 'wc-tap-gateway' );
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return __( 'the REST API', 'wc-tap-gateway' );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return __( 'WP-CLI', 'wc-tap-gateway' );
		}
		if ( is_admin() ) {
			return __( 'the admin dashboard', 'wc-tap-gateway' );
		}
		return __( 'the storefront', 'wc-tap-gateway' );
	}

	/**
	 * Queue a re-check of the transaction behind a cancelled order.
	 *
	 * Deferred rather than run inline: transitioning an order from inside its
	 * own status-transition hook is fragile, and a synchronous API call there
	 * would stall bulk cancellations and the unpaid-orders cron.
	 *
	 * @param WC_Order $order Order.
	 */
	private function schedule_recheck( WC_Order $order ): void {
		$transaction_id = $this->get_transaction_id( $order );

		if ( '' === $transaction_id ) {
			return;
		}

		// We just verified this order ourselves; nothing has changed at Tap.
		$verified_at = (int) $order->get_meta( Tap_Order_Processor::META_LAST_VERIFIED );
		if ( $verified_at > 0 && ( time() - $verified_at ) < self::RECENT_VERIFICATION_WINDOW ) {
			return;
		}

		if ( (string) $order->get_meta( self::META_RECHECKED ) === $transaction_id ) {
			return;
		}

		$args = array( $order->get_id() );

		if ( ! wp_next_scheduled( self::RECHECK_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 60, self::RECHECK_HOOK, $args );
		}
	}

	/**
	 * Re-check a cancelled order against the Tap API and restore it if it was
	 * actually paid.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function run_recheck( $order_id ): void {
		try {
			$this->recheck( $order_id );
		} catch ( Throwable $e ) {
			// Runs on cron. An uncaught throwable would abort the whole cron
			// run, taking unrelated scheduled work with it.
			Tap_Logger::exception(
				'Could not re-check a cancelled Tap order.',
				$e,
				array( 'order' => $order_id )
			);
		}
	}

	/**
	 * Re-check a cancelled order against the Tap API.
	 *
	 * @param int|string $order_id Order id.
	 */
	private function recheck( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof WC_Order || 'tap' !== $order->get_payment_method() ) {
			return;
		}

		if ( ! $order->has_status( array( 'cancelled', 'failed' ) ) ) {
			return; // Something else already settled it.
		}

		$transaction_id = $this->get_transaction_id( $order );

		if ( '' === $transaction_id || ! Tap_Validator::is_valid_transaction_id( $transaction_id ) ) {
			return;
		}

		$gateway = Tap_Plugin::get_gateway();

		if ( ! $gateway instanceof WC_Tap_Gateway || '' === $gateway->get_secret_key() ) {
			return;
		}

		// Record the attempt first, so a persistent failure cannot loop.
		$order->update_meta_data( self::META_RECHECKED, $transaction_id );
		$order->save();

		$api         = new Tap_Api_Client( $gateway->get_secret_key() );
		$transaction = $api->get_transaction( $transaction_id );

		if ( ! $transaction->is_success() ) {
			Tap_Logger::warning(
				'Could not re-check a cancelled order against the Tap API.',
				array(
					'order' => $order->get_id(),
					'error' => $transaction->get_error_message(),
				)
			);
			return;
		}

		$status = strtoupper( $transaction->get_string( 'status' ) );

		if ( 'CAPTURED' !== $status && 'AUTHORIZED' !== $status ) {
			return; // Correctly cancelled.
		}

		Tap_Logger::warning(
			'A cancelled order turns out to have been paid; restoring it.',
			array(
				'order'       => $order->get_id(),
				'transaction' => $transaction_id,
				'status'      => $status,
			)
		);

		$processor = new Tap_Order_Processor( $order, $api, $gateway->get_webhook_url() );
		$processor->apply( $transaction );
	}

	/**
	 * Best available transaction id for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function get_transaction_id( WC_Order $order ): string {
		foreach ( array( Tap_Order_Processor::META_COMPLETED_ID, Tap_Order_Processor::META_CHARGE_ID ) as $meta_key ) {
			$value = (string) $order->get_meta( $meta_key );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return (string) $order->get_transaction_id();
	}
}

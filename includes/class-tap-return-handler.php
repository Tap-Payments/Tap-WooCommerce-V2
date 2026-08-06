<?php
/**
 * Handles the customer's return from Tap.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Runs on the order-received page for Tap orders, verifies the transaction, and
 * routes the customer to the success or failure page.
 *
 * WooCommerce has already validated the order key before woocommerce_thankyou
 * fires, so the order itself is trusted here. The transaction id in the query
 * string is not: it is format-checked and then verified against the Tap API.
 */
final class Tap_Return_Handler {

	private const FAILURE_FLAG = 'tap_payment';

	/**
	 * Handle the customer's return.
	 *
	 * Nothing may escape: this runs inside the order-received template, where an
	 * uncaught throwable would replace the customer's confirmation page with a
	 * fatal error even though their payment went through.
	 *
	 * @param int|string $order_id Order id supplied by WooCommerce.
	 */
	public function handle( $order_id ): void {
		try {
			$this->process( $order_id );
		} catch ( Throwable $e ) {
			Tap_Logger::exception(
				'Unhandled failure while handling the customer return from Tap.',
				$e,
				array( 'order' => $order_id )
			);

			// Leave the order alone. The webhook is the authoritative path and
			// can still settle it; failing the order here on the basis of a bug
			// in our own code could reject a payment that actually succeeded.
			$this->add_failure_notice(
				$e instanceof Tap_Exception
					? $e->get_customer_message()
					: __( 'We could not confirm your payment yet. If you were charged, your order will update shortly.', 'wc-tap-gateway' )
			);
		}
	}

	/**
	 * Verify the transaction behind the customer's return and route them onward.
	 *
	 * @param int|string $order_id Order id supplied by WooCommerce.
	 */
	private function process( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof WC_Order || 'tap' !== $order->get_payment_method() ) {
			return;
		}

		Tap_Logger::set_request_id( Tap_Logger::generate_request_id() );

		// Only skip verification when the payment is genuinely settled.
		//
		// "cancelled" deliberately does NOT skip. WooCommerce's hold-stock cron
		// cancels unpaid orders on a timer, and it can fire while the customer
		// is still on Tap's payment page. Sending them to the failure page
		// without looking up the transaction would discard a payment that had
		// already been taken, leaving the customer charged and no transaction
		// id anywhere on the order. "failed" behaves the same way, for a
		// customer retrying after an earlier abandoned attempt.
		if ( self::is_settled( $order ) ) {
			$this->maybe_redirect_to_success_page( $order );
			return;
		}

		$gateway = Tap_Plugin::get_gateway();

		if ( ! $gateway instanceof WC_Tap_Gateway ) {
			Tap_Logger::error( 'Customer returned from Tap but the gateway is unavailable.' );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only identifiers, verified against the Tap API below.
		$transaction_id = isset( $_GET['tap_id'] ) ? sanitize_text_field( wp_unslash( $_GET['tap_id'] ) ) : '';
		$reported       = isset( $_GET['tap_status'] ) ? sanitize_text_field( wp_unslash( $_GET['tap_status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $transaction_id ) {
			// The popup records the transaction id on the order as soon as the
			// SDK reports one, so prefer that over anything in the URL.
			$stored = (string) $order->get_meta( Tap_Order_Processor::META_CHARGE_ID );

			if ( Tap_Validator::is_valid_transaction_id( $stored ) ) {
				Tap_Logger::debug(
					'No transaction id on the return URL; using the one recorded during checkout.',
					array(
						'order'       => $order->get_id(),
						'transaction' => $stored,
					)
				);
				$transaction_id = $stored;
			} elseif ( 'success' === $reported ) {
				// The SDK reported a successful payment but its id could not be
				// read. Failing the order here would reject a payment the
				// customer has already made; the webhook settles it instead.
				Tap_Logger::warning(
					'Checkout reported success with no readable transaction id; leaving the order for the webhook.',
					array( 'order' => $order->get_id() )
				);
				$this->add_failure_notice(
					__( 'Thanks — your payment is being confirmed. This page will update once your bank confirms it.', 'wc-tap-gateway' )
				);
				return;
			} else {
				// Genuinely no payment: the SDK failed to load, or the customer
				// abandoned the popup.
				Tap_Logger::info(
					'Customer returned from Tap without a transaction id; failing the order.',
					array( 'order' => $order->get_id() )
				);
				$this->fail_order(
					$order,
					__( 'The payment was not completed.', 'wc-tap-gateway' ),
					__( 'Customer returned from Tap without a transaction id.', 'wc-tap-gateway' )
				);
				$this->redirect_to_failure( $order );
			}
		}

		if ( ! Tap_Validator::is_valid_transaction_id( $transaction_id ) ) {
			Tap_Logger::warning(
				'Rejected a malformed transaction id on the return URL.',
				array( 'order' => $order->get_id() )
			);
			$this->fail_order(
				$order,
				__( 'The payment could not be verified.', 'wc-tap-gateway' ),
				__( 'Customer returned from Tap with a malformed transaction id.', 'wc-tap-gateway' )
			);
			$this->redirect_to_failure( $order );
		}

		$api         = new Tap_Api_Client( $gateway->get_secret_key() );
		$transaction = $api->get_transaction( $transaction_id );

		if ( ! $transaction->is_success() ) {
			Tap_Logger::error(
				'Could not verify the transaction on customer return.',
				array(
					'order'       => $order->get_id(),
					'transaction' => $transaction_id,
					'error'       => $transaction->get_error_message(),
				)
			);
			// The order is left pending on purpose: the payment may well have
			// succeeded, and the webhook can still settle it.
			$this->add_failure_notice( __( 'We could not confirm your payment yet. If you were charged, your order will update shortly.', 'wc-tap-gateway' ) );
			return;
		}

		$processor = new Tap_Order_Processor( $order, $api, $gateway->get_webhook_url() );
		$outcome   = $processor->apply( $transaction );

		switch ( $outcome ) {
			case Tap_Order_Processor::OUTCOME_PAID:
			case Tap_Order_Processor::OUTCOME_AUTHORIZED:
			case Tap_Order_Processor::OUTCOME_ALREADY_PROCESSED:
				if ( WC()->cart instanceof WC_Cart ) {
					WC()->cart->empty_cart();
				}
				$this->maybe_redirect_to_success_page( $order );
				break;

			case Tap_Order_Processor::OUTCOME_LOCKED:
				// The webhook is settling this order right now. Leave the page
				// alone; a refresh will show the final state.
				break;

			default:
				$this->maybe_restore_cart( $order );
				$this->redirect_to_failure( $order, $processor->get_failure_message() );
		}
	}

	/**
	 * Whether the order's payment has already been settled.
	 *
	 * Settled means WooCommerce has moved the order past payment, or this
	 * plugin has recorded the transaction that paid for it. Anything else —
	 * including cancelled and failed — is still worth verifying against Tap,
	 * because a transaction may have completed after the order left "pending".
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_settled( WC_Order $order ): bool {
		if ( '' !== (string) $order->get_meta( Tap_Order_Processor::META_COMPLETED_ID ) ) {
			return true;
		}

		return $order->has_status( array( 'processing', 'completed', 'on-hold', 'refunded' ) );
	}

	/**
	 * Mark an order failed without a Tap transaction to reference.
	 *
	 * @param WC_Order $order            Order.
	 * @param string   $customer_message Message shown to the customer.
	 * @param string   $note             Order note.
	 */
	private function fail_order( WC_Order $order, string $customer_message, string $note ): void {
		$this->maybe_restore_cart( $order );

		$order->update_meta_data( Tap_Order_Processor::META_FAIL_MESSAGE, $customer_message );
		$order->update_status( 'failed', $note );
		$order->save();
	}

	/**
	 * Put the order's items back in the cart, but only if the cart is empty.
	 *
	 * WooCommerce keeps the cart intact until payment completes, so on a failed
	 * payment the customer's cart is normally still populated. 2.x re-added
	 * every item unconditionally, which doubled the quantities.
	 *
	 * @param WC_Order $order Order whose items should be restored.
	 */
	private function maybe_restore_cart( WC_Order $order ): void {
		$cart = WC()->cart;

		if ( ! $cart instanceof WC_Cart || ! $cart->is_empty() ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$quantity     = max( 1, (int) $item->get_quantity() );

			// WC_Cart::add_to_cart() expects an array of attributes here. 2.x
			// passed an imploded string, and constructed a WC_Product_Variation
			// from id 0 for every non-variable product.
			$attributes = array();

			if ( $variation_id > 0 ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation instanceof WC_Product_Variation ) {
					$attributes = $variation->get_variation_attributes();
				}
			}

			try {
				$cart->add_to_cart( $product_id, $quantity, $variation_id, $attributes );
			} catch ( Throwable $e ) {
				Tap_Logger::warning(
					'Could not restore a cart item after a failed payment.',
					array(
						'order'   => $order->get_id(),
						'product' => $product_id,
						'error'   => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Send the customer to the configured success page, if there is one.
	 *
	 * @param WC_Order $order Order.
	 */
	private function maybe_redirect_to_success_page( WC_Order $order ): void {
		$gateway = Tap_Plugin::get_gateway();

		if ( ! $gateway instanceof WC_Tap_Gateway ) {
			return;
		}

		$page_id = (int) $gateway->get_option( 'success_page_id' );

		if ( $page_id <= 0 ) {
			return; // Stay on the standard order-received page.
		}

		$url = get_permalink( $page_id );

		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		$this->redirect( add_query_arg( 'order', $order->get_id(), $url ) );
	}

	/**
	 * Send the customer to the failure page.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $message Optional message to store for display.
	 * @return never
	 */
	private function redirect_to_failure( WC_Order $order, string $message = '' ): void {
		if ( '' !== $message ) {
			$order->update_meta_data( Tap_Order_Processor::META_FAIL_MESSAGE, $message );
			$order->save();
		}

		$this->redirect( self::get_failure_url( $order ) );
	}

	/**
	 * Build the failure page URL for an order.
	 *
	 * The failure reason is referenced by order id and order key rather than
	 * carried in the URL. 2.x put the message itself in a query parameter, which
	 * let anyone craft a link that displayed arbitrary text inside a WooCommerce
	 * error box on the merchant's own domain.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function get_failure_url( WC_Order $order ): string {
		$gateway = Tap_Plugin::get_gateway();
		$url     = '';

		if ( $gateway instanceof WC_Tap_Gateway ) {
			$page_id = (int) $gateway->get_option( 'failer_page_id' );
			if ( $page_id > 0 ) {
				$permalink = get_permalink( $page_id );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					$url = $permalink;
				}
			}
		}

		if ( '' === $url ) {
			$url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );
		}

		return add_query_arg(
			array(
				self::FAILURE_FLAG => 'failed',
				'tap_order'        => $order->get_id(),
				'tap_key'          => $order->get_order_key(),
			),
			$url
		);
	}

	/**
	 * Redirect, falling back to markup when output has already started.
	 *
	 * woocommerce_thankyou fires during template output, so a header redirect
	 * is not always possible.
	 *
	 * @param string $url Destination.
	 * @return never
	 */
	private function redirect( string $url ): void {
		if ( ! headers_sent() ) {
			wp_safe_redirect( $url );
			exit;
		}

		printf(
			'<meta http-equiv="refresh" content="0;url=%1$s" /><p><a href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Continue', 'wc-tap-gateway' )
		);
		exit;
	}

	/**
	 * Queue a WooCommerce error notice.
	 *
	 * @param string $message Message.
	 */
	private function add_failure_notice( string $message ): void {
		if ( function_exists( 'wc_add_notice' ) && ! wc_has_notice( $message, 'error' ) ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Prepend the failure notice to the failure page's content.
	 *
	 * Hooked to the_content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function render_failure_notice( $content ): string {
		$content = (string) $content;

		// This filter runs on every post and page on the site, so a throwable
		// here would break far more than the checkout.
		try {
			return $this->build_failure_notice( $content );
		} catch ( Throwable $e ) {
			Tap_Logger::exception( 'Could not render the Tap failure notice.', $e );
			return $content;
		}
	}

	/**
	 * Build the failure notice, if this request should show one.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function build_failure_notice( string $content ): string {
		static $already_shown = false;

		if ( $already_shown || is_admin() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display flag on a public page.
		if ( ! isset( $_GET[ self::FAILURE_FLAG ] ) || 'failed' !== sanitize_text_field( wp_unslash( $_GET[ self::FAILURE_FLAG ] ) ) ) {
			return $content;
		}

		$order_id  = isset( $_GET['tap_order'] ) ? absint( $_GET['tap_order'] ) : 0;
		$order_key = isset( $_GET['tap_key'] ) ? sanitize_text_field( wp_unslash( $_GET['tap_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$already_shown = true;

		$message = __( 'Your payment could not be completed. Please try again.', 'wc-tap-gateway' );

		if ( $order_id > 0 && '' !== $order_key ) {
			$order = wc_get_order( $order_id );

			// The order key proves the visitor owns this order, so the stored
			// message can safely be shown.
			if ( $order instanceof WC_Order && hash_equals( $order->get_order_key(), $order_key ) ) {
				$stored = (string) $order->get_meta( Tap_Order_Processor::META_FAIL_MESSAGE );
				if ( '' !== trim( $stored ) ) {
					$message = $stored;
				}
			}
		}

		$notice = '<div class="woocommerce"><ul class="woocommerce-error" role="alert"><li>'
			. esc_html( $message )
			. '</li></ul></div>';

		return $notice . $content;
	}
}

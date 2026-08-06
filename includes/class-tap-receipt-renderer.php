<?php
/**
 * Renders the popup checkout on the order-pay page.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Outputs the container the Tap SDK mounts into and hands the checkout script
 * its configuration.
 *
 * All data reaches the browser through wp_localize_script(), which JSON-encodes
 * and escapes it. 2.x echoed fourteen hidden inputs by string concatenation
 * with no escaping at all, including customer-controlled billing names.
 */
final class Tap_Receipt_Renderer {

	/**
	 * Gateway instance.
	 *
	 * @var WC_Tap_Gateway
	 */
	private WC_Tap_Gateway $gateway;

	/**
	 * Constructor.
	 *
	 * @param WC_Tap_Gateway $gateway Gateway instance.
	 */
	public function __construct( WC_Tap_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Render the receipt page for an order.
	 *
	 * @param int|string $order_id Order id supplied by WooCommerce.
	 */
	public function render( $order_id ): void {
		try {
			$this->render_checkout( $order_id );
		} catch ( Throwable $e ) {
			Tap_Logger::exception(
				'Could not render the Tap popup checkout.',
				$e,
				array( 'order' => $order_id )
			);

			printf(
				'<p class="woocommerce-error">%s</p>',
				esc_html__( 'We could not load the payment form. Please refresh the page or choose another payment method.', 'wc-tap-gateway' )
			);
		}
	}

	/**
	 * Render the popup checkout for an order.
	 *
	 * @param int|string $order_id Order id supplied by WooCommerce.
	 */
	private function render_checkout( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'popup' !== $this->gateway->get_option( 'ui_mode' ) ) {
			return;
		}

		$public_key = $this->gateway->get_public_key();
		$secret_key = $this->gateway->get_secret_key();

		if ( '' === $public_key || '' === $secret_key ) {
			Tap_Logger::error( 'Cannot render the Tap popup: API keys are not configured.' );
			echo '<p class="woocommerce-error">' . esc_html__( 'This payment method is not available right now. Please choose another one.', 'wc-tap-gateway' ) . '</p>';
			return;
		}

		// Registration first: under a block theme this method runs before
		// wp_enqueue_scripts, and wp_localize_script() throws nothing but
		// silently drops the data when the handle is not yet registered.
		WC_Tap_Gateway::ensure_assets_registered();

		wp_enqueue_style( WC_Tap_Gateway::STYLE_HANDLE );
		wp_enqueue_script( WC_Tap_Gateway::SDK_HANDLE );
		wp_enqueue_script( WC_Tap_Gateway::SCRIPT_HANDLE );

		$localized = wp_localize_script(
			WC_Tap_Gateway::SCRIPT_HANDLE,
			'tapCheckoutData',
			$this->build_config( $order, $public_key, $secret_key )
		);

		if ( ! $localized ) {
			// Never fail silently here again: with no configuration the script
			// returns immediately and the customer sees a page that does
			// nothing at all.
			Tap_Logger::error(
				'Could not attach the checkout configuration to the Tap script; the popup will not open.',
				array( 'order' => $order->get_id() )
			);
		}

		?>
		<div id="tap_root" class="tap-checkout-root"></div>
		<div id="tap_loading_overlay" class="tap-loading-overlay" aria-live="polite" aria-busy="true">
			<div class="tap-spinner" role="presentation"></div>
			<div class="tap-loading-text"><?php esc_html_e( 'Loading secure checkout…', 'wc-tap-gateway' ); ?></div>
		</div>
		<noscript>
			<p class="woocommerce-error">
				<?php esc_html_e( 'JavaScript is required to complete this payment. Please enable it and reload the page.', 'wc-tap-gateway' ); ?>
			</p>
		</noscript>
		<button type="button" id="tap_open_checkout" class="button alt">
			<?php esc_html_e( 'Pay now', 'wc-tap-gateway' ); ?>
		</button>
		<?php
	}

	/**
	 * Build the configuration handed to the checkout script.
	 *
	 * @param WC_Order $order      Order being paid.
	 * @param string   $public_key Active publishable key.
	 * @param string   $secret_key Active secret key.
	 * @return array<string, mixed>
	 */
	private function build_config( WC_Order $order, string $public_key, string $secret_key ): array {
		$currency = $order->get_currency();
		$amount   = Tap_Currency::format( $order->get_total(), $currency );
		$post_url = $this->gateway->get_webhook_url();

		// The hash must be computed over exactly the values the SDK submits. In
		// 2.x the hash covered an empty transaction reference while the script
		// sent the literal "quote_6", so the two could never agree.
		$reference = (string) $order->get_id();

		$hash = Tap_Signature::checkout_hash(
			$public_key,
			$amount,
			$currency,
			$reference,
			$post_url,
			$secret_key
		);

		return array(
			'publicKey'   => $public_key,
			'merchantId'  => (string) $this->gateway->get_option( 'merchant_id', '' ),
			'platformId'  => WC_Tap_Gateway::PLATFORM_ID,
			'mode'        => $this->gateway->get_transaction_mode(),
			'language'    => $this->gateway->get_language_code(),
			'themeMode'   => 'dark' === $this->gateway->get_option( 'theme_mode' ) ? 'dark' : 'light',
			'saveCard'    => 'yes' === $this->gateway->get_option( 'save_card' ),
			'currency'    => $currency,
			'amount'      => $amount,
			'hashString'  => $hash,
			'postUrl'     => $post_url,
			'returnUrl'   => $this->gateway->get_return_url( $order ),
			'orderId'     => $order->get_id(),
			'orderKey'    => $order->get_order_key(),
			'reference'   => $reference,
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( Tap_Ajax::NONCE_ACTION ),
			'customer'    => array(
				'firstName'   => $order->get_billing_first_name(),
				'lastName'    => $order->get_billing_last_name(),
				'email'       => $order->get_billing_email(),
				'phone'       => preg_replace( '/\D+/', '', (string) $order->get_billing_phone() ) ?? '',
				'countryCode' => Tap_Countries::dial_code( $order->get_billing_country() ),
			),
			'items'       => Tap_Request_Builder::order_items( $order ),
			'shipping'    => Tap_Request_Builder::shipping( $order ),
			'timeouts'    => array(
				'sdk'    => 20000,
				'render' => 60000,
			),
			'debug'       => 'yes' === $this->gateway->get_option( 'debug_log' ),
			'i18n'        => array(
				'loading'    => __( 'Loading secure checkout…', 'wc-tap-gateway' ),
				'completing' => __( 'Payment received. Completing your order…', 'wc-tap-gateway' ),
				'failed'     => __( 'The payment could not be started. Redirecting…', 'wc-tap-gateway' ),
			),
		);
	}
}

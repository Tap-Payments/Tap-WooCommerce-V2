<?php
/**
 * The WooCommerce payment gateway.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Tap payment gateway.
 *
 * Deliberately limited to WooCommerce's gateway contract: settings,
 * availability, checkout fields, process_payment(), and process_refund().
 * Payload construction, HTTP, verification, and order transitions live in their
 * own classes.
 */
final class WC_Tap_Gateway extends WC_Payment_Gateway {

	/**
	 * Tap platform identifier for the WooCommerce integration.
	 */
	public const PLATFORM_ID = 'commerce_platform_h8vB1824817Hyx71tc2A936';

	public const SDK_HANDLE    = 'tap-sdk';
	public const SCRIPT_HANDLE = 'tap-checkout';
	public const STYLE_HANDLE  = 'tap-payment';

	/**
	 * Whether test mode is active.
	 *
	 * @var bool
	 */
	public bool $testmode = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'tap';
		$this->has_fields         = false;
		$this->method_title       = __( 'Tap Gateway', 'wc-tap-gateway' );
		$this->method_description = __( 'Accept card and wallet payments through Tap.', 'wc-tap-gateway' );
		$this->icon               = TAP_GATEWAY_URL . 'assets/img/logo.png';

		$this->supports = array(
			'products',
			'refunds',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title' );
		$this->description = (string) $this->get_option( 'description' );
		$this->enabled     = (string) $this->get_option( 'enabled' );
		$this->testmode    = 'yes' === $this->get_option( 'testmode' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Define the settings form.
	 */
	public function init_form_fields(): void {
		try {
			$this->form_fields = Tap_Settings::get_form_fields();
		} catch ( Throwable $e ) {
			// The gateway is constructed on nearly every request, so a failure
			// building the settings form must not break the storefront.
			Tap_Logger::exception( 'Could not build the Tap settings form.', $e );
			$this->form_fields = array();
		}
	}

	/**
	 * Whether the gateway can be offered to the customer.
	 *
	 * 2.x offered the gateway even with no API keys configured, so the customer
	 * only discovered the problem at the moment of payment.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		try {
			if ( ! parent::is_available() ) {
				return false;
			}

			return '' !== $this->get_public_key() && '' !== $this->get_secret_key();
		} catch ( Throwable $e ) {
			// Fail closed: never offer a gateway whose availability could not
			// be determined.
			Tap_Logger::exception( 'Could not determine Tap gateway availability.', $e );
			return false;
		}
	}

	/**
	 * The active secret key for the configured mode.
	 *
	 * @return string
	 */
	public function get_secret_key(): string {
		return trim( (string) $this->get_option( $this->testmode ? 'test_secret_key' : 'live_secret_key', '' ) );
	}

	/**
	 * The active publishable key for the configured mode.
	 *
	 * @return string
	 */
	public function get_public_key(): string {
		return trim( (string) $this->get_option( $this->testmode ? 'test_public_key' : 'live_public_key', '' ) );
	}

	/**
	 * The configured transaction mode.
	 *
	 * @return string One of Tap_Validator::KIND_CHARGE or KIND_AUTHORIZE.
	 */
	public function get_transaction_mode(): string {
		return 'authorize' === $this->get_option( 'payment_mode' )
			? Tap_Validator::KIND_AUTHORIZE
			: Tap_Validator::KIND_CHARGE;
	}

	/**
	 * The two-letter UI language code for the Tap checkout.
	 *
	 * @return string
	 */
	public function get_language_code(): string {
		return 'arabic' === $this->get_option( 'ui_language' ) ? 'ar' : 'en';
	}

	/**
	 * The URL Tap posts payment notifications to.
	 *
	 * @return string
	 */
	public function get_webhook_url(): string {
		return WC()->api_request_url( 'tap_webhook' );
	}

	/**
	 * Trim whitespace from the publishable keys on save.
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function validate_text_field( $key, $value ) {
		return trim( (string) parent::validate_text_field( $key, $value ) );
	}

	/**
	 * Trim whitespace from the secret keys on save.
	 *
	 * Keys pasted with a trailing space are a common and hard-to-diagnose cause
	 * of authentication failures against the Tap API.
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function validate_password_field( $key, $value ) {
		return trim( (string) parent::validate_password_field( $key, $value ) );
	}

	/**
	 * Warn the merchant if the saved keys do not look like Tap keys.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		try {
			$this->warn_about_suspicious_keys();
		} catch ( Throwable $e ) {
			// The settings are already saved by this point; a failed sanity
			// check must not make the merchant think the save failed.
			Tap_Logger::exception( 'Could not validate the saved Tap API keys.', $e );
		}

		return $saved;
	}

	/**
	 * Warn when a saved key does not look like the key it should be.
	 */
	private function warn_about_suspicious_keys(): void {
		$expectations = array(
			'test_public_key' => 'pk_test_',
			'test_secret_key' => 'sk_test_',
			'live_public_key' => 'pk_live_',
			'live_secret_key' => 'sk_live_',
		);

		foreach ( $expectations as $field => $prefix ) {
			$value = trim( (string) $this->get_option( $field, '' ) );

			if ( '' === $value || str_starts_with( $value, $prefix ) ) {
				continue;
			}

			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: 1: settings field label, 2: expected key prefix. */
					__( 'The value saved in "%1$s" does not start with %2$s. Please double-check the key.', 'wc-tap-gateway' ),
					$this->form_fields[ $field ]['title'] ?? $field,
					$prefix
				)
			);
		}
	}

	/**
	 * Register the front-end assets.
	 *
	 * Registration only. The scripts are enqueued from the receipt renderer,
	 * which runs for Tap orders only, so the third-party SDK is never loaded for
	 * customers who are paying by other means.
	 *
	 * Hooked from Tap_Plugin rather than from this constructor, so it does not
	 * depend on when WooCommerce first instantiates its gateways.
	 */
	public static function register_scripts(): void {
		try {
			if ( ! is_checkout() && ! is_checkout_pay_page() ) {
				return;
			}

			self::ensure_assets_registered();
			wp_enqueue_style( self::STYLE_HANDLE );
		} catch ( Throwable $e ) {
			// Runs on wp_enqueue_scripts, i.e. on every front-end page view.
			Tap_Logger::exception( 'Could not register the Tap front-end assets.', $e );
		}
	}

	/**
	 * Register the front-end assets, if they are not registered already.
	 *
	 * Idempotent, and called from two places on purpose.
	 *
	 * Hook order is not the same in every theme. Under a block theme the
	 * order-pay template — and therefore woocommerce_receipt_tap — is rendered
	 * BEFORE wp_enqueue_scripts fires. wp_localize_script() silently discards
	 * its data when the handle is not yet registered (WP_Scripts::add_data()
	 * returns false), which left the checkout script on the page with no
	 * configuration and no error anywhere. So the renderer calls this itself
	 * rather than assuming registration has already happened.
	 */
	public static function ensure_assets_registered(): void {
		if ( wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		// The CDN path pins the SDK version. 1.5.0-beta is the only 1.5.x build
		// published on this CDN (a plain 1.5.0 path returns 404), so it is kept
		// here deliberately; move to a stable tag once Tap publishes one.
		wp_register_script(
			self::SDK_HANDLE,
			'https://tap-sdks.b-cdn.net/checkout/1.5.0-beta/index.js',
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Version is pinned in the CDN path.
			true
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			TAP_GATEWAY_URL . 'assets/js/tap-checkout.js',
			array( 'jquery', self::SDK_HANDLE ),
			TAP_GATEWAY_VERSION,
			true
		);

		wp_register_style(
			self::STYLE_HANDLE,
			TAP_GATEWAY_URL . 'assets/css/tap-payment.css',
			array(),
			TAP_GATEWAY_VERSION
		);
	}

	/**
	 * Render the checkout description.
	 */
	public function payment_fields(): void {
		try {
			$description = (string) $this->description;

			if ( $this->testmode ) {
				$description .= ' ' . __( 'TEST MODE IS ENABLED. Use the test card numbers from the Tap documentation.', 'wc-tap-gateway' );
			}

			$description = trim( $description );

			if ( '' !== $description ) {
				echo wp_kses_post( wpautop( $description ) );
			}
		} catch ( Throwable $e ) {
			// Rendered inside the checkout form; a throwable would break the
			// whole payment method list, not just this description.
			Tap_Logger::exception( 'Could not render the Tap payment fields.', $e );
		}
	}

	/**
	 * Start a payment.
	 *
	 * @param int $order_id Order id.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ) {
		try {
			$order = wc_get_order( absint( $order_id ) );

			if ( ! $order instanceof WC_Order ) {
				Tap_Logger::error( 'process_payment called for an order that does not exist.', array( 'order' => $order_id ) );
				return $this->failure( __( 'We could not find your order. Please try again.', 'wc-tap-gateway' ) );
			}

			$ui_mode = (string) $this->get_option( 'ui_mode' );

			if ( 'popup' === $ui_mode ) {
				return array(
					'result'   => 'success',
					'redirect' => $order->get_checkout_payment_url( true ),
				);
			}

			if ( 'redirect' === $ui_mode ) {
				return $this->start_redirect_payment( $order );
			}

			// 2.x fell off the end of this method for any other value, returning
			// null and fatally breaking the checkout.
			Tap_Logger::error( 'The Tap gateway has no valid checkout mode configured.', array( 'ui_mode' => $ui_mode ) );

			return $this->failure( __( 'This payment method is not configured correctly. Please choose another one.', 'wc-tap-gateway' ) );
		} catch ( Tap_Exception $e ) {
			Tap_Logger::exception( 'Could not start a Tap payment.', $e, array( 'order' => $order_id ) );
			return $this->failure( $e->get_customer_message() );
		} catch ( Throwable $e ) {
			// WooCommerce reads $result['result'] from whatever this returns, so
			// an escaping throwable would end the checkout with a fatal error
			// rather than a message the customer can act on.
			Tap_Logger::exception( 'Unhandled failure while starting a Tap payment.', $e, array( 'order' => $order_id ) );
			return $this->failure( __( 'We could not start your payment. Please try again or choose another payment method.', 'wc-tap-gateway' ) );
		}
	}

	/**
	 * Create a Tap transaction and hand back its redirect URL.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array<string, string>
	 */
	private function start_redirect_payment( WC_Order $order ): array {
		$request_id = Tap_Logger::generate_request_id();
		Tap_Logger::set_request_id( $request_id );

		$secret_key = $this->get_secret_key();
		$public_key = $this->get_public_key();

		if ( '' === $secret_key || '' === $public_key ) {
			throw new Tap_Configuration_Exception(
				'Cannot start a payment: API keys are not configured.',
				array(
					'order' => $order->get_id(),
					'mode'  => $this->testmode ? 'test' : 'live',
				)
			);
		}

		$currency = $order->get_currency();
		$amount   = Tap_Currency::format( $order->get_total(), $currency );
		$post_url = $this->get_webhook_url();

		$payload = Tap_Request_Builder::transaction(
			$order,
			array(
				'request_id'   => $request_id,
				'post_url'     => $post_url,
				'redirect_url' => $this->get_return_url( $order ),
				'merchant_id'  => (string) $this->get_option( 'merchant_id', '' ),
				'platform_id'  => self::PLATFORM_ID,
				'save_card'    => 'yes' === $this->get_option( 'save_card' ),
				'hash_string'  => Tap_Signature::checkout_hash(
					$public_key,
					$amount,
					$currency,
					(string) $order->get_id(),
					$post_url,
					$secret_key
				),
			)
		);

		$api      = new Tap_Api_Client( $secret_key, $request_id );
		$response = $api->create_transaction( $this->get_transaction_mode(), $payload, $this->get_language_code() );

		$redirect_url   = $response->get_string( 'transaction.url' );
		$transaction_id = $response->get_string( 'id' );

		// 2.x returned 'result' => 'success' with an empty redirect URL here, so
		// a failed API call looked to the customer like a silent page reload.
		if ( ! $response->is_success() || '' === $redirect_url ) {
			Tap_Logger::error(
				'Could not start the payment.',
				array(
					'order' => $order->get_id(),
					'error' => $response->get_error_message(),
				)
			);

			$order->add_order_note(
				sprintf(
					/* translators: %s: error message from Tap. */
					esc_html__( 'Tap could not start the payment: %s', 'wc-tap-gateway' ),
					esc_html( $response->get_error_message() )
				)
			);
			$order->save();

			return $this->failure( __( 'We could not start your payment. Please try again or choose another payment method.', 'wc-tap-gateway' ) );
		}

		if ( '' !== $transaction_id ) {
			$order->update_meta_data( Tap_Order_Processor::META_CHARGE_ID, $transaction_id );
		}
		$order->update_meta_data( Tap_Order_Processor::META_REQUEST_ID, $request_id );
		$order->save();

		Tap_Logger::info(
			'Payment started; redirecting the customer to Tap.',
			array(
				'order'       => $order->get_id(),
				'transaction' => $transaction_id,
			)
		);

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}

	/**
	 * Refund all or part of an order through Tap.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount to refund; null means the full total.
	 * @param string     $reason   Reason supplied by the administrator.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		try {
			return $this->refund( $order_id, $amount, (string) $reason );
		} catch ( Tap_Exception $e ) {
			Tap_Logger::exception( 'Refund failed.', $e, array( 'order' => $order_id ) );
			return new WP_Error( 'tap_refund_failed', $e->get_customer_message() );
		} catch ( Throwable $e ) {
			// Returning WP_Error puts the reason in front of the administrator
			// instead of leaving them with a blank failure in the order screen.
			Tap_Logger::exception( 'Unhandled failure while refunding.', $e, array( 'order' => $order_id ) );
			return new WP_Error(
				'tap_refund_error',
				__( 'The refund could not be completed because of an unexpected error. Check the Tap log for details.', 'wc-tap-gateway' )
			);
		}
	}

	/**
	 * Perform the refund.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount to refund; null means the full total.
	 * @param string     $reason   Reason supplied by the administrator.
	 * @return bool|WP_Error
	 */
	private function refund( $order_id, $amount, string $reason ) {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'tap_refund_no_order', __( 'Order not found.', 'wc-tap-gateway' ) );
		}

		$charge_id = (string) $order->get_meta( Tap_Order_Processor::META_COMPLETED_ID );

		if ( '' === $charge_id ) {
			$charge_id = (string) $order->get_meta( Tap_Order_Processor::META_CHARGE_ID );
		}
		if ( '' === $charge_id ) {
			$charge_id = (string) $order->get_transaction_id();
		}

		if ( ! Tap_Validator::is_valid_transaction_id( $charge_id ) ) {
			return new WP_Error(
				'tap_refund_no_charge',
				__( 'No Tap transaction is recorded against this order, so it cannot be refunded automatically.', 'wc-tap-gateway' )
			);
		}

		if ( Tap_Validator::KIND_AUTHORIZE === Tap_Validator::transaction_kind( $charge_id ) ) {
			return new WP_Error(
				'tap_refund_not_captured',
				__( 'This payment is authorized but not captured. Void or capture it in your Tap dashboard instead.', 'wc-tap-gateway' )
			);
		}

		$secret_key = $this->get_secret_key();

		if ( '' === $secret_key ) {
			return new WP_Error( 'tap_refund_not_configured', __( 'The Tap gateway is not configured with an API key.', 'wc-tap-gateway' ) );
		}

		$amount = ( null === $amount || '' === $amount ) ? $order->get_total() : $amount;

		if ( (float) $amount <= 0 ) {
			return new WP_Error( 'tap_refund_bad_amount', __( 'The refund amount must be greater than zero.', 'wc-tap-gateway' ) );
		}

		$request_id = Tap_Logger::generate_request_id();
		Tap_Logger::set_request_id( $request_id );

		$api      = new Tap_Api_Client( $secret_key, $request_id );
		$response = $api->create_refund(
			Tap_Request_Builder::refund( $order, $charge_id, $amount, (string) $reason, $this->get_webhook_url() )
		);

		if ( ! $response->is_success() ) {
			Tap_Logger::error(
				'Refund rejected by Tap.',
				array(
					'order' => $order->get_id(),
					'error' => $response->get_error_message(),
				)
			);

			return new WP_Error( 'tap_refund_failed', $response->get_error_message() );
		}

		$refund_id = $response->get_string( 'id' );
		$status    = strtoupper( $response->get_string( 'status' ) );

		$order->add_order_note(
			sprintf(
				/* translators: 1: refund amount, 2: Tap refund id, 3: refund status. */
				esc_html__( 'Tap refund of %1$s accepted. Refund ID: %2$s (status: %3$s)', 'wc-tap-gateway' ),
				esc_html( (string) wp_strip_all_tags( wc_price( (float) $amount, array( 'currency' => $order->get_currency() ) ) ) ),
				esc_html( $refund_id ),
				esc_html( '' !== $status ? $status : 'UNKNOWN' )
			)
		);
		$order->save();

		Tap_Logger::info(
			'Refund accepted.',
			array(
				'order'  => $order->get_id(),
				'refund' => $refund_id,
				'status' => $status,
			)
		);

		// Tap settles refunds asynchronously; PENDING and REFUNDED are both
		// successful outcomes at this point. 2.x returned null for anything
		// other than PENDING, which showed the administrator no reason at all.
		return true;
	}

	/**
	 * Queue a checkout error and return WooCommerce's failure response.
	 *
	 * @param string $message Message shown to the customer.
	 * @return array<string, string>
	 */
	private function failure( string $message ): array {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, 'error' );
		}

		return array( 'result' => 'failure' );
	}
}

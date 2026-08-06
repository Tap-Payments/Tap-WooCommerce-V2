<?php
/**
 * Plugin bootstrap and hook wiring.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Owns every add_action/add_filter call in the plugin, so the set of entry
 * points can be read in one place.
 */
final class Tap_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Cached gateway instance.
	 *
	 * @var WC_Tap_Gateway|null
	 */
	private static ?WC_Tap_Gateway $gateway = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Wires every hook.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . TAP_GATEWAY_BASENAME, array( $this, 'add_settings_link' ) );
		add_action( 'wp_enqueue_scripts', array( 'WC_Tap_Gateway', 'register_scripts' ), 11 );

		// Webhook.
		$webhook = new Tap_Webhook_Handler();
		add_action( 'woocommerce_api_tap_webhook', array( $webhook, 'handle' ) );

		// Customer return from Tap.
		$return_handler = new Tap_Return_Handler();
		add_action( 'woocommerce_thankyou_tap', array( $return_handler, 'handle' ) );
		add_filter( 'the_content', array( $return_handler, 'render_failure_notice' ) );

		// Popup checkout on the order-pay page.
		add_action( 'woocommerce_receipt_tap', array( $this, 'render_receipt_page' ) );

		// AJAX.
		$ajax = new Tap_Ajax();
		add_action( 'wp_ajax_' . Tap_Ajax::ACTION, array( $ajax, 'save_charge_id' ) );
		add_action( 'wp_ajax_nopriv_' . Tap_Ajax::ACTION, array( $ajax, 'save_charge_id' ) );

		// Cancellation audit trail and safety net.
		$cancellation = new Tap_Cancellation_Handler();
		add_action( 'woocommerce_order_status_cancelled', array( $cancellation, 'handle' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed', array( $cancellation, 'handle' ), 10, 2 );
		add_action( Tap_Cancellation_Handler::RECHECK_HOOK, array( $cancellation, 'run_recheck' ) );

		// Privacy.
		$privacy = new Tap_Privacy();
		add_filter( 'woocommerce_privacy_export_order_personal_data', array( $privacy, 'export_order_data' ), 10, 2 );
		add_action( 'woocommerce_privacy_before_remove_order_personal_data', array( $privacy, 'erase_order_data' ) );
		add_action( 'admin_init', array( $privacy, 'add_privacy_policy_content' ) );

		// Cart & Checkout Blocks.
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		try {
			load_plugin_textdomain( 'wc-tap-gateway', false, dirname( TAP_GATEWAY_BASENAME ) . '/languages' );
		} catch ( Throwable $e ) {
			// Untranslated strings are a much smaller problem than a fatal on
			// the init hook.
			Tap_Logger::exception( 'Could not load the Tap translations.', $e );
		}
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array<int, mixed> $gateways Registered gateways.
	 * @return array<int, mixed>
	 */
	public function register_gateway( $gateways ): array {
		$gateways   = is_array( $gateways ) ? $gateways : array();
		$gateways[] = 'WC_Tap_Gateway';

		return $gateways;
	}

	/**
	 * Add a settings shortcut to the plugins list.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public function add_settings_link( $links ): array {
		$links = is_array( $links ) ? $links : array();

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=tap' ) ),
				esc_html__( 'Settings', 'wc-tap-gateway' )
			)
		);

		return $links;
	}

	/**
	 * Render the popup checkout on the order-pay page.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function render_receipt_page( $order_id ): void {
		$gateway = self::get_gateway();

		if ( ! $gateway instanceof WC_Tap_Gateway ) {
			return;
		}

		// Tap_Receipt_Renderer::render() has its own guard.
		( new Tap_Receipt_Renderer( $gateway ) )->render( $order_id );
	}

	/**
	 * Register the Cart & Checkout Blocks payment method type.
	 */
	public function register_blocks_support(): void {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ): void {
				try {
					$registry->register( new WC_Tap_Blocks_Support() );
				} catch ( Throwable $e ) {
					// Failing to register must not break the whole block
					// checkout, which would take every other gateway with it.
					Tap_Logger::exception( 'Could not register the Tap block payment method.', $e );
				}
			}
		);
	}

	/**
	 * Get the registered gateway instance.
	 *
	 * Always resolved through WooCommerce's own registry rather than by
	 * constructing a new gateway. 2.x called `new WC_Tap_Gateway()` on every
	 * order cancellation and every Blocks checkout render, and each construction
	 * ran two full get_pages() queries.
	 *
	 * @return WC_Tap_Gateway|null
	 */
	public static function get_gateway(): ?WC_Tap_Gateway {
		if ( self::$gateway instanceof WC_Tap_Gateway ) {
			return self::$gateway;
		}

		try {
			if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
				return null;
			}

			$gateways = WC()->payment_gateways()->payment_gateways();

			if ( isset( $gateways['tap'] ) && $gateways['tap'] instanceof WC_Tap_Gateway ) {
				self::$gateway = $gateways['tap'];
			}

			return self::$gateway;
		} catch ( Throwable $e ) {
			// Every caller already handles a null gateway, so returning null is
			// a safe degradation.
			Tap_Logger::exception( 'Could not resolve the Tap gateway instance.', $e );
			return null;
		}
	}
}

<?php
/**
 * Plugin Name:          WooCommerce Tap Payment Gateway
 * Plugin URI:           https://github.com/Tap-Payments/Tap-WooCommerce-V2
 * Description:          Accept card and wallet payments on your WooCommerce store through Tap. Supports redirect and popup checkout, authorizations, and refunds.
 * Version:              3.0.4
 * Author:               Tap Payments
 * Author URI:           https://tap.company/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          wc-tap-gateway
 * Domain Path:          /languages
 * Requires Plugins:     woocommerce
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * WC requires at least: 8.0
 * WC tested up to:      10.0
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const TAP_GATEWAY_VERSION = '3.0.4';
const TAP_GATEWAY_MIN_WC  = '8.0';

define( 'TAP_GATEWAY_FILE', __FILE__ );
define( 'TAP_GATEWAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAP_GATEWAY_URL', plugin_dir_url( __FILE__ ) );
define( 'TAP_GATEWAY_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Map of class name => path relative to includes/.
 *
 * An explicit map is used rather than a PSR-4 autoloader so that the plugin has
 * no runtime dependency on Composer, while still keeping every class in its own
 * file. Composer is used for development tooling only (see composer.json).
 *
 * @var array<string, string>
 */
const TAP_GATEWAY_CLASS_MAP = array(
	'Tap_Plugin'               => 'class-tap-plugin.php',
	'Tap_Exception'            => 'class-tap-exceptions.php',
	'Tap_Configuration_Exception' => 'class-tap-exceptions.php',
	'Tap_Validation_Exception' => 'class-tap-exceptions.php',
	'Tap_Api_Exception'        => 'class-tap-exceptions.php',
	'Tap_Order_Exception'      => 'class-tap-exceptions.php',
	'Tap_Logger'               => 'class-tap-logger.php',
	'Tap_Currency'             => 'class-tap-currency.php',
	'Tap_Countries'            => 'class-tap-countries.php',
	'Tap_Validator'            => 'class-tap-validator.php',
	'Tap_Signature'            => 'class-tap-signature.php',
	'Tap_Settings'             => 'class-tap-settings.php',
	'Tap_Response'             => 'api/class-tap-response.php',
	'Tap_Api_Client'           => 'api/class-tap-api-client.php',
	'Tap_Request_Builder'      => 'api/class-tap-request-builder.php',
	'Tap_Order_Processor'      => 'class-tap-order-processor.php',
	'Tap_Webhook_Handler'      => 'class-tap-webhook-handler.php',
	'Tap_Return_Handler'       => 'class-tap-return-handler.php',
	'Tap_Receipt_Renderer'     => 'class-tap-receipt-renderer.php',
	'Tap_Ajax'                 => 'class-tap-ajax.php',
	'Tap_Cancellation_Handler' => 'class-tap-cancellation-handler.php',
	'Tap_Privacy'              => 'class-tap-privacy.php',
	'WC_Tap_Gateway'           => 'class-wc-tap-gateway.php',
	'WC_Tap_Blocks_Support'    => 'blocks/class-wc-tap-blocks-support.php',
);

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! isset( TAP_GATEWAY_CLASS_MAP[ $class_name ] ) ) {
			return;
		}
		require_once TAP_GATEWAY_PATH . 'includes/' . TAP_GATEWAY_CLASS_MAP[ $class_name ];
	}
);

/**
 * Declare compatibility with WooCommerce opt-in features.
 *
 * Must run on before_woocommerce_init. Without the custom_order_tables
 * declaration WooCommerce treats the plugin as HPOS-incompatible and refuses to
 * enable High-Performance Order Storage.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TAP_GATEWAY_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TAP_GATEWAY_FILE, true );
	}
);

/**
 * Boot the plugin once all plugins are loaded.
 *
 * The gateway class extends WC_Payment_Gateway, so it can only be declared once
 * WooCommerce is present. Without this guard, activating the plugin without
 * WooCommerce produces a fatal error that also locks the user out of wp-admin.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', 'tap_gateway_missing_wc_notice' );
			return;
		}

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, TAP_GATEWAY_MIN_WC, '<' ) ) {
			add_action( 'admin_notices', 'tap_gateway_outdated_wc_notice' );
			return;
		}

		try {
			Tap_Plugin::instance();
		} catch ( Throwable $e ) {
			// A throwable during boot would be a fatal on every request,
			// including wp-admin, locking the merchant out of their own site.
			if ( class_exists( 'Tap_Logger' ) ) {
				Tap_Logger::exception( 'Tap gateway failed to initialise.', $e );
			}

			$GLOBALS['tap_gateway_boot_error'] = $e->getMessage();
			add_action( 'admin_notices', 'tap_gateway_boot_failure_notice' );
		}
	}
);

/**
 * Admin notice shown when the plugin could not start.
 */
function tap_gateway_boot_failure_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'Tap Payment Gateway could not start.', 'wc-tap-gateway' ),
		esc_html( (string) ( $GLOBALS['tap_gateway_boot_error'] ?? '' ) )
	);
}

/**
 * Admin notice shown when WooCommerce is not active.
 */
function tap_gateway_missing_wc_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Tap Payment Gateway requires WooCommerce to be installed and active.', 'wc-tap-gateway' )
	);
}

/**
 * Admin notice shown when the installed WooCommerce is too old.
 */
function tap_gateway_outdated_wc_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum supported WooCommerce version. */
				__( 'Tap Payment Gateway requires WooCommerce %s or newer.', 'wc-tap-gateway' ),
				TAP_GATEWAY_MIN_WC
			)
		)
	);
}

/**
 * One-time cleanup of data written by older versions of the plugin.
 *
 * Version 2.x wrote the raw $_GET superglobal into an autoloaded option named
 * "webhook_debug" on every successful webhook. That option is removed here.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		try {
			delete_option( 'webhook_debug' );

			$settings = get_option( 'woocommerce_tap_settings' );
			if ( ! is_array( $settings ) ) {
				return;
			}
			$changed = false;

			// 2.x shipped no default for ui_mode, which made process_payment()
			// return null and fatally break the checkout.
			if ( empty( $settings['ui_mode'] ) ) {
				$settings['ui_mode'] = 'redirect';
				$changed             = true;
			}
			if ( empty( $settings['payment_mode'] ) ) {
				$settings['payment_mode'] = 'charge';
				$changed                  = true;
			}
			// The post_url setting was never read by any code path.
			if ( array_key_exists( 'post_url', $settings ) ) {
				unset( $settings['post_url'] );
				$changed = true;
			}
			// API keys pasted with surrounding whitespace are a common cause of
			// unexplained 401s from the Tap API.
			foreach ( array( 'test_secret_key', 'test_public_key', 'live_secret_key', 'live_public_key', 'merchant_id' ) as $key ) {
				if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && trim( $settings[ $key ] ) !== $settings[ $key ] ) {
					$settings[ $key ] = trim( $settings[ $key ] );
					$changed          = true;
				}
			}

			if ( $changed ) {
				update_option( 'woocommerce_tap_settings', $settings );
			}
		} catch ( Throwable $e ) {
			// Activation must never fail: WordPress would report the plugin as
			// broken and refuse to activate it. This migration is best-effort.
			if ( class_exists( 'Tap_Logger' ) ) {
				Tap_Logger::exception( 'Tap activation migration failed.', $e );
			}
		}
	}
);

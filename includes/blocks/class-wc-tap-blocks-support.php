<?php
/**
 * Cart & Checkout Blocks integration.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Tap payment method with the block-based checkout.
 */
final class WC_Tap_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method id, matching the gateway.
	 *
	 * @var string
	 */
	protected $name = 'tap';

	/**
	 * Load the gateway settings.
	 */
	public function initialize(): void {
		$this->settings = (array) get_option( 'woocommerce_tap_settings', array() );
	}

	/**
	 * Whether the payment method should be offered.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		$gateway = Tap_Plugin::get_gateway();

		if ( $gateway instanceof WC_Tap_Gateway ) {
			return $gateway->is_available();
		}

		return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Register and return the script handles for the block checkout.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'wc-tap-blocks';

		wp_register_script(
			$handle,
			TAP_GATEWAY_URL . 'assets/js/blocks/tap-blocks.js',
			array( 'wp-element', 'wp-html-entities', 'wp-i18n', 'wc-blocks-registry', 'wc-settings' ),
			TAP_GATEWAY_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'wc-tap-gateway', TAP_GATEWAY_PATH . 'languages' );
		}

		return array( $handle );
	}

	/**
	 * Data exposed to the block checkout script.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		$gateway = Tap_Plugin::get_gateway();

		return array(
			'title'       => $gateway instanceof WC_Tap_Gateway ? $gateway->get_title() : (string) $this->get_setting( 'title' ),
			'description' => $gateway instanceof WC_Tap_Gateway ? $gateway->get_description() : (string) $this->get_setting( 'description' ),
			// Passed from PHP so the logo resolves whatever directory the plugin
			// is installed in. 2.x derived it by string-mangling WooCommerce's
			// asset URL and appending the GitHub zip's folder name, so the icon
			// 404'd under any other directory name.
			'icon'        => TAP_GATEWAY_URL . 'assets/img/logo.png',
			'supports'    => $this->get_supported_features(),
		);
	}

	/**
	 * Features supported by the gateway.
	 *
	 * @return string[]
	 */
	public function get_supported_features(): array {
		$gateway = Tap_Plugin::get_gateway();

		$features = $gateway instanceof WC_Tap_Gateway
			? array_filter( $gateway->supports, array( $gateway, 'supports' ) )
			: array( 'products' );

		return array_values( $features );
	}
}

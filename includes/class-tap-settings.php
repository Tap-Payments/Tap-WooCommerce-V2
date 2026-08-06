<?php
/**
 * Gateway settings definitions.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Builds the WooCommerce settings form for the gateway.
 *
 * The page dropdowns are populated only while the gateway's own settings screen
 * is being rendered. In 2.x they were built in the constructor, so get_pages()
 * ran twice on essentially every request on the site.
 */
final class Tap_Settings {

	/**
	 * Cached page list.
	 *
	 * @var array<int|string, string>|null
	 */
	private static ?array $page_options = null;

	/**
	 * Build the form fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_form_fields(): array {
		return array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'wc-tap-gateway' ),
				'label'   => __( 'Enable Tap Gateway', 'wc-tap-gateway' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'wc-tap-gateway' ),
				'type'        => 'text',
				'description' => __( 'The payment method name shown to customers during checkout.', 'wc-tap-gateway' ),
				'default'     => __( 'Credit Card', 'wc-tap-gateway' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'wc-tap-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'The description shown to customers under the payment method name.', 'wc-tap-gateway' ),
				'default'     => __( 'Pay securely with your card via Tap.', 'wc-tap-gateway' ),
			),
			'testmode'        => array(
				'title'       => __( 'Test mode', 'wc-tap-gateway' ),
				'label'       => __( 'Enable test mode', 'wc-tap-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Use your Tap test API keys instead of your live keys.', 'wc-tap-gateway' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'test_public_key' => array(
				'title'       => __( 'Test publishable key', 'wc-tap-gateway' ),
				'type'        => 'text',
				'description' => __( 'Your Tap test key beginning pk_test_.', 'wc-tap-gateway' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'test_secret_key' => array(
				// Secret keys use the password field type so they are not
				// rendered in cleartext in the admin DOM.
				'title'       => __( 'Test secret key', 'wc-tap-gateway' ),
				'type'        => 'password',
				'description' => __( 'Your Tap test key beginning sk_test_.', 'wc-tap-gateway' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'live_public_key' => array(
				'title'       => __( 'Live publishable key', 'wc-tap-gateway' ),
				'type'        => 'text',
				'description' => __( 'Your Tap live key beginning pk_live_.', 'wc-tap-gateway' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'live_secret_key' => array(
				'title'       => __( 'Live secret key', 'wc-tap-gateway' ),
				'type'        => 'password',
				'description' => __( 'Your Tap live key beginning sk_live_.', 'wc-tap-gateway' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'merchant_id'     => array(
				'title'       => __( 'Merchant ID', 'wc-tap-gateway' ),
				'type'        => 'text',
				'description' => __( 'Your Tap merchant identifier.', 'wc-tap-gateway' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'payment_mode'    => array(
				'title'       => __( 'Payment mode', 'wc-tap-gateway' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Charge captures funds immediately. Authorize places a hold that you capture later; authorized orders are placed on hold.', 'wc-tap-gateway' ),
				'desc_tip'    => true,
				'default'     => 'charge',
				'options'     => array(
					'charge'    => __( 'Charge', 'wc-tap-gateway' ),
					'authorize' => __( 'Authorize', 'wc-tap-gateway' ),
				),
			),
			'ui_mode'         => array(
				'title'       => __( 'Checkout mode', 'wc-tap-gateway' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Redirect sends the customer to a Tap-hosted page. Popup opens the Tap checkout over your store.', 'wc-tap-gateway' ),
				'desc_tip'    => true,
				'default'     => 'redirect',
				'options'     => array(
					'redirect' => __( 'Redirect', 'wc-tap-gateway' ),
					'popup'    => __( 'Popup', 'wc-tap-gateway' ),
				),
			),
			'ui_language'     => array(
				'title'    => __( 'Checkout language', 'wc-tap-gateway' ),
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'default'  => 'english',
				'desc_tip' => true,
				'options'  => array(
					'english' => __( 'English', 'wc-tap-gateway' ),
					'arabic'  => __( 'Arabic', 'wc-tap-gateway' ),
				),
			),
			'theme_mode'      => array(
				'title'    => __( 'Popup theme', 'wc-tap-gateway' ),
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'default'  => 'light',
				'desc_tip' => true,
				'options'  => array(
					'light' => __( 'Light', 'wc-tap-gateway' ),
					'dark'  => __( 'Dark', 'wc-tap-gateway' ),
				),
			),
			'save_card'       => array(
				'title'       => __( 'Save cards', 'wc-tap-gateway' ),
				'label'       => __( 'Allow customers to save their card for future purchases', 'wc-tap-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Applies to both the redirect and popup checkout.', 'wc-tap-gateway' ),
				'desc_tip'    => true,
				'default'     => 'no',
			),
			'success_page_id' => array(
				'title'       => __( 'Success page', 'wc-tap-gateway' ),
				'type'        => 'select',
				'options'     => self::get_page_options(),
				'description' => __( 'Where to send customers after a successful payment. Leave unset to use the standard WooCommerce order-received page.', 'wc-tap-gateway' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'failer_page_id'  => array(
				// The option key is retained from 2.x so existing configuration
				// is not lost on upgrade, despite the typo.
				'title'       => __( 'Failure page', 'wc-tap-gateway' ),
				'type'        => 'select',
				'options'     => self::get_page_options(),
				'description' => __( 'Where to send customers after a failed payment. Leave unset to return them to the checkout.', 'wc-tap-gateway' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'debug_log'       => array(
				'title'       => __( 'Debug log', 'wc-tap-gateway' ),
				'label'       => __( 'Log gateway activity', 'wc-tap-gateway' ),
				'type'        => 'checkbox',
				'description' => sprintf(
					/* translators: %s: path to the WooCommerce log viewer. */
					__( 'Writes detailed diagnostics to %s. API keys and card numbers are redacted. Errors are always logged regardless of this setting.', 'wc-tap-gateway' ),
					'<code>WooCommerce &gt; Status &gt; Logs</code>'
				),
				'default'     => 'no',
			),
		);
	}

	/**
	 * Page dropdown options, loaded only on the gateway's settings screen.
	 *
	 * @return array<int|string, string>
	 */
	private static function get_page_options(): array {
		if ( ! self::is_gateway_settings_screen() ) {
			return array();
		}

		if ( null !== self::$page_options ) {
			return self::$page_options;
		}

		$options = array( '' => __( 'Select a page…', 'wc-tap-gateway' ) );
		$pages   = (array) get_pages( array( 'sort_column' => 'menu_order' ) );

		foreach ( $pages as $page ) {
			if ( ! $page instanceof WP_Post ) {
				continue;
			}

			$prefix     = '';
			$parent_id  = (int) $page->post_parent;
			$safety_net = 0;

			while ( $parent_id > 0 && $safety_net < 10 ) {
				$prefix .= ' - ';
				$parent  = get_post( $parent_id );
				if ( ! $parent instanceof WP_Post ) {
					break;
				}
				$parent_id = (int) $parent->post_parent;
				++$safety_net;
			}

			$options[ $page->ID ] = $prefix . $page->post_title;
		}

		self::$page_options = $options;

		return $options;
	}

	/**
	 * Whether the current request is rendering or saving the gateway's settings.
	 *
	 * @return bool
	 */
	private static function is_gateway_settings_screen(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Screen detection only; WooCommerce verifies the nonce when saving.
		$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page
			&& 'checkout' === $tab
			&& in_array( strtolower( $section ), array( 'tap', 'wc_tap_gateway' ), true );
	}
}

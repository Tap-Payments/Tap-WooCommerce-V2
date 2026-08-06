/**
 * Registers Tap as a payment method on the WooCommerce Cart & Checkout Blocks.
 *
 * Written as plain ES5 against the wp.* and wc.* globals so it runs as shipped,
 * with no build step. The 2.x file was a checked-in webpack bundle with no
 * corresponding source, which made it unmaintainable.
 */
( function ( wp, wc ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings ) {
		return;
	}

	var createElement = wp.element.createElement;
	var decodeEntities = wp.htmlEntities.decodeEntities;
	var __ = wp.i18n.__;

	var settings = wc.wcSettings.getSetting( 'tap_data', {} );

	var title = decodeEntities( settings.title || __( 'Tap', 'wc-tap-gateway' ) );
	var description = decodeEntities( settings.description || '' );

	/**
	 * The label shown next to the payment method radio button.
	 *
	 * @return {Object} React element.
	 */
	function Label() {
		var children = [
			createElement( 'span', { key: 'title', className: 'wc-block-components-payment-method-label' }, title )
		];

		// The icon URL is supplied by PHP, so it is correct whatever directory
		// the plugin is installed in.
		if ( settings.icon ) {
			children.unshift(
				createElement( 'img', {
					key: 'icon',
					src: settings.icon,
					alt: title,
					className: 'wc-block-components-payment-method-icon',
					style: { maxHeight: '24px', marginInlineEnd: '8px' }
				} )
			);
		}

		return createElement(
			'span',
			{ style: { display: 'flex', alignItems: 'center' } },
			children
		);
	}

	/**
	 * The content shown when the payment method is selected.
	 *
	 * @return {Object} React element.
	 */
	function Content() {
		return createElement( 'div', null, description );
	}

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'tap',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		ariaLabel: title,
		placeOrderButtonLabel: __( 'Proceed to Tap', 'wc-tap-gateway' ),
		canMakePayment: function () {
			// PHP already decides availability in WC_Tap_Blocks_Support::is_active(),
			// which checks that API keys are configured.
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ]
		}
	} );
}( window.wp, window.wc ) );

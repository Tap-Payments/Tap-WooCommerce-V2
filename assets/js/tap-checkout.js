/**
 * Tap popup checkout, rendered on the WooCommerce order-pay page.
 *
 * All configuration arrives in window.tapCheckoutData via wp_localize_script.
 * Nothing is read out of hidden inputs, and nothing is logged to the console:
 * the payload contains the customer's contact details and the payment hash.
 */
( function ( $ ) {
	'use strict';

	var config = window.tapCheckoutData;

	// The script is registered on every checkout page but only configured on the
	// order-pay page for Tap orders.
	if ( ! config || ! config.publicKey ) {
		return;
	}

	var SDK_TIMEOUT = ( config.timeouts && config.timeouts.sdk ) || 20000;
	var RENDER_TIMEOUT = ( config.timeouts && config.timeouts.render ) || 60000;
	var POLL_INTERVAL = 200;

	var started = false;
	var rendered = false;
	var failedOver = false;
	var completing = false;
	var renderWatchdog = null;
	var unmount = null;

	/**
	 * Log only when the gateway's "Debug log" setting is on.
	 *
	 * The checkout configuration contains the customer's contact details and
	 * the payment hash, so nothing is logged in normal operation.
	 */
	function debug() {
		if ( config.debug && window.console && window.console.log ) {
			window.console.log.apply( window.console, [ '[tap]' ].concat( Array.prototype.slice.call( arguments ) ) );
		}
	}

	/**
	 * Append a query parameter to a URL that may already carry one.
	 *
	 * @param {string} url   Base URL.
	 * @param {string} key   Parameter name.
	 * @param {string} value Parameter value.
	 * @return {string} URL with the parameter appended.
	 */
	function appendParam( url, key, value ) {
		var separator = url.indexOf( '?' ) === -1 ? '?' : '&';

		return url + separator + encodeURIComponent( key ) + '=' + encodeURIComponent( value );
	}

	/**
	 * Show the loading overlay.
	 *
	 * @param {string} [text] Optional replacement message.
	 */
	function showLoading( text ) {
		var overlay = document.getElementById( 'tap_loading_overlay' );

		if ( ! overlay ) {
			return;
		}

		if ( text ) {
			var textEl = overlay.querySelector( '.tap-loading-text' );
			if ( textEl ) {
				textEl.textContent = text;
			}
		}

		overlay.style.display = 'flex';
	}

	/**
	 * Hide the loading overlay.
	 */
	function hideLoading() {
		var overlay = document.getElementById( 'tap_loading_overlay' );

		if ( overlay ) {
			overlay.style.display = 'none';
			overlay.setAttribute( 'aria-busy', 'false' );
		}
	}

	/**
	 * Clear the render watchdog, if one is pending.
	 */
	function clearWatchdog() {
		if ( renderWatchdog ) {
			window.clearTimeout( renderWatchdog );
			renderWatchdog = null;
		}
	}

	/**
	 * Record the transaction id against the order, then run a callback.
	 *
	 * Deliberately asynchronous. A synchronous XHR would freeze the browser at
	 * the most sensitive moment of the checkout, and is on the browsers'
	 * removal path.
	 *
	 * @param {string}   chargeId Tap transaction id.
	 * @param {Function} done     Called once the request settles, either way.
	 */
	function saveChargeId( chargeId, done ) {
		if ( ! chargeId || ! config.ajaxUrl ) {
			done();
			return;
		}

		$.ajax( {
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'tap_save_charge_id',
				order_id: config.orderId,
				order_key: config.orderKey,
				charge_id: chargeId,
				nonce: config.nonce
			}
		} ).always( function () {
			// The redirect happens regardless: the server verifies the payment
			// against the Tap API on return, so this call is a convenience, not
			// a prerequisite.
			done();
		} );
	}

	var ID_PATTERN = /^(chg|auth)_[A-Za-z0-9]{1,64}$/;

	/**
	 * Send a checkout error to the server so it reaches the WooCommerce log.
	 *
	 * Best effort: failures here are ignored, since the customer is already
	 * being redirected into the failure flow.
	 *
	 * @param {*} error Error reported by the SDK.
	 */
	function reportError( error ) {
		if ( ! config.ajaxUrl ) {
			return;
		}

		var description;
		try {
			description = typeof error === 'string' ? error : JSON.stringify( error );
		} catch ( e ) {
			description = String( error );
		}

		$.ajax( {
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'tap_save_charge_id',
				order_id: config.orderId,
				order_key: config.orderKey,
				nonce: config.nonce,
				client_error: ( description || 'unknown error' ).slice( 0, 500 )
			}
		} );
	}

	/**
	 * Extract a transaction id from whatever shape the SDK hands back.
	 *
	 * The SDK does not document a stable success payload, and it has been seen
	 * to nest the id (for example under `data`). Rather than guess at a fixed
	 * path, well-known keys are tried first and then the structure is searched.
	 * Anything returned is still validated server-side against the Tap API, so
	 * a wrong guess cannot settle an order.
	 *
	 * @param {Object|string} value SDK success payload.
	 * @param {number}        depth Current recursion depth.
	 * @return {string} Transaction id, or an empty string.
	 */
	function extractChargeId( value, depth ) {
		depth = depth || 0;

		if ( ! value || depth > 6 ) {
			return '';
		}

		if ( typeof value === 'string' ) {
			if ( ID_PATTERN.test( value ) ) {
				return value;
			}
			// The SDK sometimes hands back a JSON string.
			try {
				return extractChargeId( JSON.parse( value ), depth + 1 );
			} catch ( e ) {
				return '';
			}
		}

		if ( typeof value !== 'object' ) {
			return '';
		}

		var preferred = [ 'chargeId', 'charge_id', 'transactionId', 'id' ];
		for ( var i = 0; i < preferred.length; i++ ) {
			var candidate = value[ preferred[ i ] ];
			if ( typeof candidate === 'string' && ID_PATTERN.test( candidate ) ) {
				return candidate;
			}
		}

		for ( var key in value ) {
			if ( ! Object.prototype.hasOwnProperty.call( value, key ) ) {
				continue;
			}
			var found = extractChargeId( value[ key ], depth + 1 );
			if ( found ) {
				return found;
			}
		}

		return '';
	}

	/**
	 * Abandon the popup and let the server settle the order.
	 *
	 * Returning with an empty tap_id tells the server the payment never
	 * started, so it fails the order and shows the failure page.
	 */
	function failover() {
		// Never fail over once the payment has succeeded: that would send an
		// empty tap_id and fail an order the customer has already paid for.
		if ( failedOver || completing ) {
			return;
		}

		failedOver = true;
		clearWatchdog();
		showLoading( config.i18n && config.i18n.failed );
		window.location = appendParam( config.returnUrl, 'tap_id', '' );
	}

	/**
	 * Poll for the Tap SDK, then hand off to the callbacks.
	 *
	 * @param {Function} onLoaded  Called once the SDK is usable.
	 * @param {Function} onTimeout Called if it never becomes usable.
	 */
	function waitForSdk( onLoaded, onTimeout ) {
		var start = Date.now();

		( function poll() {
			if ( window.TapSDKs && typeof window.TapSDKs.renderCheckoutElement === 'function' ) {
				onLoaded();
				return;
			}

			if ( Date.now() - start >= SDK_TIMEOUT ) {
				onTimeout();
				return;
			}

			window.setTimeout( poll, POLL_INTERVAL );
		}() );
	}

	/**
	 * Build the configuration object for the Tap SDK.
	 *
	 * @return {Object} SDK configuration.
	 */
	function buildSdkConfig() {
		var transaction = {
			saveCard: !! config.saveCard,
			threeDSecure: true,
			description: '',
			statement_descriptor: '',
			reference: {
				// Must match the reference the server signed into hashString.
				transaction: String( config.reference ),
				order: String( config.orderId )
			},
			redirect: {
				url: config.returnUrl
			},
			// Deliberately asymmetric with `redirect` above: the SDK forwards
			// this object to Tap's charge API untouched, and it expects `post`
			// as a bare URL string here. Sending { url: … } — which is the
			// shape the REST API documents — makes Tap reject the request with
			// "Request values are empty" and registers no webhook, so the order
			// is never settled. This matches the shape used by 2.x.
			post: config.postUrl,
			metadata: {
				orderId: String( config.orderId )
			},
			platform: {
				id: config.platformId
			}
		};

		var order = {
			amount: config.amount,
			currency: config.currency,
			items: config.items || []
		};

		if ( config.shipping && config.shipping.amount > 0 ) {
			order.shipping = config.shipping;
		}

		var sdkConfig = {
			open: true,
			checkoutMode: 'popup',
			language: config.language,
			themeMode: config.themeMode,
			supportedCurrencies: 'ALL',
			supportedRegions: [],
			supportedPaymentTypes: [],
			supportedPaymentMethods: 'ALL',
			supportedSchemes: [],
			selectedCurrency: config.currency,
			paymentType: 'ALL',
			cardOptions: {
				showBrands: true,
				showLoadingState: true,
				collectHolderName: true,
				cardNameEditable: true,
				cardFundingSource: 'all',
				saveCardOption: config.saveCard ? 'all' : 'none',
				forceLtr: true
			},
			gateway: {
				merchantId: config.merchantId,
				publicKey: config.publicKey
			},
			hashString: config.hashString,
			customer: {
				firstName: config.customer.firstName,
				lastName: config.customer.lastName,
				email: config.customer.email,
				phone: {
					countryCode: config.customer.countryCode ? '+' + config.customer.countryCode : '',
					number: config.customer.phone
				}
			},
			amount: config.amount,
			order: order,
			transaction: {
				mode: config.mode
			},
			onReady: function () {
				rendered = true;
				clearWatchdog();
				hideLoading();
			},
			onClose: function () {
				if ( unmount ) {
					unmount();
					unmount = null;
				}
				clearWatchdog();

				// The SDK closes its own popup after a successful payment, so
				// onClose fires immediately after onSuccess. Hiding the overlay
				// here would wipe out the "finishing payment" spinner and leave
				// the customer looking at a dead page until the redirect lands.
				if ( completing ) {
					return;
				}

				// Genuine dismissal: let "Pay now" reopen the popup.
				started = false;
				rendered = false;
				hideLoading();
			},
			onSuccess: function ( result ) {
				completing = true;

				var chargeId = extractChargeId( result );

				debug( 'onSuccess payload:', result, 'extracted id:', chargeId );

				// Payment succeeded. Keep the customer informed while the id is
				// recorded and the redirect is prepared.
				showLoading( config.i18n && config.i18n.completing );

				var target = chargeId
					? appendParam( config.returnUrl, 'tap_id', chargeId )
					// The id could not be read. Never send an empty tap_id on
					// its own: the server reads that as "customer came back
					// without paying" and fails the order. tap_status tells it
					// the payment actually went through, so it waits for the
					// webhook instead of failing a paid order.
					: appendParam( config.returnUrl, 'tap_status', 'success' );

				if ( ! chargeId ) {
					debug( 'could not extract a transaction id; deferring to the webhook' );
				}

				saveChargeId( chargeId, function () {
					window.location = target;
				} );
			},
			onError: function ( error ) {
				debug( 'onError:', error );

				// Report it server-side so the failure is visible in
				// WooCommerce > Status > Logs without the customer having had
				// devtools open. This is the only channel through which an SDK
				// error is otherwise observable at all.
				reportError( error );

				failover();
			}
		};

		sdkConfig.transaction[ config.mode ] = transaction;

		return sdkConfig;
	}

	/**
	 * Mount the Tap checkout element.
	 */
	function render() {
		var element;

		try {
			element = window.TapSDKs.renderCheckoutElement( 'tap_root', buildSdkConfig() );
			unmount = element && element.unmount;
			hideLoading();
		} catch ( e ) {
			failover();
			return;
		}

		// Fail over only if nothing actually appeared. onReady clears this.
		renderWatchdog = window.setTimeout( function () {
			if ( rendered ) {
				return;
			}

			var container = document.getElementById( 'tap_root' );
			var hasContent = container && ( container.children.length > 0 || ( container.innerHTML || '' ).trim().length > 0 );

			if ( ! hasContent ) {
				failover();
			}
		}, RENDER_TIMEOUT );
	}

	/**
	 * Open the Tap checkout.
	 */
	function start() {
		if ( started ) {
			return;
		}

		started = true;

		if ( ! ( window.TapSDKs && typeof window.TapSDKs.renderCheckoutElement === 'function' ) ) {
			showLoading( config.i18n && config.i18n.loading );
		}

		waitForSdk( render, failover );
	}

	$( function () {
		$( '#tap_open_checkout' ).on( 'click', function ( event ) {
			event.preventDefault();
			start();
		} );

		start();
	} );
}( jQuery ) );

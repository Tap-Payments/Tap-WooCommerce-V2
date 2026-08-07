jQuery(document).ready(function(){
    var myEl = jQuery('#submit_tap_payment_form');
jQuery('#submit_tap_payment_form').on('click', function() {
    startCheckout();
});
    var currency = jQuery("#currency").val();
    var testmode = jQuery("#testmode").val();
    if (testmode == true) {
        var active_pk = jQuery("#test_public_key").val();
    }else{
        var active_pk = jQuery("#publishable_key").val();
    }

    var items = jQuery("#itm").val();

    items = JSON.parse(items);
    
  
    var shipping = jQuery("#shippingItems").val();
    
    shipping = JSON.parse(shipping);
    var amount = parseFloat(jQuery("#amount").val());
    var fixed_amount = '';
    if (currency === 'KWD' || currency === 'BHD' || currency === 'OMR' || currency === 'JOD') {
        fixed_amount= amount.toFixed(3);
    }
    else {
        fixed_amount = amount.toFixed(2);
    }
    console.log(fixed_amount);
    var merchant_id = jQuery("#merchant_id").val();
    var hash = jQuery("#hashstring").val();
    var order_id =  jQuery("#order_id").val();

    console.log(order_id+'order_id');
    var post_url = jQuery("#post_url").val();
    var redirect_url = jQuery("#tap_end_url").val();
    var tap_ajax_url = (window.tap_ajax_object && tap_ajax_object.ajax_url) || jQuery("#tap_ajax_url").val();
    var tap_ajax_nonce = (window.tap_ajax_object && tap_ajax_object.nonce) || jQuery("#tap_ajax_nonce").val();
    console.log(redirect_url);

    // Persist the Tap charge id on the WooCommerce order as soon as a charge is
    // initiated (returned by the SDK), so the order always keeps the charge id.
    function saveChargeId(chargeId) {
        if (!chargeId) {
            console.warn('tap saveChargeId: no chargeId, skipping', chargeId);
            return null;
        }
        if (!tap_ajax_url) {
            console.warn('tap saveChargeId: missing #tap_ajax_url on page');
            return null;
        }
        var redirect = null;
        try {
            jQuery.ajax({
                url: tap_ajax_url,
                method: "POST",
                async: false,
                dataType: "json",
                data: {
                    action: "tap_save_charge_id",
                    order_id: order_id,
                    charge_id: chargeId,
                    nonce: tap_ajax_nonce
                },
                success: function (resp) {
                    console.log('tap saveChargeId response:', resp);
                    if (resp && resp.success && resp.data && resp.data.redirect) {
                        redirect = resp.data.redirect;
                    }
                },
                error: function (xhr, status, err) {
                    console.error('tap saveChargeId ajax error:', status, err, xhr && xhr.responseText);
                }
            });
        } catch (e) {
            console.log(e);
        }
        return redirect;
    }
        
    var Ui_language = jQuery("#ui_language").val();
    if( Ui_language == 'english'){
        Ui_language_val = 'en';
    }else{
        Ui_language_val = 'ar';
    }
    var payment_mode = jQuery("#chg").val();
    console.log(payment_mode);
    var save_card = jQuery('#save_card').val();
    var transaction_type = '';
    if (payment_mode == 'charge') {
        transaction_type = 'charge';
    }
    else {
        transaction_type = 'authorize';
    }


    if( save_card == 'no') {
        save_card_val = false;
    }else {
        save_card_val = true;
    }

    
    var billing_first_name = jQuery("#billing_first_name").val();
    var customer_user_id = jQuery("#customer_user_id").val();
    var billing_last_name = jQuery("#billing_last_name").val();
    var billing_email = jQuery("#billing_email").val();
    var billing_phone = jQuery("#billing_phone").val();
    var country_code = jQuery("#countrycode").val();

    country_code = '+'+country_code;
    const charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    const timestamp = Date.now().toString(15); // base36 for compactness

    for (let i = 0; i < length; i++) {
        const randomIndex = Math.floor(Math.random() * charset.length);
        result += charset[randomIndex];
    }
    const prefix = 'woo_';
    const hexLength = 28;
    let hexPart = '';




  
    // Generate a 28-character hex string (14 bytes)
    const array = new Uint8Array(14); // 14 bytes * 2 hex chars = 28 chars
    window.crypto.getRandomValues(array);
  
    hexPart = Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
    var request_id = prefix + hexPart;
        let unmount = null;
        let tapRendered = false;
        let tapRenderWatchdog = null;
        let tapStarted = false;
        let tapFailedOver = false;

        // Show/hide the loading spinner overlay on the receipt page. It is shown
        // while the Tap SDK is still loading, and hidden once the popup renders
        // (the SDK shows its own loader from that point on).
        function showTapLoading(text) {
            var overlay = document.getElementById('tap_loading_overlay');
            if (!overlay) { return; }
            var textEl = overlay.querySelector('.tap-loading-text');
            if (textEl && text) { textEl.textContent = text; }
            overlay.style.display = 'flex';
        }
        function hideTapLoading() {
            var overlay = document.getElementById('tap_loading_overlay');
            if (overlay) { overlay.style.display = 'none'; }
        }

        // Redirect the customer into the failure flow when the Tap SDK cannot be
        // loaded or the checkout UI never renders. An empty tap_id makes the
        // server cancel the pending order; tap_failover_msg is shown on the
        // failure page (defaults to "Something went wrong").
        function tapFailover(reason, customerMessage) {
            if (tapFailedOver) { return; }
            tapFailedOver = true;
            console.error('Tap checkout failover:', reason);
            if (tapRenderWatchdog) { clearTimeout(tapRenderWatchdog); tapRenderWatchdog = null; }
            showTapLoading('Loading…');
            var msg = encodeURIComponent(customerMessage || 'Something went wrong');
            window.location = `${redirect_url}&tap_id=&tap_failover_msg=${msg}`;
        }

        // Poll for the Tap SDK for up to 20s before giving up (guards against the
        // CDN script failing to load or hanging).
        function waitForTapSDK(onLoaded, onTimeout) {
            var start = Date.now();
            (function poll() {
                if (window.TapSDKs && typeof window.TapSDKs.renderCheckoutElement === 'function') {
                    onLoaded();
                    return;
                }
                if (Date.now() - start >= 60000) {
                    onTimeout();
                    return;
                }
                setTimeout(poll, 200);
            })();
        }

        const config = {
                    open: true,
                    onClose: () => { 
                            stopCheckout(); 
                            // Allow the "Place order" button to reopen the popup.
                            tapStarted = false;
                            tapRendered = false;
                            if (tapRenderWatchdog) { clearTimeout(tapRenderWatchdog); tapRenderWatchdog = null; }
                        },
                    onReady: () => {
                        tapRendered = true;
                        if (tapRenderWatchdog) { clearTimeout(tapRenderWatchdog); tapRenderWatchdog = null; }
                        hideTapLoading();
                        console.log('tap onReady');
                    },
                    onSuccess: (res) => { 
                        //showBlur(); 
                        console.log('tap onSuccess raw:', res);
                        // The SDK may return an object, a JSON string, or the charge
                        // id as a plain string. Extract the charge id defensively.
                        var chargeId = '';
                        if (typeof res === 'string') {
                            try {
                                var parsed = JSON.parse(res);
                                chargeId = (parsed && (parsed.chargeId || parsed.id)) || '';
                            } catch (e) {
                                chargeId = res;
                            }
                        } else if (res) {
                            chargeId = res.chargeId || res.id || (res.charge && res.charge.id) || '';
                        }
                        console.log('tap onSuccess chargeId:', chargeId);
                        // Record the charge id in the order notes, then redirect to
                        // the thank-you page so the server finalises the order.
                        saveChargeId(chargeId);
                        window.location = `${redirect_url}&tap_id=${chargeId}`;
                    },
                    onError: (error) => { 
                        console.log({ error }); 
                        window.location = `${redirect_url}`;

                    },


                    "checkoutMode": "popup",
                    "language": Ui_language_val,
                    "themeMode": "dark",
                    "supportedCurrencies": "ALL",
                    "supportedRegions": [],
               
                    "supportedPaymentTypes": [],
                    "supportedPaymentMethods": "ALL",
                    "supportedSchemes": [],
                    "cardOptions": {
                        "showBrands": true,
                        "showLoadingState": true,
                        "collectHolderName": true,
                        "cardNameEditable": true,
                        "cardFundingSource": "all",
                        "saveCardOption": "none",
                        "forceLtr": true
                    },
                    "selectedCurrency": currency,
                    "paymentType": "ALL",
                    "gateway": {
                        "merchantId": merchant_id,
                        "publicKey": active_pk
                    },
                    "hashString": hash,
                    "customer": {
                        "firstName": billing_first_name,
                        "lastName": billing_last_name,
                        "phone": {
                            "countryCode": country_code,
                            "number": billing_phone
                        },
                        "email": billing_email
                    },
                    "transaction": {
                        "mode": transaction_type,
                        [transaction_type]: {
                            "saveCard": save_card_val,
                            "threeDSecure": true,
                            "description": "",
                            "statement_descriptor": "",
                            "reference": {
                                "transaction": "quote_6",
                                "order": order_id
                            },
                            "redirect": {
                                "url": redirect_url
                            },
                            "post": post_url,
                            "metadata": {
                                "requestId": request_id
                            },
                            "platform": {
                                "id": "commerce_platform_h8vB1824817Hyx71tc2A936"
                            }
                        }
                    },
                    "amount": fixed_amount,
                    "order": {
                        "amount": fixed_amount,
                        "currency": currency,
                        "items": items,
                        ...(shipping && shipping.amount > 0 ? { shipping } : {})
                    }
                };
        console.log(config);  
        const stopCheckout = () => { unmount && unmount(); };

        function tapDoRender() {
            try {
                const checkoutElement = window.TapSDKs.renderCheckoutElement("checkout-element", config);
                unmount = checkoutElement && checkoutElement.unmount;
                // SDK is now rendering the popup (with its own loader), hide ours.
                hideTapLoading();
            } catch (e) {
                tapFailover('renderCheckoutElement threw: ' + (e && e.message));
                return;
            }
            // 60s render watchdog: fail over only if nothing actually rendered
            // (onReady clears this timer when the UI is ready).
            tapRenderWatchdog = setTimeout(function () {
                if (tapRendered) { return; }
                var container = document.getElementById('checkout-element') || document.getElementById('tap_root');
                var hasContent = container && (container.children.length > 0 || (container.innerHTML || '').trim().length > 0);
                if (!hasContent) {
                    tapFailover('render watchdog: nothing rendered within 60s');
                }
            }, 60000);
        }

        const startCheckout = () => { 
            if (tapStarted) { return; }
            tapStarted = true;
            // Show our loader while the SDK is still loading. If the SDK is
            // already available it renders immediately (with its own loader).
            if (!(window.TapSDKs && typeof window.TapSDKs.renderCheckoutElement === 'function')) {
                showTapLoading('Loading…');
            }
            waitForTapSDK(tapDoRender, function () {
                tapFailover('TapSDKs failed to load within timeout', 'Something went wrong');
            });
        };
    

        var popup = true;
        if (popup == true) {

            startCheckout();
        }

});




var chg = jQuery("#chg").val();
jQuery(function($){
    var checkout_form = jQuery( 'form.woocommerce-checkout' );
    checkout_form.on( 'checkout_place_order', chg);
});
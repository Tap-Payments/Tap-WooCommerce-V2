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
    console.log(redirect_url);
        
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
    const { renderCheckoutElement } = window.TapSDKs;
        let unmount = null;
        const config = {
                    open: true,
                    onClose: () => { 
                            stopCheckout(); 
                        },
                    onSuccess: (res) => { 
                        //showBlur(); 
                        window.location = `${redirect_url}&tap_id=${res.chargeId}`;

                    },
                    onError: (error) => { 
                        console.log({ error }); 
                        //window.location = `${redirect_url}`;

                    },
                    // checkoutMode: "popup",
                    // language: "en",
                    // themeMode: "light",
                    // supportedCurrencies: "ALL",
                    // // supportedPaymentMethods: "ALL",
                    // "supportedPaymentMethods": [
                    //     "KNET",
                    //     "DEEMA"
                    // ],
                    // selectedCurrency: "KWD",
                    // paymentType: "ALL",
                    // gateway: {
                    //     publicKey: "pk_test_Vlk842B1EA7tDN5QbrfGjYzh",
                    // },
                    // customer: {
                    //     firstName: "Ossama",
                    //     lastName: "Ossama",
                    //     phone: {
                    //         countryCode: "965",
                    //         number: "98868822"
                    //     },
                    //     email: 'test@tap.company'
                    // },
                    // transaction: {
                    //     mode: "charge",
                    //     charge: {
                    //         saveCard: false,
                    //         threeDSecure: true,
                    //         description: "aziz",
                    //         statement_descriptor: "",
                    //         reference: { transaction: "", order: "" },
                    //         redirect: { url: "https://invoices.tap.company/redirect/inv_bCp513110123jl0527069" },
                    //         post: "https://invoices.tap.company/post/inv_bCp513110123jl0527069"
                    //     }
                    // },
                    // amount: 500.000,
                    // order: {
                    //     amount: 500.000,
                    //     currency: "KWD",
                    //     items: [{ name: "Tap Product", description: "null", quantity: "1", amount: "500.000", currency: "KWD" }],
                    //     discount: {
                    //         type: 'F',
                    //         value: 0
                    //     },
                    // },
                    // cardOptions: {
                    //     showBrands: true,
                    //     showLoadingState: true,
                    //     collectHolderName: true,
                    //     preLoadCardName: 'test',
                    //     cardNameEditable: true,
                    //     cardFundingSource: "all",
                    //     saveCardOption: "none",
                    //     forceLtr: true
                    // }

                    "checkoutMode": "popup",
                    "language": Ui_language_val,
                    "themeMode": "dark",
                    "supportedCurrencies": "ALL",
                    "supportedRegions": [
                        //"REGIONAL"
                    ],
                    // "supportedCountries": [
                    //     "KW"
                    // ],
                    "supportedPaymentTypes": [
                        // "BNPL",
                        // "CARD"
                    ],
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
        const startCheckout = () => { 

            const checkoutElement = renderCheckoutElement("checkout-element",
                config);

            unmount = checkoutElement.unmount;
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
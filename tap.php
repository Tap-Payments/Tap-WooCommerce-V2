<?php

/*
 * Plugin Name: WooCommerce Tap Payment Gateway
 * Plugin URI: 
 * Description: Take credit card payments on your store. (Features : All In One - Popup, Redirect
 * Author: Waqas Zeeshan
 * Author URI: https://tap.company/
 * Version: 2.1.1
 */
 
 /* This action hook registers our PHP class as a WooCommerce payment gateway */
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

add_filter( 'woocommerce_payment_gateways', 'tap_add_gateway_class' );

function tap_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Tap_Gateway';
	return $gateways;
}

/**
 * Write a message to the WooCommerce logs (WooCommerce > Status > Logs) under
 * the "tap" source. Falls back to PHP's error_log() when WooCommerce logging is
 * unavailable.
 *
 * @param string $message The message to log.
 * @param string $level   One of emergency|alert|critical|error|warning|notice|info|debug.
 */
function tap_log( $message, $level = 'error' ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		$logger = wc_get_logger();
		if ( $logger ) {
			$logger->log( $level, $message, array( 'source' => 'tap' ) );
			return;
		}
	}
	error_log( 'Tap [' . $level . ']: ' . $message );
}

/**
 * Unified Tap API request helper.
 *
 * Centralises all cURL boilerplate for calls to the Tap API, wraps the request
 * in a try/catch and logs any transport/JSON exception. This avoids duplicating
 * the same cURL setup across the plugin.
 *
 * @param string $url        Full endpoint URL.
 * @param string $secret_key Tap secret key used for the Bearer token.
 * @param array  $args       Optional overrides:
 *                           - method  (string) HTTP method, default 'GET'.
 *                           - body    (array|string|null) request body; arrays are json_encoded.
 *                           - headers (array) extra headers beyond auth + content-type.
 *                           - assoc   (bool) decode JSON as associative array, default false.
 * @return mixed|null Decoded JSON response, or null on failure.
 */
function tap_api_request( $url, $secret_key, $args = array() ) {
	$args = array_merge( array(
		'method'  => 'GET',
		'body'    => null,
		'headers' => array(),
		'assoc'   => false,
	), $args );

	$headers = array_merge( array(
		'authorization: Bearer ' . $secret_key,
		'content-type: application/json',
	), (array) $args['headers'] );

	$body = $args['body'];
	if ( is_array( $body ) ) {
		$body = json_encode( $body );
	}

	$curl = null;
	try {
		$curl = curl_init();
		if ( false === $curl ) {
			throw new Exception( 'curl_init() failed' );
		}

		$opts = array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => strtoupper( $args['method'] ),
			CURLOPT_HTTPHEADER     => $headers,
		);
		if ( ! is_null( $body ) ) {
			$opts[ CURLOPT_POSTFIELDS ] = $body;
		}
		curl_setopt_array( $curl, $opts );

		$response  = curl_exec( $curl );
		$errno     = curl_errno( $curl );
		$error     = curl_error( $curl );
		$http_code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );

		if ( $errno ) {
			throw new Exception( sprintf( 'cURL error (%d): %s', $errno, $error ) );
		}

		$decoded = json_decode( $response, (bool) $args['assoc'] );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'Invalid JSON response: ' . json_last_error_msg() . ' | HTTP ' . $http_code . ' | Body: ' . substr( (string) $response, 0, 500 ) );
		}

		return $decoded;
	} catch ( Exception $e ) {
		tap_log( 'Tap API request failed [' . strtoupper( $args['method'] ) . ' ' . $url . ']: ' . $e->getMessage() );
		return null;
	} finally {
		if ( $curl instanceof CurlHandle || ( is_resource( $curl ) ) ) {
			curl_close( $curl );
		}
	}
}

add_action( 'plugins_loaded', 'tap_init_gateway_class' );
define('tap_imgdir', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/assets/img/');

function tap_init_gateway_class() {

	class WC_Tap_Gateway extends WC_Payment_Gateway {
		public $testmode;
    	public $failer_page_id;
    	public $success_page_id;
    	public $description;
    	public $api_key;
    	public $test_secret_key;
    	public $test_public_key;
    	public $live_secret_key;
    	public $live_public_key;
    	public $payment_mode;
    	public $ui_mode;
    	public $ui_language;
    	public $post_url;
    	public $save_card;
    	public $merchant_id;


 		/**
 		 * Class constructor, more about it in Step 3
 		 */
 		public function __construct() {

 			global $woocommerce;

			$this->id = 'tap'; // payment gateway plugin ID
			$this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
			$this->has_fields = false; // in case you need a custom credit card form
			$this->method_title = 'Tap Gateway';
			$this->method_description = 'Get Paid via Tap Gateway'; // will be displayed on  the options page

			$this->supports = array(
				'products',
				'refunds',
				
			);
			// Method with all the options fields
			$this->init_form_fields();
			
			// Load the settings.
			$this->init_settings();
			
         	$this->icon = tap_imgdir . 'logo.png';
			$this->title = $this->get_option('title');
		   	$this->failer_page_id = $this->settings['failer_page_id'];
			$this->success_page_id = $this->settings['success_page_id'];
			$this->description = $this->get_option( 'description' );
			$this->enabled = $this->get_option('enabled');
			$this->testmode = 'yes' === $this->get_option('testmode');
			$this->api_key = $this->get_option('api_key');
	        $this->test_secret_key = $this->get_option('test_secret_key');
	        $this->test_public_key = $this->get_option('test_public_key');
	        $this->live_secret_key = $this->get_option('live_secret_key');
	        $this->live_public_key = $this->get_option('live_public_key');
	        $this->merchant_id = $this->get_option('merchant_id');
			$this->payment_mode = $this->get_option('payment_mode');
			//$this->type = 'type';
			$this->ui_mode = $this->get_option('ui_mode');
			$this->ui_language = $this->get_option('ui_language');
         	$this->post_url = $this->get_option('post_url');
			$this->save_card = $this->get_option('save_card');

			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ), 11);
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			
			add_action( 'woocommerce_receipt_tap', array( $this, 'tap_checkout_receipt_page' ) );
			add_action( 'woocommerce_thankyou_tap', array( $this, 'tap_thank_you_page' ) );
			add_action( 'woocommerce_api_tap_webhook', array( $this, 'webhook' ) );
			add_filter( 'the_content', array( $this, 'tap_maybe_show_failure_notice' ) );

			
			
		}

		public function webhook($order_id) {
			$data = json_decode(file_get_contents("php://input"), true);
        	$headers = apache_request_headers();
        	$header = getallheaders();
        	$active_sk = '';	
			if($this->testmode == '1') {
	 			$active_sk = $this->test_secret_key;
			}else {
	 			$active_sk = $this->live_secret_key;
			}
         	$orderid = $data['reference']['order'];
         	$status = $data['status'];
         	$charge_id = $data['id'];
        	$order = wc_get_order($orderid);

        	if($order->get_status() != 'pending'){
				return;
			}

        	$order_currency = $order->get_currency();
        	$order_amount = $order->get_total();
      		

        	if ($status !== 'CAPTURED' && $status !== 'AUTHORIZED') { 
        		$order->update_status('cancelled');
           	$order->add_order_note(sanitize_text_field('Tap payment failed..').("<br>").('ID').(':'). ($charge_id.("<br>").('Payment Type :') . ($data['source']['payment_method']).("<br>").('Payment Ref:'). ($data['reference']['payment'])));

        	}
        	if (($order_amount == $data['amount']) && ($order_currency == $data['currency'])) {
		        if ($status == 'CAPTURED'){
		         	$order->update_status('processing');
		         	$order->add_order_note(sanitize_text_field('Tap payment successful..').("<br>").('ID').(':'). ($charge_id.("<br>").('Payment Type :') . ($data['source']['payment_method']).("<br>").('Payment Ref:'). ($data['reference']['payment'])));
		         	$order->reduce_order_stock();
		         	update_option('webhook_debug', $_GET);
	         }
	         else if ($status == 'AUTHORIZED'){
	         	$order->update_status('pending');
	         	$order->add_order_note(sanitize_text_field('by post'));
	         	$order->add_order_note(sanitize_text_field('Tap payment successful..').("<br>").('ID').(':'). ($charge_id.("<br>").('Payment Type :') . ($data['source']['payment_method']).("<br>").('Payment Ref:'). ($data['reference']['payment'])));
	         }
	      }
	    else {
	      	if ($data['status'] == 'CAPTURED') {
	      		$refund_url = "https://api.tap.company/v2/refunds/";
               	$refund_object["charge_id"]  = $data['id'];
               	$refund_object["amount"]   = $data['amount'];
               	$refund_object["currency"] = $data['currency'];
               	$refund_object["reason"]           = "Order currency and response currency mismatch(fraudulent)";
               	$refund_object["post_url"] = ""; 
                $refund_response = tap_api_request( $refund_url, $active_sk, array(
                    'method' => 'POST',
                    'body'   => $refund_object,
                ) );
            	$order->update_status('cancelled');
	         	$order->add_order_note(sanitize_text_field('Tap payment decllined..').("<br>").('ID').(':'). ($charge_id.("<br>").('Payment Type :') . ($data['source']['payment_method']).("<br>").('Payment Ref:'). ($data['reference']['payment']). ('Refund ID' ) . $refund_response->id));
	      	}

	      	if ($data['status'] == 'AUTHORIZED') {
	      		$void_url = "https://api.tap.company/v2/authorize/".$data['id']."/void";
                $void_response = tap_api_request( $void_url, $active_sk, array(
                    'method' => 'POST',
                    'body'   => '{}',
                ) );

               	$order->update_status('cancelled');
	         	$order->add_order_note(sanitize_text_field('Tap payment decllined..').("<br>").('ID').(':'). ($charge_id.("<br>").('Payment Type :') . ($data['source']['payment_method']).("<br>").('Payment Ref:'). ($data['reference']['payment'])));
	      	}
	    }
  
	}
	


	/**
	 * Build the URL the customer is sent to when a payment fails/declines.
	 * A query flag is appended so the failure notice can be rendered on the
	 * destination page regardless of whether the theme prints WooCommerce notices.
	 */
	public function tap_get_failure_url( $message = '' ) {
		$failure_url = '';
		if ( ! empty( $this->failer_page_id ) ) {
			$failure_url = get_permalink( $this->failer_page_id );
		}
		if ( empty( $failure_url ) ) {
			$failure_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url();
		}
		$args = array( 'tap_payment' => 'failed' );
		if ( ! empty( $message ) ) {
			// add_query_arg() url-encodes the value.
			$args['tap_msg'] = $message;
		}
		return add_query_arg( $args, $failure_url );
	}

	/**
	 * Redirect the customer to the failure page. Works even if output has
	 * already started (e.g. from within the thank-you page template), where a
	 * normal header redirect would silently fail. An optional gateway response
	 * message is carried through so it can be shown on the failure page.
	 */
	public function tap_redirect_failure( $message = '' ) {
		$url = $this->tap_get_failure_url( $message );
		if ( ! headers_sent() ) {
			wp_safe_redirect( $url );
		} else {
			echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $url ) . '" />';
			echo '<script>window.location.href = ' . wp_json_encode( $url ) . ';</script>';
		}
		exit;
	}

	/**
	 * Render the failure notice on the failure page. Prints the gateway response
	 * message when available, otherwise a generic "Transaction Failed". Uses
	 * WooCommerce notice markup so it inherits the store's styling.
	 */
	public function tap_maybe_show_failure_notice( $content ) {
		static $already_shown = false;
		if ( $already_shown || is_admin() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( isset( $_GET['tap_payment'] ) && 'failed' === sanitize_text_field( wp_unslash( $_GET['tap_payment'] ) ) ) {
			$already_shown = true;
			$message = isset( $_GET['tap_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['tap_msg'] ) ) : '';
			if ( '' === trim( $message ) ) {
				$message = __( 'Transaction Failed', 'woothemes' );
			}
			$notice  = '<div class="woocommerce">';
			$notice .= '<ul class="woocommerce-error" role="alert"><li>' . esc_html( $message ) . '</li></ul>';
			$notice .= '</div>';
			return $notice . $content;
		}
		return $content;
	}

	public function tap_thank_you_page($order_id){
		global $woocommerce;
		$active_sk = '';	

		$order = wc_get_order( $order_id );
		if($order->get_status() == 'cancelled'){
			$this->tap_redirect_failure( $order->get_meta( '_tap_fail_message' ) );
		}
		
		if($order->get_status() != 'pending'){
			return;
		}

		if ($this->testmode == '1') {
	 		$active_sk = $this->test_secret_key;
		}else {
	 		$active_sk = $this->live_secret_key;
		}
		// Determine the transaction type from the Tap id prefix rather than the
		// configured payment mode: charges start with "chg_" and authorizations
		// start with "auth_".
		$tap_id       = isset( $_GET['tap_id'] ) ? sanitize_text_field( wp_unslash( $_GET['tap_id'] ) ) : '';
		$is_authorize = ( strpos( $tap_id, 'auth_' ) === 0 );
		$is_charge    = ( strpos( $tap_id, 'chg_' ) === 0 );
		$url          = $is_authorize
			? 'https://api.tap.company/v2/authorize/'
			: 'https://api.tap.company/v2/charges/';
		$response = tap_api_request( $url . $_GET['tap_id'], $active_sk, array( 'method' => 'GET' ) );

 		// Gateway response message (e.g. decline reason) to show on failure.
 		$fail_message = ( isset( $response->response->message ) && $response->response->message ) ? $response->response->message : '';
 		$order_amount = $order->get_total();
 		$order_currency = $order->get_currency();
 		if ($response->status !== 'CAPTURED' && $response->status !== 'AUTHORIZED') { 
 			$order->update_status('cancelled');
			$order->add_order_note(sanitize_text_field('Tap payment failed').("<br>").('Reason:').($fail_message).("<br>").('ID').(':'). ($_GET['tap_id'].("<br>").('Payment Type :') . ($response->source->payment_method).("<br>").('Payment Ref:'). ($response->reference->payment)));
			$items = $order->get_items();
	 		foreach ( $items as $item ) {
	    		$product_name = $item->get_name();
	    		$product_quantity = $item->get_quantity();
	   		 	$product_id = $item->get_product_id();
	    		$product_variation_id = $item->get_variation_id();
	    		$variation = new WC_Product_Variation($product_variation_id);
	    		$variationName = implode(" / ", $variation->get_variation_attributes());
	    		$woocommerce->cart->add_to_cart( $product_id, $product_quantity, $product_variation_id , $variationName);
			}
			$order->update_meta_data( '_tap_fail_message', $fail_message );
			$order->save();
			$this->tap_redirect_failure( $fail_message );
 		}
	 	if (empty($_GET['tap_id'])) {
	 		$items = $order->get_items();
	 		foreach ( $items as $item ) {
	    		$product_name = $item->get_name();
	    		$product_quantity = $item->get_quantity();
	   		 	$product_id = $item->get_product_id();
	    		$product_variation_id = $item->get_variation_id();
	    		$variation = new WC_Product_Variation($product_variation_id);
	    		$variationName = implode(" / ", $variation->get_variation_attributes());
	    				$woocommerce->cart->add_to_cart( $product_id, $product_quantity, $product_variation_id , $variationName);
			}

		 	$order->update_status('cancelled');
		 	$this->tap_redirect_failure();
	 	}
	 	//echo 'order amount--'.$order_amount.'response amount--'.$response->amount.'order currency--'.$order_currency.'--response currency'.$response->currency;exit;
 		if (($order_amount == $response->amount) && ($order_currency == $response->currency)) { 
 				
 			if (!empty($_GET['tap_id']) && $is_charge) {
				if ($response->status == 'CAPTURED') {
					$order->update_status('processing');
					$order->payment_complete($_GET['tap_id']);
						update_post_meta( $order->id, '_transaction_id', $_GET['tap_id']);
					$order->set_transaction_id( $_GET['tap_id'] );
					$order->add_order_note(sanitize_text_field('Tap payment successful').("<br>").('ID').(':'). ($_GET['tap_id'].("<br>").('Payment Type :') . ($response->source->payment_method).("<br>").('Payment Ref:'). ($response->reference->payment)));
						$order->payment_complete($_GET['tap_id']);
						$woocommerce->cart->empty_cart();
					if ( $this->success_page_id == "" || $this->success_page_id == 0 ) {
							$redirect_url = $order->get_checkout_order_received_url();
						} else {
							$redirect_url = get_permalink($this->success_page_id);
							wp_redirect($redirect_url);exit;
					}
				}
 			}

 			if (!empty($_GET['tap_id']) && $is_authorize) {
				if ($response->status == 'AUTHORIZED') {
						$order->update_status('pending');
						$order->add_order_note(sanitize_text_field('Tap payment successful').("<br>").('ID').(':'). ($_GET['tap_id'].("<br>").('Payment Type :') . ($response->source->payment_method).("<br>").('Payment Ref:'). ($response->reference->payment)));
						$woocommerce->cart->empty_cart();
						if ( $this->success_page_id == "" || $this->success_page_id == 0 ) {
							$redirect_url = $order->get_checkout_order_received_url();
						} else {
							$redirect_url = get_permalink($this->success_page_id);
							wp_redirect($redirect_url);exit;
						} 
				} 
				else {
						$order->update_status('cancelled');
						$order->update_meta_data( '_tap_fail_message', $fail_message );
						$order->save();
						$this->tap_redirect_failure( $fail_message );
				}
 			}
	 	}
	 	else {
		 	if ($response->status == 'CAPTURED') {
				$refund_url = "https://api.tap.company/v2/refunds/";
				$refund_object["charge_id"]                 = $_REQUEST['tap_id'];
				$refund_object["amount"]   = $response->amount;
			    $refund_object["currency"]               = $response->currency;
			    $refund_object["reason"]           = "Order currency and response currency mismatch(fraudulent)";
			    $refund_object["post_url"] = ""; 
						
				$refund_response = tap_api_request( $refund_url, $active_sk, array(
					'method' => 'POST',
					'body'   => $refund_object,
				) );
				$order->update_status('cancelled');
				$order->add_order_note(sanitize_text_field('Tap declined payment error').("<br>").('ID').(':'). ($_GET['tap_id'].("<br>").('Payment Type :') . ($response->source->payment_method).("<br>").('Payment Ref:'). ($response->reference->payment). ('Refund ID').(':') . ($refund_response->id)));
				$this->tap_redirect_failure( __( 'Payment verification failed (amount/currency mismatch).', 'woothemes' ) );
			}

			else if ($response->status == 'AUTHORIZED')
            {
                $void_url = "https://api.tap.company/v2/authorize/".$_REQUEST['tap_id']."/void";
                $void_response = tap_api_request( $void_url, $active_sk, array(
                    'method' => 'POST',
                    'body'   => '{}',
                ) );
                $order->update_status('cancelled');
                $order->add_order_note(sanitize_text_field('Tap declined payment error').("<br>").('ID').(':'). ($_GET['tap_id'].("<br>").('Payment Type :') . ($response->source->payment_method).("<br>").('Payment Ref:'). ($response->reference->payment)));
                $this->tap_redirect_failure( __( 'Payment verification failed (amount/currency mismatch).', 'woothemes' ) );
            }
	 	}
	}


 	public function tap_checkout_receipt_page($order_id) {
 		global $woocommerce;
 		$order = wc_get_order( $order_id );
 		// Build items from the order being paid, not the live cart. On the
 		// order-pay endpoint the cart may be empty or hold different products,
 		// which would send Tap the wrong items (the SDK validates them client-side).
 		$items = $order->get_items();
 		if($this->testmode == "testmode"){
            $active_pk = $this->test_public_key;
            $active_sk = $this->test_secret_key;
 		}
 		else
 		{
 			$active_pk = $this->live_public_key;
 			$active_sk = $this->live_secret_key;
 		}
 		$merchant_id = $this->merchant_id;
 		$ref = '';
 		if ($order->currency=="KWD"){
			$Total_price = number_format((float)$order->total, 3, '.', '');
		} else {
			$Total_price = number_format((float)$order->total, 2, '.', '');
		}
        $Hash = 'x_publickey'. $active_pk.'x_amount'.$Total_price.'x_currency'.$order->currency.'x_transaction'.$ref.'x_post'.get_site_url()."/wc-api/tap_webhook";
        $hashstring = hash_hmac('sha256', $Hash, $active_sk);
 		$country_code = $this->getStorePhoneCountryCode($order->get_billing_country());

        $current_user = wp_get_current_user();
        $first_name = $current_user->user_firstname;
        $last_name  = $current_user->user_lastname;
        if(empty($first_name)){
         	$first_name = $order->billing_first_name;
        }
        if(empty($last_name)){
         	$last_name = $order->billing_last_name;
        }
 		echo '<div id="tap_root"></div>';
 		echo '<div id="tap_loading_overlay">
 				<div class="tap-spinner"></div>
 				<div class="tap-loading-text">' . esc_html__( 'Loading…', 'woothemes' ) . '</div>
 			  </div>';
 		echo '<style>
 			#tap_loading_overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;display:none;flex-direction:column;align-items:center;justify-content:center;background:rgba(255,255,255,0.92);}
 			#tap_loading_overlay .tap-spinner{width:48px;height:48px;border:5px solid #e0e0e0;border-top-color:#2d8cff;border-radius:50%;animation:tap-spin 1s linear infinite;}
 			#tap_loading_overlay .tap-loading-text{margin-top:16px;font-size:16px;color:#333;font-family:inherit;}
 			@keyframes tap-spin{to{transform:rotate(360deg);}}
 			</style>';
 			echo '<input type="hidden" id="publishable_key" value="' . $this->live_public_key . '" />';
        echo '<input type="hidden" id="test_public_key" value="' . $this->test_public_key . '" />';
        echo '<input type="hidden" id="testmode" value="' . $this->testmode . '" />';
        echo '<input type="hidden" id="post_url" value="' . get_site_url()."/wc-api/tap_webhook" . '" />';
 		echo '<input type="hidden" id="tap_end_url" value="' . $this->get_return_url($order) . '" />';
 		echo '<input type="hidden" id="order_id" value="' . $order->id . '" />';
 		echo '<input type="hidden" id="tap_ajax_url" value="' . admin_url('admin-ajax.php') . '" />';
 		echo '<input type="hidden" id="tap_ajax_nonce" value="' . wp_create_nonce('tap_save_charge_id') . '" />';
 		echo '<input type="hidden" id="hashstring" value="' . $hashstring . '" />';
 		echo '<input type="hidden" id="ui_language" value="' . $this->ui_language . '" />';
 		echo '<input type="hidden" id="countrycode" value="' . $country_code . '" />';
 			
 		echo '<input type="hidden" id="chg" value="' . $this->payment_mode . '" />';
 		echo '<input type="hidden" id="save_card" value="' . $this->save_card . '" />';
 		echo '<input type="hidden" id="payment_mode" value="' . $this->payment_mode . '" />';
 		echo '<input type="hidden" id="amount" value="' . $order->total . '" />';
 		echo '<input type="hidden" id="currency" value="' . $order->currency . '" />';
 		echo '<input type="hidden" id="billing_first_name" value="' . $first_name . '" />';
 		echo '<input type="hidden" id="billing_last_name" value="' . $last_name . '" />';
 		echo '<input type="hidden" id="billing_email" value="' . $order->billing_email . '" />';
 		echo '<input type="hidden" id="billing_phone" value="' . $order->billing_phone . '" />';
 		echo '<input type="hidden" id="customer_user_id" value="' . get_current_user_id() . '" />';
 		echo '<input type="hidden" id="merchant_id" value="'. $merchant_id .'"/>';


 		$shipping_methods = $order->get_shipping_methods();

 		$shipping_carrier = [];// Initialize a new empty object
		foreach ($shipping_methods as $shipping_item_id => $shipping_item) {
			$shipping_carrier['amount'] = floatval($shipping_item->get_total());
		    $shipping_carrier['currency']  = $order->currency;
		    $shipping_carrier['description']  = $shipping_item->get_name();
		    $shipping_carrier['provider'] = $shipping_item->get_name();
		    $shipping_carrier['service'] = $shipping_item->get_name();

		}


		
 		$order_it = [];
 		$decimals = in_array($order->get_currency(), array('KWD','BHD','OMR','JOD'), true) ? 3 : 2;
 			
 		foreach($items as $item_id=>$item) {
 			$product  = $item->get_product();
 			$quantity = intval($item->get_quantity());
 			if ($quantity < 1) {
 				$quantity = 1;
 			}
 			// Unit price as actually charged on this order line.
 			$price = round(((float) $item->get_total()) / $quantity, $decimals);

 			$name       = $item->get_name();
 			$product_id = $item->get_product_id();
 			$description = $product ? $product->get_description() : '';
 			if (strlen($description) > 240) {
			    // Truncate to 240 characters and append '...'
			    $description = substr($description, 0, 240) . '...';
			}

 			$order_it[] = [
			    'name' => $name,
			    'description' => $description,
			    'quantity' => $quantity,
			    'currency' => $order->currency,
			    'amount' => $price
			];
 			echo '<input type="hidden" name="items_bulk[]" data-name="'.esc_attr($name).'" data-quantity="'.esc_attr($quantity).'" data-sale-price="'.esc_attr($price).'" data-item-product-id="'.esc_attr($product_id).'" data-product-total-amount="'.esc_attr($quantity*$price).'" class="items_bulk">';
 		}

 		echo '<input type="hidden" id="itm" value="' . htmlspecialchars(json_encode($order_it), ENT_QUOTES, 'UTF-8') . '">';
		echo '<input type="hidden" id="shippingItems" value="' . htmlspecialchars(json_encode($shipping_carrier), ENT_QUOTES, 'UTF-8') . '">';


 		if ( $this->ui_mode == 'popup') {	
 			static $already_rendered = false;
		    if ($already_rendered) {
		        return;
		    }
		    $already_rendered = true;
		    ?>
 			

            <script type="text/javascript">
             	jQuery(function(){
					
				jQuery("#submit_tap_payment_form").click();});
             </script>
            <?php
 				echo '<input type="button" value="Place order by Tap" id="submit_tap_payment_form" />';
 		}	
 	}


	public function init_form_fields(){

		$this->form_fields = array(
			'enabled' => array(
				'title'       => 'Enable/Disable',
				'label'       => 'Enable Tap Gateway',
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			),
			'title' => array(
				'title'       => 'Title',
				'type'        => 'text',
				'description' => 'This controls the title which the user sees duringcheckout.',
				'default'     => 'Credit Card',
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => 'Description',
				'type'        => 'textarea',
				'description' => 'This controls the description which the user sees during checkout.',
				'default'     => 'Pay with your credit card via Tap payment gateway. On clicking Place order payment will be processed.',
			),
			'testmode' => array(
				'title'       => 'Test mode',
				'label'       => 'Enable Test Mode',
				'type'        => 'checkbox',
				'description' => 'Place the payment gateway in test mode using test API keys.',
				'default'     => 'yes',
				'desc_tip'    => true,
			),
       		'test_secret_key' => array(
             	'title' => 'Test Secret Key',
             	'type'  => 'text'
            ),
       	  	'test_public_key' => array(
	            'title' => 'Test Public Key',
	            'type'  => 'text'
            ),
       	   'live_public_key' => array(
             	'title' => 'Live Public Key',
             	'type'  => 'text'
            ),
       		'live_secret_key' => array(
               'title' => 'Live Secret Key',
               'type'  => 'text'
            ),
            'merchant_id' => array(
               'title' => 'Merchant ID',
               'type'  => 'text'
            ),
			'payment_mode' => array(
               'title'       => 'Payment Mode',
               'type'        => 'select',
               'class'       => 'wc-enhanced-select',
               'default'     => '',
               'desc_tip'    => true,
               'options'     => array(
						'charge'    => 'Charge',
						'authorize' => 'Authorize',
						)
		 		),
		 	'ui_mode' => array(
               'title'       => 'Ui Mode',
               'type'        => 'select',
               'class'       => 'wc-enhanced-select',
               'default'     => '',
               'desc_tip'    => true,
               'options'     => array(
						'redirect' => 'redirect',
						'popup'    => 'popup'
						)
		 		),
		 	'ui_language' => array(
             	'title'       => 'Ui Language',
             	'type'        => 'select',
             	'class'       => 'wc-enhanced-select',
             	'default'     => '',
             	'desc_tip'    => true,
             	'options'     => array(
						'english' => 'English',
						'arabic'  => 'Arabic',	
 					)
		 		),
		 	'failer_page_id' => array(
					'title' 	=> __('Return to failure Page'),
					'type' 	=> 'select',
					'options' => $this->tap_get_pages('Select Page'),
					'description' => __('URL of failure page', 'kdc'),
					'desc_tip' => true
            ),
		 	'success_page_id' => array(
					'title' 	=> __('Return to success Page'),
					'type' 	=> 'select',
					'options' 	=> $this->tap_get_pages('Select Page'),
					'description' => __('URL of success page', 'kdc'),
					'desc_tip' 	=> true
            ),	
		 	'post_url' => array(
					'title' => 'Post URL',
					'type'  => 'text'
				),
		 	'save_card' => array(
					'title'  => 'Save Cards',
					'label'		=> 'Check if you want to save card data',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				)
			);
	 	}


	
		


      	public function payment_scripts() {
      		if (is_checkout()) {
				if ($this->ui_mode == 'popup' || $this->ui_mode == 'redirect' ){
			
					wp_enqueue_script( 'tap_js', 'https://tap-sdks.b-cdn.net/checkout/1.5.0-beta/indexk.js', array('jquery') );
					$taap_version = @filemtime( plugin_dir_path( __FILE__ ) . 'tap.js' );
					wp_register_script( 'woocommerce_tap', plugins_url( 'tap.js', __FILE__ ), array('jquery'), $taap_version );
					wp_enqueue_style( 'tap-payment', plugins_url( 'tap-payment.css', __FILE__ ) );
					wp_localize_script( 'woocommerce_tap', 'tap_ajax_object', array(
						'ajax_url' => admin_url( 'admin-ajax.php' ),
						'nonce'    => wp_create_nonce( 'tap_save_charge_id' ),
					) );
					wp_enqueue_script( 'woocommerce_tap' );
				}
			}
		}

	 	public function getStorePhoneCountryCode($country_code)
    	{
        	$countrycode = array(
	            'AD'=>'376',
	            'AE'=>'971',
	            'AF'=>'93',
	            'AG'=>'1268',
	            'AI'=>'1264',
	            'AL'=>'355',
	            'AM'=>'374',
	            'AN'=>'599',
	            'AO'=>'244',
	            'AQ'=>'672',
	            'AR'=>'54',
	            'AS'=>'1684',
	            'AT'=>'43',
	            'AU'=>'61',
	            'AW'=>'297',
	            'AZ'=>'994',
	            'BA'=>'387',
	            'BB'=>'1246',
	            'BD'=>'880',
	            'BE'=>'32',
	            'BF'=>'226',
	            'BG'=>'359',
	            'BH'=>'973',
	            'BI'=>'257',
	            'BJ'=>'229',
	            'BL'=>'590',
	            'BM'=>'1441',
	            'BN'=>'673',
	            'BO'=>'591',
	            'BR'=>'55',
	            'BS'=>'1242',
	            'BT'=>'975',
	            'BW'=>'267',
	            'BY'=>'375',
	            'BZ'=>'501',
	            'CA'=>'1',
	            'CC'=>'61',
	            'CD'=>'243',
	            'CF'=>'236',
	            'CG'=>'242',
	            'CH'=>'41',
	            'CI'=>'225',
	            'CK'=>'682',
	            'CL'=>'56',
	            'CM'=>'237',
	            'CN'=>'86',
	            'CO'=>'57',
	            'CR'=>'506',
	            'CU'=>'53',
	            'CV'=>'238',
	            'CX'=>'61',
	            'CY'=>'357',
	            'CZ'=>'420',
	            'DE'=>'49',
	            'DJ'=>'253',
	            'DK'=>'45',
	            'DM'=>'1767',
	            'DO'=>'1809',
	            'DZ'=>'213',
	            'EC'=>'593',
	            'EE'=>'372',
	            'EG'=>'20',
	            'ER'=>'291',
	            'ES'=>'34',
	            'ET'=>'251',
	            'FI'=>'358',
	            'FJ'=>'679',
	            'FK'=>'500',
	            'FM'=>'691',
	            'FO'=>'298',
	            'FR'=>'33',
	            'GA'=>'241',
	            'GB'=>'44',
	            'GD'=>'1473',
	            'GE'=>'995',
	            'GH'=>'233',
	            'GI'=>'350',
	            'GL'=>'299',
	            'GM'=>'220',
	            'GN'=>'224',
	            'GQ'=>'240',
	            'GR'=>'30',
	            'GT'=>'502',
	            'GU'=>'1671',
	            'GW'=>'245',
	            'GY'=>'592',
	            'HK'=>'852',
	            'HN'=>'504',
	            'HR'=>'385',
	            'HT'=>'509',
	            'HU'=>'36',
	            'ID'=>'62',
	            'IE'=>'353',
	            'IL'=>'972',
	            'IM'=>'44',
	            'IN'=>'91',
	            'IQ'=>'964',
	            'IR'=>'98',
	            'IS'=>'354',
	            'IT'=>'39',
	            'JM'=>'1876',
	            'JO'=>'962',
	            'JP'=>'81',
	            'KE'=>'254',
	            'KG'=>'996',
	            'KH'=>'855',
	            'KI'=>'686',
	            'KM'=>'269',
	            'KN'=>'1869',
	            'KP'=>'850',
	            'KR'=>'82',
	            'KW'=>'965',
	            'KY'=>'1345',
	            'KZ'=>'7',
	            'LA'=>'856',
	            'LB'=>'961',
	            'LC'=>'1758',
	            'LI'=>'423',
	            'LK'=>'94',
	            'LR'=>'231',
	            'LS'=>'266',
	            'LT'=>'370',
	            'LU'=>'352',
	            'LV'=>'371',
	            'LY'=>'218',
	            'MA'=>'212',
	            'MC'=>'377',
	            'MD'=>'373',
	            'ME'=>'382',
	            'MF'=>'1599',
	            'MG'=>'261',
	            'MH'=>'692',
	            'MK'=>'389',
	            'ML'=>'223',
	            'MM'=>'95',
	            'MN'=>'976',
	            'MO'=>'853',
	            'MP'=>'1670',
	            'MR'=>'222',
	            'MS'=>'1664',
	            'MT'=>'356',
	            'MU'=>'230',
	            'MV'=>'960',
	            'MW'=>'265',
	            'MX'=>'52',
	            'MY'=>'60',
	            'MZ'=>'258',
	            'NA'=>'264',
	            'NC'=>'687',
	            'NE'=>'227',
	            'NG'=>'234',
	            'NI'=>'505',
	            'NL'=>'31',
	            'NO'=>'47',
	            'NP'=>'977',
	            'NR'=>'674',
	            'NU'=>'683',
	            'NZ'=>'64',
	            'OM'=>'968',
	            'PA'=>'507',
	            'PE'=>'51',
	            'PF'=>'689',
	            'PG'=>'675',
	            'PH'=>'63',
	            'PK'=>'92',
	            'PL'=>'48',
	            'PM'=>'508',
	            'PN'=>'870',
	            'PR'=>'1',
	            'PT'=>'351',
	            'PW'=>'680',
	            'PY'=>'595',
	            'QA'=>'974',
	            'RO'=>'40',
	            'RS'=>'381',
	            'RU'=>'7',
	            'RW'=>'250',
	            'SA'=>'966',
	            'SB'=>'677',
	            'SC'=>'248',
	            'SD'=>'249',
	            'SE'=>'46',
	            'SG'=>'65',
	            'SH'=>'290',
	            'SI'=>'386',
	            'SK'=>'421',
	            'SL'=>'232',
	            'SM'=>'378',
	            'SN'=>'221',
	            'SO'=>'252',
	            'SR'=>'597',
	            'ST'=>'239',
	            'SV'=>'503',
	            'SY'=>'963',
	            'SZ'=>'268',
	            'TC'=>'1649',
	            'TD'=>'235',
	            'TG'=>'228',
	            'TH'=>'66',
	            'TJ'=>'992',
	            'TK'=>'690',
	            'TL'=>'670',
	            'TM'=>'993',
	            'TN'=>'216',
	            'TO'=>'676',
	            'TR'=>'90',
	            'TT'=>'1868',
	            'TV'=>'688',
	            'TW'=>'886',
	            'TZ'=>'255',
	            'UA'=>'380',
	            'UG'=>'256',
	            'US'=>'1',
	            'UY'=>'598',
	            'UZ'=>'998',
	            'VA'=>'39',
	            'VC'=>'1784',
	            'VE'=>'58',
	            'VG'=>'1284',
	            'VI'=>'1340',
	            'VN'=>'84',
	            'VU'=>'678',
	            'WF'=>'681',
	            'WS'=>'685',
	            'XK'=>'381',
	            'YE'=>'967',
	            'YT'=>'262',
	            'ZA'=>'27',
	            'ZM'=>'260',
	            'ZW'=>'263'
        		);
        	return $countrycode[$country_code];
    	}

		public function payment_fields() {
			if ($this->ui_mode == 'popup' || $this->ui_mode == 'redirect' ){

           	global $woocommerce;
				$customer_user_id = get_current_user_id();
				$currency = get_woocommerce_currency();
				$amount = $woocommerce->cart->total;
				$mode = $this->payment_mode;
	 			if ( $this->description ) {
					if ( $this->testmode ) {
						$this->description .= ' TEST MODE ENABLED. In test mode, you can use the card numbers mentioned in documentation';
						$this->description  = trim( $this->description );
					}
					echo wpautop( wp_kses_post( $this->description ) );
				}
				echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';
			
				do_action( 'woocommerce_credit_card_form_start', $this->id );
			?>
	 			<div id="tap_root"></div>
	 			<?php
				do_action( 'woocommerce_credit_card_form_end', $this->id );
				echo '<div class="clear"></div></fieldset>';
 			}

		}

	 	public function process_payment($order_id) {
	 		$order 	= new WC_Order( $order_id );
	 		if ($this->ui_mode == 'popup'){
	 			global $woocommerce;
				
				return array(
					'result' => 'success',
					'redirect' => $order->get_checkout_payment_url( true )
				);
			}

			if ($this->ui_mode == 'redirect') {
			
				$currencyCode = $order->get_currency();
			   $orderid = $order->get_id();
			   $order_items = $order->get_items();
				if ($this->payment_mode	== 'authorize') {
		   		$charge_url = 'https://api.tap.company/v2/authorize';
		   	}
		   	else {
		   		$charge_url = 'https://api.tap.company/v2/charges';
		   	}
			   $first_name = isset($_POST['billing_first_name']) ? $_POST['billing_first_name'] : $order->get_billing_first_name();
			   $last_name  = isset($_POST['billing_last_name']) ? $_POST['billing_last_name'] : $order->get_billing_last_name();
			   $country    = isset($_POST['billing_country']) ? $_POST['billing_country'] : $order->get_billing_country();
			   $city       = isset($_POST['billing_city']) ? $_POST['billing_city'] : $order->get_billing_city();
				$billing_address = isset($_POST['billing_address_1']) ? $_POST['billing_address_1'] : $order->get_billing_address_1();
				$country_code = $this->getStorePhoneCountryCode($country);
			   $return_url    = $order->get_checkout_order_received_url();
			   $billing_email = isset($_POST['billing_email']) ? $_POST['billing_email'] : $order->get_billing_email();
			   $biliing_fone  = isset($_POST['billing_phone']) ? $_POST['billing_phone'] : $order->get_billing_phone();
			   $avenue = isset($_POST['billing_address_2']) ? $_POST['billing_address_2'] : $order->get_billing_address_2();
			   $order_amount  = $order->get_total();
			   $post_url      = get_site_url()."/wc-api/tap_webhook";
			   if($this->testmode == "testmode"){
                $active_pk = $this->test_public_key;
                $active_sk = $this->test_secret_key;
	 			}else{
	 				$active_pk = $this->live_public_key;
	 				$active_sk = $this->live_secret_key;
	 			}
	 			$merchant_id = $this->merchant_id;
			   	$ref = $order_id;

			   if($currencyCode=="KWD" || $currencyCode == "BHD" || $currencyCode == "OMR"){
					$order_amount = number_format((float)$order->get_total(), 3, '.', '');
			   	} else{
					$order_amount = number_format((float)$order->get_total(), 2, '.', '');
		   		}
		   		if ($this->ui_language == 'arabic') {
		   			$language = 'ar';
		   		}
		   		else {
		   			$language = 'en';
		   		}

			   	$Hash = 'x_publickey'. $active_pk.'x_amount'.$order_amount.'x_currency'.$currencyCode.'x_transaction'.$ref.'x_post'.get_site_url()."/wc-api/tap_webhook";
         		$hashstring = hash_hmac('sha256', $Hash, $active_sk);
         		$timestamp = base_convert((string) round(microtime(true) * 1000), 10, 36);
		        $bytes = random_bytes(10);
		        $hexPart = bin2hex($bytes);

		        $prefix = 'woo_';
		        $request_id = "{$prefix}{$timestamp}_{$hexPart}";

				$source_id = 'src_all';
				$trans_object["amount"]                   = $order_amount;
				$trans_object["currency"]                 = $currencyCode;
				$trans_object["threeDsecure"]             = true;
				$trans_object["save_card"]                = false;
				$trans_object["description"]              = $orderid;
				$trans_object["statement_descriptor"]     = 'Sample';
				$trans_object["metadata"]["requestId"]         = $request_id;
				$trans_object["metadata"]["udf2"]         = 'test';
				$trans_object["reference"]["transaction"] = $ref;
				$trans_object["hashstring"] 					= $hashstring;
				$trans_object["merchant"]["id"] = $merchant_id;
				$trans_object["platform"]["id"] = 'commerce_platform_h8vB1824817Hyx71tc2A936';
				$trans_object["reference"]["order"]       = $orderid;
				$trans_object["receipt"]["email"]         = false;
				$trans_object["receipt"]["sms"]           = true;
				$trans_object["customer"]["first_name"]   = $first_name;
				$trans_object["customer"]["last_name"]    = $last_name;
				$trans_object["customer"]["email"]        = $billing_email;
				$trans_object["customer"]["phone"]["country_code"]  = $country_code;
				$trans_object["customer"]["phone"]["number"] = $biliing_fone;
				$trans_object["source"]["id"] = $source_id;
				$trans_object["post"]["url"]  = $post_url;
				$trans_object["redirect"]["url"] = $return_url;
				$frequest = json_encode($trans_object, JSON_UNESCAPED_UNICODE);
				$frequest = stripslashes($frequest);
				
				if ($this->testmode == "testmode"){
                	$active_pk = $this->test_public_key;
                	$active_sk = $this->test_secret_key;
	 			} else {
	 				$active_pk = $this->live_public_key;
	 				$active_sk = $this->live_secret_key;
	 			}

				$obj = tap_api_request( $charge_url, $active_sk, array(
					'method'  => 'POST',
					'body'    => $frequest,
					'headers' => array( 'lang_code: ' . $language ),
				) );
				$charge_id   = isset( $obj->id ) ? $obj->id : '';
				$redirct_Url = isset( $obj->transaction->url ) ? $obj->transaction->url : '';
			    
			    return array(
					'result'   => 'success',
					'redirect' => $redirct_Url
				);
			}
		}

		public function tap_get_pages($title = false, $indent = true) {
			$wp_pages = get_pages('sort_column=menu_order');
			$page_list = array();
			if ($title) $page_list[] = $title;
			foreach ($wp_pages as $page) {
				$prefix = '';
				//show indented child pages?
				if ($indent) {
             	$has_parent = $page->post_parent;
             	while($has_parent) {
                 	$prefix .=  ' - ';
                 	$next_page = get_post($has_parent);
                 	$has_parent = $next_page->post_parent;
             	}
         	}
            //add to page list array array
            $page_list[$page->ID] = $prefix . $page->post_title;
        	}
        	return $page_list;
    	}

    	public function process_refund($order_id, $amount = null, $reason = ''){
			global $post, $woocommerce;
			
			$order   = new WC_Order($order_id);
			$transID = get_post_meta( $order->id, '_transaction_id');
			$currency = $order->currency;
	 		$refund_url = 'https://api.tap.company/v2/refunds';
	 		$refund_request['charge_id'] = $transID;
	 		$refund_request['amount'] = $amount;
	 		$refund_request['currency'] = $currency;
	 		$refund_request['description'] = "Description";
	 		$refund_request['reason'] = $reason;
         	$refund_request['reference']['merchant']  = "txn_0001";	 
	      	$refund_request['metadata']['udf1']= "test1";
	      	$refund_request['metadata']['udf2']= "test2";
	      	$refund_request['post']['url']  = "http://your_url.com/post";
	 		$json_request = json_encode($refund_request);
	 		$json_request = str_replace( '\/', '/', $json_request );
	 		$json_request = str_replace(array('[',']'),'',$json_request);

	 		$active_sk = '';
			if ($this->testmode == '1') {
	 			$active_sk = $this->test_secret_key;
			} else {
	 			$active_sk = $this->live_secret_key;
			}

			$response = tap_api_request( 'https://api.tap.company/v2/refunds', $active_sk, array(
				'method' => 'POST',
				'body'   => $json_request,
			) );
	 		if ($response->id) {
	 			if ( $response->status == 'PENDING') {
	 				$order->add_order_note(sanitize_text_field('Tap Refund successful').("<br>").'Refund ID'.("<br>"). $response->id);
	 						return true;
	 			}
	 		} 
	 		else { 
	 			return false;
	 		}
		}
   	}
}


/*
 * AJAX: save the Tap charge id onto the order as an order note.
 * Registered at plugin load (not inside the gateway constructor) so it is
 * available during admin-ajax.php requests, where the gateway is not built.
 */
add_action( 'wp_ajax_tap_save_charge_id', 'tap_ajax_save_charge_id' );
add_action( 'wp_ajax_nopriv_tap_save_charge_id', 'tap_ajax_save_charge_id' );

function tap_ajax_save_charge_id() {
	if ( ! check_ajax_referer( 'tap_save_charge_id', 'nonce', false ) ) {
		wp_send_json_error( 'bad_nonce' );
	}

	$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$charge_id = isset( $_POST['charge_id'] ) ? sanitize_text_field( wp_unslash( $_POST['charge_id'] ) ) : '';

	if ( ! $order_id || empty( $charge_id ) ) {
		wp_send_json_error( 'missing_params' );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( 'invalid_order' );
	}

	if ( $order->get_meta( '_tap_charge_id' ) !== $charge_id ) {
		$order->update_meta_data( '_tap_charge_id', $charge_id );
		$order->set_transaction_id( $charge_id );
		$order->add_order_note( sanitize_text_field( 'Tap charge initiated. Charge ID: ' . $charge_id ) );
		$order->save();
	}

	wp_send_json_success( array( 'charge_id' => $charge_id ) );
}


/*
 * Handles a transition to the "cancelled" status for Tap orders. It does two
 * things on the shared hook:
 *   1. Audit log: record who cancelled the order (or attempted it) and from
 *      where, as an order note and order meta.
 *   2. Safety net: re-check the stored charge id against the Tap API. If the
 *      charge was actually captured, move the order to "processing" instead of
 *      leaving it cancelled.
 */
add_action( 'woocommerce_order_status_cancelled', 'tap_handle_order_cancelled', 5, 2 );

function tap_handle_order_cancelled( $order_id, $order = null ) {
	if ( ! ( $order instanceof WC_Order ) ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order ) {
		return;
	}

	tap_log_order_cancellation( $order );
	tap_recheck_charge_on_cancel( $order );
}

/*
 * Audit log: record who cancelled the order (or attempted the cancellation)
 * and from where, storing the details as an order note and order meta.
 */
function tap_log_order_cancellation( $order ) {
	// Who triggered it.
	$user = wp_get_current_user();
	if ( $user && $user->ID ) {
		$roles = implode( ', ', (array) $user->roles );
		$who   = sprintf( '%s (#%d%s)', $user->display_name, $user->ID, $roles ? ', ' . $roles : '' );
	} else {
		$who = 'Guest / system (not logged in)';
	}

	// From where (request context).
	if ( doing_action( 'woocommerce_cancel_unpaid_orders' ) ) {
		$context = 'automatic (unpaid hold-stock time limit reached)';
	} elseif ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		$context = 'scheduled task (cron)';
	} elseif ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
		$context = 'AJAX request';
	} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$context = 'REST API';
	} elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
		$context = 'WP-CLI';
	} elseif ( is_admin() ) {
		$context = 'admin dashboard';
	} else {
		$context = 'frontend';
	}

	// IP address.
	$ip = '';
	if ( class_exists( 'WC_Geolocation' ) ) {
		$ip = WC_Geolocation::get_ip_address();
	}
	if ( empty( $ip ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	$note = 'Order cancelled by: ' . $who . ' | Source: ' . $context;
	if ( $ip ) {
		$note .= ' | IP: ' . $ip;
	}
	if ( $uri ) {
		$note .= ' | URL: ' . $uri;
	}

	$order->add_order_note( $note );
	$order->update_meta_data( '_tap_cancelled_by', $who );
	$order->update_meta_data( '_tap_cancelled_context', $context );
	$order->update_meta_data( '_tap_cancelled_ip', $ip );
	$order->update_meta_data( '_tap_cancelled_time', current_time( 'mysql' ) );
	$order->save();
}


/*
 * Safety net: when a Tap order is cancelled, re-check the stored charge id
 * against the Tap API. If the charge was actually captured, move the order to
 * "processing" instead of leaving it cancelled.
 */
function tap_recheck_charge_on_cancel( $order ) {
	if ( $order->get_payment_method() !== 'tap' ) {
		return;
	}

	$charge_id = $order->get_meta( '_tap_charge_id' );
	if ( empty( $charge_id ) ) {
		$charge_id = $order->get_transaction_id();
	}
	if ( empty( $charge_id ) ) {
		return;
	}

	// Prevent re-processing the same charge more than once.
	if ( $order->get_meta( '_tap_cancel_rechecked' ) === $charge_id ) {
		return;
	}

	if ( ! class_exists( 'WC_Tap_Gateway' ) ) {
		return;
	}
	$gateway   = new WC_Tap_Gateway();
	$active_sk = $gateway->testmode ? $gateway->test_secret_key : $gateway->live_secret_key;
	$url       = ( $gateway->payment_mode == 'charge' )
		? 'https://api.tap.company/v2/charges/'
		: 'https://api.tap.company/v2/authorize/';

	$response = tap_api_request( $url . $charge_id, $active_sk, array( 'method' => 'GET' ) );

	// Mark as rechecked regardless, so we don't hit the API repeatedly.
	$order->update_meta_data( '_tap_cancel_rechecked', $charge_id );
	$order->save();

	if ( isset( $response->status ) && $response->status == 'CAPTURED' ) {
		$note = ("<br>") . ('ID') . (':') . ( $charge_id . ("<br>") . ('Payment Type :') . ( isset( $response->source->payment_method ) ? $response->source->payment_method : '' ) . ("<br>") . ('Payment Ref:') . ( isset( $response->reference->payment ) ? $response->reference->payment : '' ) );
		$order->add_order_note( 'Tap payment successful (verified on cancellation).' . $note );
		$order->payment_complete( $charge_id );
		$order->update_status( 'processing', sanitize_text_field( 'Tap payment successful' ) . $note );
	}
}


//Support for the Checkout Block
add_action('woocommerce_blocks_loaded', 'woocommerce_gateway_tap_woocommerce_block_support');

function woocommerce_gateway_tap_woocommerce_block_support() {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once dirname( __FILE__ ) . '/class-wc-tap-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {

				$container = Automattic\WooCommerce\Blocks\Package::container();
				// registers as shared instance.
				$container->register(
					WC_Tap_Blocks_Support::class,
					function(Automattic\WooCommerce\Blocks\Registry\Container $container) {
						$asset_api = $container->get( Automattic\WooCommerce\Blocks\Assets\Api::class );
						return new WC_Tap_Blocks_Support($asset_api);
					}
				);
				$payment_method_registry->register(
					$container->get( WC_Tap_Blocks_Support::class )
				);
			},
		);
	}
}
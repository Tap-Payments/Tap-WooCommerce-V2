<?php
/**
 * Builds Tap API request payloads from WooCommerce objects.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Keeps payload construction out of the gateway class and gives the redirect
 * flow and the popup flow one shared definition of an order's line items,
 * shipping, and customer.
 */
final class Tap_Request_Builder {

	/**
	 * Maximum length of a line item description accepted by Tap.
	 */
	private const MAX_DESCRIPTION_LENGTH = 240;

	/**
	 * Maximum length of the statement descriptor.
	 */
	private const MAX_DESCRIPTOR_LENGTH = 22;

	/**
	 * Build the body for a charge or authorization request.
	 *
	 * @param WC_Order             $order   Order being paid.
	 * @param array<string, mixed> $context Keys: request_id, post_url, redirect_url, hash_string, merchant_id, platform_id, save_card.
	 * @return array<string, mixed>
	 */
	public static function transaction( WC_Order $order, array $context ): array {
		$currency = $order->get_currency();
		$amount   = Tap_Currency::format( $order->get_total(), $currency );

		$payload = array(
			'amount'               => $amount,
			'currency'             => $currency,
			'threeDSecure'         => true,
			'save_card'            => (bool) ( $context['save_card'] ?? false ),
			'description'          => sprintf(
				/* translators: 1: site name, 2: order number. */
				__( '%1$s order %2$s', 'wc-tap-gateway' ),
				self::statement_descriptor(),
				$order->get_order_number()
			),
			'statement_descriptor' => self::statement_descriptor(),
			'metadata'             => array(
				'requestId' => (string) ( $context['request_id'] ?? '' ),
				'orderId'   => (string) $order->get_id(),
				'platform'  => 'woocommerce',
			),
			'reference'            => array(
				'transaction' => (string) $order->get_id(),
				'order'       => (string) $order->get_id(),
			),
			'hashstring'           => (string) ( $context['hash_string'] ?? '' ),
			'receipt'              => array(
				'email' => false,
				'sms'   => true,
			),
			'customer'             => self::customer( $order ),
			'source'               => array( 'id' => 'src_all' ),
			'post'                 => array( 'url' => (string) ( $context['post_url'] ?? '' ) ),
			'redirect'             => array( 'url' => (string) ( $context['redirect_url'] ?? '' ) ),
		);

		if ( ! empty( $context['merchant_id'] ) ) {
			$payload['merchant'] = array( 'id' => (string) $context['merchant_id'] );
		}
		if ( ! empty( $context['platform_id'] ) ) {
			$payload['platform'] = array( 'id' => (string) $context['platform_id'] );
		}

		/**
		 * Filter the charge/authorization payload before it is sent to Tap.
		 *
		 * @since 3.0.0
		 *
		 * @param array<string, mixed> $payload Request body.
		 * @param WC_Order             $order   Order being paid.
		 */
		return (array) apply_filters( 'wc_tap_transaction_payload', $payload, $order );
	}

	/**
	 * Build the body for a refund request.
	 *
	 * @param WC_Order         $order     Order being refunded.
	 * @param string           $charge_id Tap charge id.
	 * @param float|int|string $amount    Refund amount.
	 * @param string           $reason    Refund reason.
	 * @param string           $post_url  Webhook URL for refund notifications.
	 * @return array<string, mixed>
	 */
	public static function refund( WC_Order $order, string $charge_id, $amount, string $reason, string $post_url ): array {
		$currency = $order->get_currency();

		$payload = array(
			'charge_id'   => $charge_id,
			'amount'      => (float) Tap_Currency::format( $amount, $currency ),
			'currency'    => $currency,
			'description' => sprintf(
				/* translators: %s: order number. */
				__( 'Refund for order %s', 'wc-tap-gateway' ),
				$order->get_order_number()
			),
			'reason'      => '' !== trim( $reason ) ? $reason : __( 'Refund requested from WooCommerce.', 'wc-tap-gateway' ),
			'reference'   => array( 'merchant' => (string) $order->get_id() ),
			'metadata'    => array(
				'orderId'  => (string) $order->get_id(),
				'platform' => 'woocommerce',
			),
			'post'        => array( 'url' => $post_url ),
		);

		/**
		 * Filter the refund payload before it is sent to Tap.
		 *
		 * @since 3.0.0
		 *
		 * @param array<string, mixed> $payload Request body.
		 * @param WC_Order             $order   Order being refunded.
		 */
		return (array) apply_filters( 'wc_tap_refund_payload', $payload, $order );
	}

	/**
	 * Build the customer block for a transaction.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array<string, mixed>
	 */
	public static function customer( WC_Order $order ): array {
		return array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(),
			'phone'      => array(
				'country_code' => Tap_Countries::dial_code( $order->get_billing_country() ),
				'number'       => preg_replace( '/\D+/', '', (string) $order->get_billing_phone() ) ?? '',
			),
		);
	}

	/**
	 * Build the line items for a transaction.
	 *
	 * Items are taken from the order rather than the live cart. On the order-pay
	 * endpoint the cart may be empty or hold different products, which would
	 * send Tap items that do not match the amount being charged.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array<int, array<string, mixed>>
	 */
	public static function order_items( WC_Order $order ): array {
		$currency = $order->get_currency();
		$decimals = Tap_Currency::decimals( $currency );
		$items    = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$quantity = max( 1, (int) $item->get_quantity() );
			$product  = $item->get_product();

			$items[] = array(
				'name'        => $item->get_name(),
				'description' => self::describe( $product ),
				'quantity'    => $quantity,
				'currency'    => $currency,
				'amount'      => round( ( (float) $item->get_total() ) / $quantity, $decimals ),
			);
		}

		return $items;
	}

	/**
	 * Build the shipping block for a transaction.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array<string, mixed> Empty when the order has no shipping.
	 */
	public static function shipping( WC_Order $order ): array {
		$total = (float) $order->get_shipping_total();

		if ( $total <= 0 ) {
			return array();
		}

		$names = array();
		foreach ( $order->get_shipping_methods() as $method ) {
			$names[] = $method->get_name();
		}
		$label = ! empty( $names ) ? implode( ', ', $names ) : __( 'Shipping', 'wc-tap-gateway' );

		return array(
			'amount'      => round( $total, Tap_Currency::decimals( $order->get_currency() ) ),
			'currency'    => $order->get_currency(),
			'description' => $label,
			'provider'    => $label,
			'service'     => $label,
		);
	}

	/**
	 * Produce a safe, length-limited product description.
	 *
	 * mb_* functions are required: byte-based truncation splits multi-byte
	 * characters, which produces invalid UTF-8 and makes json_encode() fail for
	 * Arabic catalogues.
	 *
	 * @param WC_Product|false|null $product Product, when resolvable.
	 * @return string
	 */
	private static function describe( $product ): string {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$description = wp_strip_all_tags( (string) $product->get_short_description() );

		if ( '' === trim( $description ) ) {
			$description = wp_strip_all_tags( (string) $product->get_description() );
		}

		$description = trim( preg_replace( '/\s+/u', ' ', $description ) ?? '' );

		if ( mb_strlen( $description ) > self::MAX_DESCRIPTION_LENGTH ) {
			$description = mb_substr( $description, 0, self::MAX_DESCRIPTION_LENGTH - 1 ) . '…';
		}

		return $description;
	}

	/**
	 * The text shown on the customer's bank statement.
	 *
	 * 2.x hardcoded the literal "Sample" here, which is what customers saw on
	 * their statements.
	 *
	 * @return string
	 */
	private static function statement_descriptor(): string {
		$name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$name = preg_replace( '/[^\p{L}\p{N} \-]+/u', ' ', $name ) ?? '';
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) ?? '' );

		if ( '' === $name ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			$name = is_string( $host ) ? $host : 'Store';
		}

		return trim( mb_substr( $name, 0, self::MAX_DESCRIPTOR_LENGTH ) );
	}
}

<?php
/**
 * Validation of Tap identifiers and of the binding between a Tap transaction
 * and a WooCommerce order.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Pure validation logic, kept free of side effects so it can be unit tested.
 */
final class Tap_Validator {

	public const KIND_CHARGE    = 'charge';
	public const KIND_AUTHORIZE = 'authorize';

	/**
	 * Whether a string is a well-formed Tap transaction id.
	 *
	 * 2.x concatenated the raw query string straight into the API URL, which
	 * allowed path traversal and query injection into authenticated requests
	 * carrying the merchant's live secret key.
	 *
	 * @param string $transaction_id Candidate id.
	 * @return bool
	 */
	public static function is_valid_transaction_id( string $transaction_id ): bool {
		return 1 === preg_match( '/^(chg|auth)_[A-Za-z0-9]{1,64}$/', $transaction_id );
	}

	/**
	 * Determine whether an id refers to a charge or an authorization.
	 *
	 * The prefix is authoritative; the configured payment mode is not, because a
	 * merchant can change the setting between placing and completing an order.
	 *
	 * @param string $transaction_id Tap transaction id.
	 * @return string One of KIND_CHARGE, KIND_AUTHORIZE, or an empty string.
	 */
	public static function transaction_kind( string $transaction_id ): string {
		if ( str_starts_with( $transaction_id, 'chg_' ) ) {
			return self::KIND_CHARGE;
		}
		if ( str_starts_with( $transaction_id, 'auth_' ) ) {
			return self::KIND_AUTHORIZE;
		}
		return '';
	}

	/**
	 * Whether the transaction actually references the order being completed.
	 *
	 * Without this check, any captured transaction of the same amount and
	 * currency can be replayed against any other pending order.
	 *
	 * @param Tap_Response $transaction Verified transaction fetched from Tap.
	 * @param WC_Order     $order       Order being completed.
	 * @return bool
	 */
	public static function references_order( Tap_Response $transaction, WC_Order $order ): bool {
		$reference = $transaction->get( 'reference.order' );

		if ( null === $reference || '' === $reference ) {
			// Fall back to the transaction reference, which both the redirect
			// and popup flows also set to the order id.
			$reference = $transaction->get( 'reference.transaction' );
		}

		if ( null === $reference || '' === $reference ) {
			return false;
		}

		return (string) $reference === (string) $order->get_id();
	}

	/**
	 * Whether the transaction amount and currency match the order.
	 *
	 * @param Tap_Response $transaction Verified transaction fetched from Tap.
	 * @param WC_Order     $order       Order being completed.
	 * @return bool
	 */
	public static function amount_matches( Tap_Response $transaction, WC_Order $order ): bool {
		$order_currency       = strtoupper( $order->get_currency() );
		$transaction_currency = strtoupper( (string) $transaction->get( 'currency', '' ) );

		if ( $order_currency !== $transaction_currency ) {
			return false;
		}

		return Tap_Currency::equals(
			$order->get_total(),
			$transaction->get( 'amount', 0 ),
			$order_currency
		);
	}

	/**
	 * Whether a transaction id has already been consumed by a different order.
	 *
	 * @param string $transaction_id Tap transaction id.
	 * @param int    $order_id       Order claiming the transaction.
	 * @return bool
	 */
	public static function is_claimed_by_another_order( string $transaction_id, int $order_id ): bool {
		try {
			$orders = wc_get_orders(
				array(
					'limit'      => 2,
					'return'     => 'ids',
					'status'     => 'any',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => Tap_Order_Processor::META_COMPLETED_ID,
							'value'   => $transaction_id,
							'compare' => '=',
						),
					),
				)
			);
		} catch ( Throwable $e ) {
			// Fail open, deliberately. This is a backstop; references_order() is
			// the check that actually binds a transaction to its order. Failing
			// closed here would reject legitimate payments whenever the order
			// query breaks.
			Tap_Logger::exception(
				'Could not check whether the transaction is claimed by another order; continuing.',
				$e,
				array(
					'order'       => $order_id,
					'transaction' => $transaction_id,
				)
			);
			return false;
		}

		if ( ! is_array( $orders ) ) {
			return false;
		}

		foreach ( $orders as $existing_id ) {
			if ( (int) $existing_id !== $order_id ) {
				return true;
			}
		}

		return false;
	}
}

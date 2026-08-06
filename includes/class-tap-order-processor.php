<?php
/**
 * Applies a verified Tap transaction to a WooCommerce order.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The single place in the plugin that changes an order's status in response to
 * a payment.
 *
 * Both the webhook and the customer's return from Tap route through here, which
 * is what makes completion idempotent: 2.x duplicated this logic across
 * webhook() and tap_thank_you_page(), and the two could race and both process
 * the same order.
 *
 * The transaction passed in must already have been fetched from the Tap API. A
 * status posted to the webhook is never trusted.
 */
final class Tap_Order_Processor {

	public const META_COMPLETED_ID  = '_tap_completed_charge_id';
	public const META_CHARGE_ID     = '_tap_charge_id';
	public const META_REQUEST_ID    = '_tap_request_id';
	public const META_FAIL_MESSAGE  = '_tap_fail_message';
	public const META_LAST_VERIFIED = '_tap_last_verified';

	public const OUTCOME_PAID              = 'paid';
	public const OUTCOME_AUTHORIZED        = 'authorized';
	public const OUTCOME_DECLINED          = 'declined';
	public const OUTCOME_MISMATCH          = 'mismatch';
	public const OUTCOME_UNRELATED         = 'unrelated';
	public const OUTCOME_DUPLICATE         = 'duplicate';
	public const OUTCOME_ALREADY_PROCESSED = 'already_processed';
	public const OUTCOME_LOCKED            = 'locked';
	public const OUTCOME_ERROR             = 'error';

	/**
	 * How long the per-order lock is held, in seconds.
	 */
	private const LOCK_TTL = 30;

	/**
	 * Order being processed.
	 *
	 * @var WC_Order
	 */
	private WC_Order $order;

	/**
	 * API client used for compensating actions (refund / void).
	 *
	 * @var Tap_Api_Client
	 */
	private Tap_Api_Client $api;

	/**
	 * Webhook URL to attach to compensating refunds.
	 *
	 * @var string
	 */
	private string $post_url;

	/**
	 * Message describing why the payment failed, when it did.
	 *
	 * @var string
	 */
	private string $failure_message = '';

	/**
	 * The order's status before this transaction was applied.
	 *
	 * @var string
	 */
	private string $previous_status = '';

	/**
	 * Constructor.
	 *
	 * @param WC_Order       $order    Order being processed.
	 * @param Tap_Api_Client $api      API client.
	 * @param string         $post_url Webhook URL for compensating refunds.
	 */
	public function __construct( WC_Order $order, Tap_Api_Client $api, string $post_url = '' ) {
		$this->order    = $order;
		$this->api      = $api;
		$this->post_url = $post_url;
	}

	/**
	 * A customer-facing description of why the payment failed.
	 *
	 * @return string
	 */
	public function get_failure_message(): string {
		return $this->failure_message;
	}

	/**
	 * Apply a verified transaction to the order.
	 *
	 * @param Tap_Response $transaction Transaction fetched from the Tap API.
	 * @return string One of the OUTCOME_* constants.
	 */
	public function apply( Tap_Response $transaction ): string {
		$transaction_id = $transaction->get_string( 'id' );
		$status         = strtoupper( $transaction->get_string( 'status' ) );

		if ( ! $this->acquire_lock() ) {
			Tap_Logger::info(
				'Skipping: another request is processing this order.',
				array( 'order' => $this->order->get_id() )
			);
			return self::OUTCOME_LOCKED;
		}

		try {
			// Re-read the order so the status reflects anything written by the
			// request that held the lock before us.
			$fresh = wc_get_order( $this->order->get_id() );
			if ( $fresh instanceof WC_Order ) {
				$this->order = $fresh;
			}

			$completed = (string) $this->order->get_meta( self::META_COMPLETED_ID );

			if ( '' !== $completed ) {
				if ( $completed === $transaction_id ) {
					Tap_Logger::debug(
						'Transaction already applied to this order.',
						array( 'order' => $this->order->get_id() )
					);
					return self::OUTCOME_ALREADY_PROCESSED;
				}

				// A different transaction is being applied to an order that has
				// already been settled. Never silently overwrite the first one.
				Tap_Logger::warning(
					'Order is already settled by a different transaction; ignoring.',
					array(
						'order'       => $this->order->get_id(),
						'settled_by'  => $completed,
						'transaction' => $transaction_id,
					)
				);
				return self::OUTCOME_DUPLICATE;
			}

			// The transaction must reference the order it is being applied to.
			// Without this, any captured transaction of the same amount and
			// currency can be replayed against any other pending order.
			if ( ! Tap_Validator::references_order( $transaction, $this->order ) ) {
				Tap_Logger::warning(
					'Transaction does not reference this order; refusing to apply it.',
					array(
						'order'       => $this->order->get_id(),
						'transaction' => $transaction_id,
						'reference'   => $transaction->get_string( 'reference.order' ),
					)
				);
				$this->failure_message = __( 'We could not verify this payment against your order.', 'wc-tap-gateway' );
				return self::OUTCOME_UNRELATED;
			}

			if ( Tap_Validator::is_claimed_by_another_order( $transaction_id, $this->order->get_id() ) ) {
				Tap_Logger::warning(
					'Transaction has already been used to pay a different order.',
					array(
						'order'       => $this->order->get_id(),
						'transaction' => $transaction_id,
					)
				);
				$this->failure_message = __( 'We could not verify this payment against your order.', 'wc-tap-gateway' );
				return self::OUTCOME_DUPLICATE;
			}

			$this->order->update_meta_data( self::META_LAST_VERIFIED, (string) time() );

			// Remembered so a payment that lands on an order WooCommerce had
			// already cancelled or failed is called out, rather than quietly
			// flipping the order to processing.
			$this->previous_status = $this->order->get_status();

			if ( 'CAPTURED' !== $status && 'AUTHORIZED' !== $status ) {
				return $this->handle_declined( $transaction );
			}

			if ( ! Tap_Validator::amount_matches( $transaction, $this->order ) ) {
				return $this->handle_mismatch( $transaction, $status );
			}

			return 'CAPTURED' === $status
				? $this->handle_captured( $transaction )
				: $this->handle_authorized( $transaction );
		} catch ( Throwable $e ) {
			// Nothing thrown from here may escape: this runs inside both the
			// webhook and the customer's return from Tap. The caller turns
			// OUTCOME_ERROR into a retryable response, so Tap tries again.
			Tap_Logger::exception(
				'Failed to apply a Tap transaction to an order.',
				$e,
				array(
					'order'       => $this->order->get_id(),
					'transaction' => $transaction_id,
				)
			);

			$this->failure_message = $e instanceof Tap_Exception
				? $e->get_customer_message()
				: __( 'Something went wrong while confirming your payment. If you were charged, your order will update shortly.', 'wc-tap-gateway' );

			$this->note_safely(
				sprintf(
					/* translators: %s: error message. */
					__( 'Tap could not finish processing this payment automatically: %s. Check the transaction in your Tap dashboard before dispatching.', 'wc-tap-gateway' ),
					$e->getMessage()
				)
			);

			return self::OUTCOME_ERROR;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Save the order, reporting rather than propagating a failure.
	 *
	 * WC_Order::save() can throw from the data store or from any of the hooks
	 * that fire during it.
	 *
	 * @param string $stage What was being saved, for the log.
	 * @return bool True when the save succeeded.
	 */
	private function save_safely( string $stage ): bool {
		try {
			$this->order->save();
			return true;
		} catch ( Throwable $e ) {
			Tap_Logger::exception(
				'Could not save the order.',
				$e,
				array(
					'order' => $this->order->get_id(),
					'stage' => $stage,
				)
			);
			return false;
		}
	}

	/**
	 * Add an order note, reporting rather than propagating a failure.
	 *
	 * @param string $note Note text.
	 */
	private function note_safely( string $note ): void {
		try {
			$this->order->add_order_note( esc_html( $note ) );
		} catch ( Throwable $e ) {
			Tap_Logger::exception(
				'Could not add an order note.',
				$e,
				array( 'order' => $this->order->get_id() )
			);
		}
	}

	/**
	 * Complete the order for a captured payment.
	 *
	 * @param Tap_Response $transaction Verified transaction.
	 * @return string
	 */
	private function handle_captured( Tap_Response $transaction ): string {
		$transaction_id = $transaction->get_string( 'id' );

		// payment_complete() must be called while the order is still in one of
		// the statuses listed by woocommerce_valid_order_statuses_for_payment_complete
		// (on-hold, pending, failed, cancelled by default). 2.x called
		// update_status('processing') first, which put the order outside that
		// list and turned payment_complete() into a no-op: no transaction id,
		// no date_paid, and no woocommerce_payment_complete for integrations.
		$completable = apply_filters(
			'woocommerce_valid_order_statuses_for_payment_complete',
			array( 'on-hold', 'pending', 'failed', 'cancelled' ),
			$this->order
		);

		$completable_status = $this->order->has_status( (array) $completable );

		if ( ! $completable_status ) {
			// Not fatal, but payment_complete() is about to do nothing, so make
			// that visible rather than letting it fail silently as it did in 2.x.
			Tap_Logger::warning(
				'Order status does not allow payment completion; the paid date and completion hooks will be skipped.',
				array(
					'order'  => $this->order->get_id(),
					'status' => $this->order->get_status(),
				)
			);
			$this->note_safely(
				sprintf(
					/* translators: %s: current order status. */
					__( 'Tap confirmed this payment, but the order was already in the "%s" status, so WooCommerce skipped the payment-complete step. Verify the order before dispatching.', 'wc-tap-gateway' ),
					$this->order->get_status()
				)
			);
		}

		try {
			// WC_Order::payment_complete() catches Exception internally but not
			// Error, so a TypeError raised by anything hooked to
			// woocommerce_payment_complete would otherwise escape into the
			// webhook or the thank-you page.
			$this->order->payment_complete( $transaction_id );
		} catch ( Throwable $e ) {
			Tap_Logger::exception(
				'payment_complete() raised while settling a captured payment.',
				$e,
				array(
					'order'       => $this->order->get_id(),
					'transaction' => $transaction_id,
				)
			);

			// The throwable may have come from a hook that runs before the order
			// was saved, or from one that runs after. Re-read the order and let
			// its actual state decide, rather than assuming either way.
			if ( ! $this->order_reached_paid_state() ) {
				$this->note_safely(
					sprintf(
						/* translators: %s: Tap transaction id. */
						__( 'Tap captured payment %s but this order could not be completed. The money HAS been taken. Complete the order manually.', 'wc-tap-gateway' ),
						$transaction_id
					)
				);

				// Deliberately not recording the completed-id here: doing so
				// would make the order permanently un-retryable.
				throw new Tap_Order_Exception(
					'Order could not be completed after a captured payment.',
					array(
						'order'       => $this->order->get_id(),
						'transaction' => $transaction_id,
					),
					'',
					$e
				);
			}
		}

		// Recorded only once completion has actually happened, so a failure
		// part-way through does not leave the order flagged as settled and
		// therefore un-retryable.
		$this->order->update_meta_data( self::META_COMPLETED_ID, $transaction_id );
		$this->order->update_meta_data( self::META_CHARGE_ID, $transaction_id );
		$this->note_safely_html( $this->build_note( __( 'Tap payment captured.', 'wc-tap-gateway' ), $transaction ) );
		$this->note_rescued_from_previous_status();
		$this->save_safely( 'captured' );

		Tap_Logger::info(
			'Order paid.',
			array(
				'order'       => $this->order->get_id(),
				'transaction' => $transaction_id,
			)
		);

		return self::OUTCOME_PAID;
	}

	/**
	 * Flag an order that was paid after it had already been cancelled or failed.
	 *
	 * Most often WooCommerce's hold-stock cron cancelled the order while the
	 * customer was still completing payment. The order is legitimately paid,
	 * but stock and fulfilment were unwound in the meantime, so it should not
	 * pass silently.
	 */
	private function note_rescued_from_previous_status(): void {
		if ( ! in_array( $this->previous_status, array( 'cancelled', 'failed' ), true ) ) {
			return;
		}

		Tap_Logger::warning(
			'A payment settled an order that had already been cancelled or failed.',
			array(
				'order'           => $this->order->get_id(),
				'previous_status' => $this->previous_status,
			)
		);

		$this->note_safely(
			sprintf(
				/* translators: %s: the status the order held before the payment was confirmed. */
				__( 'This order was in the "%s" status when Tap confirmed the payment, so it has been restored. Check stock and fulfilment before dispatching.', 'wc-tap-gateway' ),
				$this->previous_status
			)
		);
	}

	/**
	 * Whether the order actually reached a paid state.
	 *
	 * Re-reads the order from the data store, so it reflects what was really
	 * persisted rather than what the in-memory object believes.
	 *
	 * @return bool
	 */
	private function order_reached_paid_state(): bool {
		try {
			$fresh = wc_get_order( $this->order->get_id() );

			if ( ! $fresh instanceof WC_Order ) {
				return false;
			}

			$this->order = $fresh;

			// payment_complete() sets the paid date in the branch that actually
			// completes the order, so it is the reliable signal.
			return null !== $fresh->get_date_paid( 'edit' ) || $fresh->is_paid();
		} catch ( Throwable $e ) {
			Tap_Logger::exception(
				'Could not re-read the order to confirm its paid state.',
				$e,
				array( 'order' => $this->order->get_id() )
			);
			return false;
		}
	}

	/**
	 * Change the order status, reporting rather than propagating a failure.
	 *
	 * WC_Order::update_status() catches Exception internally and returns false,
	 * but Errors raised by status-transition hooks still escape.
	 *
	 * @param string $status New status.
	 * @param string $note   Note recorded with the transition.
	 * @return bool True when the transition succeeded.
	 */
	private function update_status_safely( string $status, string $note ): bool {
		try {
			$changed = $this->order->update_status( $status, $note );

			if ( false === $changed ) {
				Tap_Logger::error(
					'WooCommerce refused the status transition.',
					array(
						'order'  => $this->order->get_id(),
						'status' => $status,
					)
				);
				return false;
			}

			return true;
		} catch ( Throwable $e ) {
			Tap_Logger::exception(
				'Status transition raised.',
				$e,
				array(
					'order'  => $this->order->get_id(),
					'status' => $status,
				)
			);
			return false;
		}
	}

	/**
	 * Add a pre-escaped order note, reporting rather than propagating a failure.
	 *
	 * @param string $note Note text, already escaped by build_note().
	 */
	private function note_safely_html( string $note ): void {
		try {
			$this->order->add_order_note( $note );
		} catch ( Throwable $e ) {
			Tap_Logger::exception(
				'Could not add an order note.',
				$e,
				array( 'order' => $this->order->get_id() )
			);
		}
	}

	/**
	 * Put the order on hold for an authorized (not yet captured) payment.
	 *
	 * @param Tap_Response $transaction Verified transaction.
	 * @return string
	 */
	private function handle_authorized( Tap_Response $transaction ): string {
		$transaction_id = $transaction->get_string( 'id' );

		try {
			// WC setters raise WC_Data_Exception on values they reject.
			$this->order->set_transaction_id( $transaction_id );
		} catch ( Throwable $e ) {
			Tap_Logger::exception(
				'Could not set the transaction id on the order.',
				$e,
				array(
					'order'       => $this->order->get_id(),
					'transaction' => $transaction_id,
				)
			);
		}

		$this->order->update_meta_data( self::META_COMPLETED_ID, $transaction_id );
		$this->order->update_meta_data( self::META_CHARGE_ID, $transaction_id );

		// "on-hold" rather than "pending": WooCommerce's cancel-unpaid-orders
		// cron cancels pending orders once the hold-stock window elapses, which
		// in 2.x silently cancelled every successful authorization.
		if ( ! $this->update_status_safely( 'on-hold', $this->build_note( __( 'Tap payment authorized, awaiting capture.', 'wc-tap-gateway' ), $transaction ) ) ) {
			throw new Tap_Order_Exception(
				'Order could not be moved to on-hold after an authorization.',
				array(
					'order'       => $this->order->get_id(),
					'transaction' => $transaction_id,
				)
			);
		}

		$this->save_safely( 'authorized' );

		// update_status( 'on-hold' ) triggers wc_maybe_reduce_stock_levels(),
		// which is guarded by the _order_stock_reduced flag. No explicit stock
		// call belongs here.
		Tap_Logger::info(
			'Order authorized.',
			array(
				'order'       => $this->order->get_id(),
				'transaction' => $transaction_id,
			)
		);

		return self::OUTCOME_AUTHORIZED;
	}

	/**
	 * Mark the order failed for a declined payment.
	 *
	 * @param Tap_Response $transaction Verified transaction.
	 * @return string
	 */
	private function handle_declined( Tap_Response $transaction ): string {
		$reason = $transaction->get_string( 'response.message' );

		if ( '' === $reason ) {
			$reason = __( 'The payment was not completed.', 'wc-tap-gateway' );
		}

		$this->failure_message = $reason;

		$this->order->update_meta_data( self::META_FAIL_MESSAGE, $reason );
		$this->order->update_meta_data( self::META_CHARGE_ID, $transaction->get_string( 'id' ) );

		// "failed" rather than "cancelled": WooCommerce reserves "cancelled" for
		// a customer or administrator cancelling, and "failed" for a payment
		// that was attempted and rejected. Failed orders can also be retried.
		$this->update_status_safely(
			'failed',
			$this->build_note(
				sprintf(
					/* translators: %s: decline reason returned by Tap. */
					__( 'Tap payment failed: %s', 'wc-tap-gateway' ),
					$reason
				),
				$transaction
			)
		);
		$this->save_safely( 'declined' );

		Tap_Logger::info(
			'Order payment declined.',
			array(
				'order'       => $this->order->get_id(),
				'transaction' => $transaction->get_string( 'id' ),
				'status'      => $transaction->get_string( 'status' ),
			)
		);

		return self::OUTCOME_DECLINED;
	}

	/**
	 * Reverse a payment whose amount or currency does not match the order.
	 *
	 * @param Tap_Response $transaction Verified transaction.
	 * @param string       $status      Uppercased transaction status.
	 * @return string
	 */
	private function handle_mismatch( Tap_Response $transaction, string $status ): string {
		$transaction_id = $transaction->get_string( 'id' );

		Tap_Logger::error(
			'Amount or currency mismatch; reversing the payment.',
			array(
				'order'                => $this->order->get_id(),
				'transaction'          => $transaction_id,
				'order_total'          => $this->order->get_total(),
				'order_currency'       => $this->order->get_currency(),
				'transaction_amount'   => $transaction->get_string( 'amount' ),
				'transaction_currency' => $transaction->get_string( 'currency' ),
			)
		);

		$reversal = __( 'no reversal attempted', 'wc-tap-gateway' );

		if ( 'CAPTURED' === $status ) {
			$response = $this->api->create_refund(
				Tap_Request_Builder::refund(
					$this->order,
					$transaction_id,
					(float) $transaction->get( 'amount', 0 ),
					__( 'Order amount and payment amount do not match.', 'wc-tap-gateway' ),
					$this->post_url
				)
			);
			$reversal = $response->is_success()
				? sprintf(
					/* translators: %s: Tap refund id. */
					__( 'refunded (refund ID %s)', 'wc-tap-gateway' ),
					$response->get_string( 'id' )
				)
				: sprintf(
					/* translators: %s: error message from Tap. */
					__( 'refund FAILED (%s) — reverse this payment manually', 'wc-tap-gateway' ),
					$response->get_error_message()
				);
		} elseif ( 'AUTHORIZED' === $status ) {
			$response = $this->api->void_authorization( $transaction_id );
			$reversal = $response->is_success()
				? __( 'authorization voided', 'wc-tap-gateway' )
				: sprintf(
					/* translators: %s: error message from Tap. */
					__( 'void FAILED (%s) — reverse this authorization manually', 'wc-tap-gateway' ),
					$response->get_error_message()
				);
		}

		$this->failure_message = __( 'We could not verify the payment amount for your order.', 'wc-tap-gateway' );

		$this->order->update_meta_data( self::META_FAIL_MESSAGE, $this->failure_message );
		$this->update_status_safely(
			'failed',
			$this->build_note(
				sprintf(
					/* translators: 1: order total, 2: amount taken by Tap, 3: outcome of the reversal attempt. */
					__( 'Tap payment rejected: order total %1$s does not match the payment of %2$s — %3$s.', 'wc-tap-gateway' ),
					$this->order->get_total() . ' ' . $this->order->get_currency(),
					$transaction->get_string( 'amount' ) . ' ' . $transaction->get_string( 'currency' ),
					$reversal
				),
				$transaction
			)
		);
		$this->save_safely( 'mismatch' );

		return self::OUTCOME_MISMATCH;
	}

	/**
	 * Build an order note describing a transaction.
	 *
	 * Every interpolated value comes from the Tap API and is escaped, because
	 * order notes are rendered in wp-admin.
	 *
	 * @param string       $headline    Leading sentence.
	 * @param Tap_Response $transaction Transaction being described.
	 * @return string
	 */
	private function build_note( string $headline, Tap_Response $transaction ): string {
		$rows = array(
			__( 'Transaction ID', 'wc-tap-gateway' ) => $transaction->get_string( 'id' ),
			__( 'Payment method', 'wc-tap-gateway' ) => $transaction->get_string( 'source.payment_method' ),
			__( 'Payment reference', 'wc-tap-gateway' ) => $transaction->get_string( 'reference.payment' ),
			__( 'Status', 'wc-tap-gateway' )         => $transaction->get_string( 'status' ),
		);

		$note = esc_html( $headline );

		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$note .= '<br>' . esc_html( $label ) . ': ' . esc_html( $value );
		}

		return $note;
	}

	/**
	 * Take the per-order processing lock.
	 *
	 * @return bool True when the lock was acquired.
	 */
	private function acquire_lock(): bool {
		$key = $this->lock_key();

		try {
			// add_option() with autoload off is atomic at the database level,
			// which a get/set transient pair is not.
			if ( ! add_option( $key, (string) time(), '', 'no' ) ) {
				$held = (int) get_option( $key, 0 );

				if ( $held > 0 && ( time() - $held ) < self::LOCK_TTL ) {
					return false;
				}

				// Stale lock left behind by a request that died mid-flight.
				update_option( $key, (string) time(), false );
			}

			return true;
		} catch ( Throwable $e ) {
			// Fail open. The lock is an optimisation against concurrent
			// webhook and browser returns; the completed-transaction check in
			// apply() is what actually guarantees idempotency, so refusing to
			// process would be worse than processing without the lock.
			Tap_Logger::exception(
				'Could not acquire the order processing lock; continuing without it.',
				$e,
				array( 'order' => $this->order->get_id() )
			);
			return true;
		}
	}

	/**
	 * Release the per-order processing lock.
	 *
	 * Runs in a finally block, so it must never throw: doing so would replace
	 * the original exception with this one.
	 */
	private function release_lock(): void {
		try {
			delete_option( $this->lock_key() );
		} catch ( Throwable $e ) {
			Tap_Logger::exception(
				'Could not release the order processing lock; it will expire.',
				$e,
				array( 'order' => $this->order->get_id() )
			);
		}
	}

	/**
	 * Option name used for the per-order lock.
	 *
	 * @return string
	 */
	private function lock_key(): string {
		return 'tap_lock_' . $this->order->get_id();
	}
}

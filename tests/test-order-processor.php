<?php
/**
 * Order settlement: security guards, idempotency and exception safety.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

TapTest::group( 'Exception hierarchy' );

$e = new Tap_Exception( 'internal detail sk_live_secret', array( 'order' => 7 ) );
TapTest::is( 'context is carried', $e->get_context(), array( 'order' => 7 ) );
TapTest::not_ok( 'the customer message never leaks getMessage()', str_contains( $e->get_customer_message(), 'sk_live' ) );
TapTest::is( 'a supplied customer message is honoured', ( new Tap_Exception( 'x', array(), 'Try another card.' ) )->get_customer_message(), 'Try another card.' );
TapTest::ok( 'configuration errors have their own wording', str_contains( ( new Tap_Configuration_Exception( 'x' ) )->get_customer_message(), 'not available' ) );
TapTest::ok( 'is a Throwable', $e instanceof Throwable );

$api_error = Tap_Api_Exception::from_response( Tap_Response::error( 503, array( 'message' => 'upstream down' ) ), 'Charge creation' );
TapTest::is( 'message names the operation', $api_error->getMessage(), 'Charge creation failed: upstream down' );
TapTest::is( 'status is exposed', $api_error->get_status_code(), 503 );
TapTest::ok( '503 is retryable', $api_error->is_retryable() );
TapTest::not_ok( '400 is not retryable', Tap_Api_Exception::from_response( Tap_Response::error( 400, array( 'message' => 'bad' ) ), 'x' )->is_retryable() );
TapTest::ok( 'a transport failure is retryable', Tap_Api_Exception::from_response( Tap_Response::from_wp_error( new WP_Error( 'http', 'timeout' ) ), 'x' )->is_retryable() );

TapTest::group( 'Settlement: happy path' );

$order = tap_test_fresh_order();
TapTest::is( 'a matching capture settles the order', tap_test_processor( $order )->apply( tap_test_transaction() ), Tap_Order_Processor::OUTCOME_PAID );
TapTest::is( 'payment_complete() was called exactly once', $order->payment_complete_calls, 1 );
TapTest::is( 'the transaction id is recorded', $order->meta[ Tap_Order_Processor::META_COMPLETED_ID ], 'chg_abc123' );
TapTest::is( 'the processing lock is released', $GLOBALS['tap_test_options'], array() );

TapTest::group( 'Settlement: security guards' );

$order = tap_test_fresh_order();
TapTest::is(
	'a transaction referencing another order is refused',
	tap_test_processor( $order )->apply( tap_test_transaction( array( 'reference' => array( 'order' => '999' ) ) ) ),
	Tap_Order_Processor::OUTCOME_UNRELATED
);
TapTest::is( 'the order is left untouched', $order->status, 'pending' );

$order = tap_test_fresh_order();
$order->meta[ Tap_Order_Processor::META_COMPLETED_ID ] = 'chg_abc123';
TapTest::is( 'replaying the same transaction is idempotent', tap_test_processor( $order )->apply( tap_test_transaction() ), Tap_Order_Processor::OUTCOME_ALREADY_PROCESSED );
TapTest::is( 'and does not settle again', $order->payment_complete_calls, 0 );

$order = tap_test_fresh_order();
$order->meta[ Tap_Order_Processor::META_COMPLETED_ID ] = 'chg_other';
TapTest::is( 'a second transaction on a settled order is refused', tap_test_processor( $order )->apply( tap_test_transaction() ), Tap_Order_Processor::OUTCOME_DUPLICATE );

$order = tap_test_fresh_order();
$GLOBALS['tap_test_options']['tap_lock_42'] = (string) time();
TapTest::is( 'a concurrent request backs off', tap_test_processor( $order )->apply( tap_test_transaction() ), Tap_Order_Processor::OUTCOME_LOCKED );

TapTest::group( 'Settlement: declines and mismatches' );

$order = tap_test_fresh_order();
$outcome = tap_test_processor( $order )->apply( tap_test_transaction( array( 'status' => 'DECLINED', 'response' => array( 'message' => 'Insufficient funds' ) ) ) );
TapTest::is( 'a decline is recorded', $outcome, Tap_Order_Processor::OUTCOME_DECLINED );
TapTest::is( 'the order fails rather than cancels', $order->status, 'failed' );
TapTest::is( 'the reason is stored for the customer', $order->meta[ Tap_Order_Processor::META_FAIL_MESSAGE ], 'Insufficient funds' );

$order = tap_test_fresh_order();
TapTest::is( 'an amount mismatch is reversed', tap_test_processor( $order )->apply( tap_test_transaction( array( 'amount' => '0.100' ) ) ), Tap_Order_Processor::OUTCOME_MISMATCH );
TapTest::is( 'and the order fails', $order->status, 'failed' );
TapTest::ok( 'a failed reversal is flagged for manual action', (bool) preg_grep( '/manually/i', $order->notes ) );

TapTest::group( 'Settlement: exception safety' );

$order = tap_test_fresh_order();
$order->payment_complete_behaviour = function () { throw new TypeError( 'a hook blew up' ); };
TapTest::is( 'an Error during completion is caught', tap_test_processor( $order )->apply( tap_test_transaction() ), Tap_Order_Processor::OUTCOME_ERROR );
TapTest::not_ok( 'the order is not flagged settled, so it stays retryable', isset( $order->meta[ Tap_Order_Processor::META_COMPLETED_ID ] ) );
TapTest::ok( 'the merchant is warned that money was taken', (bool) preg_grep( '/money HAS been taken/i', $order->notes ) );
TapTest::is( 'the lock is still released', $GLOBALS['tap_test_options'], array() );

$order = tap_test_fresh_order();
$order->payment_complete_behaviour = function ( $o ) { $o->paid = true; throw new TypeError( 'a post-save hook blew up' ); };
TapTest::is( 'a throwable after the order was paid still counts as paid', tap_test_processor( $order )->apply( tap_test_transaction() ), Tap_Order_Processor::OUTCOME_PAID );
TapTest::is( 'and the transaction id is recorded', $order->meta[ Tap_Order_Processor::META_COMPLETED_ID ], 'chg_abc123' );

$order = tap_test_fresh_order();
$order->save_behaviour = function () { throw new RuntimeException( 'db gone' ); };
$threw   = false;
$outcome = null;
try {
	$outcome = tap_test_processor( $order )->apply( tap_test_transaction() );
} catch ( Throwable $t ) {
	$threw = true;
}
TapTest::not_ok( 'a failing save() does not propagate', $threw );
TapTest::ok( 'a defined outcome is still returned', in_array( $outcome, array( Tap_Order_Processor::OUTCOME_PAID, Tap_Order_Processor::OUTCOME_ERROR ), true ) );

exit( TapTest::summary() );

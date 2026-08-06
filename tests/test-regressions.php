<?php
/**
 * Regressions from the 2.x gateway that must not return.
 *
 * Each case names the original defect so a future change that reintroduces it
 * fails here rather than in production.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once TAP_GATEWAY_PATH . 'includes/class-tap-return-handler.php';

TapTest::group( '2.x: a charge returning on an auto-cancelled order was discarded' );

// WooCommerce's hold-stock cron cancels unpaid orders on a timer, and can fire
// while the customer is still paying. 2.x sent any cancelled order straight to
// the failure page without looking up the transaction: customer charged, no
// transaction id recorded anywhere.
$order         = tap_test_fresh_order();
$order->status = 'cancelled';

TapTest::not_ok( 'a cancelled order is NOT treated as settled', Tap_Return_Handler::is_settled( $order ) );

$outcome = tap_test_processor( $order )->apply( tap_test_transaction() );
TapTest::is( 'the payment is applied instead of discarded', $outcome, Tap_Order_Processor::OUTCOME_PAID );
TapTest::is( 'the transaction id IS recorded on the order', $order->meta[ Tap_Order_Processor::META_COMPLETED_ID ], 'chg_abc123' );
TapTest::ok(
	'the merchant is told the order was restored from cancelled',
	(bool) preg_grep( '/restored/i', $order->notes )
);

$order         = tap_test_fresh_order();
$order->status = 'failed';
TapTest::not_ok( 'a failed order is NOT treated as settled either', Tap_Return_Handler::is_settled( $order ) );
TapTest::is( 'and its payment is applied', tap_test_processor( $order )->apply( tap_test_transaction() ), Tap_Order_Processor::OUTCOME_PAID );

TapTest::group( 'is_settled: genuinely settled orders skip re-verification' );

foreach ( array( 'processing', 'completed', 'on-hold', 'refunded' ) as $status ) {
	$order         = tap_test_fresh_order();
	$order->status = $status;
	TapTest::ok( sprintf( '"%s" is settled', $status ), Tap_Return_Handler::is_settled( $order ) );
}

$order         = tap_test_fresh_order();
$order->status = 'pending';
TapTest::not_ok( '"pending" is not settled', Tap_Return_Handler::is_settled( $order ) );

// Our own marker settles an order regardless of the status a third party set.
$order         = tap_test_fresh_order();
$order->status = 'pending';
$order->meta[ Tap_Order_Processor::META_COMPLETED_ID ] = 'chg_abc123';
TapTest::ok( 'a recorded transaction id counts as settled', Tap_Return_Handler::is_settled( $order ) );

TapTest::group( '2.x: line items were built from the live cart, not the order' );

// On the order-pay endpoint the cart can be empty or hold different products,
// so Tap received items that did not match the amount being charged.
$builder_source = (string) file_get_contents( TAP_GATEWAY_PATH . 'includes/api/class-tap-request-builder.php' );
TapTest::ok( 'the request builder reads the order', str_contains( $builder_source, '$order->get_items()' ) );
TapTest::not_ok( 'the request builder never touches the cart', str_contains( $builder_source, 'WC()->cart' ) );

$renderer_source = (string) file_get_contents( TAP_GATEWAY_PATH . 'includes/class-tap-receipt-renderer.php' );
TapTest::not_ok( 'the receipt renderer never touches the cart', str_contains( $renderer_source, 'WC()->cart' ) );

TapTest::group( '2.x: browser-side failures were invisible' );

$checkout_js = (string) file_get_contents( TAP_GATEWAY_PATH . 'assets/js/tap-checkout.js' );

// onError only wrote to console.log, so a failed payment looked to the customer
// like the popup had closed by itself.
TapTest::ok( 'onError reports to the server', str_contains( $checkout_js, 'reportError( error )' ) );
TapTest::ok( 'onError routes the customer into the failure flow', (bool) preg_match( '/onError:.{0,400}failover\(\)/s', $checkout_js ) );
TapTest::not_ok( 'no bare console.log survives', (bool) preg_match( '/(?<!\/\/ )console\.log\(/', $checkout_js ) );

// An unguarded read of window.TapSDKs threw and left a permanent spinner when
// the CDN script failed to load or hung.
TapTest::ok( 'the SDK is polled with a timeout', str_contains( $checkout_js, 'Date.now() - start >= SDK_TIMEOUT' ) );
TapTest::ok( 'the timeout fails over rather than hanging', str_contains( $checkout_js, 'waitForSdk( render, failover )' ) );
TapTest::ok( 'every SDK read is guarded by a typeof check', 2 === preg_match_all( '/window\.TapSDKs && typeof window\.TapSDKs\.renderCheckoutElement === \x27function\x27/', $checkout_js ) );
TapTest::ok( 'the render call is wrapped in try/catch', (bool) preg_match( '/try \{\s*element = window\.TapSDKs\.renderCheckoutElement/s', $checkout_js ) );
TapTest::ok( 'a render watchdog exists', str_contains( $checkout_js, 'renderWatchdog = window.setTimeout' ) );

TapTest::group( '3.0.x: the popup post URL must stay a bare string' );

// Sending { url: … } here makes Tap reject the charge with "Request values are
// empty" and registers no webhook, so popup orders never settle.
TapTest::ok( 'post is sent as a string', (bool) preg_match( '/post: config\.postUrl,/', $checkout_js ) );
TapTest::not_ok( 'post is not sent as an object', (bool) preg_match( '/post: \{\s*url:/s', $checkout_js ) );

exit( TapTest::summary() );

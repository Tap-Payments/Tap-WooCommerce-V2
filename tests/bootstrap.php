<?php
/**
 * Minimal WordPress/WooCommerce stubs for the standalone unit tests.
 *
 * These tests deliberately do not require a WordPress installation: they cover
 * the plugin's pure logic (currency precision, identifier validation, signature
 * generation, response parsing, order state transitions) so they can run in CI
 * in under a second.
 *
 * Anything that genuinely needs WordPress — hook ordering, script registration,
 * template rendering — is NOT covered here and must be verified against a real
 * install. See tests/README.md.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

define( 'ABSPATH', '/tmp/wp/' );
define( 'TAP_GATEWAY_PATH', dirname( __DIR__ ) . '/' );
define( 'TAP_GATEWAY_URL', 'https://example.test/wp-content/plugins/tap/' );

if ( ! defined( 'TAP_GATEWAY_VERSION' ) ) {
	define( 'TAP_GATEWAY_VERSION', '3.0.0' );
}

$GLOBALS['tap_test_options']   = array();
$GLOBALS['tap_test_blogname']  = 'Test Store';
$GLOBALS['tap_current_order']  = null;

// ---------------------------------------------------------------- WP stubs --

function apply_filters( $tag, $value ) { return $value; }
function __( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text, $domain = null ) { return $text; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function get_bloginfo( $key ) { return $GLOBALS['tap_test_blogname']; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function absint( $value ) { return abs( (int) $value ); }

function get_option( $key, $default = false ) { return $GLOBALS['tap_test_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['tap_test_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['tap_test_options'][ $key ] ); return true; }
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $key, $GLOBALS['tap_test_options'] ) ) { return false; }
	$GLOBALS['tap_test_options'][ $key ] = $value;
	return true;
}

function wc_get_order( $id ) { return $GLOBALS['tap_current_order'] ?? false; }
function wc_get_orders( $args ) { return array(); }

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}

class WC_Product {
	public function __construct( private string $short = '', private string $long = '' ) {}
	public function get_short_description() { return $this->short; }
	public function get_description() { return $this->long; }
}

/**
 * Configurable order double.
 *
 * Behaviour hooks let a test make save() or payment_complete() throw, which is
 * how the exception-handling paths are exercised.
 */
class WC_Order {
	public array $meta                     = array();
	public array $notes                    = array();
	public string $status                  = 'pending';
	public bool $paid                      = false;
	public $payment_complete_behaviour     = null;
	public $save_behaviour                 = null;
	public int $payment_complete_calls     = 0;

	public function __construct( private int $id = 42, private string $currency = 'KWD', private float $total = 10.5 ) {}

	public function get_id(): int { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_currency(): string { return $this->currency; }
	public function get_total() { return $this->total; }
	public function get_status(): string { return $this->status; }
	public function has_status( $status ): bool { return in_array( $this->status, (array) $status, true ); }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function set_transaction_id( $id ) { $this->meta['_txn'] = $id; }
	public function get_transaction_id() { return $this->meta['_txn'] ?? ''; }
	public function get_date_paid( $context = 'view' ) { return $this->paid ? 'now' : null; }
	public function is_paid(): bool { return $this->paid; }
	public function get_items() { return array(); }
	public function get_payment_method() { return 'tap'; }

	public function save() {
		if ( is_callable( $this->save_behaviour ) ) { ( $this->save_behaviour )(); }
		return $this->id;
	}

	public function payment_complete( $transaction_id = '' ) {
		$this->payment_complete_calls++;
		if ( is_callable( $this->payment_complete_behaviour ) ) { ( $this->payment_complete_behaviour )( $this ); }
		return true;
	}

	public function update_status( $status, $note = '' ) {
		$this->status  = $status;
		$this->notes[] = $note;
		return true;
	}
}

// ------------------------------------------------------------ plugin files --

foreach ( array(
	'includes/class-tap-logger.php',
	'includes/class-tap-currency.php',
	'includes/class-tap-countries.php',
	'includes/api/class-tap-response.php',
	'includes/class-tap-exceptions.php',
	'includes/class-tap-signature.php',
	'includes/class-tap-validator.php',
	'includes/api/class-tap-api-client.php',
	'includes/api/class-tap-request-builder.php',
	'includes/class-tap-order-processor.php',
) as $file ) {
	require_once TAP_GATEWAY_PATH . $file;
}

// ------------------------------------------------------------ tiny harness --

final class TapTest {
	private static int $passed = 0;
	private static int $failed = 0;
	private static array $failures = array();

	public static function group( string $name ): void {
		echo "\n" . $name . "\n";
	}

	public static function is( string $label, $actual, $expected ): void {
		self::record( $label, $actual === $expected, $actual, $expected );
	}

	public static function ok( string $label, $actual ): void {
		self::record( $label, true === $actual, $actual, true );
	}

	public static function not_ok( string $label, $actual ): void {
		self::record( $label, false === $actual, $actual, false );
	}

	private static function record( string $label, bool $passed, $actual, $expected ): void {
		if ( $passed ) {
			self::$passed++;
			printf( "  ok   %s\n", $label );
			return;
		}
		self::$failed++;
		self::$failures[] = $label;
		printf( "  FAIL %s\n         got %s, want %s\n", $label, var_export( $actual, true ), var_export( $expected, true ) );
	}

	public static function summary(): int {
		printf( "\n%d passed, %d failed\n", self::$passed, self::$failed );
		if ( self::$failed > 0 ) {
			echo "Failed: " . implode( '; ', self::$failures ) . "\n";
		}
		return self::$failed > 0 ? 1 : 0;
	}
}

/**
 * Reset global state between test groups.
 */
function tap_test_fresh_order( string $currency = 'KWD', float $total = 10.5 ): WC_Order {
	$GLOBALS['tap_test_options'] = array();
	$order                       = new WC_Order( 42, $currency, $total );
	$GLOBALS['tap_current_order'] = $order;
	return $order;
}

/**
 * A processor wired to an API client that never touches the network.
 *
 * An empty secret key makes Tap_Api_Client short-circuit before any HTTP call.
 */
function tap_test_processor( WC_Order $order ): Tap_Order_Processor {
	return new Tap_Order_Processor( $order, new Tap_Api_Client( '' ), 'https://example.test/wc-api/tap_webhook' );
}

/**
 * Build a Tap transaction response for tests.
 *
 * @param array<string, mixed> $overrides Fields to override.
 */
function tap_test_transaction( array $overrides = array() ): Tap_Response {
	return Tap_Response::success(
		200,
		array_merge(
			array(
				'id'        => 'chg_abc123',
				'status'    => 'CAPTURED',
				'amount'    => '10.500',
				'currency'  => 'KWD',
				'reference' => array( 'order' => '42', 'payment' => 'p1' ),
				'source'    => array( 'payment_method' => 'VISA' ),
			),
			$overrides
		)
	);
}

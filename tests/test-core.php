<?php
/**
 * Currency precision, country lookup, response parsing, logging and validation.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

TapTest::group( 'Tap_Currency: one list, not four' );

TapTest::is( 'KWD is 3dp', Tap_Currency::decimals( 'KWD' ), 3 );
TapTest::is( 'BHD is 3dp (lowercase input)', Tap_Currency::decimals( 'bhd' ), 3 );
TapTest::is( 'OMR is 3dp', Tap_Currency::decimals( 'OMR' ), 3 );
TapTest::is( 'JOD is 3dp — omitted by 2.x, caused false auto-refunds', Tap_Currency::decimals( 'JOD' ), 3 );
TapTest::is( 'USD is 2dp', Tap_Currency::decimals( 'USD' ), 2 );
TapTest::is( 'AED is 2dp', Tap_Currency::decimals( 'AED' ), 2 );
TapTest::is( 'JPY is 0dp', Tap_Currency::decimals( 'JPY' ), 0 );
TapTest::is( 'formats JOD to 3dp', Tap_Currency::format( '10.5', 'JOD' ), '10.500' );
TapTest::is( 'formats USD to 2dp', Tap_Currency::format( 10.5, 'USD' ), '10.50' );
TapTest::is( 'formats a whole AED amount', Tap_Currency::format( 65, 'AED' ), '65.00' );
TapTest::ok( '"10.5" equals 10.500 in JOD', Tap_Currency::equals( '10.5', 10.500, 'JOD' ) );
TapTest::not_ok( '10.50 does not equal 10.51 in USD', Tap_Currency::equals( 10.50, 10.51, 'USD' ) );
TapTest::ok( 'float noise 0.1+0.2 equals 0.3', Tap_Currency::equals( 0.1 + 0.2, 0.3, 'USD' ) );

TapTest::group( 'Tap_Countries: no undefined index' );

TapTest::is( 'KW', Tap_Countries::dial_code( 'KW' ), '965' );
TapTest::is( 'lowercase sa', Tap_Countries::dial_code( 'sa' ), '966' );
TapTest::is( 'AE', Tap_Countries::dial_code( 'AE' ), '971' );
TapTest::is( 'PS — missing from the 2.x table', Tap_Countries::dial_code( 'PS' ), '970' );
TapTest::is( 'unknown code returns empty, not null', Tap_Countries::dial_code( 'ZZ' ), '' );
TapTest::is( 'empty input returns empty', Tap_Countries::dial_code( '' ), '' );

TapTest::group( 'Tap_Validator: identifier format (SSRF surface)' );

TapTest::ok( 'valid charge id', Tap_Validator::is_valid_transaction_id( 'chg_TS02A5220231388f1H1234' ) );
TapTest::ok( 'valid authorize id', Tap_Validator::is_valid_transaction_id( 'auth_TS02A522023' ) );
TapTest::not_ok( 'path traversal', Tap_Validator::is_valid_transaction_id( '../../merchants/me' ) );
TapTest::not_ok( 'encoded traversal', Tap_Validator::is_valid_transaction_id( 'chg_..%2F..%2Fx' ) );
TapTest::not_ok( 'query injection', Tap_Validator::is_valid_transaction_id( 'chg_x?limit=100' ) );
TapTest::not_ok( 'embedded slash', Tap_Validator::is_valid_transaction_id( 'chg_x/void' ) );
TapTest::not_ok( 'header injection', Tap_Validator::is_valid_transaction_id( "chg_abc\nX-Evil: 1" ) );
TapTest::not_ok( 'empty', Tap_Validator::is_valid_transaction_id( '' ) );
TapTest::not_ok( 'wrong prefix', Tap_Validator::is_valid_transaction_id( 'ref_abc' ) );
TapTest::not_ok( 'over-long', Tap_Validator::is_valid_transaction_id( 'chg_' . str_repeat( 'a', 65 ) ) );
TapTest::is( 'chg_ is a charge', Tap_Validator::transaction_kind( 'chg_1' ), 'charge' );
TapTest::is( 'auth_ is an authorization', Tap_Validator::transaction_kind( 'auth_1' ), 'authorize' );
TapTest::is( 'unknown prefix', Tap_Validator::transaction_kind( 'xyz' ), '' );

TapTest::group( 'Tap_Response: three outcomes, not one null' );

$ok = Tap_Response::success( 200, array( 'id' => 'chg_1', 'reference' => array( 'order' => '42' ), 'transaction' => array( 'url' => 'https://pay' ) ) );
TapTest::ok( 'success is success', $ok->is_success() );
TapTest::is( 'dot path reads nested values', $ok->get( 'reference.order' ), '42' );
TapTest::is( 'missing dot path returns the default', $ok->get( 'a.b.c', 'dflt' ), 'dflt' );
TapTest::is( 'get_string on a missing key', $ok->get_string( 'nope' ), '' );
TapTest::is( 'get_string on a nested key', $ok->get_string( 'transaction.url' ), 'https://pay' );

$err = Tap_Response::error( 401, array( 'errors' => array( array( 'description' => 'Invalid API key' ) ) ) );
TapTest::not_ok( 'a 401 is not success', $err->is_success() );
TapTest::is( 'the API message is surfaced', $err->get_error_message(), 'Invalid API key' );
TapTest::is( 'get_string is safe on an error body', $err->get_string( 'id' ), '' );
TapTest::is( 'a plain message key is read', Tap_Response::error( 400, array( 'message' => 'Bad amount' ) )->get_error_message(), 'Bad amount' );
TapTest::not_ok( 'a null body still yields a message', '' === Tap_Response::error( 500, null )->get_error_message() );

$thrown = Tap_Response::from_throwable( new TypeError( 'boom' ) );
TapTest::not_ok( 'from_throwable is not success', $thrown->is_success() );
TapTest::is( 'from_throwable has status 0', $thrown->get_status_code(), 0 );
TapTest::ok( 'from_throwable names the class', str_contains( $thrown->get_error_message(), 'TypeError' ) );

TapTest::group( 'Tap_Logger: redaction' );

TapTest::is( 'redacts a live secret key', Tap_Logger::redact( 'key=sk_live_abcdef123456' ), 'key=sk_live_***' );
TapTest::is( 'redacts a test publishable key', Tap_Logger::redact( 'pk_test_ZZZ999' ), 'pk_test_***' );
TapTest::is( 'redacts a long hex digest', Tap_Logger::redact( 'hash=' . str_repeat( 'a', 64 ) ), 'hash=aaaaaaaa***' );
TapTest::is( 'redacts a card-length digit run', Tap_Logger::redact( 'pan 4111111111111111 x' ), 'pan 411111****** x' );
TapTest::is( 'leaves ordinary text alone', Tap_Logger::redact( 'order 42 paid in KWD' ), 'order 42 paid in KWD' );
TapTest::is( 'leaves short numbers alone', Tap_Logger::redact( 'order 12345' ), 'order 12345' );

$a = Tap_Logger::generate_request_id();
TapTest::ok( 'request id has the expected shape', (bool) preg_match( '/^woo_[0-9a-z]+_[0-9a-f]{16}$/', $a ) );
TapTest::not_ok( 'request ids are unique', $a === Tap_Logger::generate_request_id() );

$threw = false;
try {
	Tap_Logger::log( 'error', 'msg', array( 'obj' => new stdClass(), 'res' => fopen( 'php://memory', 'r' ) ) );
	Tap_Logger::exception( 'ctx', new Tap_Exception( 'inner', array( 'order' => 1 ) ) );
	Tap_Logger::exception( 'chained', new Tap_Exception( 'outer', array(), '', new RuntimeException( 'root cause' ) ) );
} catch ( Throwable $t ) {
	$threw = true;
}
TapTest::not_ok( 'the logger never throws — catch blocks depend on it', $threw );

TapTest::group( 'Tap_Request_Builder: text handling' );

$describe = new ReflectionMethod( 'Tap_Request_Builder', 'describe' );
$describe->setAccessible( true );

$arabic = str_repeat( 'منتج رائع جداً ', 40 );
$out    = $describe->invoke( null, new WC_Product( '', $arabic ) );
TapTest::ok( 'multibyte truncation stays valid UTF-8', mb_check_encoding( $out, 'UTF-8' ) );
TapTest::not_ok( 'the result can be JSON encoded', false === json_encode( array( 'd' => $out ) ) );
TapTest::ok( 'truncated to the limit', mb_strlen( $out ) <= 240 );
TapTest::is( 'strips HTML and collapses whitespace', $describe->invoke( null, new WC_Product( '', "<p>Hello</p>\n\n  <b>world</b>  " ) ), 'Hello world' );
TapTest::is( 'prefers the short description', $describe->invoke( null, new WC_Product( 'Short one', 'Long one' ) ), 'Short one' );
TapTest::is( 'a non-product yields empty', $describe->invoke( null, false ), '' );

$descriptor = new ReflectionMethod( 'Tap_Request_Builder', 'statement_descriptor' );
$descriptor->setAccessible( true );

$GLOBALS['tap_test_blogname'] = 'Açaí & Co <b>Store</b> ™ Extra Long Name Here';
$value                        = $descriptor->invoke( null );
TapTest::not_ok( 'never the literal "Sample" that 2.x printed on statements', 'Sample' === $value );
TapTest::ok( 'at most 22 characters', mb_strlen( $value ) <= 22 );
TapTest::not_ok( 'no HTML survives', str_contains( $value, '<' ) );
TapTest::not_ok( 'no doubled spaces', str_contains( $value, '  ' ) );
TapTest::ok( 'unicode letters are kept', str_contains( $value, 'Açaí' ) );

$GLOBALS['tap_test_blogname'] = '<b>!!!</b>';
TapTest::is( 'falls back to the host when the name is all punctuation', $descriptor->invoke( null ), 'example.test' );
$GLOBALS['tap_test_blogname'] = 'Test Store';

exit( TapTest::summary() );

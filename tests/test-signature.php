<?php
/**
 * Signature generation and webhook verification.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

$secret = 'sk_test_secret';

TapTest::group( 'Tap_Signature: checkout hash' );

$hash = Tap_Signature::checkout_hash( 'pk_test_abc', '10.500', 'KWD', '1234', 'https://s.test/wc-api/tap_webhook', $secret );
TapTest::ok( 'is 64-char hex', (bool) preg_match( '/^[a-f0-9]{64}$/', $hash ) );
TapTest::is(
	'matches an independently computed HMAC',
	$hash,
	hash_hmac( 'sha256', 'x_publickeypk_test_abcx_amount10.500x_currencyKWDx_transaction1234x_posthttps://s.test/wc-api/tap_webhook', $secret )
);
TapTest::not_ok(
	'changing the amount changes the hash',
	$hash === Tap_Signature::checkout_hash( 'pk_test_abc', '99.000', 'KWD', '1234', 'https://s.test/wc-api/tap_webhook', $secret )
);

TapTest::group( 'Tap_Signature: webhook verification' );

$payload = array(
	'id'          => 'chg_1',
	'amount'      => '10.500',
	'currency'    => 'KWD',
	'status'      => 'CAPTURED',
	'reference'   => array( 'gateway' => 'g1', 'payment' => 'p1' ),
	'transaction' => array( 'created' => '1700000000' ),
);

$good = Tap_Signature::webhook_hash( $payload, $secret );
TapTest::ok( 'a correct signature verifies', Tap_Signature::verify_webhook( $payload, $good, $secret ) );
TapTest::not_ok( 'a wrong signature is rejected', Tap_Signature::verify_webhook( $payload, str_repeat( 'a', 64 ), $secret ) );
TapTest::not_ok( 'an empty signature is rejected', Tap_Signature::verify_webhook( $payload, '', $secret ) );
TapTest::not_ok( 'an empty secret is rejected', Tap_Signature::verify_webhook( $payload, $good, '' ) );
TapTest::not_ok(
	'a tampered amount is rejected',
	Tap_Signature::verify_webhook( array_merge( $payload, array( 'amount' => '0.100' ) ), $good, $secret )
);
TapTest::not_ok(
	'a tampered status is rejected',
	Tap_Signature::verify_webhook( array_merge( $payload, array( 'status' => 'DECLINED' ) ), $good, $secret )
);
TapTest::ok( 'missing nested keys do not fatal', is_string( Tap_Signature::webhook_hash( array( 'id' => 'chg_1' ), $secret ) ) );

TapTest::group( 'Tap_Signature: amount rendering variants' );

// The live failure this covers: JSON carries 65.00, json_decode gives float(65),
// and (string) float(65) is "65" — but Tap signed "65.00".
$raw_body   = '{"id":"chg_2","amount":65.00,"currency":"AED","status":"CAPTURED","reference":{"gateway":"g","payment":"p"},"transaction":{"created":"1700000000"}}';
$decoded    = json_decode( $raw_body, true );
$candidates = Tap_Signature::webhook_hash_candidates( $decoded, $secret, $raw_body );

TapTest::is( 'decoded amount really is a bare 65', (string) $decoded['amount'], '65' );
TapTest::ok( 'more than one candidate is produced', count( $candidates ) > 1 );

// Whichever rendering Tap used, verification must succeed.
$signed_as_two_dp = hash_hmac(
	'sha256',
	'x_idchg_2x_amount65.00x_currencyAEDx_gateway_referencegx_payment_referencepx_statusCAPTUREDx_created1700000000',
	$secret
);
TapTest::ok(
	'a signature over "65.00" verifies',
	Tap_Signature::verify_webhook( $decoded, $signed_as_two_dp, $secret, $raw_body )
);

$signed_as_plain = hash_hmac(
	'sha256',
	'x_idchg_2x_amount65x_currencyAEDx_gateway_referencegx_payment_referencepx_statusCAPTUREDx_created1700000000',
	$secret
);
TapTest::ok(
	'a signature over "65" also verifies',
	Tap_Signature::verify_webhook( $decoded, $signed_as_plain, $secret, $raw_body )
);

TapTest::not_ok(
	'a signature over a different amount still fails',
	Tap_Signature::verify_webhook(
		$decoded,
		hash_hmac( 'sha256', 'x_idchg_2x_amount1.00x_currencyAEDx_gateway_referencegx_payment_referencepx_statusCAPTUREDx_created1700000000', $secret ),
		$secret,
		$raw_body
	)
);

TapTest::not_ok(
	'tolerating amount formats does not tolerate a wrong key',
	Tap_Signature::verify_webhook( $decoded, $signed_as_two_dp, 'sk_test_other', $raw_body )
);

exit( TapTest::summary() );

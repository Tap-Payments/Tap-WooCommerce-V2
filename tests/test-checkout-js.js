/**
 * Tests for the checkout script's transaction-id extraction.
 *
 * The Tap SDK does not document a stable onSuccess payload, and an unreadable
 * id previously caused the browser to return with an empty tap_id — which the
 * server read as "customer did not pay" and failed an order that had in fact
 * been charged. These cases cover the shapes the SDK is known or likely to use.
 *
 * Run: node tests/test-checkout-js.js
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const source = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'tap-checkout.js' ), 'utf8' );

// Pull the real shipped implementation out of the IIFE rather than duplicating it.
const start = source.indexOf( 'var ID_PATTERN' );
const marker = source.indexOf( 'function extractChargeId' );
const end = source.indexOf( '\n\t/**', marker );

if ( start === -1 || marker === -1 || end === -1 ) {
	console.error( 'FAIL could not locate extractChargeId in tap-checkout.js' );
	process.exit( 1 );
}

// eslint-disable-next-line no-eval
const extractChargeId = eval( `(function(){ ${ source.slice( start, end ) } return extractChargeId; })()` );

let passed = 0;
let failed = 0;

function is( label, actual, expected ) {
	if ( actual === expected ) {
		passed++;
		console.log( `  ok   ${ label }` );
		return;
	}
	failed++;
	console.log( `  FAIL ${ label }\n         got ${ JSON.stringify( actual ) }, want ${ JSON.stringify( expected ) }` );
}

console.log( '\nextractChargeId: shapes that must yield an id' );
is( 'a bare charge id string', extractChargeId( 'chg_abc123' ), 'chg_abc123' );
is( 'a bare authorization id string', extractChargeId( 'auth_abc123' ), 'auth_abc123' );
is( '{ id }', extractChargeId( { id: 'chg_abc123' } ), 'chg_abc123' );
is( '{ chargeId }', extractChargeId( { chargeId: 'chg_abc123' } ), 'chg_abc123' );
is( '{ charge_id }', extractChargeId( { charge_id: 'chg_abc123' } ), 'chg_abc123' );
is( '{ charge: { id } }', extractChargeId( { charge: { id: 'chg_abc123' } } ), 'chg_abc123' );
is( '{ data: { id } } — the nested shape that broke live', extractChargeId( { data: { id: 'chg_abc123' } } ), 'chg_abc123' );
is( 'a realistic SDK envelope', extractChargeId( { status: 'CAPTURED', data: { id: 'chg_abc123', amount: 98 } } ), 'chg_abc123' );
is( 'a JSON string', extractChargeId( '{"data":{"id":"chg_abc123"}}' ), 'chg_abc123' );
is( 'deeply nested', extractChargeId( { a: { b: { c: { transaction: { id: 'chg_abc123' } } } } } ), 'chg_abc123' );
is( 'prefers chargeId over an unrelated id', extractChargeId( { id: 'cus_zzz', chargeId: 'chg_abc123' } ), 'chg_abc123' );

console.log( '\nextractChargeId: shapes that must yield nothing' );
is( 'null', extractChargeId( null ), '' );
is( 'undefined', extractChargeId( undefined ), '' );
is( 'empty object', extractChargeId( {} ), '' );
is( 'empty string', extractChargeId( '' ), '' );
is( 'a customer id is not a charge id', extractChargeId( { id: 'cus_xyz' } ), '' );
is( 'an arbitrary string', extractChargeId( 'something went wrong' ), '' );
is( 'malformed JSON', extractChargeId( '{not json' ), '' );
is( 'a token that only looks similar', extractChargeId( { id: 'charge_abc' } ), '' );
is( 'an id with a slash is rejected', extractChargeId( { id: 'chg_abc/../x' } ), '' );

console.log( '\nextractChargeId: termination' );
const cyclic = { level: 1 };
cyclic.self = cyclic;
is( 'a cyclic object terminates instead of hanging', extractChargeId( cyclic ), '' );

let deep = { id: 'chg_deep' };
for ( let i = 0; i < 12; i++ ) {
	deep = { nested: deep };
}
is( 'recursion is bounded (very deep nesting gives up)', extractChargeId( deep ), '' );

console.log( `\n${ passed } passed, ${ failed } failed` );
process.exit( failed > 0 ? 1 : 0 );

<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * Removes the plugin's own settings and any leftover locks. Order data is
 * deliberately left untouched: transaction ids on past orders are financial
 * records the merchant may still need.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

try {
	delete_option( 'woocommerce_tap_settings' );

	// Written by version 2.x on every successful webhook.
	delete_option( 'webhook_debug' );

	// Per-order processing locks, in case any were orphaned by a fatal error.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tap\_lock\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// Any pending re-check events.
	$timestamp = wp_next_scheduled( 'wc_tap_recheck_cancelled_order' );
	while ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, 'wc_tap_recheck_cancelled_order' );
		$timestamp = wp_next_scheduled( 'wc_tap_recheck_cancelled_order' );
	}
	wp_clear_scheduled_hook( 'wc_tap_recheck_cancelled_order' );
} catch ( Throwable $e ) {
	// Uninstall must complete. Leaving a stray option behind is a far smaller
	// problem than a fatal that blocks the plugin from being removed.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- No logger is available during uninstall.
		error_log( 'Tap uninstall cleanup failed: ' . $e->getMessage() );
	}
}

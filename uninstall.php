<?php
/**
 * Uninstall: removes this plugin's settings. The shared Smarfle connection and saved business info go with the last
 * Smarfle plugin removed (then Smarfle is told to stop this site's key).
 *
 * @package Smarfle_Booking_Forms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'smarfle_booking_forms' );
delete_transient( 'smarfle_bf_test_lead' );

$smarfle_others = array_filter(
	(array) glob( WP_PLUGIN_DIR . '/smarfle-*', GLOB_ONLYDIR ),
	function ( $dir ) {
		return basename( $dir ) !== basename( __DIR__ ) && file_exists( $dir . '/includes/core/loader.php' );
	}
);

if ( empty( $smarfle_others ) ) {
	$smarfle_connection = get_option( 'smarfle_connection', array() );
	if ( is_array( $smarfle_connection ) && ! empty( $smarfle_connection['site_key'] ) ) {
		// The stored key is encrypted with this site's salt (Smarfle_Core::seal_key).
		if ( ! class_exists( 'Smarfle_Core', false ) ) {
			require_once __DIR__ . '/includes/core/class-smarfle-core.php';
		}
		$smarfle_connection['site_key'] = Smarfle_Core::open_key( (string) $smarfle_connection['site_key'] );
	}
	if ( is_array( $smarfle_connection ) && ! empty( $smarfle_connection['site_key'] ) ) {
		$smarfle_base = defined( 'SMARFLE_API_BASE' ) ? untrailingslashit( (string) SMARFLE_API_BASE ) : 'https://www.smarfle.com';
		wp_remote_post(
			$smarfle_base . '/api/plugin/v1/disconnect',
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'Authorization' => 'Bearer ' . $smarfle_connection['site_key'] ),
			)
		);
	}
	foreach ( array( 'smarfle_connection', 'smarfle_site', 'smarfle_settings', 'smarfle_stats', 'smarfle_queue', 'smarfle_backoff' ) as $smarfle_option ) {
		delete_option( $smarfle_option );
	}
	wp_clear_scheduled_hook( 'smarfle_weekly_refresh' );
	wp_clear_scheduled_hook( 'smarfle_retry_queue' );
	global $wpdb;
	// Short-lived counters and caches (smarfle_ transients).
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_smarfle\\_%' OR option_name LIKE '\\_transient\\_timeout\\_smarfle\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

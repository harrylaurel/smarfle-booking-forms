<?php
/**
 * Plugin Name:       Smarfle Booking & Forms
 * Plugin URI:        https://www.smarfle.com/features/integrations
 * Description:       Online booking with real free times and contact forms that send every inquiry to your Smarfle Inbox. Also sends Contact Form 7 and WPForms entries to Smarfle. For US home service and local businesses with a Smarfle account.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Smarfle
 * Author URI:        https://www.smarfle.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smarfle-booking-forms
 *
 * @package Smarfle_Booking_Forms
 */

defined( 'ABSPATH' ) || exit;

define( 'SMARFLE_BOOKING_FORMS_VERSION', '1.0.0' );
define( 'SMARFLE_BOOKING_FORMS_FILE', __FILE__ );
define( 'SMARFLE_BOOKING_FORMS_DIR', __DIR__ );

// The shared Smarfle core (one connection for every Smarfle plugin on the site; the newest copy loads).
require_once __DIR__ . '/includes/core/loader.php';
smarfle_core_register( '1.2.0', __DIR__ . '/includes/core', plugins_url( 'includes/core', __FILE__ ) );

require_once __DIR__ . '/includes/class-smarfle-booking-forms.php';
add_action( 'plugins_loaded', array( 'Smarfle_Booking_Forms', 'init' ) );

/**
 * Deactivated and no other Smarfle plugin left active: stop the shared background jobs (the connection is kept, so
 * turning a Smarfle plugin back on needs no new connection).
 */
function smarfle_booking_forms_deactivate() {
	foreach ( (array) get_option( 'active_plugins', array() ) as $smarfle_plugin ) {
		if ( 0 === strpos( (string) $smarfle_plugin, 'smarfle-' ) && plugin_basename( __FILE__ ) !== $smarfle_plugin ) {
			return;
		}
	}
	wp_clear_scheduled_hook( 'smarfle_weekly_refresh' );
	wp_clear_scheduled_hook( 'smarfle_retry_queue' );
}
register_deactivation_hook( __FILE__, 'smarfle_booking_forms_deactivate' );

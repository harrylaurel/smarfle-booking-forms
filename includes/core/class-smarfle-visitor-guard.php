<?php
/**
 * The website's own spam checks before a visitor's form reaches Smarfle (Smarfle's reCAPTCHA does not run on a
 * WordPress page): a hidden field bots fill, a minimum time on the form (set by the page's script, so bots that do
 * not run JavaScript fail), and limits per visitor address and per site day. Nothing is stored about the visitor
 * except short-lived counters.
 *
 * @package Smarfle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Visitor checks.
 */
final class Smarfle_Visitor_Guard {

	/** Submissions per visitor address per hour. */
	const PER_HOUR = 10;

	/** Customer submissions this site sends Smarfle per UTC day (Smarfle's own limit is 50). */
	const SITE_DAY = 50;

	/**
	 * Checks a submission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param string          $kind    Form kind (contact, booking, quote...).
	 * @return true|string True, or 'spam' (answer as if sent, drop it) or 'too_many' (ask to try later).
	 */
	public static function check( $request, $kind ) {
		$honeypot = (string) $request->get_param( 'smarfle_hp' );
		$elapsed  = (int) $request->get_param( 'smarfle_elapsed' );
		if ( '' !== trim( $honeypot ) || $elapsed < 3 ) {
			return 'spam';
		}
		$key   = 'smarfle_v_' . md5( self::visitor_ip() . '|' . $kind . '|' . gmdate( 'YmdH' ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::PER_HOUR ) {
			return 'too_many';
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Counts one customer submission for today; false when the site already sent its daily number to Smarfle (the
	 * caller then emails it to the owner instead, no call).
	 *
	 * @return bool
	 */
	public static function take_site_day() {
		$key   = 'smarfle_day_' . gmdate( 'Ymd' );
		$count = (int) get_transient( $key );
		if ( $count >= self::SITE_DAY ) {
			return false;
		}
		set_transient( $key, $count + 1, DAY_IN_SECONDS );
		return true;
	}

	/**
	 * Booking-time look-ups per visitor address per hour (each look-up the site cannot answer from its own copy costs a
	 * call to Smarfle).
	 *
	 * @return bool
	 */
	public static function take_lookup() {
		$key   = 'smarfle_l_' . md5( self::visitor_ip() . '|' . gmdate( 'YmdH' ) );
		$count = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * The visitor's address (REMOTE_ADDR; a site behind a proxy can filter it with smarfle_visitor_ip).
	 *
	 * @return string
	 */
	public static function visitor_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'smarfle_visitor_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * The visitor's address and browser, only when the visitor ticked the text-message consent box: Smarfle keeps them
	 * as the proof of that consent. Without consent nothing about the visitor's device is sent (WordPress.org
	 * guidelines 7 and 9).
	 *
	 * @param bool $consent Whether the visitor gave text-message consent.
	 * @return array
	 */
	public static function consent_proof( $consent ) {
		if ( ! $consent ) {
			return array();
		}
		return array(
			'visitor_ip'    => self::visitor_ip(),
			'visitor_agent' => self::visitor_agent(),
		);
	}

	/**
	 * The visitor's browser (for the text-consent record).
	 *
	 * @return string
	 */
	public static function visitor_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 ) : '';
	}
}

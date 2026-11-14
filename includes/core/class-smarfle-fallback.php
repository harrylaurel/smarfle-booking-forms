<?php
/**
 * Fallbacks: when Smarfle cannot take a visitor's inquiry (not connected, inactive account, a limit, or Smarfle
 * briefly unreachable), it goes to the owner's email, so nothing is lost. When the reason was temporary, a small local
 * copy is sent to Smarfle later (at most 25 kept, for 7 days).
 *
 * @package Smarfle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Email the owner, keep a copy to retry.
 */
final class Smarfle_Fallback {

	const QUEUE_OPTION = 'smarfle_queue';
	const CRON_RETRY   = 'smarfle_retry_queue';
	const MAX_QUEUE    = 25;

	/**
	 * Emails an inquiry to the owner.
	 *
	 * @param string $subject  Subject.
	 * @param array  $lines    Label => value lines.
	 * @param string $reply_to The visitor's email, for Reply.
	 * @return bool
	 */
	public static function email_owner( $subject, $lines, $reply_to = '' ) {
		$body = '';
		foreach ( $lines as $label => $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$body .= $label . ': ' . $value . "\n";
			}
		}
		$body .= "\n" . sprintf(
			/* translators: %s: the website address. */
			__( 'Sent from %s by the Smarfle plugin. Connect an active Smarfle account to get these in your Smarfle Inbox with automatic follow-up.', 'smarfle-booking-forms' ),
			home_url()
		);
		$headers = array();
		$reply   = sanitize_email( $reply_to );
		if ( $reply ) {
			$headers[] = 'Reply-To: ' . $reply;
		}
		$sent = wp_mail( Smarfle_Core::instance()->fallback_email(), $subject, $body, $headers );
		if ( $sent ) {
			Smarfle_Core::instance()->count( 'emailed' );
		}
		return $sent;
	}

	/**
	 * True when a failed answer was temporary (worth sending again later).
	 *
	 * @param array $res Smarfle_Api::call answer.
	 * @return bool
	 */
	public static function is_temporary( $res ) {
		return 0 === $res['status'] || 429 === $res['status'] || $res['status'] >= 500 || 'backoff' === $res['error'] || 'busy' === $res['error'];
	}

	/**
	 * Sends a visitor's submission to Smarfle when it can take it (live account, the site's daily number not reached);
	 * a temporary failure keeps a copy to send later. The caller emails the owner when this returns false.
	 *
	 * @param string $path    API path (/lead).
	 * @param array  $payload JSON body.
	 * @param string $kind    What to count this week (inquiries, quotes).
	 * @return bool True when Smarfle took it.
	 */
	public static function send( $path, $payload, $kind ) {
		$core = Smarfle_Core::instance();
		if ( ! $core->is_live() || ! Smarfle_Visitor_Guard::take_site_day() ) {
			return false;
		}
		$res = Smarfle_Api::call( 'POST', $path, $payload, array( 'type' => 'action' ) );
		if ( $res['ok'] ) {
			$core->count( $kind );
			return true;
		}
		if ( self::is_temporary( $res ) ) {
			self::queue( $path, $payload );
		}
		return false;
	}

	/**
	 * Keeps a copy to send to Smarfle when it answers again.
	 *
	 * @param string $path    API path (/lead, ...).
	 * @param array  $payload JSON body.
	 */
	public static function queue( $path, $payload ) {
		$queue   = self::items();
		$queue[] = array(
			'path'    => $path,
			'payload' => $payload,
			'at'      => time(),
		);
		$queue   = array_slice( $queue, -self::MAX_QUEUE );
		update_option( self::QUEUE_OPTION, $queue, false );
		if ( ! wp_next_scheduled( self::CRON_RETRY ) ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::CRON_RETRY );
		}
	}

	/**
	 * Saved copies.
	 *
	 * @return array
	 */
	public static function items() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Sends saved copies (hourly while any are left; 5 at a time; dropped after 7 days or a permanent refusal).
	 */
	public static function retry() {
		$core  = Smarfle_Core::instance();
		$queue = array_values(
			array_filter(
				self::items(),
				function ( $item ) {
					return isset( $item['at'] ) && (int) $item['at'] > time() - WEEK_IN_SECONDS;
				}
			)
		);
		if ( empty( $queue ) ) {
			delete_option( self::QUEUE_OPTION );
			return;
		}
		if ( $core->is_live() && Smarfle_Api::backoff_left( 'action' ) <= 0 ) {
			$keep = array();
			$sent = 0;
			foreach ( $queue as $item ) {
				if ( $sent >= 5 ) {
					$keep[] = $item;
					continue;
				}
				$res = Smarfle_Api::call( 'POST', $item['path'], $item['payload'], array( 'type' => 'action' ) );
				++$sent;
				if ( ! $res['ok'] && self::is_temporary( $res ) ) {
					$keep[] = $item;
				}
			}
			$queue = $keep;
		}
		if ( empty( $queue ) ) {
			delete_option( self::QUEUE_OPTION );
			return;
		}
		update_option( self::QUEUE_OPTION, $queue, false );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::CRON_RETRY );
	}
}

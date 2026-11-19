<?php
/**
 * The website's own endpoints the forms post to (/wp-json/smarfle/v1/...). The browser never talks to Smarfle and
 * never sees the site key: these run the site's spam checks, then call Smarfle from the server, or email the owner
 * when Smarfle cannot take it.
 *
 * Public by design (any visitor can send a form), so permission_callback is __return_true; the checks are in
 * Smarfle_Visitor_Guard.
 *
 * @package Smarfle_Booking_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for the forms.
 */
final class Smarfle_BF_Endpoints {

	const NS = 'smarfle/v1';

	/**
	 * Registers the routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/contact',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'contact' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/booking/times',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'times' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/booking',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'book' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * A JSON answer.
	 *
	 * @param array $data   Body.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function reply( $data, $status = 200 ) {
		$res = new WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/**
	 * A text parameter.
	 *
	 * @param WP_REST_Request $r   Request.
	 * @param string          $key Name.
	 * @param int             $max Max length.
	 * @return string
	 */
	private static function text( $r, $key, $max = 200 ) {
		$v = $r->get_param( $key );
		return is_scalar( $v ) ? substr( sanitize_text_field( (string) $v ), 0, $max ) : '';
	}

	/**
	 * The page the form was on, only when it is this site's own page.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return string
	 */
	private static function page_url( $r ) {
		$url = esc_url_raw( self::text( $r, 'page_url', 500 ) );
		return $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ? $url : '';
	}

	/**
	 * Attribution the page saw (utm_source / medium / campaign).
	 *
	 * @param WP_REST_Request $r Request.
	 * @return array
	 */
	private static function utm( $r ) {
		return array(
			'utm_source'   => self::text( $r, 'utm_source', 80 ),
			'utm_medium'   => self::text( $r, 'utm_medium', 80 ),
			'utm_campaign' => self::text( $r, 'utm_campaign', 120 ),
		);
	}

	/* ------------------------------------------------------------------ Contact form */

	/**
	 * POST /smarfle/v1/contact
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response
	 */
	public static function contact( $r ) {
		$guard = Smarfle_Visitor_Guard::check( $r, 'contact' );
		if ( 'spam' === $guard ) {
			return self::reply( array( 'ok' => true ) );
		}
		if ( 'too_many' === $guard ) {
			return self::reply(
				array(
					'ok'      => false,
					'message' => __( 'Too many messages from this device. Please try again later or call us.', 'smarfle-booking-forms' ),
				),
				429
			);
		}
		$name    = self::text( $r, 'name', 120 );
		$email   = sanitize_email( self::text( $r, 'email', 200 ) );
		$phone   = self::text( $r, 'phone', 40 );
		$message = substr( sanitize_textarea_field( (string) $r->get_param( 'message' ) ), 0, 4000 );
		$form    = self::text( $r, 'form', 120 );
		if ( '' === $name || ! is_email( $email ) || '' === trim( $message ) ) {
			return self::reply(
				array(
					'ok'      => false,
					'message' => __( 'Please fill in your name, a valid email and your message.', 'smarfle-booking-forms' ),
				),
				400
			);
		}
		$core    = Smarfle_Core::instance();
		$site    = $core->site_data();
		$version = self::text( $r, 'consent_version', 40 );
		$consent = '' !== $phone && (bool) $r->get_param( 'sms_consent' ) && isset( $site['consent']['version'] ) && $version === $site['consent']['version'];
		$payload = array_merge(
			array(
				'form'            => '' !== $form ? $form : 'Website contact form',
				'fields'          => array(
					'name'    => $name,
					'email'   => $email,
					'phone'   => $phone,
					'message' => $message,
				),
				'page_url'        => self::page_url( $r ),
				'page_title'      => self::text( $r, 'page_title', 200 ),
				'sms_consent'     => $consent,
				'consent_version' => $consent ? $version : '',
			),
			Smarfle_Visitor_Guard::consent_proof( $consent ),
			self::utm( $r )
		);
		$lines   = array(
			__( 'Name', 'smarfle-booking-forms' )    => $name,
			__( 'Email', 'smarfle-booking-forms' )   => $email,
			__( 'Phone', 'smarfle-booking-forms' )   => $phone,
			__( 'Message', 'smarfle-booking-forms' ) => $message,
			__( 'Page', 'smarfle-booking-forms' )    => $payload['page_url'],
		);
		$sent    = self::send_to_smarfle( '/lead', $payload, 'inquiries' );
		if ( 'smarfle' === $sent ) {
			return self::reply( array( 'ok' => true ) );
		}
		/* translators: %s: visitor's name. */
		$subject = sprintf( __( 'New website inquiry from %s', 'smarfle-booking-forms' ), $name );
		$emailed = Smarfle_Fallback::email_owner( $subject, $lines, $email );
		return self::reply( array( 'ok' => $emailed ), $emailed ? 200 : 500 );
	}

	/**
	 * Sends a customer action to Smarfle when it can take it. A temporary failure keeps a copy to retry.
	 *
	 * @param string $path    /lead.
	 * @param array  $payload Body.
	 * @param string $kind    What to count this week (inquiries).
	 * @return string 'smarfle' when Smarfle took it, else 'fallback'.
	 */
	private static function send_to_smarfle( $path, $payload, $kind ) {
		$core = Smarfle_Core::instance();
		if ( ! $core->is_live() || ! Smarfle_Visitor_Guard::take_site_day() ) {
			return 'fallback';
		}
		$res = Smarfle_Api::call( 'POST', $path, $payload, array( 'type' => 'action' ) );
		if ( $res['ok'] ) {
			$core->count( $kind );
			return 'smarfle';
		}
		if ( Smarfle_Fallback::is_temporary( $res ) ) {
			Smarfle_Fallback::queue( $path, $payload );
		}
		return 'fallback';
	}

	/* ------------------------------------------------------------------ Booking times */

	/**
	 * GET /smarfle/v1/booking/times?service=&quantity=&office=
	 * The next 14 days for one service. Kept 10 minutes here (same answer for every visitor), so most visitors cost
	 * no call to Smarfle.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response
	 */
	public static function times( $r ) {
		$core     = Smarfle_Core::instance();
		$service  = self::text( $r, 'service', 40 );
		$office   = self::text( $r, 'office', 40 );
		$quantity = max( 1, min( 999, absint( $r->get_param( 'quantity' ) ) ) );
		if ( ! self::is_id( $service ) || ( '' !== $office && ! self::is_id( $office ) ) ) {
			return self::reply( array( 'ok' => false ), 400 );
		}
		if ( ! $core->is_live() ) {
			return self::reply(
				array(
					'ok'       => false,
					'fallback' => 'call',
				)
			);
		}
		$key    = self::times_key( $service, $quantity, $office );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return self::reply( $cached );
		}
		if ( ! Smarfle_Visitor_Guard::take_lookup() ) {
			return self::reply( array( 'ok' => false ), 429 );
		}
		$res = Smarfle_Api::call(
			'GET',
			'/slots',
			null,
			array(
				'type'  => 'slots',
				'query' => array(
					'service_id' => $service,
					'quantity'   => (string) $quantity,
					'office'     => $office,
				),
			)
		);
		if ( ! $res['ok'] ) {
			return self::reply(
				array(
					'ok'       => false,
					'fallback' => 'call',
				)
			);
		}
		$days = array();
		foreach ( isset( $res['data']['days'] ) && is_array( $res['data']['days'] ) ? $res['data']['days'] : array() as $day ) {
			if ( ! is_array( $day ) || empty( $day['date'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day['date'] ) ) {
				continue;
			}
			$times = array();
			foreach ( isset( $day['times'] ) && is_array( $day['times'] ) ? $day['times'] : array() as $t ) {
				if ( preg_match( '/^\d{2}:\d{2}$/', (string) $t ) ) {
					$times[] = (string) $t;
				}
			}
			$days[] = array(
				'date'  => (string) $day['date'],
				'times' => $times,
			);
		}
		$parts = array();
		foreach ( isset( $res['data']['time_of_day'] ) && is_array( $res['data']['time_of_day'] ) ? $res['data']['time_of_day'] : array() as $p ) {
			if ( is_array( $p ) && isset( $p['label'], $p['value'] ) && preg_match( '/^\d{2}:\d{2}$/', (string) $p['value'] ) ) {
				$parts[] = array(
					'label' => sanitize_text_field( $p['label'] ),
					'value' => (string) $p['value'],
				);
			}
		}
		$answer = array(
			'ok'        => true,
			'booking'   => ! empty( $res['data']['booking'] ),
			'mode'      => isset( $res['data']['mode'] ) ? sanitize_key( $res['data']['mode'] ) : 'booking',
			'timeOfDay' => $parts,
			'days'      => $days,
		);
		set_transient( $key, $answer, 10 * MINUTE_IN_SECONDS );
		return self::reply( $answer );
	}

	/* ------------------------------------------------------------------ Booking */

	/**
	 * POST /smarfle/v1/booking
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response
	 */
	public static function book( $r ) {
		$guard = Smarfle_Visitor_Guard::check( $r, 'booking' );
		if ( 'spam' === $guard ) {
			return self::reply( array( 'ok' => true ) );
		}
		if ( 'too_many' === $guard ) {
			return self::reply(
				array(
					'ok'      => false,
					'message' => __( 'Too many bookings from this device. Please call us.', 'smarfle-booking-forms' ),
				),
				429
			);
		}
		$core    = Smarfle_Core::instance();
		$site    = $core->site_data();
		$service = self::text( $r, 'service', 40 );
		$office  = self::text( $r, 'office', 40 );
		$date    = self::text( $r, 'date', 10 );
		$time    = self::text( $r, 'time', 5 );
		$first   = self::text( $r, 'first_name', 100 );
		$last    = self::text( $r, 'last_name', 100 );
		$email   = sanitize_email( self::text( $r, 'email', 200 ) );
		$phone   = self::text( $r, 'phone', 40 );
		$digits  = preg_replace( '/\D/', '', $phone );
		if ( ! self::is_id( $service ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) || '' === $first || '' === $last || ! is_email( $email ) || ! preg_match( '/^1?\d{10}$/', $digits ) ) {
			return self::reply(
				array(
					'ok'      => false,
					'message' => __( 'Please fill in every field marked *.', 'smarfle-booking-forms' ),
				),
				400
			);
		}

		// Only the business's own questions, as Smarfle listed them.
		$answers = array();
		$asked   = isset( $site['booking']['questions'] ) && is_array( $site['booking']['questions'] ) ? $site['booking']['questions'] : array();
		$given   = $r->get_param( 'answers' );
		foreach ( $asked as $q ) {
			$qid = is_array( $q ) && isset( $q['id'] ) ? (string) $q['id'] : '';
			if ( '' !== $qid && is_array( $given ) && isset( $given[ $qid ] ) && is_scalar( $given[ $qid ] ) ) {
				$answers[ $qid ] = substr( sanitize_text_field( (string) $given[ $qid ] ), 0, 300 );
			}
		}
		$version = self::text( $r, 'consent_version', 40 );
		$consent = (bool) $r->get_param( 'sms_consent' ) && isset( $site['consent']['booking_version'] ) && $version === $site['consent']['booking_version'];
		$payload = array_merge(
			array(
				'service_id'      => $service,
				'quantity'        => max( 1, min( 999, absint( $r->get_param( 'quantity' ) ) ) ),
				'size'            => self::text( $r, 'size', 20 ),
				'office'          => self::is_id( $office ) ? $office : '',
				'date'            => $date,
				'time'            => $time,
				'time_of_day'     => self::text( $r, 'time_of_day', 20 ),
				'first_name'      => $first,
				'last_name'       => $last,
				'email'           => $email,
				'phone'           => $phone,
				'notes'           => substr( sanitize_textarea_field( (string) $r->get_param( 'notes' ) ), 0, 2000 ),
				'address_street'  => self::text( $r, 'address_street', 300 ),
				'address_city'    => self::text( $r, 'address_city', 120 ),
				'address_state'   => self::text( $r, 'address_state', 60 ),
				'address_zip'     => self::text( $r, 'address_zip', 10 ),
				'answers'         => $answers,
				'sms_consent'     => $consent,
				'consent_version' => $consent ? $version : '',
				'page_url'        => self::page_url( $r ),
			),
			Smarfle_Visitor_Guard::consent_proof( $consent ),
			self::utm( $r )
		);

		if ( $core->is_live() && Smarfle_Visitor_Guard::take_site_day() ) {
			$res = Smarfle_Api::call(
				'POST',
				'/book',
				$payload,
				array(
					'type'    => 'action',
					'timeout' => 20,
				)
			);
			delete_transient( self::times_key( $service, $payload['quantity'], $payload['office'] ) );
			if ( $res['ok'] ) {
				$core->count( 'bookings' );
				return self::reply(
					array(
						'ok'      => true,
						'status'  => isset( $res['data']['status'] ) ? sanitize_key( $res['data']['status'] ) : '',
						'message' => isset( $res['data']['message'] ) ? sanitize_text_field( $res['data']['message'] ) : '',
					)
				);
			}
			if ( 409 === $res['status'] ) {
				return self::reply(
					array(
						'ok'    => false,
						'taken' => true,
					),
					409
				);
			}
			// Smarfle refused the details themselves: its message is a sentence written for the customer (codes have no spaces).
			if ( 400 === $res['status'] && ! empty( $res['data']['error'] ) && is_string( $res['data']['error'] ) && false !== strpos( $res['data']['error'], ' ' ) ) {
				return self::reply(
					array(
						'ok'      => false,
						'message' => sanitize_text_field( $res['data']['error'] ),
					),
					400
				);
			}
		}

		// Smarfle could not take it: the owner gets the request by email and calls the customer to confirm.
		$name     = trim( $first . ' ' . $last );
		$services = isset( $site['services'] ) && is_array( $site['services'] ) ? $site['services'] : array();
		$label    = $service;
		foreach ( $services as $s ) {
			if ( is_array( $s ) && isset( $s['id'], $s['name'] ) && $s['id'] === $service ) {
				$label = sanitize_text_field( $s['name'] );
			}
		}
		$lines = array(
			__( 'Service', 'smarfle-booking-forms' )   => $label,
			__( 'Asked for', 'smarfle-booking-forms' ) => $date . ' ' . ( '' !== $payload['time_of_day'] ? $payload['time_of_day'] : $time ),
			__( 'Name', 'smarfle-booking-forms' )      => $name,
			__( 'Email', 'smarfle-booking-forms' )     => $email,
			__( 'Phone', 'smarfle-booking-forms' )     => $phone,
			__( 'Address', 'smarfle-booking-forms' )   => trim( implode( ', ', array_filter( array( $payload['address_street'], $payload['address_city'], trim( $payload['address_state'] . ' ' . $payload['address_zip'] ) ) ) ) ),
			__( 'Answers', 'smarfle-booking-forms' )   => implode( '; ', $answers ),
			__( 'Notes', 'smarfle-booking-forms' )     => $payload['notes'],
			__( 'Not booked yet', 'smarfle-booking-forms' ) => __( 'Call the customer to confirm a time.', 'smarfle-booking-forms' ),
		);
		/* translators: %s: customer's name. */
		$emailed = Smarfle_Fallback::email_owner( sprintf( __( 'Booking request from %s (not booked yet)', 'smarfle-booking-forms' ), $name ), $lines, $email );
		return self::reply(
			array(
				'ok'       => $emailed,
				'fallback' => true,
				'message'  => $emailed
					? __( 'Thanks! We got your request and will call you to confirm the time.', 'smarfle-booking-forms' )
					: __( 'The booking could not be sent. Please call us.', 'smarfle-booking-forms' ),
			),
			$emailed ? 200 : 500
		);
	}

	/**
	 * Cache key for one service's times.
	 *
	 * @param string $service  Service id.
	 * @param int    $quantity Quantity.
	 * @param string $office   Office id.
	 * @return string
	 */
	private static function times_key( $service, $quantity, $office ) {
		return 'smarfle_times_' . md5( $service . '|' . $quantity . '|' . $office );
	}

	/**
	 * A Smarfle id (uuid).
	 *
	 * @param string $v Value.
	 * @return bool
	 */
	private static function is_id( $v ) {
		return (bool) preg_match( '/^[0-9a-f-]{36}$/i', (string) $v );
	}
}

<?php
/**
 * Calls to Smarfle (https://www.smarfle.com/api/plugin/v1/...).
 *
 * Every answer carries the account state, which is saved. A "busy" or "limit" answer (503 / 429 with Retry-After)
 * stops that kind of call here until the time Smarfle gave, so a site never keeps knocking. A refused key (401)
 * forgets the connection: no more calls until the owner connects again.
 *
 * @package Smarfle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The HTTP client.
 */
final class Smarfle_Api {

	const BACKOFF_OPTION = 'smarfle_backoff';
	const MAX_BACKOFF    = DAY_IN_SECONDS;

	/**
	 * Calls Smarfle.
	 *
	 * @param string     $method GET or POST.
	 * @param string     $path   Path under /api/plugin/v1, for example /site.
	 * @param array|null $body   JSON body for POST.
	 * @param array      $args   type (site, slots, action, connect, disconnect), auth (default true), query, timeout.
	 * @return array ok, status, data, error, retry_after.
	 */
	public static function call( $method, $path, $body = null, $args = array() ) {
		$core = Smarfle_Core::instance();
		$args = wp_parse_args(
			$args,
			array(
				'type'    => 'site',
				'auth'    => true,
				'query'   => array(),
				'timeout' => 10,
			)
		);

		$wait = self::backoff_left( $args['type'] );
		if ( $wait > 0 ) {
			return self::answer( false, 429, array(), 'backoff', $wait );
		}
		if ( $args['auth'] && ! $core->is_connected() ) {
			return self::answer( false, 401, array(), 'not_connected' );
		}

		$url = $core->api_base() . '/api/plugin/v1' . $path;
		if ( ! empty( $args['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $args['query'] ), $url );
		}
		$headers = array(
			'Accept'            => 'application/json',
			'X-Smarfle-Plugins' => $core->plugins_header(),
			'X-WP-Version'      => get_bloginfo( 'version' ),
			'X-PHP-Version'     => PHP_VERSION,
		);
		if ( $args['auth'] ) {
			$headers['Authorization'] = 'Bearer ' . $core->site_key();
		}
		$request = array(
			'method'      => $method,
			'timeout'     => (int) $args['timeout'],
			'headers'     => $headers,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$request['headers']['Content-Type'] = 'application/json';
			$request['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $request );
		if ( is_wp_error( $response ) ) {
			// Smarfle unreachable: wait a few minutes before this kind of call tries again.
			self::set_backoff( $args['type'], 5 * MINUTE_IN_SECONDS );
			return self::answer( false, 0, array(), 'network' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();

		if ( isset( $data['state'] ) && is_array( $data['state'] ) ) {
			$core->save_state( $data['state'] );
		}
		if ( 401 === $status && $args['auth'] ) {
			$core->forget_connection( __( 'Smarfle no longer accepts this website (it was disconnected in Smarfle). Connect again to turn the plugins back on.', 'smarfle-booking-forms' ) );
			return self::answer( false, 401, $data, 'not_connected' );
		}
		if ( 429 === $status || 503 === $status || $status >= 500 ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			self::set_backoff( $args['type'], $retry > 0 ? $retry : 15 * MINUTE_IN_SECONDS );
		}
		$ok = $status >= 200 && $status < 300 && ( ! isset( $data['ok'] ) || false !== $data['ok'] );
		return self::answer( $ok, $status, $data, $ok ? '' : ( isset( $data['error'] ) ? sanitize_key( $data['error'] ) : 'error' ) );
	}

	/**
	 * One answer shape.
	 *
	 * @param bool   $ok          Success.
	 * @param int    $status      HTTP status (0 = no answer).
	 * @param array  $data        Decoded body.
	 * @param string $error       Error key.
	 * @param int    $retry_after Seconds to wait.
	 * @return array
	 */
	private static function answer( $ok, $status, $data, $error, $retry_after = 0 ) {
		return array(
			'ok'          => (bool) $ok,
			'status'      => (int) $status,
			'data'        => $data,
			'error'       => (string) $error,
			'retry_after' => (int) $retry_after,
		);
	}

	/**
	 * Seconds this kind of call must still wait (0 = go).
	 *
	 * @param string $type Call type.
	 * @return int
	 */
	public static function backoff_left( $type ) {
		$all = get_option( self::BACKOFF_OPTION, array() );
		if ( ! is_array( $all ) || empty( $all[ $type ] ) ) {
			return 0;
		}
		return max( 0, (int) $all[ $type ] - time() );
	}

	/**
	 * Stops this kind of call until Smarfle's Retry-After (capped at a day).
	 *
	 * @param string $type    Call type.
	 * @param int    $seconds Seconds.
	 */
	public static function set_backoff( $type, $seconds ) {
		if ( in_array( $type, array( 'connect', 'disconnect' ), true ) ) {
			return;
		}
		$all          = get_option( self::BACKOFF_OPTION, array() );
		$all          = is_array( $all ) ? $all : array();
		$all[ $type ] = time() + min( self::MAX_BACKOFF, max( 60, (int) $seconds ) );
		update_option( self::BACKOFF_OPTION, $all, false );
	}

	/**
	 * Clears every wait (after a new connection).
	 */
	public static function clear_backoff() {
		delete_option( self::BACKOFF_OPTION );
	}
}

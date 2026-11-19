<?php
/**
 * Smarfle core: the one connection to Smarfle that every Smarfle plugin on the site shares.
 *
 * Holds the site key (server side only, never printed in a page), the weekly business info, the account state that
 * comes back with every answer, the Smarfle admin page, the owner's notices and the "From your website this week"
 * dashboard box.
 *
 * @package Smarfle
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-smarfle-api.php';
require_once __DIR__ . '/class-smarfle-visitor-guard.php';
require_once __DIR__ . '/class-smarfle-fallback.php';

/**
 * The shared core.
 */
final class Smarfle_Core {

	const OPTION_CONNECTION = 'smarfle_connection';
	const OPTION_SITE       = 'smarfle_site';
	const OPTION_SETTINGS   = 'smarfle_settings';
	const OPTION_STATS      = 'smarfle_stats';
	const CRON_WEEKLY       = 'smarfle_weekly_refresh';
	const PAGE              = 'smarfle';
	const DEFAULT_API_BASE  = 'https://www.smarfle.com';

	/**
	 * The loaded core.
	 *
	 * @var Smarfle_Core|null
	 */
	private static $instance = null;

	/**
	 * Core version that loaded.
	 *
	 * @var string
	 */
	public $version = '';

	/**
	 * Folder of the copy that loaded.
	 *
	 * @var string
	 */
	public $dir = '';

	/**
	 * URL of the copy that loaded.
	 *
	 * @var string
	 */
	public $url = '';

	/**
	 * Smarfle plugins active on this site: slug => array( name, version, section ).
	 *
	 * @var array
	 */
	private $plugins = array();

	/**
	 * Starts the core (called once by the loader).
	 *
	 * @param string $version Core version.
	 * @param string $dir     Folder of this copy.
	 * @param string $url     URL of this copy.
	 */
	public static function boot( $version, $dir, $url ) {
		if ( self::$instance ) {
			return;
		}
		$core           = new self();
		$core->version  = $version;
		$core->dir      = $dir;
		$core->url      = $url;
		self::$instance = $core;
		$core->hooks();
	}

	/**
	 * The loaded core, or null before plugins_loaded.
	 *
	 * @return Smarfle_Core|null
	 */
	public static function instance() {
		return self::$instance;
	}

	/**
	 * WordPress hooks.
	 */
	private function hooks() {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON_WEEKLY, array( $this, 'weekly_refresh' ) );
		add_action( Smarfle_Fallback::CRON_RETRY, array( 'Smarfle_Fallback', 'retry' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_finish_connect' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		foreach ( array( 'connect', 'disconnect', 'refresh', 'settings', 'credit' ) as $action ) {
			add_action( 'admin_post_smarfle_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * A Smarfle plugin announces itself (slug, name, version and its section on the Smarfle page).
	 *
	 * @param string $slug Plugin slug, for example smarfle-booking-forms.
	 * @param array  $args name, version, section (callable printing the plugin's checklist).
	 */
	public function register_plugin( $slug, $args ) {
		$this->plugins[ sanitize_key( $slug ) ] = wp_parse_args(
			$args,
			array(
				'name'    => $slug,
				'version' => '0.0.0',
				'section' => null,
			)
		);
	}

	/**
	 * Active Smarfle plugins.
	 *
	 * @return array
	 */
	public function plugins() {
		return $this->plugins;
	}

	/**
	 * "smarfle-booking-forms@1.0.0,..." for the X-Smarfle-Plugins header (shown to the owner in Smarfle).
	 *
	 * @return string
	 */
	public function plugins_header() {
		$parts = array();
		foreach ( $this->plugins as $slug => $p ) {
			$parts[] = $slug . '@' . $p['version'];
		}
		return implode( ',', $parts );
	}

	/* ------------------------------------------------------------------ Settings and addresses */

	/**
	 * Smarfle's address (a developer can point a test site at a local Smarfle with the SMARFLE_API_BASE constant).
	 *
	 * @return string
	 */
	public function api_base() {
		$base = defined( 'SMARFLE_API_BASE' ) ? (string) SMARFLE_API_BASE : self::DEFAULT_API_BASE;
		return untrailingslashit( esc_url_raw( $base ) );
	}

	/**
	 * A page in the owner's Smarfle account.
	 *
	 * @param string $path Path, for example /inbox.
	 * @return string
	 */
	public function app_url( $path ) {
		return $this->api_base() . $path;
	}

	/**
	 * Owner settings shared by the Smarfle plugins.
	 *
	 * @return array
	 */
	public function settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'credit'         => '',
				'fallback_email' => '',
			)
		);
	}

	/**
	 * Saves some owner settings.
	 *
	 * @param array $changes Settings to change.
	 */
	public function update_settings( $changes ) {
		update_option( self::OPTION_SETTINGS, array_merge( $this->settings(), $changes ), false );
	}

	/**
	 * Where inquiries go when Smarfle cannot take them (the owner's choice, else the site's admin email).
	 *
	 * @return string
	 */
	public function fallback_email() {
		$email = sanitize_email( $this->settings()['fallback_email'] );
		return $email ? $email : (string) get_option( 'admin_email' );
	}

	/* ------------------------------------------------------------------ Connection and account state */

	/**
	 * The stored connection.
	 *
	 * @return array
	 */
	public function connection() {
		$c = get_option( self::OPTION_CONNECTION, array() );
		return is_array( $c ) ? $c : array();
	}

	/**
	 * True when this site holds a Smarfle site key.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== $this->site_key();
	}

	/**
	 * The site key (server side only), decrypted.
	 *
	 * @return string
	 */
	public function site_key() {
		$c = $this->connection();
		return isset( $c['site_key'] ) ? self::open_key( (string) $c['site_key'] ) : '';
	}

	/**
	 * The secret the stored key is encrypted with: this site's own salt from wp-config.php, so a copy of the database
	 * alone (a leaked backup) does not hold a usable key.
	 *
	 * @return string 32 bytes.
	 */
	private static function key_secret() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|smarfle-site-key', true );
	}

	/**
	 * Encrypts the site key for storage (AES-256-GCM). Without OpenSSL the key is kept as it is.
	 *
	 * @param string $key Site key.
	 * @return string
	 */
	public static function seal_key( $key ) {
		if ( '' === $key || ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			return $key;
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$out = openssl_encrypt( $key, 'aes-256-gcm', self::key_secret(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $out ? $key : 'enc1:' . base64_encode( $iv . $tag . $out ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
	}

	/**
	 * Decrypts a stored site key ('' when it cannot be read, for example after the site's salts changed: the owner then
	 * connects again).
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function open_key( $stored ) {
		if ( 0 !== strpos( $stored, 'enc1:' ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext stored as text.
		if ( false === $raw || strlen( $raw ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$out = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key_secret(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $out ? '' : $out;
	}

	/**
	 * The account state from Smarfle's last answer: status, live, trial_days_left, message.
	 *
	 * @return array
	 */
	public function state() {
		$c = $this->connection();
		return isset( $c['state'] ) && is_array( $c['state'] ) ? $c['state'] : array(
			'status'          => 'inactive',
			'live'            => false,
			'trial_days_left' => null,
			'message'         => null,
		);
	}

	/**
	 * True when the plugins may use Smarfle (connected, on the trial or a paid plan).
	 *
	 * @return bool
	 */
	public function is_live() {
		$state = $this->state();
		return $this->is_connected() && ! empty( $state['live'] );
	}

	/**
	 * Keeps the state that came back with an answer.
	 *
	 * @param array $state The answer's state.
	 */
	public function save_state( $state ) {
		if ( ! $this->is_connected() || ! is_array( $state ) ) {
			return;
		}
		$c          = $this->connection();
		$c['state'] = array(
			'status'          => isset( $state['status'] ) ? sanitize_key( $state['status'] ) : 'inactive',
			'live'            => ! empty( $state['live'] ),
			'trial_days_left' => isset( $state['trial_days_left'] ) ? absint( $state['trial_days_left'] ) : null,
			'message'         => isset( $state['message'] ) ? sanitize_text_field( $state['message'] ) : null,
			'at'              => time(),
		);
		update_option( self::OPTION_CONNECTION, $c, false );
	}

	/**
	 * Forgets the connection here (Smarfle refused the key, or the owner disconnected).
	 *
	 * @param string $reason Why (shown to the owner).
	 */
	public function forget_connection( $reason = '' ) {
		update_option(
			self::OPTION_CONNECTION,
			array(
				'site_key'         => '',
				'disconnected_at'  => time(),
				'disconnect_cause' => sanitize_text_field( $reason ),
			),
			false
		);
		wp_clear_scheduled_hook( self::CRON_WEEKLY );
		/**
		 * The connection is gone: plugins drop what they kept from Smarfle.
		 */
		do_action( 'smarfle_disconnected' );
	}

	/* ------------------------------------------------------------------ Weekly business info */

	/**
	 * The saved business info: data, fetched_at.
	 *
	 * @return array
	 */
	public function site() {
		$s = get_option( self::OPTION_SITE, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * The saved business info itself (empty before the first refresh).
	 *
	 * @return array
	 */
	public function site_data() {
		$s = $this->site();
		return isset( $s['data'] ) && is_array( $s['data'] ) ? $s['data'] : array();
	}

	/**
	 * Fetches the business info from Smarfle (the weekly call). Keeps the saved copy when Smarfle cannot answer.
	 *
	 * @return array The API answer.
	 */
	public function refresh_site() {
		$res = Smarfle_Api::call( 'GET', '/site', null, array( 'type' => 'site' ) );
		if ( $res['ok'] ) {
			$site = $this->site();
			if ( isset( $res['data']['site'] ) && is_array( $res['data']['site'] ) ) {
				$site['data'] = $res['data']['site'];
			}
			$site['fetched_at'] = time();
			update_option( self::OPTION_SITE, $site, false );
		}
		return $res;
	}

	/**
	 * Schedules the weekly refresh while connected.
	 */
	public function schedule() {
		if ( $this->is_connected() && ! wp_next_scheduled( self::CRON_WEEKLY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_WEEKLY );
		}
	}

	/**
	 * The weekly refresh (also keeps an inactive site's state current: one call a week until it is live again).
	 */
	public function weekly_refresh() {
		if ( $this->is_connected() ) {
			$this->refresh_site();
			/**
			 * The weekly refresh: each Smarfle plugin refreshes what it keeps (one call each, at most).
			 *
			 * @param Smarfle_Core $core The core.
			 */
			do_action( 'smarfle_weekly', $this );
		}
	}

	/* ------------------------------------------------------------------ This week's counts (no calls) */

	/**
	 * Counts something a plugin sent to Smarfle this week (an inquiry, a booking).
	 *
	 * @param string $kind inquiries, bookings, emailed.
	 */
	public function count( $kind ) {
		$stats = $this->week_stats();
		$kind  = sanitize_key( $kind );
		if ( ! isset( $stats['counts'][ $kind ] ) ) {
			$stats['counts'][ $kind ] = 0;
		}
		++$stats['counts'][ $kind ];
		update_option( self::OPTION_STATS, $stats, false );
	}

	/**
	 * Sets this week's number for something a plugin learns as a total (new reviews this week).
	 *
	 * @param string $kind reviews.
	 * @param int    $n    Number.
	 */
	public function set_count( $kind, $n ) {
		$stats                                    = $this->week_stats();
		$stats['counts'][ sanitize_key( $kind ) ] = max( 0, (int) $n );
		update_option( self::OPTION_STATS, $stats, false );
	}

	/**
	 * This ISO week's counts.
	 *
	 * @return array week, counts.
	 */
	public function week_stats() {
		$week  = gmdate( 'o-\WW' );
		$stats = get_option( self::OPTION_STATS, array() );
		if ( ! is_array( $stats ) || ! isset( $stats['week'] ) || $stats['week'] !== $week ) {
			$stats = array(
				'week'   => $week,
				'counts' => array(),
			);
		}
		return $stats;
	}

	/* ------------------------------------------------------------------ Block wrapper */

	/**
	 * Puts a block's own wrapper around what a Smarfle renderer drew, so the editor's block settings (wide or full
	 * width, an extra CSS class) apply. Shortcodes and Elementor widgets do not call this. An empty answer stays empty.
	 *
	 * @param string $html The block's HTML.
	 * @return string
	 */
	public static function block_wrap( $html ) {
		if ( '' === trim( (string) $html ) || ! function_exists( 'get_block_wrapper_attributes' ) ) {
			return (string) $html;
		}
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/* ------------------------------------------------------------------ Credit line (off unless the owner says yes) */

	/**
	 * The "Powered by Smarfle" line under a Smarfle block, only when the owner chose to show it.
	 *
	 * @return string HTML.
	 */
	public function credit_html() {
		if ( 'yes' !== $this->settings()['credit'] ) {
			return '';
		}
		return '<p class="smarfle-credit">' . sprintf(
			/* translators: %s: Smarfle (a link). */
			esc_html__( 'Powered by %s', 'smarfle-booking-forms' ),
			'<a href="' . esc_url( 'https://www.smarfle.com/?utm_source=wordpress&utm_medium=plugin&utm_campaign=credit' ) . '" rel="nofollow noopener" target="_blank">Smarfle</a>'
		) . '</p>';
	}

	/* ------------------------------------------------------------------ Connect (PKCE), disconnect */

	/**
	 * The site address Smarfle checks the return address against.
	 *
	 * @return string
	 */
	private function connect_site_url() {
		return untrailingslashit( site_url() );
	}

	/**
	 * Start: send the owner to Smarfle's approve page with a PKCE challenge.
	 */
	public function handle_connect() {
		$this->guard_admin_post( 'smarfle_connect' );
		$verifier  = $this->random_token( 48 );
		$state     = $this->random_token( 24 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE S256 needs base64url.
		set_transient(
			'smarfle_pkce_' . get_current_user_id(),
			array(
				'verifier' => $verifier,
				'state'    => $state,
			),
			15 * MINUTE_IN_SECONDS
		);
		$url = add_query_arg(
			array(
				'site_url'       => rawurlencode( $this->connect_site_url() ),
				'site_name'      => rawurlencode( wp_strip_all_tags( get_bloginfo( 'name' ) ) ),
				'redirect_uri'   => rawurlencode( admin_url( 'admin.php?page=' . self::PAGE ) ),
				'code_challenge' => $challenge,
				'state'          => $state,
			),
			$this->app_url( '/connect/wordpress' )
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Smarfle's own approve page, a different host by design.
		exit;
	}

	/**
	 * Back from Smarfle with a one-time code: trade it (and the PKCE verifier) for the site key.
	 */
	public function maybe_finish_connect() {
		if ( ! isset( $_GET['page'], $_GET['smarfle_state'] ) || self::PAGE !== $_GET['page'] || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the PKCE state below is the check.
			return;
		}
		$pkce = get_transient( 'smarfle_pkce_' . get_current_user_id() );
		delete_transient( 'smarfle_pkce_' . get_current_user_id() );
		$state = sanitize_text_field( wp_unslash( $_GET['smarfle_state'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_array( $pkce ) || ! hash_equals( (string) $pkce['state'], $state ) ) {
			$this->redirect_page( 'connect_expired' );
		}
		if ( isset( $_GET['smarfle_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->redirect_page( 'connect_cancelled' );
		}
		$code = isset( $_GET['smarfle_code'] ) ? sanitize_text_field( wp_unslash( $_GET['smarfle_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) {
			$this->redirect_page( 'connect_failed' );
		}
		$res = Smarfle_Api::call(
			'POST',
			'/connect/token',
			array(
				'code'          => $code,
				'code_verifier' => $pkce['verifier'],
				'site_url'      => $this->connect_site_url(),
				'site_name'     => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			),
			array(
				'auth' => false,
				'type' => 'connect',
			)
		);
		if ( ! $res['ok'] || empty( $res['data']['site_key'] ) ) {
			$this->redirect_page( 'connect_failed' );
		}
		update_option(
			self::OPTION_CONNECTION,
			array(
				'site_key'     => self::seal_key( sanitize_text_field( $res['data']['site_key'] ) ),
				'site_id'      => isset( $res['data']['site_id'] ) ? sanitize_text_field( $res['data']['site_id'] ) : '',
				'business'     => isset( $res['data']['business']['name'] ) ? sanitize_text_field( $res['data']['business']['name'] ) : '',
				'connected_at' => time(),
			),
			false
		);
		if ( isset( $res['data']['state'] ) ) {
			$this->save_state( $res['data']['state'] );
		}
		delete_option( self::OPTION_SITE );
		Smarfle_Api::clear_backoff();
		$this->refresh_site();
		$this->schedule();
		/**
		 * A website just connected: each Smarfle plugin fetches what it keeps (for example the reviews).
		 *
		 * @param Smarfle_Core $core The core.
		 */
		do_action( 'smarfle_after_connect', $this );
		$this->redirect_page( 'connected' );
	}

	/**
	 * Disconnect: Smarfle stops the key at once; the forms email the owner again.
	 */
	public function handle_disconnect() {
		$this->guard_admin_post( 'smarfle_disconnect' );
		if ( $this->is_connected() ) {
			Smarfle_Api::call( 'POST', '/disconnect', array(), array( 'type' => 'disconnect' ) );
		}
		$this->forget_connection( __( 'You disconnected this website.', 'smarfle-booking-forms' ) );
		delete_option( self::OPTION_SITE );
		$this->redirect_page( 'disconnected' );
	}

	/**
	 * Refresh now (once a day) / Check again for an inactive account.
	 */
	public function handle_refresh() {
		$this->guard_admin_post( 'smarfle_refresh' );
		$last = (int) get_transient( 'smarfle_manual_refresh' );
		if ( $last ) {
			$this->redirect_page( 'refresh_wait' );
		}
		set_transient( 'smarfle_manual_refresh', time(), DAY_IN_SECONDS );
		$res = $this->refresh_site();
		$this->redirect_page( $res['ok'] ? 'refreshed' : 'refresh_failed' );
	}

	/**
	 * Owner settings: where inquiries go when Smarfle cannot take them.
	 */
	public function handle_settings() {
		$this->guard_admin_post( 'smarfle_settings' );
		$email = isset( $_POST['smarfle_fallback_email'] ) ? sanitize_email( wp_unslash( $_POST['smarfle_fallback_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_admin_post checked the nonce.
		$this->update_settings( array( 'fallback_email' => $email ) );
		$this->redirect_page( 'saved' );
	}

	/**
	 * The one credit question (WordPress.org guideline 10: off until the owner says yes).
	 */
	public function handle_credit() {
		$this->guard_admin_post( 'smarfle_credit' );
		$choice = isset( $_POST['smarfle_credit'] ) && 'yes' === $_POST['smarfle_credit'] ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_admin_post checked the nonce.
		$this->update_settings( array( 'credit' => $choice ) );
		$this->redirect_page( 'saved' );
	}

	/**
	 * Nonce and capability check for the Smarfle page's buttons.
	 *
	 * @param string $action Nonce action.
	 */
	public function guard_admin_post( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'smarfle-booking-forms' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the Smarfle page with a message.
	 *
	 * @param string $notice Message key.
	 */
	public function redirect_page( $notice ) {
		wp_safe_redirect( add_query_arg( 'smarfle_notice', $notice, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * A url-safe random token.
	 *
	 * @param int $bytes Random bytes.
	 * @return string
	 */
	private function random_token( $bytes ) {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- url-safe random token.
	}

	/* ------------------------------------------------------------------ Admin page, notices, dashboard box */

	/**
	 * The Smarfle menu.
	 */
	public function admin_menu() {
		add_menu_page(
			__( 'Smarfle', 'smarfle-booking-forms' ),
			__( 'Smarfle', 'smarfle-booking-forms' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' ),
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="40 48 400 416"><g fill="black"><rect x="72" y="160" width="90" height="28" rx="14"/><rect x="56" y="242" width="112" height="28" rx="14"/><rect x="72" y="324" width="90" height="28" rx="14"/><path d="M328 64L224 256h88L264 448l160-240H332z"/></g></svg>' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon as a data URI, the WordPress way.
			58
		);
	}

	/**
	 * Admin styles on the Smarfle page and the dashboard only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function admin_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE === $hook || 'index.php' === $hook ) {
			wp_enqueue_style( 'smarfle-admin', $this->url . '/assets/admin.css', array(), $this->version );
		}
	}

	/**
	 * One notice for the owner when Smarfle cannot take the website's inquiries (dashboard, plugins, Smarfle page).
	 */
	public function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_connected() || $this->is_live() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'toplevel_page_' . self::PAGE ), true ) ) {
			return;
		}
		$state = $this->state();
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Smarfle is inactive: website inquiries now go to your email.', 'smarfle-booking-forms' ) . '</strong> ';
		if ( ! empty( $state['message'] ) ) {
			echo esc_html( $state['message'] ) . ' ';
		}
		echo '<a href="' . esc_url( $this->app_url( '/subscription' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Choose a plan in Smarfle', 'smarfle-booking-forms' ) . '</a></p></div>';
	}

	/**
	 * The "From your website this week" box (no calls: the plugins count what they send).
	 */
	public function dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_connected() ) {
			return;
		}
		wp_add_dashboard_widget( 'smarfle_week', __( 'From your website this week', 'smarfle-booking-forms' ), array( $this, 'render_dashboard_widget' ) );
	}

	/**
	 * Prints the dashboard box.
	 */
	public function render_dashboard_widget() {
		$counts = $this->week_stats()['counts'];
		$parts  = array();
		$labels = array(
			/* translators: %d: number of inquiries. */
			'inquiries' => _n_noop( '%d inquiry', '%d inquiries', 'smarfle-booking-forms' ),
			/* translators: %d: number of bookings. */
			'bookings'  => _n_noop( '%d booking', '%d bookings', 'smarfle-booking-forms' ),
			/* translators: %d: number of new reviews. */
			'reviews'   => _n_noop( '%d new review', '%d new reviews', 'smarfle-booking-forms' ),
		);
		foreach ( $labels as $kind => $noop ) {
			$n = isset( $counts[ $kind ] ) ? (int) $counts[ $kind ] : 0;
			if ( $n > 0 || 'reviews' !== $kind ) {
				$parts[] = sprintf( translate_nooped_plural( $noop, $n, 'smarfle-booking-forms' ), $n );
			}
		}
		echo '<div class="smarfle-week"><p class="smarfle-week__counts">' . esc_html( implode( ', ', $parts ) ) . '</p>';
		echo '<p><a href="' . esc_url( $this->app_url( '/inbox' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the Inbox', 'smarfle-booking-forms' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( $this->app_url( '/calendar' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Calendar', 'smarfle-booking-forms' ) . '</a></p>';
		$state = $this->state();
		if ( 'trial' === $state['status'] && null !== $state['trial_days_left'] ) {
			echo '<p class="smarfle-week__trial">' . esc_html(
				sprintf(
					/* translators: %d: days left in the Smarfle trial. */
					_n( '%d day left in your Smarfle trial.', '%d days left in your Smarfle trial.', (int) $state['trial_days_left'], 'smarfle-booking-forms' ),
					(int) $state['trial_days_left']
				)
			) . ' <a href="' . esc_url( $this->app_url( '/subscription' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Plans from $19 a month', 'smarfle-booking-forms' ) . '</a></p>';
		} elseif ( ! $this->is_live() ) {
			echo '<p class="smarfle-week__trial">' . esc_html__( 'Smarfle is inactive: website inquiries go to your email.', 'smarfle-booking-forms' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * A button that posts to admin-post.php with a nonce.
	 *
	 * @param string $action  Action (smarfle_...).
	 * @param string $label   Button text.
	 * @param string $classes Button classes.
	 * @param array  $fields  Extra hidden fields.
	 */
	public function post_button( $action, $label, $classes = 'button', $fields = array() ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="smarfle-inline-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		wp_nonce_field( $action );
		echo '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * The Smarfle page: connection, account, then each Smarfle plugin's checklist.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notices = array(
			'connected'         => array( 'success', __( 'Connected to Smarfle.', 'smarfle-booking-forms' ) ),
			'disconnected'      => array( 'success', __( 'Disconnected. Your forms email you again.', 'smarfle-booking-forms' ) ),
			'connect_cancelled' => array( 'warning', __( 'Nothing was connected.', 'smarfle-booking-forms' ) ),
			'connect_expired'   => array( 'error', __( 'That connection request expired. Press Connect to Smarfle again.', 'smarfle-booking-forms' ) ),
			'connect_failed'    => array( 'error', __( 'Smarfle could not connect this website. Press Connect to Smarfle again.', 'smarfle-booking-forms' ) ),
			'refreshed'         => array( 'success', __( 'Business info refreshed from Smarfle.', 'smarfle-booking-forms' ) ),
			'refresh_wait'      => array( 'warning', __( 'Business info can be refreshed once a day. It also refreshes by itself every week.', 'smarfle-booking-forms' ) ),
			'refresh_failed'    => array( 'error', __( 'Smarfle did not answer. Your saved copy stays; try again tomorrow.', 'smarfle-booking-forms' ) ),
			'saved'             => array( 'success', __( 'Saved.', 'smarfle-booking-forms' ) ),
		);
		$notice  = isset( $_GET['smarfle_notice'] ) ? sanitize_key( wp_unslash( $_GET['smarfle_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message key only.
		$extra   = apply_filters( 'smarfle_page_notices', array() );
		if ( is_array( $extra ) ) {
			$notices = array_merge( $notices, $extra );
		}

		echo '<div class="wrap smarfle-page"><h1>' . esc_html__( 'Smarfle', 'smarfle-booking-forms' ) . '</h1>';
		if ( $notice && isset( $notices[ $notice ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $notices[ $notice ][0] ) . ' is-dismissible"><p>' . esc_html( $notices[ $notice ][1] ) . '</p></div>';
		}

		echo '<div class="smarfle-card">';
		if ( ! $this->is_connected() ) {
			$c = $this->connection();
			echo '<h2>' . esc_html__( 'Connect your website to Smarfle', 'smarfle-booking-forms' ) . '</h2>';
			echo '<p>' . esc_html__( 'Website inquiries and bookings go straight into your Smarfle Inbox and calendar, followed up for you. You approve the connection in Smarfle; this website never sees your password.', 'smarfle-booking-forms' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'For US home service and local businesses with a Smarfle account. New to Smarfle? You can start the 14-day free trial on the next screen.', 'smarfle-booking-forms' ) . '</p>';
			if ( ! empty( $c['disconnect_cause'] ) ) {
				echo '<p class="description">' . esc_html( $c['disconnect_cause'] ) . '</p>';
			}
			$this->post_button( 'smarfle_connect', __( 'Connect to Smarfle', 'smarfle-booking-forms' ), 'button button-primary button-hero' );
		} else {
			$c     = $this->connection();
			$state = $this->state();
			$site  = $this->site();
			echo '<h2>' . esc_html(
				sprintf(
					/* translators: %s: the business name in Smarfle. */
					__( 'Connected to %s', 'smarfle-booking-forms' ),
					! empty( $c['business'] ) ? $c['business'] : __( 'your Smarfle account', 'smarfle-booking-forms' )
				)
			) . '</h2>';
			if ( 'trial' === $state['status'] ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: %d: days left in the trial. */
						_n( 'Smarfle trial: %d day left. Everything works during the trial.', 'Smarfle trial: %d days left. Everything works during the trial.', (int) $state['trial_days_left'], 'smarfle-booking-forms' ),
						(int) $state['trial_days_left']
					)
				) . ' <a href="' . esc_url( $this->app_url( '/subscription' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Plans from $19 a month', 'smarfle-booking-forms' ) . '</a></p>';
			} elseif ( 'paid' === $state['status'] ) {
				echo '<p>' . esc_html__( 'Your Smarfle plan is active.', 'smarfle-booking-forms' ) . '</p>';
			} else {
				echo '<p><strong>' . esc_html__( 'Smarfle is inactive: website inquiries now go to your email.', 'smarfle-booking-forms' ) . '</strong> ' . esc_html( (string) $state['message'] ) . '</p>';
			}
			$fetched = isset( $site['fetched_at'] ) ? (int) $site['fetched_at'] : 0;
			echo '<p class="description">' . esc_html(
				$fetched
					? sprintf(
						/* translators: %s: how long ago, for example "2 days". */
						__( 'Business info (services, phone, booking) last refreshed %s ago; it refreshes by itself every week.', 'smarfle-booking-forms' ),
						human_time_diff( $fetched )
					)
					: __( 'Business info has not been fetched yet.', 'smarfle-booking-forms' )
			) . '</p>';
			echo '<div class="smarfle-actions">';
			$this->post_button( 'smarfle_refresh', $this->is_live() ? __( 'Refresh now', 'smarfle-booking-forms' ) : __( 'Check again', 'smarfle-booking-forms' ) );
			echo '<a class="button" href="' . esc_url( $this->app_url( '/inbox' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open Smarfle', 'smarfle-booking-forms' ) . '</a>';
			$this->post_button( 'smarfle_disconnect', __( 'Disconnect', 'smarfle-booking-forms' ), 'button button-link-delete' );
			echo '</div>';
		}
		echo '</div>';

		// The one credit question, until answered (nothing pre-ticked).
		$settings = $this->settings();
		if ( $this->is_connected() && '' === $settings['credit'] ) {
			echo '<div class="smarfle-card"><h2>' . esc_html__( 'Show a small "Powered by Smarfle" line under Smarfle forms?', 'smarfle-booking-forms' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Optional. You can change it any time on this page.', 'smarfle-booking-forms' ) . '</p><div class="smarfle-actions">';
			$this->post_button( 'smarfle_credit', __( 'Yes, show it', 'smarfle-booking-forms' ), 'button', array( 'smarfle_credit' => 'yes' ) );
			$this->post_button( 'smarfle_credit', __( 'No thanks', 'smarfle-booking-forms' ), 'button', array( 'smarfle_credit' => 'no' ) );
			echo '</div></div>';
		}

		foreach ( $this->plugins as $plugin ) {
			if ( is_callable( $plugin['section'] ) ) {
				echo '<div class="smarfle-card">';
				call_user_func( $plugin['section'], $this );
				echo '</div>';
			}
		}

		// Settings shared by every Smarfle plugin.
		echo '<div class="smarfle-card"><h2>' . esc_html__( 'Settings', 'smarfle-booking-forms' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="smarfle_settings">';
		wp_nonce_field( 'smarfle_settings' );
		echo '<p><label for="smarfle_fallback_email"><strong>' . esc_html__( 'When Smarfle cannot take an inquiry, email it to', 'smarfle-booking-forms' ) . '</strong></label><br>';
		echo '<input type="email" class="regular-text" id="smarfle_fallback_email" name="smarfle_fallback_email" value="' . esc_attr( $settings['fallback_email'] ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '"></p>';
		if ( '' !== $settings['credit'] ) {
			echo '<p class="description">' . esc_html( 'yes' === $settings['credit'] ? __( 'The "Powered by Smarfle" line is shown.', 'smarfle-booking-forms' ) : __( 'The "Powered by Smarfle" line is hidden.', 'smarfle-booking-forms' ) ) . '</p>';
		}
		echo '<p><button type="submit" class="button">' . esc_html__( 'Save', 'smarfle-booking-forms' ) . '</button></p></form>';
		if ( '' !== $settings['credit'] ) {
			$this->post_button( 'smarfle_credit', 'yes' === $settings['credit'] ? __( 'Hide the Powered by line', 'smarfle-booking-forms' ) : __( 'Show the Powered by line', 'smarfle-booking-forms' ), 'button-link', array( 'smarfle_credit' => 'yes' === $settings['credit'] ? 'no' : 'yes' ) );
		}
		echo '</div></div>';
	}
}

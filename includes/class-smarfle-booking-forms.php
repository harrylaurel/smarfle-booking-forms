<?php
/**
 * Smarfle Booking & Forms: the booking form and contact form (Gutenberg blocks, Elementor widgets and shortcodes, all
 * drawn by one renderer), the website's own endpoints the forms post to, Contact Form 7 and WPForms sending to
 * Smarfle, and the setup checklist on the Smarfle page.
 *
 * @package Smarfle_Booking_Forms
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-smarfle-bf-render.php';
require_once __DIR__ . '/class-smarfle-bf-endpoints.php';

/**
 * The plugin.
 */
final class Smarfle_Booking_Forms {

	const SLUG   = 'smarfle-booking-forms';
	const OPTION = 'smarfle_booking_forms';

	/**
	 * Hooks, once the shared core has loaded.
	 */
	public static function init() {
		$core = class_exists( 'Smarfle_Core', false ) ? Smarfle_Core::instance() : null;
		if ( ! $core ) {
			return;
		}
		$core->register_plugin(
			self::SLUG,
			array(
				'name'    => 'Booking & Forms',
				'version' => SMARFLE_BOOKING_FORMS_VERSION,
				'section' => array( __CLASS__, 'render_section' ),
			)
		);
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'rest_api_init', array( 'Smarfle_BF_Endpoints', 'register_routes' ) );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_elementor' ) );
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'from_cf7' ) );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'from_wpforms' ), 10, 3 );
		add_action( 'admin_post_smarfle_bf_connector', array( __CLASS__, 'handle_connector' ) );
		add_action( 'admin_post_smarfle_bf_booking_page', array( __CLASS__, 'handle_booking_page' ) );
		add_action( 'admin_post_smarfle_bf_test_lead', array( __CLASS__, 'handle_test_lead' ) );
		add_filter( 'smarfle_page_notices', array( __CLASS__, 'page_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SMARFLE_BOOKING_FORMS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * This plugin's own settings.
	 *
	 * @return array cf7, wpforms, booking_page, test_client.
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'cf7'          => false,
				'wpforms'      => false,
				'booking_page' => 0,
				'test_client'  => '',
			)
		);
	}

	/**
	 * Saves some of this plugin's settings.
	 *
	 * @param array $changes Changes.
	 */
	public static function update_settings( $changes ) {
		update_option( self::OPTION, array_merge( self::settings(), $changes ), false );
	}

	/**
	 * "Settings" next to Deactivate on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Smarfle_Core::PAGE ) ) . '">' . esc_html__( 'Settings', 'smarfle-booking-forms' ) . '</a>' );
		return $links;
	}

	/* ------------------------------------------------------------------ Blocks, shortcodes, Elementor */

	/**
	 * Registers the two blocks (drawn on the server) and the shortcodes.
	 */
	public static function register_blocks() {
		$url = plugins_url( 'assets/', SMARFLE_BOOKING_FORMS_FILE );
		// Minified files load unless SCRIPT_DEBUG; the readable sources ship next to them.
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_register_style( 'smarfle-forms', $url . 'forms' . $min . '.css', array(), SMARFLE_BOOKING_FORMS_VERSION );
		wp_register_script( 'smarfle-contact-form', $url . 'contact-form' . $min . '.js', array(), SMARFLE_BOOKING_FORMS_VERSION, array( 'in_footer' => true ) );
		wp_register_script( 'smarfle-booking-form', $url . 'booking-form' . $min . '.js', array(), SMARFLE_BOOKING_FORMS_VERSION, array( 'in_footer' => true ) );
		wp_register_script(
			'smarfle-bf-editor',
			$url . 'editor' . $min . '.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			SMARFLE_BOOKING_FORMS_VERSION,
			array( 'in_footer' => true )
		);
		if ( is_admin() ) {
			wp_add_inline_script( 'smarfle-bf-editor', 'window.smarfleBookingForms = ' . wp_json_encode( Smarfle_BF_Render::editor_data() ) . ';', 'before' );
		}
		wp_set_script_translations( 'smarfle-bf-editor', 'smarfle-booking-forms' );

		register_block_type(
			SMARFLE_BOOKING_FORMS_DIR . '/blocks/booking-form',
			array( 'render_callback' => array( 'Smarfle_BF_Render', 'booking_block' ) )
		);
		register_block_type(
			SMARFLE_BOOKING_FORMS_DIR . '/blocks/contact-form',
			array( 'render_callback' => array( 'Smarfle_BF_Render', 'contact_block' ) )
		);
		add_shortcode( 'smarfle_booking', array( 'Smarfle_BF_Render', 'booking_shortcode' ) );
		add_shortcode( 'smarfle_contact_form', array( 'Smarfle_BF_Render', 'contact_shortcode' ) );
	}

	/**
	 * Elementor widgets (only when Elementor is active).
	 *
	 * @param object $widgets_manager Elementor's widgets manager.
	 */
	public static function register_elementor( $widgets_manager ) {
		require_once __DIR__ . '/elementor/class-smarfle-bf-elementor-booking.php';
		require_once __DIR__ . '/elementor/class-smarfle-bf-elementor-contact.php';
		$widgets_manager->register( new Smarfle_BF_Elementor_Booking() );
		$widgets_manager->register( new Smarfle_BF_Elementor_Contact() );
	}

	/* ------------------------------------------------------------------ Existing forms: Contact Form 7, WPForms */

	/**
	 * Contact Form 7: after its email went out (its own spam checks passed), send the entry to Smarfle too.
	 *
	 * @param object $contact_form The CF7 form.
	 */
	public static function from_cf7( $contact_form ) {
		if ( ! self::settings()['cf7'] || ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$fields = array();
		foreach ( (array) $submission->get_posted_data() as $name => $value ) {
			if ( '' === $name || '_' === $name[0] || count( $fields ) >= 40 ) {
				continue;
			}
			$value = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			if ( '' !== trim( $value ) ) {
				$fields[ sanitize_text_field( $name ) ] = sanitize_textarea_field( $value );
			}
		}
		self::forward_existing_form(
			array(
				'form'          => method_exists( $contact_form, 'title' ) ? sanitize_text_field( $contact_form->title() ) : 'Contact Form 7',
				'fields'        => $fields,
				'page_url'      => esc_url_raw( (string) $submission->get_meta( 'url' ) ),
				'visitor_ip'    => sanitize_text_field( (string) $submission->get_meta( 'remote_ip' ) ),
				'visitor_agent' => substr( sanitize_text_field( (string) $submission->get_meta( 'user_agent' ) ), 0, 300 ),
			)
		);
	}

	/**
	 * WPForms: after an entry is processed, send it to Smarfle too (field labels, which Smarfle matches for you).
	 *
	 * @param array $fields    Processed fields.
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form settings.
	 */
	public static function from_wpforms( $fields, $entry, $form_data ) {
		if ( ! self::settings()['wpforms'] || ! is_array( $fields ) ) {
			return;
		}
		$out = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || in_array( isset( $field['type'] ) ? $field['type'] : '', array( 'html', 'divider', 'pagebreak', 'captcha', 'content' ), true ) ) {
				continue;
			}
			$label = isset( $field['name'] ) ? sanitize_text_field( $field['name'] ) : '';
			$value = isset( $field['value'] ) ? sanitize_textarea_field( (string) $field['value'] ) : '';
			if ( '' !== $label && '' !== trim( $value ) && count( $out ) < 40 ) {
				$out[ $label ] = $value;
			}
		}
		$referer = wp_get_referer();
		self::forward_existing_form(
			array(
				'form'          => isset( $form_data['settings']['form_title'] ) ? sanitize_text_field( $form_data['settings']['form_title'] ) : 'WPForms',
				'fields'        => $out,
				'page_url'      => $referer ? esc_url_raw( $referer ) : '',
				'visitor_ip'    => Smarfle_Visitor_Guard::visitor_ip(),
				'visitor_agent' => Smarfle_Visitor_Guard::visitor_agent(),
			)
		);
	}

	/**
	 * Sends an existing form's entry to Smarfle. The form plugin already emailed it, so there is no email fallback;
	 * a temporary failure keeps a copy to send later.
	 *
	 * @param array $payload The /lead body.
	 */
	private static function forward_existing_form( $payload ) {
		$core = Smarfle_Core::instance();
		if ( empty( $payload['fields'] ) || ! $core->is_live() || ! Smarfle_Visitor_Guard::take_site_day() ) {
			return;
		}
		$res = Smarfle_Api::call( 'POST', '/lead', $payload, array( 'type' => 'action' ) );
		if ( $res['ok'] ) {
			$core->count( 'inquiries' );
		} elseif ( Smarfle_Fallback::is_temporary( $res ) ) {
			Smarfle_Fallback::queue( '/lead', $payload );
		}
	}

	/* ------------------------------------------------------------------ Setup checklist on the Smarfle page */

	/**
	 * Messages for this plugin's buttons.
	 *
	 * @param array $notices Notices.
	 * @return array
	 */
	public static function page_notices( $notices ) {
		$notices['bf_saved']       = array( 'success', __( 'Saved.', 'smarfle-booking-forms' ) );
		$notices['bf_test_sent']   = array( 'success', __( 'Test lead sent. Open it in Smarfle below.', 'smarfle-booking-forms' ) );
		$notices['bf_test_failed'] = array( 'error', __( 'Smarfle did not take the test lead. Check that the account is active, then try again.', 'smarfle-booking-forms' ) );
		$notices['bf_test_wait']   = array( 'warning', __( 'One test lead a day is enough: the last one is in Smarfle.', 'smarfle-booking-forms' ) );
		return $notices;
	}

	/**
	 * The Booking & Forms checklist.
	 *
	 * @param Smarfle_Core $core The core.
	 */
	public static function render_section( $core ) {
		$settings = self::settings();
		$site     = $core->site_data();
		$booking  = isset( $site['booking'] ) && is_array( $site['booking'] ) ? $site['booking'] : array();
		$live     = $core->is_live();

		echo '<h2>' . esc_html__( 'Booking & Forms', 'smarfle-booking-forms' ) . '</h2>';
		echo '<ol class="smarfle-checklist">';

		// 1. Forms.
		echo '<li><strong>' . esc_html__( 'Contact form', 'smarfle-booking-forms' ) . '</strong><br>';
		echo esc_html__( 'Add the "Smarfle contact form" block (or Elementor widget, or the [smarfle_contact_form] shortcode) to any page. Without an active Smarfle account it emails you.', 'smarfle-booking-forms' ) . '</li>';

		// 2. Online booking.
		echo '<li><strong>' . esc_html__( 'Online booking', 'smarfle-booking-forms' ) . '</strong><br>';
		if ( ! $core->is_connected() ) {
			echo esc_html__( 'Connect to Smarfle above to show your services and real free times.', 'smarfle-booking-forms' );
		} elseif ( empty( $booking['enabled'] ) ) {
			echo esc_html__( 'Online booking is off in Smarfle.', 'smarfle-booking-forms' ) . ' <a href="' . esc_url( $core->app_url( '/calendar?booking=1' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Turn it on in Smarfle', 'smarfle-booking-forms' ) . '</a>, ' . esc_html__( 'then press Refresh now.', 'smarfle-booking-forms' );
		} else {
			$page = (int) $settings['booking_page'];
			if ( $page && get_post( $page ) && 'trash' !== get_post_status( $page ) ) {
				echo '<span class="smarfle-checklist__done">' . esc_html__( 'Done.', 'smarfle-booking-forms' ) . '</span> ';
				echo '<a href="' . esc_url( get_edit_post_link( $page ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>';
				if ( 'publish' !== get_post_status( $page ) ) {
					echo ' ' . esc_html__( '(a draft: publish it when it looks right)', 'smarfle-booking-forms' );
				}
			} else {
				echo esc_html__( 'Add the "Smarfle booking form" block to a page, or let us make the page for you (a draft you can check before publishing).', 'smarfle-booking-forms' );
				echo '<div class="smarfle-checklist__step-actions">';
				$core->post_button( 'smarfle_bf_booking_page', __( 'Create a Book online page', 'smarfle-booking-forms' ), 'button button-primary' );
				echo '</div>';
			}
		}
		echo '</li>';

		// 3. Existing forms.
		$has_cf7     = class_exists( 'WPCF7_Submission' );
		$has_wpforms = function_exists( 'wpforms' );
		if ( $has_cf7 || $has_wpforms ) {
			echo '<li><strong>' . esc_html__( 'Your existing forms', 'smarfle-booking-forms' ) . '</strong><br>';
			echo esc_html__( 'Send their entries to Smarfle too (they keep emailing you as before; Smarfle matches the fields for you).', 'smarfle-booking-forms' );
			echo '<div class="smarfle-checklist__step-actions">';
			if ( $has_cf7 ) {
				self::connector_button( $core, 'cf7', 'Contact Form 7', $settings['cf7'] );
			}
			if ( $has_wpforms ) {
				self::connector_button( $core, 'wpforms', 'WPForms', $settings['wpforms'] );
			}
			echo '</div></li>';
		}

		// 4. Test lead.
		echo '<li><strong>' . esc_html__( 'Send a test lead', 'smarfle-booking-forms' ) . '</strong><br>';
		if ( ! $live ) {
			echo esc_html__( 'Available once this website is connected to an active Smarfle account.', 'smarfle-booking-forms' );
		} else {
			echo esc_html__( 'A test inquiry with no email or phone (nobody is contacted) lands in your Smarfle Inbox.', 'smarfle-booking-forms' );
			echo '<div class="smarfle-checklist__step-actions">';
			$core->post_button( 'smarfle_bf_test_lead', __( 'Send a test lead', 'smarfle-booking-forms' ) );
			if ( '' !== $settings['test_client'] ) {
				echo '<a href="' . esc_url( $core->app_url( '/clients/' . rawurlencode( $settings['test_client'] ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the test lead in Smarfle', 'smarfle-booking-forms' ) . '</a>';
			}
			echo '</div>';
		}
		echo '</li></ol>';
	}

	/**
	 * An on/off button for one existing-form plugin.
	 *
	 * @param Smarfle_Core $core Core.
	 * @param string       $key  cf7 or wpforms.
	 * @param string       $name Plugin name.
	 * @param bool         $on   Current state.
	 */
	private static function connector_button( $core, $key, $name, $on ) {
		$core->post_button(
			'smarfle_bf_connector',
			$on
				/* translators: %s: Contact Form 7 or WPForms. */
				? sprintf( __( 'Stop sending %s to Smarfle', 'smarfle-booking-forms' ), $name )
				/* translators: %s: Contact Form 7 or WPForms. */
				: sprintf( __( 'Send %s entries to Smarfle', 'smarfle-booking-forms' ), $name ),
			$on ? 'button' : 'button button-primary',
			array(
				'connector' => $key,
				'turn'      => $on ? 'off' : 'on',
			)
		);
		if ( $on ) {
			echo '<span class="smarfle-checklist__done">' . esc_html(
				/* translators: %s: Contact Form 7 or WPForms. */
				sprintf( __( '%s entries go to Smarfle.', 'smarfle-booking-forms' ), $name )
			) . '</span>';
		}
	}

	/**
	 * Turns a connector on or off.
	 */
	public static function handle_connector() {
		$core = Smarfle_Core::instance();
		$core->guard_admin_post( 'smarfle_bf_connector' );
		$key = isset( $_POST['connector'] ) ? sanitize_key( wp_unslash( $_POST['connector'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_admin_post checked the nonce.
		$on  = isset( $_POST['turn'] ) && 'on' === $_POST['turn']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( in_array( $key, array( 'cf7', 'wpforms' ), true ) ) {
			self::update_settings( array( $key => $on ) );
		}
		$core->redirect_page( 'bf_saved' );
	}

	/**
	 * Creates a "Book online" page holding the booking form, as a draft, and opens it in the editor.
	 */
	public static function handle_booking_page() {
		$core = Smarfle_Core::instance();
		$core->guard_admin_post( 'smarfle_bf_booking_page' );
		$page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => __( 'Book online', 'smarfle-booking-forms' ),
				'post_content' => '<!-- wp:smarfle/booking-form /-->',
			),
			true
		);
		if ( is_wp_error( $page ) ) {
			$core->redirect_page( 'bf_test_failed' );
		}
		self::update_settings( array( 'booking_page' => (int) $page ) );
		wp_safe_redirect( (string) get_edit_post_link( (int) $page, 'raw' ) );
		exit;
	}

	/**
	 * Sends one test lead (at most once a day).
	 */
	public static function handle_test_lead() {
		$core = Smarfle_Core::instance();
		$core->guard_admin_post( 'smarfle_bf_test_lead' );
		if ( get_transient( 'smarfle_bf_test_lead' ) ) {
			$core->redirect_page( 'bf_test_wait' );
		}
		$res = Smarfle_Api::call( 'POST', '/lead', array( 'test' => true ), array( 'type' => 'action' ) );
		if ( ! $res['ok'] || empty( $res['data']['client_id'] ) ) {
			$core->redirect_page( 'bf_test_failed' );
		}
		set_transient( 'smarfle_bf_test_lead', 1, DAY_IN_SECONDS );
		self::update_settings( array( 'test_client' => sanitize_text_field( $res['data']['client_id'] ) ) );
		$core->redirect_page( 'bf_test_sent' );
	}
}

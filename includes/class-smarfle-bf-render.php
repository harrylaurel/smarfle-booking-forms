<?php
/**
 * One renderer for every way the forms appear: the Gutenberg blocks, the Elementor widgets and the shortcodes.
 * Scripts and styles load only on pages that show a form.
 *
 * @package Smarfle_Booking_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the booking form and the contact form.
 */
final class Smarfle_BF_Render {

	/**
	 * Counter for unique field ids when a page has several forms.
	 *
	 * @var int
	 */
	private static $n = 0;

	/* ------------------------------------------------------------------ Entry points */

	/**
	 * Block render callback: booking form.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function booking_block( $attributes ) {
		return Smarfle_Core::block_wrap( self::booking_form( is_array( $attributes ) ? $attributes : array() ) );
	}

	/**
	 * Block render callback: contact form.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function contact_block( $attributes ) {
		return Smarfle_Core::block_wrap( self::contact_form( is_array( $attributes ) ? $attributes : array() ) );
	}

	/**
	 * [smarfle_booking service="" office="" heading=""]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function booking_shortcode( $atts ) {
		return self::booking_form(
			shortcode_atts(
				array(
					'service' => '',
					'office'  => '',
					'heading' => '',
				),
				$atts,
				'smarfle_booking'
			)
		);
	}

	/**
	 * [smarfle_contact_form heading="" button="" form=""]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function contact_shortcode( $atts ) {
		return self::contact_form(
			shortcode_atts(
				array(
					'heading' => '',
					'button'  => '',
					'form'    => '',
				),
				$atts,
				'smarfle_contact_form'
			)
		);
	}

	/**
	 * Services and offices for the block editor and Elementor (names only).
	 *
	 * @return array
	 */
	public static function editor_data() {
		$core = Smarfle_Core::instance();
		$site = $core ? $core->site_data() : array();
		$out  = array(
			'connected' => $core && $core->is_connected(),
			'services'  => array(),
			'offices'   => array(),
		);
		foreach ( self::services( $site ) as $s ) {
			$out['services'][] = array(
				'id'   => $s['id'],
				'name' => $s['label'],
			);
		}
		foreach ( self::offices( $site ) as $o ) {
			$out['offices'][] = array(
				'id'   => $o['id'],
				'name' => $o['name'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Contact form */

	/**
	 * The contact form. Works without Smarfle (it emails the owner); with an active Smarfle account every inquiry goes
	 * to the Smarfle Inbox.
	 *
	 * @param array $a heading, button, form.
	 * @return string
	 */
	public static function contact_form( $a ) {
		$core    = Smarfle_Core::instance();
		$site    = $core->site_data();
		$id      = 'smarfle-c' . ( ++self::$n );
		$heading = isset( $a['heading'] ) ? sanitize_text_field( $a['heading'] ) : '';
		$button  = ! empty( $a['button'] ) ? sanitize_text_field( $a['button'] ) : __( 'Send', 'smarfle-booking-forms' );
		$form    = ! empty( $a['form'] ) ? sanitize_text_field( $a['form'] ) : __( 'Website contact form', 'smarfle-booking-forms' );
		$consent = $core->is_live() && isset( $site['consent']['text'], $site['consent']['version'] ) ? $site['consent'] : null;

		wp_enqueue_style( 'smarfle-forms' );
		wp_enqueue_script( 'smarfle-contact-form' );

		$config = array(
			'endpoint' => esc_url_raw( rest_url( 'smarfle/v1/contact' ) ),
			'consent'  => $consent ? sanitize_text_field( $consent['version'] ) : '',
			'strings'  => array(
				'sending' => __( 'Sending...', 'smarfle-booking-forms' ),
				'sent'    => __( 'Thanks! Your message was sent. We will get back to you soon.', 'smarfle-booking-forms' ),
				'error'   => __( 'Your message could not be sent. Please try again or call us.', 'smarfle-booking-forms' ),
				'missing' => __( 'Please fill in your name, email and message.', 'smarfle-booking-forms' ),
			),
		);

		$html  = '<div class="smarfle-form-wrap">';
		$html .= '<form class="smarfle-form smarfle-contact" novalidate data-smarfle-config="' . esc_attr( wp_json_encode( $config ) ) . '">';
		if ( '' !== $heading ) {
			$html .= '<h3 class="smarfle-form__heading">' . esc_html( $heading ) . '</h3>';
		}
		$html .= '<input type="hidden" name="form" value="' . esc_attr( $form ) . '">';
		$html .= self::field( $id . '-name', 'name', __( 'Name', 'smarfle-booking-forms' ), 'text', true, 'name' );
		$html .= '<div class="smarfle-form__row">';
		$html .= self::field( $id . '-email', 'email', __( 'Email', 'smarfle-booking-forms' ), 'email', true, 'email' );
		$html .= self::field( $id . '-phone', 'phone', __( 'Phone', 'smarfle-booking-forms' ), 'tel', false, 'tel' );
		$html .= '</div>';
		$html .= '<p class="smarfle-form__field"><label for="' . esc_attr( $id . '-message' ) . '">' . esc_html__( 'Message', 'smarfle-booking-forms' ) . ' <span aria-hidden="true">*</span></label>';
		$html .= '<textarea id="' . esc_attr( $id . '-message' ) . '" name="message" rows="5" required maxlength="4000"></textarea></p>';
		if ( $consent ) {
			$html .= self::consent_box( $id . '-consent', self::linked_consent( (string) $consent['text'], $consent ) );
		}
		$html .= self::honeypot();
		$html .= '<p class="smarfle-form__actions"><button type="submit" class="smarfle-form__button wp-element-button">' . esc_html( $button ) . '</button></p>';
		$html .= '<p class="smarfle-form__status" role="status" aria-live="polite"></p>';
		$html .= '</form>' . $core->credit_html() . '</div>';
		return $html;
	}

	/* ------------------------------------------------------------------ Booking form */

	/**
	 * The booking form: a service, a real free time from Smarfle, the customer's details. Without an active Smarfle
	 * account (or with online booking off in Smarfle) it becomes a "Call us" box.
	 *
	 * @param array $a service, office, heading.
	 * @return string
	 */
	public static function booking_form( $a ) {
		$core     = Smarfle_Core::instance();
		$site     = $core->site_data();
		$heading  = isset( $a['heading'] ) ? sanitize_text_field( $a['heading'] ) : '';
		$preset   = isset( $a['service'] ) ? sanitize_text_field( $a['service'] ) : '';
		$office   = isset( $a['office'] ) ? sanitize_text_field( $a['office'] ) : '';
		$booking  = isset( $site['booking'] ) && is_array( $site['booking'] ) ? $site['booking'] : array();
		$phone    = isset( $site['business']['phone'] ) ? sanitize_text_field( $site['business']['phone'] ) : '';
		$services = self::services( $site );
		if ( '' !== $preset ) {
			$services = array_values(
				array_filter(
					$services,
					function ( $s ) use ( $preset ) {
						return $s['id'] === $preset || ( '' !== $s['group'] && $s['group'] === $preset );
					}
				)
			);
		}

		wp_enqueue_style( 'smarfle-forms' );

		if ( ! $core->is_connected() ) {
			return self::editor_notice( __( 'Smarfle booking form: connect this website to Smarfle (Smarfle menu) to show your services and real free times. Visitors see nothing until then.', 'smarfle-booking-forms' ) );
		}
		if ( ! $core->is_live() || empty( $booking['enabled'] ) || empty( $services ) ) {
			$why = ! $core->is_live()
				? __( 'Smarfle is inactive, so this booking form shows a Call us box.', 'smarfle-booking-forms' )
				: ( empty( $booking['enabled'] ) ? __( 'Online booking is off in Smarfle, so this form shows a Call us box.', 'smarfle-booking-forms' ) : __( 'No service to book here: check the service chosen for this block, and that the service shows on your website in Smarfle.', 'smarfle-booking-forms' ) );
			return self::editor_notice( $why ) . self::call_us( $heading, $phone );
		}

		wp_enqueue_script( 'smarfle-booking-form' );
		$consent = isset( $site['consent'] ) && is_array( $site['consent'] ) ? $site['consent'] : array();
		$offices = self::offices( $site );
		if ( '' !== $office ) {
			$offices = array_values(
				array_filter(
					$offices,
					function ( $o ) use ( $office ) {
						return $o['id'] === $office;
					}
				)
			);
		}
		$questions = array();
		foreach ( isset( $booking['questions'] ) && is_array( $booking['questions'] ) ? $booking['questions'] : array() as $q ) {
			if ( ! is_array( $q ) || empty( $q['id'] ) || empty( $q['label'] ) ) {
				continue;
			}
			$questions[] = array(
				'id'       => sanitize_text_field( $q['id'] ),
				'label'    => sanitize_text_field( $q['label'] ),
				'type'     => in_array( isset( $q['type'] ) ? $q['type'] : '', array( 'text', 'textarea', 'select', 'checkbox' ), true ) ? $q['type'] : 'text',
				'options'  => isset( $q['options'] ) && is_array( $q['options'] ) ? array_map( 'sanitize_text_field', $q['options'] ) : array(),
				'required' => ! empty( $q['required'] ),
			);
		}
		$consent_html = '';
		if ( ! empty( $consent['booking_text'] ) && ! empty( $consent['booking_version'] ) ) {
			$consent_html = self::linked_consent( $consent['booking_text'] . ' See the Privacy Policy, Terms and SMS Policy. ' . ( isset( $consent['no_sharing_note'] ) ? $consent['no_sharing_note'] : '' ), $consent );
		}

		$config = array(
			'times'     => esc_url_raw( rest_url( 'smarfle/v1/booking/times' ) ),
			'book'      => esc_url_raw( rest_url( 'smarfle/v1/booking' ) ),
			'mode'      => isset( $booking['mode'] ) ? sanitize_key( $booking['mode'] ) : 'booking',
			'services'  => $services,
			'offices'   => count( $offices ) > 1 || '' !== $office ? $offices : array(),
			'office'    => 1 === count( $offices ) ? $offices[0]['id'] : '',
			'questions' => $questions,
			'address'   => ! empty( $booking['collect_address'] ),
			'zip'       => ! empty( $booking['require_address'] ),
			// The business's own choice in Smarfle: show prices on the booking form, or not.
			'prices'    => ! isset( $booking['show_prices'] ) || ! empty( $booking['show_prices'] ),
			'consent'   => '' !== $consent_html ? sanitize_text_field( $consent['booking_version'] ) : '',
			'phone'     => $phone,
			'strings'   => self::booking_strings( $phone ),
		);

		$html  = '<div class="smarfle-form-wrap">';
		$html .= '<div class="smarfle-form smarfle-booking" data-smarfle-config="' . esc_attr( wp_json_encode( $config ) ) . '">';
		if ( '' !== $heading ) {
			$html .= '<h3 class="smarfle-form__heading">' . esc_html( $heading ) . '</h3>';
		}
		$html .= '<p class="smarfle-booking__loading">' . esc_html__( 'Book online: pick a service and a free time.', 'smarfle-booking-forms' ) . '</p>';
		if ( '' !== $consent_html ) {
			// The consent wording, drawn here (escaped) and moved into the form by the script.
			$html .= '<template class="smarfle-booking__consent">' . self::consent_box( '', $consent_html ) . '</template>';
		}
		$html .= '<template class="smarfle-booking__hp">' . self::honeypot() . '</template>';
		if ( '' !== $phone ) {
			$html .= '<noscript><p>' . esc_html__( 'To book, please call', 'smarfle-booking-forms' ) . ' <a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>.</p></noscript>';
		}
		$html .= '</div>' . $core->credit_html() . '</div>';
		return $html;
	}

	/**
	 * Words the booking script shows (translatable here).
	 *
	 * @param string $phone Business phone.
	 * @return array
	 */
	private static function booking_strings( $phone ) {
		return array(
			'service'     => __( 'Service', 'smarfle-booking-forms' ),
			'choose'      => __( 'Choose a service', 'smarfle-booking-forms' ),
			'office'      => __( 'Location', 'smarfle-booking-forms' ),
			'quantity'    => __( 'How many', 'smarfle-booking-forms' ),
			'length'      => __( 'Length (ft)', 'smarfle-booking-forms' ),
			'width'       => __( 'Width (ft)', 'smarfle-booking-forms' ),
			'day'         => __( 'Day', 'smarfle-booking-forms' ),
			'time'        => __( 'Time', 'smarfle-booking-forms' ),
			'preferred'   => __( 'Preferred time', 'smarfle-booking-forms' ),
			'loading'     => __( 'Finding free times...', 'smarfle-booking-forms' ),
			/* translators: %s: business phone number. */
			'none'        => '' !== $phone ? sprintf( __( 'No free times in the next two weeks. Please call %s.', 'smarfle-booking-forms' ), $phone ) : __( 'No free times in the next two weeks. Please contact us.', 'smarfle-booking-forms' ),
			/* translators: %s: business phone number. */
			'call'        => '' !== $phone ? sprintf( __( 'Online booking is not available right now. Please call %s.', 'smarfle-booking-forms' ), $phone ) : __( 'Online booking is not available right now. Please contact us.', 'smarfle-booking-forms' ),
			'first'       => __( 'First name', 'smarfle-booking-forms' ),
			'last'        => __( 'Last name', 'smarfle-booking-forms' ),
			'email'       => __( 'Email', 'smarfle-booking-forms' ),
			'phone'       => __( 'Phone', 'smarfle-booking-forms' ),
			'street'      => __( 'Street address', 'smarfle-booking-forms' ),
			'city'        => __( 'City', 'smarfle-booking-forms' ),
			'state'       => __( 'State', 'smarfle-booking-forms' ),
			'zipLabel'    => __( 'ZIP code', 'smarfle-booking-forms' ),
			'notes'       => __( 'Anything we should know?', 'smarfle-booking-forms' ),
			'yes'         => __( 'Yes', 'smarfle-booking-forms' ),
			'submit'      => __( 'Book this time', 'smarfle-booking-forms' ),
			'submitQuote' => __( 'Request a quote', 'smarfle-booking-forms' ),
			'sending'     => __( 'Booking...', 'smarfle-booking-forms' ),
			'done'        => __( 'Thanks! Your booking was sent.', 'smarfle-booking-forms' ),
			'missing'     => __( 'Please fill in the fields marked *.', 'smarfle-booking-forms' ),
			'pickTime'    => __( 'Please pick a day and a time.', 'smarfle-booking-forms' ),
			'badPhone'    => __( 'Please enter a 10 digit US phone number.', 'smarfle-booking-forms' ),
			'badZip'      => __( 'Please enter the 5 digit ZIP code of the service address.', 'smarfle-booking-forms' ),
			'taken'       => __( 'That time was just taken. Please pick another time.', 'smarfle-booking-forms' ),
			'error'       => __( 'The booking could not be sent. Please try again or call us.', 'smarfle-booking-forms' ),
			'perSqft'     => __( 'per sq ft', 'smarfle-booking-forms' ),
			/* translators: %s: unit, for example "room". */
			'perUnit'     => __( 'per %s', 'smarfle-booking-forms' ),
		);
	}

	/* ------------------------------------------------------------------ Pieces */

	/**
	 * Services a website may show, flattened with their option groups.
	 *
	 * @param array $site Business info.
	 * @return array
	 */
	private static function services( $site ) {
		$out = array();
		foreach ( isset( $site['services'] ) && is_array( $site['services'] ) ? $site['services'] : array() as $s ) {
			if ( ! is_array( $s ) || empty( $s['id'] ) || empty( $s['name'] ) ) {
				continue;
			}
			$group  = isset( $s['group'] ) ? sanitize_text_field( (string) $s['group'] ) : '';
			$option = isset( $s['option'] ) ? sanitize_text_field( (string) $s['option'] ) : '';
			$out[]  = array(
				'id'      => sanitize_text_field( $s['id'] ),
				'name'    => sanitize_text_field( $s['name'] ),
				'label'   => '' !== $group && '' !== $option ? $group . ': ' . $option : sanitize_text_field( $s['name'] ),
				'group'   => $group,
				'option'  => $option,
				'price'   => isset( $s['price'] ) ? (float) $s['price'] : 0,
				'perItem' => ! empty( $s['per_item'] ),
				'unit'    => isset( $s['unit'] ) ? sanitize_text_field( (string) $s['unit'] ) : '',
				'max'     => isset( $s['max_quantity'] ) ? absint( $s['max_quantity'] ) : 0,
				'bySize'  => ! empty( $s['price_by_size'] ),
			);
		}
		return $out;
	}

	/**
	 * Offices.
	 *
	 * @param array $site Business info.
	 * @return array
	 */
	private static function offices( $site ) {
		$out = array();
		foreach ( isset( $site['offices'] ) && is_array( $site['offices'] ) ? $site['offices'] : array() as $o ) {
			if ( is_array( $o ) && ! empty( $o['id'] ) && ! empty( $o['name'] ) ) {
				$out[] = array(
					'id'   => sanitize_text_field( $o['id'] ),
					'name' => sanitize_text_field( $o['name'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * One labelled input.
	 *
	 * @param string $id           Field id.
	 * @param string $name         Field name.
	 * @param string $label        Label.
	 * @param string $type         Input type.
	 * @param bool   $required     Required.
	 * @param string $autocomplete Autocomplete hint.
	 * @return string
	 */
	private static function field( $id, $name, $label, $type, $required, $autocomplete ) {
		return '<p class="smarfle-form__field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>'
			. '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" autocomplete="' . esc_attr( $autocomplete ) . '"' . ( $required ? ' required' : '' ) . ' maxlength="200"></p>';
	}

	/**
	 * The text-consent checkbox (never ticked, never required: texts are not a condition of contacting the business).
	 *
	 * @param string $id   Checkbox id ('' = the script sets one).
	 * @param string $html The consent wording, already escaped and linked.
	 * @return string
	 */
	private static function consent_box( $id, $html ) {
		return '<p class="smarfle-form__consent"><label' . ( '' !== $id ? ' for="' . esc_attr( $id ) . '"' : '' ) . '><input type="checkbox" name="sms_consent" value="1"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '> <span>' . $html . '</span></label></p>';
	}

	/**
	 * The consent wording, word for word as Smarfle gave it, with Privacy Policy, Terms and SMS Policy linked.
	 *
	 * @param string $text    Wording.
	 * @param array  $consent Consent info (privacy_url, terms_url, sms_policy_url).
	 * @return string Escaped HTML.
	 */
	private static function linked_consent( $text, $consent ) {
		$plain = (string) $text;
		$links = array(
			'SMS Policy'     => isset( $consent['sms_policy_url'] ) ? (string) $consent['sms_policy_url'] : '',
			'Privacy Policy' => isset( $consent['privacy_url'] ) ? (string) $consent['privacy_url'] : '',
			'Terms'          => isset( $consent['terms_url'] ) ? (string) $consent['terms_url'] : '',
		);
		// Where each linked phrase sits in the plain text (first time only, never overlapping), so a link is built
		// from the text alone and nothing is ever replaced inside markup that was already added.
		$found = array();
		foreach ( $links as $words => $url ) {
			if ( '' === $url || '' === esc_url( $url ) ) {
				continue;
			}
			$pos = strpos( $plain, $words );
			if ( false === $pos ) {
				continue;
			}
			foreach ( $found as $f ) {
				if ( $pos < $f['end'] && $pos + strlen( $words ) > $f['start'] ) {
					continue 2;
				}
			}
			$found[] = array(
				'start' => $pos,
				'end'   => $pos + strlen( $words ),
				'words' => $words,
				'url'   => $url,
			);
		}
		usort(
			$found,
			function ( $a, $b ) {
				return $a['start'] - $b['start'];
			}
		);
		$html = '';
		$at   = 0;
		foreach ( $found as $f ) {
			$html .= esc_html( substr( $plain, $at, $f['start'] - $at ) );
			$html .= '<a href="' . esc_url( $f['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $f['words'] ) . '</a>';
			$at    = $f['end'];
		}
		return $html . esc_html( substr( $plain, $at ) );
	}

	/**
	 * The markup the forms use, for wp_kses() where a form is echoed (the Elementor widgets).
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$common = array(
			'class'       => true,
			'id'          => true,
			'role'        => true,
			'aria-hidden' => true,
			'aria-live'   => true,
			'aria-label'  => true,
			'data-*'      => true,
		);
		$field  = array_merge(
			$common,
			array(
				'type'         => true,
				'name'         => true,
				'value'        => true,
				'required'     => true,
				'maxlength'    => true,
				'autocomplete' => true,
				'tabindex'     => true,
				'checked'      => true,
				'placeholder'  => true,
				'rows'         => true,
				'disabled'     => true,
			)
		);
		return array(
			'form'     => array_merge( $common, array( 'novalidate' => true ) ),
			'div'      => $common,
			'p'        => $common,
			'span'     => $common,
			'h3'       => $common,
			'strong'   => $common,
			'small'    => $common,
			'br'       => array(),
			'template' => $common,
			'noscript' => array(),
			'label'    => array_merge( $common, array( 'for' => true ) ),
			'input'    => $field,
			'textarea' => $field,
			'button'   => array_merge(
				$common,
				array(
					'type'     => true,
					'disabled' => true,
				)
			),
			'a'        => array_merge(
				$common,
				array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				)
			),
		);
	}

	/**
	 * A field people never see and bots fill in.
	 *
	 * @return string
	 */
	private static function honeypot() {
		return '<p class="smarfle-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this empty', 'smarfle-booking-forms' ) . ' <input type="text" name="smarfle_hp" tabindex="-1" autocomplete="off" value=""></label></p>';
	}

	/**
	 * "Call us" box (the booking form's fallback).
	 *
	 * @param string $heading Heading.
	 * @param string $phone   Phone.
	 * @return string
	 */
	private static function call_us( $heading, $phone ) {
		if ( '' === $phone ) {
			return '';
		}
		$html = '<div class="smarfle-form smarfle-call">';
		if ( '' !== $heading ) {
			$html .= '<h3 class="smarfle-form__heading">' . esc_html( $heading ) . '</h3>';
		}
		$html .= '<p>' . esc_html__( 'To book, give us a call.', 'smarfle-booking-forms' ) . '</p>';
		$html .= '<p><a class="smarfle-form__button wp-element-button" href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html(
			/* translators: %s: phone number. */
			sprintf( __( 'Call %s', 'smarfle-booking-forms' ), $phone )
		) . '</a></p></div>';
		return '<div class="smarfle-form-wrap">' . $html . '</div>';
	}

	/**
	 * A note only people who can edit the page see (never visitors).
	 *
	 * @param string $text Note.
	 * @return string
	 */
	private static function editor_notice( $text ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<div class="smarfle-editor-note"><p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=' . Smarfle_Core::PAGE ) ) . '">' . esc_html__( 'Open the Smarfle settings', 'smarfle-booking-forms' ) . '</a></p></div>';
	}
}

<?php
/**
 * Elementor widget: Smarfle contact form (drawn by the same renderer as the block and the shortcode).
 *
 * @package Smarfle_Booking_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * The contact form widget.
 */
class Smarfle_BF_Elementor_Contact extends \Elementor\Widget_Base {

	/**
	 * Widget id.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'smarfle-contact-form';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Smarfle contact form', 'smarfle-booking-forms' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Widget categories.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'contact', 'form', 'lead', 'smarfle' );
	}

	/**
	 * Settings: heading, button text, form name.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'smarfle_content', array( 'label' => __( 'Contact form', 'smarfle-booking-forms' ) ) );
		$this->add_control(
			'heading',
			array(
				'label' => __( 'Heading', 'smarfle-booking-forms' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);
		$this->add_control(
			'button',
			array(
				'label'       => __( 'Button text', 'smarfle-booking-forms' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'Send', 'smarfle-booking-forms' ),
			)
		);
		$this->add_control(
			'form',
			array(
				'label'       => __( 'Form name in Smarfle', 'smarfle-booking-forms' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'Website contact form', 'smarfle-booking-forms' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Prints the form.
	 */
	protected function render() {
		$s    = $this->get_settings_for_display();
		$html = Smarfle_BF_Render::contact_form(
			array(
				'heading' => isset( $s['heading'] ) ? $s['heading'] : '',
				'button'  => isset( $s['button'] ) ? $s['button'] : '',
				'form'    => isset( $s['form'] ) ? $s['form'] : '',
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes everything it prints.
	}
}

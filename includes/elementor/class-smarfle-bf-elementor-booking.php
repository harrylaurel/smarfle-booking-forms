<?php
/**
 * Elementor widget: Smarfle booking form (drawn by the same renderer as the block and the shortcode).
 *
 * @package Smarfle_Booking_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * The booking form widget.
 */
class Smarfle_BF_Elementor_Booking extends \Elementor\Widget_Base {

	/**
	 * Widget id.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'smarfle-booking-form';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Smarfle booking form', 'smarfle-booking-forms' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-calendar';
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
		return array( 'booking', 'appointment', 'schedule', 'smarfle' );
	}

	/**
	 * Settings: service, office, heading.
	 */
	protected function register_controls() {
		$data     = Smarfle_BF_Render::editor_data();
		$services = array( '' => __( 'Every service (the visitor chooses)', 'smarfle-booking-forms' ) );
		foreach ( $data['services'] as $s ) {
			$services[ $s['id'] ] = $s['name'];
		}
		$offices = array( '' => __( 'Every location', 'smarfle-booking-forms' ) );
		foreach ( $data['offices'] as $o ) {
			$offices[ $o['id'] ] = $o['name'];
		}
		$this->start_controls_section( 'smarfle_content', array( 'label' => __( 'Booking form', 'smarfle-booking-forms' ) ) );
		$this->add_control(
			'heading',
			array(
				'label' => __( 'Heading', 'smarfle-booking-forms' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);
		$this->add_control(
			'service',
			array(
				'label'   => __( 'Service', 'smarfle-booking-forms' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $services,
				'default' => '',
			)
		);
		if ( count( $data['offices'] ) > 1 ) {
			$this->add_control(
				'office',
				array(
					'label'   => __( 'Location', 'smarfle-booking-forms' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'options' => $offices,
					'default' => '',
				)
			);
		}
		$this->end_controls_section();
	}

	/**
	 * Prints the form.
	 */
	protected function render() {
		$s    = $this->get_settings_for_display();
		$html = Smarfle_BF_Render::booking_form(
			array(
				'heading' => isset( $s['heading'] ) ? $s['heading'] : '',
				'service' => isset( $s['service'] ) ? $s['service'] : '',
				'office'  => isset( $s['office'] ) ? $s['office'] : '',
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes everything it prints.
	}
}

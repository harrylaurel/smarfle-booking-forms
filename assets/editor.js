/* Block editor: the two Smarfle blocks, drawn by the server (the same renderer as on the page). */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	var SSR = wp.serverSideRender;
	var data = window.smarfleBookingForms || { services: [], offices: [], connected: false };

	function notConnected() {
		return data.connected ? null : el( c.Notice, { status: 'warning', isDismissible: false }, __( 'Connect this website in the Smarfle menu to show your services and free times.', 'smarfle-booking-forms' ) );
	}

	wp.blocks.registerBlockType( 'smarfle/booking-form', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var services = [ { label: __( 'Every service (the visitor chooses)', 'smarfle-booking-forms' ), value: '' } ].concat(
				data.services.map( function ( s ) {
					return { label: s.name, value: s.id };
				} )
			);
			var offices = [ { label: __( 'Every location', 'smarfle-booking-forms' ), value: '' } ].concat(
				data.offices.map( function ( o ) {
					return { label: o.name, value: o.id };
				} )
			);
			return el(
				'div',
				be.useBlockProps(),
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Booking form', 'smarfle-booking-forms' ) },
						notConnected(),
						el( c.TextControl, { label: __( 'Heading', 'smarfle-booking-forms' ), value: a.heading, onChange: function ( v ) { set( { heading: v } ); } } ),
						el( c.SelectControl, { label: __( 'Service', 'smarfle-booking-forms' ), value: a.service, options: services, onChange: function ( v ) { set( { service: v } ); } } ),
						data.offices.length > 1 ? el( c.SelectControl, { label: __( 'Location', 'smarfle-booking-forms' ), value: a.office, options: offices, onChange: function ( v ) { set( { office: v } ); } } ) : null
					)
				),
				el( SSR, { block: 'smarfle/booking-form', attributes: a } )
			);
		},
		save: function () {
			return null;
		},
	} );

	wp.blocks.registerBlockType( 'smarfle/contact-form', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el(
				'div',
				be.useBlockProps(),
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Contact form', 'smarfle-booking-forms' ) },
						el( c.TextControl, { label: __( 'Heading', 'smarfle-booking-forms' ), value: a.heading, onChange: function ( v ) { set( { heading: v } ); } } ),
						el( c.TextControl, { label: __( 'Button text', 'smarfle-booking-forms' ), value: a.button, placeholder: __( 'Send', 'smarfle-booking-forms' ), onChange: function ( v ) { set( { button: v } ); } } ),
						el( c.TextControl, { label: __( 'Form name in Smarfle', 'smarfle-booking-forms' ), value: a.form, placeholder: __( 'Website contact form', 'smarfle-booking-forms' ), onChange: function ( v ) { set( { form: v } ); } } )
					)
				),
				el( SSR, { block: 'smarfle/contact-form', attributes: a } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );

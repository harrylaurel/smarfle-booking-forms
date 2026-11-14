/* Smarfle contact form: posts to this website (never to Smarfle directly); no libraries. */
( function () {
	'use strict';
	var started = Date.now();

	function utm() {
		var keys = [ 'utm_source', 'utm_medium', 'utm_campaign' ];
		var out = {};
		var params = new URLSearchParams( window.location.search );
		keys.forEach( function ( k ) {
			var v = params.get( k );
			try {
				if ( v ) {
					window.sessionStorage.setItem( 'smarfle_' + k, v );
				} else {
					v = window.sessionStorage.getItem( 'smarfle_' + k );
				}
			} catch ( e ) {}
			if ( v ) {
				out[ k ] = v.slice( 0, 120 );
			}
		} );
		return out;
	}
	var attribution = utm();

	function setup( form ) {
		var cfg;
		try {
			cfg = JSON.parse( form.getAttribute( 'data-smarfle-config' ) || '{}' );
		} catch ( e ) {
			return;
		}
		var status = form.querySelector( '.smarfle-form__status' );
		var button = form.querySelector( 'button[type="submit"]' );
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var get = function ( n ) {
				var el = form.elements[ n ];
				return el ? String( el.value || '' ).trim() : '';
			};
			if ( ! get( 'name' ) || ! get( 'email' ) || ! get( 'message' ) || ( form.elements.email && ! form.elements.email.checkValidity() ) ) {
				status.textContent = cfg.strings.missing;
				return;
			}
			var consent = form.elements.sms_consent;
			var body = Object.assign(
				{
					form: get( 'form' ),
					name: get( 'name' ),
					email: get( 'email' ),
					phone: get( 'phone' ),
					message: get( 'message' ),
					sms_consent: !! ( consent && consent.checked ),
					consent_version: cfg.consent || '',
					smarfle_hp: get( 'smarfle_hp' ),
					smarfle_elapsed: Math.round( ( Date.now() - started ) / 1000 ),
					page_url: window.location.href.split( '#' )[ 0 ],
					page_title: document.title,
				},
				attribution
			);
			button.disabled = true;
			status.textContent = cfg.strings.sending;
			fetch( cfg.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( body ),
				credentials: 'same-origin',
			} )
				.then( function ( r ) {
					return r.json().catch( function () {
						return {};
					} );
				} )
				.then( function ( data ) {
					if ( data && data.ok ) {
						form.reset();
						status.textContent = cfg.strings.sent;
						form.classList.add( 'is-sent' );
					} else {
						status.textContent = ( data && data.message ) || cfg.strings.error;
					}
				} )
				.catch( function () {
					status.textContent = cfg.strings.error;
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	function init() {
		document.querySelectorAll( 'form.smarfle-contact[data-smarfle-config]' ).forEach( setup );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

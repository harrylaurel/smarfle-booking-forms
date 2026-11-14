/* Smarfle booking form: a service, a real free time, the customer's details. Talks to this website only (never to
   Smarfle directly), no libraries. */
( function () {
	'use strict';
	var started = Date.now();

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				n.textContent = attrs[ k ];
			} else if ( attrs[ k ] !== false && attrs[ k ] !== null && attrs[ k ] !== undefined ) {
				n.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
			}
		} );
		( kids || [] ).forEach( function ( c ) {
			if ( c ) {
				n.appendChild( c );
			}
		} );
		return n;
	}
	function fmtTime( t ) {
		var h = parseInt( t.slice( 0, 2 ), 10 );
		return ( h % 12 || 12 ) + ':' + t.slice( 3, 5 ) + ' ' + ( h >= 12 ? 'PM' : 'AM' );
	}
	function fmtDay( ymd ) {
		var d = new Date( ymd + 'T12:00:00Z' );
		return {
			wd: d.toLocaleDateString( undefined, { weekday: 'short', timeZone: 'UTC' } ),
			md: d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' } ),
		};
	}
	function money( n ) {
		return '$' + ( Math.round( n ) === n ? String( n ) : n.toFixed( 2 ) );
	}
	function utm() {
		var out = {};
		var params = new URLSearchParams( window.location.search );
		[ 'utm_source', 'utm_medium', 'utm_campaign' ].forEach( function ( k ) {
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

	function setup( root ) {
		var cfg;
		try {
			cfg = JSON.parse( root.getAttribute( 'data-smarfle-config' ) || '{}' );
		} catch ( e ) {
			return;
		}
		var S = cfg.strings;
		var uid = 'smarfle-b' + Math.random().toString( 36 ).slice( 2, 8 );
		var st = { service: cfg.services.length === 1 ? cfg.services[ 0 ] : null, office: cfg.office || '', days: [], parts: [], day: null, time: null, part: '', mode: cfg.mode };
		var loading = root.querySelector( '.smarfle-booking__loading' );
		if ( loading ) {
			loading.remove();
		}

		function field( name, label, type, req, auto ) {
			var id = uid + '-' + name;
			return el( 'p', { class: 'smarfle-form__field' }, [
				el( 'label', { for: id, text: label + ( req ? ' *' : '' ) } ),
				el( type === 'textarea' ? 'textarea' : 'input', { id: id, name: name, type: type === 'textarea' ? null : type, required: req, autocomplete: auto || null, rows: type === 'textarea' ? 3 : null, maxlength: type === 'textarea' ? 2000 : 200 } ),
			] );
		}
		function priceText( sv ) {
			if ( ! sv.price ) {
				return '';
			}
			if ( sv.bySize ) {
				return money( sv.price ) + ' ' + S.perSqft;
			}
			return money( sv.price ) + ( sv.perItem && sv.unit ? ' ' + S.perUnit.replace( '%s', sv.unit ) : '' );
		}

		var form = el( 'form', { class: 'smarfle-booking__form', novalidate: true } );

		// Service.
		if ( st.service ) {
			form.appendChild( el( 'p', { class: 'smarfle-booking__service', text: st.service.label + ( priceText( st.service ) ? ' · ' + priceText( st.service ) : '' ) } ) );
		} else {
			var sel = el( 'select', { id: uid + '-service', required: true }, [ el( 'option', { value: '', text: S.choose } ) ] );
			var groups = {};
			cfg.services.forEach( function ( sv, i ) {
				var opt = el( 'option', { value: String( i ), text: ( sv.group ? sv.option : sv.name ) + ( priceText( sv ) ? ' · ' + priceText( sv ) : '' ) } );
				if ( sv.group ) {
					if ( ! groups[ sv.group ] ) {
						groups[ sv.group ] = el( 'optgroup', { label: sv.group } );
						sel.appendChild( groups[ sv.group ] );
					}
					groups[ sv.group ].appendChild( opt );
				} else {
					sel.appendChild( opt );
				}
			} );
			sel.addEventListener( 'change', function () {
				st.service = sel.value === '' ? null : cfg.services[ parseInt( sel.value, 10 ) ];
				amounts();
				loadTimes();
			} );
			form.appendChild( el( 'p', { class: 'smarfle-form__field' }, [ el( 'label', { for: uid + '-service', text: S.service + ' *' } ), sel ] ) );
		}

		// How many / size.
		var qtyRow = el( 'div', { class: 'smarfle-form__row', hidden: true }, [ field( 'quantity', S.quantity, 'number', true ) ] );
		var sizeRow = el( 'div', { class: 'smarfle-form__row', hidden: true }, [ field( 'size_length', S.length, 'number', true ), field( 'size_width', S.width, 'number', true ) ] );
		form.appendChild( qtyRow );
		form.appendChild( sizeRow );
		var timer = null;
		[ qtyRow, sizeRow ].forEach( function ( row ) {
			row.addEventListener( 'input', function () {
				clearTimeout( timer );
				timer = setTimeout( loadTimes, 500 );
			} );
		} );
		function amounts() {
			qtyRow.hidden = ! ( st.service && st.service.perItem && ! st.service.bySize );
			sizeRow.hidden = ! ( st.service && st.service.bySize );
			var q = form.elements.quantity;
			q.min = 1;
			q.max = ( st.service && st.service.max ) || 20;
			if ( ! q.value ) {
				q.value = 1;
			}
		}
		function quantity() {
			if ( ! st.service ) {
				return 1;
			}
			if ( st.service.bySize ) {
				var a = parseFloat( form.elements.size_length.value ) * parseFloat( form.elements.size_width.value );
				return a > 0 ? Math.min( 999, Math.ceil( a ) ) : 1;
			}
			if ( st.service.perItem ) {
				return Math.max( 1, Math.min( st.service.max || 20, parseInt( form.elements.quantity.value, 10 ) || 1 ) );
			}
			return 1;
		}

		// Location.
		if ( cfg.offices.length > 1 ) {
			var osel = el( 'select', { id: uid + '-office' }, cfg.offices.map( function ( o ) {
				return el( 'option', { value: o.id, text: o.name } );
			} ) );
			st.office = cfg.offices[ 0 ].id;
			osel.addEventListener( 'change', function () {
				st.office = osel.value;
				loadTimes();
			} );
			form.appendChild( el( 'p', { class: 'smarfle-form__field' }, [ el( 'label', { for: uid + '-office', text: S.office } ), osel ] ) );
		}

		// Day and time.
		var daysBox = el( 'div', { class: 'smarfle-booking__days', role: 'group', 'aria-label': S.day } );
		var timesBox = el( 'div', { class: 'smarfle-booking__times', role: 'group', 'aria-label': S.time } );
		var timesMsg = el( 'p', { class: 'smarfle-booking__msg', role: 'status', 'aria-live': 'polite' } );
		form.appendChild( el( 'div', { class: 'smarfle-booking__when' }, [ daysBox, timesBox, timesMsg ] ) );

		function chip( label, sub, pressed, disabled, onClick ) {
			var b = el( 'button', { type: 'button', class: 'smarfle-chip', 'aria-pressed': pressed ? 'true' : 'false', disabled: disabled }, [ el( 'span', { text: label } ), sub ? el( 'small', { text: sub } ) : null ] );
			b.addEventListener( 'click', onClick );
			return b;
		}
		function renderDays() {
			daysBox.textContent = '';
			var quote = st.mode === 'quote';
			st.days.forEach( function ( d ) {
				var f = fmtDay( d.date );
				daysBox.appendChild(
					chip( f.wd, f.md, st.day === d, ! quote && d.times.length === 0, function () {
						st.day = d;
						st.time = null;
						renderDays();
						renderTimes();
					} )
				);
			} );
		}
		function renderTimes() {
			timesBox.textContent = '';
			if ( ! st.day ) {
				return;
			}
			var quote = st.mode === 'quote';
			var list = quote
				? st.parts
				: st.day.times.map( function ( t ) {
						return { label: fmtTime( t ), value: t };
				  } );
			list.forEach( function ( p ) {
				timesBox.appendChild(
					chip( p.label, '', st.time === p.value && ( ! quote || st.part === p.label ), false, function () {
						st.time = p.value;
						st.part = quote ? p.label : '';
						renderTimes();
					} )
				);
			} );
		}
		function loadTimes() {
			st.day = null;
			st.time = null;
			st.days = [];
			renderDays();
			renderTimes();
			if ( ! st.service ) {
				timesMsg.textContent = '';
				return;
			}
			timesMsg.textContent = S.loading;
			var url = cfg.times + ( cfg.times.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'service=' + encodeURIComponent( st.service.id ) + '&quantity=' + quantity() + '&office=' + encodeURIComponent( st.office );
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					if ( ! data || ! data.ok || ! data.booking ) {
						timesMsg.textContent = S.call;
						return;
					}
					st.mode = data.mode || st.mode;
					st.parts = data.timeOfDay || [];
					st.days = data.days || [];
					var first = st.days.filter( function ( d ) {
						return st.mode === 'quote' || d.times.length > 0;
					} )[ 0 ];
					st.day = first || null;
					timesMsg.textContent = first ? '' : S.none;
					renderDays();
					renderTimes();
				} )
				.catch( function () {
					timesMsg.textContent = S.call;
				} );
		}

		// Customer details.
		form.appendChild( el( 'div', { class: 'smarfle-form__row' }, [ field( 'first_name', S.first, 'text', true, 'given-name' ), field( 'last_name', S.last, 'text', true, 'family-name' ) ] ) );
		form.appendChild( el( 'div', { class: 'smarfle-form__row' }, [ field( 'email', S.email, 'email', true, 'email' ), field( 'phone', S.phone, 'tel', true, 'tel' ) ] ) );
		if ( cfg.address ) {
			form.appendChild( field( 'address_street', S.street, 'text', false, 'address-line1' ) );
			form.appendChild( el( 'div', { class: 'smarfle-form__row smarfle-form__row--3' }, [ field( 'address_city', S.city, 'text', false, 'address-level2' ), field( 'address_state', S.state, 'text', false, 'address-level1' ), field( 'address_zip', S.zipLabel, 'text', cfg.zip, 'postal-code' ) ] ) );
		}
		cfg.questions.forEach( function ( q, i ) {
			var id = uid + '-q' + i;
			var input;
			if ( q.type === 'select' ) {
				input = el( 'select', { id: id, 'data-q': q.id, required: q.required }, [ el( 'option', { value: '', text: '' } ) ].concat( q.options.map( function ( o ) {
					return el( 'option', { value: o, text: o } );
				} ) ) );
			} else if ( q.type === 'checkbox' ) {
				form.appendChild( el( 'p', { class: 'smarfle-form__consent' }, [ el( 'label', {}, [ el( 'input', { type: 'checkbox', 'data-q': q.id, value: S.yes } ), el( 'span', { text: ' ' + q.label } ) ] ) ] ) );
				return;
			} else {
				input = el( q.type === 'textarea' ? 'textarea' : 'input', { id: id, 'data-q': q.id, type: q.type === 'textarea' ? null : 'text', required: q.required, maxlength: 300 } );
			}
			form.appendChild( el( 'p', { class: 'smarfle-form__field' }, [ el( 'label', { for: id, text: q.label + ( q.required ? ' *' : '' ) } ), input ] ) );
		} );
		form.appendChild( field( 'notes', S.notes, 'textarea', false ) );
		[ 'consent', 'hp' ].forEach( function ( part ) {
			var tpl = root.querySelector( 'template.smarfle-booking__' + part );
			if ( tpl ) {
				form.appendChild( tpl.content.cloneNode( true ) );
			}
		} );
		var button = el( 'button', { type: 'submit', class: 'smarfle-form__button wp-element-button', text: st.mode === 'quote' ? S.submitQuote : S.submit } );
		var status = el( 'p', { class: 'smarfle-form__status', role: 'status', 'aria-live': 'polite' } );
		form.appendChild( el( 'p', { class: 'smarfle-form__actions' }, [ button ] ) );
		form.appendChild( status );
		root.appendChild( form );
		amounts();
		if ( st.service ) {
			loadTimes();
		}

		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var v = function ( n ) {
				var f = form.elements[ n ];
				return f ? String( f.value || '' ).trim() : '';
			};
			if ( ! st.service || ! st.day || ! st.time ) {
				status.textContent = S.pickTime;
				return;
			}
			var answers = {};
			var missing = ! v( 'first_name' ) || ! v( 'last_name' ) || ! v( 'email' ) || ! form.elements.email.checkValidity();
			form.querySelectorAll( '[data-q]' ).forEach( function ( q ) {
				var val = q.type === 'checkbox' ? ( q.checked ? q.value : '' ) : String( q.value || '' ).trim();
				if ( val ) {
					answers[ q.getAttribute( 'data-q' ) ] = val;
				} else if ( q.required ) {
					missing = true;
				}
			} );
			if ( missing ) {
				status.textContent = S.missing;
				return;
			}
			if ( ! /^1?\d{10}$/.test( v( 'phone' ).replace( /\D/g, '' ) ) ) {
				status.textContent = S.badPhone;
				return;
			}
			if ( cfg.zip && ! /^\d{5}$/.test( v( 'address_zip' ) ) ) {
				status.textContent = S.badZip;
				return;
			}
			var consent = form.elements.sms_consent;
			var body = Object.assign(
				{
					service: st.service.id,
					quantity: quantity(),
					size: st.service.bySize ? v( 'size_length' ) + 'x' + v( 'size_width' ) : '',
					office: st.office,
					date: st.day.date,
					time: st.time,
					time_of_day: st.part,
					first_name: v( 'first_name' ),
					last_name: v( 'last_name' ),
					email: v( 'email' ),
					phone: v( 'phone' ),
					address_street: v( 'address_street' ),
					address_city: v( 'address_city' ),
					address_state: v( 'address_state' ),
					address_zip: v( 'address_zip' ),
					notes: v( 'notes' ),
					answers: answers,
					sms_consent: !! ( consent && consent.checked ),
					consent_version: cfg.consent || '',
					smarfle_hp: v( 'smarfle_hp' ),
					smarfle_elapsed: Math.round( ( Date.now() - started ) / 1000 ),
					page_url: window.location.href.split( '#' )[ 0 ],
				},
				attribution
			);
			button.disabled = true;
			status.textContent = S.sending;
			fetch( cfg.book, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ), credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json().catch( function () {
						return {};
					} );
				} )
				.then( function ( data ) {
					if ( data && data.ok ) {
						root.textContent = '';
						root.appendChild( el( 'p', { class: 'smarfle-booking__done', role: 'status', text: data.message || S.done } ) );
						return;
					}
					if ( data && data.taken ) {
						status.textContent = S.taken;
						loadTimes();
						return;
					}
					status.textContent = ( data && data.message ) || S.error;
				} )
				.catch( function () {
					status.textContent = S.error;
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	function init() {
		document.querySelectorAll( '.smarfle-booking[data-smarfle-config]' ).forEach( setup );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

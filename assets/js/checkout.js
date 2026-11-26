/**
 * Memberkin checkout enhancements (no build step, no dependencies).
 * - Live price summary when the coupon or country changes (REST /memberkin/v1/quote).
 * - Shows only the selected gateway's fields.
 * - Prevents double submission.
 * The form works fully without JavaScript.
 */
( function () {
	'use strict';

	var settings = window.memberkinCheckout || {};

	function debounce( fn, wait ) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( null, args ); }, wait );
		};
	}

	function init( root ) {
		var form = root.querySelector( 'form' );
		if ( ! form ) {
			return;
		}
		var summary = root.querySelector( '.memberkin-summary-wrap' );
		var url = root.getAttribute( 'data-quote-url' );
		var levels = ( root.getAttribute( 'data-levels' ) || '' ).split( ',' ).filter( Boolean ).map( Number );

		function value( name ) {
			var el = form.querySelector( '[name="' + name + '"]' );
			return el ? el.value : '';
		}

		// Add-on choices (fields named memberkin_*, except the session token), sent as a query string.
		function choices() {
			var params = new window.URLSearchParams();
			new window.FormData( form ).forEach( function ( v, k ) {
				if ( 0 === k.indexOf( 'memberkin_' ) && 0 !== k.indexOf( 'memberkin_token' ) && 'string' === typeof v ) {
					params.append( k, v );
				}
			} );
			return params.toString();
		}

		var requote = debounce( function () {
			if ( ! url || ! summary || ! window.fetch ) {
				return;
			}
			summary.classList.add( 'is-loading' );
			window.fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					level_ids: levels,
					choices: choices(),
					coupon_code: value( 'coupon_code' ),
					country: value( 'billing[country]' ),
					region: value( 'billing[region]' ),
					postcode: value( 'billing[postcode]' ),
					vat_number: value( 'vat_number' ),
					gateway: ( form.querySelector( '[name="gateway"]:checked' ) || form.querySelector( '[name="gateway"]' ) || {} ).value || ''
				} )
			} ).then( function ( r ) {
				return r.json().then( function ( data ) { return { ok: r.ok, data: data }; } );
			} ).then( function ( res ) {
				summary.classList.remove( 'is-loading' );
				if ( res.ok && res.data.html ) {
					summary.innerHTML = res.data.html;
					var submit = form.querySelector( '[data-memberkin-submit]' );
					if ( submit && res.data.quote && res.data.quote.total ) {
						submit.setAttribute( 'data-total', res.data.quote.total.decimal );
					}
				} else if ( res.data && res.data.message ) {
					var old = summary.querySelector( '.memberkin-summary-error' );
					if ( old ) {
						old.remove();
					}
					summary.insertAdjacentHTML( 'beforeend', '<p class="memberkin-field-error memberkin-summary-error" role="alert"></p>' );
					summary.lastElementChild.textContent = res.data.message;
				}
			} ).catch( function () {
				summary.classList.remove( 'is-loading' );
			} );
		}, 400 );

		form.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( '[name="coupon_code"], [data-memberkin-requote], [name="billing[postcode]"], [name="vat_number"]' ) ) {
				requote();
			}
			if ( e.target.matches( '[name="gateway"]' ) ) {
				toggleGateways();
				requote(); // A merchant-of-record gateway adds tax itself, so the total may change.
			}
		} );

		form.addEventListener( 'input', function ( e ) {
			if ( e.target.matches( '[name^="memberkin_price["]' ) ) {
				requote();
			}
		} );

		var apply = form.querySelector( '[name="memberkin_apply_coupon"]' );
		if ( apply ) {
			apply.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				requote();
			} );
		}

		function toggleGateways() {
			var checked = form.querySelector( '[name="gateway"]:checked' );
			form.querySelectorAll( '.memberkin-gateway__fields' ).forEach( function ( el ) {
				el.hidden = ! checked || el.getAttribute( 'data-gateway' ) !== checked.value;
			} );
		}
		toggleGateways();

		form.addEventListener( 'submit', function ( e ) {
			if ( e.submitter && e.submitter.name === 'memberkin_apply_coupon' ) {
				return;
			}
			if ( form.getAttribute( 'data-submitting' ) ) {
				e.preventDefault();
				return;
			}
			/* Gateways with JS SDKs can cancel submission to collect a token first: */
			var evt = new CustomEvent( 'memberkin:checkout-submit', { cancelable: true, detail: { form: form } } );
			if ( ! form.dispatchEvent( evt ) ) {
				e.preventDefault();
				return;
			}
			form.setAttribute( 'data-submitting', '1' );
			var button = form.querySelector( '[data-memberkin-submit]' );
			if ( button ) {
				button.setAttribute( 'aria-busy', 'true' );
				button.textContent = settings.processing || '…';
			}
		} );

		var errors = root.querySelector( '.memberkin-errors' );
		if ( errors ) {
			errors.focus();
		}

		/* 3-D Secure: gateway scripts listen for this event and resubmit with gateway_fields[confirmed]. */
		var secret = root.getAttribute( 'data-client-secret' );
		if ( secret ) {
			root.dispatchEvent( new CustomEvent( 'memberkin:requires-action', { detail: { form: form, clientSecret: secret } } ) );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-memberkin-checkout]' ).forEach( init );
	} );
} )();

/**
 * Memberkin — Stripe Payment Element.
 *
 * - Mounts the Payment Element in deferred-intent mode (no intent exists until the form is submitted).
 * - On submit, creates a PaymentMethod in the browser and posts only its id (pm_…); the server confirms the intent.
 * - When the server answers "requires action" (3-D Secure, redirect-based methods), the re-rendered form carries the
 *   client secret: Stripe.js handles the next action, then the browser goes to the checkout return URL.
 */
( function () {
	'use strict';

	function minorUnits( decimal, exponent ) {
		var value = parseFloat( String( decimal ).replace( ',', '.' ) );
		return isNaN( value ) ? null : Math.round( value * Math.pow( 10, exponent ) );
	}

	function init( box ) {
		var cfg;
		try {
			cfg = JSON.parse( box.getAttribute( 'data-memberkin-stripe' ) );
		} catch ( e ) {
			return;
		}
		var form = box.closest( 'form' );
		var root = box.closest( '[data-memberkin-checkout]' );
		var input = box.querySelector( 'input[name="memberkin_gateway[payment_method]"]' );
		var errorBox = box.querySelector( '.memberkin-stripe__error' );
		if ( ! form || ! window.Stripe || ! cfg.publishableKey ) {
			return;
		}

		function showError( message ) {
			errorBox.textContent = message || '';
			errorBox.hidden = ! message;
		}

		var stripe = window.Stripe( cfg.publishableKey, cfg.locale ? { locale: cfg.locale } : undefined );
		var options = {
			mode: cfg.mode,
			currency: cfg.currency,
			paymentMethodCreation: 'manual',
			appearance: cfg.appearance || {}
		};
		if ( 'payment' === cfg.mode ) {
			options.amount = cfg.amount;
		}
		if ( cfg.setupFutureUsage ) {
			options.setupFutureUsage = 'off_session';
		}
		var elements = stripe.elements( options );
		elements.create( 'payment' ).mount( box.querySelector( '.memberkin-stripe__element' ) );

		function selectedGateway() {
			var checked = form.querySelector( '[name="gateway"]:checked' );
			return checked ? checked.value : '';
		}

		/* Keep the Element in step with the live price summary (coupons, tax). */
		function syncAmount() {
			var submit = form.querySelector( '[data-memberkin-submit]' );
			var total = submit ? submit.getAttribute( 'data-total' ) : null;
			if ( null === total ) {
				return;
			}
			var amount = minorUnits( total, cfg.exponent );
			if ( null === amount ) {
				return;
			}
			if ( amount > 0 ) {
				elements.update( { mode: 'payment', amount: amount } );
			} else if ( cfg.setupFutureUsage ) {
				elements.update( { mode: 'setup' } );
			}
		}

		form.addEventListener( 'memberkin:checkout-submit', function ( event ) {
			if ( 'stripe' !== selectedGateway() || input.value ) {
				return;
			}
			event.preventDefault();
			showError( '' );
			syncAmount();
			elements.submit().then( function ( result ) {
				if ( result.error ) {
					throw result.error;
				}
				return stripe.createPaymentMethod( { elements: elements } );
			} ).then( function ( result ) {
				if ( result.error ) {
					throw result.error;
				}
				input.value = result.paymentMethod.id;
				var submit = form.querySelector( '[data-memberkin-submit]' );
				if ( form.requestSubmit ) {
					form.requestSubmit( submit || undefined );
				} else {
					form.submit();
				}
			} ).catch( function ( error ) {
				showError( ( error && error.message ) || cfg.i18n.error );
			} );
		} );

		/* Next action after the server confirmed the intent. */
		var secret = root ? root.getAttribute( 'data-client-secret' ) : '';
		var token = form.querySelector( 'input[name="memberkin_token"]' );
		if ( secret && /^(pi|seti)_/.test( secret ) && token && token.value ) {
			stripe.handleNextAction( { clientSecret: secret } ).then( function ( result ) {
				if ( result.error ) {
					showError( result.error.message || cfg.i18n.authFail );
					return;
				}
				window.location.href = cfg.returnBase + encodeURIComponent( token.value );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-memberkin-stripe]' ).forEach( init );
	} );
} )();

/**
 * Memberkin admin behaviour (no build step).
 * - [data-memberkin-toggle="selector"] on a checkbox/select shows or hides the targeted rows.
 * - [data-memberkin-confirm="message"] on a link/button asks for confirmation.
 * - [data-memberkin-autorefresh] reloads the page every 5 seconds while background jobs run.
 */
( function () {
	'use strict';

	function applyToggle( control ) {
		var targets = document.querySelectorAll( control.getAttribute( 'data-memberkin-toggle' ) );
		var show;
		if ( control.type === 'checkbox' ) {
			show = control.checked;
		} else {
			var values = ( control.getAttribute( 'data-memberkin-show-values' ) || '' ).split( ',' );
			show = values.indexOf( control.value ) !== -1;
		}
		targets.forEach( function ( el ) {
			el.hidden = ! show;
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( document.querySelector( '[data-memberkin-autorefresh]' ) ) {
			var touched = false; // Never reload over a form the user started filling in.
			document.addEventListener( 'change', function () {
				touched = true;
			} );
			window.setTimeout( function () {
				if ( ! touched ) {
					window.location.reload();
				}
			}, 5000 );
		}
		document.querySelectorAll( '[data-memberkin-toggle]' ).forEach( function ( control ) {
			applyToggle( control );
			control.addEventListener( 'change', function () {
				applyToggle( control );
			} );
		} );
	} );

	document.addEventListener( 'click', function ( event ) {
		var el = event.target.closest( '[data-memberkin-confirm]' );
		if ( el && ! window.confirm( el.getAttribute( 'data-memberkin-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
} )();

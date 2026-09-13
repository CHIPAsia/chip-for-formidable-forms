/**
 * CHIP subscription renewal actions.
 *
 * Adds an immediate "retry now" on the payments sidebar, so a merchant does not
 * have to wait for the next scheduled retry to learn whether a card works again.
 *
 * The result is written into a live region as well as onto the screen: a merchant
 * using a screen reader gets no feedback at all from a silent in-place update, and
 * a charge is not something to leave ambiguous.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
			return;
		}

		document.addEventListener( 'DOMContentLoaded', fn );
	}

	/**
	 * The region that reports the outcome to assistive tech.
	 *
	 * The list screen ships one. The payments sidebar does not, so create a
	 * visually hidden one rather than reusing the visible message, which would
	 * double-announce for a sighted screen-reader user.
	 */
	function liveRegion() {
		var region = document.querySelector( '.frm_chip_live_region' );

		if ( region ) {
			return region;
		}

		region = document.createElement( 'div' );
		region.className = 'frm_chip_live_region screen-reader-text';
		region.setAttribute( 'aria-live', 'polite' );
		region.setAttribute( 'role', 'status' );

		document.body.appendChild( region );

		return region;
	}

	ready( function () {
		var button = document.querySelector( '.frm_chip_retry_renewal' );

		if ( ! button ) {
			return;
		}

		var region = liveRegion();
		var fallback = button.getAttribute( 'data-failed' );

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			// A second click while the first charge is in flight would issue a
			// second charge, so the control is disabled rather than merely
			// guarded by a flag.
			if ( button.disabled ) {
				return;
			}

			button.disabled = true;
			button.setAttribute( 'aria-busy', 'true' );

			var original = button.textContent;

			button.textContent = button.getAttribute( 'data-working' ) || 'Working…';

			var body = new URLSearchParams();
			body.append( 'action', 'frm_chip_retry_renewal' );
			body.append( 'sub', button.getAttribute( 'data-sub' ) );
			body.append( 'nonce', button.getAttribute( 'data-nonce' ) );
			// The action, so the server can tell a retry from an early renewal.
			body.append( 'mode', button.getAttribute( 'data-mode' ) || 'retry' );

			fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( response ) {
					return response.text();
				} )
				.then( function ( html ) {
					// Formidable's own refund/cancel handlers answer with markup,
					// so mirror that rather than inventing a second convention.
					var box = document.createElement( 'div' );
					box.innerHTML = html;

					var message = box.querySelector( '.frm_updated_message, .frm_error_style' );

					if ( message ) {
						button.parentNode.insertBefore( message, button.nextSibling );

						// Inserting a node is silent, so announce it as well.
						region.textContent = ( message.textContent || '' ).trim();
					} else {
						// Never leave the merchant guessing whether a charge
						// happened.
						region.textContent = fallback || 'The retry finished without a result.';
					}

					// A charged renewal changes the subscription, so the panel is
					// reloaded to show the new state. Leaving the button live would
					// invite a second click on a subscription that no longer needs
					// a retry.
					var charged = box.querySelector( '.frm_updated_message' );
					var succeeded = charged && /charged/i.test( charged.textContent );

					if ( succeeded ) {
						window.location.reload();
						return;
					}

					button.disabled = false;
					button.removeAttribute( 'aria-busy' );
					button.textContent = original;
				} )
				.catch( function () {
					button.disabled = false;
					button.removeAttribute( 'aria-busy' );
					button.textContent = original;

					region.textContent = fallback || 'The retry could not be sent. Please try again.';
				} );
		} );
	} );
}() );

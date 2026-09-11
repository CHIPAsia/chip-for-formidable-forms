/**
 * CHIP subscription renewal actions.
 *
 * Adds an immediate "retry now" on the payments sidebar, so a merchant does not
 * have to wait for the next scheduled retry to learn whether a card works again.
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

	ready( function () {
		var link = document.querySelector( '.frm_chip_retry_renewal' );

		if ( ! link ) {
			return;
		}

		link.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( link.dataset.busy ) {
				return;
			}

			link.dataset.busy = '1';
			var original = link.textContent;

			link.textContent = link.dataset.working || 'Working…';

			var body = new URLSearchParams();
			body.append( 'action', 'frm_chip_retry_renewal' );
			body.append( 'sub', link.dataset.sub );
			body.append( 'nonce', link.dataset.nonce );

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
						link.parentNode.insertBefore( message, link.nextSibling );
					}

					delete link.dataset.busy;
					link.textContent = original;
				} )
				.catch( function () {
					delete link.dataset.busy;
					link.textContent = original;
				} );
		} );
	} );
}() );

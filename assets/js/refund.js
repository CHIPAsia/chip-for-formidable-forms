/**
 * Refund-then-cancel for a payment that funds a subscription.
 *
 * Core's refund link asks a single "are you sure" question. That cannot express
 * the difference between giving one period back (the subscription keeps running)
 * and ending the arrangement (no further charges). The payment sidebar therefore
 * offers two links, and this chains the subscription cancellation onto the second
 * one — but only after the refund itself has succeeded.
 *
 * Order matters: cancelling first would delete the recurring token, and a refund
 * that then failed would have ended the subscription for nothing. So the refund
 * runs first, its response is checked, and the cancel is only sent if the refund
 * reported success.
 */
( function() {
	'use strict';

	/**
	 * Whether a response element reports success.
	 *
	 * The handlers render `<div class="frm_updated_message">` on success and
	 * `<div class="frm_error_style">` on failure, so the class is the signal.
	 *
	 * @param {Element|null} element Response element.
	 * @return {boolean} True when the response reports success.
	 */
	function isSuccessResponse( element ) {
		if ( ! element ) {
			return false;
		}

		return null !== element.querySelector( '.frm_updated_message, .frm_updated_message p' )
			|| ( element.classList && element.classList.contains( 'frm_updated_message' ) );
	}

	/**
	 * Send the cancel request for a subscription.
	 *
	 * @param {string} url Cancel URL with its nonce.
	 * @return {Promise<boolean>} Resolves true when cancellation succeeded.
	 */
	async function cancelSubscription( url ) {
		try {
			const response = await fetch( url, { credentials: 'same-origin' } );
			const html = await response.text();

			const holder = document.createElement( 'div' );
			holder.innerHTML = html;

			return isSuccessResponse( holder );
		} catch ( error ) {
			return false;
		}
	}

	/**
	 * Replace a link with a status message.
	 *
	 * @param {Element} link    The link being acted on.
	 * @param {string}  message Text to show.
	 * @param {boolean} ok      Whether it succeeded.
	 * @return {void}
	 */
	function showResult( link, message, ok ) {
		const holder = document.createElement( 'div' );
		holder.className = ok ? 'frm_updated_message' : 'frm_error_style';
		holder.textContent = message;

		link.replaceWith( holder );
	}

	/**
	 * Hide core's own refund link when the choice is offered.
	 *
	 * Core renders a plain "Refund" link in the sidebar before our hook runs, and
	 * it cannot be filtered server-side (the filter is hardcoded to stripe and
	 * authnet). Leaving it would put two refund controls side by side, one of which
	 * silently ignores the subscription. It is removed only when we are showing
	 * the choice, so a payment without a live subscription keeps core's link.
	 *
	 * @return void
	 */
	function removeCoreRefundLink() {
		const choice = document.querySelector( '[data-frm-chip-refund-choice]' );

		if ( ! choice ) {
			return;
		}

		[ ...document.querySelectorAll( '.frm_trans_ajax_link' ) ].forEach( function( link ) {
			if ( ! /refund/i.test( link.textContent ) ) {
				return;
			}

			// Compare the enclosing sidebar box, not an ancestor: every box shares
			// the same .inside wrapper, so an ancestor test matches everything and
			// the removal never runs. This link's own box is what must differ.
			const box = link.closest( '.misc-pub-section' );
			const mine = choice.closest( '.misc-pub-section' );

			if ( box && mine && box === mine ) {
				return;
			}

			( box || link ).remove();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', removeCoreRefundLink );
	} else {
		removeCoreRefundLink();
	}

	document.addEventListener( 'click', async function( event ) {
		const link = event.target.closest( '.frm_chip_refund_and_cancel' );

		if ( ! link ) {
			return;
		}

		// Core binds its own confirm flow to .frm_trans_ajax_link, so stop that
		// and run this instead. The confirm text on the link is still honoured by
		// core's modal, which is what dispatched this click in the first place.
		event.preventDefault();
		event.stopImmediatePropagation();

		const refundUrl = link.getAttribute( 'href' );
		const cancelUrl = link.dataset.frmChipCancel;

		if ( ! refundUrl || ! cancelUrl ) {
			return;
		}

		const original = link.innerHTML;
		link.textContent = '…';

		let refundOk = false;

		try {
			const response = await fetch( refundUrl, { credentials: 'same-origin' } );
			const html = await response.text();

			const holder = document.createElement( 'div' );
			holder.innerHTML = html;
			refundOk = isSuccessResponse( holder );
		} catch ( error ) {
			refundOk = false;
		}

		if ( ! refundOk ) {
			link.innerHTML = original;
			showResult(
				link,
				'The refund did not go through, so the subscription was left as it is.',
				false
			);
			return;
		}

		const cancelOk = await cancelSubscription( cancelUrl );

		if ( cancelOk ) {
			showResult( link, 'Refunded and the subscription was cancelled.', true );
			return;
		}

		// The money went back but the subscription still stands: say so, because
		// this is the state a merchant must not be left guessing about.
		showResult(
			link,
			'Refunded, but the subscription could not be cancelled. Cancel it from the CHIP Subscriptions screen.',
			false
		);
	}, true );
}() );

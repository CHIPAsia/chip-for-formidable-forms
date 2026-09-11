/**
 * Admin behaviour for the CHIP payment action.
 *
 * Formidable's shared payment action toggles gateway sections by the
 * `show_<gateway>` class, so CHIP's panel carries `show_chip` and core handles
 * the gateway switching.
 *
 * Two things core does not cover:
 *
 * 1. The CHIP panel sits outside the `frm_trans_sub_opts` recurring block, so it
 *    stays visible for one-time payments too. Core hides that block for one-time
 *    payments, which would otherwise hide the whole CHIP section.
 * 2. The per-form payment method override shows its checkbox list only when the
 *    mode is set to "Choose methods for this form".
 */
( function() {
	'use strict';

	const GATEWAY = 'chip';

	/**
	 * Whether the CHIP gateway is selected in a given settings container.
	 *
	 * @param {Element} settings Action settings container.
	 * @return {boolean} True when CHIP is selected.
	 */
	function isChipSelected( settings ) {
		const input = settings.querySelector( '[name*="[post_content][gateway]"][value="' + GATEWAY + '"]' );

		return Boolean( input && input.checked );
	}

	/**
	 * Show or hide the CHIP panel to match the current selection.
	 *
	 * @param {Element} settings Action settings container.
	 * @return {void}
	 */
	function syncChipPanel( settings ) {
		if ( ! settings ) {
			return;
		}

		const panels = settings.querySelectorAll( '.show_' + GATEWAY );

		if ( ! panels.length ) {
			return;
		}

		const selected = isChipSelected( settings );

		panels.forEach(
			function( panel ) {
				if ( selected ) {
					panel.classList.remove( 'frm_hidden' );
					panel.style.display = panel.classList.contains( 'frm_grid_container' ) ? 'grid' : '';
					return;
				}

				panel.classList.add( 'frm_hidden' );
				panel.style.display = 'none';
			}
		);
	}

	/**
	 * Show the per-form checkbox list only in "custom" mode.
	 *
	 * @param {Element} settings Action settings container.
	 * @return {void}
	 */
	function syncWhitelistBlock( settings ) {
		if ( ! settings ) {
			return;
		}

		const select = settings.querySelector( 'select.frm_chip_payment_methods' );

		if ( ! select ) {
			return;
		}

		const block = settings.querySelector( '.frm_chip_whitelist_block' );

		if ( ! block ) {
			return;
		}

		const show = 'custom' === select.value;

		block.classList.toggle( 'frm_hidden', ! show );
		block.style.display = show ? 'grid' : 'none';
	}

	/**
	 * Sync every payment action on the page.
	 *
	 * @return {void}
	 */
	function syncAll() {
		document.querySelectorAll( '.frm_single_payment_settings' ).forEach(
			function( settings ) {
				syncChipPanel( settings );
				syncWhitelistBlock( settings );
			}
		);
	}

	/**
	 * Find the payment action container for a given element.
	 *
	 * @param {Element} element Starting element.
	 * @return {Element|null} Settings container.
	 */
	function closestSettings( element ) {
		return element ? element.closest( '.frm_form_action_settings' ) : null;
	}

	document.addEventListener(
		'change',
		function( event ) {
			const target = event.target;

			if ( ! target ) {
				return;
			}

			const settings = closestSettings( target );

			if ( ! settings ) {
				return;
			}

			if ( target.classList.contains( 'frm_chip_payment_methods' ) ) {
				syncWhitelistBlock( settings );
				return;
			}

			// Gateway radio, payment type dropdown, or anything inside the panel.
			if (
				target.closest( '.frm-long-icon-buttons' ) ||
				target.classList.contains( 'frm_trans_type' ) ||
				target.closest( '.show_' + GATEWAY )
			) {
				// Let core finish its own toggling before we correct it.
				window.setTimeout( function() {
					syncChipPanel( settings );
				}, 0 );
			}
		},
		true
	);

	// Core renders panels on load and after adding an action, so re-sync then.
	if ( window.wp && window.wp.hooks ) {
		window.wp.hooks.addAction( 'frm_trans_toggled_gateway', 'formidable-chip', syncAll );
		window.wp.hooks.addAction( 'frm_filled_form_action', 'formidable-chip', syncAll );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', syncAll );
	} else {
		syncAll();
	}
}() );

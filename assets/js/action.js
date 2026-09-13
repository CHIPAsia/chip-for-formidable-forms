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
 *
 * It also constrains the currency. Formidable offers 31 currencies, but CHIP
 * settles Malaysian merchants in MYR only, so the plugin forces MYR on save. Left
 * alone, a merchant picks USD, sees it silently revert after saving, and has no
 * way to know why. The dropdown is pinned to MYR while CHIP is selected, and the
 * reason is stated, so the choice is never presented as available.
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
	 * Pin the currency to MYR while CHIP is selected.
	 *
	 * Formidable renders one currency dropdown for the shared payment action. The
	 * plugin forces MYR on save, so leaving every currency selectable means the
	 * merchant's choice is discarded with no explanation. Selecting MYR in the
	 * dropdown and marking it read-only shows what will actually be used.
	 *
	 * The select is disabled rather than hidden, so the value is still submitted
	 * with the form and the merchant can see which currency applies.
	 *
	 * @param {Element} settings Action settings container.
	 * @return {void}
	 */
	function syncCurrency( settings ) {
		if ( ! settings ) {
			return;
		}

		// Found by name first, then marked with a class. The name cannot be the
		// ongoing handle: it is moved to the carrier below, so a name-based lookup
		// stops matching on the next run and the function silently does nothing.
		const select = settings.querySelector( 'select.frm_chip_currency_select' )
			|| settings.querySelector( 'select[name*="[post_content][currency]"]' );

		if ( ! select ) {
			return;
		}

		select.classList.add( 'frm_chip_currency_select' );

		// The values the server supplied. Without them the script does nothing,
		// so a stale cached script cannot pin the wrong currency.
		const config = window.frmChipAdmin || {};

		if ( ! config.currency ) {
			return;
		}

		select.dataset.chipCurrency = String( config.currency ).toLowerCase();
		select.dataset.chipNote     = config.currencyLabel || '';

		const selected = isChipSelected( settings );

		// A note explaining why, created once per action.
		let note = settings.querySelector( '.frm_chip_currency_note' );

		if ( ! note ) {
			note = document.createElement( 'p' );
			note.className = 'frm_chip_currency_note description';
			note.textContent = select.dataset.chipNote || '';

			const field = select.closest( '.frm_form_field' ) || select.parentNode;

			if ( field && field.parentNode ) {
				field.parentNode.insertBefore( note, field.nextSibling );
			}
		}

		note.classList.toggle( 'frm_hidden', ! selected );

		// The original name, remembered so it can be restored. The carrier below
		// takes it over while the select is disabled, because a disabled select
		// submits nothing; leaving the name off permanently would drop the field
		// for another gateway later.
		if ( ! select.dataset.chipName ) {
			select.dataset.chipName = select.name;
		}

		const carrier = settings.querySelector( 'input.frm_chip_currency_carrier' );

		if ( ! selected ) {
			// Another gateway is in charge of this action; hand the field back so
			// its own currency choice is what gets saved.
			select.disabled = false;

			if ( carrier ) {
				carrier.remove();
			}

			if ( ! select.name ) {
				select.name = select.dataset.chipName;
			}

			return;
		}

		// A currency the plugin cannot honour is corrected here rather than being
		// silently rewritten on save. Option values are lowercase, so the compare
		// is case-insensitive: a mismatch would leave the dropdown showing nothing.
		const wanted = select.dataset.chipCurrency;
		const match  = [...select.options].find( function( option ) {
			return option.value.toLowerCase() === wanted;
		} );

		if ( match && select.value !== match.value ) {
			select.value = match.value;
		}

		select.disabled = true;

		// Carry the value in a hidden field while the select cannot submit one.
		if ( ! carrier ) {
			const input = document.createElement( 'input' );
			input.type = 'hidden';
			input.className = 'frm_chip_currency_carrier';
			input.name = select.dataset.chipName;
			select.removeAttribute( 'name' );
			select.parentNode.insertBefore( input, select );
		}

		const carrierInput = settings.querySelector( 'input.frm_chip_currency_carrier' );

		if ( carrierInput ) {
			carrierInput.value = select.dataset.chipCurrency;
		}
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
				syncCurrency( settings );
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

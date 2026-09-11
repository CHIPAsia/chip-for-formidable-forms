/**
 * Admin behaviour for the CHIP payment action.
 *
 * Formidable's shared payment action toggles gateway sections by the
 * `show_<gateway>` class. CHIP's settings panel carries `show_chip`, so most of
 * the visibility work is already handled by core. This only covers the cases
 * core does not: the settings panel lives inside the recurring block, and the
 * product name field is only meaningful for recurring actions.
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
	 * Show or hide the CHIP panel to match the current selection and type.
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
		const typeDropdown = settings.querySelector( 'select.frm_trans_type' );
		const isRecurring = Boolean( typeDropdown && 'recurring' === typeDropdown.value );

		panels.forEach(
			function( panel ) {
				if ( selected && isRecurring ) {
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
	 * Sync every payment action on the page.
	 *
	 * @return {void}
	 */
	function syncAll() {
		document.querySelectorAll( '.frm_single_payment_settings' ).forEach( syncChipPanel );
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

			// Gateway radio, payment type dropdown, or anything inside the panel.
			if (
				target.closest( '.frm-long-icon-buttons' ) ||
				target.classList.contains( 'frm_trans_type' ) ||
				target.closest( '.frm_trans_sub_opts' )
			) {
				const settings = closestSettings( target );

				if ( settings ) {
					// Let core finish its own toggling before we correct it.
					window.setTimeout( function() {
						syncChipPanel( settings );
					}, 0 );
				}
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

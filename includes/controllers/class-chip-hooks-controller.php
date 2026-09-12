<?php
/**
 * Hook registration.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Registers the plugin with Formidable's payments layer.
 *
 * CHIP is registered as a gateway on the `frm_payment_gateways` filter rather
 * than as a standalone form action. That makes CHIP a peer of Stripe, Square and
 * PayPal Lite: it rides Formidable's shared payment action, its payment records,
 * its status triggers and its Pro conditional logic, instead of duplicating all
 * of it.
 */
class FrmChipHooksController {

	/**
	 * Gateway key used throughout Formidable.
	 *
	 * @var string
	 */
	const GATEWAY = 'chip';

	/**
	 * Register front end and shared hooks.
	 *
	 * @return void
	 */
	public static function load_hooks() {
		// Register CHIP as a selectable gateway.
		add_filter( 'frm_payment_gateways', 'FrmChipAppController::add_gateway' );

		// Provide defaults for the shared payment action settings.
		add_filter( 'frm_pay_action_defaults', 'FrmChipActionsController::add_action_defaults' );

		// Persist and normalise CHIP specific settings when a payment action is saved.
		add_filter( 'frm_before_save_payment_action', 'FrmChipActionsController::before_save_settings', 30, 2 );

		// Keep CHIP fields hidden in the form builder.
		add_filter( 'frm_show_normal_field_type', 'FrmChipActionsController::hide_order_fields', 10, 3 );

		// Hand the payer to CHIP instead of letting Formidable confirm the entry.
		add_filter( 'frm_success_filter', 'FrmChipActionsController::filter_success_method', 20, 3 );

		// Build the redirect URL at the last moment, when the entry exists.
		add_filter( 'frm_redirect_url', 'FrmChipActionsController::filter_redirect_url', 20, 3 );

		// Verify the payer when they come back from the CHIP checkout.
		add_filter( 'frm_filter_final_form', 'FrmChipReturnController::maybe_show_result' );

		// Handle a payer returning from a card update link.
		add_action( 'init', 'FrmChipReturnController::maybe_handle_card_update' );

		// Verify and record asynchronous CHIP callbacks.
		add_action( 'init', 'FrmChipCallbackController::maybe_handle' );

		// CHIP stores a card token but never renews on its own, so the site has
		// to charge each period itself. load_hooks() also makes sure the cron is
		// scheduled, which the activation hook alone cannot guarantee: a site
		// that updates the plugin in place never re-runs activation, so without
		// this the renewals would silently never run.
		FrmChipRenewals::load_hooks();

		// Tell both sides when a renewal stops working.
		FrmChipNotifications::load_hooks();

		// Run pending upgrade routines after an in-place update.
		add_action( 'admin_init', 'FrmChipInstall::maybe_upgrade' );

		// Receipt display, shared by the payments screen and the entry sidebar.
		add_filter( 'frm_pay_chip_receipt', 'FrmChipPaymentsController::receipt_link' );

		/*
		 * Formidable's refund and cancel handlers are hardcoded switches over
		 * Stripe, Square and PayPal, so a CHIP payment reaches their default
		 * branch and always reports failure. These run first on the same AJAX
		 * requests and bow out for anything that is not a CHIP payment, which
		 * keeps a single refund/cancel action working for every gateway.
		 */
		add_action( 'wp_ajax_frm_trans_refund', 'FrmChipPaymentsController::maybe_handle_refund', 5 );
		add_action( 'wp_ajax_frm_trans_cancel', 'FrmChipSubscriptionsController::maybe_handle_cancel', 5 );
	}

	/**
	 * Register admin only hooks.
	 *
	 * @return void
	 */
	public static function load_admin_hooks() {
		// Global settings section.
		add_filter( 'frm_add_settings_section', 'FrmChipSettingsController::add_settings_section', 20 );
		add_action( 'frm_update_settings', 'FrmChipSettingsController::process_form' );

		// Payment action settings panel, rendered after the recurring section.
		add_action( 'frm_payments_settings_after_recurring', 'FrmChipActionsController::render_action_options' );

		// Admin script for the gateway toggle on the payment action.
		add_action( 'frm_add_form_option_section', 'FrmChipActionsController::enqueue_admin_script' );

		// CHIP symbol for the gateway tab icon, which Formidable's sprite lacks.
		add_action( 'admin_footer', 'FrmChipActionsController::print_gateway_icon' );

		// Refund support on the payments screen.
		// The payments sidebar carries the payment's own actions plus the
		// renewal state of the subscription it belongs to.
		FrmChipSubscriptionsController::load_hooks();
	}
}

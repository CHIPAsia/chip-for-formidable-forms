<?php
/**
 * Subscription cancellation.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Makes Formidable's cancel action work for CHIP subscriptions.
 *
 * Same reasoning as the refund handler: Formidable's cancel handler is a
 * hardcoded switch over Stripe, Square and PayPal, so a CHIP subscription falls
 * to its default branch and always reports failure. This takes over the shared
 * `frm_trans_cancel` request before Formidable's handler runs.
 */
class FrmChipSubscriptionsController {

	/**
	 * Hook into Formidable's cancel request.
	 *
	 * Registered at priority 5 so it runs before Formidable's handler at 10.
	 *
	 * @return void
	 */
	public static function maybe_handle_cancel() {
		$sub_id = isset( $_GET['sub'] ) ? absint( $_GET['sub'] ) : 0;

		if ( ! $sub_id ) {
			return;
		}

		$subscriptions = new FrmTransLiteSubscription();
		$subscription  = $subscriptions->get_one( $sub_id );

		if ( ! $subscription || FrmChipHooksController::GATEWAY !== $subscription->paysys ) {
			return;
		}

		check_ajax_referer( 'frm_trans_ajax', 'nonce' );
		FrmAppHelper::permission_check( 'frm_edit_entries' );

		$api = FrmChipAppController::api();

		if ( is_wp_error( $api ) ) {
			self::respond( false, $api->get_error_message() );
		}

		// The reusable token lives on the purchase that created the subscription.
		$result = $api->delete_recurring_token( $subscription->sub_id );

		if ( is_wp_error( $result ) ) {
			self::respond( false, $result->get_error_message() );
		}

		// CHIP has no renewal engine of its own, so removing the token stops all
		// future charges immediately. Formidable still records a pending
		// cancellation so the period already paid for is honoured.
		FrmTransLiteSubscriptionsController::change_subscription_status(
			array(
				'status' => 'future_cancel',
				'sub'    => $subscription,
			)
		);

		self::respond( true, __( 'Canceled', 'chip-for-formidable-forms' ) );
	}

	/**
	 * Send an AJAX response and stop.
	 *
	 * @param bool   $success Whether the operation succeeded.
	 * @param string $message Message to display.
	 * @return void
	 */
	private static function respond( $success, $message ) {
		wp_die(
			sprintf(
				'<div class="%1$s">%2$s</div>',
				$success ? 'frm_updated_message' : 'frm_error_style',
				esc_html( $message )
			)
		);
	}
}

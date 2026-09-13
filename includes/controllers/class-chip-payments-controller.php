<?php
/**
 * Refund handling.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Makes Formidable's refund action work for CHIP payments.
 *
 * Formidable renders a refund link for any completed payment, but its handler is
 * a hardcoded switch over Stripe, Square and PayPal. A CHIP payment therefore
 * reaches the default branch and always reports "Refund Failed".
 *
 * Rather than adding a second, competing refund link, this controller takes over
 * the shared `frm_trans_refund` request before Formidable's own handler runs. The
 * merchant keeps one refund action, on one screen, that works for every gateway.
 */
class FrmChipPaymentsController {

	/**
	 * Hook into Formidable's refund request.
	 *
	 * Registered at priority 5 so it runs before Formidable's handler at 10. Any
	 * request that is not a CHIP payment is left untouched.
	 *
	 * @return void
	 */
	public static function maybe_handle_refund() {
		$payment_id = isset( $_GET['payment_id'] ) ? absint( $_GET['payment_id'] ) : 0;

		if ( ! $payment_id ) {
			return;
		}

		$payments = new FrmTransLitePayment();
		$payment  = $payments->get_one( $payment_id );

		if ( ! $payment || FrmChipHooksController::GATEWAY !== $payment->paysys ) {
			return;
		}

		check_ajax_referer( 'frm_trans_ajax', 'nonce' );
		FrmAppHelper::permission_check( 'frm_edit_entries' );

		if ( 'complete' !== $payment->status ) {
			self::respond( false, __( 'Only completed payments can be refunded.', 'chip-for-formidable-forms' ) );
		}

		$settings = FrmChipSettings::get_settings();

		if ( ! $settings->get( 'refund' ) ) {
			self::respond( false, __( 'Refunds are disabled in the CHIP settings.', 'chip-for-formidable-forms' ) );
		}

		if ( ! $payment->receipt_id ) {
			self::respond( false, __( 'That payment has no CHIP purchase to refund.', 'chip-for-formidable-forms' ) );
		}

		$api = FrmChipAppController::api();

		if ( is_wp_error( $api ) ) {
			self::respond( false, $api->get_error_message() );
		}

		$result = $api->refund_purchase( $payment->receipt_id );

		if ( is_wp_error( $result ) ) {
			self::respond( false, $result->get_error_message() );
		}

		$status = isset( $result['status'] ) ? (string) $result['status'] : '';

		if ( 'pending_refund' === $status ) {
			// CHIP is still working on it. The callback settles the final state.
			FrmTransLitePaymentsController::change_payment_status( $payment, 'processing' );

			$message = __(
				'Refund submitted. This payment updates once CHIP finishes processing it.',
				'chip-for-formidable-forms'
			);

			self::respond( true, $message );
		}

		FrmTransLitePaymentsController::change_payment_status( $payment, 'refunded' );

		// Record the refund against the subscription. The payment row alone does
		// not tell a merchant that a subscription has had money returned: the
		// subscription keeps running, keeps its bill date, and the screen shows it
		// as an ordinary active subscription. This leaves a trace the screen and
		// the renewal engine can both see.
		self::note_refund_on_subscription( $payment );

		self::respond( true, __( 'Refunded', 'chip-for-formidable-forms' ) );
	}

	/**
	 * Record a refund against the subscription the payment belongs to.
	 *
	 * Core does not link a refund to a subscription, and neither does this plugin's
	 * renewal engine: the subscription keeps its status and its bill date, so the
	 * payer is charged again next period with nothing on the screen to say money
	 * was returned. Refunding the first payment of a subscription is the sharp
	 * case — the payer has their money back and keeps being billed.
	 *
	 * The subscription is deliberately NOT stopped: a merchant refunding one
	 * renewal may only mean to give that period back, and cancelling on their
	 * behalf would be a worse error than leaving it running. What the merchant
	 * lacks is the information, so the refund is recorded and surfaced instead.
	 *
	 * @param stdClass $payment Payment row.
	 * @return void
	 */
	private static function note_refund_on_subscription( $payment ) {
		if ( empty( $payment->sub_id ) ) {
			return;
		}

		$subscriptions = new FrmTransLiteSubscription();
		$subscription  = $subscriptions->get_one( (int) $payment->sub_id );

		if ( ! $subscription ) {
			return;
		}

		$meta = FrmChipSubscriptionsController::get_meta( $subscription );

		$meta['chip_refunded_payment'] = (int) $payment->id;
		$meta['chip_refunded_at']      = gmdate( 'Y-m-d H:i:s' );

		// Count them, so a screen can say "2 payments refunded" without a query.
		$meta['chip_refunded_count'] = isset( $meta['chip_refunded_count'] )
			? (int) $meta['chip_refunded_count'] + 1
			: 1;

		$subscriptions->update(
			$subscription->id,
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column on frm_subscriptions, not postmeta.
				'meta_value' => $meta,
			)
		);

		FrmChipHelper::log(
			'Refund recorded against subscription',
			array(
				'sub'     => $subscription->id,
				'payment' => $payment->id,
			)
		);
	}

	/**
	 * Add the CHIP purchase reference to the payment sidebar.
	 *
	 * Hooked to frm_pay_chip_sidebar.
	 *
	 * @param stdClass $payment Payment row.
	 * @return void
	 */
	public static function sidebar_actions( $payment ) {
		if ( empty( $payment->receipt_id ) ) {
			return;
		}

		$meta = $payment->meta_value ? maybe_unserialize( $payment->meta_value ) : array();
		$last = is_array( $meta ) && ! empty( $meta['chip_purchase']['payment_method'] )
			? $meta['chip_purchase']['payment_method']
			: '';

		if ( '' === $last ) {
			return;
		}

		?>
		<div class="misc-pub-section">
			<?php FrmAppHelper::icon_by_class( 'frmfont frm_credit_card_icon' ); ?>
			<span class="frm_link_label">
				<?php esc_html_e( 'Paid with:', 'chip-for-formidable-forms' ); ?>
				<b><?php echo esc_html( FrmChipPaymentMethods::get_label( $last ) ); ?></b>
			</span>
		</div>
		<?php
	}

	/**
	 * Provide a receipt label for a CHIP payment.
	 *
	 * CHIP has no public per-transaction dashboard, so the purchase ID is shown
	 * as plain text rather than a link that would lead nowhere.
	 *
	 * @param string $receipt Receipt ID.
	 * @return string
	 */
	public static function receipt_link( $receipt ) {
		return esc_html( $receipt );
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

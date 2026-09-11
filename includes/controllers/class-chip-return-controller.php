<?php
/**
 * Browser return handler.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Handles the payer coming back from the CHIP checkout.
 *
 * The return redirect is not trustworthy on its own, so the purchase is always
 * re-checked against the CHIP API before anything is settled. Once verified, the
 * form's own configured confirmation runs, so the merchant's success message,
 * redirect and email triggers behave exactly as they would for a normal
 * submission.
 */
class FrmChipReturnController {

	/**
	 * Query arg carrying the entry ID.
	 *
	 * @var string
	 */
	const ENTRY_ARG = 'frmchip';

	/**
	 * Query arg carrying the status hint.
	 *
	 * @var string
	 */
	const STATUS_ARG = 'frmchipst';

	/**
	 * Maybe render the payment result instead of the form.
	 *
	 * Hooked to frm_filter_final_form, which passes the finished form HTML.
	 *
	 * @param string $html Form HTML.
	 * @return string
	 */
	public static function maybe_show_result( $html ) {
		$entry_id = absint( FrmAppHelper::simple_get( self::ENTRY_ARG, 'absint' ) );

		if ( ! $entry_id ) {
			return $html;
		}

		$entry = FrmEntry::getOne( $entry_id, true );

		if ( ! $entry ) {
			return $html;
		}

		$form = FrmForm::getOne( $entry->form_id );

		if ( ! $form ) {
			return $html;
		}

		// Only take over for forms that actually used CHIP.
		$payment = self::get_latest_payment( $entry_id );

		if ( ! $payment ) {
			return $html;
		}

		$result = FrmChipSettlement::verify_and_settle( $payment->receipt_id );

		if ( is_wp_error( $result ) ) {
			FrmChipHelper::log( 'Return verification failed', $result->get_error_message() );

			return self::insert_message(
				$html,
				'<div class="frm_error_style">' . esc_html( $result->get_error_message() ) . '</div>'
			);
		}

		$purchase = $result['purchase'];
		$status   = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';

		if ( FrmChipSettlement::is_paid( $status ) ) {
			return self::render_success( $html, $form, $entry );
		}

		if ( FrmChipSettlement::is_failed( $status ) ) {
			return self::insert_message( $html, self::get_failure_message( $purchase ) );
		}

		// Anything still in flight: tell the payer to wait for confirmation
		// rather than implying the payment succeeded or failed.
		return self::insert_message( $html, self::get_pending_message() );
	}

	/**
	 * Run the form's own confirmation for a verified payment.
	 *
	 * @param string   $html  Original form HTML.
	 * @param stdClass $form  Form.
	 * @param stdClass $entry Entry.
	 * @return string
	 */
	private static function render_success( $html, $form, $entry ) {
		$atts = array(
			'form'     => $form,
			'entry_id' => $entry->id,
			'fields'   => FrmFieldsHelper::get_form_fields( $form->id ),
			'entry'    => $entry,
		);

		ob_start();
		FrmFormsController::run_on_submit_actions( $atts );
		$output = ob_get_clean();

		if ( '' !== trim( (string) $output ) ) {
			return $output;
		}

		// No configured confirmation to run, so acknowledge the payment.
		return self::insert_message( $html, self::get_success_message() );
	}

	/**
	 * Get the most recent payment for an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return stdClass|null
	 */
	private static function get_latest_payment( $entry_id ) {
		$payments = new FrmTransLitePayment();
		$rows     = $payments->get_all_for_entry( $entry_id );

		foreach ( (array) $rows as $row ) {
			if ( FrmChipHooksController::GATEWAY === $row->paysys && ! empty( $row->receipt_id ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Build the failure notice.
	 *
	 * @param array $purchase Decoded CHIP purchase.
	 * @return string
	 */
	private static function get_failure_message( $purchase ) {
		$message = __( 'Your payment was not completed.', 'chip-for-formidable-forms' );

		$reason = self::get_failure_reason( $purchase );

		if ( '' !== $reason ) {
			$message .= ' ' . $reason;
		}

		return '<div class="frm_error_style">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Extract a human readable failure reason from a purchase.
	 *
	 * @param array $purchase Decoded CHIP purchase.
	 * @return string
	 */
	private static function get_failure_reason( $purchase ) {
		$attempts = isset( $purchase['transaction_data']['attempts'] )
			? $purchase['transaction_data']['attempts']
			: array();

		if ( ! is_array( $attempts ) || ! $attempts ) {
			return '';
		}

		$latest = reset( $attempts );

		if ( is_array( $latest ) && ! empty( $latest['error']['message'] ) ) {
			return (string) $latest['error']['message'];
		}

		return '';
	}

	/**
	 * Notice for a payment that has not finalised yet.
	 *
	 * @return string
	 */
	private static function get_pending_message() {
		return '<div class="frm_message">' . esc_html__(
			'Your payment is still being confirmed. You will receive a notification once it completes.',
			'chip-for-formidable-forms'
		) . '</div>';
	}

	/**
	 * Notice for a payment that completed without a configured confirmation.
	 *
	 * @return string
	 */
	private static function get_success_message() {
		return '<div class="frm_message">' . esc_html__(
			'Thank you. Your payment has been received.',
			'chip-for-formidable-forms'
		) . '</div>';
	}

	/**
	 * Place a message inside the form so Formidable's styling applies.
	 *
	 * @param string $html    Form HTML.
	 * @param string $message Message markup.
	 * @return string
	 */
	private static function insert_message( $html, $message ) {
		$anchor = '<fieldset>';
		$pos    = strpos( $html, $anchor );

		if ( false === $pos ) {
			return $message;
		}

		return substr_replace( $html, $anchor . $message, $pos, strlen( $anchor ) );
	}
}

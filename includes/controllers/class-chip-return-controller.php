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
	 * Query arg naming the subscription a card update belongs to.
	 *
	 * @var string
	 */
	const CARD_ARG = 'frmchip_card';

	/**
	 * Query arg carrying the card update key.
	 *
	 * @var string
	 */
	const CARD_KEY_ARG = 'frmchip_key';

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
		$payments = self::get_settleable_payments( $entry_id );

		if ( ! $payments ) {
			// Nothing left to settle. If the entry has a CHIP payment at all it
			// already reached a final state, so show the result rather than the
			// form so a returning payer is not asked to pay again.
			return self::maybe_show_final_result( $html, $entry_id, $form, $entry );
		}

		$result = self::settle_all( $payments );

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
	 * Handle a payer returning from a card update checkout.
	 *
	 * Runs on `init` rather than through a form, because the payer arrives from
	 * an email link and no form is being submitted. The link is authorised by a
	 * key derived from the site secret, so no login is required — a Formidable
	 * form has no customer account to log into.
	 *
	 * @return void
	 */
	public static function maybe_handle_card_update() {
		$subscription_id = absint( FrmAppHelper::simple_get( self::CARD_ARG, 'absint' ) );

		if ( ! $subscription_id ) {
			return;
		}

		$key          = FrmAppHelper::simple_get( self::CARD_KEY_ARG, 'sanitize_text_field' );
		$subscription = FrmChipRenewals::resolve_card_update_request( $subscription_id, $key );

		if ( is_wp_error( $subscription ) ) {
			FrmChipHelper::log( 'Card update rejected', $subscription->get_error_message() );
			self::render_notice(
				__(
					'This card update link is not valid. Please contact the site for help.',
					'chip-for-formidable-forms'
				),
				false
			);
		}

		// The purchase a previous visit created, if any. On the return leg from
		// CHIP it is the checkout the payer just completed; on a first visit
		// there is none. Its status is what distinguishes the two, because CHIP
		// only echoes back the parameters we set at creation, and the purchase
		// does not exist until after it is created.
		$pending = FrmChipRenewals::get_card_update_purchase( $subscription );

		if ( '' !== $pending && FrmChipRenewals::card_update_is_complete( $pending ) ) {
			self::finish_card_update( $subscription, $pending );
			return;
		}

		// First visit, or a previous attempt the payer did not complete: open a
		// fresh checkout. A checkout is single-use, so one is created per visit
		// rather than being baked into the emailed link.
		$url = FrmChipRenewals::create_card_update_link( $subscription );

		if ( is_wp_error( $url ) ) {
			FrmChipHelper::log( 'Card update checkout failed', $url->get_error_message() );
			self::render_notice(
				__(
					'We could not open the card update page. Please contact the site for help.',
					'chip-for-formidable-forms'
				),
				false
			);
		}

		// wp_redirect rather than wp_safe_redirect: the target is the CHIP
		// checkout on gate.chip-in.asia, and wp_safe_redirect() refuses any host
		// outside this site — it silently falls back to wp-admin, which is what
		// the payer would otherwise see. The URL is not user input: it is the
		// checkout_url CHIP just returned for a purchase this site created.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Verify the new card and store it against the subscription.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param string   $purchase_id  Purchase that captured the card.
	 * @return void
	 */
	private static function finish_card_update( $subscription, $purchase_id ) {
		$api      = FrmChipAppController::api();
		$purchase = is_wp_error( $api ) ? $api : $api->get_purchase( $purchase_id );

		if ( is_wp_error( $purchase ) ) {
			self::render_notice(
				__( 'We could not check that card. Please try the link again.', 'chip-for-formidable-forms' ),
				false
			);
		}

		$status = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';

		// skip_capture means an authorised card comes back preauthorized.
		if ( ! in_array( $status, array( 'paid', 'preauthorized' ), true ) ) {
			self::render_notice(
				__(
					'That card could not be saved. Please try again with a different card.',
					'chip-for-formidable-forms'
				),
				false
			);
		}

		$done = FrmChipRenewals::replace_token( $subscription, $purchase_id );

		if ( is_wp_error( $done ) ) {
			self::render_notice( $done->get_error_message(), false );
		}

		self::render_notice(
			__(
				'Thank you. Your new card has been saved and your subscription will continue.',
				'chip-for-formidable-forms'
			),
			true
		);
	}

	/**
	 * Show a short standalone message and stop.
	 *
	 * The payer arrives from an email with no page to return to, so the message
	 * has to stand on its own rather than being injected into a form.
	 *
	 * @param string $message Message to show.
	 * @param bool   $success Whether it is good news.
	 * @return void
	 */
	private static function render_notice( $message, $success ) {
		$title = $success
			? __( 'Card updated', 'chip-for-formidable-forms' )
			: __( 'Card not updated', 'chip-for-formidable-forms' );

		$class = $success ? 'frm_message' : 'frm_error_style';

		wp_die(
			'<div class="' . esc_attr( $class ) . '">' . esc_html( $message ) . '</div>',
			esc_html( $title ),
			array(
				'response'  => 200,
				// A plain back link rather than a form action, since this did not
				// come from a form submission.
				'link_text' => esc_html__( 'Back to the site', 'chip-for-formidable-forms' ),
				'link_url'  => esc_url( home_url( '/' ) ),
			)
		);
	}

	/**
	 * Verify every unsettled CHIP payment for the entry.
	 *
	 * Only one can be the purchase the payer just completed, and that is the one
	 * whose status decides what the payer sees. The rest are verified too so an
	 * abandoned earlier attempt is not left pending forever.
	 *
	 * A failure on one payment does not stop the others: the entry's outcome is
	 * driven by whichever purchase is actually paid.
	 *
	 * @param array $payments Payment rows.
	 * @return array|WP_Error Payment/settlement info for the deciding purchase.
	 */
	private static function settle_all( $payments ) {
		$deciding   = null;
		$last_error = null;

		foreach ( $payments as $payment ) {
			$result = FrmChipSettlement::verify_and_settle( $payment->receipt_id );

			if ( is_wp_error( $result ) ) {
				$last_error = $result;
				continue;
			}

			$status = isset( $result['purchase']['status'] ) ? (string) $result['purchase']['status'] : '';

			// A paid purchase settles the outcome immediately.
			if ( FrmChipSettlement::is_paid( $status ) ) {
				return $result;
			}

			if ( null === $deciding ) {
				$deciding = $result;
			}
		}

		if ( null !== $deciding ) {
			return $deciding;
		}

		return $last_error ? $last_error : new WP_Error(
			'chip_payment_not_found',
			__( 'That payment does not match a payment recorded on this site.', 'chip-for-formidable-forms' )
		);
	}

	/**
	 * Show the stored outcome for an entry whose payments are all final.
	 *
	 * Reached when a payer reloads the return URL after the payment completed.
	 * The form is replaced rather than shown again so they are not invited to
	 * pay a second time.
	 *
	 * @param string   $html     Form HTML.
	 * @param int      $entry_id Entry ID.
	 * @param stdClass $form     Form.
	 * @param stdClass $entry    Entry.
	 * @return string
	 */
	private static function maybe_show_final_result( $html, $entry_id, $form, $entry ) {
		$payments = new FrmTransLitePayment();
		$rows     = $payments->get_all_for_entry( $entry_id );

		foreach ( (array) $rows as $row ) {
			if ( FrmChipHooksController::GATEWAY !== $row->paysys ) {
				continue;
			}

			if ( 'complete' === (string) $row->status ) {
				return self::render_success( $html, $form, $entry );
			}

			if ( 'failed' === (string) $row->status ) {
				return self::insert_message(
					$html,
					'<div class="frm_error_style">'
						. esc_html__( 'Your payment was not completed.', 'chip-for-formidable-forms' )
						. '</div>'
				);
			}
		}

		return $html;
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
	 * Get the CHIP payments for an entry that could still settle.
	 *
	 * An entry can carry more than one CHIP payment when a payer retries after a
	 * failure. The return URL identifies the entry, not the purchase, so every
	 * unsettled payment for that entry is verified rather than only the newest —
	 * otherwise a payer returning from a second attempt would leave the newest
	 * payment pending, and settling "the latest" could mark the wrong row paid.
	 *
	 * Already-final payments are skipped so a return does no needless API work
	 * and cannot disturb a completed record.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array Payment rows, newest first.
	 */
	private static function get_settleable_payments( $entry_id ) {
		$payments = new FrmTransLitePayment();
		$rows     = $payments->get_all_for_entry( $entry_id );
		$final    = array( 'complete', 'refunded', 'canceled', 'failed' );
		$pending  = array();

		foreach ( (array) $rows as $row ) {
			if ( FrmChipHooksController::GATEWAY !== $row->paysys || empty( $row->receipt_id ) ) {
				continue;
			}

			if ( in_array( (string) $row->status, $final, true ) ) {
				continue;
			}

			$pending[] = $row;
		}

		return $pending;
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

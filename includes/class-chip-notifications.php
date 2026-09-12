<?php
/**
 * Renewal notifications.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Emails the merchant and the payer when a renewal cannot be charged.
 *
 * Both sides need to know, for different reasons: the merchant has revenue at
 * risk and may want to chase it, and the payer has a card to fix and would
 * otherwise keep receiving a service they are no longer paying for.
 *
 * The payer's email carries a link that lets them hand over a new card without
 * logging in. A Formidable form has no customer account to log into, so the link
 * is authorised by a key derived from the site secret — see
 * FrmChipRenewals::card_update_key().
 */
class FrmChipNotifications {

	/**
	 * Largest number of emails one cron run may send.
	 *
	 * A site with many failing subscriptions should still get through its mail
	 * queue rather than trying to send everything at once.
	 *
	 * @var int
	 */
	const MAX_PER_RUN = 50;

	/**
	 * Emails sent during this run, across both audiences.
	 *
	 * @var int
	 */
	private static $sent = 0;

	/**
	 * Register the notification hooks.
	 *
	 * @return void
	 */
	public static function load_hooks() {
		add_action( 'frm_chip_subscription_failed', array( __CLASS__, 'on_subscription_failed' ), 10, 2 );
	}

	/**
	 * Send the notifications for a failed subscription.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param string   $reason       Why the renewal stopped.
	 * @return void
	 */
	public static function on_subscription_failed( $subscription, $reason ) {
		self::notify_merchant( $subscription, $reason );
		self::notify_payer( $subscription, $reason );
	}

	/**
	 * Tell the merchant a subscription stopped renewing.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param string   $reason       Failure reason.
	 * @return void
	 */
	private static function notify_merchant( $subscription, $reason ) {
		if ( ! self::may_send() ) {
			return;
		}

		$to = self::merchant_address();

		if ( ! $to ) {
			return;
		}

		$admin_url = admin_url( 'admin.php?page=formidable-payments&trans_type=subscriptions' );
		$subject   = sprintf(
			/* translators: %s: site name. */
			__( '[%s] A subscription renewal failed', 'chip-for-formidable-forms' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body = array(
			__(
				'A CHIP subscription could not be renewed and is no longer being charged.',
				'chip-for-formidable-forms'
			),
			'',
			sprintf(
				/* translators: %s: subscription ID. */
				__( 'Subscription: #%s', 'chip-for-formidable-forms' ),
				$subscription->id
			),
			sprintf(
				/* translators: %s: reason the charge failed. */
				__( 'Reason: %s', 'chip-for-formidable-forms' ),
				$reason
			),
			sprintf(
				/* translators: %s: amount. */
				__( 'Amount: %s', 'chip-for-formidable-forms' ),
				FrmChipHelper::format_amount( $subscription->amount )
			),
			'',
			__( 'You can retry the charge from the Subscriptions list:', 'chip-for-formidable-forms' ),
			$admin_url,
		);

		wp_mail( $to, $subject, implode( "\n", $body ) );

		++self::$sent;
	}

	/**
	 * Tell the payer their card could not be charged, and offer a way to fix it.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param string   $reason       Failure reason.
	 * @return void
	 */
	private static function notify_payer( $subscription, $reason ) {
		$to = self::payer_address( $subscription );

		if ( ! $to || ! self::may_send() ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] We could not charge your payment card', 'chip-for-formidable-forms' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body = array(
			__(
				'We tried to charge the card saved for your subscription, but the payment did not go through.',
				'chip-for-formidable-forms'
			),
			'',
			sprintf(
				/* translators: %s: amount. */
				__( 'Amount outstanding: %s', 'chip-for-formidable-forms' ),
				FrmChipHelper::format_amount( $subscription->amount )
			),
			'',
			__(
				'If your card has expired or been replaced, use the link below to add a new one. No payment is taken.',
				'chip-for-formidable-forms'
			),
		);

		$link = FrmChipRenewals::card_update_url( $subscription );

		if ( $link ) {
			$body[] = '';
			$body[] = $link;
		} else {
			// Without a link the payer has to contact the site, so say so rather
			// than leaving them with no next step.
			$body[] = '';
			$body[] = __(
				'Please reply to this email or contact us so we can update your payment details.',
				'chip-for-formidable-forms'
			);
		}

		wp_mail( $to, $subject, implode( "\n", $body ) );

		++self::$sent;
	}

	/**
	 * Where merchant notifications go.
	 *
	 * A filter is offered because the person who should hear about a failed
	 * renewal is not always the WordPress administrator.
	 *
	 * @return string|false
	 */
	public static function merchant_address() {
		$to = apply_filters( 'frm_chip_merchant_notification_email', get_option( 'admin_email' ) );

		return is_email( $to ) ? $to : false;
	}

	/**
	 * The payer's address, taken from the entry the subscription came from.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string|false
	 */
	public static function payer_address( $subscription ) {
		$entry = FrmEntry::getOne( (int) $subscription->item_id, true );

		if ( ! $entry ) {
			return false;
		}

		$action = self::get_action( $subscription, $entry );

		if ( ! $action || empty( $action->post_content['chip_billing_email'] ) ) {
			return false;
		}

		$email = FrmChipHelper::get_entry_value( $action->post_content['chip_billing_email'], $entry );

		if ( is_email( $email ) ) {
			return $email;
		}

		// Fall back to any email field on the entry: a site that has not mapped
		// a billing email still wants its payer told.
		foreach ( (array) $entry->metas as $value ) {
			if ( is_email( $value ) ) {
				return $value;
			}
		}

		return false;
	}

	/**
	 * The payment action behind a subscription.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param stdClass $entry        Entry.
	 * @return WP_Post|null
	 */
	private static function get_action( $subscription, $entry ) {
		$form = FrmForm::getOne( $entry->form_id );

		if ( ! $form ) {
			return null;
		}

		foreach ( (array) FrmFormAction::get_action_for_form( $form->id, 'payment' ) as $action ) {
			if ( (int) $action->ID === (int) $subscription->action_id ) {
				return $action;
			}
		}

		return null;
	}

	/**
	 * Whether another email may be sent in this run.
	 *
	 * @return bool
	 */
	private static function may_send() {
		/**
		 * Filter whether renewal notification emails are sent at all.
		 *
		 * A site that handles its own communications can turn these off.
		 *
		 * @param bool     $enabled Whether to send.
		 * @param stdClass $subscription Subscription row.
		 */
		return self::$sent < self::MAX_PER_RUN && (bool) apply_filters( 'frm_chip_send_renewal_notifications', true );
	}
}

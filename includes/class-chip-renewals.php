<?php
/**
 * Recurring renewal.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Charges CHIP subscriptions when they come due.
 *
 * CHIP stores a card token but has no renewal engine of its own, so the site has
 * to issue every charge after the first. Stripe gets renewals pushed to it by
 * Stripe's own engine; CHIP does not work that way, which is why this class
 * exists.
 *
 * Two design points worth stating explicitly:
 *
 * 1. A failed charge is retried on a fixed schedule, because most declines are
 *    temporary (insufficient funds, issuer hold). Stripe's default is 8 tries in
 *    2 weeks; this uses 4 attempts across 14 days, which is the common
 *    fixed-schedule shape for a plugin with no machine-learning retry engine.
 *
 * 2. A *hard* decline is not retried. When CHIP rejects the token itself
 *    (`invalid_recurring_token`) the card is gone — expired, cancelled or the
 *    token deleted — and no amount of retrying will help, so the subscription is
 *    failed immediately rather than leaving the payer in a zombie state.
 */
class FrmChipRenewals {

	/**
	 * Days after the due date to attempt each retry.
	 *
	 * Attempt 0 is the due date itself. The tail (3, 5, 7) gives a card that was
	 * merely short of funds several chances across two weeks, which is where most
	 * recoverable declines resolve.
	 *
	 * @var int[]
	 */
	const RETRY_OFFSETS = array( 0, 3, 5, 7 );

	/**
	 * Consecutive failed attempts before automated retrying stops.
	 *
	 * Stripe stops after 10 consecutive declines. Going further rarely recovers
	 * anything and starts to look like aggressive retrying to the issuer.
	 *
	 * @var int
	 */
	const MAX_CONSECUTIVE_ATTEMPTS = 10;

	/**
	 * Rolling window for the card network retry cap, in days.
	 *
	 * @var int
	 */
	const NETWORK_WINDOW_DAYS = 30;

	/**
	 * Total charge attempts allowed against one card within the window.
	 *
	 * Visa and Mastercard both cap retries at 15 attempts per card per 30 days
	 * and levy penalty fees above that, so the cap is enforced rather than left
	 * to the merchant to remember. A manual retry still has to fit inside it.
	 *
	 * @var int
	 */
	const NETWORK_MAX_ATTEMPTS = 15;

	/**
	 * CHIP error codes that mean the token is dead, not that this attempt failed.
	 *
	 * @var string[]
	 */
	const HARD_DECLINE_CODES = array(
		'invalid_recurring_token',
	);

	/**
	 * Register the cron handler, and make sure the schedule exists.
	 *
	 * The scheduling check runs on every request rather than only on activation.
	 * A site that updates the plugin in place never re-runs the activation hook,
	 * and on such a site renewal would otherwise never be triggered at all —
	 * silently, because everything else about the plugin keeps working.
	 *
	 * @return void
	 */
	public static function load_hooks() {
		add_action( 'frm_chip_renewals', array( __CLASS__, 'run' ) );

		self::maybe_schedule();
	}

	/**
	 * Schedule the renewal check if it is not already scheduled.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( 'frm_chip_renewals' ) ) {
			// Twice daily: a due date is a day, and the check is idempotent, so
			// running more often than daily only shortens the wait to the first
			// attempt of the day.
			wp_schedule_event( time(), 'twicedaily', 'frm_chip_renewals' );
		}
	}

	/**
	 * Clear the schedule, used on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( 'frm_chip_renewals' );
	}

	/**
	 * Charge every subscription that is due.
	 *
	 * @return array Summary of what happened, for the log and for tests.
	 */
	public static function run() {
		$settings = FrmChipSettings::get_settings();

		if ( ! $settings->is_configured() ) {
			return array(
				'checked' => 0,
				'charged' => 0,
				'failed'  => 0,
				'skipped' => 0,
			);
		}

		$summary = array(
			'checked' => 0,
			'charged' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		foreach ( self::get_due_subscriptions() as $subscription ) {
			++$summary['checked'];

			$result = self::charge( $subscription );

			if ( 'charged' === $result ) {
				++$summary['charged'];
			} elseif ( 'failed' === $result ) {
				++$summary['failed'];
			} else {
				++$summary['skipped'];
			}
		}

		return $summary;
	}

	/**
	 * Subscriptions that are active and due for a charge.
	 *
	 * Only CHIP subscriptions, only active ones, and only those whose next bill
	 * date has arrived. `future_cancel` is excluded: the payer has cancelled and
	 * is being honoured to the end of the paid period.
	 *
	 * @return array
	 */
	public static function get_due_subscriptions() {
		global $wpdb;

		$today = gmdate( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'frm_subscriptions
				 WHERE paysys = %s AND status = %s AND next_bill_date IS NOT NULL
				   AND next_bill_date != %s AND next_bill_date <= %s
				 ORDER BY next_bill_date ASC',
				FrmChipHooksController::GATEWAY,
				'active',
				'0000-00-00',
				$today
			)
		);

		return $rows ? $rows : array();
	}

	/**
	 * Charge one subscription for its next period.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string charged|failed|skipped.
	 */
	public static function charge( $subscription ) {
		$api = FrmChipAppController::api();

		if ( is_wp_error( $api ) ) {
			FrmChipHelper::log( 'Renewal skipped: API unavailable', $api->get_error_message() );

			return 'skipped';
		}

		$token = (string) $subscription->sub_id;

		if ( '' === $token ) {
			FrmChipHelper::log( 'Renewal has no token', $subscription->id );

			return 'skipped';
		}

		// A charge takes seconds, and the merchant's "Retry now" can be clicked
		// again while the first one is still in flight. Without this, each click
		// issues a separate charge against the payer's card. The same lock the
		// settlement uses keeps a concurrent cron run out too.
		if ( ! self::acquire_charge_lock( $subscription ) ) {
			FrmChipHelper::log( 'Renewal already in progress', $subscription->id );

			return 'skipped';
		}

		try {
			return self::charge_locked( $api, $subscription, $token, FrmChipSettings::get_settings() );
		} finally {
			self::release_charge_lock( $subscription );
		}
	}

	/**
	 * Whether a subscription may be attempted right now.
	 *
	 * Enforces two limits:
	 *
	 * 1. The card network cap. Visa and Mastercard both allow 15 charge attempts
	 *    per card per 30 days and charge penalty fees above it, so attempts are
	 *    counted across all of a subscription's payments in the window.
	 * 2. A consecutive-failure ceiling, matching Stripe's 10, so a permanently
	 *    dead card is not hammered indefinitely.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return true|WP_Error True when allowed, otherwise the reason.
	 */
	public static function check_retry_allowed( $subscription ) {
		$meta = self::get_meta( $subscription );

		$consecutive = isset( $meta['chip_renewal_consecutive_failures'] )
			? (int) $meta['chip_renewal_consecutive_failures']
			: 0;

		if ( $consecutive >= self::MAX_CONSECUTIVE_ATTEMPTS ) {
			return new WP_Error(
				'chip_retry_limit_reached',
				sprintf(
					/* translators: %d: number of consecutive failed attempts. */
					__(
						'This card has failed %d times in a row. Update the card before trying again.',
						'chip-for-formidable-forms'
					),
					$consecutive
				)
			);
		}

		$recent = self::count_recent_attempts( $subscription );

		if ( $recent >= self::NETWORK_MAX_ATTEMPTS ) {
			/* translators: 1: charges already attempted, 2: window in days. */
			$message = __(
				'%1$d charges were already attempted on this card in the last %2$d days, the card network limit.',
				'chip-for-formidable-forms'
			);

			return new WP_Error(
				'chip_retry_network_limit',
				sprintf(
					/* translators: 1: charges already attempted, 2: window in days. */
					$message,
					$recent,
					self::NETWORK_WINDOW_DAYS
				)
			);
		}

		return true;
	}

	/**
	 * Count charge attempts recorded against a subscription inside the window.
	 *
	 * Counted from the recorded payments rather than a stored counter, so the
	 * figure cannot drift from what actually happened.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return int
	 */
	public static function count_recent_attempts( $subscription ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::NETWORK_WINDOW_DAYS . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'frm_payments
				 WHERE paysys = %s AND sub_id = %s AND created_at >= %s',
				FrmChipHooksController::GATEWAY,
				(string) $subscription->id,
				$since
			)
		);
	}

	/**
	 * How many attempts a subscription has left in the network window.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return int
	 */
	public static function remaining_attempts( $subscription ) {
		return max( 0, self::NETWORK_MAX_ATTEMPTS - self::count_recent_attempts( $subscription ) );
	}

	/**
	 * Charge a subscription on the merchant's instruction, ignoring the schedule.
	 *
	 * Distinct from the cron path: a merchant may want to charge a subscription
	 * that is not yet due, or one that has already been marked failed because
	 * the payer topped the card up late. The safety limits still apply, so this
	 * cannot be used to hammer a card.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string|WP_Error charged|failed|skipped, or the reason it may not run.
	 */
	public static function charge_manually( $subscription ) {
		$allowed = self::check_retry_allowed( $subscription );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		// A manual attempt is a fresh decision by the merchant, so a previously
		// failed subscription is put back into rotation before charging.
		if ( 'failed' === (string) $subscription->status ) {
			self::reactivate( $subscription );
		}

		return self::charge( $subscription );
	}

	/**
	 * Put a failed subscription back into rotation.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return void
	 */
	private static function reactivate( $subscription ) {
		$meta = self::get_meta( $subscription );

		unset( $meta['chip_renewal_reason'] );
		unset( $meta['chip_renewal_failed_at'] );

		self::update_subscription(
			$subscription,
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column on frm_subscriptions, not postmeta.
				'meta_value' => $meta,
				'status'     => 'active',
			)
		);
	}

	/**
	 * Take the per-subscription charge lock.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return bool
	 */
	private static function acquire_charge_lock( $subscription ) {
		global $wpdb;

		$lock = self::charge_lock_name( $subscription );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 0 ) );

		return '1' === (string) $acquired;
	}

	/**
	 * Release the per-subscription charge lock.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return void
	 */
	private static function release_charge_lock( $subscription ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::charge_lock_name( $subscription ) ) );
	}

	/**
	 * Lock name for a subscription's in-flight charge.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string
	 */
	private static function charge_lock_name( $subscription ) {
		return 'frm_chip_renewal_' . (int) $subscription->id;
	}

	/**
	 * Charge a subscription, already holding its lock.
	 *
	 * @param FrmChipApi      $api          API client.
	 * @param stdClass        $subscription Subscription row.
	 * @param string          $token        Recurring token.
	 * @param FrmChipSettings $settings     Plugin settings.
	 * @return string charged|failed|skipped.
	 */
	private static function charge_locked( $api, $subscription, $token, $settings ) {
		$amount = FrmChipHelper::to_minor_units( $subscription->amount );

		$purchase = self::create_renewal_purchase( $api, $subscription, $amount, $settings );

		if ( is_wp_error( $purchase ) ) {
			return self::handle_charge_failure( $subscription, $purchase );
		}

		$charged = $api->charge_purchase( $purchase['id'], $token );

		if ( is_wp_error( $charged ) ) {
			return self::handle_charge_failure( $subscription, $charged, $purchase['id'] );
		}

		// A charge can come back as pending_charge while the acquirer works; the
		// outcome then arrives by callback like any other purchase, so the
		// payment row is recorded as pending and settlement takes over.
		$status = isset( $charged['status'] ) ? (string) $charged['status'] : '';

		self::record_payment( $subscription, $purchase['id'], $amount, $settings );

		if ( 'paid' === $status ) {
			// The charge already succeeded, so advance the schedule now. The
			// callback will settle the payment row itself.
			self::advance_schedule( $subscription );
			FrmChipHelper::log(
				'Renewal charged',
				array(
					'sub'      => $subscription->id,
					'purchase' => $purchase['id'],
				)
			);

			return 'charged';
		}

		// pending_charge or similar: leave the date alone. Settlement advances it
		// once the charge is confirmed, so a failure further along is retried
		// instead of being skipped.
		FrmChipHelper::log(
			'Renewal pending',
			array(
				'sub'    => $subscription->id,
				'status' => $status,
			)
		);

		return 'charged';
	}

	/**
	 * Create the purchase that the token will be charged against.
	 *
	 * CHIP charges a *new* purchase using the token, rather than charging the
	 * original purchase again.
	 *
	 * @param FrmChipApi      $api          API client.
	 * @param stdClass        $subscription Subscription row.
	 * @param int             $amount       Amount in minor units.
	 * @param FrmChipSettings $settings     Plugin settings.
	 * @return array|WP_Error
	 */
	private static function create_renewal_purchase( $api, $subscription, $amount, $settings ) {
		$entry = FrmEntry::getOne( (int) $subscription->item_id, true );
		$form  = $entry ? FrmForm::getOne( $entry->form_id ) : null;

		$client = array();

		if ( $entry && $form ) {
			$action = self::get_action( $subscription, $form );

			if ( $action ) {
				$client = self::build_client( $action, $entry );
			}
		}

		// The entry may be gone (deleted, or an entry cleared by a retention
		// setting). CHIP requires a client object with an email, so fall back to
		// the merchant's own address rather than sending an empty one, which
		// serialises as a JSON list and is rejected outright.
		if ( empty( $client['email'] ) ) {
			$client['email'] = self::fallback_email();
		}

		if ( empty( $client['full_name'] ) ) {
			$client['full_name'] = FrmChipHelper::truncate( self::fallback_name( $subscription ), 128 );
		}

		$params = array(
			'brand_id'         => (string) $settings->get( 'brand_id' ),
			'creator_agent'    => 'Formidable Forms: ' . FRM_CHIP_MODULE_VERSION,
			'platform'         => 'formidableforms',
			'reference'        => FrmChipHelper::truncate( self::build_reference( $subscription ), 128 ),
			'send_receipt'     => false,
			'success_callback' => FrmChipActionsController::get_callback_url(),
			'client'           => $client,
			'purchase'         => array(
				'currency' => FrmChipHelper::CURRENCY,
				'timezone' => FrmChipHelper::get_timezone(),
				'products' => array(
					array(
						'name'     => FrmChipHelper::truncate( self::build_product_name( $subscription, $form ), 256 ),
						'price'    => (int) $amount,
						'quantity' => 1,
					),
				),
			),
		);

		/**
		 * Filter the payload for a renewal charge.
		 *
		 * @param array    $params       Purchase payload.
		 * @param stdClass $subscription Subscription row.
		 */
		$params = apply_filters( 'frm_chip_renewal_purchase_params', $params, $subscription );

		return $api->create_purchase( $params );
	}

	/**
	 * Decide what to do after a failed charge attempt.
	 *
	 * A hard decline fails the subscription at once. Anything else is treated as
	 * temporary and retried on the schedule until the attempts run out.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param WP_Error $error        Failure.
	 * @param string   $purchase_id  Purchase ID when one was created.
	 * @return string failed|skipped.
	 */
	private static function handle_charge_failure( $subscription, $error, $purchase_id = '' ) {
		$code = $error->get_error_code();
		$data = $error->get_error_data();
		$body = isset( $data['body'] ) ? $data['body'] : array();

		FrmChipHelper::log(
			'Renewal charge failed',
			array(
				'sub'     => $subscription->id,
				'code'    => $code,
				'message' => $error->get_error_message(),
			)
		);

		if ( self::is_hard_decline( $error, $body ) ) {
			// The card is unusable. Retrying cannot help, so stop and let the
			// merchant and payer deal with it.
			self::fail_subscription( $subscription, $error->get_error_message() );

			return 'failed';
		}

		$attempts = self::count_failures( $subscription ) + 1;

		self::record_failure( $subscription );

		if ( $attempts >= count( self::RETRY_OFFSETS ) ) {
			// Attempts exhausted: this is the point at which recovery is
			// considered to have failed.
			self::fail_subscription( $subscription, $error->get_error_message() );

			return 'failed';
		}

		self::schedule_retry( $subscription, $attempts );

		return 'skipped';
	}

	/**
	 * Whether a failure means the token itself is unusable.
	 *
	 * @param WP_Error $error Failure.
	 * @param array    $body  Decoded error body.
	 * @return bool
	 */
	private static function is_hard_decline( $error, $body ) {
		if ( in_array( $error->get_error_code(), self::HARD_DECLINE_CODES, true ) ) {
			return true;
		}

		$text = wp_json_encode( $body );

		foreach ( self::HARD_DECLINE_CODES as $hard ) {
			if ( false !== strpos( (string) $text, $hard ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How many failed attempts this subscription has already had.
	 *
	 * Stored on the subscription row's meta so it survives between cron runs.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return int
	 */
	private static function count_failures( $subscription ) {
		$meta = self::get_meta( $subscription );

		return isset( $meta['chip_renewal_failures'] ) ? (int) $meta['chip_renewal_failures'] : 0;
	}

	/**
	 * Read the plugin's own meta from a subscription row.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return array
	 */
	private static function get_meta( $subscription ) {
		if ( empty( $subscription->meta_value ) ) {
			return array();
		}

		$decoded = maybe_unserialize( $subscription->meta_value );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Record a failed attempt and push the next attempt forward.
	 *
	 * The bill date is moved to the next retry offset rather than left in the
	 * past, so the subscription is not picked up again on the next cron run.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return void
	 */
	private static function record_failure( $subscription ) {
		$meta = self::get_meta( $subscription );

		$meta['chip_renewal_failures']             = self::count_failures( $subscription ) + 1;
		$meta['chip_renewal_consecutive_failures'] = self::count_consecutive_failures( $subscription ) + 1;
		$meta['chip_last_failure']                 = current_time( 'mysql', 1 );

		self::update_subscription(
			$subscription,
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column on frm_subscriptions, not postmeta.
				'meta_value'     => $meta,
				'next_bill_date' => self::next_retry_date( self::count_failures( $subscription ) + 1 ),
			)
		);
	}

	/**
	 * Consecutive failed attempts, reset by any successful charge.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return int
	 */
	private static function count_consecutive_failures( $subscription ) {
		$meta = self::get_meta( $subscription );

		return isset( $meta['chip_renewal_consecutive_failures'] )
			? (int) $meta['chip_renewal_consecutive_failures']
			: 0;
	}

	/**
	 * Move the bill date to the next retry slot.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param int      $attempt      Which attempt just failed.
	 * @return void
	 */
	private static function schedule_retry( $subscription, $attempt ) {
		self::update_subscription(
			$subscription,
			array( 'next_bill_date' => self::next_retry_date( $attempt ) )
		);
	}

	/**
	 * The date of the next retry attempt.
	 *
	 * Offsets are measured from today rather than from the original due date so a
	 * late-running cron cannot collapse the schedule.
	 *
	 * @param int $attempt Attempt number, 1-based.
	 * @return string
	 */
	private static function next_retry_date( $attempt ) {
		$offset = isset( self::RETRY_OFFSETS[ $attempt ] )
			? self::RETRY_OFFSETS[ $attempt ]
			: self::end_of_retries_offset();

		return gmdate( 'Y-m-d', strtotime( '+' . $offset . ' days' ) );
	}

	/**
	 * Days of the last configured retry, used when attempts run out.
	 *
	 * @return int
	 */
	private static function end_of_retries_offset() {
		$offsets = self::RETRY_OFFSETS;

		return (int) end( $offsets );
	}

	/**
	 * Mark a subscription failed and tell the merchant why.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param string   $reason       Failure reason.
	 * @return void
	 */
	private static function fail_subscription( $subscription, $reason ) {
		$meta = self::get_meta( $subscription );

		$meta['chip_renewal_failed_at'] = current_time( 'mysql', 1 );
		$meta['chip_renewal_reason']    = $reason;

		self::update_subscription(
			$subscription,
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column on frm_subscriptions, not postmeta.
				'meta_value' => $meta,
				'status'     => 'failed',
			)
		);

		/**
		 * Fires when a CHIP subscription could not be renewed.
		 *
		 * A site can use this to email the merchant or notify the payer. It is
		 * deliberately an action rather than built-in mail so the site keeps
		 * control of its own communications.
		 *
		 * @param stdClass $subscription Subscription row.
		 * @param string   $reason       Why renewal stopped.
		 */
		do_action( 'frm_chip_subscription_failed', $subscription, $reason );

		FrmChipHelper::log(
			'Subscription failed',
			array(
				'sub'    => $subscription->id,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Advance a subscription to its next billing period.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return void
	 */
	public static function advance_schedule( $subscription ) {
		$interval = in_array( $subscription->time_interval, array( 'day', 'week', 'month', 'year' ), true )
			? $subscription->time_interval
			: 'month';

		$count = max( 1, (int) $subscription->interval_count );

		// Anchor on the existing date when it is in the future, so a charge that
		// lands early does not drift the schedule forward.
		$base = gmdate( 'Y-m-d', strtotime( '+' . $count . ' ' . $interval . 's' ) );

		$meta = self::get_meta( $subscription );

		// A successful charge clears the failure counters.
		unset( $meta['chip_renewal_failures'] );
		unset( $meta['chip_renewal_consecutive_failures'] );
		unset( $meta['chip_last_failure'] );

		self::update_subscription(
			$subscription,
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column on frm_subscriptions, not postmeta.
				'meta_value'     => $meta,
				'next_bill_date' => $base,
			)
		);
	}

	/**
	 * Record the payment row for a renewal charge.
	 *
	 * @param stdClass        $subscription Subscription row.
	 * @param string          $purchase_id  CHIP purchase ID.
	 * @param int             $amount       Amount in minor units.
	 * @param FrmChipSettings $settings     Plugin settings.
	 * @return void
	 */
	private static function record_payment( $subscription, $purchase_id, $amount, $settings ) {
		$payment = new FrmTransLitePayment();

		$payment->create(
			array(
				'paysys'     => FrmChipHooksController::GATEWAY,
				'amount'     => FrmChipHelper::from_minor_units( $amount ),
				'status'     => 'pending',
				'item_id'    => (int) $subscription->item_id,
				'action_id'  => (int) $subscription->action_id,
				'receipt_id' => (string) $purchase_id,
				'sub_id'     => (string) $subscription->id,
				'test'       => $settings->is_test_mode() ? 1 : 0,
			)
		);
	}

	/**
	 * Load the payment action behind a subscription.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param stdClass $form         Form.
	 * @return WP_Post|null
	 */
	private static function get_action( $subscription, $form ) {
		$form_actions = FrmFormAction::get_action_for_form( $form->id, 'payment' );

		foreach ( (array) $form_actions as $action ) {
			if ( (int) $action->ID === (int) $subscription->action_id ) {
				return $action;
			}
		}

		return null;
	}

	/**
	 * The stable link a payer is emailed, and where a card update begins.
	 *
	 * It does not create a checkout by itself: a checkout is single-use, so
	 * creating one here would waste it the moment the mail is sent and a payer
	 * returning later would land on an already-consumed purchase. The return
	 * handler opens a fresh checkout on each visit instead.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string
	 */
	public static function card_update_url( $subscription ) {
		return add_query_arg(
			array(
				'frmchip_card' => (int) $subscription->id,
				'frmchip_key'  => self::card_update_key( $subscription ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The purchase a card update link last created, if any.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string
	 */
	public static function get_card_update_purchase( $subscription ) {
		$meta = self::get_meta( $subscription );

		return isset( $meta['chip_card_update_purchase'] )
			? sanitize_text_field( (string) $meta['chip_card_update_purchase'] )
			: '';
	}

	/**
	 * Whether a card update checkout has actually been completed.
	 *
	 * Asked of CHIP rather than inferred from the return URL, because CHIP only
	 * echoes back the parameters set at creation, and the purchase does not
	 * exist until after it is created.
	 *
	 * @param string $purchase_id Purchase ID.
	 * @return bool
	 */
	public static function card_update_is_complete( $purchase_id ) {
		$api = FrmChipAppController::api();

		if ( is_wp_error( $api ) ) {
			return false;
		}

		$purchase = $api->get_purchase( $purchase_id );

		if ( is_wp_error( $purchase ) ) {
			return false;
		}

		$status = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';

		// skip_capture means an authorised card comes back preauthorized.
		return in_array( $status, array( 'paid', 'preauthorized' ), true );
	}

	/**
	 * Create a checkout the payer can use to hand over a new card.
	 *
	 * Used when a card dies (expired or cancelled). The token cannot be fixed,
	 * so the only way to keep the subscription alive is a fresh card. The
	 * purchase is zero-value with `skip_capture`, which CHIP documents as
	 * saving a card without a financial transaction.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string|WP_Error Checkout URL, or the reason it could not be made.
	 */
	public static function create_card_update_link( $subscription ) {
		$settings = FrmChipSettings::get_settings();

		if ( ! $settings->is_configured() ) {
			return new WP_Error(
				'chip_not_configured',
				__( 'CHIP is not configured, so a card update link cannot be created.', 'chip-for-formidable-forms' )
			);
		}

		$api = FrmChipAppController::api();

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$entry  = FrmEntry::getOne( (int) $subscription->item_id, true );
		$form   = $entry ? FrmForm::getOne( $entry->form_id ) : null;
		$action = ( $entry && $form ) ? self::get_action( $subscription, $form ) : null;

		$client = array();

		if ( $action && $entry ) {
			$client = self::build_client( $action, $entry );
		}

		if ( empty( $client['email'] ) ) {
			$client['email'] = self::fallback_email();
		}

		if ( empty( $client['full_name'] ) ) {
			$client['full_name'] = FrmChipHelper::truncate( self::fallback_name( $subscription ), 128 );
		}

		$url = add_query_arg(
			array(
				'frmchip_card' => (int) $subscription->id,
				'frmchip_key'  => self::card_update_key( $subscription ),
			),
			home_url( '/' )
		);

		// The redirect must carry the purchase, because the return leg is what
		// verifies and stores the new card. The purchase does not exist until
		// after creation, so the URL is rebuilt with it.
		$params = array(
			'brand_id'                 => (string) $settings->get( 'brand_id' ),
			'creator_agent'            => 'Formidable Forms: ' . FRM_CHIP_MODULE_VERSION,
			'platform'                 => 'formidableforms',
			'reference'                => FrmChipHelper::truncate( 'card-update-' . (int) $subscription->id, 128 ),
			'send_receipt'             => false,
			'force_recurring'          => true,
			'skip_capture'             => true,
			'payment_method_whitelist' => FrmChipPaymentMethods::CARD_GROUP,
			'client'                   => $client,
			'purchase'                 => array(
				'currency' => FrmChipHelper::CURRENCY,
				'timezone' => FrmChipHelper::get_timezone(),
				'products' => array(
					array(
						'name'     => FrmChipHelper::truncate(
							__( 'Update payment card', 'chip-for-formidable-forms' ),
							256
						),
						'price'    => 0,
						'quantity' => 1,
					),
				),
			),
		);

		/**
		 * Filter the payload for a payer's card update checkout.
		 *
		 * @param array    $params       Purchase payload.
		 * @param stdClass $subscription Subscription row.
		 */
		$params = apply_filters( 'frm_chip_card_update_params', $params, $subscription );

		// CHIP requires redirects at creation, so the purchase is created before
		// the URL can name it. Create it first with no redirect, then... — CHIP
		// does not allow updating redirects, so the purchase is created once with
		// a redirect that carries a reference we control: the subscription and
		// its key. The return handler then looks the purchase up from CHIP.
		$params['success_redirect'] = $url;
		$params['failure_redirect'] = $url;
		$params['cancel_redirect']  = $url;

		$purchase = $api->create_purchase( $params );

		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}

		// Remember which subscription this checkout belongs to, so the return
		// handler knows what to re-tokenise.
		$meta = self::get_meta( $subscription );

		$meta['chip_card_update_purchase'] = (string) $purchase['id'];

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column on frm_subscriptions, not postmeta.
		self::update_subscription( $subscription, array( 'meta_value' => $meta ) );

		if ( empty( $purchase['checkout_url'] ) ) {
			return new WP_Error(
				'chip_no_checkout_url',
				__( 'CHIP did not return a checkout URL.', 'chip-for-formidable-forms' )
			);
		}

		return (string) $purchase['checkout_url'];
	}

	/**
	 * Key that authorises a card update, without needing the payer to log in.
	 *
	 * Derived from the site's own secret so it cannot be guessed, and stable so
	 * the same link keeps working while the card is still broken.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string
	 */
	public static function card_update_key( $subscription ) {
		return substr(
			wp_hash( 'frm_chip_card_update|' . (int) $subscription->id . '|' . (string) $subscription->sub_id ),
			0,
			32
		);
	}

	/**
	 * Whether a card update request is authorised.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param string   $key          Supplied key.
	 * @return bool
	 */
	public static function verify_card_update_key( $subscription, $key ) {
		$expected = self::card_update_key( $subscription );

		return is_string( $key ) && hash_equals( $expected, $key );
	}

	/**
	 * The subscription a card update request refers to.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $key             Supplied key.
	 * @return stdClass|WP_Error
	 */
	public static function resolve_card_update_request( $subscription_id, $key ) {
		$subscriptions = new FrmTransLiteSubscription();
		$subscription  = $subscriptions->get_one( (int) $subscription_id );

		if ( ! $subscription || FrmChipHooksController::GATEWAY !== $subscription->paysys ) {
			return new WP_Error(
				'chip_subscription_not_found',
				__( 'That subscription could not be found.', 'chip-for-formidable-forms' )
			);
		}

		if ( ! self::verify_card_update_key( $subscription, $key ) ) {
			return new WP_Error(
				'chip_invalid_key',
				__( 'This card update link is not valid.', 'chip-for-formidable-forms' )
			);
		}

		return $subscription;
	}

	/**
	 * Replace a subscription's token after the payer supplied a new card.
	 *
	 * The stored token is the purchase that holds the card, so the new purchase
	 * becomes the subscription's token and the old one is discarded.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param string   $purchase_id  Purchase that captured the new card.
	 * @return true|WP_Error
	 */
	public static function replace_token( $subscription, $purchase_id ) {
		$api = FrmChipAppController::api();

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$purchase = $api->get_purchase( $purchase_id );

		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}

		if ( empty( $purchase['is_recurring_token'] ) ) {
			return new WP_Error(
				'chip_no_token',
				__( 'That card could not be stored. Please try again.', 'chip-for-formidable-forms' )
			);
		}

		$previous = (string) $subscription->sub_id;

		$meta = self::get_meta( $subscription );

		unset( $meta['chip_card_update_purchase'] );
		unset( $meta['chip_renewal_reason'] );
		unset( $meta['chip_renewal_failed_at'] );
		unset( $meta['chip_renewal_failures'] );
		unset( $meta['chip_renewal_consecutive_failures'] );

		self::update_subscription(
			$subscription,
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column on frm_subscriptions, not postmeta.
				'meta_value' => $meta,
				// A card update is also the payer's intent to continue.
				'status'     => 'active',
				'sub_id'     => (string) $purchase_id,
			)
		);

		// Release the old token so the dead card cannot be charged again.
		if ( '' !== $previous && $previous !== (string) $purchase_id ) {
			$api->delete_recurring_token( $previous );
		}

		/**
		 * Fires when a payer has supplied a new card for a subscription.
		 *
		 * @param stdClass $subscription Subscription row.
		 * @param string   $purchase_id  Purchase holding the new token.
		 */
		do_action( 'frm_chip_subscription_card_updated', $subscription, $purchase_id );

		FrmChipHelper::log( 'Subscription card updated', array( 'sub' => $subscription->id ) );

		return true;
	}

	/**
	 * Email to use when the original entry's client fields are unavailable.
	 *
	 * The admin address is the safest choice: it is always valid, and a renewal
	 * receipt going to the merchant is better than a charge failing outright.
	 *
	 * @return string
	 */
	private static function fallback_email() {
		$email = get_option( 'admin_email' );

		if ( ! is_email( $email ) ) {
			// Last resort: the site domain, which at least parses as an address.
			$host  = wp_parse_url( home_url(), PHP_URL_HOST );
			$email = 'noreply@' . ( $host ? $host : 'localhost' );
		}

		return FrmChipHelper::truncate( $email, 128 );
	}

	/**
	 * Name to use when the original entry's name fields are unavailable.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string
	 */
	private static function fallback_name( $subscription ) {
		$site = get_bloginfo( 'name' );

		if ( $site ) {
			return $site;
		}

		return 'Subscription #' . (int) $subscription->id;
	}

	/**
	 * Reuse the mapped client fields on a renewal, when the entry is still there.
	 *
	 * @param WP_Post  $action Payment action.
	 * @param stdClass $entry  Entry.
	 * @return array
	 */
	private static function build_client( $action, $entry ) {
		$content = $action->post_content;

		$first = isset( $content['chip_billing_first_name'] )
			? FrmChipHelper::get_entry_value( $content['chip_billing_first_name'], $entry )
			: '';
		$last  = isset( $content['chip_billing_last_name'] )
			? FrmChipHelper::get_entry_value( $content['chip_billing_last_name'], $entry )
			: '';

		$full_name = trim( $first . ' ' . $last );

		$email = isset( $content['chip_billing_email'] )
			? FrmChipHelper::get_entry_value( $content['chip_billing_email'], $entry )
			: '';

		$client = array(
			'email'     => FrmChipHelper::truncate( $email, 128 ),
			'full_name' => FrmChipHelper::truncate( $full_name, 128 ),
		);

		$phone = isset( $content['chip_billing_phone'] )
			? FrmChipHelper::get_entry_value( $content['chip_billing_phone'], $entry )
			: '';

		if ( '' !== $phone ) {
			$client['phone'] = FrmChipHelper::truncate( $phone, 32 );
		}

		return $client;
	}

	/**
	 * Reference for a renewal charge.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string
	 */
	private static function build_reference( $subscription ) {
		return 'formidable-renewal-' . (int) $subscription->id . '-' . gmdate( 'Ymd' );
	}

	/**
	 * Product name shown on the renewal purchase.
	 *
	 * @param stdClass      $subscription Subscription row.
	 * @param stdClass|null $form        Form.
	 * @return string
	 */
	private static function build_product_name( $subscription, $form ) {
		if ( $form && ! empty( $form->name ) ) {
			return $form->name;
		}

		return __( 'Subscription renewal', 'chip-for-formidable-forms' );
	}

	/**
	 * Persist changes to a subscription row.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @param array    $data         Columns to write.
	 * @return void
	 */
	private static function update_subscription( $subscription, $data ) {
		$subscriptions = new FrmTransLiteSubscription();
		$subscriptions->update( $subscription->id, $data );

		// Keep the in-memory row in step so a second call in the same request
		// does not act on stale values.
		foreach ( $data as $key => $value ) {
			if ( 'meta_value' === $key ) {
				$subscription->meta_value = maybe_serialize( $value );
				continue;
			}

			$subscription->{$key} = $value;
		}
	}
}

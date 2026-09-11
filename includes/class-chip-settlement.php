<?php
/**
 * Payment settlement.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Turns a CHIP purchase state into a Formidable payment state.
 *
 * CHIP uses a hosted checkout, so nothing settles during the request that starts
 * the payment. The outcome arrives twice: once as a browser redirect when the
 * payer returns, and once as a signed server callback. Both paths land here, and
 * the work is idempotent so whichever arrives first wins and the other is a
 * no-op.
 */
class FrmChipSettlement {

	/**
	 * Map a CHIP purchase status onto a Formidable payment status.
	 *
	 * Formidable understands: pending, processing, authorized, complete, failed,
	 * refunded and canceled.
	 *
	 * @param string $status CHIP purchase status.
	 * @return string
	 */
	public static function map_status( $status ) {
		$map = array(
			'paid'            => 'complete',
			'cleared'         => 'complete',
			'settled'         => 'complete',
			'hold'            => 'authorized',
			'preauthorized'   => 'authorized',
			'refunded'        => 'refunded',
			'pending_refund'  => 'processing',
			'pending_capture' => 'processing',
			'pending_release' => 'processing',
			'pending_charge'  => 'processing',
			'pending_execute' => 'processing',
			'cancelled'       => 'canceled',
			'released'        => 'canceled',
			'error'           => 'failed',
			'expired'         => 'failed',
			'overdue'         => 'failed',
			'blocked'         => 'failed',
			'chargeback'      => 'failed',
			'created'         => 'pending',
			'sent'            => 'pending',
			'viewed'          => 'pending',
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : 'pending';
	}

	/**
	 * Whether a status means the payer's money was taken.
	 *
	 * @param string $status CHIP purchase status.
	 * @return bool
	 */
	public static function is_paid( $status ) {
		return in_array( $status, array( 'paid', 'cleared', 'settled' ), true );
	}

	/**
	 * Whether a status is final and unsuccessful.
	 *
	 * @param string $status CHIP purchase status.
	 * @return bool
	 */
	public static function is_failed( $status ) {
		$failed = array( 'error', 'expired', 'overdue', 'blocked', 'chargeback', 'cancelled', 'released' );

		return in_array( $status, $failed, true );
	}

	/**
	 * Apply a CHIP purchase to the payment record it belongs to.
	 *
	 * @param array    $purchase Decoded CHIP purchase.
	 * @param stdClass $payment  Formidable payment row.
	 * @return bool True when the payment reached a new state.
	 */
	public static function apply( $purchase, $payment ) {
		if ( empty( $purchase['status'] ) ) {
			return false;
		}

		$chip_status = (string) $purchase['status'];
		$new_status  = self::map_status( $chip_status );

		// Guard against settling a payment from a different purchase.
		if ( isset( $purchase['id'] ) && (string) $purchase['id'] !== (string) $payment->receipt_id ) {
			return false;
		}

		$payment_row = new FrmTransLitePayment();

		// Record the raw CHIP payload so the payment screen shows the history.
		$payment_row->update(
			$payment->id,
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- writing meta, not querying it.
				'meta_value' => array(
					'chip_status'   => $chip_status,
					'chip_purchase' => self::summarise( $purchase ),
				),
			)
		);

		if ( self::is_paid( $chip_status ) ) {
			self::settle_subscription( $purchase, $payment );
		}

		if ( $new_status === $payment->status ) {
			// Nothing changed, so no triggers should run again.
			return false;
		}

		// change_payment_status persists the status and fires the payment status
		// triggers, which is what lets merchant emails react to the outcome.
		FrmTransLitePaymentsController::change_payment_status( $payment, $new_status );

		return true;
	}

	/**
	 * Reduce a purchase to the fields worth storing on the payment row.
	 *
	 * @param array $purchase Decoded CHIP purchase.
	 * @return array
	 */
	private static function summarise( $purchase ) {
		$transaction = isset( $purchase['transaction_data'] ) && is_array( $purchase['transaction_data'] )
			? $purchase['transaction_data']
			: array();

		return array(
			'id'             => isset( $purchase['id'] ) ? $purchase['id'] : '',
			'status'         => isset( $purchase['status'] ) ? $purchase['status'] : '',
			'payment_method' => isset( $transaction['payment_method'] ) ? $transaction['payment_method'] : '',
			'total'          => isset( $purchase['purchase']['total'] ) ? $purchase['purchase']['total'] : '',
			'currency'       => isset( $purchase['purchase']['currency'] ) ? $purchase['purchase']['currency'] : '',
			'is_test'        => ! empty( $purchase['is_test'] ),
			'reference'      => isset( $purchase['reference'] ) ? $purchase['reference'] : '',
			'updated_at'     => current_time( 'mysql', 1 ),
		);
	}

	/**
	 * Activate the subscription attached to a paid purchase.
	 *
	 * CHIP stores the reusable token on the purchase that was actually paid, so
	 * a successful first payment is what makes the subscription live.
	 *
	 * @param array    $purchase Decoded CHIP purchase.
	 * @param stdClass $payment  Formidable payment row.
	 * @return void
	 */
	private static function settle_subscription( $purchase, $payment ) {
		if ( empty( $payment->sub_id ) ) {
			return;
		}

		$subscriptions = new FrmTransLiteSubscription();
		$subscription  = $subscriptions->get_one( $payment->sub_id );

		if ( ! $subscription || 'active' === $subscription->status ) {
			return;
		}

		$updates = array( 'status' => 'active' );

		// Anchor the next charge to when this payment's access period ends.
		if ( ! empty( $payment->expire_date ) && '0000-00-00' !== $payment->expire_date ) {
			$updates['next_bill_date'] = $payment->expire_date;
		}

		$subscriptions->update( $subscription->id, $updates );
	}

	/**
	 * Find the payment row for a CHIP purchase ID.
	 *
	 * @param string $purchase_id CHIP purchase ID.
	 * @return stdClass|null
	 */
	public static function get_payment_by_purchase( $purchase_id ) {
		global $wpdb;

		$purchase_id = sanitize_text_field( (string) $purchase_id );

		if ( '' === $purchase_id ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'frm_payments WHERE receipt_id = %s ORDER BY created_at DESC',
				$purchase_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * How long to wait for the per-payment lock, in seconds.
	 *
	 * @var int
	 */
	const LOCK_TIMEOUT = 15;

	/**
	 * Verify a purchase with CHIP and settle it.
	 *
	 * Used by both the return handler and the callback handler. The server side
	 * lookup is what makes the outcome trustworthy: a payer could otherwise
	 * hand-craft a return URL.
	 *
	 * The whole settle runs under a per-payment lock. The two paths can arrive
	 * at the same moment — a payer returning while a callback is still being
	 * processed, or CHIP redelivering a callback it thinks failed — and without
	 * the lock both would read the same starting status and both would apply the
	 * outcome, firing the merchant's payment triggers twice.
	 *
	 * @param string     $purchase_id      CHIP purchase ID.
	 * @param array|null $trusted_purchase Purchase already verified by signature,
	 *                                     or null to fetch it from the API.
	 * @return array|WP_Error {
	 *     @type stdClass $payment  Payment row.
	 *     @type array    $purchase Decoded CHIP purchase.
	 *     @type bool     $settled  Whether the status changed.
	 * }
	 */
	public static function verify_and_settle( $purchase_id, $trusted_purchase = null ) {
		$purchase = $trusted_purchase;

		if ( null === $purchase ) {
			$api = FrmChipAppController::api();

			if ( is_wp_error( $api ) ) {
				return $api;
			}

			$purchase = $api->get_purchase( $purchase_id );

			if ( is_wp_error( $purchase ) ) {
				return $purchase;
			}

			if ( empty( $purchase['id'] ) ) {
				return new WP_Error(
					'chip_purchase_not_found',
					__( 'CHIP could not find that payment.', 'chip-for-formidable-forms' )
				);
			}
		}

		$lock = self::lock_name( $purchase_id );
		self::acquire_lock( $lock );

		try {
			// Re-read under the lock: a concurrent delivery may have settled it
			// already, in which case apply() sees no change and does nothing.
			$payment = self::get_payment_by_purchase( $purchase_id );

			if ( ! $payment ) {
				return new WP_Error(
					'chip_payment_not_found',
					__( 'That payment does not match a payment recorded on this site.', 'chip-for-formidable-forms' )
				);
			}

			$settled = self::apply( $purchase, $payment );

			return array(
				'payment'  => $payment,
				'purchase' => $purchase,
				'settled'  => $settled,
			);
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Build the advisory lock name for a purchase.
	 *
	 * MySQL caps lock names at 64 characters, so the purchase ID is reduced to
	 * characters that are safe in a lock name and truncated.
	 *
	 * @param string $purchase_id CHIP purchase ID.
	 * @return string
	 */
	private static function lock_name( $purchase_id ) {
		$safe = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $purchase_id );

		return 'frm_chip_payment_' . substr( (string) $safe, 0, 40 );
	}

	/**
	 * Take the MySQL advisory lock for a payment.
	 *
	 * The lock is held per database connection, so it serialises the browser
	 * return and the server callback even though they are separate requests.
	 *
	 * @param string $lock Lock name.
	 * @return void
	 */
	private static function acquire_lock( $lock ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->get_results( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) );
	}

	/**
	 * Release the MySQL advisory lock for a payment.
	 *
	 * @param string $lock Lock name.
	 * @return void
	 */
	private static function release_lock( $lock ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->get_results( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

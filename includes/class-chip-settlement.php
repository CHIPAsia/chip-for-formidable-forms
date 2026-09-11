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
	 * Verify a purchase with CHIP and settle it.
	 *
	 * Used by both the return handler and the callback handler. The server side
	 * lookup is what makes the outcome trustworthy: a payer could otherwise
	 * hand-craft a return URL.
	 *
	 * @param string $purchase_id CHIP purchase ID.
	 * @return array|WP_Error {
	 *     @type stdClass $payment  Payment row.
	 *     @type array    $purchase Decoded CHIP purchase.
	 *     @type bool     $settled  Whether the status changed.
	 * }
	 */
	public static function verify_and_settle( $purchase_id ) {
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
	}
}

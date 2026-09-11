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
	 * Register the subscription screen additions.
	 *
	 * @return void
	 */
	public static function load_hooks() {
		// Refund support on the payments screen.
		add_action( 'frm_pay_chip_sidebar', array( 'FrmChipPaymentsController', 'sidebar_actions' ) );

		// Core renders the subscription's status and billing cycle but has no
		// notion of a renewal the site has to issue, so there is no hook to add
		// rows to that table. The receipt filter is the one gateway-aware hook
		// core exposes on the screen, and the payments sidebar is the surface a
		// merchant actually works from, so the renewal state is shown in both
		// places rather than on a separate page of our own.
		add_filter( 'frm_sub_chip_receipt', array( __CLASS__, 'add_renewal_summary' ) );

		add_action( 'frm_pay_chip_sidebar', array( __CLASS__, 'sidebar_renewal_status' ), 5 );

		// Merchant-triggered renewal retry.
		add_action( 'wp_ajax_frm_chip_retry_renewal', array( __CLASS__, 'maybe_handle_retry' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
	}

	/**
	 * Load the renewal script on the payments screen.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function enqueue_script( $hook ) {
		if ( false === strpos( (string) $hook, 'formidable-payments' ) ) {
			return;
		}

		wp_enqueue_script(
			'frm-chip-subscriptions',
			FRM_CHIP_URL . 'assets/js/subscriptions.js',
			array(),
			FRM_CHIP_MODULE_VERSION,
			true
		);
	}

	/**
	 * Add the renewal state to the subscription screen's receipt line.
	 *
	 * @param string $link Receipt link markup.
	 * @return string
	 */
	public static function add_renewal_summary( $link ) {
		$subscription = self::get_current_subscription();

		if ( ! $subscription ) {
			return $link;
		}

		$summary = self::describe_renewal( $subscription );

		if ( '' === $summary ) {
			return $link;
		}

		return $link . '<br /><span class="frm_sub_label">' . esc_html( $summary ) . '</span>';
	}

	/**
	 * Show the next charge, and any failure, against the payment's subscription.
	 *
	 * Hooked before sidebar_actions so the renewal line sits above the refund
	 * and cancel links.
	 *
	 * @param stdClass $payment Payment row.
	 * @return void
	 */
	public static function sidebar_renewal_status( $payment ) {
		if ( empty( $payment->sub_id ) ) {
			return;
		}

		$subscriptions = new FrmTransLiteSubscription();
		$subscription  = $subscriptions->get_one( $payment->sub_id );

		if ( ! $subscription || FrmChipHooksController::GATEWAY !== $subscription->paysys ) {
			return;
		}

		$summary = self::describe_renewal( $subscription );

		if ( '' === $summary ) {
			return;
		}

		$can_retry = 'active' === (string) $subscription->status
			&& (int) $subscription->id > 0
			&& self::get_failures( $subscription ) > 0;

		?>
		<div class="misc-pub-section">
			<?php FrmAppHelper::icon_by_class( 'frmfont frm_calendar_icon' ); ?>
			<span class="frm_link_label">
				<?php echo esc_html( $summary ); ?>
			</span>
			<?php if ( $can_retry ) { ?>
				<a href="#"
					class="frm_chip_retry_renewal"
					data-sub="<?php echo absint( $subscription->id ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'frm_chip_retry_renewal' ) ); ?>"
					style="margin-left:6px;">
					<?php esc_html_e( 'Retry now', 'chip-for-formidable-forms' ); ?>
				</a>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * How many failed renewal attempts a subscription has had.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return int
	 */
	private static function get_failures( $subscription ) {
		$meta = self::get_meta( $subscription );

		return isset( $meta['chip_renewal_failures'] ) ? (int) $meta['chip_renewal_failures'] : 0;
	}

	/**
	 * Charge a subscription immediately, on the merchant's instruction.
	 *
	 * A retry that is already scheduled can otherwise make a merchant wait days
	 * to find out whether a card works again, which is the moment they most want
	 * to know.
	 *
	 * @return void
	 */
	public static function maybe_handle_retry() {
		$sub_id = isset( $_POST['sub'] ) ? absint( $_POST['sub'] ) : 0;

		if ( ! $sub_id ) {
			return;
		}

		$subscriptions = new FrmTransLiteSubscription();
		$subscription  = $subscriptions->get_one( $sub_id );

		if ( ! $subscription || FrmChipHooksController::GATEWAY !== $subscription->paysys ) {
			return;
		}

		check_ajax_referer( 'frm_chip_retry_renewal', 'nonce' );
		FrmAppHelper::permission_check( 'frm_edit_entries' );

		if ( 'active' !== (string) $subscription->status ) {
			self::respond( false, __( 'Only an active subscription can be retried.', 'chip-for-formidable-forms' ) );
		}

		$result = FrmChipRenewals::charge( $subscription );

		if ( 'charged' === $result ) {
			self::respond( true, __( 'Renewal charged.', 'chip-for-formidable-forms' ) );
		}

		if ( 'failed' === $result ) {
			// charge() records the reason, so read it back rather than guessing.
			$fresh = $subscriptions->get_one( $sub_id );
			$meta  = self::get_meta( $fresh );
			$why   = isset( $meta['chip_renewal_reason'] ) ? (string) $meta['chip_renewal_reason'] : '';

			self::respond(
				false,
				'' !== $why
					? $why
					: __( 'The subscription could not be renewed.', 'chip-for-formidable-forms' )
			);
		}

		self::respond(
			false,
			__( 'The renewal could not be attempted. Try again shortly.', 'chip-for-formidable-forms' )
		);
	}

	/**
	 * One line describing where a subscription stands with renewals.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return string
	 */
	private static function describe_renewal( $subscription ) {
		$meta = self::get_meta( $subscription );

		if ( 'failed' === (string) $subscription->status ) {
			$reason = isset( $meta['chip_renewal_reason'] ) ? (string) $meta['chip_renewal_reason'] : '';

			if ( '' !== $reason ) {
				/* translators: %s: reason the renewal failed. */
				return sprintf( __( 'Renewal failed: %s', 'chip-for-formidable-forms' ), $reason );
			}

			return __( 'Renewal failed. The card could not be charged.', 'chip-for-formidable-forms' );
		}

		if ( 'future_cancel' === (string) $subscription->status ) {
			return __( 'Cancelled. No further charges will be made.', 'chip-for-formidable-forms' );
		}

		if ( 'active' !== (string) $subscription->status ) {
			return '';
		}

		$failures = isset( $meta['chip_renewal_failures'] ) ? (int) $meta['chip_renewal_failures'] : 0;

		if ( $failures > 0 && ! empty( $subscription->next_bill_date ) ) {
			return sprintf(
				/* translators: 1: next retry date, 2: attempt number, 3: total attempts. */
				__( 'Retrying on %1$s (attempt %2$d of %3$d).', 'chip-for-formidable-forms' ),
				FrmAppHelper::get_localized_date( 'M j, Y', $subscription->next_bill_date ),
				$failures + 1,
				count( FrmChipRenewals::RETRY_OFFSETS )
			);
		}

		if ( ! empty( $subscription->next_bill_date ) ) {
			return sprintf(
				/* translators: %s: next billing date. */
				__( 'Next charge on %s.', 'chip-for-formidable-forms' ),
				FrmAppHelper::get_localized_date( 'M j, Y', $subscription->next_bill_date )
			);
		}

		return '';
	}

	/**
	 * The subscription currently being viewed, if any.
	 *
	 * @return stdClass|null
	 */
	private static function get_current_subscription() {
		if ( ! FrmAppHelper::is_admin_page( 'formidable-payments' ) ) {
			return null;
		}

		$id = FrmAppHelper::simple_get( 'id', 'absint' );

		if ( ! $id ) {
			return null;
		}

		$subscriptions = new FrmTransLiteSubscription();
		$subscription  = $subscriptions->get_one( $id );

		if ( ! $subscription || FrmChipHooksController::GATEWAY !== $subscription->paysys ) {
			return null;
		}

		return $subscription;
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

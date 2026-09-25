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
	 * Admin page slug for the CHIP subscriptions screen.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'formidable-chip-subscriptions';

	/**
	 * The capability that governs everything on the subscriptions screen.
	 *
	 * The page registers this for its menu, and every action reachable from it
	 * checks the same one — the page renderer, the row actions and the retry
	 * handler. One predicate instead of five spellings, so a control can never be
	 * offered to somebody the action behind it would then refuse. That is what
	 * happened when the page and its handlers named different capabilities: the
	 * screen rendered for a role that could not press a single button on it.
	 *
	 * frm_change_settings is the one Formidable grants to the roles that run a
	 * site's forms and payments, and it is what this plugin's own actions already
	 * required. The page is a management screen, not a read-only report, so it
	 * asks for the management capability rather than the view one.
	 *
	 * @var string
	 */
	const MANAGE_CAPABILITY = 'frm_change_settings';

	/**
	 * Whether the current user may manage CHIP subscriptions.
	 *
	 * The single decision point. FrmAppHelper::permission_check() also accepts a
	 * user carrying 'administrator' and prints Formidable's own "you are not
	 * allowed" message, so this stays consistent with the rest of the plugin.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::MANAGE_CAPABILITY ) || current_user_can( 'administrator' );
	}

	/**
	 * Whether the subscriptions screen is one of Formidable's "white pages".
	 *
	 * Formidable adds frm-white-body to the body class for the screens it styles
	 * itself, and that class is what carries the h1 sizing, the table margins and
	 * the white background. Our page is not in core's list, so it renders at
	 * WordPress defaults on a grey background — visibly a different product
	 * sitting inside wp-admin. frm_is_white_page is core's own filter for this.
	 *
	 * @param bool $is_white_page Whether core already treats this page as white.
	 * @return bool
	 */
	public static function is_white_page( $is_white_page ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$frm_chip_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::PAGE_SLUG === $frm_chip_page ) {
			return true;
		}

		return $is_white_page;
	}

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

		// The renewal list screen.
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 );
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

		// The refund-then-cancel choice on a payment's sidebar.
		wp_enqueue_script(
			'frm-chip-refund',
			FRM_CHIP_URL . 'assets/js/refund.js',
			array(),
			FRM_CHIP_MODULE_VERSION,
			true
		);

		wp_register_style( 'frm-chip-refund', false, array(), FRM_CHIP_MODULE_VERSION );
		wp_enqueue_style( 'frm-chip-refund' );
		wp_add_inline_style(
			'frm-chip-refund',
			'.frm-chip-refund-choice-actions { margin-top: 6px; }'
			. '.frm-chip-refund-choice-actions a { display: block; margin-bottom: 4px; }'
			. '.frm-chip-refund-choice-actions .description { display: block; }'
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

		/*
		 * Two distinct actions, because they mean different things.
		 *
		 * A RETRY collects money already owed — the charge failed, or the cron has
		 * not caught up with a date that has passed. It never moves the schedule.
		 *
		 * A RENEW NOW brings the next cycle forward. The payer is charged now for a
		 * period they have not been billed for, and the schedule moves up. Offering
		 * only "Retry now" on a healthy subscription hid that difference: pressing
		 * it took money early and silently shifted every future charge.
		 */
		$can_retry     = self::can_retry( $subscription );
		$can_renew_now = self::can_renew_now( $subscription );

		?>
		<div class="misc-pub-section">
			<?php FrmAppHelper::icon_by_class( 'frmfont frm_calendar_icon' ); ?>
			<span class="frm_link_label">
				<?php echo esc_html( $summary ); ?>
			</span>
			<?php if ( $can_retry || $can_renew_now ) { ?>
				<?php
				// A button, not a link: this performs an action on the current
				// page rather than navigating, and the label names the payer so a
				// screen reader does not announce the same label every time.
				$frm_chip_label = $summary
					? sprintf(
						/* translators: %s: the renewal summary, e.g. "Renews 19 Sep 2026". */
						__( 'Retry now. %s', 'chip-for-formidable-forms' ),
						$summary
					)
					: __( 'Retry now', 'chip-for-formidable-forms' );

				$frm_chip_failed = __( 'The retry could not be sent. Please try again.', 'chip-for-formidable-forms' );

				$frm_chip_action = $can_retry ? 'retry' : 'renew';
				$frm_chip_text   = $can_retry
					? __( 'Retry now', 'chip-for-formidable-forms' )
					: __( 'Renew now', 'chip-for-formidable-forms' );

				if ( ! $can_retry ) {
					$frm_chip_label = $summary
						? sprintf(
							/* translators: %s: the renewal summary, e.g. "Next charge on 13 Oct 2026". */
							__( 'Renew now, charging the next period early. %s', 'chip-for-formidable-forms' ),
							$summary
						)
						: __( 'Renew now, charging the next period early.', 'chip-for-formidable-forms' );
				}
				?>
				<button type="button"
					class="frm_chip_retry_renewal button button-small"
					data-sub="<?php echo absint( $subscription->id ); ?>"
					data-mode="<?php echo esc_attr( $frm_chip_action ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'frm_chip_retry_renewal' ) ); ?>"
					data-working="<?php echo esc_attr__( 'Working…', 'chip-for-formidable-forms' ); ?>"
					data-failed="<?php echo esc_attr( $frm_chip_failed ); ?>"
					aria-label="<?php echo esc_attr( $frm_chip_label ); ?>"
					style="margin-left:6px;">
					<?php echo esc_html( $frm_chip_text ); ?>
				</button>
				<?php
				// The AJAX result is inserted next to the button, which is silent
				// for a screen reader. Give it a region to announce into. The list
				// screen ships its own; this one is for the payments sidebar.
				?>
				<div class="frm_chip_live_region screen-reader-text" aria-live="polite" role="status"></div>
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
		FrmAppHelper::permission_check( self::MANAGE_CAPABILITY );

		// Which action was asked for. The button states it, but the server decides
		// what each one is allowed to do rather than trusting the form: a request
		// claiming "renew" on a subscription that is already past due is treated as
		// the retry it really is, so the schedule cannot be moved by dressing an
		// early renewal up as a retry.
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'retry';

		// A cancelled subscription should not be revived by either action; the payer
		// asked for it to stop.
		if ( in_array( (string) $subscription->status, array( 'future_cancel', 'canceled' ), true ) ) {
			self::respond(
				false,
				__(
					'This subscription has been cancelled, so it will not be charged again.',
					'chip-for-formidable-forms'
				)
			);
		}

		// A retry collects money already owed, so it is refused when nothing is
		// owed. This is the server's own check: hiding the button on a healthy
		// subscription is not enough on its own, because a request can be replayed.
		if ( 'retry' === $mode && ! self::can_retry( $subscription ) ) {
			self::respond(
				false,
				__(
					'Nothing is owed on this subscription yet. Use Renew now to charge the next period early.',
					'chip-for-formidable-forms'
				)
			);
		}

		// An early renewal is only for a healthy subscription. On a failed one the
		// intent is a retry, so it is treated as one.
		if ( 'renew' === $mode && self::can_retry( $subscription ) ) {
			$mode = 'retry';
		}

		$result = 'renew' === $mode
			? FrmChipRenewals::charge_early( $subscription )
			: FrmChipRenewals::charge_manually( $subscription );

		if ( is_wp_error( $result ) ) {
			self::respond( false, $result->get_error_message() );
		}

		if ( 'charged' === $result ) {
			self::respond(
				true,
				'renew' === $mode
					? __( 'Charged now. The next charge moves to the following period.', 'chip-for-formidable-forms' )
					: __( 'Renewal charged.', 'chip-for-formidable-forms' )
			);
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

		if ( 'skipped' === $result ) {
			self::respond(
				false,
				__(
					'A charge is already being processed for this subscription. Please wait a moment.',
					'chip-for-formidable-forms'
				)
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
			$next = sprintf(
				/* translators: %s: next billing date. */
				__( 'Next charge on %s.', 'chip-for-formidable-forms' ),
				FrmAppHelper::get_localized_date( 'M j, Y', $subscription->next_bill_date )
			);

			// A refunded payment leaves the subscription running, so the next
			// charge would take money back for a period already returned. Say so
			// here: nothing else on the screen distinguishes this subscription
			// from one that has never been refunded.
			if ( ! empty( $meta['chip_refunded_count'] ) ) {
				$count = (int) $meta['chip_refunded_count'];

				return sprintf(
					/* translators: 1: next billing date, 2: number of refunded payments. */
					_n(
						'%1$s %2$d payment on this subscription was refunded.',
						'%1$s %2$d payments on this subscription were refunded.',
						$count,
						'chip-for-formidable-forms'
					),
					$next,
					$count
				);
			}

			return $next;
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
	 * Public because the refund handler records against the same meta, and both
	 * must read and write it the same way.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return array
	 */
	public static function get_meta( $subscription ) {
		if ( empty( $subscription->meta_value ) ) {
			return array();
		}

		$decoded = maybe_unserialize( $subscription->meta_value );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Register the subscription list page.
	 *
	 * Core's payments screen lists subscriptions but offers no hook for adding a
	 * column or a row action, and it shows nothing about renewal — a merchant
	 * cannot tell which subscriptions are failing or retry one. Since the data
	 * and the action are both ours, they get their own screen rather than being
	 * bolted onto a table that has no seam for them.
	 *
	 * @return void
	 */
	public static function register_page() {
		$hook = add_submenu_page(
			'formidable',
			__( 'CHIP Subscriptions', 'chip-for-formidable-forms' ),
			__( 'CHIP Subscriptions', 'chip-for-formidable-forms' ),
			// Core registers its own payments screen with this capability, and a
			// role that may only look at subscriptions should keep doing so. It is
			// the controls, not the page, that must disappear for a user who
			// cannot act — see MANAGE_CAPABILITY.
			'frm_view_entries',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'handle_page_actions' ) );
		}
	}

	/**
	 * Handle a retry submitted from the list page.
	 *
	 * @return void
	 */
	public static function handle_page_actions() {
		// The nonces are verified immediately below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sub_id = isset( $_GET['frmchip_retry'] ) ? absint( $_GET['frmchip_retry'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$renew_id = isset( $_GET['frmchip_renew'] ) ? absint( $_GET['frmchip_renew'] ) : 0;

		$mode = '';

		if ( $sub_id ) {
			$mode = 'retry';
		} elseif ( $renew_id ) {
			$sub_id = $renew_id;
			$mode   = 'renew';
		}

		if ( ! $sub_id ) {
			return;
		}

		check_admin_referer( 'frm_chip_' . $mode . '_' . $sub_id );
		FrmAppHelper::permission_check( self::MANAGE_CAPABILITY );

		$subscriptions = new FrmTransLiteSubscription();
		$subscription  = $subscriptions->get_one( $sub_id );
		$notice        = array(
			'error',
			__( 'That subscription could not be found.', 'chip-for-formidable-forms' ),
		);

		if ( $subscription && FrmChipHooksController::GATEWAY === $subscription->paysys ) {
			if ( in_array( (string) $subscription->status, array( 'future_cancel', 'canceled' ), true ) ) {
				$notice = array(
					'error',
					__(
						'This subscription is cancelled, so it will not be charged again.',
						'chip-for-formidable-forms'
					),
				);
			} elseif ( 'retry' === $mode && ! self::can_retry( $subscription ) ) {
				// Nothing is owed, so a retry is not what the merchant wants.
				$notice = array(
					'error',
					__(
						'Nothing is owed on this subscription yet. Use Renew now to charge the next period early.',
						'chip-for-formidable-forms'
					),
				);
			} else {
				$result = 'renew' === $mode
					? FrmChipRenewals::charge_early( $subscription )
					: FrmChipRenewals::charge_manually( $subscription );

				if ( is_wp_error( $result ) ) {
					$notice = array( 'error', $result->get_error_message() );
				} elseif ( 'charged' === $result ) {
					$notice = array(
						'success',
						'renew' === $mode
							? __(
								'Charged now. The next charge moves to the following period.',
								'chip-for-formidable-forms'
							)
							: __( 'Renewal charged.', 'chip-for-formidable-forms' ),
					);
				} elseif ( 'failed' === $result ) {
					$fresh = $subscriptions->get_one( $sub_id );
					$meta  = self::get_meta( $fresh );
					$why   = isset( $meta['chip_renewal_reason'] ) ? (string) $meta['chip_renewal_reason'] : '';

					$notice = array(
						'error',
						'' !== $why
							? $why
							: __( 'The subscription could not be renewed.', 'chip-for-formidable-forms' ),
					);
				} else {
					$notice = array(
						'error',
						__( 'A charge is already being processed for this subscription.', 'chip-for-formidable-forms' ),
					);
				}
			}
		}

		// Redirect so a refresh does not charge again.
		$url = add_query_arg(
			array(
				'page'         => self::PAGE_SLUG,
				'frmchip_msg'  => $notice[0],
				'frmchip_text' => $notice[1],
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the CHIP subscriptions screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		FrmAppHelper::permission_check( 'frm_view_entries' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$frm_chip_page = isset( $_GET['frmchip_page'] ) ? max( 1, absint( $_GET['frmchip_page'] ) ) : 1;

		$rows = self::get_subscription_rows( $frm_chip_page );

		// The message is our own redirect output, shown back to the user, so it
		// carries no action and no nonce applies.
		$frm_chip_raw      = (string) FrmAppHelper::simple_get( 'frmchip_text', 'sanitize_text_field' );
		$frm_chip_msg_type = sanitize_key( (string) FrmAppHelper::simple_get( 'frmchip_msg' ) );

		$frm_chip_msg = $frm_chip_raw;

		$frm_chip_rows  = $rows;
		$frm_chip_total = self::count_subscriptions();
		$frm_chip_pages = max( 1, (int) ceil( $frm_chip_total / self::PER_PAGE ) );

		include FRM_CHIP_PATH . 'includes/views/subscriptions/list.php';
	}

	/**
	 * How many CHIP subscriptions exist, for the pager.
	 *
	 * @return int
	 */
	private static function count_subscriptions() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'frm_subscriptions WHERE paysys = %s',
				FrmChipHooksController::GATEWAY
			)
		);
	}

	/**
	 * How many subscriptions the list screen shows per page.
	 *
	 * A merchant accumulating subscriptions for years should not load every one
	 * of them, with an entry and form lookup each, in a single request.
	 *
	 * @var int
	 */
	const PER_PAGE = 50;

	/**
	 * Every CHIP subscription, with its renewal state.
	 *
	 * @param int $page 1-based page number.
	 * @return array
	 */
	public static function get_subscription_rows( $page = 1 ) {
		global $wpdb;

		$per_page = self::PER_PAGE;
		$offset   = max( 0, ( (int) $page - 1 ) * $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$subs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'frm_subscriptions
				 WHERE paysys = %s
				 ORDER BY
					CASE status
						WHEN %s THEN 0
						WHEN %s THEN 1
						ELSE 2
					END,
					next_bill_date ASC
				 LIMIT %d OFFSET %d',
				FrmChipHooksController::GATEWAY,
				'failed',
				'active',
				$per_page,
				$offset
			)
		);

		$rows = array();

		foreach ( (array) $subs as $sub ) {
			$entry = FrmEntry::getOne( (int) $sub->item_id, true );
			$meta  = self::get_meta( $sub );

			$rows[] = array(
				'subscription'  => $sub,
				'entry'         => $entry,
				'form'          => $entry ? FrmForm::getOne( $entry->form_id ) : null,
				'payer'         => $entry && isset( $entry->metas ) ? self::first_email( $entry ) : '',
				'state'         => self::describe_renewal( $sub ),
				'failures'      => isset( $meta['chip_renewal_failures'] ) ? (int) $meta['chip_renewal_failures'] : 0,
				'remaining'     => FrmChipRenewals::remaining_attempts( $sub ),
				'can_retry'     => self::can_retry( $sub ),
				'can_renew_now' => self::can_renew_now( $sub ),
			);
		}

		return $rows;
	}

	/**
	 * Whether a row should offer a "retry now" action.
	 *
	 * A retry is meaningful only when collection is stuck: the subscription is on
	 * hold after a failed attempt, or it is still marked active but its charge
	 * date has passed because the cron has not run yet. On a subscription that is
	 * comfortably inside its paid period a retry would take money for a period
	 * already paid for, so it is not offered — that is a different action.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return bool
	 */
	public static function can_retry( $subscription ) {
		if ( in_array( (string) $subscription->status, array( 'future_cancel', 'canceled' ), true ) ) {
			return false;
		}

		// A stored token is required, so the retry cannot fail for that reason.
		if ( '' === (string) $subscription->sub_id ) {
			return false;
		}

		if ( 'failed' === (string) $subscription->status ) {
			return true;
		}

		return self::is_past_due( $subscription );
	}

	/**
	 * Whether a row should offer to bring the next cycle forward.
	 *
	 * This is the honest counterpart to the retry: the subscription is healthy and
	 * not yet due, so charging now is a deliberate early renewal. It moves the
	 * schedule, which a retry never should.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return bool
	 */
	public static function can_renew_now( $subscription ) {
		if ( in_array( (string) $subscription->status, array( 'future_cancel', 'canceled' ), true ) ) {
			return false;
		}

		if ( '' === (string) $subscription->sub_id ) {
			return false;
		}

		return 'active' === (string) $subscription->status && ! self::is_past_due( $subscription );
	}

	/**
	 * Whether the charge date has arrived.
	 *
	 * A missing or zeroed date means there is nothing to collect, so it is not
	 * treated as due.
	 *
	 * @param stdClass $subscription Subscription row.
	 * @return bool
	 */
	private static function is_past_due( $subscription ) {
		$due = isset( $subscription->next_bill_date ) ? (string) $subscription->next_bill_date : '';

		if ( '' === $due || '0000-00-00' === $due ) {
			return false;
		}

		// Compared in UTC against the stored date, so the answer does not change
		// with the site's timezone or the hour of the day.
		return $due <= gmdate( 'Y-m-d' );
	}

	/**
	 * First email address found on an entry.
	 *
	 * @param stdClass $entry Entry.
	 * @return string
	 */
	private static function first_email( $entry ) {
		foreach ( (array) $entry->metas as $value ) {
			if ( is_email( $value ) ) {
				return (string) $value;
			}
		}

		return '';
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

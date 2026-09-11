<?php
/**
 * Payment action behaviour for the CHIP gateway.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Drives the CHIP side of Formidable's shared payment action.
 *
 * Formidable calls `trigger_gateway()` when a payment action using this gateway
 * runs. Because CHIP uses a hosted checkout, the gateway does not settle the
 * payment inline: it records a pending payment, creates the CHIP purchase and
 * sends the payer to the checkout URL. The payment is settled later by the
 * return handler or the callback handler, whichever arrives first.
 */
class FrmChipActionsController {

	/**
	 * Flag set while building the redirect so the success method is only
	 * overridden for the payment that actually needs it.
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * Register defaults for the shared payment action settings.
	 *
	 * @param array $defaults Action defaults.
	 * @return array
	 */
	public static function add_action_defaults( $defaults ) {
		$defaults['chip_billing_first_name'] = '';
		$defaults['chip_billing_last_name']  = '';
		$defaults['chip_billing_email']      = '';
		$defaults['chip_billing_phone']      = '';
		$defaults['chip_billing_address']    = '';
		$defaults['chip_product_name']       = '';
		$defaults['chip_reference']          = '';

		return $defaults;
	}

	/**
	 * Normalise CHIP settings when a payment action is saved.
	 *
	 * Runs after Formidable has resolved the gateway list, so the shared defaults
	 * and the per gateway defaults are both present.
	 *
	 * @param array $settings Action settings being saved.
	 * @param array $action   Action data, including menu_order (the form ID).
	 * @return array
	 */
	public static function before_save_settings( $settings, $action ) {
		$gateways = ! empty( $settings['gateway'] ) ? (array) $settings['gateway'] : array();

		if ( ! in_array( FrmChipHooksController::GATEWAY, $gateways, true ) ) {
			return $settings;
		}

		$field_keys = array(
			'chip_billing_first_name',
			'chip_billing_last_name',
			'chip_billing_email',
			'chip_billing_phone',
			'chip_billing_address',
			'chip_reference',
		);

		foreach ( $field_keys as $key ) {
			$settings[ $key ] = isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;
		}

		$settings['chip_payment_methods'] = self::sanitize_payment_method_mode( $settings );

		$settings['chip_whitelist'] = FrmChipPaymentMethods::sanitize_selection(
			isset( $settings['chip_whitelist'] ) ? $settings['chip_whitelist'] : array()
		);

		if ( isset( $settings['chip_product_name'] ) ) {
			$settings['chip_product_name'] = sanitize_text_field( $settings['chip_product_name'] );
		}

		// CHIP settles Malaysian merchants in MYR only.
		$settings['currency'] = strtolower( FrmChipHelper::CURRENCY );

		return $settings;
	}

	/**
	 * Run the CHIP payment for a form submission.
	 *
	 * @param WP_Post  $action Payment action.
	 * @param stdClass $entry  Entry.
	 * @param stdClass $form   Form.
	 * @return array Response consumed by Formidable.
	 */
	public static function trigger_gateway( $action, $entry, $form ) {
		$response = array(
			'success'      => false,
			'running'      => false,
			'run_triggers' => false,
			'show_errors'  => true,
		);

		$amount = FrmChipHelper::get_amount_in_minor_units( $action, $entry, $form );

		if ( $amount <= 0 ) {
			$response['error'] = __( 'Please specify an amount for the payment.', 'chip-for-formidable-forms' );
			return $response;
		}

		$api = FrmChipAppController::api();

		if ( is_wp_error( $api ) ) {
			$response['error'] = $api->get_error_message();
			return $response;
		}

		// Capture the submitting page before building the payload: the return
		// URLs are part of the payload, and an AJAX submit would otherwise
		// resolve them to admin-ajax.php.
		FrmChipHelper::store_referer( $entry->id );

		$params = self::build_purchase_params( $action, $entry, $form, $amount );

		if ( is_wp_error( $params ) ) {
			$response['error'] = $params->get_error_message();
			return $response;
		}

		$purchase = $api->create_purchase( $params );

		if ( is_wp_error( $purchase ) ) {
			$response['error'] = $purchase->get_error_message();
			return $response;
		}

		if ( empty( $purchase['id'] ) || empty( $purchase['checkout_url'] ) ) {
			$response['error'] = __(
				'CHIP did not return a checkout URL for this payment.',
				'chip-for-formidable-forms'
			);
			return $response;
		}

		$is_recurring = 'recurring' === $action->post_content['type'];

		$payment_id = self::record_pending_payment( $action, $entry, $purchase, $amount, $is_recurring );

		if ( is_wp_error( $payment_id ) ) {
			$response['error'] = $payment_id->get_error_message();
			return $response;
		}

		self::$pending[ (int) $entry->id ] = array(
			'action'       => $action,
			'purchase_id'  => (string) $purchase['id'],
			'checkout_url' => (string) $purchase['checkout_url'],
			'payment_id'   => (int) $payment_id,
			'is_recurring' => $is_recurring,
			'amount'       => (int) $amount,
		);

		// A hosted checkout cannot fail inline: the payer leaves the site and the
		// outcome arrives by return redirect or callback.
		$response['success']      = true;
		$response['running']      = true;
		$response['run_triggers'] = false;

		return $response;
	}

	/**
	 * Record the pending payment so the payments screen and the return handler
	 * both have something to work with.
	 *
	 * @param WP_Post  $action       Payment action.
	 * @param stdClass $entry        Entry.
	 * @param array    $purchase     CHIP purchase response.
	 * @param int      $amount       Amount in minor units.
	 * @param bool     $is_recurring Whether this is a recurring payment.
	 * @return int|WP_Error Payment row ID.
	 */
	private static function record_pending_payment( $action, $entry, $purchase, $amount, $is_recurring ) {
		$settings = FrmChipSettings::get_settings();

		$payment = new FrmTransLitePayment();

		$payment_id = $payment->create(
			array(
				'paysys'     => FrmChipHooksController::GATEWAY,
				'amount'     => FrmChipHelper::from_minor_units( $amount ),
				'status'     => 'pending',
				'item_id'    => (int) $entry->id,
				'action_id'  => (int) $action->ID,
				'receipt_id' => (string) $purchase['id'],
				'sub_id'     => '',
				'test'       => $settings->is_test_mode() ? 1 : 0,
			)
		);

		if ( ! $payment_id ) {
			$message = __(
				'The payment could not be recorded, so the payer was not sent to CHIP.',
				'chip-for-formidable-forms'
			);

			return new WP_Error( 'chip_payment_not_recorded', $message );
		}

		if ( $is_recurring ) {
			self::record_pending_subscription( $action, $entry, $purchase, $amount, (int) $payment_id );
		}

		return (int) $payment_id;
	}

	/**
	 * Record a pending subscription so Formidable's cron and the payments screen
	 * can track it.
	 *
	 * @param WP_Post  $action     Payment action.
	 * @param stdClass $entry      Entry.
	 * @param array    $purchase   CHIP purchase response.
	 * @param int      $amount     Amount in minor units.
	 * @param int      $payment_id Payment row ID.
	 * @return void
	 */
	private static function record_pending_subscription( $action, $entry, $purchase, $amount, $payment_id ) {
		$settings = FrmChipSettings::get_settings();

		$interval = isset( $action->post_content['interval'] ) ? $action->post_content['interval'] : 'month';
		$interval = in_array( $interval, array( 'day', 'week', 'month', 'year' ), true ) ? $interval : 'month';

		$interval_count = isset( $action->post_content['interval_count'] )
			? absint( $action->post_content['interval_count'] )
			: 1;

		$interval_count = $interval_count > 0 ? $interval_count : 1;

		$subscription = new FrmTransLiteSubscription();

		$sub_id = $subscription->create(
			array(
				'paysys'         => FrmChipHooksController::GATEWAY,
				// The token is stored on the first purchase, so it is the handle
				// used to charge this subscription later.
				'sub_id'         => (string) $purchase['id'],
				'item_id'        => (int) $entry->id,
				'amount'         => FrmChipHelper::from_minor_units( $amount ),
				'first_amount'   => FrmChipHelper::from_minor_units( $amount ),
				'action_id'      => (int) $action->ID,
				'interval_count' => $interval_count,
				'time_interval'  => $interval,
				'end_count'      => self::get_payment_limit( $action ),
				'status'         => 'pending',
				'next_bill_date' => self::get_next_bill_date( $interval, $interval_count ),
				'test'           => $settings->is_test_mode() ? 1 : 0,
			)
		);

		if ( ! $sub_id ) {
			return;
		}

		$payment = new FrmTransLitePayment();
		$payment->update( $payment_id, array( 'sub_id' => (string) $sub_id ) );
	}

	/**
	 * Resolve the configured recurring payment limit.
	 *
	 * @param WP_Post $action Payment action.
	 * @return int
	 */
	private static function get_payment_limit( $action ) {
		$limit = isset( $action->post_content['payment_limit'] ) ? absint( $action->post_content['payment_limit'] ) : 0;

		return $limit > 0 ? $limit : 9999;
	}

	/**
	 * Work out the first renewal date.
	 *
	 * @param string $interval       day|week|month|year.
	 * @param int    $interval_count Interval multiplier.
	 * @return string
	 */
	private static function get_next_bill_date( $interval, $interval_count ) {
		$map = array(
			'day'   => 'days',
			'week'  => 'weeks',
			'month' => 'months',
			'year'  => 'years',
		);

		$unit = isset( $map[ $interval ] ) ? $map[ $interval ] : 'months';

		return gmdate( 'Y-m-d', strtotime( '+' . $interval_count . ' ' . $unit ) );
	}

	/**
	 * Build the CHIP purchase payload for a submission.
	 *
	 * @param WP_Post  $action Payment action.
	 * @param stdClass $entry  Entry.
	 * @param stdClass $form   Form.
	 * @param int      $amount Amount in minor units.
	 * @return array|WP_Error
	 */
	private static function build_purchase_params( $action, $entry, $form, $amount ) {
		$settings = FrmChipSettings::get_settings();

		if ( ! $settings->is_configured() ) {
			$message = __(
				'CHIP is not configured. Add your secret key and brand ID in Formidable settings.',
				'chip-for-formidable-forms'
			);

			return new WP_Error( 'chip_not_configured', $message );
		}

		$is_recurring = 'recurring' === $action->post_content['type'];

		$client_email = FrmChipHelper::get_entry_value( $action->post_content['chip_billing_email'], $entry );
		$first_name   = FrmChipHelper::get_entry_value( $action->post_content['chip_billing_first_name'], $entry );
		$last_name    = FrmChipHelper::get_entry_value( $action->post_content['chip_billing_last_name'], $entry );
		$phone        = FrmChipHelper::get_entry_value( $action->post_content['chip_billing_phone'], $entry );

		$full_name = trim( $first_name . ' ' . $last_name );

		if ( '' === $full_name ) {
			// Fall back to the email local part so CHIP always receives a name.
			$full_name = $client_email ? strstr( $client_email, '@', true ) : '';
		}

		$product_name = isset( $action->post_content['chip_product_name'] )
			? trim( (string) $action->post_content['chip_product_name'] )
			: '';

		if ( '' === $product_name ) {
			$product_name = $form->name;
		}

		$reference = FrmChipHelper::get_entry_value( $action->post_content['chip_reference'], $entry );

		if ( '' === $reference ) {
			$reference = 'formidable-' . $form->id . '-' . $entry->id;
		}

		$client = array(
			'email'     => FrmChipHelper::truncate( $client_email, 128 ),
			'full_name' => FrmChipHelper::truncate( $full_name, 128 ),
		);

		// Send the phone exactly as the payer typed it. Omitted entirely when
		// the field is unmapped or blank, so CHIP never receives an empty string.
		if ( '' !== $phone ) {
			$client['phone'] = FrmChipHelper::truncate( $phone, 32 );
		}

		$params = array(
			'brand_id'         => (string) $settings->get( 'brand_id' ),
			'creator_agent'    => 'Formidable Forms: ' . FRM_CHIP_MODULE_VERSION,
			'platform'         => 'formidableforms',
			'reference'        => FrmChipHelper::truncate( $reference, 128 ),
			// Deliberately false: CHIP's own receipt is not sent, so the site
			// stays in control of payer communication.
			'send_receipt'     => false,
			'success_callback' => self::get_callback_url(),
			'success_redirect' => self::get_return_url( $form, $entry, 'success' ),
			'failure_redirect' => self::get_return_url( $form, $entry, 'failed' ),
			'cancel_redirect'  => self::get_return_url( $form, $entry, 'cancelled' ),
			'client'           => $client,
			'purchase'         => array(
				'currency'   => FrmChipHelper::CURRENCY,
				'timezone'   => apply_filters( 'frm_chip_purchase_timezone', FrmChipHelper::get_timezone() ),
				'due_strict' => (bool) $settings->get( 'due_strict' ),
				'notes'      => FrmChipHelper::truncate( $form->name . ' | entry #' . $entry->id, 10000 ),
				'products'   => array(
					array(
						'name'     => FrmChipHelper::truncate( $product_name, 256 ),
						'price'    => (int) $amount,
						'quantity' => 1,
					),
				),
			),
		);

		if ( $settings->get( 'due_strict' ) ) {
			$params['due'] = time() + ( absint( $settings->get( 'due_strict_timing' ) ) * MINUTE_IN_SECONDS );
		}

		$address = FrmChipHelper::get_entry_value( $action->post_content['chip_billing_address'], $entry );

		if ( '' !== $address ) {
			$params['client']['street_address'] = FrmChipHelper::truncate( $address, 128 );
		}

		$whitelist = self::resolve_whitelist( $settings, $amount, $is_recurring, $action );

		if ( $whitelist ) {
			$params['payment_method_whitelist'] = $whitelist;
		}

		if ( $is_recurring ) {
			// Recurring charges need a stored card, which only the card networks
			// support. force_recurring tells CHIP to save the card on this first
			// purchase so it can be charged again later.
			$params['force_recurring'] = true;

			$card_methods = array_values( array_intersect( $whitelist, FrmChipPaymentMethods::CARD_GROUP ) );

			if ( $whitelist && ! $card_methods ) {
				$message = __(
					'Recurring payments need a card method. Enable Card (Visa, Mastercard, Maestro) in the settings.',
					'chip-for-formidable-forms'
				);

				return new WP_Error( 'chip_recurring_needs_card', $message );
			}

			$params['payment_method_whitelist'] = $card_methods ? $card_methods : FrmChipPaymentMethods::CARD_GROUP;
		}

		/**
		 * Filter the CHIP purchase payload before it is sent.
		 *
		 * @param array    $params Purchase payload.
		 * @param WP_Post  $action Payment action.
		 * @param stdClass $entry  Entry.
		 * @param stdClass $form   Form.
		 */
		return apply_filters( 'frm_chip_purchase_params', $params, $action, $entry, $form );
	}

	/**
	 * Normalise the per-form payment method mode.
	 *
	 * An unrecognised or absent value means "use the global settings", which is
	 * what every action saved before this setting existed will have.
	 *
	 * @param array $settings Action settings being saved.
	 * @return string global|all|custom
	 */
	private static function sanitize_payment_method_mode( array $settings ) {
		$mode = isset( $settings['chip_payment_methods'] )
			? sanitize_text_field( $settings['chip_payment_methods'] )
			: '';

		return in_array( $mode, array( 'all', 'custom' ), true ) ? $mode : 'global';
	}

	/**
	 * Resolve the configured whitelist against the merchant's available methods.
	 *
	 * @param FrmChipSettings $settings     Plugin settings.
	 * @param int             $amount       Amount in minor units.
	 * @param bool            $is_recurring Whether this is a recurring payment.
	 * @param object|null     $action       Payment action, for a per-form override.
	 * @return array
	 */
	private static function resolve_whitelist( $settings, $amount, $is_recurring, $action = null ) {
		$configured = $settings->get_whitelist( $action );

		if ( ! $configured ) {
			return array();
		}

		$api = FrmChipAppController::api();

		if ( is_wp_error( $api ) ) {
			return $configured;
		}

		$resolved = FrmChipPaymentMethods::resolve( $configured, $api, FrmChipHelper::CURRENCY, $amount );

		if ( $is_recurring ) {
			// Recurring requires a card regardless of what else is enabled.
			return array_values( array_intersect( $resolved, FrmChipPaymentMethods::CARD_GROUP ) );
		}

		return $resolved;
	}

	/**
	 * Build the return URL the payer lands on after the CHIP checkout.
	 *
	 * Formidable stores the page the form was submitted from as a temporary entry
	 * meta, which the return handler reads back to show the right confirmation.
	 *
	 * @param stdClass $form   Form.
	 * @param stdClass $entry  Entry.
	 * @param string   $status success|failed|cancelled.
	 * @return string
	 */
	private static function get_return_url( $form, $entry, $status ) {
		$base = wp_get_referer();

		if ( ! $base ) {
			$base = home_url( '/' );
		}

		/**
		 * Filter the URL the payer returns to after the CHIP checkout.
		 *
		 * @param string   $base   Base URL.
		 * @param stdClass $form   Form.
		 * @param stdClass $entry  Entry.
		 * @param string   $status success|failed|cancelled.
		 */
		$base = apply_filters( 'frm_chip_return_url', $base, $form, $entry, $status );

		return add_query_arg(
			array(
				'frmchip'   => (int) $entry->id,
				'frmchipst' => $status,
			),
			$base
		);
	}

	/**
	 * Build the URL CHIP posts asynchronous callbacks to.
	 *
	 * @return string
	 */
	private static function get_callback_url() {
		return add_query_arg(
			array( 'frmchip_callback' => 1 ),
			home_url( '/' )
		);
	}

	/**
	 * Override Formidable's confirmation method so the payer is redirected to
	 * CHIP instead of seeing the form's normal success action.
	 *
	 * @param string|array $method Confirmation method resolved by Formidable.
	 * @param stdClass     $form   Form.
	 * @param string       $event  Current event.
	 * @return string|array
	 */
	public static function filter_success_method( $method, $form, $event ) {
		$pending = self::get_pending_for_form( $form->id );

		if ( ! $pending ) {
			return $method;
		}

		return 'redirect';
	}

	/**
	 * Supply the CHIP checkout URL as the redirect target.
	 *
	 * @param string   $url  Redirect URL resolved by Formidable.
	 * @param stdClass $form Form.
	 * @param array    $args Redirect args, including the entry ID.
	 * @return string
	 */
	public static function filter_redirect_url( $url, $form, $args = array() ) {
		if ( empty( $args['id'] ) ) {
			return $url;
		}

		$entry_id = absint( $args['id'] );

		if ( ! isset( self::$pending[ $entry_id ] ) ) {
			return $url;
		}

		return self::$pending[ $entry_id ]['checkout_url'];
	}

	/**
	 * Get the pending CHIP payment for a form, if one was created this request.
	 *
	 * @param int $form_id Form ID.
	 * @return array|null
	 */
	private static function get_pending_for_form( $form_id ) {
		$form_id = absint( $form_id );

		foreach ( self::$pending as $pending ) {
			if ( (int) $pending['action']->menu_order === $form_id ) {
				return $pending;
			}
		}

		return null;
	}

	/**
	 * Hide the fields CHIP uses to store payer details from the form output.
	 *
	 * @param bool     $show_normal Whether the field renders normally.
	 * @param string   $type        Field type.
	 * @param stdClass $field       Field object.
	 * @return bool
	 */
	public static function hide_order_fields( $show_normal, $type, $field ) {
		if ( ! in_array( $type, array( 'email', 'name', 'address' ), true ) ) {
			return $show_normal;
		}

		return FrmField::get_option( $field, 'is_chip_order_field' ) ? false : $show_normal;
	}

	/**
	 * Render the CHIP section of the payment action settings.
	 *
	 * @param array $args Contains form_action and action_control.
	 * @return void
	 */
	public static function render_action_options( $args ) {
		if ( ! isset( $args['form_action'], $args['action_control'] ) ) {
			return;
		}

		$form_action    = $args['form_action'];
		$action_control = $args['action_control'];
		$gateways       = (array) $form_action->post_content['gateway'];

		if ( ! in_array( FrmChipHooksController::GATEWAY, $gateways, true ) ) {
			return;
		}

		$form_fields   = FrmChipHelper::get_form_fields( $form_action->menu_order );
		$settings      = FrmChipSettings::get_settings();
		$is_configured = $settings->is_configured();
		$is_recurring  = 'recurring' === $form_action->post_content['type'];

		include FRM_CHIP_PATH . 'includes/views/action-settings/options.php';
	}

	/**
	 * Enqueue the admin script for the payment action panel.
	 *
	 * @return void
	 */
	public static function enqueue_admin_script() {
		wp_enqueue_script(
			'frm-chip-action',
			FRM_CHIP_URL . 'assets/js/action.js',
			array( 'jquery', 'wp-hooks' ),
			FRM_CHIP_MODULE_VERSION,
			true
		);
	}

	/**
	 * Print the CHIP symbol used by the gateway tab icon.
	 *
	 * Formidable resolves each gateway tab icon as `frm_<gateway>_full_icon`
	 * against its own SVG sprite, which has no CHIP symbol, so the tab would
	 * otherwise render as an empty box. The sprite is emitted with readfile()
	 * and exposes no filter, so the symbol is appended to the page instead.
	 *
	 * @return void
	 */
	public static function print_gateway_icon() {
		include FRM_CHIP_PATH . 'includes/views/gateway-icon.php';
	}

	/**
	 * Get the receipt link for a CHIP payment.
	 *
	 * Kept for parity with the other gateways; CHIP has no public transaction
	 * dashboard, so the purchase ID is returned unlinked.
	 *
	 * @param string $receipt Receipt ID.
	 * @return string
	 */
	public static function get_receipt_link( $receipt ) {
		return esc_html( $receipt );
	}
}

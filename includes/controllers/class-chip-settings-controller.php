<?php
/**
 * Global settings screen.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Adds the CHIP section to Formidable's global settings.
 *
 * Follows the same contract as the Stripe, Square and PayPal Lite sections: a
 * section entry with a class and a function, and processing wired to
 * `frm_update_settings`.
 */
class FrmChipSettingsController {

	/**
	 * Register the settings section.
	 *
	 * @param array $sections Registered sections.
	 * @return array
	 */
	public static function add_settings_section( $sections ) {
		$sections['chip'] = array(
			'class'    => self::class,
			'function' => 'route',
			'icon'     => 'frmfont frm_credit_card_alt_icon',
			'name'     => __( 'CHIP', 'chip-for-formidable-forms' ),
		);

		return $sections;
	}

	/**
	 * Render the section.
	 *
	 * @return void
	 */
	public static function route() {
		$settings = FrmChipSettings::get_settings();
		$status   = self::get_account_status( $settings );

		include FRM_CHIP_PATH . 'includes/views/settings/form.php';
	}

	/**
	 * Save posted settings.
	 *
	 * @return void
	 */
	public static function process_form() {
		// Formidable verifies the nonce before firing frm_update_settings.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['frm_chip_secret_key'] ) && ! isset( $_POST['frm_chip_brand_id'] ) ) {
			return;
		}

		$settings = FrmChipSettings::get_settings();
		$before   = array(
			'secret_key' => $settings->get( 'secret_key' ),
			'brand_id'   => $settings->get( 'brand_id' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$settings->update( wp_unslash( $_POST ) );
		$settings->store();

		$key_changed   = $before['secret_key'] !== $settings->get( 'secret_key' );
		$brand_changed = $before['brand_id'] !== $settings->get( 'brand_id' );

		if ( $key_changed || $brand_changed ) {
			// The public key is cached per company, so a credential change needs
			// it re-fetched on the next callback.
			self::refresh_public_key( $settings );
		}
	}

	/**
	 * Fetch and cache the public key for the configured credentials.
	 *
	 * @param FrmChipSettings $settings Plugin settings.
	 * @return void
	 */
	private static function refresh_public_key( $settings ) {
		if ( ! $settings->is_configured() ) {
			return;
		}

		$result = FrmChipAppController::get_public_key();

		if ( is_wp_error( $result ) ) {
			FrmChipHelper::log( 'Public key lookup failed', $result->get_error_message() );
		}
	}

	/**
	 * Probe the credentials so the screen can report the account state.
	 *
	 * @param FrmChipSettings $settings Plugin settings.
	 * @return array {
	 *     @type string $state   unknown|unconfigured|connected|error.
	 *     @type string $message Human readable summary.
	 * }
	 */
	private static function get_account_status( $settings ) {
		if ( ! $settings->is_configured() ) {
			return array(
				'state'   => 'unconfigured',
				'message' => __( 'Enter your secret key and brand ID to start accepting payments.', 'chip-for-formidable-forms' ),
			);
		}

		$api = FrmChipAppController::api();

		if ( is_wp_error( $api ) ) {
			return array(
				'state'   => 'error',
				'message' => $api->get_error_message(),
			);
		}

		$methods = $api->get_payment_methods( FrmChipHelper::CURRENCY, 1000 );

		if ( is_wp_error( $methods ) ) {
			return array(
				'state'   => 'error',
				'message' => $methods->get_error_message(),
			);
		}

		$available = isset( $methods['available_payment_methods'] ) ? (array) $methods['available_payment_methods'] : array();

		if ( ! $available ) {
			$message = __(
				'No payment methods are enabled for this brand.',
				'chip-for-formidable-forms'
			);

			return array(
				'state'   => 'error',
				'message' => $message,
			);
		}

		return array(
			'state'   => 'connected',
			'message' => sprintf(
				/* translators: %s: comma separated list of payment method labels. */
				__( 'Connected. Available: %s', 'chip-for-formidable-forms' ),
				implode( ', ', array_map( array( 'FrmChipPaymentMethods', 'get_label' ), $available ) )
			),
		);
	}
}

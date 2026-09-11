<?php
/**
 * Gateway registration.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Registers CHIP with Formidable's gateway registry.
 *
 * Formidable resolves a gateway by concatenating `Frm` + the `class` setting +
 * `ActionsController`, then calls `trigger_gateway()` on it statically. The
 * `class` value here must therefore stay in step with FrmChipActionsController.
 */
class FrmChipAppController {

	/**
	 * Add CHIP to the gateway list.
	 *
	 * @param array $gateways Registered gateways.
	 * @return array
	 */
	public static function add_gateway( $gateways ) {
		$gateways[ FrmChipHooksController::GATEWAY ] = array(
			'label'      => __( 'CHIP', 'chip-for-formidable-forms' ),
			'user_label' => __( 'Payment', 'chip-for-formidable-forms' ),
			// Resolved as FrmChipActionsController.
			'class'      => 'Chip',
			// CHIP supports one-time and recurring charges.
			'recurring'  => true,
			'include'    => array(
				'billing_first_name',
				'billing_last_name',
				'billing_address',
			),
		);

		return $gateways;
	}

	/**
	 * Get the gateway definition for CHIP.
	 *
	 * @return array
	 */
	public static function get_gateway() {
		$gateways = FrmTransLiteAppHelper::get_gateways();

		return isset( $gateways[ FrmChipHooksController::GATEWAY ] )
			? $gateways[ FrmChipHooksController::GATEWAY ]
			: array();
	}

	/**
	 * Get the API client for the configured credentials.
	 *
	 * @return FrmChipApi|WP_Error
	 */
	public static function api() {
		$settings = FrmChipSettings::get_settings();

		if ( ! $settings->is_configured() ) {
			$message = __(
				'CHIP is not configured. Add your secret key and brand ID in Formidable settings.',
				'chip-for-formidable-forms'
			);

			return new WP_Error( 'chip_not_configured', $message );
		}

		return FrmChipApi::get_instance( $settings->get( 'secret_key' ), $settings->get( 'brand_id' ) );
	}

	/**
	 * Get the public key used to verify callbacks, fetching and caching it once
	 * per company.
	 *
	 * @return string|WP_Error PEM encoded public key.
	 */
	public static function get_public_key() {
		$api = self::api();

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$company_uid = $api->get_company_uid();

		if ( is_wp_error( $company_uid ) ) {
			return $company_uid;
		}

		$option_name = 'frm_chip_public_key_' . $company_uid;
		$cached      = get_option( $option_name );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$public_key = $api->get_public_key();

		if ( is_wp_error( $public_key ) ) {
			return $public_key;
		}

		update_option( $option_name, $public_key, false );

		return $public_key;
	}

	/**
	 * Get the URL of the CHIP settings section.
	 *
	 * @return string
	 */
	public static function get_settings_url() {
		return admin_url( 'admin.php?page=formidable-settings&t=chip_settings' );
	}
}

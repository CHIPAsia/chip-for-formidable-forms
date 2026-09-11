<?php
/**
 * Payment method registry and runtime resolution.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Knows which payment methods exist, how they are grouped, and how a configured
 * whitelist is resolved against what the merchant actually has available.
 *
 * Two identifiers are interchangeable at the API level and are therefore exposed
 * to merchants as a single choice, then resolved at runtime:
 *
 * - DuitNow QR: `duitnow_qr` is the legacy identifier, `dnqr` the modern one.
 * - ShopeePay:  `razer_shopeepay` is the legacy identifier, `shopee_pay` the
 *   modern one (whitelist only, never returned by the payment methods endpoint).
 *
 * In both groups the modern identifier wins when the merchant has both.
 */
class FrmChipPaymentMethods {

	/**
	 * DuitNow QR group members.
	 *
	 * @var array
	 */
	const DUITNOW_GROUP = array( 'duitnow_qr', 'dnqr' );

	/**
	 * ShopeePay group members.
	 *
	 * @var array
	 */
	const SHOPEE_GROUP = array( 'razer_shopeepay', 'shopee_pay' );

	/**
	 * Card network members.
	 *
	 * @var array
	 */
	const CARD_GROUP = array( 'visa', 'mastercard', 'maestro' );

	/**
	 * Cache duration for the payment methods lookup, in seconds.
	 *
	 * @var int
	 */
	const CACHE_TTL = 1800;

	/**
	 * Get the merchant facing options for the whitelist setting.
	 *
	 * Keys are the stored values, values are the exact display strings required
	 * by the shared CHIP API spec. Group entries are single keys that expand at
	 * runtime.
	 *
	 * @return array<string, string>
	 */
	public static function get_options() {
		return array(
			'fpx'             => __( 'FPX', 'chip-for-formidable-forms' ),
			'fpx_b2b1'        => __( 'FPX B2B1', 'chip-for-formidable-forms' ),
			'card'            => __( 'Card (Visa, Mastercard, Maestro)', 'chip-for-formidable-forms' ),
			'duitnow_qr'      => __( 'DuitNow QR', 'chip-for-formidable-forms' ),
			'dnqr'            => __( 'DuitNow QR (DuitNow)', 'chip-for-formidable-forms' ),
			'shopee_pay'      => __( 'ShopeePay', 'chip-for-formidable-forms' ),
			'razer_grabpay'   => __( 'GrabPay', 'chip-for-formidable-forms' ),
			'razer_atome'     => __( 'Atome', 'chip-for-formidable-forms' ),
			'razer_maybankqr' => __( 'Maybank QRPay', 'chip-for-formidable-forms' ),
			'razer_tng'       => __( "Touch 'n Go eWallet", 'chip-for-formidable-forms' ),
			'mpgs_apple_pay'  => __( 'Apple Pay', 'chip-for-formidable-forms' ),
			'mpgs_google_pay' => __( 'Google Pay', 'chip-for-formidable-forms' ),
			'crypto_coin'     => __( 'Crypto Coin', 'chip-for-formidable-forms' ),
		);
	}

	/**
	 * Get the display label for a stored whitelist value.
	 *
	 * @param string $key Stored value.
	 * @return string
	 */
	public static function get_label( $key ) {
		$options = self::get_options();

		return isset( $options[ $key ] ) ? $options[ $key ] : $key;
	}

	/**
	 * Expand a configured whitelist into the raw identifiers CHIP understands.
	 *
	 * Group keys are widened to every member so a group selection survives
	 * regardless of which identifier the merchant has. The `card` aggregator is
	 * expanded to its networks and then removed, because CHIP does not recognise
	 * the aggregator itself.
	 *
	 * @param array $whitelist Configured values.
	 * @return array
	 */
	public static function expand_groups( array $whitelist ) {
		$expanded = array();

		foreach ( $whitelist as $method ) {
			switch ( $method ) {
				case 'card':
					$expanded = array_merge( $expanded, self::CARD_GROUP );
					break;
				case 'duitnow_qr':
				case 'dnqr':
					$expanded = array_merge( $expanded, self::DUITNOW_GROUP );
					break;
				case 'shopee_pay':
				case 'razer_shopeepay':
					$expanded = array_merge( $expanded, self::SHOPEE_GROUP );
					break;
				default:
					$expanded[] = $method;
					break;
			}
		}

		return array_values( array_unique( $expanded ) );
	}

	/**
	 * Resolve a configured whitelist against the merchant's available methods.
	 *
	 * Non-group entries pass through untouched. Each configured group collapses
	 * to the single identifier the merchant actually has, preferring the modern
	 * one. If the lookup fails the expanded list is used as-is, so a transient
	 * API problem never blocks a checkout.
	 *
	 * @param array      $whitelist Configured values.
	 * @param FrmChipApi $api       API client for the active credentials.
	 * @param string     $currency  Currency code.
	 * @param int        $amount    Amount in minor units.
	 * @return array
	 */
	public static function resolve( array $whitelist, FrmChipApi $api, $currency, $amount ) {
		$expanded = self::expand_groups( $whitelist );

		$groups = self::configured_groups( $whitelist );

		if ( ! $groups ) {
			// Nothing group-like configured: never spend an API call.
			return $expanded;
		}

		$available = self::get_available( $api, $currency, $amount );

		if ( null === $available ) {
			return $expanded;
		}

		$resolved = array();

		foreach ( $groups as $group ) {
			$members = array_values( array_intersect( $group, $available ) );

			if ( ! $members ) {
				// The merchant has neither identifier for this group.
				continue;
			}

			// Prefer the modern identifier when both are present.
			if ( in_array( 'dnqr', $members, true ) ) {
				$members = array_values( array_diff( $members, array( 'duitnow_qr' ) ) );
			}

			if ( in_array( 'shopee_pay', $members, true ) ) {
				$members = array_values( array_diff( $members, array( 'razer_shopeepay' ) ) );
			}

			$resolved = array_merge( $resolved, $members );
		}

		// Keep everything that is not part of a configured group.
		$group_members = array_merge( self::DUITNOW_GROUP, self::SHOPEE_GROUP );
		$final         = array_values( array_diff( $expanded, $group_members ) );
		$final         = array_merge( $final, $resolved );

		return array_values( array_unique( $final ) );
	}

	/**
	 * Get the groups represented in a configured whitelist.
	 *
	 * @param array $whitelist Configured values.
	 * @return array<int, array>
	 */
	private static function configured_groups( array $whitelist ) {
		$groups = array();

		if ( array_intersect( $whitelist, self::DUITNOW_GROUP ) ) {
			$groups[] = self::DUITNOW_GROUP;
		}

		if ( array_intersect( $whitelist, self::SHOPEE_GROUP ) ) {
			$groups[] = self::SHOPEE_GROUP;
		}

		return $groups;
	}

	/**
	 * Fetch the merchant's available payment methods, cached per brand, currency
	 * and amount bucket.
	 *
	 * @param FrmChipApi $api      API client.
	 * @param string     $currency Currency code.
	 * @param int        $amount   Amount in minor units.
	 * @return array|null Null when the lookup could not be completed.
	 */
	public static function get_available( FrmChipApi $api, $currency, $amount ) {
		$bucket    = (int) ( $amount / 100 );
		$cache_key = 'frm_chip_pm_' . md5( (string) $currency . '|' . $bucket . '|' . self::get_cache_salt() );

		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = $api->get_payment_methods( $currency, $amount );

		if ( is_wp_error( $response ) || ! isset( $response['available_payment_methods'] ) ) {
			return null;
		}

		$available = (array) $response['available_payment_methods'];

		set_transient( $cache_key, $available, self::CACHE_TTL );

		return $available;
	}

	/**
	 * Salt the cache key so two brands with the same currency and amount bucket
	 * do not share an entry.
	 *
	 * @return string
	 */
	private static function get_cache_salt() {
		$settings = FrmChipSettings::get_settings();

		return (string) $settings->get( 'brand_id' );
	}

	/**
	 * Clear cached availability lookups.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		$settings = FrmChipSettings::get_settings();

		foreach ( array( 1000, 5000, 10000, 20000, 50000 ) as $bucket ) {
			$key = 'frm_chip_pm_' . md5(
				FrmChipHelper::CURRENCY . '|' . ( $bucket / 100 ) . '|' . (string) $settings->get( 'brand_id' )
			);

			delete_transient( $key );
		}
	}
}

<?php
/**
 * Shared helpers.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Utility methods shared across the plugin.
 */
class FrmChipHelper {

	/**
	 * Supported currency.
	 *
	 * CHIP Collect settles Malaysian merchants in MYR. Anything else is rejected
	 * before a purchase is created so the payer never reaches a checkout that
	 * cannot complete.
	 *
	 * @var string
	 */
	const CURRENCY = 'MYR';

	/**
	 * Turn a CHIP error response into a message worth showing an admin.
	 *
	 * @param mixed $decoded Decoded response body.
	 * @param int   $code    HTTP status code.
	 * @return string
	 */
	public static function describe_api_error( $decoded, $code ) {
		$parts = array();

		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $key => $value ) {
				if ( 'errors' === $key && is_array( $value ) ) {
					foreach ( $value as $error ) {
						$parts[] = self::describe_single_error( $error );
					}
					continue;
				}

				if ( is_array( $value ) && isset( $value['message'] ) ) {
					$parts[] = $value['message'];
					continue;
				}

				if ( is_string( $value ) ) {
					$parts[] = $value;
				}
			}
		}

		$parts = array_filter( array_map( 'trim', $parts ) );

		if ( $parts ) {
			return implode( ' ', $parts );
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'CHIP returned an unexpected response (HTTP %d).', 'chip-for-formidable-forms' ),
			$code
		);
	}

	/**
	 * Describe a single error entry from a CHIP response.
	 *
	 * @param mixed $error Error entry, usually an array with a message key.
	 * @return string
	 */
	private static function describe_single_error( $error ) {
		if ( is_string( $error ) ) {
			return $error;
		}

		if ( is_array( $error ) && isset( $error['message'] ) ) {
			return (string) $error['message'];
		}

		return '';
	}

	/**
	 * Convert a major unit amount into the minor units CHIP expects.
	 *
	 * CHIP prices are specified in cents, so RM 10.00 is 1000.
	 *
	 * @param mixed $amount Amount in major units.
	 * @return int
	 */
	public static function to_minor_units( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Convert minor units back into a major unit decimal string.
	 *
	 * @param mixed $amount Amount in minor units.
	 * @return string
	 */
	public static function from_minor_units( $amount ) {
		return number_format( (float) $amount / 100, 2, '.', '' );
	}

	/**
	 * Resolve the amount configured on a payment action.
	 *
	 * Formidable resolves shortcodes in the amount setting, so the value can be
	 * a static figure or a reference to a product/quantity field.
	 *
	 * @param WP_Post  $action Payment action.
	 * @param stdClass $entry  Entry.
	 * @param stdClass $form   Form.
	 * @return int Amount in minor units, 0 when nothing was resolved.
	 */
	public static function get_amount_in_minor_units( $action, $entry, $form ) {
		if ( ! isset( $action->post_content['amount'] ) ) {
			return 0;
		}

		$amount = FrmTransLiteActionsController::prepare_amount(
			$action->post_content['amount'],
			compact( 'form', 'entry', 'action' )
		);

		return self::to_minor_units( $amount );
	}

	/**
	 * Determine the timezone to send with a purchase.
	 *
	 * @return string
	 */
	public static function get_timezone() {
		$timezone = wp_timezone_string();

		if ( preg_match( '/^[A-Za-z]+\/[A-Za-z_\/-]+$/', $timezone ) ) {
			return $timezone;
		}

		return 'UTC';
	}

	/**
	 * Get the fields that can be mapped in the payment action settings.
	 *
	 * @param int $form_id Form ID.
	 * @return array
	 */
	public static function get_form_fields( $form_id ) {
		$form_id = absint( $form_id );

		/**
		 * Filter the fields offered in the CHIP payment action settings.
		 *
		 * @param array $fields  Field objects.
		 * @param int   $form_id Form ID.
		 */
		return apply_filters(
			'frm_chip_action_field_options',
			FrmField::getAll(
				array(
					'fi.form_id'  => $form_id,
					'fi.type not' => array( 'divider', 'end_divider', 'html', 'break', 'captcha', 'rte', 'form', 'submit' ),
				),
				'field_order'
			),
			$form_id
		);
	}

	/**
	 * Read a value from an entry for a configured field ID.
	 *
	 * @param mixed    $field_id Field ID from the action settings.
	 * @param stdClass $entry    Entry.
	 * @return string
	 */
	public static function get_entry_value( $field_id, $entry ) {
		$field_id = absint( $field_id );

		if ( ! $field_id || empty( $entry->metas[ $field_id ] ) ) {
			return '';
		}

		$value = $entry->metas[ $field_id ];

		if ( is_array( $value ) ) {
			$value = implode( ' ', array_filter( array_map( 'strval', $value ) ) );
		}

		return trim( (string) $value );
	}

	/**
	 * Reduce a value to something safe to send as a CHIP client field.
	 *
	 * @param string $value  Raw value.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	public static function truncate( $value, $length ) {
		$value = wp_strip_all_tags( (string) $value );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Log a message when debugging is enabled.
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data    Optional data to append.
	 * @return void
	 */
	public static function log( $message, $data = null ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( null !== $data ) {
			$message .= ' ' . wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[chip-for-formidable-forms] ' . $message );
	}

	/**
	 * Remember the page the payer submitted from.
	 *
	 * Formidable can submit by AJAX, in which case the referer during processing
	 * is admin-ajax.php. The real page is captured here so the payer can be
	 * returned to it after the hosted checkout. Stored against field ID 0, which
	 * Formidable reserves for internal entry meta.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	public static function store_referer( $entry_id ) {
		$entry_id = absint( $entry_id );
		$referer  = wp_get_referer();

		if ( ! $entry_id || ! $referer ) {
			return;
		}

		// Strip anything left over from a previous return so it cannot loop.
		$referer = remove_query_arg( array( 'frmchip', 'frmchipst', 'frmchip_error' ), $referer );

		FrmEntryMeta::add_entry_meta(
			$entry_id,
			0,
			'',
			wp_json_encode( array( 'referer' => $referer ) )
		);
	}

	/**
	 * Read and clear the stored referer for an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return string Empty string when nothing was stored.
	 */
	public static function pull_referer( $entry_id ) {
		$entry_id = absint( $entry_id );

		if ( ! $entry_id ) {
			return '';
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, meta_value FROM ' . $wpdb->prefix . 'frm_item_metas'
					. ' WHERE field_id = 0 AND item_id = %d AND meta_value LIKE %s',
				$entry_id,
				'{"referer":%'
			)
		);

		if ( ! $row ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'frm_item_metas', array( 'id' => (int) $row->id ) );

		$meta = json_decode( $row->meta_value, true );

		if ( ! is_array( $meta ) || empty( $meta['referer'] ) ) {
			return '';
		}

		return (string) $meta['referer'];
	}
}

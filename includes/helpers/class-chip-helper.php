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
	 * CHIP reports validation failures as a map of field name to a list of
	 * entries: {"success_callback":[{"message":"...","code":"..."}]}. Errors can
	 * also arrive as a plain string, or as a list under an "errors" key. All of
	 * those shapes are unwrapped here so the real reason is surfaced rather than
	 * a generic HTTP status.
	 *
	 * @param mixed $decoded Decoded response body.
	 * @param int   $code    HTTP status code.
	 * @return string
	 */
	public static function describe_api_error( $decoded, $code ) {
		$parts = array();

		if ( is_string( $decoded ) && '' !== trim( $decoded ) ) {
			$parts[] = trim( $decoded );
		}

		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $key => $value ) {
				// Each entry may be a list of error objects, a single object, or
				// a string. Collect every message found.
				foreach ( self::collect_messages( $value ) as $message ) {
					$parts[] = self::label_message( $key, $message );
				}
			}
		}

		$parts = array_values( array_filter( array_map( 'trim', $parts ) ) );

		if ( $parts ) {
			// Keep the message readable; a long validation list is truncated.
			return implode( ' ', array_slice( array_unique( $parts ), 0, 4 ) );
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'CHIP returned an unexpected response (HTTP %d).', 'chip-for-formidable-forms' ),
			$code
		);
	}

	/**
	 * Pull every human readable message out of an error value.
	 *
	 * @param mixed $value Error value from a CHIP response.
	 * @return array
	 */
	private static function collect_messages( $value ) {
		$messages = array();

		if ( is_string( $value ) ) {
			return array( $value );
		}

		if ( ! is_array( $value ) ) {
			return $messages;
		}

		if ( isset( $value['message'] ) ) {
			return array( (string) $value['message'] );
		}

		foreach ( $value as $entry ) {
			foreach ( self::collect_messages( $entry ) as $message ) {
				$messages[] = $message;
			}
		}

		return $messages;
	}

	/**
	 * Prefix a message with the field it belongs to, when useful.
	 *
	 * Numeric keys are list indexes, not field names, so they are dropped.
	 *
	 * @param mixed  $key     Response key.
	 * @param string $message Message text.
	 * @return string
	 */
	private static function label_message( $key, $message ) {
		if ( is_int( $key ) || '' === (string) $key || 'errors' === $key ) {
			return $message;
		}

		return $key . ': ' . $message;
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
			array(
				'form'   => $form,
				'entry'  => $entry,
				'action' => $action,
			)
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
	 * Format an amount for display in a notification.
	 *
	 * Amounts are stored as a plain decimal string, so this only adds the
	 * currency. Kept separate from Formidable's own formatter because the
	 * subscription row does not carry the payment a formatter expects.
	 *
	 * @param string $amount Amount as stored.
	 * @return string
	 */
	public static function format_amount( $amount ) {
		return self::CURRENCY . ' ' . number_format( (float) $amount, 2 );
	}

	/**
	 * Get the fields that can be mapped in the payment action settings.
	 *
	 * The form ID list goes through `frm_trans_action_get_field_options_form_id`,
	 * the same filter Formidable's own payment actions use. Without it, fields
	 * inside an embedded form (a Formidable Pro feature) never appear in the
	 * mapping dropdowns, because Pro widens the ID list through that filter.
	 *
	 * @param int $form_id Form ID.
	 * @return array
	 */
	public static function get_form_fields( $form_id ) {
		$form_id = absint( $form_id );

		/**
		 * Filter the form IDs whose fields are offered in the mapping dropdowns.
		 *
		 * Pro hooks this to include embedded forms. Kept in step with the core
		 * payment action so CHIP offers exactly the same fields.
		 *
		 * @param int|int[] $form_ids Form ID or IDs.
		 * @param int       $form_id  The form containing the payment action.
		 */
		$form_ids = apply_filters( 'frm_trans_action_get_field_options_form_id', $form_id, $form_id );
		$form_ids = is_array( $form_ids ) ? $form_ids : array( $form_ids );

		$fields = FrmField::getAll(
			array(
				'fi.form_id'  => $form_ids,
				'fi.type not' => array(
					'divider',
					'end_divider',
					'html',
					'break',
					'captcha',
					'rte',
					'form',
					'submit',
				),
			),
			'field_order'
		);

		// Fields from an embedded form are labelled with their form so a merchant
		// can tell two identically named fields apart.
		foreach ( $fields as $field ) {
			if ( (int) $field->form_id === $form_id ) {
				continue;
			}

			$embedded = FrmForm::getName( $field->form_id );

			if ( $embedded ) {
				/* translators: 1: field name, 2: embedded form name. */
				$field->name = sprintf( __( '%1$s (%2$s)', 'chip-for-formidable-forms' ), $field->name, $embedded );
			}
		}

		/**
		 * Filter the fields offered in the CHIP payment action settings.
		 *
		 * @param array $fields  Field objects.
		 * @param int   $form_id Form ID.
		 */
		return apply_filters( 'frm_chip_action_field_options', $fields, $form_id );
	}

	/**
	 * Read a value from an entry for a configured field ID.
	 *
	 * Also resolves a field that belongs to an embedded form. Those values live on
	 * the child entry, not the one being submitted: the parent's column for an
	 * embedded form holds a list of child entry IDs, so reading it directly would
	 * hand CHIP the ID ("32") instead of what the payer typed. Core resolves this
	 * the same way in FrmEntriesHelper::prepare_display_value().
	 *
	 * @param mixed    $field_id Field ID from the action settings.
	 * @param stdClass $entry    Entry.
	 * @return string
	 */
	public static function get_entry_value( $field_id, $entry ) {
		$field_id = absint( $field_id );

		if ( ! $field_id || ! $entry || empty( $entry->metas ) ) {
			return '';
		}

		if ( isset( $entry->metas[ $field_id ] ) && ! empty( $entry->metas[ $field_id ] ) ) {
			return self::flatten_value( $entry->metas[ $field_id ] );
		}

		return self::get_embedded_value( $field_id, $entry );
	}

	/**
	 * Read a field that belongs to an embedded form, from its child entry.
	 *
	 * @param int      $field_id Field ID from the action settings.
	 * @param stdClass $entry    Parent entry.
	 * @return string
	 */
	private static function get_embedded_value( $field_id, $entry ) {
		$field = FrmField::getOne( $field_id );

		if ( ! $field ) {
			return '';
		}

		// Not an embedded field: nothing further to look up.
		if ( (int) $field->form_id === (int) $entry->form_id ) {
			return '';
		}

		$children = FrmEntry::getAll( array( 'it.parent_item_id' => (int) $entry->id ), '', '', true );

		foreach ( (array) $children as $child ) {
			if ( ! empty( $child->metas[ $field_id ] ) ) {
				return self::flatten_value( $child->metas[ $field_id ] );
			}
		}

		return '';
	}

	/**
	 * Reduce a stored meta value to a single string.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function flatten_value( $value ) {
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

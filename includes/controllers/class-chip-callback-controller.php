<?php
/**
 * Server callback handler.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Handles asynchronous callbacks from CHIP.
 *
 * CHIP delivers a callback to `success_callback` when a purchase is paid, and
 * separately for charges made against a saved token. Deliveries are retried for
 * up to 36 hours, so this endpoint has to be idempotent and must always answer
 * with a 2xx once the payload has been understood.
 *
 * The payload signature is verified against the company public key. If no key is
 * available the purchase is re-fetched from the API instead, which is equally
 * trustworthy because it does not rely on the request at all.
 */
class FrmChipCallbackController {

	/**
	 * Query arg marking a callback request.
	 *
	 * @var string
	 */
	const ARG = 'frmchip_callback';

	/**
	 * How long to wait for the per-payment lock, in seconds.
	 *
	 * @var int
	 */
	const LOCK_TIMEOUT = 15;

	/**
	 * Handle a callback request when present.
	 *
	 * @return void
	 */
	public static function maybe_handle() {
		if ( ! isset( $_GET[ self::ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		self::handle();
	}

	/**
	 * Process the callback.
	 *
	 * @return void
	 */
	private static function handle() {
		$raw_body = file_get_contents( 'php://input' );
		$payload  = json_decode( (string) $raw_body, true );

		if ( ! is_array( $payload ) || empty( $payload['id'] ) ) {
			FrmChipHelper::log( 'Callback rejected: unreadable payload' );
			self::respond( 400, 'Invalid payload' );
		}

		$purchase_id = sanitize_text_field( (string) $payload['id'] );

		$payment = FrmChipSettlement::get_payment_by_purchase( $purchase_id );

		if ( ! $payment ) {
			// Not one of ours. Answer 200 so CHIP stops retrying a callback that
			// will never match anything on this site.
			FrmChipHelper::log( 'Callback ignored: no matching payment', $purchase_id );
			self::respond( 200, 'Ignored' );
		}

		$verified = self::verify_signature( $raw_body, $payload );

		if ( is_wp_error( $verified ) ) {
			FrmChipHelper::log( 'Callback signature rejected', $verified->get_error_message() );
			self::respond( 401, 'Invalid signature' );
		}

		// Serialise concurrent deliveries of the same purchase while leaving
		// other payments free to run in parallel.
		$lock = 'frm_chip_payment_' . $purchase_id;
		self::acquire_lock( $lock );

		try {
			// Re-read under the lock: a concurrent delivery may have settled it.
			$payment = FrmChipSettlement::get_payment_by_purchase( $purchase_id );

			if ( ! $payment ) {
				self::respond( 200, 'Ignored' );
			}

			if ( $verified['authoritative'] ) {
				// Signature checked out, so the payload can be trusted directly.
				FrmChipSettlement::apply( $payload, $payment );
			} else {
				// No public key available: confirm against the API instead.
				$result = FrmChipSettlement::verify_and_settle( $purchase_id );

				if ( is_wp_error( $result ) ) {
					FrmChipHelper::log( 'Callback verification failed', $result->get_error_message() );
					self::respond( 500, 'Verification failed' );
				}
			}
		} finally {
			self::release_lock( $lock );
		}

		self::respond( 200, 'OK' );
	}

	/**
	 * Verify the callback signature.
	 *
	 * @param string $raw_body Raw request body.
	 * @param array  $payload  Decoded payload.
	 * @return array|WP_Error {
	 *     @type bool $authoritative Whether the payload itself can be trusted.
	 * }
	 */
	private static function verify_signature( $raw_body, $payload ) {
		$signature = isset( $_SERVER['HTTP_X_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) )
			: '';

		if ( '' === $signature ) {
			return array( 'authoritative' => false );
		}

		$public_key = FrmChipAppController::get_public_key();

		if ( is_wp_error( $public_key ) ) {
			return array( 'authoritative' => false );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $signature, true );

		if ( false === $decoded ) {
			$message = __( 'The callback signature could not be decoded.', 'chip-for-formidable-forms' );

			return new WP_Error( 'chip_bad_signature', $message );
		}

		$key = openssl_pkey_get_public( $public_key );

		if ( false === $key ) {
			$message = __( 'The stored CHIP public key is not usable.', 'chip-for-formidable-forms' );

			return new WP_Error( 'chip_bad_public_key', $message );
		}

		// sha256WithRSAEncryption over the raw body; OpenSSL hashes internally.
		// The key resource is freed when $key leaves scope; openssl_pkey_free()
		// is deprecated as of PHP 8.0.
		$result = openssl_verify( $raw_body, $decoded, $key, OPENSSL_ALGO_SHA256 );

		if ( 1 !== $result ) {
			$message = __( 'The callback signature did not match.', 'chip-for-formidable-forms' );

			return new WP_Error( 'chip_signature_mismatch', $message );
		}

		return array( 'authoritative' => true );
	}

	/**
	 * Take the MySQL advisory lock for a payment.
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

	/**
	 * Send a response and stop.
	 *
	 * @param int    $code    HTTP status code.
	 * @param string $message Plain text body.
	 * @return void
	 */
	private static function respond( $code, $message ) {
		status_header( $code );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $message );
		exit;
	}
}

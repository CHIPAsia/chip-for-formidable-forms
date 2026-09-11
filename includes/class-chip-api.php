<?php
/**
 * CHIP Collect API client.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Thin wrapper around the CHIP Collect REST API.
 *
 * Instances are cached per credential pair so two brands on the same site never
 * share a client. Every call returns either a decoded array or a WP_Error.
 */
class FrmChipApi {

	/**
	 * Instances keyed by credential hash.
	 *
	 * @var array<string, FrmChipApi>
	 */
	private static $instances = array();

	/**
	 * Secret key used for bearer auth.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Brand ID used for brand scoped endpoints.
	 *
	 * @var string
	 */
	private $brand_id;

	/**
	 * Get a client for the given credentials.
	 *
	 * @param string $secret_key CHIP secret key.
	 * @param string $brand_id   CHIP brand ID.
	 * @return FrmChipApi
	 */
	public static function get_instance( $secret_key, $brand_id ) {
		$key = md5( (string) $secret_key . '|' . (string) $brand_id );

		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = new self( (string) $secret_key, (string) $brand_id );
		}

		return self::$instances[ $key ];
	}

	/**
	 * Constructor.
	 *
	 * @param string $secret_key CHIP secret key.
	 * @param string $brand_id   CHIP brand ID.
	 */
	private function __construct( $secret_key, $brand_id ) {
		$this->secret_key = $secret_key;
		$this->brand_id   = $brand_id;
	}

	/**
	 * Create a purchase and receive its checkout URL.
	 *
	 * @param array $params Purchase payload.
	 * @return array|WP_Error
	 */
	public function create_purchase( array $params ) {
		// time() forces a fresh response instead of a cached one.
		return $this->request( 'POST', '/purchases/?time=' . time(), $params );
	}

	/**
	 * Retrieve a purchase.
	 *
	 * @param string $purchase_id Purchase ID.
	 * @return array|WP_Error
	 */
	public function get_purchase( $purchase_id ) {
		return $this->request( 'GET', '/purchases/' . rawurlencode( $purchase_id ) . '/?time=' . time() );
	}

	/**
	 * Refund a paid purchase.
	 *
	 * @param string   $purchase_id Purchase ID.
	 * @param int|null $amount      Amount in minor units for a partial refund, null for full.
	 * @return array|WP_Error
	 */
	public function refund_purchase( $purchase_id, $amount = null ) {
		$params = array();

		if ( null !== $amount ) {
			$params['amount'] = (int) $amount;
		}

		return $this->request( 'POST', '/purchases/' . rawurlencode( $purchase_id ) . '/refund/', $params );
	}

	/**
	 * Cancel a pending purchase.
	 *
	 * @param string $purchase_id Purchase ID.
	 * @return array|WP_Error
	 */
	public function cancel_purchase( $purchase_id ) {
		return $this->request( 'POST', '/purchases/' . rawurlencode( $purchase_id ) . '/cancel/' );
	}

	/**
	 * Charge a saved recurring token.
	 *
	 * @param string $purchase_id    Purchase ID that will carry the charge.
	 * @param string $recurring_token Purchase ID holding the token.
	 * @return array|WP_Error
	 */
	public function charge_purchase( $purchase_id, $recurring_token ) {
		return $this->request(
			'POST',
			'/purchases/' . rawurlencode( $purchase_id ) . '/charge/',
			array( 'recurring_token' => $recurring_token )
		);
	}

	/**
	 * Delete the recurring token stored on a purchase.
	 *
	 * @param string $purchase_id Purchase ID holding the token.
	 * @return array|WP_Error
	 */
	public function delete_recurring_token( $purchase_id ) {
		return $this->request( 'POST', '/purchases/' . rawurlencode( $purchase_id ) . '/delete_recurring_token/' );
	}

	/**
	 * List payment methods available to the brand.
	 *
	 * `amount` is mandatory: most methods enforce a minimum, and omitting or
	 * understating it hides them from the response.
	 *
	 * @param string $currency Currency code.
	 * @param int    $amount   Amount in minor units.
	 * @param string $language Optional language code.
	 * @return array|WP_Error
	 */
	public function get_payment_methods( $currency, $amount, $language = '' ) {
		$args = array(
			'brand_id' => $this->brand_id,
			'currency' => $currency,
			'amount'   => (int) $amount,
		);

		if ( '' !== $language ) {
			$args['language'] = $language;
		}

		return $this->request( 'GET', '/payment_methods/?' . http_build_query( $args, '', '&' ) );
	}

	/**
	 * Fetch the company wide public key used to verify callbacks.
	 *
	 * The endpoint returns a JSON encoded PEM string, so the surrounding quotes
	 * are stripped by json_decode before the value is returned.
	 *
	 * @return string|WP_Error PEM encoded public key.
	 */
	public function get_public_key() {
		$result = $this->request( 'GET', '/public_key/' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_string( $result ) ) {
			return $result;
		}

		return new WP_Error(
			'chip_invalid_public_key',
			__( 'CHIP returned an unexpected public key response.', 'chip-for-formidable-forms' )
		);
	}

	/**
	 * Retrieve the company UID for the credentials.
	 *
	 * The public key is cached per company, so the UID is needed to key it.
	 *
	 * @return string|WP_Error
	 */
	public function get_company_uid() {
		$result = $this->request( 'GET', '/company_statements/?time=' . time() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['results'] ) && is_array( $result['results'] ) ) {
			$first = reset( $result['results'] );

			if ( isset( $first['company_uid'] ) ) {
				return (string) $first['company_uid'];
			}
		}

		$created = $this->request(
			'POST',
			'/company_statements/',
			array(
				'format'   => 'csv',
				'timezone' => 'UTC',
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		if ( isset( $created['company_uid'] ) ) {
			return (string) $created['company_uid'];
		}

		return new WP_Error(
			'chip_no_company_uid',
			__( 'Unable to determine the CHIP company UID for these credentials.', 'chip-for-formidable-forms' )
		);
	}

	/**
	 * Register a webhook.
	 *
	 * @param array $params Webhook payload.
	 * @return array|WP_Error
	 */
	public function create_webhook( array $params ) {
		return $this->request( 'POST', '/webhooks/?time=' . time(), $params );
	}

	/**
	 * List registered webhooks.
	 *
	 * @return array|WP_Error
	 */
	public function get_webhooks() {
		return $this->request( 'GET', '/webhooks/?time=' . time() );
	}

	/**
	 * Perform an API call.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route beginning with a slash, including the API version prefix.
	 * @param array  $params Request body.
	 * @return array|string|WP_Error Decoded body, or WP_Error on failure.
	 */
	private function request( $method, $route, array $params = array() ) {
		$url = CHIP_FRM_API_ROOT_URL . '/api/v1' . $route;

		$args = array(
			'method'    => $method,
			'timeout'   => 30,
			'sslverify' => (bool) apply_filters( 'frm_chip_sslverify', true ),
			'headers'   => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->secret_key,
			),
		);

		if ( ! empty( $params ) ) {
			$args['body'] = wp_json_encode( $params );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$decoded = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'chip_api_error',
				FrmChipHelper::describe_api_error( $decoded, $code ),
				array(
					'status' => $code,
					'body'   => $decoded,
				)
			);
		}

		if ( null === $decoded ) {
			return new WP_Error(
				'chip_invalid_response',
				__( 'CHIP returned a response that could not be read.', 'chip-for-formidable-forms' )
			);
		}

		return $decoded;
	}
}

<?php
/**
 * Global settings model.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Stores the CHIP credentials and global purchase defaults.
 *
 * Mirrors the shape Formidable uses for its own gateway settings (a stdClass in
 * a single option) so the global settings section behaves the same way.
 */
class FrmChipSettings {

	/**
	 * Cached settings object.
	 *
	 * @var FrmChipSettings|null
	 */
	private static $instance;

	/**
	 * Settings values.
	 *
	 * @var stdClass
	 */
	public $settings;

	/**
	 * Get the shared settings instance.
	 *
	 * @return FrmChipSettings
	 */
	public static function get_settings() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = new stdClass();
		$this->set_defaults( $this->read_option() );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public function default_options() {
		return array(
			'test_mode'         => 0,
			'secret_key'        => '',
			'brand_id'          => '',
			'due_strict'        => 0,
			'due_strict_timing' => 60,
			'send_receipt'      => 0,
			'refund'            => 1,
			'whitelist_enabled' => 0,
			'whitelist'         => array(),
		);
	}

	/**
	 * Read the raw option.
	 *
	 * @return object|null
	 */
	private function read_option() {
		$settings = get_option( FRM_CHIP_OPTION );

		if ( is_object( $settings ) ) {
			return $settings;
		}

		if ( is_array( $settings ) ) {
			return (object) $settings;
		}

		return null;
	}

	/**
	 * Apply defaults around a set of stored values.
	 *
	 * @param object|null $settings Stored values.
	 * @return void
	 */
	private function set_defaults( $settings ) {
		foreach ( $this->default_options() as $key => $default ) {
			if ( is_object( $settings ) && isset( $settings->{$key} ) ) {
				$this->settings->{$key} = $settings->{$key};
				continue;
			}

			$this->settings->{$key} = $default;
		}
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( $key ) {
		return isset( $this->settings->{$key} ) ? $this->settings->{$key} : null;
	}

	/**
	 * Whether both credentials are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( (string) $this->get( 'secret_key' ) )
			&& '' !== trim( (string) $this->get( 'brand_id' ) );
	}

	/**
	 * Whether the account is in test mode.
	 *
	 * @return bool
	 */
	public function is_test_mode() {
		return (bool) $this->get( 'test_mode' );
	}

	/**
	 * Get the configured payment method whitelist, expanded to raw identifiers.
	 *
	 * An empty whitelist means "let CHIP decide", which is the default.
	 *
	 * @param object|null $action Optional payment action, for a per-form override.
	 * @return array
	 */
	public function get_whitelist( $action = null ) {
		$configured = $this->get_configured_whitelist( $action );

		if ( ! $configured ) {
			return array();
		}

		return FrmChipPaymentMethods::expand_groups( $configured );
	}

	/**
	 * Get the raw configured selection, before group expansion.
	 *
	 * Resolution order:
	 *
	 * 1. A per-form override with methods selected wins outright.
	 * 2. A per-form override set to "all methods" clears the whitelist entirely.
	 * 3. Otherwise the global setting applies.
	 *
	 * @param object|null $action Optional payment action carrying the override.
	 * @return array
	 */
	public function get_configured_whitelist( $action = null ) {
		$override = $this->get_action_override( $action );

		if ( 'custom' === $override ) {
			return $this->get_action_whitelist( $action );
		}

		if ( 'all' === $override ) {
			return array();
		}

		if ( ! $this->get( 'whitelist_enabled' ) ) {
			return array();
		}

		$configured = $this->get( 'whitelist' );

		if ( ! is_array( $configured ) || ! $configured ) {
			return array();
		}

		return $configured;
	}

	/**
	 * Read the per-form payment method mode from a payment action.
	 *
	 * @param object|null $action Payment action.
	 * @return string all|custom|global
	 */
	public function get_action_override( $action = null ) {
		$mode = $this->get_action_setting( $action, 'chip_payment_methods' );

		if ( ! in_array( $mode, array( 'all', 'custom' ), true ) ) {
			return 'global';
		}

		return $mode;
	}

	/**
	 * Read the per-form payment method selection from a payment action.
	 *
	 * @param object|null $action Payment action.
	 * @return array
	 */
	public function get_action_whitelist( $action = null ) {
		$selected = $this->get_action_setting( $action, 'chip_whitelist' );

		if ( ! is_array( $selected ) || ! $selected ) {
			return array();
		}

		return FrmChipPaymentMethods::sanitize_selection( $selected );
	}

	/**
	 * Read a single value from a payment action's post_content.
	 *
	 * @param object|null $action Payment action.
	 * @param string      $key    Setting name.
	 * @return mixed
	 */
	private function get_action_setting( $action, $key ) {
		if ( ! is_object( $action ) || ! isset( $action->post_content[ $key ] ) ) {
			return null;
		}

		return $action->post_content[ $key ];
	}

	/**
	 * Update the stored settings from a posted form.
	 *
	 * @param array $params Request data, expected to use the frm_chip_ prefix.
	 * @return void
	 */
	public function update( array $params ) {
		$this->settings->test_mode         = empty( $params['frm_chip_test_mode'] ) ? 0 : 1;
		$this->settings->due_strict        = empty( $params['frm_chip_due_strict'] ) ? 0 : 1;
		$this->settings->send_receipt      = empty( $params['frm_chip_send_receipt'] ) ? 0 : 1;
		$this->settings->refund            = empty( $params['frm_chip_refund'] ) ? 0 : 1;
		$this->settings->whitelist_enabled = empty( $params['frm_chip_whitelist_enabled'] ) ? 0 : 1;

		$this->settings->secret_key = isset( $params['frm_chip_secret_key'] )
			? sanitize_text_field( wp_unslash( $params['frm_chip_secret_key'] ) )
			: '';

		$this->settings->brand_id = isset( $params['frm_chip_brand_id'] )
			? sanitize_text_field( wp_unslash( $params['frm_chip_brand_id'] ) )
			: '';

		$timing = isset( $params['frm_chip_due_strict_timing'] )
			? absint( $params['frm_chip_due_strict_timing'] )
			: 0;

		// An unset timing with due_strict on would expire purchases instantly.
		$this->settings->due_strict_timing = $timing > 0 ? $timing : 60;

		$whitelist = isset( $params['frm_chip_whitelist'] ) ? (array) $params['frm_chip_whitelist'] : array();

		$this->settings->whitelist = FrmChipPaymentMethods::sanitize_selection( $whitelist );
	}

	/**
	 * Persist the settings.
	 *
	 * @return void
	 */
	public function store() {
		update_option( FRM_CHIP_OPTION, $this->settings );

		FrmChipPaymentMethods::clear_cache();
	}

	/**
	 * Delete stored settings.
	 *
	 * @return void
	 */
	public static function uninstall() {
		delete_option( FRM_CHIP_OPTION );

		$settings = self::get_settings();

		if ( $settings->is_configured() ) {
			$api    = FrmChipApi::get_instance( $settings->get( 'secret_key' ), $settings->get( 'brand_id' ) );
			$public = $api->get_company_uid();

			if ( ! is_wp_error( $public ) ) {
				delete_option( 'frm_chip_public_key_' . $public );
			}
		}
	}
}

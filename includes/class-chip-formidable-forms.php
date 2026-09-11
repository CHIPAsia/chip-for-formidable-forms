<?php
/**
 * Plugin loader.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Loads the plugin and wires the CHIP gateway into Formidable's payments layer.
 */
final class FrmChipFormidableForms {

	/**
	 * Singleton instance.
	 *
	 * @var FrmChipFormidableForms|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * @return FrmChipFormidableForms
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Load files and register hooks.
	 */
	private function __construct() {
		$this->includes();
		$this->init();
	}

	/**
	 * Include the plugin's classes.
	 *
	 * Public and static so the activation hook can reuse it: that hook fires
	 * after `plugins_loaded` has already passed for the request, so the normal
	 * bootstrap never runs.
	 *
	 * @return void
	 */
	public static function includes() {
		require_once FRM_CHIP_PATH . 'includes/class-chip-api.php';
		require_once FRM_CHIP_PATH . 'includes/class-chip-install.php';
		require_once FRM_CHIP_PATH . 'includes/class-chip-settlement.php';
		require_once FRM_CHIP_PATH . 'includes/class-chip-renewals.php';
		require_once FRM_CHIP_PATH . 'includes/helpers/class-chip-helper.php';
		require_once FRM_CHIP_PATH . 'includes/helpers/class-chip-payment-methods.php';
		require_once FRM_CHIP_PATH . 'includes/models/class-chip-settings.php';
		require_once FRM_CHIP_PATH . 'includes/controllers/class-chip-hooks-controller.php';
		require_once FRM_CHIP_PATH . 'includes/controllers/class-chip-app-controller.php';
		require_once FRM_CHIP_PATH . 'includes/controllers/class-chip-actions-controller.php';
		require_once FRM_CHIP_PATH . 'includes/controllers/class-chip-callback-controller.php';
		require_once FRM_CHIP_PATH . 'includes/controllers/class-chip-return-controller.php';
		require_once FRM_CHIP_PATH . 'includes/controllers/class-chip-payments-controller.php';
		require_once FRM_CHIP_PATH . 'includes/controllers/class-chip-subscriptions-controller.php';

		if ( is_admin() ) {
			require_once FRM_CHIP_PATH . 'includes/controllers/class-chip-settings-controller.php';
		}
	}

	/**
	 * Register hooks.
	 *
	 * The actions controller has to be defined before the gateway filter runs,
	 * because Formidable resolves a gateway's `class` setting by concatenating a
	 * class name and calling it statically.
	 *
	 * @return void
	 */
	private function init() {
		FrmChipHooksController::load_hooks();

		if ( is_admin() ) {
			FrmChipHooksController::load_admin_hooks();
		}

		add_filter( 'plugin_action_links_' . FRM_CHIP_BASENAME, array( $this, 'settings_link' ) );
	}

	/**
	 * Add a Settings link on the plugins list.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function settings_link( $links ) {
		$url = admin_url( 'admin.php?page=formidable-settings&t=chip_settings' );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'chip-for-formidable-forms' ) . '</a>'
		);

		return $links;
	}
}

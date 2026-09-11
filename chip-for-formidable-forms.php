<?php
/**
 * Plugin Name: CHIP for Formidable Forms
 * Plugin URI: https://wordpress.org/plugins/chip-for-formidable-forms/
 * Description: Accept FPX, cards, e-wallets and DuitNow QR payments in Formidable Forms with CHIP.
 * Version: 1.0.0
 * Author: Chip In Sdn Bhd
 * Author URI: https://www.chip-in.asia
 * Requires PHP: 7.4
 * Requires at least: 6.3
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright: (c) 2026 CHIP
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

define( 'FRM_CHIP_MODULE_VERSION', 'v1.0.0' );
define( 'FRM_CHIP_FILE', __FILE__ );
define( 'FRM_CHIP_BASENAME', plugin_basename( FRM_CHIP_FILE ) );
define( 'FRM_CHIP_PATH', plugin_dir_path( FRM_CHIP_FILE ) );
define( 'FRM_CHIP_URL', plugin_dir_url( FRM_CHIP_FILE ) );

// CHIP API endpoint, as documented at https://docs.chip-in.asia.
define( 'CHIP_FRM_API_ROOT_URL', 'https://gate.chip-in.asia' );

// Option name and settings param used by FrmChipSettings.
define( 'FRM_CHIP_OPTION', 'frm_chip_options' );

require_once FRM_CHIP_PATH . 'includes/class-chip-formidable-forms.php';

/**
 * Boot the plugin once Formidable Forms is known to be loaded.
 *
 * Formidable loads its own classes on `plugins_loaded` priority 0, and the
 * payments layer (which provides FrmTransLiteActionsController and the
 * frm_payment_gateways filter) ships with the free plugin. Booting on a later
 * priority guarantees those classes exist before we register anything.
 *
 * @return void
 */
function frm_chip_bootstrap() {
	if ( ! class_exists( 'FrmTransLiteActionsController' ) ) {
		add_action( 'admin_notices', 'frm_chip_missing_formidable_notice' );
		return;
	}

	FrmChipFormidableForms::get_instance();
}
add_action( 'plugins_loaded', 'frm_chip_bootstrap', 20 );

/**
 * Warn when Formidable Forms is missing or too old.
 *
 * @return void
 */
function frm_chip_missing_formidable_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'CHIP for Formidable Forms requires Formidable Forms 6.35 or newer to be installed and active.',
			'chip-for-formidable-forms'
		)
	);
}

register_activation_hook( FRM_CHIP_FILE, 'frm_chip_activate' );

/**
 * Run install routines on activation.
 *
 * The activation hook fires after `plugins_loaded` has already passed for the
 * request, so the normal bootstrap does not run. The includes are loaded here
 * explicitly.
 *
 * @return void
 */
function frm_chip_activate() {
	if ( ! class_exists( 'FrmTransLiteActionsController' ) ) {
		return;
	}

	FrmChipFormidableForms::includes();

	FrmChipInstall::activate();
}

/*
 * Deliberately no uninstall hook.
 *
 * Uninstalling leaves the stored credentials and cached CHIP public key in
 * place, matching CHIP for Gravity Forms. Reinstalling therefore restores a
 * working connection instead of silently dropping the merchant's settings, and
 * a merchant who reinstalls to retry a failed setup is not locked out of the
 * CHIP dashboard.
 *
 * Nothing here writes rows to Formidable's own tables, so there is no orphaned
 * data beyond the two options above.
 */

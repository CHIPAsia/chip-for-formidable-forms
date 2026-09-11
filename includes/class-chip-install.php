<?php
/**
 * Activation routines.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

/**
 * Handles first-run setup.
 *
 * Formidable owns the payments tables and upgrades them itself, so there is no
 * schema of our own to create. Activation only makes sure the shared payments
 * tables exist and that the settings option is seeded.
 */
class FrmChipInstall {

	/**
	 * Option recording the installed plugin version.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'frm_chip_version';

	/**
	 * Run on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_payment_tables();
		self::seed_settings();

		// Recurring charges are issued by the site, so the renewal check has to
		// be scheduled from the moment the plugin is active.
		FrmChipRenewals::maybe_schedule();

		update_option( self::VERSION_OPTION, FRM_CHIP_MODULE_VERSION );
	}

	/**
	 * Run on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		FrmChipRenewals::unschedule();
	}

	/**
	 * Create the shared payments tables if Formidable has not done so yet.
	 *
	 * Creating a purchase requires the `frm_payments` table, and Formidable only
	 * creates it once its own payment action has been used. Forcing the upgrade
	 * here means the first CHIP payment does not fail on a fresh site.
	 *
	 * @return void
	 */
	private static function ensure_payment_tables() {
		if ( ! class_exists( 'FrmTransLiteDb' ) ) {
			return;
		}

		if ( FrmTransLiteAppHelper::payments_table_exists() ) {
			return;
		}

		$db = new FrmTransLiteDb();
		$db->upgrade();
	}

	/**
	 * Seed the settings option so the global section renders with defaults.
	 *
	 * @return void
	 */
	private static function seed_settings() {
		if ( false !== get_option( FRM_CHIP_OPTION ) ) {
			return;
		}

		$settings = FrmChipSettings::get_settings();
		$settings->store();
	}

	/**
	 * Run pending upgrade routines when the plugin is updated in place.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::VERSION_OPTION );

		if ( FRM_CHIP_MODULE_VERSION === $installed ) {
			return;
		}

		self::ensure_payment_tables();
		update_option( self::VERSION_OPTION, FRM_CHIP_MODULE_VERSION );
	}
}

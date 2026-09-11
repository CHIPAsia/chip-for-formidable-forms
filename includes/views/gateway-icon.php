<?php
/**
 * CHIP gateway icon for Formidable's payment action.
 *
 * Formidable renders each gateway tab's icon by building the class
 * `frm_<gateway>_full_icon` and resolving it as a reference into its own SVG
 * sprite (see stripe/views/action-settings/gateway-buttons.php). That sprite has
 * no CHIP symbol, so the CHIP tab would render as an empty box next to the
 * Stripe, Square and PayPal wordmarks.
 *
 * The sprite is emitted with readfile() and exposes no filter, so the CHIP
 * symbol is printed into the admin footer instead. An SVG <use> reference
 * resolves by element id, so a symbol declared after the sprite still resolves.
 *
 * The markup lives in assets/gateway-icon.svg so the path data stays out of PHP
 * source; this file only prints it.
 *
 * @package FormidableCHIP
 */

defined( 'ABSPATH' ) || die();

$frm_chip_icon_file = FRM_CHIP_PATH . 'assets/gateway-icon.svg';

if ( ! is_readable( $frm_chip_icon_file ) ) {
	return;
}

// The file is a static plugin asset with no user input, so it is printed as-is.
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
readfile( $frm_chip_icon_file );

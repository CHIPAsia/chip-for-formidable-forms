<?php
/**
 * Global settings form.
 *
 * @package FormidableCHIP
 *
 * @var FrmChipSettings $settings Plugin settings.
 * @var array           $status   Account status probe result.
 */

defined( 'ABSPATH' ) || die();

$frm_chip_options = FrmChipPaymentMethods::get_options();
$frm_chip_current = (array) $settings->get( 'whitelist' );
?>
<div class="frm_grid_container">
	<h3><?php esc_html_e( 'CHIP', 'chip-for-formidable-forms' ); ?></h3>

	<p class="frm6">
		<label for="frm_chip_secret_key"><?php esc_html_e( 'Secret Key', 'chip-for-formidable-forms' ); ?></label>
		<input type="password"
			name="frm_chip_secret_key"
			id="frm_chip_secret_key"
			class="large-text"
			value="<?php echo esc_attr( $settings->get( 'secret_key' ) ); ?>"
			autocomplete="off" />
	</p>

	<p class="frm6">
		<label for="frm_chip_brand_id"><?php esc_html_e( 'Brand ID', 'chip-for-formidable-forms' ); ?></label>
		<input type="text"
			name="frm_chip_brand_id"
			id="frm_chip_brand_id"
			class="large-text"
			value="<?php echo esc_attr( $settings->get( 'brand_id' ) ); ?>" />
	</p>
</div>

<p>
	<label>
		<input type="checkbox" name="frm_chip_test_mode" value="1" <?php checked( $settings->is_test_mode() ); ?> />
		<?php esc_html_e( 'Test mode', 'chip-for-formidable-forms' ); ?>
	</label>
	<span class="frm_sub_label">
		<?php esc_html_e( 'Payments are simulated. Use this with a test secret key only.', 'chip-for-formidable-forms' ); ?>
	</span>
</p>

<div class="frm_grid_container">
	<h3><?php esc_html_e( 'Purchase settings', 'chip-for-formidable-forms' ); ?></h3>

	<p class="frm6">
		<label>
			<input type="checkbox" name="frm_chip_send_receipt" value="1"
				<?php checked( $settings->get( 'send_receipt' ) ); ?> />
			<?php esc_html_e( 'Send receipt', 'chip-for-formidable-forms' ); ?>
		</label>
		<span class="frm_sub_label">
			<?php esc_html_e( 'CHIP emails a receipt to the payer once the payment completes.', 'chip-for-formidable-forms' ); ?>
		</span>
	</p>

	<p class="frm6">
		<label>
			<input type="checkbox" name="frm_chip_due_strict" value="1" <?php checked( $settings->get( 'due_strict' ) ); ?> />
			<?php esc_html_e( 'Due strict', 'chip-for-formidable-forms' ); ?>
		</label>
		<span class="frm_sub_label">
			<?php esc_html_e( 'Block payment once the due time has passed.', 'chip-for-formidable-forms' ); ?>
		</span>
	</p>

	<p class="frm6">
		<label for="frm_chip_due_strict_timing">
			<?php esc_html_e( 'Due strict timing', 'chip-for-formidable-forms' ); ?>
		</label>
		<input type="number"
			min="1"
			name="frm_chip_due_strict_timing"
			id="frm_chip_due_strict_timing"
			value="<?php echo esc_attr( $settings->get( 'due_strict_timing' ) ); ?>" />
		<span class="frm_sub_label"><?php esc_html_e( 'minutes', 'chip-for-formidable-forms' ); ?></span>
	</p>

	<p class="frm6">
		<label>
			<input type="checkbox" name="frm_chip_refund" value="1" <?php checked( $settings->get( 'refund' ) ); ?> />
			<?php esc_html_e( 'Allow refunds', 'chip-for-formidable-forms' ); ?>
		</label>
		<span class="frm_sub_label">
			<?php esc_html_e( 'Show a refund action on completed CHIP payments.', 'chip-for-formidable-forms' ); ?>
		</span>
	</p>
</div>

<div class="frm_grid_container">
	<h3><?php esc_html_e( 'Payment methods', 'chip-for-formidable-forms' ); ?></h3>

	<p>
		<label>
			<input type="checkbox" name="frm_chip_whitelist_enabled" value="1"
				<?php checked( $settings->get( 'whitelist_enabled' ) ); ?> />
			<?php esc_html_e( 'Limit the payment methods offered at checkout', 'chip-for-formidable-forms' ); ?>
		</label>
		<span class="frm_sub_label">
			<?php esc_html_e( 'Leave this off to offer every method enabled for your brand.', 'chip-for-formidable-forms' ); ?>
		</span>
	</p>

	<p>
		<?php foreach ( $frm_chip_options as $frm_chip_key => $frm_chip_label ) { ?>
			<label class="frm_inline_label">
				<input type="checkbox"
					name="frm_chip_whitelist[]"
					value="<?php echo esc_attr( $frm_chip_key ); ?>"
					<?php checked( in_array( $frm_chip_key, $frm_chip_current, true ) ); ?> />
				<?php echo esc_html( $frm_chip_label ); ?>
			</label>
		<?php } ?>
	</p>

	<p class="frm_sub_label">
		<?php
		esc_html_e(
			'Recurring payments always use card networks, because charging again needs a saved card.',
			'chip-for-formidable-forms'
		);
		?>
	</p>
</div>

<p class="frm_chip_status frm_chip_status--<?php echo esc_attr( $status['state'] ); ?>">
	<strong><?php esc_html_e( 'Status:', 'chip-for-formidable-forms' ); ?></strong>
	<?php echo esc_html( $status['message'] ); ?>
</p>

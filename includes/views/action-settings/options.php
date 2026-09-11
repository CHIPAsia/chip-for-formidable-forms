<?php
/**
 * CHIP section of the payment action settings.
 *
 * @package FormidableCHIP
 *
 * @var WP_Post      $form_action    Form action.
 * @var FrmFormAction $action_control Action control.
 * @var array        $form_fields    Fields available for mapping.
 * @var FrmChipSettings $settings    Plugin settings.
 * @var bool         $is_configured  Whether credentials are present.
 * @var bool         $is_recurring   Whether the action is recurring.
 */

defined( 'ABSPATH' ) || die();

$frm_chip_text_field = function ( $name, $label, $value ) use ( $action_control, $form_fields ) {
	?>
	<p class="frm6 show_chip">
		<label for="<?php echo esc_attr( $action_control->get_field_id( $name ) ); ?>">
			<?php echo esc_html( $label ); ?>
		</label>
		<select name="<?php echo esc_attr( $action_control->get_field_name( $name ) ); ?>"
			id="<?php echo esc_attr( $action_control->get_field_id( $name ) ); ?>">
			<option value=""><?php esc_html_e( '&mdash; Select &mdash;', 'chip-for-formidable-forms' ); ?></option>
			<?php foreach ( $form_fields as $frm_chip_field ) { ?>
				<option value="<?php echo esc_attr( $frm_chip_field->id ); ?>"
					<?php selected( (int) $value, (int) $frm_chip_field->id ); ?>>
					<?php echo esc_html( FrmAppHelper::truncate( $frm_chip_field->name, 50, 1 ) ); ?>
				</option>
			<?php } ?>
		</select>
	</p>
	<?php
};
?>

<div class="frm_trans_sub_opts frm_grid_container show_chip<?php echo $is_recurring ? '' : ' frm_hidden'; ?>">
	<div class="frm_grid_container">
		<h3><?php esc_html_e( 'CHIP', 'chip-for-formidable-forms' ); ?></h3>

		<?php if ( ! $is_configured ) { ?>
			<div class="frm_warning_style frm-with-icon">
				<?php FrmAppHelper::icon_by_class( 'frmfont frm_alert_icon', array( 'style' => 'width:24px' ) ); ?>
				<span>
					<?php
					printf(
						/* translators: %s: settings page URL. */
						esc_html__( 'CHIP is not configured. %s to add credentials.', 'chip-for-formidable-forms' ),
						'<a href="' . esc_url( FrmChipAppController::get_settings_url() ) . '">'
						. esc_html__( 'Open CHIP settings', 'chip-for-formidable-forms' )
						. '</a>'
					);
					?>
				</span>
			</div>
		<?php } ?>

		<?php
		$frm_chip_text_field(
			'chip_billing_email',
			__( 'Email', 'chip-for-formidable-forms' ),
			$form_action->post_content['chip_billing_email'] ?? ''
		);
		?>

		<p class="frm6 show_chip">
			<label for="<?php echo esc_attr( $action_control->get_field_id( 'chip_product_name' ) ); ?>">
				<?php esc_html_e( 'Product name', 'chip-for-formidable-forms' ); ?>
			</label>
			<input type="text"
				name="<?php echo esc_attr( $action_control->get_field_name( 'chip_product_name' ) ); ?>"
				id="<?php echo esc_attr( $action_control->get_field_id( 'chip_product_name' ) ); ?>"
				class="large-text"
				placeholder="<?php echo esc_attr( $form_action->post_content['description'] ?? '' ); ?>"
				value="<?php echo esc_attr( $form_action->post_content['chip_product_name'] ?? '' ); ?>" />
		</p>

		<?php
		$frm_chip_text_field(
			'chip_billing_first_name',
			__( 'First name', 'chip-for-formidable-forms' ),
			$form_action->post_content['chip_billing_first_name'] ?? ''
		);

		$frm_chip_text_field(
			'chip_billing_last_name',
			__( 'Last name', 'chip-for-formidable-forms' ),
			$form_action->post_content['chip_billing_last_name'] ?? ''
		);

		$frm_chip_text_field(
			'chip_billing_address',
			__( 'Address', 'chip-for-formidable-forms' ),
			$form_action->post_content['chip_billing_address'] ?? ''
		);

		$frm_chip_text_field(
			'chip_reference',
			__( 'Reference', 'chip-for-formidable-forms' ),
			$form_action->post_content['chip_reference'] ?? ''
		);
		?>

		<p class="frm_sub_label show_chip">
			<?php
			esc_html_e(
				'These values are sent to CHIP with the purchase. Unset values fall back to the entry ID.',
				'chip-for-formidable-forms'
			);
			?>
		</p>

		<?php if ( $is_recurring ) { ?>
			<p class="frm_sub_label show_chip">
				<?php
				esc_html_e(
					'Recurring payments charge a saved card. CHIP does not renew on its own: Formidable charges it.',
					'chip-for-formidable-forms'
				);
				?>
			</p>
		<?php } ?>
	</div>
</div>

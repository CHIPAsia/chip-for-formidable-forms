<?php
/**
 * CHIP subscriptions list.
 *
 * @package FormidableCHIP
 *
 * @var array  $frm_chip_rows     Subscription rows with their renewal state.
 * @var string $frm_chip_msg      Notice text.
 * @var string $frm_chip_msg_type Notice type, success or error.
 */

defined( 'ABSPATH' ) || die();

$frm_chip_status_labels = array(
	'active'        => __( 'Active', 'chip-for-formidable-forms' ),
	'failed'        => __( 'Failed', 'chip-for-formidable-forms' ),
	'pending'       => __( 'Pending', 'chip-for-formidable-forms' ),
	'future_cancel' => __( 'Cancelled', 'chip-for-formidable-forms' ),
	'canceled'      => __( 'Cancelled', 'chip-for-formidable-forms' ),
);
?>
<div class="frm_wrap">
	<div class="frm_page_container">
		<div class="frm_top_bar">
			<h1><?php esc_html_e( 'CHIP Subscriptions', 'chip-for-formidable-forms' ); ?></h1>
		</div>

		<?php if ( '' !== $frm_chip_msg ) { ?>
			<div class="<?php echo 'success' === $frm_chip_msg_type ? 'frm_message' : 'frm_error_style'; ?>">
				<?php echo esc_html( $frm_chip_msg ); ?>
			</div>
		<?php } ?>

		<p class="description">
			<?php
			esc_html_e(
				'Recurring payments are charged from this site. A failed charge is retried on days 3, 5 and 7.',
				'chip-for-formidable-forms'
			);
			?>
		</p>

		<?php if ( ! $frm_chip_rows ) { ?>
			<div class="frm_no_items" style="margin-top:20px;">
				<?php esc_html_e( 'No CHIP subscriptions yet.', 'chip-for-formidable-forms' ); ?>
			</div>
		<?php } else { ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Status', 'chip-for-formidable-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Payer', 'chip-for-formidable-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Form', 'chip-for-formidable-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount', 'chip-for-formidable-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Renewal', 'chip-for-formidable-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Attempts left', 'chip-for-formidable-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'chip-for-formidable-forms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $frm_chip_rows as $frm_chip_row ) { ?>
						<?php
						$frm_chip_sub   = $frm_chip_row['subscription'];
						$frm_chip_entry = $frm_chip_row['entry'];

						$frm_chip_entry_url = $frm_chip_entry
							? admin_url(
								'admin.php?page=formidable-entries&frm_action=show&action=show&id='
								. (int) $frm_chip_entry->id
							)
							: '';

						$frm_chip_label = isset( $frm_chip_status_labels[ (string) $frm_chip_sub->status ] )
							? $frm_chip_status_labels[ (string) $frm_chip_sub->status ]
							: ucfirst( str_replace( '_', ' ', (string) $frm_chip_sub->status ) );
						?>
						<tr>
							<td><?php echo esc_html( $frm_chip_label ); ?></td>
							<td>
								<?php if ( $frm_chip_entry_url ) { ?>
									<a href="<?php echo esc_url( $frm_chip_entry_url ); ?>">
										<?php
										echo esc_html(
											$frm_chip_entry->name
												? $frm_chip_entry->name
												: __( '(no name)', 'chip-for-formidable-forms' )
										);
										?>
									</a>
								<?php } else { ?>
									<?php esc_html_e( '(entry deleted)', 'chip-for-formidable-forms' ); ?>
								<?php } ?>
								<?php if ( '' !== $frm_chip_row['payer'] ) { ?>
									<span class="description">
										<?php echo esc_html( $frm_chip_row['payer'] ); ?>
									</span>
								<?php } ?>
							</td>
							<td>
								<?php
								echo $frm_chip_row['form']
									? esc_html( $frm_chip_row['form']->name )
									: '&mdash;';
								?>
							</td>
							<td><?php echo esc_html( FrmChipHelper::format_amount( $frm_chip_sub->amount ) ); ?></td>
							<td>
								<?php
								echo '' !== $frm_chip_row['state']
									? esc_html( $frm_chip_row['state'] )
									: '&mdash;';
								?>
							</td>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: number of attempts remaining. */
										_n(
											'%d attempt',
											'%d attempts',
											$frm_chip_row['remaining'],
											'chip-for-formidable-forms'
										),
										$frm_chip_row['remaining']
									)
								);
								?>
							</td>
							<td>
								<?php if ( $frm_chip_row['can_retry'] ) { ?>
									<?php
									$frm_chip_retry_url = wp_nonce_url(
										add_query_arg(
											array(
												'page' => FrmChipSubscriptionsController::PAGE_SLUG,
												'frmchip_retry' => (int) $frm_chip_sub->id,
											),
											admin_url( 'admin.php' )
										),
										'frm_chip_retry_' . (int) $frm_chip_sub->id
									);
									?>
									<a href="<?php echo esc_url( $frm_chip_retry_url ); ?>" class="button button-small">
										<?php esc_html_e( 'Retry now', 'chip-for-formidable-forms' ); ?>
									</a>
								<?php } else { ?>
									&mdash;
								<?php } ?>
							</td>
						</tr>
					<?php } ?>
				</tbody>
			</table>
		<?php } ?>
	</div>
</div>

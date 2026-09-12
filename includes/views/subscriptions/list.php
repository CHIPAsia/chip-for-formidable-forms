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

		<?php
		// A retry performed from this screen reloads the page, so the outcome is
		// announced by that navigation. This region exists so the sidebar's
		// in-place retry has somewhere to report its result to assistive tech.
		?>
		<div class="frm_chip_live_region" aria-live="polite" role="status"></div>

		<?php if ( ! $frm_chip_rows ) { ?>
			<div class="frm_no_items" style="margin-top:20px;">
				<?php esc_html_e( 'No CHIP subscriptions yet.', 'chip-for-formidable-forms' ); ?>
			</div>
		<?php } else { ?>
			<?php if ( $frm_chip_pages > 1 ) { ?>
				<div class="tablenav top">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of subscriptions. */
									_n(
										'%s subscription',
										'%s subscriptions',
										$frm_chip_total,
										'chip-for-formidable-forms'
									),
									number_format_i18n( $frm_chip_total )
								)
							);
							?>
						</span>
						<?php
						$frm_chip_links = paginate_links(
							array(
								'base'      => add_query_arg( 'frmchip_page', '%#%' ),
								'format'    => '',
								'current'   => (int) $frm_chip_page,
								'total'     => (int) $frm_chip_pages,
								'type'      => 'plain',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						);

						echo wp_kses_post( $frm_chip_links );
						?>
					</div>
				</div>
			<?php } ?>
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
							<?php
							// The status is the row's header: it identifies the row for a
							// screen reader, so a cell read out of context still makes sense.
							?>
							<th scope="row"><?php echo esc_html( $frm_chip_label ); ?></th>
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
									<?php
									// Every row's link reads "Retry now", so without a
									// distinguishing name a screen reader announces the
									// same label for each row and the merchant cannot
									// tell which subscription they are about to charge.
									// The payer and status can repeat across rows (the
									// same payer with two active subscriptions), so the
									// subscription id is what makes it unique.
									$frm_chip_who = $frm_chip_entry && $frm_chip_entry->name
										? $frm_chip_entry->name
										: sprintf(
											/* translators: %d: entry id. */
											__( 'entry %d', 'chip-for-formidable-forms' ),
											$frm_chip_entry ? (int) $frm_chip_entry->id : 0
										);

									$frm_chip_retry_label = sprintf(
										/* translators: 1: payer name, 2: status, 3: subscription id. */
										__( 'Retry now for %1$s (%2$s), sub %3$d', 'chip-for-formidable-forms' ),
										$frm_chip_who,
										$frm_chip_label,
										(int) $frm_chip_sub->id
									);
									?>
									<a href="<?php echo esc_url( $frm_chip_retry_url ); ?>"
										class="button button-small"
										aria-label="<?php echo esc_attr( $frm_chip_retry_label ); ?>">
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

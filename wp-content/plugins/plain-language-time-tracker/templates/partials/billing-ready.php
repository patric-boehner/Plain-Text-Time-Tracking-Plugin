<?php
/**
 * Billing · Ready to bill — outstanding work grouped by client.
 *
 * Summary cards (Outstanding / Billed this month / Absorbed this month) over a
 * table of per-project "Review & bill" scopes (client / project / type / covered
 * range / amount). Each row links INTO the detailed entries view (bill=1) where
 * selection + commit happen; this view lists no entries.
 *
 * Expects $queue (PLTT_Billing::get_invoicing_queue), $mtd
 * (PLTT_Billing::get_billed_totals for this month) and $reports_base in scope.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$q_clients = isset( $queue['clients'] ) ? $queue['clients'] : array();
?>

<div class="pltt-summary-cards pltt-numbar pltt-bill-cards-summary">
	<div class="card">
		<div class="card-label"><?php esc_html_e( 'Outstanding', 'plain-language-time-tracker' ); ?></div>
		<div class="card-value pltt-bill-outstanding-value"><?php echo esc_html( pltt_format_currency( (float) $queue['grand_total'] ) ); ?></div>
		<div class="card-secondary">
			<?php
			printf(
				/* translators: 1: number of billable items; 2: number of clients. */
				esc_html__( '%1$s to bill · %2$s clients', 'plain-language-time-tracker' ),
				esc_html( number_format_i18n( (int) $queue['scope_count'] ) ),
				esc_html( number_format_i18n( count( $q_clients ) ) )
			);
			?>
		</div>
	</div>

	<div class="card">
		<div class="card-label"><?php esc_html_e( 'Billed this month', 'plain-language-time-tracker' ); ?></div>
		<div class="card-value"><?php echo esc_html( pltt_format_currency( (float) $mtd['billed'] ) ); ?></div>
		<div class="card-secondary">
			<?php
			printf(
				/* translators: %s: number of billing records this month. */
				esc_html( _n( '%s record', '%s records', (int) $mtd['count'], 'plain-language-time-tracker' ) ),
				esc_html( number_format_i18n( (int) $mtd['count'] ) )
			);
			?>
		</div>
	</div>

	<div class="card">
		<div class="card-label"><?php esc_html_e( 'Absorbed this month', 'plain-language-time-tracker' ); ?></div>
		<div class="card-value pltt-bill-absorbed-value"><?php echo esc_html( pltt_format_currency( (float) $mtd['absorbed'] ) ); ?></div>
		<div class="card-secondary"><?php esc_html_e( 'written down at bill time', 'plain-language-time-tracker' ); ?></div>
	</div>
</div>

<?php if ( empty( $q_clients ) ) : ?>
	<?php
	pltt_render_empty_state(
		__( 'Nothing outstanding to bill.', 'plain-language-time-tracker' ),
		__( 'When a retainer runs over, an hourly project has unbilled time, or a fixed budget is ready, it shows up here.', 'plain-language-time-tracker' )
	);
	?>
<?php else : ?>
	<table class="widefat striped pltt-bill-ready-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Type', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Covered', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-amount-col"><?php esc_html_e( 'Amount', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-bill-ready-action"></th>
			</tr>
		</thead>
		<tbody>
			<?php $seen_proj = array(); // First row per project gets an anchor id (a project can have several period rows). ?>
			<?php foreach ( $q_clients as $group ) : ?>
				<?php $client_name = $group['client'] ? $group['client']->name : __( '(Unknown client)', 'plain-language-time-tracker' ); ?>
				<?php foreach ( $group['scopes'] as $scope ) : ?>
					<?php
					$v    = pltt_build_billing_scope_view( $scope, $client_name );
					$proj = $scope['project'];

					// Anchor target for the Overview backlog link — only the first row
					// of each project (ids must be unique).
					$row_id = '';
					if ( ! isset( $seen_proj[ (int) $proj->id ] ) ) {
						$seen_proj[ (int) $proj->id ] = true;
						$row_id                       = 'pltt-bill-proj-' . (int) $proj->id;
					}

					// Open the detailed view on the range this scope bills, so all the
					// billable work is in view: retainer = its period; hourly/fixed = the
					// span of the unbilled entries through today.
					if ( 'retainer_overage' === $scope['billing_type'] ) {
						$range_from = (string) $scope['period_start'];
						$range_to   = (string) $scope['period_end'];
					} else {
						$range_from = pltt_get_current_date();
						foreach ( $scope['entries'] as $e ) {
							if ( $e->entry_date < $range_from ) {
								$range_from = $e->entry_date;
							}
						}
						$range_to = pltt_get_current_date();
					}
					$review_url = add_query_arg(
						array(
							'page'       => 'pltt-reports',
							'view'       => 'detailed',
							'client_id'  => (int) $proj->client_id,
							'project_id' => (int) $proj->id,
							'from'       => $range_from,
							'to'         => $range_to,
							'bill'       => 1, // Explicit "start a bill" flag — invokes the select row.
						),
						$reports_base
					);
					?>
					<tr class="pltt-bill-ready-row"<?php echo $row_id ? ' id="' . esc_attr( $row_id ) . '"' : ''; ?>>
						<td><?php echo esc_html( $client_name ); ?></td>
						<td class="pltt-bill-ready-proj"><?php echo esc_html( $proj->name ); ?></td>
						<td>
							<span class="pltt-badge <?php echo esc_attr( pltt_billing_type_badge_class( $scope['billing_type'] ) ); ?>"><?php echo esc_html( $v['type_label'] ); ?></span>
						</td>
						<td class="pltt-bill-ready-covered">
						<?php echo esc_html( $v['date_range'] ); ?>
						<?php if ( $v['count'] > 0 ) : ?>
							<span class="pltt-bill-ready-count">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: number of unbilled entries covered. */
										_n( '· %d entry', '· %d entries', $v['count'], 'plain-language-time-tracker' ),
										(int) $v['count']
									)
								);
								?>
							</span>
						<?php endif; ?>
					</td>
						<td class="pltt-amount-col"><?php echo esc_html( pltt_format_currency( $scope['unbilled'] ) ); ?></td>
						<td class="pltt-bill-ready-action">
							<a class="pltt-bill-ready-link" href="<?php echo esc_url( $review_url ); ?>">
								<?php esc_html_e( 'Bill', 'plain-language-time-tracker' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

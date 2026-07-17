<?php
/**
 * Billing — the ledger + doorway.
 *
 * Two sections, always shown together:
 *   1. Outstanding — everything ready to bill, grouped by client, one card per
 *      project scope (hourly, retainer overage, fixed budget). Each card's
 *      "Review & bill" links INTO the detailed entries view, filtered to that
 *      project, where selection + commit happen. This page lists no entries.
 *   2. Billing records — the frozen record of what's already been billed.
 *      "View line items" reopens the copyable description; no sent/paid tracking.
 *
 * Rendered by PLTT_Admin::render_invoicing_page(). Expects $queue
 * (PLTT_Billing::get_invoicing_queue()) and $log (PLTT_Billing::get_invoiced_log()).
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$reports_base = admin_url( 'admin.php' );
?>

<div class="wrap pltt-wrap pltt-billing-ledger">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Billing', 'plain-language-time-tracker' ); ?></h1>
	</div>
	<p class="pltt-bill-subhead">
		<?php esc_html_e( 'What’s owed, and what you’ve billed. You bill entries where you verify them, so this page lists no entries — just who owes you and the record of past bills.', 'plain-language-time-tracker' ); ?>
	</p>

	<?php // ============ OUTSTANDING ============ ?>
	<h2 class="pltt-bill-sectitle"><?php esc_html_e( 'Outstanding — ready to bill', 'plain-language-time-tracker' ); ?></h2>

	<?php if ( empty( $queue['clients'] ) ) : ?>
		<div class="pltt-card pltt-bill-empty">
			<p class="pltt-report-placeholder-lead"><?php esc_html_e( 'Nothing outstanding to bill.', 'plain-language-time-tracker' ); ?></p>
			<p class="description"><?php esc_html_e( 'When a retainer runs over, an hourly project has unbilled time, or a fixed budget is ready, it shows up here.', 'plain-language-time-tracker' ); ?></p>
		</div>
	<?php else : ?>
		<p class="pltt-bill-lead">
			<strong><?php echo esc_html( pltt_format_currency( $queue['grand_total'] ) ); ?></strong>
			<?php esc_html_e( 'outstanding', 'plain-language-time-tracker' ); ?>
			· <?php echo (int) $queue['scope_count']; ?> <?php esc_html_e( 'to bill', 'plain-language-time-tracker' ); ?>
			· <?php echo (int) count( $queue['clients'] ); ?> <?php esc_html_e( 'clients', 'plain-language-time-tracker' ); ?>
		</p>

		<?php $seen_proj = array(); // First card per project gets an anchor id (a project can have several period cards). ?>
		<?php foreach ( $queue['clients'] as $group ) : ?>
			<?php $client_name = $group['client'] ? $group['client']->name : __( '(Unknown client)', 'plain-language-time-tracker' ); ?>
			<section class="pltt-bill-clientgroup">
				<header class="pltt-bill-clienthead">
					<h3 class="pltt-bill-clientname"><?php echo esc_html( $client_name ); ?></h3>
					<span class="pltt-bill-clienttotal">
						<?php
						printf(
							/* translators: %s: client's outstanding total, e.g. "$2,730". */
							esc_html__( '%s outstanding', 'plain-language-time-tracker' ),
							esc_html( pltt_format_currency( $group['total'] ) )
						);
						?>
					</span>
				</header>

				<div class="pltt-bill-cards">
					<?php foreach ( $group['scopes'] as $scope ) : ?>
						<?php
						$v          = pltt_build_billing_scope_view( $scope, $client_name );
						$proj       = $scope['project'];
						$is_hourly  = ( 'hourly' === $scope['billing_type'] );

						// Anchor target for the Insights backlog notice — only the first
						// card of each project (ids must be unique).
						$card_id = '';
						if ( ! isset( $seen_proj[ (int) $proj->id ] ) ) {
							$seen_proj[ (int) $proj->id ] = true;
							$card_id                      = 'pltt-bill-proj-' . (int) $proj->id;
						}

						// Open the detailed view on the range this scope bills, so all the
						// billable work is in view: retainer = its period; hourly/fixed =
						// the span of the unbilled entries through today. (Same rule as the
						// Insights review card in project-billing-section.php.)
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

						// One-line basis: hourly names its entry count; retainer/fixed
						// name their scope (period overage / project).
						if ( $is_hourly ) {
							$basis = sprintf(
								/* translators: %d: number of unbilled entries. */
								_n( '%d unbilled entry', '%d unbilled entries', $v['count'], 'plain-language-time-tracker' ),
								$v['count']
							);
						} else {
							$basis = $v['derivation'];
						}
						?>
						<div class="pltt-bill-card"<?php echo $card_id ? ' id="' . esc_attr( $card_id ) . '"' : ''; ?>>
							<div class="pltt-bill-card-head">
								<span class="pltt-bill-card-proj"><?php echo esc_html( $proj->name ); ?></span>
								<span class="pltt-badge <?php echo esc_attr( pltt_billing_type_badge_class( $scope['billing_type'] ) ); ?>"><?php echo esc_html( $v['type_label'] ); ?></span>
							</div>
							<?php if ( ! empty( $v['date_range'] ) ) : ?>
								<div class="pltt-bill-card-range">
									<?php echo esc_html( $v['date_range'] ); ?>
								</div>
							<?php endif; ?>
							<div class="pltt-bill-card-amount"><?php echo esc_html( pltt_format_currency( $scope['unbilled'] ) ); ?></div>
							<div class="pltt-bill-card-basis"><?php echo esc_html( $basis ); ?></div>
							<a class="button button-primary pltt-bill-card-action" href="<?php echo esc_url( $review_url ); ?>">
								<?php esc_html_e( 'Review &amp; bill', 'plain-language-time-tracker' ); ?> &rarr;
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php // ============ BILLING RECORDS ============ ?>
	<h2 class="pltt-bill-sectitle pltt-bill-sectitle--records"><?php esc_html_e( 'Billing records', 'plain-language-time-tracker' ); ?></h2>

	<?php if ( empty( $log['rows'] ) ) : ?>
		<div class="pltt-card pltt-bill-empty">
			<p class="pltt-report-placeholder-lead"><?php esc_html_e( 'No bills recorded yet.', 'plain-language-time-tracker' ); ?></p>
			<p class="description"><?php esc_html_e( 'Once you bill outstanding work, each record shows up here.', 'plain-language-time-tracker' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped pltt-bill-records-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Billed on', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></th>
					<th class="pltt-amount-col"><?php esc_html_e( 'Hours', 'plain-language-time-tracker' ); ?></th>
					<th class="pltt-amount-col"><?php esc_html_e( 'Amount', 'plain-language-time-tracker' ); ?></th>
					<th class="pltt-bill-records-action"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log['rows'] as $row ) : ?>
					<?php
					$rec       = $row['record'];
					$dialog_id = 'pltt-recordcopy-' . (int) $rec->id;
					?>
					<tr>
						<td class="pltt-time-cell"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $rec->marked_at ) ) ); ?></td>
						<td>
							<?php
							echo '' !== $row['client_name']
								? esc_html( $row['client_name'] )
								: '<span class="pltt-empty">&mdash;</span>';
							?>
						</td>
						<td>
							<?php echo esc_html( $row['project_name'] ); ?>
							<?php if ( (float) $rec->absorbed_amount > 0 ) : ?>
								<span class="pltt-bill-absorbed">
									<?php
									printf(
										/* translators: %s: absorbed amount. */
										esc_html__( '· %s absorbed', 'plain-language-time-tracker' ),
										esc_html( pltt_format_currency( (float) $rec->absorbed_amount ) )
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td class="pltt-amount-col"><?php echo null !== $rec->billed_minutes ? esc_html( pltt_format_duration( (int) $rec->billed_minutes ) ) : '<span class="pltt-empty">&mdash;</span>'; ?></td>
						<td class="pltt-amount-col"><?php echo esc_html( pltt_format_currency( (float) $rec->billed_amount ) ); ?></td>
						<td class="pltt-bill-records-action">
							<button type="button" class="button-link" data-lineitems-dialog="<?php echo esc_attr( $dialog_id ); ?>">
								<?php esc_html_e( 'View record', 'plain-language-time-tracker' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php // Record-detail dialogs live outside the table (a <dialog> can't sit in a <tbody>). ?>
		<?php foreach ( $log['rows'] as $row ) : ?>
			<?php
			$rec       = $row['record'];
			$dialog_id = 'pltt-recordcopy-' . (int) $rec->id;
			$rv        = pltt_build_billing_record_view( $rec );
			include PLTT_PLUGIN_DIR . 'templates/partials/billing-record-dialog.php';
			?>
		<?php endforeach; ?>

		<p class="description pltt-bill-recordnote">
			<?php esc_html_e( 'A record freezes what you billed and marks those entries billed, so “outstanding” resolves itself. There’s no sent/paid tracking — that’s your invoicing tool’s job.', 'plain-language-time-tracker' ); ?>
		</p>
	<?php endif; ?>
</div>

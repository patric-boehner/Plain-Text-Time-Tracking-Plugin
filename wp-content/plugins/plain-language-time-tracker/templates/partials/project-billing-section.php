<?php
/**
 * Project billing card — the gateway, in the Reports single-project card
 * (detailed view only).
 *
 * "Review & bill" RESETS the report date range to the scope it applies to, so all
 * the billable work comes into view: hourly = the span of unbilled entries;
 * retainer = the overage period. On the hourly range, the entry table's select
 * row + the docked "Bill selected" bar take over (see billing-select-bar.php).
 * This card is the entry point; it doesn't commit anything itself.
 *
 * Retainer overage is period-based, so it respects the report's date filter: only
 * periods intersecting the range show as cards; earlier unbilled periods roll into
 * one "backlog" alert that points to the Billing page. Hourly/fixed are NOT
 * period-scoped (a running tab of uncovered work), so they always show regardless
 * of the filter — that's how you bill them (and keeps the select row + bar alive).
 *
 * Expects in scope (set by project-context-card.php / PLTT_Reports::render):
 *   $project             — the single project object.
 *   $context_client      — owning client object (may be empty).
 *   $date_from, $date_to — the report's current filter range.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $project ) ) {
	return;
}

$is_active = ( 'active' === ( $project->status ?? '' ) );

// Retainer overage is period-based → respect the report's date filter: show only
// periods intersecting the range; roll earlier unbilled periods into a backlog
// alert. Hourly/fixed are a running tab of uncovered work (not period-scoped), so
// they always show — otherwise a month filter would hide older unbilled entries
// and kill the hourly select row + "Bill selected" bar.
$range_from     = isset( $date_from ) ? $date_from : null;
$range_to       = isset( $date_to ) ? $date_to : null;
$ready_scopes   = array();
$backlog_amount = 0.0;
if ( $is_active ) {
	foreach ( PLTT_Billing::get_ready_to_invoice( $project, true ) as $s ) {
		if ( 'retainer_overage' === $s['billing_type'] && $range_from && $range_to
			&& ! ( (string) $s['period_start'] <= $range_to && (string) $s['period_end'] >= $range_from ) ) {
			// Retainer period entirely outside the filter range → backlog.
			$backlog_amount += (float) $s['unbilled'];
		} else {
			$ready_scopes[] = $s;
		}
	}
}
$backlog_amount = round( $backlog_amount, 2 );
$has_backlog    = ( $backlog_amount > 0.005 );

$billing_history = PLTT_Billing::get_for_project_history( (int) $project->id );

// Nothing in range, no backlog, no past billing — render no footprint.
if ( empty( $ready_scopes ) && ! $has_backlog && empty( $billing_history ) ) {
	return;
}

$client_name = ! empty( $context_client ) ? $context_client->name : '';
$today       = pltt_get_current_date();

// Base URL for the detailed view of this project — "Review & bill" just adds the
// scope's date range to it.
$detail_base = add_query_arg(
	array(
		'page'       => 'pltt-reports',
		'view'       => 'detailed',
		'client_id'  => (int) $project->client_id,
		'project_id' => (int) $project->id,
	),
	admin_url( 'admin.php' )
);
?>
<div class="card pltt-project-billing-card">
	<?php if ( ! empty( $ready_scopes ) ) : ?>
		<?php foreach ( $ready_scopes as $scope ) : ?>
			<?php
			$v         = pltt_build_billing_scope_view( $scope, $client_name );
			$is_hourly = ( 'hourly' === $scope['billing_type'] );

			// The range "Review & bill" jumps to: retainer = its period; hourly (and
			// fixed) = the span of the unbilled entries through today.
			if ( 'retainer_overage' === $scope['billing_type'] ) {
				$range_from = (string) $scope['period_start'];
				$range_to   = (string) $scope['period_end'];
			} else {
				$range_from = $today;
				foreach ( $scope['entries'] as $e ) {
					if ( $e->entry_date < $range_from ) {
						$range_from = $e->entry_date;
					}
				}
				$range_to = $today;
			}
			$review_url = add_query_arg(
				array(
					'from' => $range_from,
					'to'   => $range_to,
					'bill' => 1, // Explicit "start a bill" flag — invokes the select row.
				),
				$detail_base
			);

			$basis = $is_hourly
				? sprintf(
					/* translators: %d: number of unbilled entries. */
					_n( '%d unbilled entry', '%d unbilled entries', $v['count'], 'plain-language-time-tracker' ),
					$v['count']
				)
				: $v['derivation'];
			?>
			<div class="pltt-bill-card">
				<div class="pltt-bill-card-head">
					<span class="pltt-bill-card-proj"><?php esc_html_e( 'Ready to bill', 'plain-language-time-tracker' ); ?></span>
					<span class="pltt-badge <?php echo esc_attr( pltt_billing_type_badge_class( $scope['billing_type'] ) ); ?>"><?php echo esc_html( $v['type_label'] ); ?></span>
				</div>
				<?php if ( ! empty( $v['date_range'] ) ) : ?>
					<div class="pltt-bill-card-range">
						<?php echo esc_html( $v['date_range'] ); ?>
					</div>
				<?php endif; ?>
				<div class="pltt-bill-card-amount"><?php echo esc_html( pltt_format_currency( $scope['unbilled'] ) ); ?></div>
				<div class="pltt-bill-card-basis"><?php echo esc_html( $basis ); ?></div>
				<?php
					// Hide the "Review & bill" CTA once a bill is in progress (bill=1) —
					// you're already reviewing/selecting in the detailed view below.
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only mode flag.
					if ( empty( $_GET['bill'] ) ) :
						?>
						<a class="button button-primary pltt-bill-card-action" href="<?php echo esc_url( $review_url ); ?>">
					<?php esc_html_e( 'Review &amp; bill', 'plain-language-time-tracker' ); ?> &rarr;
				</a>
					<?php endif; ?>
			</div>
		<?php endforeach; ?>
	<?php elseif ( ! $has_backlog && ! empty( $billed_period ) ) : ?>
		<?php // Settled: the shown retainer period has a committed record (set by project-context-card.php). ?>
		<p class="pltt-pcc-bill-billed">
			<span class="dashicons dashicons-yes" aria-hidden="true"></span>
			<?php
			printf(
				/* translators: 1: billed amount; 2: period label, e.g. "June 2026". */
				esc_html__( 'Billed %1$s · %2$s', 'plain-language-time-tracker' ),
				esc_html( pltt_format_currency( $billed_period['amount'] ) ),
				esc_html( $billed_period['label'] )
			);
			?>
		</p>
	<?php elseif ( ! $has_backlog && ! empty( $billing_history ) ) : ?>
		<p class="pltt-pcc-billing-caughtup">
			<span class="dashicons dashicons-yes" aria-hidden="true"></span>
			<?php esc_html_e( 'Nothing to bill right now.', 'plain-language-time-tracker' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( $has_backlog ) : ?>
		<?php // Outstanding work outside the current range — one line, not a stack of old period cards. ?>
		<p class="pltt-pcc-billing-backlog">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<span>
				<?php
				printf(
					/* translators: %s: backlog amount, e.g. "$2,400". */
					esc_html__( '%s in earlier unbilled work sits outside this range.', 'plain-language-time-tracker' ),
					esc_html( pltt_format_currency( $backlog_amount ) )
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=pltt-invoicing' ) . '#pltt-bill-proj-' . (int) $project->id ); ?>"><?php esc_html_e( 'Review in Billing', 'plain-language-time-tracker' ); ?></a>
			</span>
		</p>
	<?php endif; ?>

	<?php
	if ( ! empty( $billing_history ) ) :
		// A catch-all project can accrue years of records — don't list them here.
		// Link to the full ledger on the project's report page instead.
		$history_count = count( $billing_history );
		$history_url   = add_query_arg( 'tab', 'report', PLTT_Project_Detail::get_url( (int) $project->id ) ) . '#pltt-billing-history';
		?>
		<p class="pltt-pcc-billing-history-link">
			<a href="<?php echo esc_url( $history_url ); ?>">
				<?php
				/* translators: %d: number of past billing records. */
				echo esc_html( sprintf( _n( '%d previous bill', '%d previous bills', $history_count, 'plain-language-time-tracker' ), $history_count ) );
				?>
			</a>
		</p>
	<?php endif; ?>
</div>

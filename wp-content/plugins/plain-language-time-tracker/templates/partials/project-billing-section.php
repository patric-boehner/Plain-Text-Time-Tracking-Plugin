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
 * Outstanding scopes are driven by the billing MODEL, not the current date
 * filter: hourly = all-time uncovered, retainer = each open overage period.
 *
 * Expects in scope (set by project-context-card.php / PLTT_Reports::render):
 *   $project        — the single project object.
 *   $context_client — owning client object (may be empty).
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $project ) ) {
	return;
}

// WITH entries: we need each scope's entries to compute its date span.
$ready_scopes = ( 'active' === ( $project->status ?? '' ) )
	? PLTT_Billing::get_ready_to_invoice( $project, true )
	: array();

$billing_history = PLTT_Billing::get_for_project_history( (int) $project->id );

// No outstanding work and no record of past billing — render no footprint.
if ( empty( $ready_scopes ) && empty( $billing_history ) ) {
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
						<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
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
	<?php elseif ( ! empty( $billed_period ) ) : ?>
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
	<?php elseif ( ! empty( $billing_history ) ) : ?>
		<p class="pltt-pcc-billing-caughtup">
			<span class="dashicons dashicons-yes" aria-hidden="true"></span>
			<?php esc_html_e( 'Nothing to bill right now.', 'plain-language-time-tracker' ); ?>
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

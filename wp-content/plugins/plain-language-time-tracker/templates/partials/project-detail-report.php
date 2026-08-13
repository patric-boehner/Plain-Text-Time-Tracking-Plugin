<?php
/**
 * Report tab — period lens + type-aware hero + stat cards + volume chart.
 *
 * For recurring projects a period lens (Full / step-by-period) re-scopes the
 * cards and the volume chart to the chosen window. Every other billing type
 * stays on the full lifetime span with no control.
 *
 * The per-tag "Where the time went" bars and the "Activity over time" swimlane
 * were removed 2026-07-18 — see docs/removed-project-report-sections.md for the
 * design + how to rebuild them.
 *
 * Expects $report (from PLTT_Project_Report::build()) and $project in scope.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cards  = $report['cards'];
$window = $report['window'] ?? null;

$has_entries = ! empty( $report['has_entries'] );
$is_period   = ! empty( $window['is_period'] );
?>

<?php if ( ! $has_entries ) : ?>
	<?php
	pltt_render_empty_state(
		__( 'No time logged on this project yet.', 'plain-language-time-tracker' ),
		__( 'Once entries are verified for this project, its lifetime report appears here.', 'plain-language-time-tracker' )
	);
	?>
	<?php return; ?>
<?php endif; ?>

<?php
// Scope block — this screen IS a scope with agreed terms, so identity and
// figures live in one bordered object (see pltt-system.css). Identity is the
// project's lifetime; the figure row (cards) re-scopes with the period lens.
// Client, then the terms agreed with them — "3 hours included each month at
// $90/hr", "$3,870 budgeted as 38h 42m at $100/hr". Previously only hourly
// projects said anything here, so a retainer's page never stated its allocation
// and a fixed project never stated its budget.
$scope_terms = ( isset( $client ) && $client && ! empty( $client->name ) )
	? $client->name
	: __( 'Internal', 'plain-language-time-tracker' );

$terms_txt = pltt_format_project_terms( $project );
if ( '' !== $terms_txt ) {
	$scope_terms .= ' · ' . $terms_txt;
}

// The "Showing …" line is the readout of the date control, so it has to follow
// the selected period — it used to read $stats (lifetime) unconditionally and so
// still said the project's whole span while the figures beside it showed one
// month. $window's from/to already collapse to the lifetime span on "All time",
// and $subhead_stats is the matching windowed count (== $stats when not windowed).
$span_first = ! empty( $window['from'] ) ? $window['from'] : ( $stats->first_entry_date ?? '' );
$span_last  = ! empty( $window['to'] ) ? $window['to'] : ( $stats->last_entry_date ?? '' );
$span_txt   = '';
if ( $span_first && $span_last ) {
	$span_txt = ( $span_first === $span_last )
		? date_i18n( 'M j, Y', strtotime( $span_first ) )
		: date_i18n( 'M j, Y', strtotime( $span_first ) ) . ' – ' . date_i18n( 'M j, Y', strtotime( $span_last ) );
}
$span_stats = isset( $subhead_stats ) ? $subhead_stats : $stats;
$span_count = isset( $span_stats->total_count ) ? (int) $span_stats->total_count : 0;
?>
<div class="pltt-scope-block">
	<div class="pltt-scope-id">
		<div class="pltt-scope-titlerow">
			<h1 class="pltt-scope-title"><?php echo esc_html( $project->name ); ?></h1>
			<?php pltt_render_billing_type_badge( $billing_type ); ?>
			<?php if ( 'archived' === $project->status ) : ?>
				<span class="pltt-badge pltt-badge-archived"><?php esc_html_e( 'Archived', 'plain-language-time-tracker' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="pltt-scope-terms"><?php echo esc_html( $scope_terms ); ?></div>
		<?php if ( '' !== $span_txt || $span_count > 0 ) : ?>
			<div class="pltt-scope-when">
				<span><?php esc_html_e( 'Showing', 'plain-language-time-tracker' ); ?></span>
				<?php if ( '' !== $span_txt ) : ?>
					<span class="pltt-mono"><?php echo esc_html( $span_txt ); ?></span>
				<?php endif; ?>
				<?php if ( $span_count > 0 ) : ?>
					<span>&middot; <?php echo esc_html( sprintf( _n( '%s entry', '%s entries', $span_count, 'plain-language-time-tracker' ), number_format_i18n( $span_count ) ) ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- Figure row: the shared number bar (label / value / basis). -->
	<div class="pltt-summary-cards pltt-numbar pltt-stat-cards">
		<?php foreach ( $cards['items'] as $card ) : ?>
			<div class="card">
				<div class="card-label"><?php echo esc_html( $card['label'] ); ?></div>
				<?php if ( $card['is_empty'] ) : ?>
					<div class="card-value pltt-card-value-empty">&mdash;</div>
				<?php else : ?>
					<?php
					$card_value_class = 'card-value';
					if ( ! empty( $card['over'] ) ) {
						$card_value_class .= ' pltt-numbar-over';
					} elseif ( ! empty( $card['muted'] ) ) {
						$card_value_class .= ' pltt-numbar-muted';
					}
					?>
					<div class="<?php echo esc_attr( $card_value_class ); ?>">
						<?php echo esc_html( $card['value'] ); ?>
						<?php if ( '' !== $card['value_suffix'] ) : ?>
							<span class="pltt-card-value-of"><?php echo esc_html( $card['value_suffix'] ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php
				// Basis line: plain text, an inline link, both joined with " · ", or
				// pre-built HTML from a shared figure helper.
				$card_link = ! empty( $card['sub_link'] ) ? $card['sub_link'] : null;
				$card_html = ! empty( $card['sub_html'] ) ? $card['sub_html'] : '';
				if ( '' !== $card['sub'] || $card_link || '' !== $card_html ) :
					?>
					<div class="card-secondary<?php echo $card['attention'] ? ' pltt-alloc-over' : ''; ?>">
						<?php echo esc_html( $card['sub'] ); ?>
						<?php echo $card_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts in pltt_retainer_period_status_figure(). ?>
						<?php if ( $card_link ) : ?>
							<?php echo ( '' !== $card['sub'] ) ? ' &middot; ' : ''; ?>
							<a class="pltt-lk" href="<?php echo esc_url( $card_link['url'] ); ?>"><?php echo esc_html( $card_link['label'] ); ?> &rsaquo;</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<?php
// Type-aware hero — the gauge/figure headline, now a detail card below the
// block (the block's number bar carries the top-line figures). Null for internal.
$hero = $report['hero'] ?? null;
include PLTT_PLUGIN_DIR . 'templates/partials/project-report-hero.php';
?>

<?php
// Ready to invoice — one prompt per outstanding scope, linking to the billing
// surface (verify -> adjust -> commit). Active projects only: an archived project
// has nothing live to bill, so Review & bill disappears (billing history stays).
//
// Temporarily HIDDEN on the project page (2026-06-27, Patrick's call — keeps the
// page cleaner; may return). The queue still lives on the Invoicing menu page, so
// nothing is lost. To restore, set $show_ready = true.
$show_ready   = false;
$ready_scopes = ( $show_ready && 'active' === $project->status )
	? PLTT_Billing::get_ready_to_invoice( $project )
	: array();
if ( ! empty( $ready_scopes ) ) :
	?>
	<div class="pltt-card pltt-ready-card">
		<h2 class="pltt-ready-title"><?php esc_html_e( 'Ready to invoice', 'plain-language-time-tracker' ); ?></h2>
		<?php foreach ( $ready_scopes as $rs ) : ?>
			<?php
			$bill_url = add_query_arg(
				array(
					'page'       => 'pltt-projects',
					'action'     => 'bill',
					'project_id' => (int) $project->id,
					'type'       => $rs['billing_type'],
					'period'     => (string) $rs['period_start'],
				),
				admin_url( 'admin.php' )
			);

			if ( 'retainer_overage' === $rs['billing_type'] ) {
				$prompt = sprintf(
					/* translators: 1: period label, 2: dollar amount over allocation. */
					__( '%1$s — %2$s over allocation', 'plain-language-time-tracker' ),
					$rs['period_label'],
					pltt_format_currency( $rs['unbilled'] )
				);
			} else {
				$prompt = sprintf(
					/* translators: %s: unbilled dollar amount. */
					__( '%s unbilled', 'plain-language-time-tracker' ),
					pltt_format_currency( $rs['unbilled'] )
				);
			}
			?>
			<div class="pltt-ready-row">
				<span class="pltt-ready-prompt"><?php echo esc_html( $prompt ); ?></span>
				<a class="button button-primary" href="<?php echo esc_url( $bill_url ); ?>">
					<?php esc_html_e( 'Review &amp; Invoice', 'plain-language-time-tracker' ); ?>
				</a>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
endif;
?>

<?php
// The period lens used to sit here, beneath the cards. It moved to the header's
// action group (templates/partials/project-period-lens.php) so the date control
// is in the same place as on every other screen. It still drives the cards, the
// volume chart, and the "Where the time went" bars.
?>

<?php
// Volume bar chart (Hours by day/week/month over the active window). Same
// partial as the Reports summary chart.
if ( ! empty( $report['chart'] ) && $window ) :
	$chart             = $report['chart'];
	$date_from         = $window['from'];
	$date_to           = $window['to'];
	$chart_empty_label = $is_period ? $window['period_label'] : '';
	// No panel control here: the chart's granularity follows the selected period,
	// and a day/week/month switch on top of that didn't earn its place on this
	// screen. $chart_controls stays empty (the chart partial skips the slot).
	$chart_controls    = '';
	include PLTT_PLUGIN_DIR . 'templates/partials/chart-by-period.php';
endif;
?>

<?php
// "Where the time went" bars + "Activity over time" swimlane were removed here
// on 2026-07-18 (they felt like too much on the project page). The volume chart
// above stays. Rebuild notes: docs/removed-project-report-sections.md.
?>

<?php
// Billing history — the full read-only ledger of records for this project. Unlike
// everything above, it is NOT bound to the selected period; it's the lifetime
// ledger. A record with billed_amount = 0 is fully absorbed (no status column).
$billing_history = PLTT_Billing::get_for_project_history( (int) $project->id );
if ( ! empty( $billing_history ) ) :
	?>
	<?php
	// Group band + table, the same shape Projects, Tags, History and Reports use:
	// a titled header that sits directly on the table it labels. The id stays on
	// the group — three places link to #pltt-billing-history.
	$bh_count = count( $billing_history );
	?>
	<div class="pltt-bill-history-group" id="pltt-billing-history">
		<div class="pltt-bill-history-group-header">
			<span class="pltt-bill-history-group-title"><?php esc_html_e( 'Billing history', 'plain-language-time-tracker' ); ?></span>
			<span class="pltt-bill-history-group-meta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of bill records. */
						_n( '%s record', '%s records', $bh_count, 'plain-language-time-tracker' ),
						number_format_i18n( $bh_count )
					)
				);
				?>
			</span>
		</div>
		<?php include PLTT_PLUGIN_DIR . 'templates/partials/billing-history-table.php'; ?>
	</div>
	<?php
endif;
?>

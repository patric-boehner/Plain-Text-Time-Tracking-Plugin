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
	<div class="pltt-card pltt-report-empty">
		<p class="pltt-report-placeholder-lead"><?php esc_html_e( 'No time logged on this project yet.', 'plain-language-time-tracker' ); ?></p>
		<p class="description"><?php esc_html_e( 'Once entries are verified for this project, its lifetime report appears here.', 'plain-language-time-tracker' ); ?></p>
	</div>
	<?php return; ?>
<?php endif; ?>

<?php
// Scope block — this screen IS a scope with agreed terms, so identity and
// figures live in one bordered object (see pltt-system.css). Identity is the
// project's lifetime; the figure row (cards) re-scopes with the period lens.
$scope_terms = ( isset( $client ) && $client && ! empty( $client->name ) )
	? $client->name
	: __( 'Internal', 'plain-language-time-tracker' );
if ( 'hourly' === $billing_type ) {
	$hr_rate = pltt_resolve_billable_rate( (int) $project->client_id, (int) $project->id );
	if ( $hr_rate > 0 ) {
		/* translators: %s: hourly rate, e.g. "$100.00". */
		$scope_terms .= ' · ' . sprintf( __( '%s/hr', 'plain-language-time-tracker' ), pltt_format_currency( $hr_rate ) );
	}
}

$span_first = $stats->first_entry_date ?? '';
$span_last  = $stats->last_entry_date ?? '';
$span_txt   = '';
if ( $span_first && $span_last ) {
	$span_txt = ( $span_first === $span_last )
		? date_i18n( 'M j, Y', strtotime( $span_first ) )
		: date_i18n( 'M j, Y', strtotime( $span_first ) ) . ' – ' . date_i18n( 'M j, Y', strtotime( $span_last ) );
}
$span_count = isset( $stats->total_count ) ? (int) $stats->total_count : 0;
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
// Period lens (recurring projects only) — sits beneath the cards and drives the
// cards, the volume chart, and the "Where the time went" bars; the swimlane
// stays lifetime. Styling mirrors the Reports view-toggle + date-nav approach.
if ( $window && ! empty( $window['show_control'] ) ) :
	$pid       = (int) $project->id;
	$base_args = array(
		'page'       => 'pltt-projects',
		'action'     => 'view',
		'project_id' => $pid,
		'tab'        => 'report',
	);
	$unit_labels = array(
		'week'    => __( 'By week', 'plain-language-time-tracker' ),
		'month'   => __( 'By month', 'plain-language-time-tracker' ),
		'quarter' => __( 'By quarter', 'plain-language-time-tracker' ),
		'year'    => __( 'By year', 'plain-language-time-tracker' ),
	);
	$period_btn_label = $unit_labels[ $window['unit'] ] ?? $unit_labels['month'];
	$full_url         = add_query_arg( array_merge( $base_args, array( 'chart_scope' => 'full' ) ), admin_url( 'admin.php' ) );
	$period_url       = add_query_arg( array_merge( $base_args, array( 'chart_scope' => 'period' ) ), admin_url( 'admin.php' ) );
	?>
	<div class="pltt-period-lens">
		<!-- Full / by-period: segmented toggle, same treatment as the Reports view switch. -->
		<div class="pltt-period-modes" role="group" aria-label="<?php esc_attr_e( 'Report scope', 'plain-language-time-tracker' ); ?>">
			<a class="button<?php echo $is_period ? '' : ' button-primary'; ?>" href="<?php echo esc_url( $full_url ); ?>" aria-pressed="<?php echo $is_period ? 'false' : 'true'; ?>">
				<?php esc_html_e( 'Full', 'plain-language-time-tracker' ); ?>
			</a>
			<a class="button<?php echo $is_period ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $period_url ); ?>" aria-pressed="<?php echo $is_period ? 'true' : 'false'; ?>">
				<?php echo esc_html( $period_btn_label ); ?>
			</a>
		</div>

		<?php if ( $is_period ) : ?>
			<!-- Period nav, right-aligned: the prev/period/next stepper reuses the
			     shared .pltt-date-nav styling, with a "Latest" reset button after it
			     (mirrors the daily-log "Today" reset). -->
			<div class="pltt-period-nav">
				<div class="pltt-period-stepper" role="group" aria-label="<?php esc_attr_e( 'Period', 'plain-language-time-tracker' ); ?>">
					<?php if ( ! empty( $window['prev_anchor'] ) ) : ?>
						<?php $prev_url = add_query_arg( array_merge( $base_args, array( 'chart_scope' => 'period', 'chart_period' => $window['prev_anchor'] ) ), admin_url( 'admin.php' ) ); ?>
						<a class="pltt-date-nav-step pltt-date-nav-prev" href="<?php echo esc_url( $prev_url ); ?>" aria-label="<?php esc_attr_e( 'Previous period', 'plain-language-time-tracker' ); ?>"></a>
					<?php else : ?>
						<span class="pltt-date-nav-step pltt-date-nav-prev is-disabled" aria-disabled="true"></span>
					<?php endif; ?>

					<span class="pltt-period-label-seg"><?php echo esc_html( $window['period_label'] ); ?></span>

					<?php if ( ! empty( $window['next_anchor'] ) ) : ?>
						<?php $next_url = add_query_arg( array_merge( $base_args, array( 'chart_scope' => 'period', 'chart_period' => $window['next_anchor'] ) ), admin_url( 'admin.php' ) ); ?>
						<a class="pltt-date-nav-step pltt-date-nav-next" href="<?php echo esc_url( $next_url ); ?>" aria-label="<?php esc_attr_e( 'Next period', 'plain-language-time-tracker' ); ?>"></a>
					<?php else : ?>
						<span class="pltt-date-nav-step pltt-date-nav-next is-disabled" aria-disabled="true"></span>
					<?php endif; ?>
				</div>

				<?php if ( empty( $window['is_latest'] ) ) : ?>
					<a class="button button-secondary pltt-period-latest" href="<?php echo esc_url( $period_url ); ?>"><?php esc_html_e( 'Latest', 'plain-language-time-tracker' ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php
// Volume bar chart (Hours by day/week/month over the active window). Same
// partial as the Reports summary chart.
if ( ! empty( $report['chart'] ) && $window ) :
	$chart             = $report['chart'];
	$date_from         = $window['from'];
	$date_to           = $window['to'];
	$chart_empty_label = $is_period ? $window['period_label'] : '';
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
	<div class="pltt-card pltt-billing-history" id="pltt-billing-history">
		<div class="pltt-where-header">
			<h2 class="pltt-where-title"><?php esc_html_e( 'Billing history', 'plain-language-time-tracker' ); ?></h2>
		</div>
		<?php include PLTT_PLUGIN_DIR . 'templates/partials/billing-history-table.php'; ?>
	</div>
	<?php
endif;
?>

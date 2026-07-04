<?php
/**
 * Project context card — shown on the Reports page when filtered to a single project.
 *
 * Communicates project identity and state at a glance: client + project name, the
 * type/financial info line, an allocation/budget bar for retainer & fixed-fee
 * projects, and an archived badge + muted treatment when applicable.
 *
 * Expects in scope (all set by PLTT_Reports::render()):
 *   $context_client   — client object (name, ...).
 *   $context_projects — array; [0] is the single project being viewed.
 *   $context_overage  — array|null from pltt_compute_overage_threshold() (detailed view only).
 *   $filter_args      — active filter args (used to compute the bar on the summary view).
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $context_projects[0] ) ) {
	return;
}

$project      = $context_projects[0];
$billing_type = pltt_get_billing_type( $project );
$is_archived  = ( 'archived' === ( $project->status ?? '' ) );
$info_line    = pltt_format_project_info_line( $project );

$card_classes = array( 'card', 'pltt-project-context-card' );
if ( $is_archived ) {
	$card_classes[] = 'is-archived';
}

// Allocation/budget bar data (retainer + fixed only). Reuse $context_overage when it
// is already populated (detailed view); otherwise compute it here (summary view). When
// the range spans periods or there's no allocation, $overage stays 'unavailable' and
// the bar is omitted — same behavior as the summary table.
$show_bar  = false;
$overage   = null;
$has_alloc = in_array( $billing_type, array( 'recurring', 'fixed' ), true )
	&& ( ! empty( $project->budget_hours ) || ! empty( $project->budget_fee ) );

if ( $has_alloc ) {
	$overage = ( ! empty( $context_overage ) && in_array( $context_overage['state'], array( 'over', 'within' ), true ) )
		? $context_overage
		: pltt_compute_overage_threshold( $project, $filter_args );

	if ( in_array( $overage['state'], array( 'over', 'within' ), true ) && $overage['allocation_minutes'] > 0 ) {
		$show_bar = true;
	}
}
?>
<div class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
	<div class="pltt-pcc-header">
		<div class="pltt-pcc-titles">
			<?php if ( ! empty( $context_client ) ) : ?>
				<span class="pltt-pcc-client"><?php echo esc_html( $context_client->name ); ?></span>
			<?php endif; ?>
			<a class="pltt-pcc-project" href="<?php echo esc_url( PLTT_Project_Detail::get_url( (int) $project->id ) ); ?>"><?php echo esc_html( $project->name ); ?></a>
		</div>
		<?php if ( $is_archived ) : ?>
			<span class="pltt-badge pltt-pcc-badge"><?php esc_html_e( 'Archived', 'plain-language-time-tracker' ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( '' !== $info_line ) : ?>
		<div class="pltt-pcc-info"><?php echo esc_html( $info_line ); ?></div>
	<?php endif; ?>

	<?php
	// Billed state for the shown retainer period (drives the bar amber→green and
	// the section's "Billed" line). $billed_period is read by project-billing-section.php.
	$is_billed_bar = false;
	$billed_period = null;

	if ( $show_bar ) {
		$used_min  = (int) $overage['used_minutes'];
		$alloc_min = (int) $overage['allocation_minutes'];
		$pct       = $alloc_min > 0 ? (int) round( $used_min / $alloc_min * 100 ) : 0;

		if ( 'recurring' === $billing_type ) {
			$fee_args = null;
			if ( 'over' === $overage['state'] ) {
				$over_min = (int) $overage['overage_minutes'];

				// Settled = a committed record covers this period with nothing left
				// unbilled (lowering the amount absorbs the rest, so any record for
				// the period settles it until more overage accrues).
				if ( ! empty( $overage['period_start'] ) ) {
					$period_rate     = pltt_resolve_billable_rate( (int) $project->client_id, (int) $project->id );
					$period_calc     = pltt_billable_amount( $over_min, $period_rate );
					$period_sums     = PLTT_Billing_Records::sum_billed( (int) $project->id, 'retainer_overage', $overage['period_start'] );
					$period_unbilled = $period_calc - $period_sums['billed'] - $period_sums['absorbed'];
					if ( ( $period_sums['billed'] + $period_sums['absorbed'] ) > 0 && $period_unbilled <= 0.005 ) {
						$is_billed_bar = true;
						$billed_period = array(
							'label'  => date_i18n( 'F Y', strtotime( $overage['period_start'] ) ),
							'amount' => (float) $period_sums['billed'],
						);
					}
				}

				$caption = $is_billed_bar
					? sprintf(
						/* translators: 1: time used; 2: overage time, now billed. */
						__( '%1$s used · %2$s billed', 'plain-language-time-tracker' ),
						pltt_format_duration( $used_min ),
						pltt_format_duration( $over_min )
					)
					: sprintf(
						/* translators: 1: time used, e.g. "5h 15m"; 2: overage time, e.g. "2h 15m". */
						__( '%1$s used · %2$s over', 'plain-language-time-tracker' ),
						pltt_format_duration( $used_min ),
						pltt_format_duration( $over_min )
					);
			} else {
				$caption = sprintf(
					/* translators: 1: time used; 2: time remaining in the allocation. */
					__( '%1$s used · %2$s remaining', 'plain-language-time-tracker' ),
					pltt_format_duration( $used_min ),
					pltt_format_duration( (int) $overage['remaining_minutes'] )
				);
			}
		} else {
			// Fixed Fee: mirror the overage helper's allocation source — hours when
			// budget_hours is set, otherwise dollars derived from budget_fee.
			if ( ! empty( $project->budget_hours ) ) {
				$fee_args = null;
			} else {
				$rate     = pltt_resolve_billable_rate( (int) $project->client_id, (int) $project->id );
				$fee_args = array(
					'spent_dollars'  => round( ( $used_min / 60.0 ) * $rate, 2 ),
					'budget_dollars' => (float) $project->budget_fee,
				);
			}
			$caption = sprintf(
				/* translators: 1: time used; 2: total budgeted time; 3: percent of budget used. */
				__( '%1$s of %2$s used · %3$d%%', 'plain-language-time-tracker' ),
				pltt_format_duration( $used_min ),
				pltt_format_duration( $alloc_min ),
				$pct
			);
		}

		pltt_render_allocation_bar( $used_min, $alloc_min / 60, $billing_type, $fee_args, $caption, $is_billed_bar );
	}

	// Billing: Review & Invoice (modal) + the project's billing-history ledger.
	// Detailed view only — that's where the per-entry work happens; the summary
	// view stays a high-level overview. $view comes from PLTT_Reports::render().
	if ( isset( $view ) && 'detailed' === $view ) {
		include PLTT_PLUGIN_DIR . 'templates/partials/project-billing-section.php';
	}
	?>
</div>

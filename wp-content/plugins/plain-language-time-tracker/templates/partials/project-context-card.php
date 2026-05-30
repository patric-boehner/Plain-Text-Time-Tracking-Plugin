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
			<span class="pltt-pcc-project"><?php echo esc_html( $project->name ); ?></span>
		</div>
		<?php if ( $is_archived ) : ?>
			<span class="pltt-badge pltt-pcc-badge"><?php esc_html_e( 'Archived', 'plain-language-time-tracker' ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( '' !== $info_line ) : ?>
		<div class="pltt-pcc-info"><?php echo esc_html( $info_line ); ?></div>
	<?php endif; ?>

	<?php
	if ( $show_bar ) {
		$used_min  = (int) $overage['used_minutes'];
		$alloc_min = (int) $overage['allocation_minutes'];
		$pct       = $alloc_min > 0 ? (int) round( $used_min / $alloc_min * 100 ) : 0;

		if ( 'recurring' === $billing_type ) {
			$fee_args = null;
			if ( 'over' === $overage['state'] ) {
				$caption = sprintf(
					/* translators: 1: time used, e.g. "5h 15m"; 2: overage time, e.g. "2h 15m". */
					__( '%1$s used · %2$s over', 'plain-language-time-tracker' ),
					pltt_format_duration( $used_min ),
					pltt_format_duration( (int) $overage['overage_minutes'] )
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

		pltt_render_allocation_bar( $used_min, $alloc_min / 60, $billing_type, $fee_args, $caption );
	}
	?>
</div>

<?php
// Overage-to-invoice card — a separate, contextually-shown card (retainers in overage
// only; fixed-fee work isn't invoiced per-entry). Compares the calculated overage
// (math-correct) against what the user has flipped to billable. Relies on $overage /
// $billing_type / $project set above; reports.php includes this partial as one unit.
if ( 'recurring' === $billing_type && ! empty( $overage ) && 'over' === $overage['state'] ) :
		$rate           = pltt_resolve_billable_rate( (int) $project->client_id, (int) $project->id );
		$calc_minutes   = (int) $overage['overage_minutes'];
		$calc_amount    = round( $calc_minutes / 60.0 * $rate, 2 );
		$marked_minutes = (int) $overage['marked_billable_minutes'];
		$marked_amount  = (float) $overage['marked_billable_amount'];
		$diff = $marked_minutes - $calc_minutes;

		if ( 0 === $marked_minutes ) {
			$note_state = 'none';
			$note_icon  = 'dashicons-warning';
			$note_text  = __( 'No overage marked billable yet. Mark entries to invoice or use the bulk action.', 'plain-language-time-tracker' );
		} elseif ( 0 === $diff ) {
			$note_state = 'match';
			$note_icon  = 'dashicons-yes';
			$note_text  = __( 'Your marked entries match the calculated overage exactly.', 'plain-language-time-tracker' );
		} elseif ( $diff > 0 ) {
			$note_state = 'over';
			$note_icon  = 'dashicons-warning';
			$note_text  = sprintf(
				/* translators: 1: marked time; 2: marked dollars; 3: extra time beyond calculated; 4: calculated dollars. */
				__( 'You\'ve marked %1$s · %2$s — %3$s more than calculated. Adjust invoice down to %4$s.', 'plain-language-time-tracker' ),
				pltt_format_duration( $marked_minutes ),
				pltt_format_currency( $marked_amount ),
				pltt_format_duration( $diff ),
				pltt_format_currency( $calc_amount )
			);
		} else {
			$note_state = 'under';
			$note_icon  = 'dashicons-info-outline';
			$note_text  = sprintf(
				/* translators: 1: marked time; 2: marked dollars; 3: absorbed time. */
				__( 'You\'ve marked %1$s · %2$s — absorbing %3$s. Invoice for %2$s.', 'plain-language-time-tracker' ),
				pltt_format_duration( $marked_minutes ),
				pltt_format_currency( $marked_amount ),
				pltt_format_duration( abs( $diff ) )
			);
		}
		?>
		<div class="card pltt-project-overage-card">
			<div class="pltt-pcc-overage-label"><?php esc_html_e( 'Overage to invoice', 'plain-language-time-tracker' ); ?></div>
			<div class="pltt-pcc-overage-figure">
				<span class="pltt-pcc-overage-time"><?php echo esc_html( pltt_format_duration( $calc_minutes ) ); ?></span>
				<span class="pltt-pcc-overage-amount"><?php echo esc_html( pltt_format_currency( $calc_amount ) ); ?></span>
			</div>
			<div class="pltt-pcc-overage-note is-<?php echo esc_attr( $note_state ); ?>">
				<span class="dashicons <?php echo esc_attr( $note_icon ); ?>" aria-hidden="true"></span>
				<span class="pltt-pcc-overage-note-text"><?php echo esc_html( $note_text ); ?></span>
			</div>
		</div>
<?php endif; ?>

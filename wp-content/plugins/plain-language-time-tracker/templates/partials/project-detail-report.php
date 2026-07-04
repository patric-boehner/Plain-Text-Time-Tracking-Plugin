<?php
/**
 * Report tab — period lens + stat cards + volume chart + "Where the time went"
 * bars + swimlane timeline.
 *
 * For recurring projects a period lens (Full / step-by-period) re-scopes the
 * cards, the volume chart, and the "Where the time went" bars to the chosen
 * window; the swimlane always shows the full lifetime arc. Every other billing
 * type stays on the full lifetime span with no control.
 *
 * Expects $report (from PLTT_Project_Report::build()) and $project in scope.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cards    = $report['cards'];
$bar_groups = $report['groupings'];                                  // Windowed — "Where the time went".
$dimensions = $report['timeline_groupings'] ?? $report['groupings']; // Lifetime — swimlane + the toggle's dimension set.
$default  = $report['default_group'];
$window   = $report['window'] ?? null;

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

<!-- Stat cards (per billing type; shared .pltt-summary-cards / .card pattern, as on Reports) -->
<div class="pltt-summary-cards pltt-stat-cards">
	<?php foreach ( $cards['items'] as $card ) : ?>
		<div class="card">
			<div class="card-label"><?php echo esc_html( $card['label'] ); ?></div>
			<?php if ( $card['is_empty'] ) : ?>
				<div class="card-value pltt-card-value-empty">&mdash;</div>
			<?php else : ?>
				<div class="card-value">
					<?php echo esc_html( $card['value'] ); ?>
					<?php if ( '' !== $card['value_suffix'] ) : ?>
						<span class="pltt-card-value-of"><?php echo esc_html( $card['value_suffix'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( '' !== $card['sub'] ) : ?>
				<div class="card-secondary<?php echo $card['attention'] ? ' pltt-alloc-over' : ''; ?>"><?php echo esc_html( $card['sub'] ); ?></div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>

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

<!-- Where the time went -->
<div class="pltt-card pltt-where-card">
	<div class="pltt-where-header">
		<h2 class="pltt-where-title"><?php esc_html_e( 'Where the time went', 'plain-language-time-tracker' ); ?></h2>
		<?php if ( count( $dimensions ) > 1 ) : ?>
			<div class="pltt-groupby">
				<span class="pltt-groupby-label"><?php esc_html_e( 'Group by', 'plain-language-time-tracker' ); ?></span>
				<div class="pltt-groupby-toggle" role="group" aria-label="<?php esc_attr_e( 'Group by', 'plain-language-time-tracker' ); ?>">
					<?php foreach ( $dimensions as $gkey => $gdata ) : ?>
						<?php $is_default = ( $gkey === $default ); ?>
						<button
							type="button"
							class="button pltt-groupby-btn<?php echo $is_default ? ' button-primary' : ''; ?>"
							data-group-target="<?php echo esc_attr( $gkey ); ?>"
							aria-pressed="<?php echo $is_default ? 'true' : 'false'; ?>"
						><?php echo esc_html( $gdata['label'] ); ?></button>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<?php foreach ( $dimensions as $gkey => $dim ) : ?>
		<?php
		// Bars use the windowed slice for this dimension; the lifetime dimension
		// set drives the keys so the group-by toggle stays in sync with the
		// (lifetime) swimlane below.
		$wg      = isset( $bar_groups[ $gkey ] ) ? $bar_groups[ $gkey ] : null;
		$buckets = ( $wg && ! empty( $wg['buckets'] ) ) ? $wg['buckets'] : array();
		$g_desc  = ( $wg && ! empty( $wg['description'] ) ) ? $wg['description'] : ( $dim['description'] ?? '' );
		?>
		<div class="pltt-bars-group" data-group="<?php echo esc_attr( $gkey ); ?>" <?php echo ( $gkey === $default ) ? '' : 'hidden'; ?>>
			<?php if ( ! empty( $g_desc ) ) : ?>
				<p class="pltt-where-desc"><?php echo esc_html( $g_desc ); ?></p>
			<?php endif; ?>
			<?php if ( empty( $buckets ) ) : ?>
				<p class="description pltt-bars-empty">
					<?php
					if ( $is_period ) {
						/* translators: %s: grouping label. */
						printf( esc_html__( 'No %s tags in this period.', 'plain-language-time-tracker' ), esc_html( strtolower( $dim['label'] ) ) );
					} else {
						/* translators: %s: grouping label. */
						printf( esc_html__( 'No %s tags on this project.', 'plain-language-time-tracker' ), esc_html( strtolower( $dim['label'] ) ) );
					}
					?>
				</p>
			<?php else : ?>
				<?php
				$max         = max( 1, (int) $wg['max_minutes'] );
				$color_index = 0;
				?>
				<ul class="pltt-bars">
					<?php foreach ( $buckets as $bucket ) : ?>
						<?php
						$width      = (int) round( ( $bucket['minutes'] / $max ) * 100 );
						$pct        = (int) round( $bucket['pct'] * 100 );
						$color_cls  = $bucket['is_untagged'] ? '' : ' pltt-bar-color-' . ( $color_index % 8 );
						if ( ! $bucket['is_untagged'] ) {
							$color_index++;
						}

						// Formatted hover tooltip — adds the active date range, which isn't shown inline.
						$bar_range = '';
						if ( ! empty( $bucket['first_date'] ) && ! empty( $bucket['last_date'] ) ) {
							$bar_range = ( $bucket['first_date'] === $bucket['last_date'] )
								? date_i18n( 'M j, Y', strtotime( $bucket['first_date'] ) )
								: date_i18n( 'M j', strtotime( $bucket['first_date'] ) ) . ' – ' . date_i18n( 'M j, Y', strtotime( $bucket['last_date'] ) );
						}
						$bar_rows = array(
							array( __( 'Time', 'plain-language-time-tracker' ), pltt_format_duration( (int) $bucket['minutes'] ) ),
							array(
								__( 'Share', 'plain-language-time-tracker' ),
								/* translators: %d: percent of project total. */
								sprintf( __( '%d%% of total', 'plain-language-time-tracker' ), (int) $pct ),
							),
						);
						if ( '' !== $bar_range ) {
							$bar_rows[] = array( __( 'Active span', 'plain-language-time-tracker' ), $bar_range );
						}
						$bar_rows[] = array(
							__( 'Worked', 'plain-language-time-tracker' ),
							/* translators: %d: number of days worked. */
							sprintf( _n( '%d day', '%d days', (int) $bucket['worked_days'], 'plain-language-time-tracker' ), (int) $bucket['worked_days'] ),
						);
						?>
						<li
							class="pltt-bar-row<?php echo $bucket['is_untagged'] ? ' pltt-bar-untagged' : ''; ?><?php echo esc_attr( $color_cls ); ?>"
							data-pltt-tip
							data-tip-title="<?php echo esc_attr( $bucket['label'] ); ?>"
							data-tip-rows='<?php echo esc_attr( wp_json_encode( $bar_rows ) ); ?>'
						>
							<div class="pltt-bar-labelwrap">
								<span class="pltt-bar-dot" aria-hidden="true"></span>
								<span class="pltt-bar-label"><?php echo esc_html( $bucket['label'] ); ?></span>
							</div>
							<div class="pltt-bar-main">
								<div class="pltt-bar-track">
									<div class="pltt-bar-fill" style="width: <?php echo esc_attr( $width ); ?>%;"></div>
								</div>
								<span class="pltt-bar-meta">
									<span class="pltt-bar-hours"><?php echo esc_html( pltt_format_duration( $bucket['minutes'] ) ); ?></span>
									<span class="pltt-bar-meta-sub">
										<?php
										printf(
											/* translators: 1: percent of total, 2: span in days, 3: worked days. */
											esc_html__( '· %1$d%% over %2$dd · worked %3$dd', 'plain-language-time-tracker' ),
											(int) $pct,
											(int) $bucket['span_days'],
											(int) $bucket['worked_days']
										);
										?>
									</span>
								</span>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>

<?php $axis = $report['axis']; ?>
<?php
// Budget-crossing line: project-level, grouping-independent. Position is the
// same in every group, so it lands once here and holds still as lanes regroup.
$budget_line = $report['budget_line'] ?? null;
$bl_pct      = null;
$bl_rows     = array();
if ( ! empty( $budget_line ) && ! empty( $axis ) ) {
	$bl_pct  = round( PLTT_Project_Report::axis_pct( $axis, $budget_line['date'] ), 2 );
	$bl_rows = array(
		array( __( 'Crossed', 'plain-language-time-tracker' ), date_i18n( 'M j, Y', strtotime( $budget_line['date'] ) ) ),
		array( __( 'Over by', 'plain-language-time-tracker' ), pltt_format_duration( (int) $budget_line['overage_minutes'] ) ),
	);
}
?>
<?php if ( ! empty( $axis ) ) : ?>
<!-- Activity over time (swimlane timeline) — always the full lifetime arc -->
<div class="pltt-card pltt-timeline-card">
	<div class="pltt-where-header">
		<h2 class="pltt-where-title"><?php esc_html_e( 'Activity over time', 'plain-language-time-tracker' ); ?></h2>
	</div>
	<p class="pltt-where-desc">
		<?php if ( null !== $bl_pct ) : ?>
			<?php esc_html_e( 'One lane per group; each bar spans from that group’s first logged day to its last. The amber line marks where cumulative hours crossed the budget — anything to its right is over-budget time, so you can see which groups ran in the red. Hover a bar, a gap, or the line for detail.', 'plain-language-time-tracker' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'One lane per group; each bar spans from that group’s first logged day to its last. The bar breaks where 7+ days passed with nothing logged — a dashed connector marks the pause. Hover a bar or gap for detail.', 'plain-language-time-tracker' ); ?>
		<?php endif; ?>
	</p>

	<?php foreach ( $dimensions as $gkey => $gdata ) : ?>
		<div class="pltt-timeline-group" data-group="<?php echo esc_attr( $gkey ); ?>" <?php echo ( $gkey === $default ) ? '' : 'hidden'; ?>>
			<?php if ( empty( $gdata['buckets'] ) ) : ?>
				<p class="description pltt-bars-empty"><?php esc_html_e( 'Nothing to plot for this grouping.', 'plain-language-time-tracker' ); ?></p>
			<?php else : ?>
				<div class="pltt-timeline">
					<!-- Month axis -->
					<div class="pltt-tl-head">
						<div class="pltt-tl-headlabel"></div>
						<div class="pltt-tl-axis" aria-hidden="true">
							<?php foreach ( $axis['months'] as $m ) : ?>
								<span class="pltt-tl-month" style="left: <?php echo esc_attr( round( $m['pct'], 2 ) ); ?>%;">
									<?php echo esc_html( $m['is_jan'] ? $m['label'] . ' ' . $m['year'] : $m['label'] ); ?>
								</span>
							<?php endforeach; ?>
							<?php if ( null !== $bl_pct ) : ?>
								<span class="pltt-tl-over-label<?php echo ( $bl_pct > 70 ) ? ' pltt-tl-over-label--flip' : ''; ?>" style="left: <?php echo esc_attr( $bl_pct ); ?>%;">
									<span class="pltt-tl-over-caret" aria-hidden="true">&#9662;</span>
									<?php esc_html_e( 'over budget', 'plain-language-time-tracker' ); ?>
								</span>
							<?php endif; ?>
						</div>
					</div>

					<div class="pltt-tl-body">
						<div class="pltt-tl-grid" aria-hidden="true">
							<?php foreach ( $axis['months'] as $m ) : ?>
								<?php if ( $m['gridline'] ) : ?>
									<span class="pltt-tl-gridline" style="left: <?php echo esc_attr( round( $m['pct'], 2 ) ); ?>%;"></span>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>

						<?php if ( null !== $bl_pct ) : ?>
							<div class="pltt-tl-overlay">
								<span class="pltt-tl-over-zone" aria-hidden="true" style="left: <?php echo esc_attr( $bl_pct ); ?>%;"></span>
								<span
									class="pltt-tl-budget-line"
									style="left: <?php echo esc_attr( $bl_pct ); ?>%;"
									data-pltt-tip
									data-tip-title="<?php esc_attr_e( 'Over budget', 'plain-language-time-tracker' ); ?>"
									data-tip-color="none"
									data-tip-rows='<?php echo esc_attr( wp_json_encode( $bl_rows ) ); ?>'
								></span>
								<span class="screen-reader-text">
									<?php
									printf(
										/* translators: 1: crossing date, 2: amount over budget. */
										esc_html__( 'Budget crossed on %1$s; %2$s over budget.', 'plain-language-time-tracker' ),
										esc_html( date_i18n( 'M j, Y', strtotime( $budget_line['date'] ) ) ),
										esc_html( pltt_format_duration( (int) $budget_line['overage_minutes'] ) )
									);
									?>
								</span>
							</div>
						<?php endif; ?>

						<?php $tcolor = 0; ?>
						<?php foreach ( $gdata['buckets'] as $bucket ) : ?>
							<?php
							$tcolor_cls = $bucket['is_untagged'] ? ' pltt-bar-untagged' : ' pltt-bar-color-' . ( $tcolor % 8 );
							if ( ! $bucket['is_untagged'] ) {
								$tcolor++;
							}
							$segments = $bucket['segments'];
							?>
							<div class="pltt-tl-track<?php echo esc_attr( $tcolor_cls ); ?>">
								<div class="pltt-tl-labelwrap">
									<span class="pltt-bar-dot" aria-hidden="true"></span>
									<span class="pltt-tl-label"><?php echo esc_html( $bucket['label'] ); ?></span>
								</div>
								<div class="pltt-tl-lane">
									<?php
									$prev_end = null;
									foreach ( $segments as $seg ) :
										$seg_l = PLTT_Project_Report::axis_pct( $axis, $seg['start'] );
										$seg_r = PLTT_Project_Report::axis_pct( $axis, $seg['end'] );

										// Dashed connector spanning the gap before this segment.
										if ( null !== $prev_end ) :
											$cl   = PLTT_Project_Report::axis_pct( $axis, $prev_end );
											$idle = (int) floor( ( strtotime( $seg['start'] ) - strtotime( $prev_end ) ) / DAY_IN_SECONDS );

											$gap_rows = array(
												array(
													__( 'Span', 'plain-language-time-tracker' ),
													date_i18n( 'M j', strtotime( $prev_end ) ) . ' – ' . date_i18n( 'M j', strtotime( $seg['start'] ) ),
												),
												array(
													__( 'Idle', 'plain-language-time-tracker' ),
													/* translators: %d: number of idle days. */
													sprintf( _n( '%d day', '%d days', $idle, 'plain-language-time-tracker' ), $idle ),
												),
											);
											?>
											<span
												class="pltt-tl-connector"
												style="left: <?php echo esc_attr( round( $cl, 2 ) ); ?>%; width: <?php echo esc_attr( round( max( 0, $seg_l - $cl ), 2 ) ); ?>%;"
												data-pltt-tip
												data-tip-title="<?php esc_attr_e( 'Idle gap', 'plain-language-time-tracker' ); ?>"
												data-tip-color="none"
												data-tip-rows='<?php echo esc_attr( wp_json_encode( $gap_rows ) ); ?>'
											></span>
										<?php endif; ?>

										<?php
										$range_label = ( $seg['start'] === $seg['end'] )
											? date_i18n( 'M j', strtotime( $seg['start'] ) )
											: date_i18n( 'M j', strtotime( $seg['start'] ) ) . ' – ' . date_i18n( 'M j', strtotime( $seg['end'] ) );
										$seg_rows = array(
											array( __( 'Span', 'plain-language-time-tracker' ), $range_label ),
											array( __( 'Time', 'plain-language-time-tracker' ), pltt_format_duration( (int) $seg['minutes'] ) ),
											array(
												__( 'Worked', 'plain-language-time-tracker' ),
												/* translators: %d: number of days worked. */
												sprintf( _n( '%d day', '%d days', (int) $seg['worked_days'], 'plain-language-time-tracker' ), (int) $seg['worked_days'] ),
											),
										);
										?>
										<span
											class="pltt-tl-seg"
											style="left: <?php echo esc_attr( round( $seg_l, 2 ) ); ?>%; width: <?php echo esc_attr( round( max( 0, $seg_r - $seg_l ), 2 ) ); ?>%;"
											data-pltt-tip
											data-tip-title="<?php echo esc_attr( $bucket['label'] ); ?>"
											data-tip-rows='<?php echo esc_attr( wp_json_encode( $seg_rows ) ); ?>'
										></span>
										<?php
										$prev_end = $seg['end'];
									endforeach;
									?>
								</div>
								<span class="screen-reader-text">
									<?php
									printf(
										/* translators: 1: bucket label, 2: worked days, 3: span days, 4: active-stretch count. */
										esc_html__( '%1$s: worked %2$d of %3$d days in %4$d active stretches.', 'plain-language-time-tracker' ),
										esc_html( $bucket['label'] ),
										(int) $bucket['worked_days'],
										(int) $bucket['span_days'],
										count( $segments )
									);
									?>
								</span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

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

<?php
/**
 * Report tab — stat cards + "Where the time went" bars (Phase 2).
 *
 * Expects $report (from PLTT_Project_Report::build()) and $project in scope.
 * The swimlane timeline is Phase 3.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cards     = $report['cards'];
$groupings = $report['groupings'];
$default   = $report['default_group'];

$has_entries = ! empty( $report['has_entries'] );
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

<!-- Where the time went -->
<div class="pltt-card pltt-where-card">
	<div class="pltt-where-header">
		<h2 class="pltt-where-title"><?php esc_html_e( 'Where the time went', 'plain-language-time-tracker' ); ?></h2>
		<?php if ( count( $groupings ) > 1 ) : ?>
			<div class="pltt-groupby">
				<span class="pltt-groupby-label"><?php esc_html_e( 'Group by', 'plain-language-time-tracker' ); ?></span>
				<div class="pltt-groupby-toggle" role="group" aria-label="<?php esc_attr_e( 'Group by', 'plain-language-time-tracker' ); ?>">
					<?php foreach ( $groupings as $gkey => $gdata ) : ?>
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

	<?php foreach ( $groupings as $gkey => $gdata ) : ?>
		<div class="pltt-bars-group" data-group="<?php echo esc_attr( $gkey ); ?>" <?php echo ( $gkey === $default ) ? '' : 'hidden'; ?>>
			<?php if ( ! empty( $gdata['description'] ) ) : ?>
				<p class="pltt-where-desc"><?php echo esc_html( $gdata['description'] ); ?></p>
			<?php endif; ?>
			<?php if ( empty( $gdata['buckets'] ) ) : ?>
				<p class="description pltt-bars-empty">
					<?php
					/* translators: %s: grouping label. */
					printf( esc_html__( 'No %s tags on this project.', 'plain-language-time-tracker' ), esc_html( strtolower( $gdata['label'] ) ) );
					?>
				</p>
			<?php else : ?>
				<?php
				$max         = max( 1, (int) $gdata['max_minutes'] );
				$color_index = 0;
				?>
				<ul class="pltt-bars">
					<?php foreach ( $gdata['buckets'] as $bucket ) : ?>
						<?php
						$width      = (int) round( ( $bucket['minutes'] / $max ) * 100 );
						$pct        = (int) round( $bucket['pct'] * 100 );
						$color_cls  = $bucket['is_untagged'] ? '' : ' pltt-bar-color-' . ( $color_index % 8 );
						if ( ! $bucket['is_untagged'] ) {
							$color_index++;
						}
						?>
						<li class="pltt-bar-row<?php echo $bucket['is_untagged'] ? ' pltt-bar-untagged' : ''; ?><?php echo esc_attr( $color_cls ); ?>">
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
<?php if ( ! empty( $axis ) ) : ?>
<!-- Activity over time (swimlane timeline) -->
<div class="pltt-card pltt-timeline-card">
	<div class="pltt-where-header">
		<h2 class="pltt-where-title"><?php esc_html_e( 'Activity over time', 'plain-language-time-tracker' ); ?></h2>
	</div>
	<p class="pltt-where-desc"><?php esc_html_e( 'One lane per group; each bar spans from that group’s first logged day to its last. The bar breaks where 7+ days passed with nothing logged — a dashed connector marks the pause. Hover a bar or gap for detail.', 'plain-language-time-tracker' ); ?></p>

	<?php foreach ( $groupings as $gkey => $gdata ) : ?>
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
											?>
											<span
												class="pltt-tl-connector"
												style="left: <?php echo esc_attr( round( $cl, 2 ) ); ?>%; width: <?php echo esc_attr( round( max( 0, $seg_l - $cl ), 2 ) ); ?>%;"
												title="<?php echo esc_attr( sprintf( /* translators: 1: start date, 2: end date, 3: idle days. */ __( '%1$s – %2$s · %3$dd idle', 'plain-language-time-tracker' ), date_i18n( 'M j', strtotime( $prev_end ) ), date_i18n( 'M j', strtotime( $seg['start'] ) ), $idle ) ); ?>"
											></span>
										<?php endif; ?>

										<?php
										$range_label = ( $seg['start'] === $seg['end'] )
											? date_i18n( 'M j', strtotime( $seg['start'] ) )
											: date_i18n( 'M j', strtotime( $seg['start'] ) ) . ' – ' . date_i18n( 'M j', strtotime( $seg['end'] ) );
										$seg_title = sprintf(
											/* translators: 1: group label, 2: date range, 3: hours, 4: worked days. */
											__( '%1$s · %2$s · %3$s, %4$dd worked', 'plain-language-time-tracker' ),
											$bucket['label'],
											$range_label,
											pltt_format_duration( (int) $seg['minutes'] ),
											(int) $seg['worked_days']
										);
										?>
										<span
											class="pltt-tl-seg"
											style="left: <?php echo esc_attr( round( $seg_l, 2 ) ); ?>%; width: <?php echo esc_attr( round( max( 0, $seg_r - $seg_l ), 2 ) ); ?>%;"
											title="<?php echo esc_attr( $seg_title ); ?>"
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

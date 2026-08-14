<?php
/**
 * Volume bar chart — billable / non-billable / internal hours per day, week, or
 * month across a date range. Shared by the Reports summary view and the Project
 * Detail report tab.
 *
 * Expects in scope:
 *   $chart             array   The pltt_build_period_chart_data() return
 *                              (buckets, bucket_size, max_minutes, avg_minutes,
 *                              today_key). Unpacked into locals below.
 *   $date_from         string  Range start (Y-m-d) — for the figure caption.
 *   $date_to           string  Range end (Y-m-d).
 * Optional:
 *   $chart_controls    string  Pre-escaped HTML rendered in the header (e.g. a
 *                              period stepper). Omit on the Reports view.
 *   $chart_empty_label string  Label for the "no time logged" note shown when the
 *                              range has buckets but zero minutes.
 *   $chart_cross       array   Fixed-budget crossing mark: { date_label, over_minutes,
 *                              f, floor, n }. Draws a rule where the budget ran out
 *                              and washes everything after it.
 *   $chart_alloc       array   Retainer allocation: { minutes, label, over_periods,
 *                              periods }. Draws a horizontal limit and puts each
 *                              month's excess above it in amber.
 *
 * Both optional marks are set by the Project Detail report only; the Reports
 * summary leaves them unset, so nothing below changes for it.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Unpack the single $chart array (pltt_build_period_chart_data() shape) into the
// locals used below, so callers don't each repeat the unpack.
$chart_buckets     = $chart['buckets'] ?? array();
$chart_bucket_size = $chart['bucket_size'] ?? 'day';
$chart_max_minutes = $chart['max_minutes'] ?? 0;
$chart_avg_minutes = $chart['avg_minutes'] ?? 0;
$chart_today_key   = $chart['today_key'] ?? '';

// No buckets means no range to draw at all — nothing to render.
if ( empty( $chart_buckets ) ) {
	return;
}

$chart_titles = array(
	'day'   => __( 'Hours by day', 'plain-language-time-tracker' ),
	'week'  => __( 'Hours by week', 'plain-language-time-tracker' ),
	'month' => __( 'Hours by month', 'plain-language-time-tracker' ),
);
$chart_caption_formats = array(
	/* translators: %s: human-readable date range, e.g. "May 1–15, 2026". */
	'day'   => __( 'Hours per day for %s, split by how the time is billed.', 'plain-language-time-tracker' ),
	/* translators: %s: human-readable date range. */
	'week'  => __( 'Hours per week for %s, split by how the time is billed.', 'plain-language-time-tracker' ),
	/* translators: %s: human-readable date range. */
	'month' => __( 'Hours per month for %s, split by how the time is billed.', 'plain-language-time-tracker' ),
);
$chart_title = $chart_titles[ $chart_bucket_size ];

// Which series actually appear, so the key can name only those. A fixed-budget
// project bills a fee rather than time, so none of its hours are billable and a
// "Billable client work" swatch stands for a colour that is nowhere in the
// picture. True of any chart, not just that one: a key to an absent colour is
// something to rule out rather than something to read.
$chart_series_minutes = array(
	'billable'    => 0,
	'client_flat' => 0,
	'internal'    => 0,
);
foreach ( $chart_buckets as $chart_bucket ) {
	$chart_series_minutes['billable']    += (int) $chart_bucket['billable_minutes'];
	$chart_series_minutes['client_flat'] += (int) $chart_bucket['client_flat_minutes'];
	$chart_series_minutes['internal']    += (int) $chart_bucket['internal_minutes'];
}

// Optional marks — absent on the Reports summary, so everything they drive
// short-circuits there.
$chart_cross = ( isset( $chart_cross ) && is_array( $chart_cross ) ) ? $chart_cross : null;
$chart_alloc = ( isset( $chart_alloc ) && is_array( $chart_alloc ) ) ? $chart_alloc : null;

// The caption is the figure's whole content for anyone who can't see it, so it
// has to name the split actually drawn — against the allocation, or by billing
// status.
$chart_caption = $chart_alloc
	? sprintf(
		/* translators: 1: the allocation, e.g. "3h"; 2: human-readable date range. */
		__( 'Hours per month against the %1$s monthly allocation, for %2$s.', 'plain-language-time-tracker' ),
		pltt_format_duration( (int) $chart_alloc['minutes'] ),
		pltt_format_date_range( $date_from, $date_to )
	)
	: sprintf( $chart_caption_formats[ $chart_bucket_size ], pltt_format_date_range( $date_from, $date_to ) );

$chart_has_data = ( $chart_max_minutes > 0 );

// Round y-axis ceiling up to a nice number for the visible label (OPT-PERF-B).
$y_ceiling      = pltt_chart_y_ceiling( $chart_max_minutes );
$y_ceiling_mins = $y_ceiling * 60;
// Show per-bar value labels only when there's room (rough threshold).
$chart_show_values = count( $chart_buckets ) <= 14;
?>
<section class="pltt-chart-section" aria-labelledby="pltt-chart-title">
	<?php
	// Header: title, then the span it covers in lighter weight — the panel states
	// its own period rather than leaving you to infer it from the page's filter.
	// A rule under it separates head from body, the same way a table's header band
	// sits on the table it labels.
	?>
	<header class="pltt-panel-header pltt-chart-header">
		<h2 id="pltt-chart-title" class="pltt-panel-title">
			<?php echo esc_html( $chart_title ); ?>
			<span class="pltt-chart-range">&middot; <?php echo esc_html( pltt_format_date_range( $date_from, $date_to ) ); ?></span>
		</h2>
		<?php if ( ! empty( $chart_controls ) ) : ?>
			<?php echo $chart_controls; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller builds escaped markup. ?>
		<?php endif; ?>
	</header>

	<div class="pltt-panel-body">
	<?php if ( ! $chart_has_data ) : ?>
		<p class="pltt-chart-empty">
			<?php
			if ( ! empty( $chart_empty_label ) ) {
				/* translators: %s: period label, e.g. "June 2026". */
				printf( esc_html__( 'No time logged in %s.', 'plain-language-time-tracker' ), esc_html( $chart_empty_label ) );
			} else {
				esc_html_e( 'No time logged in this range.', 'plain-language-time-tracker' );
			}
			?>
		</p>
	<?php else : ?>
		<figure class="pltt-chart" role="figure" aria-label="<?php echo esc_attr( $chart_caption ); ?>">
			<div class="pltt-chart-canvas" aria-hidden="true">
				<div class="pltt-chart-y-axis">
					<span class="pltt-chart-y-label pltt-chart-y-label-top">
						<?php
						/* translators: %s: number of hours, e.g. "20". */
						printf( esc_html__( '%sh', 'plain-language-time-tracker' ), esc_html( number_format( $y_ceiling, ( $y_ceiling < 2 ? 1 : 0 ) ) ) );
						?>
					</span>
					<span class="pltt-chart-y-label pltt-chart-y-label-mid">
					<?php
					/* translators: %s: number of hours, e.g. "10". */
					printf( esc_html__( '%sh', 'plain-language-time-tracker' ), esc_html( number_format( $y_ceiling * 0.5, ( $y_ceiling < 2 ? 1 : 0 ) ) ) );
					?>
				</span>
					<span class="pltt-chart-y-label pltt-chart-y-label-bottom">0</span>
				</div>
				<div class="pltt-chart-plot">
					<?php
					// Where the budget ran out: an amber rule with the work that came
					// after it washed behind the bars. Both sit BEHIND the bars — this
					// is a highlight on the picture, not a label on it, so it carries no
					// text of its own and states its date on hover. Position comes from
					// the crossing DATE, so it sits at the point in the week or month
					// the budget actually went rather than snapped to a bar's edge.
					//
					// The left offset has to reproduce the plot's own flex geometry:
					// columns share the width left after the side padding and the gaps,
					// and each whole column to the left adds one gap. PHP hands over the
					// fractional column index and its floor, since CSS can't floor.
					if ( $chart_cross ) :
						$cross_style = sprintf(
							'--cross-f: %s; --cross-fl: %d; --cross-n: %d;',
							number_format( (float) $chart_cross['f'], 4, '.', '' ),
							(int) $chart_cross['floor'],
							max( 1, (int) $chart_cross['n'] )
						);
						$cross_rows  = array(
							array( __( 'Ran out', 'plain-language-time-tracker' ), $chart_cross['date_label'] ),
							array( __( 'Over by', 'plain-language-time-tracker' ), pltt_format_duration( (int) $chart_cross['over_minutes'] ) ),
						);
						?>
						<div class="pltt-chart-wash" style="<?php echo esc_attr( $cross_style ); ?>"></div>
						<div class="pltt-chart-cross" style="<?php echo esc_attr( $cross_style ); ?>"
							data-pltt-tip
							data-tip-title="<?php esc_attr_e( 'Budget spent', 'plain-language-time-tracker' ); ?>"
							data-tip-color="none"
							data-tip-rows='<?php echo esc_attr( wp_json_encode( $cross_rows ) ); ?>'></div>
					<?php endif; ?>
					<?php
					$show_avg_line = count( $chart_buckets ) >= 2 && $chart_avg_minutes > 0 && $y_ceiling_mins > 0;
					if ( $show_avg_line ) :
						$avg_pct  = $chart_avg_minutes / $y_ceiling_mins;
						$avg_rows = array(
							array(
								__( 'Average', 'plain-language-time-tracker' ),
								pltt_format_duration( $chart_avg_minutes ),
							),
							array(
								'',
								/* translators: %s: bucket label (day/week/month). */
								sprintf( __( 'Empty %ss excluded', 'plain-language-time-tracker' ), $chart_bucket_size ),
							),
						);
						?>
						<div class="pltt-chart-avg-line"
							style="--avg-pct: <?php echo esc_attr( number_format( $avg_pct, 4, '.', '' ) ); ?>;"
							data-pltt-tip
							data-tip-title="<?php
							/* translators: %s: bucket label (day/week/month). */
							echo esc_attr( sprintf( __( 'Per active %s', 'plain-language-time-tracker' ), $chart_bucket_size ) );
							?>"
							data-tip-color="none"
							data-tip-rows='<?php echo esc_attr( wp_json_encode( $avg_rows ) ); ?>'>
							<span class="pltt-chart-avg-label">
								<?php
								/* translators: %s: formatted duration. */
								printf( esc_html__( 'avg %s', 'plain-language-time-tracker' ), esc_html( pltt_format_duration( $chart_avg_minutes ) ) );
								?>
							</span>
						</div>
					<?php endif; ?>
					<?php foreach ( $chart_buckets as $bucket ) :
						$billable_pct    = $y_ceiling_mins > 0 ? ( $bucket['billable_minutes'] / $y_ceiling_mins ) : 0;
						$client_flat_pct = $y_ceiling_mins > 0 ? ( $bucket['client_flat_minutes'] / $y_ceiling_mins ) : 0;
						$internal_pct    = $y_ceiling_mins > 0 ? ( $bucket['internal_minutes'] / $y_ceiling_mins ) : 0;
						$total_minutes   = isset( $bucket['total_minutes'] ) ? $bucket['total_minutes'] : ( $bucket['billable_minutes'] + $bucket['client_flat_minutes'] + $bucket['internal_minutes'] );
						$is_empty        = 0 === $total_minutes;
						$is_today        = ! empty( $chart_today_key ) && $bucket['key'] === $chart_today_key;

						// In allocation mode the bar is split against the limit instead of
						// by billing status: within the allocation, and the part above it.
						// Retainer time is nearly all non-billable by definition, so the
						// three-series split had one colour doing all the work anyway.
						$alloc_minutes  = $chart_alloc ? (int) $chart_alloc['minutes'] : 0;
						$within_minutes = $chart_alloc ? min( $total_minutes, $alloc_minutes ) : 0;
						$over_minutes   = $chart_alloc ? max( 0, $total_minutes - $alloc_minutes ) : 0;
						$within_pct     = ( $chart_alloc && $y_ceiling_mins > 0 ) ? ( $within_minutes / $y_ceiling_mins ) : 0;
						$over_pct       = ( $chart_alloc && $y_ceiling_mins > 0 ) ? ( $over_minutes / $y_ceiling_mins ) : 0;
						// Only carried in allocation mode, so a chart without one emits
						// exactly the style attribute it always did.
						$alloc_vars     = $chart_alloc
							? sprintf(
								' --within-pct: %s; --over-pct: %s;',
								number_format( $within_pct, 4, '.', '' ),
								number_format( $over_pct, 4, '.', '' )
							)
							: '';

						$col_classes = array( 'pltt-chart-col' );
						if ( $is_empty ) $col_classes[] = 'pltt-chart-col-empty';
						if ( $is_today ) $col_classes[] = 'pltt-chart-col-today';

						// Tooltip. A day with nothing logged gets one line saying so —
						// the full split would be four rows of "0m", which is a lot of
						// reading to arrive at "nothing". An empty label renders the row
						// full-width (see pltt-tooltip.js).
						// The dot colours read the same tokens the bar segments do, so the
						// tooltip key can't drift from what's on screen.
						$col_rows = $is_empty
							? array(
								array( '', __( 'No time logged', 'plain-language-time-tracker' ) ),
							)
							: ( $chart_alloc
							? array(
								array( __( 'Within allocation', 'plain-language-time-tracker' ), pltt_format_duration( $within_minutes ), 'var(--pltt-s-bill)' ),
								array( __( 'Overage', 'plain-language-time-tracker' ), pltt_format_duration( $over_minutes ), 'var(--pltt-s-over)' ),
								array( __( 'Total', 'plain-language-time-tracker' ), pltt_format_duration( $total_minutes ) ),
							)
							: array_values( array_filter( array(
								// Short labels here, long ones in the legend. The legend is
								// read once to learn the colours; the tooltip is read at a
								// glance against a value, inside a 280px box that also
								// carries the bucket's date range. The colour dots are what
								// tie the two together, so the wording doesn't have to.
								//
								// A series absent from the whole chart is dropped, the same
								// as in the key: on a fixed-budget project every bar would
								// otherwise open with "Billable 0m · Internal 0m". Judged
								// across the chart, not per bar, so the rows don't shuffle
								// as you move along the bars.
								$chart_series_minutes['billable'] > 0
									? array( __( 'Billable', 'plain-language-time-tracker' ), pltt_format_duration( $bucket['billable_minutes'] ), 'var(--pltt-s-bill)' )
									: null,
								$chart_series_minutes['client_flat'] > 0
									? array( __( 'Non-billable', 'plain-language-time-tracker' ), pltt_format_duration( $bucket['client_flat_minutes'] ), 'var(--pltt-s-nonbill)' )
									: null,
								$chart_series_minutes['internal'] > 0
									? array( __( 'Internal', 'plain-language-time-tracker' ), pltt_format_duration( $bucket['internal_minutes'] ), 'var(--pltt-s-own)' )
									: null,
								array( __( 'Total', 'plain-language-time-tracker' ), pltt_format_duration( $total_minutes ) ),
							) ) ) );
						?>
						<div class="<?php echo esc_attr( implode( ' ', $col_classes ) ); ?>"
							style="--billable-pct: <?php echo esc_attr( number_format( $billable_pct, 4, '.', '' ) ); ?>; --client-flat-pct: <?php echo esc_attr( number_format( $client_flat_pct, 4, '.', '' ) ); ?>; --internal-pct: <?php echo esc_attr( number_format( $internal_pct, 4, '.', '' ) ); ?>;<?php echo esc_attr( $alloc_vars ); ?>"
							data-pltt-tip
							data-tip-title="<?php echo esc_attr( $bucket['long'] ); ?>"
							data-tip-color="none"
							data-tip-rows='<?php echo esc_attr( wp_json_encode( $col_rows ) ); ?>'>
							<div class="pltt-chart-bar">
								<?php if ( $chart_show_values && ! $is_empty ) : ?>
									<span class="pltt-chart-value"><?php echo esc_html( pltt_format_duration( $total_minutes ) ); ?></span>
								<?php endif; ?>
								<?php
								// Only render segments with time, top → bottom. The first one
								// rendered is the topmost visible segment, so the CSS rounds it
								// (a zero-height span would still count for :first-of-type, so
								// omitting empties is what keeps the rounding on the real top).
								?>
								<?php if ( $chart_alloc ) : ?>
									<?php if ( $over_minutes > 0 ) : ?>
										<span class="pltt-chart-seg pltt-chart-seg-over"></span>
									<?php endif; ?>
									<?php if ( $within_minutes > 0 ) : ?>
										<span class="pltt-chart-seg pltt-chart-seg-within"></span>
									<?php endif; ?>
								<?php else : ?>
									<?php if ( $bucket['internal_minutes'] > 0 ) : ?>
										<span class="pltt-chart-seg pltt-chart-seg-internal"></span>
									<?php endif; ?>
									<?php if ( $bucket['client_flat_minutes'] > 0 ) : ?>
										<span class="pltt-chart-seg pltt-chart-seg-client-flat"></span>
									<?php endif; ?>
									<?php if ( $bucket['billable_minutes'] > 0 ) : ?>
										<span class="pltt-chart-seg pltt-chart-seg-billable"></span>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="pltt-chart-x-axis" aria-hidden="true">
				<div class="pltt-chart-x-spacer"></div>
				<div class="pltt-chart-x-ticks">
					<?php
					// On dense daily views (typically a full month or longer), hide every
					// other label to reduce x-axis clutter. Today's label always shows.
					$skip_alt_labels = ( 'day' === $chart_bucket_size && count( $chart_buckets ) > 20 );
					foreach ( $chart_buckets as $i => $bucket ) :
						$tick_is_today = ! empty( $chart_today_key ) && $bucket['key'] === $chart_today_key;
						$hide_label    = $skip_alt_labels && ( $i % 2 !== 0 ) && ! $tick_is_today;
						?>
						<span class="pltt-chart-x-tick<?php echo $tick_is_today ? ' pltt-chart-x-tick-today' : ''; ?>">
							<span class="pltt-chart-x-label<?php echo $hide_label ? ' screen-reader-text' : ''; ?>">
								<?php if ( ! empty( $bucket['short_top'] ) ) : ?>
									<span class="pltt-chart-x-label-top"><?php echo esc_html( $bucket['short_top'] ); ?></span>
								<?php endif; ?>
								<span class="pltt-chart-x-label-bottom"><?php echo esc_html( $bucket['short'] ); ?></span>
							</span>
						</span>
					<?php endforeach; ?>
				</div>
			</div>
		</figure>

		<?php
		// Key at the foot of the panel, left-aligned under the bars it explains,
		// separated by a rule. It sat in the header, which put the key further from
		// the marks than the title was. Omitted when there are no bars — a key to
		// nothing is just three words about colour.
		// aria-hidden: the figure's aria-label already names the three series.
		?>
		<?php
		// The marks in words. Everything inside the figure is aria-hidden (it's a
		// picture), and the crossing rule is a hover target rather than a focus
		// stop, so this sentence is the whole of it for anyone not looking at bars.
		?>
		<?php if ( $chart_cross ) : ?>
			<p class="screen-reader-text">
				<?php
				printf(
					/* translators: 1: date the budget ran out; 2: hours past the budget. */
					esc_html__( 'The budget ran out on %1$s. The project is %2$s past it.', 'plain-language-time-tracker' ),
					esc_html( $chart_cross['date_label'] ),
					esc_html( pltt_format_duration( (int) $chart_cross['over_minutes'] ) )
				);
				?>
			</p>
		<?php endif; ?>
		<ul class="pltt-chart-legend" aria-hidden="true">
			<?php if ( $chart_alloc ) : ?>
				<li><span class="pltt-chart-swatch pltt-chart-swatch-within"></span><?php esc_html_e( 'Within allocation', 'plain-language-time-tracker' ); ?></li>
				<li><span class="pltt-chart-swatch pltt-chart-swatch-over"></span><?php esc_html_e( 'Overage', 'plain-language-time-tracker' ); ?></li>
			<?php else : ?>
				<?php if ( $chart_series_minutes['billable'] > 0 ) : ?>
					<li><span class="pltt-chart-swatch pltt-chart-swatch-billable"></span><?php esc_html_e( 'Billable client work', 'plain-language-time-tracker' ); ?></li>
				<?php endif; ?>
				<?php if ( $chart_series_minutes['client_flat'] > 0 ) : ?>
					<li><span class="pltt-chart-swatch pltt-chart-swatch-client-flat"></span><?php esc_html_e( 'Non-billable client work', 'plain-language-time-tracker' ); ?></li>
				<?php endif; ?>
				<?php if ( $chart_series_minutes['internal'] > 0 ) : ?>
					<li><span class="pltt-chart-swatch pltt-chart-swatch-internal"></span><?php esc_html_e( 'Internal', 'plain-language-time-tracker' ); ?></li>
				<?php endif; ?>
				<?php // The tint has no label of its own on the chart, so the key is where it gets named. ?>
				<?php if ( $chart_cross ) : ?>
					<li><span class="pltt-chart-swatch pltt-chart-swatch-past"></span><?php esc_html_e( 'Past the budget', 'plain-language-time-tracker' ); ?></li>
				<?php endif; ?>
			<?php endif; ?>
		</ul>
	<?php endif; ?>
	</div>
</section>

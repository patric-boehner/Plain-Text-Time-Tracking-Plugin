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
	'day'   => __( 'Billable, non-billable, and internal hours per day for %s.', 'plain-language-time-tracker' ),
	/* translators: %s: human-readable date range. */
	'week'  => __( 'Billable, non-billable, and internal hours per week for %s.', 'plain-language-time-tracker' ),
	/* translators: %s: human-readable date range. */
	'month' => __( 'Billable, non-billable, and internal hours per month for %s.', 'plain-language-time-tracker' ),
);
$chart_title   = $chart_titles[ $chart_bucket_size ];
$chart_caption = sprintf( $chart_caption_formats[ $chart_bucket_size ], pltt_format_date_range( $date_from, $date_to ) );

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
							: array(
								// Short labels here, long ones in the legend. The legend is
								// read once to learn the colours; the tooltip is read at a
								// glance against a value, inside a 280px box that also
								// carries the bucket's date range. The colour dots are what
								// tie the two together, so the wording doesn't have to.
								array( __( 'Billable', 'plain-language-time-tracker' ), pltt_format_duration( $bucket['billable_minutes'] ), 'var(--pltt-s-bill)' ),
								array( __( 'Non-billable', 'plain-language-time-tracker' ), pltt_format_duration( $bucket['client_flat_minutes'] ), 'var(--pltt-s-nonbill)' ),
								array( __( 'Internal', 'plain-language-time-tracker' ), pltt_format_duration( $bucket['internal_minutes'] ), 'var(--pltt-s-own)' ),
								array( __( 'Total', 'plain-language-time-tracker' ), pltt_format_duration( $total_minutes ) ),
							);
						?>
						<div class="<?php echo esc_attr( implode( ' ', $col_classes ) ); ?>"
							style="--billable-pct: <?php echo esc_attr( number_format( $billable_pct, 4, '.', '' ) ); ?>; --client-flat-pct: <?php echo esc_attr( number_format( $client_flat_pct, 4, '.', '' ) ); ?>; --internal-pct: <?php echo esc_attr( number_format( $internal_pct, 4, '.', '' ) ); ?>;"
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
								<?php if ( $bucket['internal_minutes'] > 0 ) : ?>
									<span class="pltt-chart-seg pltt-chart-seg-internal"></span>
								<?php endif; ?>
								<?php if ( $bucket['client_flat_minutes'] > 0 ) : ?>
									<span class="pltt-chart-seg pltt-chart-seg-client-flat"></span>
								<?php endif; ?>
								<?php if ( $bucket['billable_minutes'] > 0 ) : ?>
									<span class="pltt-chart-seg pltt-chart-seg-billable"></span>
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
		<ul class="pltt-chart-legend" aria-hidden="true">
			<li><span class="pltt-chart-swatch pltt-chart-swatch-billable"></span><?php esc_html_e( 'Billable client work', 'plain-language-time-tracker' ); ?></li>
			<li><span class="pltt-chart-swatch pltt-chart-swatch-client-flat"></span><?php esc_html_e( 'Non-billable client work', 'plain-language-time-tracker' ); ?></li>
			<li><span class="pltt-chart-swatch pltt-chart-swatch-internal"></span><?php esc_html_e( 'Internal', 'plain-language-time-tracker' ); ?></li>
		</ul>
	<?php endif; ?>
	</div>
</section>

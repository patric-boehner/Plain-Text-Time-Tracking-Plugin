<?php
/**
 * Log Archive template.
 *
 * @package PlainLanguageTimeTracker
 *
 * @var array  $logs            Paginated log objects.
 * @var string $today           Today's date (YYYY-MM-DD).
 * @var string $date_from       Start date (YYYY-MM-DD).
 * @var string $date_to         End date (YYYY-MM-DD).
 * @var string $nav_label       Formatted nav label (e.g. "March 2026").
 * @var string $active_year     4-digit year string for the active month.
 * @var bool   $multi_year      Whether data spans more than one year.
 * @var array  $months_by_year  Map of year => array of YYYY-MM strings (newest first).
 * @var string $prev_url        URL for the previous month.
 * @var string $next_url        URL for the next month (empty string if none).
 * @var bool   $has_next        Whether a next month exists.
 * @var int    $total_logs      Total log count.
 * @var int    $total_pages     Total pages.
 * @var int    $paged           Current page number.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<?php
		// Light header, same as Today and the Overview: the count that used to sit
		// in a one-figure card is a subline instead (spec §10). A single card is a
		// lot of chrome around one number.
		?>
		<div class="pltt-light-header">
			<div class="pltt-lh-titlerow">
				<h1><?php esc_html_e( 'History', 'plain-language-time-tracker' ); ?></h1>
			</div>
			<?php if ( $total_logs > 0 ) : ?>
				<div class="pltt-lh-l2">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of daily logs in the month. */
							_n( '%s daily log', '%s daily logs', $total_logs, 'plain-language-time-tracker' ),
							number_format_i18n( $total_logs )
						)
					);
					?>
				</div>
			<?php endif; ?>
		</div>

	<?php // Date nav sits in the header (top-right), where the Today · History toggle used to be. ?>
	<form method="get" action="" class="pltt-report-filters-form">
		<input type="hidden" name="page" value="pltt-log-archive">
		<input type="hidden" name="from" id="pltt-date-from" value="<?php echo esc_attr( $date_from ); ?>">
		<input type="hidden" name="to"   id="pltt-date-to"   value="<?php echo esc_attr( $date_to ); ?>">

		<?php
		// Shared month picker (templates/partials/month-picker.php). Months here are
		// form-submitting options — the screen is driven by the hidden from/to pair
		// above — so each carries from/to rather than a URL.
		$mp_years = array();
		foreach ( $months_by_year as $year => $year_months ) {
			$mp_years[ $year ] = array();
			foreach ( $year_months as $ym ) {
				$ym_dt   = new DateTimeImmutable( $ym . '-01', wp_timezone() );
				$ym_from = $ym_dt->format( 'Y-m-d' );
				// The current month runs to today, not to a future month-end.
				$ym_to = ( $ym === substr( pltt_get_current_date(), 0, 7 ) )
					? pltt_get_current_date()
					: $ym_dt->format( 'Y-m-t' );

				$mp_years[ $year ][] = array(
					'label'  => $ym_dt->format( 'F' ),
					'from'   => $ym_from,
					'to'     => $ym_to,
					'active' => ( $ym_from === $date_from ),
				);
			}
		}

		$mp_label       = $nav_label;
		$mp_active_year = $active_year;
		$mp_prev        = $prev_url;
		$mp_next        = $has_next ? $next_url : '';
		$mp_aria        = __( 'Month navigation', 'plain-language-time-tracker' );
		$mp_reset       = ( substr( $date_from, 0, 7 ) !== substr( $today, 0, 7 ) )
			? array(
				'url'   => pltt_get_admin_url( 'history' ),
				'label' => __( 'This Month', 'plain-language-time-tracker' ),
			)
			: null;

		include PLTT_PLUGIN_DIR . 'templates/partials/month-picker.php';
		?>
		</form>
	</div>

	<?php
	// The H1 is nested in the light header — see pltt_header_end().
	pltt_header_end();
	?>

	<div class="pltt-report-content">
		<?php if ( ! empty( $logs ) ) : ?>
			<?php
			// Group logs by week, respecting WordPress's configured week start day.
			$start_of_week = (int) get_option( 'start_of_week', 0 );
			$logs_by_week  = array();
			foreach ( $logs as $log ) {
				$date_obj = new DateTimeImmutable( $log->log_date, wp_timezone() );
				$dow      = (int) $date_obj->format( 'w' );
				$diff     = ( $dow - $start_of_week + 7 ) % 7;
				$week_key = $date_obj->modify( "-{$diff} days" )->format( 'Y-m-d' );
				$logs_by_week[ $week_key ][] = $log;
			}
			?>
			<?php foreach ( $logs_by_week as $week_start_date => $week_logs ) : ?>
				<?php
				$week_start_obj = new DateTimeImmutable( $week_start_date, wp_timezone() );
				$week_end_obj   = $week_start_obj->modify( '+6 days' );
				$week_label     = $week_start_obj->format( 'M j' ) . '–' . $week_end_obj->format( 'M j, Y' );

				// Week totals in the band (spec §10) — days and hours, matching the
				// day-card headers on Entries. Hours in h/m, not decimals.
				$week_minutes = 0;
				foreach ( $week_logs as $week_log ) {
					$week_minutes += (int) $week_log->total_minutes;
				}
				$week_days = count( $week_logs );
				?>
				<div class="pltt-week-group">
					<div class="pltt-week-group-header">
						<span class="pltt-week-group-title"><?php echo esc_html( sprintf( __( 'Week of %s', 'plain-language-time-tracker' ), $week_label ) ); ?></span>
						<span class="pltt-week-group-meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of days logged that week. */
									_n( '%s day', '%s days', $week_days, 'plain-language-time-tracker' ),
									number_format_i18n( $week_days )
								)
							);
							?>
							<?php if ( $week_minutes > 0 ) : ?>
								&middot;
								<span class="pltt-mono"><?php echo esc_html( pltt_format_duration( $week_minutes ) ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<table class="widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'plain-language-time-tracker' ); ?></th>
								<th><?php esc_html_e( 'Preview', 'plain-language-time-tracker' ); ?></th>
								<th><?php esc_html_e( 'Entries', 'plain-language-time-tracker' ); ?></th>
								<th><?php esc_html_e( 'Hours', 'plain-language-time-tracker' ); ?></th>
								<th><?php esc_html_e( 'Status', 'plain-language-time-tracker' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $week_logs as $log ) : ?>
								<tr data-log-date="<?php echo esc_attr( $log->log_date ); ?>" data-entry-count="<?php echo esc_attr( $log->entry_count ); ?>">
									<td>
										<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $log->log_date ) ) ); ?>">
											<strong><?php echo esc_html( pltt_format_date( $log->log_date, 'D, M j, Y' ) ); ?></strong>
										</a>
										<div class="row-actions">
											<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $log->log_date ) ) ); ?>">
												<?php esc_html_e( 'View', 'plain-language-time-tracker' ); ?>
											</a>
											| <a href="#" class="pltt-delete-log submitdelete" role="button">
												<?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?>
											</a>
										</div>
									</td>
									<td class="pltt-log-preview">
										<?php
										if ( ! empty( $log->content ) ) {
											$preview = wp_strip_all_tags( $log->content );
											$preview = preg_replace( '/\s+/', ' ', $preview );
											echo esc_html( mb_strimwidth( $preview, 0, 80, '...' ) );
										} else {
											echo '<span class="pltt-empty">' . esc_html__( 'Empty', 'plain-language-time-tracker' ) . '</span>';
										}
										?>
									</td>
									<td>
										<?php if ( (int) $log->entry_count > 0 ) : ?>
											<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $log->log_date ) ) ); ?>#pltt-entries">
												<?php echo esc_html( $log->entry_count ); ?>
											</a>
										<?php else : ?>
											<span class="pltt-empty">0</span>
										<?php endif; ?>
									</td>
									<td class="pltt-duration-cell">
										<?php
										if ( (int) $log->total_minutes > 0 ) {
											echo esc_html( pltt_format_hours( $log->total_minutes ) );
										} else {
											echo '<span class="pltt-empty">—</span>';
										}
										?>
									</td>
									<td>
										<?php if ( $log->processed ) : ?>
											<span class="pltt-badge pltt-badge-success"><?php esc_html_e( 'Processed', 'plain-language-time-tracker' ); ?></span>
										<?php else : ?>
											<span class="pltt-badge pltt-badge-warning"><?php esc_html_e( 'Not processed', 'plain-language-time-tracker' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; // week_logs ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; // logs_by_week ?>


			<?php
			$base_url = add_query_arg( array( 'from' => $date_from, 'to' => $date_to ), pltt_get_admin_url( 'history' ) );
			pltt_render_pagination( $paged, $total_pages, $total_logs, $base_url, 'log', 'logs' );
			?>


		<?php else : ?>
			<?php pltt_render_empty_state( __( 'No logs found for the selected month.', 'plain-language-time-tracker' ) ); ?>
		<?php endif; ?>
	</div>
</div>

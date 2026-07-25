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

		<div class="pltt-date-nav-row">
			<nav class="pltt-date-nav"
				aria-label="<?php esc_attr_e( 'Month navigation', 'plain-language-time-tracker' ); ?>">

				<?php if ( $prev_url ) : ?>
				<a href="<?php echo esc_url( $prev_url ); ?>"
					class="pltt-date-nav-step pltt-date-nav-prev"
					aria-label="<?php esc_attr_e( 'Previous month', 'plain-language-time-tracker' ); ?>"></a>
				<?php endif; ?>

				<div class="pltt-date-nav-picker">
					<button type="button" class="pltt-date-nav-label"
						aria-expanded="false"
						id="pltt-date-nav-trigger">
						<span class="pltt-date-nav-label-main"><?php echo esc_html( $nav_label ); ?></span>
					</button>

					<div class="pltt-date-nav-dropdown" hidden>

						<?php if ( $multi_year ) : ?>
							<div class="pltt-date-nav-year-switcher" data-year="<?php echo esc_attr( $active_year ); ?>">
								<button type="button" class="pltt-date-nav-year-prev"
									aria-label="<?php esc_attr_e( 'Previous year', 'plain-language-time-tracker' ); ?>">&#8249;</button>
								<span class="pltt-date-nav-year-label"><?php echo esc_html( $active_year ); ?></span>
								<button type="button" class="pltt-date-nav-year-next"
									aria-label="<?php esc_attr_e( 'Next year', 'plain-language-time-tracker' ); ?>">&#8250;</button>
							</div>
							<hr class="pltt-date-nav-separator">
						<?php endif; ?>

						<?php foreach ( $months_by_year as $year => $year_months ) : ?>
							<div class="pltt-date-nav-year-months"
								data-year="<?php echo esc_attr( $year ); ?>"
								<?php if ( (string) $year !== (string) $active_year ) : ?>hidden<?php endif; ?>>
								<ul class="pltt-date-nav-options">
								<?php foreach ( $year_months as $ym ) :
									$ym_dt   = new DateTimeImmutable( $ym . '-01', wp_timezone() );
									$ym_from = $ym_dt->format( 'Y-m-d' );
									$ym_to   = ( $ym === substr( pltt_get_current_date(), 0, 7 ) )
										? pltt_get_current_date()
										: $ym_dt->format( 'Y-m-t' );
									$is_active = ( $ym_from === $date_from );
									?>
									<li><button type="button"
										class="pltt-date-nav-option"
										data-from="<?php echo esc_attr( $ym_from ); ?>"
										data-to="<?php echo esc_attr( $ym_to ); ?>"
										<?php if ( $is_active ) : ?>aria-current="true"<?php endif; ?>>
										<?php echo esc_html( $ym_dt->format( 'F' ) ); ?>
									</button></li>
								<?php endforeach; ?>
								</ul>
							</div>
						<?php endforeach; ?>

					</div>
				</div>

				<?php if ( $has_next ) : ?>
					<a href="<?php echo esc_url( $next_url ); ?>"
						class="pltt-date-nav-step pltt-date-nav-next"
						aria-label="<?php esc_attr_e( 'Next month', 'plain-language-time-tracker' ); ?>"></a>
				<?php endif; ?>

			</nav>

			<?php if ( substr( $date_from, 0, 7 ) !== substr( $today, 0, 7 ) ) : ?>
				<a href="<?php echo esc_url( pltt_get_admin_url( 'history' ) ); ?>"
					class="button button-secondary">
					<?php esc_html_e( 'This Month', 'plain-language-time-tracker' ); ?>
				</a>
			<?php endif; ?>
		</div>
		</form>
	</div>

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
			<p class="description" style="padding: 20px; text-align: center;">
				<?php esc_html_e( 'No logs found for the selected month.', 'plain-language-time-tracker' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>

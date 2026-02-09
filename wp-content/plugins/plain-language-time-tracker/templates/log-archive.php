<?php
/**
 * Log Archive template.
 *
 * @package PlainLanguageTimeTracker
 *
 * @var array  $logs        Paginated log objects.
 * @var array  $months      Available months (YYYY-MM strings).
 * @var string $month       Current month filter.
 * @var int    $total_logs  Total log count.
 * @var int    $total_pages Total pages.
 * @var int    $paged       Current page number.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Log History', 'plain-language-time-tracker' ); ?></h1>
	</div>

	<?php if ( ! empty( $months ) ) : ?>
		<div class="pltt-report-filters">
			<form method="get" action="">
				<input type="hidden" name="page" value="pltt-log-archive">

				<div class="pltt-filter-row">
					<div class="pltt-filter-group">
						<label for="pltt-month-filter"><?php esc_html_e( 'Month', 'plain-language-time-tracker' ); ?></label>
						<select name="month" id="pltt-month-filter">
							<option value=""><?php esc_html_e( 'All months', 'plain-language-time-tracker' ); ?></option>
							<?php foreach ( $months as $m ) : ?>
								<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $month, $m ); ?>>
									<?php echo esc_html( pltt_format_date( $m . '-01', 'F Y' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="pltt-filter-group pltt-filter-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'plain-language-time-tracker' ); ?></button>
						<?php if ( ! empty( $month ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=pltt-log-archive' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'plain-language-time-tracker' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( $total_logs > 0 ) : ?>
		<div class="pltt-summary-cards">
			<div class="card">
				<div class="card-value"><?php echo esc_html( $total_logs ); ?></div>
				<div class="card-label"><?php esc_html_e( 'Daily Logs', 'plain-language-time-tracker' ); ?></div>
			</div>
		</div>
	<?php endif; ?>

	<div class="pltt-report-content">
		<?php if ( ! empty( $logs ) ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'plain-language-time-tracker' ); ?></th>
						<th><?php esc_html_e( 'Preview', 'plain-language-time-tracker' ); ?></th>
						<th><?php esc_html_e( 'Status', 'plain-language-time-tracker' ); ?></th>
						<th><?php esc_html_e( 'Entries', 'plain-language-time-tracker' ); ?></th>
						<th><?php esc_html_e( 'Hours', 'plain-language-time-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $logs as $log ) : ?>
						<tr data-log-date="<?php echo esc_attr( $log->log_date ); ?>" data-entry-count="<?php echo esc_attr( $log->entry_count ); ?>">
							<td>
								<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $log->log_date ) ) ); ?>">
									<strong><?php echo esc_html( pltt_format_date( $log->log_date, 'D, M j, Y' ) ); ?></strong>
								</a>
								<div class="row-actions">
									<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $log->log_date ) ) ); ?>">
									<?php esc_html_e( 'View Log', 'plain-language-time-tracker' ); ?>
									</a>
									<?php if ( (int) $log->entry_count > 0 ) : ?>
										| <a href="<?php echo esc_url( pltt_get_admin_url( 'review', array( 'date' => $log->log_date ) ) ); ?>">
											<?php esc_html_e( 'Review Entries', 'plain-language-time-tracker' ); ?>
										</a>
									<?php endif; ?>
									| <a href="#" class="pltt-delete-log pltt-link-danger">
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
								<?php if ( $log->processed ) : ?>
									<span class="pltt-badge pltt-badge-success"><?php esc_html_e( 'Processed', 'plain-language-time-tracker' ); ?></span>
								<?php else : ?>
									<span class="pltt-badge pltt-badge-warning"><?php esc_html_e( 'Not processed', 'plain-language-time-tracker' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( (int) $log->entry_count > 0 ) : ?>
									<a href="<?php echo esc_url( pltt_get_admin_url( 'review', array( 'date' => $log->log_date ) ) ); ?>">
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
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: total logs */
								esc_html( _n( '%s log', '%s logs', $total_logs, 'plain-language-time-tracker' ) ),
								number_format_i18n( $total_logs )
							);
							?>
						</span>
						<span class="pagination-links">
							<?php
							$base_url = admin_url( 'admin.php?page=pltt-log-archive' );
							if ( ! empty( $month ) ) {
								$base_url = add_query_arg( 'month', $month, $base_url );
							}

							// First page.
							if ( $paged > 1 ) :
								?>
								<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $base_url ) ); ?>">
									&laquo;
								</a>
								<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ); ?>">
									&lsaquo;
								</a>
							<?php else : ?>
								<span class="tablenav-pages-navspan button disabled">&laquo;</span>
								<span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
							<?php endif; ?>

							<span class="paging-input">
								<?php echo esc_html( $paged ); ?>
								<?php esc_html_e( 'of', 'plain-language-time-tracker' ); ?>
								<span class="total-pages"><?php echo esc_html( $total_pages ); ?></span>
							</span>

							<?php if ( $paged < $total_pages ) : ?>
								<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ); ?>">
									&rsaquo;
								</a>
								<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ); ?>">
									&raquo;
								</a>
							<?php else : ?>
								<span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
								<span class="tablenav-pages-navspan button disabled">&raquo;</span>
							<?php endif; ?>
						</span>
					</div>
				</div>
			<?php endif; ?>

		<?php else : ?>
			<div class="pltt-notice pltt-notice-info">
				<p>
					<?php if ( ! empty( $month ) ) : ?>
						<?php esc_html_e( 'No logs found for the selected month.', 'plain-language-time-tracker' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'No daily logs yet. Start by writing your first daily log!', 'plain-language-time-tracker' ); ?>
						<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log' ) ); ?>">
							<?php esc_html_e( 'Go to Daily Log', 'plain-language-time-tracker' ); ?> &rarr;
						</a>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>
	</div>
</div>

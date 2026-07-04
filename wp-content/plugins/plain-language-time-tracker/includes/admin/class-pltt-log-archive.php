<?php
/**
 * Log Archive screen.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Log Archive admin screen.
 */
class PLTT_Log_Archive {

	/**
	 * Logs per page.
	 */
	const PER_PAGE = PLTT_LOGS_PER_PAGE;

	/**
	 * Render the Log Archive screen.
	 */
	public static function render() {
		$today    = pltt_get_current_date();
		$today_dt = new DateTimeImmutable( $today, wp_timezone() );
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = self::PER_PAGE;
		$offset   = ( $paged - 1 ) * $per_page;

		// Support old ?month=YYYY-MM bookmarks by converting to from/to.
		if ( ! isset( $_GET['from'] ) && ! empty( $_GET['month'] ) ) {
			$old_month = sanitize_text_field( wp_unslash( $_GET['month'] ) );
			if ( preg_match( '/^\d{4}-\d{2}$/', $old_month ) ) {
				$old_dt   = new DateTimeImmutable( $old_month . '-01', wp_timezone() );
				$_GET['from'] = $old_dt->format( 'Y-m-d' );
				$_GET['to']   = ( $old_dt->format( 'Y-m' ) === $today_dt->format( 'Y-m' ) )
					? $today
					: $old_dt->format( 'Y-m-t' );
			}
		}

		// Resolve from/to params; default to current month.
		$raw_from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$raw_to    = isset( $_GET['to'] )   ? sanitize_text_field( wp_unslash( $_GET['to'] ) )   : '';
		$date_from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_from ) ? $raw_from : $today_dt->format( 'Y-m-01' );
		$date_to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_to )   ? $raw_to   : $today;

		// Clamp: ensure date_to is not in the future.
		if ( $date_to > $today ) {
			$date_to = $today;
		}

		$filter_args = array(
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);

		$total_logs  = PLTT_Daily_Log::count_all( $filter_args );
		$total_pages = $total_logs > 0 ? (int) ceil( $total_logs / $per_page ) : 1;

		$logs = PLTT_Daily_Log::get_all(
			array_merge(
				$filter_args,
				array(
					'limit'  => $per_page,
					'offset' => $offset,
				)
			)
		);

		// Build months-by-year map for the nav dropdown.
		$all_months    = PLTT_Daily_Log::get_logged_months(); // YYYY-MM strings, newest first.
		$months_by_year = array();
		foreach ( $all_months as $ym ) {
			$year = substr( $ym, 0, 4 );
			if ( ! isset( $months_by_year[ $year ] ) ) {
				$months_by_year[ $year ] = array();
			}
			$months_by_year[ $year ][] = $ym;
		}
		// Ensure current year is present even if no logs yet (so nav renders).
		$current_year = $today_dt->format( 'Y' );
		if ( ! isset( $months_by_year[ $current_year ] ) ) {
			$months_by_year[ $current_year ] = array();
		}

		$active_year = substr( $date_from, 0, 4 );
		$multi_year  = count( $months_by_year ) > 1;

		// Nav label.
		$nav_label = pltt_format_date( $date_from, 'F Y' );

		// Prev / next month URLs.
		$active_dt  = new DateTimeImmutable( $date_from, wp_timezone() );
		$prev_month = $active_dt->modify( 'first day of last month' );
		$next_month = $active_dt->modify( 'first day of next month' );

		$prev_from = $prev_month->format( 'Y-m-d' );
		$prev_to   = $prev_month->format( 'Y-m-t' );

		$next_from = $next_month->format( 'Y-m-d' );
		$next_to   = ( $next_month->format( 'Y-m' ) === $today_dt->format( 'Y-m' ) )
			? $today
			: $next_month->format( 'Y-m-t' );
		$has_next  = $next_month->format( 'Y-m' ) <= $today_dt->format( 'Y-m' );

		// History is a sub-view of Today now (?page=pltt-time-tracker&screen=history).
		$base_archive_url = pltt_get_admin_url( 'history' );
		$prev_url         = add_query_arg( array( 'from' => $prev_from, 'to' => $prev_to ), $base_archive_url );
		$next_url         = $has_next ? add_query_arg( array( 'from' => $next_from, 'to' => $next_to ), $base_archive_url ) : '';

		include PLTT_PLUGIN_DIR . 'templates/log-archive.php';
	}
}

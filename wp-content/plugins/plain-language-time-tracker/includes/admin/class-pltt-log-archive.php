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
		$month    = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = self::PER_PAGE;
		$offset   = ( $paged - 1 ) * $per_page;

		$filter_args = array();
		if ( ! empty( $month ) ) {
			$filter_args['month'] = $month;
		}

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

		$months = PLTT_Daily_Log::get_logged_months();

		include PLTT_PLUGIN_DIR . 'templates/log-archive.php';
	}
}

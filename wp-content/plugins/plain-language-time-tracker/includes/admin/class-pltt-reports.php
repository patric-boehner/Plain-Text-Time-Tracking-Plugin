<?php
/**
 * Reports screen.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Reports admin screen.
 */
class PLTT_Reports {

	/**
	 * Entries per page on the reports screen.
	 */
	const PER_PAGE = PLTT_ENTRIES_PER_PAGE;

	/**
	 * Render the Reports screen.
	 */
	public static function render() {
		$date_from  = isset( $_GET['from'] ) ? pltt_sanitize_date( wp_unslash( $_GET['from'] ) ) : current_time( 'Y-m-01' );
		$date_to    = isset( $_GET['to'] ) ? pltt_sanitize_date( wp_unslash( $_GET['to'] ) ) : pltt_get_current_date();
		$view       = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'detailed';
		$client_id  = isset( $_GET['client_id'] ) ? absint( $_GET['client_id'] ) : 0;
		$project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0;
		$tag        = isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '';
		$billable   = isset( $_GET['billable'] ) && '' !== $_GET['billable'] ? absint( $_GET['billable'] ) : null;

		// Negate flags for exclusion filters.
		$client_negate  = ! empty( $_GET['client_negate'] ) ? 1 : 0;
		$project_negate = ! empty( $_GET['project_negate'] ) ? 1 : 0;
		$tag_negate     = ! empty( $_GET['tag_negate'] ) ? 1 : 0;

		// Whitelist valid views.
		if ( ! in_array( $view, array( 'detailed', 'summary' ), true ) ) {
			$view = 'detailed';
		}

		// Build filter args shared across all queries.
		$filter_args = array(
			'date_from'      => $date_from,
			'date_to'        => $date_to,
			'client_id'      => $client_id,
			'project_id'     => $project_id,
			'tag'            => $tag,
			'billable'       => $billable,
			'client_negate'  => $client_negate,
			'project_negate' => $project_negate,
			'tag_negate'     => $tag_negate,
		);

		// Get summary stats in one query (independent of view/pagination).
		$stats = PLTT_Entries::get_stats( $filter_args );

		$total_entries = $stats ? (int) $stats->total_count : 0;

		// View-specific data.
		$entries     = array();
		$summary     = array();
		$total_pages = 1;
		$paged       = 1;

		if ( 'summary' === $view ) {
			$summary = PLTT_Entries::get_summary_by_project( $date_from, $date_to, $filter_args );
		} else {
			$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
			$per_page   = self::PER_PAGE;
			$offset     = ( $paged - 1 ) * $per_page;
			$total_pages = $total_entries > 0 ? (int) ceil( $total_entries / $per_page ) : 1;

			$entries = PLTT_Entries::get_all(
				array_merge(
					$filter_args,
					array(
						'orderby' => 'entry_date',
						'order'   => 'DESC',
						'limit'   => $per_page,
						'offset'  => $offset,
					)
				)
			);
		}

		include PLTT_PLUGIN_DIR . 'templates/reports.php';
	}
}

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
		$view       = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'summary';
		$client_id  = isset( $_GET['client_id'] ) ? absint( $_GET['client_id'] ) : 0;
		$project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0;
		$tag        = isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '';
		$billable   = isset( $_GET['billable'] ) && '' !== $_GET['billable'] ? absint( $_GET['billable'] ) : null;
		$billed     = isset( $_GET['billed'] ) && '' !== $_GET['billed'] ? absint( $_GET['billed'] ) : null;

		// Negate flags for exclusion filters.
		$client_negate  = ! empty( $_GET['client_negate'] ) ? 1 : 0;
		$project_negate = ! empty( $_GET['project_negate'] ) ? 1 : 0;
		$tag_negate     = ! empty( $_GET['tag_negate'] ) ? 1 : 0;

		// Whitelist valid views.
		if ( ! in_array( $view, array( 'detailed', 'summary' ), true ) ) {
			$view = 'summary';
		}

		// Build filter args shared across all queries.
		$filter_args = array(
			'date_from'      => $date_from,
			'date_to'        => $date_to,
			'client_id'      => $client_id,
			'project_id'     => $project_id,
			'tag'            => $tag,
			'billable'       => $billable,
			'billed'         => $billed,
			'client_negate'  => $client_negate,
			'project_negate' => $project_negate,
			'tag_negate'     => $tag_negate,
		);

		// Client context card data: loaded only when a single client is selected.
		$context_client   = null;
		$context_projects = array();

		if ( $client_id > 0 ) {
			$context_client = PLTT_Clients::get( $client_id );

			if ( $context_client ) {
				if ( is_numeric( $project_id ) && (int) $project_id > 0 ) {
					$single = PLTT_Projects::get( (int) $project_id );
					if ( $single && (int) $single->client_id === (int) $client_id ) {
						$context_projects = array( $single );
					}
				} else {
					$context_projects = PLTT_Projects::get_by_client( $client_id, true );
				}
			}
		}

		// Get summary stats in one query (independent of view/pagination).
		$stats = PLTT_Entries::get_stats( $filter_args );

		$total_entries = $stats ? (int) $stats->total_count : 0;

		// Previous period stats for comparison (Card 4).
		$prev_period      = pltt_get_previous_period( $date_from, $date_to );
		$prev_filter_args = array_merge( $filter_args, array(
			'date_from' => $prev_period['from'],
			'date_to'   => $prev_period['to'],
		) );
		$prev_stats = PLTT_Entries::get_stats( $prev_filter_args );

		// Working days for daily averages (Card 2).
		$working_days = pltt_count_working_days( $date_from, $date_to );

		// Utilization percentage (Card 3) — client-facing time only, excludes Internal client.
		$utilization = $stats && $stats->client_total_minutes > 0
			? ( $stats->client_billable_minutes / $stats->client_total_minutes ) * 100
			: 0;

		// Overall Effective Hourly Rate (Card 5).
		$overall_ehr = $stats && $stats->total_minutes > 0 && (float) $stats->billable_amount > 0
			? (float) $stats->billable_amount / ( $stats->total_minutes / 60 )
			: 0;

		// Top projects for the period (Card 1) — up to 2 highest-hours client-facing projects.
		$top_projects = $total_entries > 0
			? PLTT_Entries::get_top_projects_for_period( $date_from, $date_to, $filter_args, 2 )
			: array();

		// View-specific data.
		$entries     = array();
		$summary     = array();
		$total_pages = 1;
		$paged       = 1;

		// Allocation stats for the summary table — always the full picture, not filtered by date.
		$alloc_stats = array();

		// Chart data (summary view only). Populated below.
		$chart_buckets     = array();
		$chart_bucket_size = 'day';
		$chart_max_minutes = 0;
		$chart_avg_minutes = 0;
		$chart_today_key   = '';

		if ( 'summary' === $view ) {
			$summary = PLTT_Entries::get_summary_by_project( $date_from, $date_to, $filter_args );

			// Build chart buckets aligned to the active filter range, then fold daily totals into them.
			$chart_bucket_size = pltt_resolve_bucket_size( $date_from, $date_to );
			$chart_buckets     = pltt_build_chart_buckets( $date_from, $date_to, $chart_bucket_size );

			if ( ! empty( $chart_buckets ) ) {
				$daily_rows = PLTT_Entries::get_chart_daily_totals( $date_from, $date_to, $filter_args );

				// Initialize totals on each bucket and build a key -> index map for O(1) lookup.
				$bucket_index = array();
				foreach ( $chart_buckets as $i => $bucket ) {
					$chart_buckets[ $i ]['billable_minutes']    = 0;
					$chart_buckets[ $i ]['nonbillable_minutes'] = 0;
					$bucket_index[ $bucket['key'] ]             = $i;
				}

				foreach ( $daily_rows as $row ) {
					$ymd = $row->entry_date;
					if ( 'day' === $chart_bucket_size ) {
						$key = $ymd;
					} elseif ( 'month' === $chart_bucket_size ) {
						$key = substr( $ymd, 0, 7 );
					} else {
						// Weekly: find the bucket whose start <= ymd <= end.
						$key = null;
						foreach ( $chart_buckets as $bucket ) {
							if ( $ymd >= $bucket['start'] && $ymd <= $bucket['end'] ) {
								$key = $bucket['key'];
								break;
							}
						}
					}

					if ( null === $key || ! isset( $bucket_index[ $key ] ) ) {
						continue;
					}

					$i = $bucket_index[ $key ];
					$chart_buckets[ $i ]['billable_minutes']    += (int) $row->billable_minutes;
					$chart_buckets[ $i ]['nonbillable_minutes'] += (int) $row->nonbillable_minutes;
				}

				// Compute max + total minutes across buckets (for y-axis scale and average line).
				$chart_total_minutes = 0;
				foreach ( $chart_buckets as $bucket ) {
					$bucket_total = (int) $bucket['billable_minutes'] + (int) $bucket['nonbillable_minutes'];
					if ( $bucket_total > $chart_max_minutes ) {
						$chart_max_minutes = $bucket_total;
					}
					$chart_total_minutes += $bucket_total;
				}
				$chart_avg_minutes = (int) round( $chart_total_minutes / count( $chart_buckets ) );

				// Identify which bucket (if any) contains today, for the "today" marker.
				$today_ymd = pltt_get_current_date();
				if ( $today_ymd >= $date_from && $today_ymd <= $date_to ) {
					if ( 'day' === $chart_bucket_size ) {
						$chart_today_key = $today_ymd;
					} elseif ( 'month' === $chart_bucket_size ) {
						$chart_today_key = substr( $today_ymd, 0, 7 );
					} else {
						// Weekly: today's week-start key, aligned to start_of_week.
						$week_start_dow  = (int) get_option( 'start_of_week', 0 );
						$today_dt        = new DateTimeImmutable( $today_ymd, wp_timezone() );
						$today_dow       = (int) $today_dt->format( 'w' );
						$shift           = ( $today_dow - $week_start_dow + 7 ) % 7;
						$chart_today_key = $today_dt->modify( "-{$shift} days" )->format( 'Y-m-d' );
					}
				}
			}

			// For projects with a budget, fetch allocation-aware stats.
			// Recurring: hours within the selected date range vs monthly allocation.
			// Fixed budget: all-time hours vs estimate.
			if ( ! empty( $summary ) ) {
				// Recurring budgets reset each month, so only show the bar when the
				// selected range falls within a single past-or-current calendar month.
				$from_ym            = substr( $date_from, 0, 7 );
				$to_ym              = substr( $date_to, 0, 7 );
				$single_valid_month = ( $from_ym === $to_ym ) && ( $from_ym <= current_time( 'Y-m' ) );

				foreach ( $summary as $row ) {
					$has_hours_budget = ! empty( $row->budget_hours );
					$has_fee_budget   = ! empty( $row->budget_fee );
					if ( empty( $row->project_id ) || ( ! $has_hours_budget && ! $has_fee_budget ) ) {
						continue;
					}
					if ( ! empty( $row->recurring_period ) && $single_valid_month ) {
						$alloc_stats[ $row->project_id ] = PLTT_Entries::get_stats( array(
							'project_id' => (int) $row->project_id,
							'date_from'  => $date_from,
							'date_to'    => $date_to,
						) );
					} elseif ( ! empty( $row->recurring_period ) ) {
						// Date range spans multiple months or is future — skip budget bar.
						continue;
					} else {
						$alloc_stats[ $row->project_id ] = PLTT_Entries::get_stats( array(
							'project_id' => (int) $row->project_id,
						) );
					}
				}
			}
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

			// Bulk-load tags for all fetched entries and attach as CSV for template rendering.
			if ( ! empty( $entries ) ) {
				$entry_ids     = array_map( function( $e ) { return (int) $e->id; }, $entries );
				$tags_by_entry = PLTT_Tags::get_for_entries( $entry_ids );
				foreach ( $entries as $entry ) {
					$entry->tags = implode( ',', $tags_by_entry[ (int) $entry->id ] ?? array() );
				}
			}
		}

		include PLTT_PLUGIN_DIR . 'templates/reports.php';
	}
}

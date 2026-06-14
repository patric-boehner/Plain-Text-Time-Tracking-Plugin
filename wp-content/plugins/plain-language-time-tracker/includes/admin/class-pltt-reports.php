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

		if ( $client_id > 0 && ! $client_negate ) {
			$context_client = PLTT_Clients::get( $client_id );

			if ( $context_client ) {
				$is_internal_client = ! empty( $context_client->is_internal );

				if ( ! $is_internal_client ) {
					if ( is_numeric( $project_id ) && (int) $project_id > 0 ) {
						$single = PLTT_Projects::get( (int) $project_id );
						if ( $single && (int) $single->client_id === (int) $client_id ) {
							$context_projects = array( $single );
						}
					} else {
						$range_days = ( strtotime( $date_to ) - strtotime( $date_from ) ) / DAY_IN_SECONDS + 1;
						if ( $range_days <= 92 ) {
							$context_projects = PLTT_Projects::get_by_client( $client_id, true );
						}
					}
				}
			}
		}

		// Single-project + single-client filter: project identity is unambiguous,
		// so the Top Projects card is redundant with the client context card.
		$is_single_project_view = ( $client_id > 0 && ! $client_negate )
			&& ( $project_id > 0 && ! $project_negate );

		// Overage decision-support context: populated only on detailed view when
		// filtered to a single retainer/fixed-fee project with an allocation.
		// Mirrors the $context_client / $context_projects pattern above.
		$context_overage       = null;
		$context_alloc_project = null;
		$is_single_alloc_view  = false;

		if ( 'detailed' === $view && $project_id > 0 && ! $project_negate ) {
			$alloc_project = PLTT_Projects::get( (int) $project_id );
			if ( $alloc_project ) {
				$btype     = pltt_get_billing_type( $alloc_project );
				$has_alloc = in_array( $btype, array( 'recurring', 'fixed' ), true )
					&& ( ! empty( $alloc_project->budget_hours ) || ! empty( $alloc_project->budget_fee ) );
				if ( $has_alloc ) {
					$context_alloc_project = $alloc_project;
					$context_overage       = pltt_compute_overage_threshold( $alloc_project, $filter_args );
					$is_single_alloc_view  = ( 'over' === $context_overage['state'] );
				}
			}
		}

		// Global "billable time outside your date range" notification — summary
		// view only. One aggregate signal across all projects (excluding fixed-fee
		// and archived), replacing the former per-project indicators.
		//
		// Only surface it when the viewed range includes today: looking at a current
		// window (today / this week / this month / a custom range through now) is the
		// "what do I still need to bill" context. Looking at a closed past range is a
		// retrospective view where the stranded-time nudge would just be noise.
		$unbilled_notice = null;
		$today           = pltt_get_current_date();
		if ( 'summary' === $view && $today >= $date_from && $today <= $date_to ) {
			$unbilled_notice = PLTT_Entries::get_unbilled_outside_range_summary( $date_from, $date_to, $filter_args );
		}

		// Get summary stats in one query (independent of view/pagination).
		$stats = PLTT_Entries::get_stats( $filter_args );

		$total_entries = $stats ? (int) $stats->total_count : 0;

		// Previous-period stats for the Billable Hours / Billable Amount comparison.
		//
		// Matched-slice: when the current period is still in progress (its range
		// extends past today), comparing a full prior period against a partial
		// current one manufactures alarming deltas — a half-week measured against a
		// full week. So clip the prior period to the SAME number of elapsed days and
		// drop the percentage; the cards show a plain "vs X same point last period"
		// instead. A complete (fully past) period still compares in full.
		$prev_period       = pltt_get_previous_period( $date_from, $date_to );
		$prev_compare_to   = $prev_period['to'];
		$is_partial_period = false;

		$span_days = (int) floor( ( strtotime( $date_to ) - strtotime( $date_from ) ) / DAY_IN_SECONDS ) + 1;
		if ( $today >= $date_from && $today < $date_to ) {
			$elapsed_days      = (int) floor( ( strtotime( $today ) - strtotime( $date_from ) ) / DAY_IN_SECONDS ) + 1;
			$elapsed_days      = max( 1, min( $span_days, $elapsed_days ) );
			$is_partial_period = ( $elapsed_days < $span_days );
			if ( $is_partial_period ) {
				$prev_compare_to = gmdate( 'Y-m-d', strtotime( $prev_period['from'] ) + ( $elapsed_days - 1 ) * DAY_IN_SECONDS );
			}
		}

		$prev_filter_args = array_merge( $filter_args, array(
			'date_from' => $prev_period['from'],
			'date_to'   => $prev_compare_to,
		) );
		$prev_stats = PLTT_Entries::get_stats( $prev_filter_args );

		// Internal hours (Card 2 sub-line): total − client-facing.
		$internal_minutes = $stats
			? max( 0, (int) $stats->total_minutes - (int) $stats->client_total_minutes )
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

			// Volume chart context (buckets + folded daily totals), shared with the
			// Project Detail chart via pltt_build_period_chart_data().
			$chart             = pltt_build_period_chart_data( $date_from, $date_to, $filter_args );
			$chart_buckets     = $chart['buckets'];
			$chart_bucket_size = $chart['bucket_size'];
			$chart_max_minutes = $chart['max_minutes'];
			$chart_avg_minutes = $chart['avg_minutes'];
			$chart_today_key   = $chart['today_key'];

			// For projects with a budget, fetch allocation-aware stats.
			// Recurring: hours within the selected date range vs monthly allocation.
			// Fixed budget: all-time hours vs estimate.
			if ( ! empty( $summary ) ) {
				// Recurring budgets reset each month, so only show the bar when the
				// selected range falls within a single past-or-current calendar month.
				$from_ym            = substr( $date_from, 0, 7 );
				$to_ym              = substr( $date_to, 0, 7 );
				$single_valid_month = ( $from_ym === $to_ym ) && ( $from_ym <= current_time( 'Y-m' ) );

				// OPT-N3: bucket projects by which stats query they need, then run
				// at most two bulk queries instead of one per project row.
				$recurring_pids = array();
				$alltime_pids   = array();
				foreach ( $summary as $row ) {
					$has_hours_budget = ! empty( $row->budget_hours );
					$has_fee_budget   = ! empty( $row->budget_fee );
					if ( empty( $row->project_id ) || ( ! $has_hours_budget && ! $has_fee_budget ) ) {
						continue;
					}
					if ( ! empty( $row->recurring_period ) ) {
						if ( $single_valid_month ) {
							$recurring_pids[] = (int) $row->project_id;
						}
						// else: recurring but range spans months — skip budget bar entirely.
					} else {
						$alltime_pids[] = (int) $row->project_id;
					}
				}

				if ( ! empty( $recurring_pids ) ) {
					$alloc_stats += PLTT_Entries::get_stats_grouped_by( 'project_id', array(
						'project_ids' => $recurring_pids,
						'date_from'   => $date_from,
						'date_to'     => $date_to,
					) );
				}
				if ( ! empty( $alltime_pids ) ) {
					$alloc_stats += PLTT_Entries::get_stats_grouped_by( 'project_id', array(
						'project_ids' => $alltime_pids,
					) );
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

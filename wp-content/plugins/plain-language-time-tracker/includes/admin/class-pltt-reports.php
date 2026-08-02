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
	 * Rows shown in billing select mode (bill=1).
	 *
	 * Selection can't span pages — the ticked boxes ARE the bill — so the whole
	 * range renders at once. This is a backstop against an unbounded query, not a
	 * page size: when it bites, the bar says how many rows are off the list rather
	 * than letting the bill quietly cover only what fitted.
	 */
	const BILL_SELECT_MAX = 500;

	/**
	 * Valid `view` values and the default (OPT-S8: single source of truth).
	 */
	const VIEWS        = array( 'summary', 'detailed' );
	const DEFAULT_VIEW = 'summary';

	/**
	 * Render the Reports screen.
	 */
	public static function render() {
		$date_from  = isset( $_GET['from'] ) ? pltt_sanitize_date( wp_unslash( $_GET['from'] ) ) : current_time( 'Y-m-01' );
		$date_to    = isset( $_GET['to'] ) ? pltt_sanitize_date( wp_unslash( $_GET['to'] ) ) : pltt_get_current_date();
		$view       = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : self::DEFAULT_VIEW;
		$client_id  = isset( $_GET['client_id'] ) ? absint( $_GET['client_id'] ) : 0;
		$project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0;
		$tag        = isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '';
		// One Billing filter replaces the old Billable + Invoiced pair: its options
		// ARE the Status column's values, so a query that has no answer can't be
		// constructed (spec §4 — you filter by outcome, and Billable stays a column
		// because it's the input).
		$billing_status = isset( $_GET['billing'] ) ? sanitize_key( wp_unslash( $_GET['billing'] ) ) : '';
		if ( ! in_array( $billing_status, array( 'unbilled', 'billed', 'not_charged' ), true ) ) {
			$billing_status = '';
		}

		// Negate flags for exclusion filters.
		$client_negate  = ! empty( $_GET['client_negate'] ) ? 1 : 0;
		$project_negate = ! empty( $_GET['project_negate'] ) ? 1 : 0;
		$tag_negate     = ! empty( $_GET['tag_negate'] ) ? 1 : 0;

		// Picking a project without its client (e.g. straight from the grouped
		// Project dropdown on an unfiltered view) leaves client_id = 0, so the
		// single-project scope + billing indicator never resolve. Derive the
		// client from the project so the filter behaves as one selection. The
		// Client dropdown then renders it selected on load; skip when either the
		// project or client filter is negated.
		if ( $project_id > 0 && $client_id <= 0 && ! $project_negate && ! $client_negate ) {
			$derived_project = PLTT_Projects::get( $project_id );
			if ( $derived_project && ! empty( $derived_project->client_id ) ) {
				$client_id = (int) $derived_project->client_id;
			}
		}

		// Whitelist valid views.
		if ( ! in_array( $view, self::VIEWS, true ) ) {
			$view = self::DEFAULT_VIEW;
		}

		// Build filter args shared across all queries.
		$filter_args = array(
			'date_from'      => $date_from,
			'date_to'        => $date_to,
			'client_id'      => $client_id,
			'project_id'     => $project_id,
			'tag'            => $tag,
			'billing_status' => $billing_status,
			'client_negate'  => $client_negate,
			'project_negate' => $project_negate,
			'tag_negate'     => $tag_negate,
		);

		// Scope-block context: loaded whenever a single client is selected. With a
		// project as well, the block names the project ($context_projects[0]);
		// with the client alone it names the client, and the count of that client's
		// active projects is the only extra fact its terms line needs.
		$context_client        = null;
		$context_projects      = array();
		$context_project_count = 0;

		if ( $client_id > 0 && ! $client_negate ) {
			$context_client = PLTT_Clients::get( $client_id );

			if ( $context_client ) {
				if ( $project_id > 0 && ! $project_negate ) {
					$single = PLTT_Projects::get( (int) $project_id );
					if ( $single && (int) $single->client_id === (int) $client_id ) {
						$context_projects = array( $single );
					}
				} else {
					$context_project_count = count( PLTT_Projects::get_by_client( $client_id, true ) );
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

		$today = pltt_get_current_date();

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
		$overall_ehr = $stats ? pltt_effective_rate( $stats->billable_amount, $stats->total_minutes ) : 0;

		// View-specific data.
		$entries     = array();
		$summary     = array();
		$total_pages = 1;
		$paged       = 1;

		// Allocation stats for the summary table — always the full picture, not filtered by date.
		$alloc_stats = array();

		// Chart data (summary view only). Populated below; the partial unpacks it.
		$chart = null;

		// All-time outstanding (summary view only; the "Unbilled so far" card).
		$outstanding_total = 0.0;

		if ( 'summary' === $view ) {
			$summary = PLTT_Entries::get_summary_by_project( $date_from, $date_to, $filter_args );

			// Volume chart context (buckets + folded daily totals), shared with the
			// Project Detail chart via pltt_build_period_chart_data().
			$chart = pltt_build_period_chart_data( $date_from, $date_to, $filter_args );

			// All-time outstanding for the "Unbilled so far" card — the same figure
			// the Billing page shows. It's a standing backlog independent of the
			// viewed date range, which is why the card links out to Billing rather
			// than acting in place.
			$billing_queue     = PLTT_Billing::get_invoicing_queue();
			$outstanding_total = isset( $billing_queue['grand_total'] ) ? (float) $billing_queue['grand_total'] : 0.0;

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

			// Billing mode (bill=1): hide entries already covered by a committed
			// record from the detailed list and its pagination — you're reviewing a
			// NEW bill, so they're not actionable. The summary cards keep the full
			// picture ($stats is left untouched); only the list count is adjusted.
			$entry_args = $filter_args;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only mode flag.
			$billing_mode = ( ! empty( $_GET['bill'] ) && $project_id > 0 );
			if ( $billing_mode ) {
				$covered_ids = PLTT_Billing::get_covered_entry_ids( $project_id );
				if ( ! empty( $covered_ids ) ) {
					$entry_args['exclude_entry_ids'] = $covered_ids;
					$list_stats    = PLTT_Entries::get_stats( $entry_args );
					$total_entries = $list_stats ? (int) $list_stats->total_count : 0;
				}

				// Selection can't be paginated. The checkbox is the only thing that
				// constrains the charge, so a 50-row page under a bar claiming the
				// range total meant "Bill selected" silently covered the first page
				// and dropped the rest. Show the whole range instead, and cap only as
				// a backstop — the template says so when the cap bites, because a
				// silent truncation here is exactly the failure being fixed.
				$per_page = self::BILL_SELECT_MAX;
				$offset   = 0;
				$paged    = 1;
			}

			$total_pages = $total_entries > 0 ? (int) ceil( $total_entries / $per_page ) : 1;

			$entries = PLTT_Entries::get_all(
				array_merge(
					$entry_args,
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

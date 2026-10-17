<?php
/**
 * Project Report — read-only aggregations for the Report tab.
 *
 * Pure data layer: turns a project's stats into the stat cards, the volume
 * ("Hours by …") chart, the budget marks drawn on that chart, and the "Where
 * the time went" tag grid. No output, no per-entry queries in loops. Consumed
 * by templates/partials/project-detail-report.php.
 *
 * The "Budget consumed" meter panel was removed 2026-08-13 in favour of those
 * marks — see build_budget() and ui/pltt-budget-on-chart.html.
 *
 * The original per-tag "Where the time went" bars and the "Activity over time"
 * swimlane were removed 2026-07-18 (docs/removed-project-report-sections.md).
 * The grid that replaced the bars on 2026-10-17 is built by build_where(): one
 * SQL aggregation, not the old per-entry accumulate loop.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes the Report tab's aggregations.
 */
class PLTT_Project_Report {

	/**
	 * Build the full Report-tab dataset for a project.
	 *
	 * @param int         $project_id    Project ID.
	 * @param object      $project       Project row.
	 * @param object|null $client        Owning client (unused directly; reserved).
	 * @param object|null $stats         Pre-loaded lifetime PLTT_Entries::get_stats() result; loaded if null.
	 * @param array|null  $window        Active period window (recurring period lens), or null.
	 * @param object|null $windowed_stats Pre-loaded windowed stats for $window, to reuse instead of
	 *                                   re-querying (OPT-N-A: render() already computes these for the subhead).
	 * @return array {
	 *     @type bool   $has_entries Whether any time is logged.
	 *     @type array  $budget      Budget marks for the chart: { cross, alloc } (or null).
	 *     @type array  $cards       Stat-card figures.
	 *     @type array  $chart       Volume ("Hours by …") chart data (or null).
	 *     @type array  $where       "Where the time went" grid data (or null) — see build_where().
	 *     @type array  $window      Active period-lens window (or null).
	 * }
	 *
	 * Note: this used to also return `groupings` / `timeline_groupings` /
	 * `default_group` / `axis` / `budget_line` for the original "Where the time
	 * went" bars and the "Activity over time" swimlane (removed 2026-07-18, see
	 * docs/removed-project-report-sections.md). `where` is the bars' successor.
	 */
	public static function build( $project_id, $project, $client = null, $stats = null, $window = null, $windowed_stats = null ) {
		if ( null === $stats ) {
			$stats = PLTT_Entries::get_stats( array( 'project_id' => $project_id ) );
		}

		$rate = (float) pltt_resolve_billable_rate( (int) $project->client_id, (int) $project_id );

		// Windowed slice → stat cards + volume chart. For the full/lifetime view
		// (every non-recurring project, and recurring projects in "Full" scope) the
		// window is the whole span, so these collapse back to the all-time figures.
		$is_windowed = is_array( $window ) && ! empty( $window['is_period'] );

		if ( $is_windowed ) {
			// Reuse the caller's windowed stats when supplied (OPT-N-A); only query
			// when called without them.
			$card_stats = ( null !== $windowed_stats )
				? $windowed_stats
				: PLTT_Entries::get_stats(
					array(
						'project_id' => $project_id,
						'date_from'  => $window['from'],
						'date_to'    => $window['to'],
					)
				);
		} else {
			$card_stats = $stats;
		}

		// Volume chart spans the active window (== lifetime span when not windowed).
		$chart_from = is_array( $window ) && ! empty( $window['from'] ) ? $window['from'] : ( $stats->first_entry_date ?? '' );
		$chart_to   = is_array( $window ) && ! empty( $window['to'] ) ? $window['to'] : ( $stats->last_entry_date ?? '' );
		$chart      = ( $chart_from && $chart_to )
			? pltt_build_period_chart_data( $chart_from, $chart_to, array( 'project_id' => (int) $project_id ) )
			: null;

		return array(
			'has_entries' => ( isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0 ) > 0,
			'budget'      => self::build_budget( $project, $stats, $rate, $window, $chart ),
			'cards'       => self::build_cards( $project, $card_stats, $rate, $window, $stats ),
			'chart'       => $chart,
			'where'       => self::build_where( $project_id, $project, $window, $stats ),
			'window'      => is_array( $window ) ? $window : null,
		);
	}

	/**
	 * "Where the time went": minutes per tag across the most recent periods.
	 *
	 * Columns are the project's own billing periods for a retainer (a monthly
	 * care plan reads month by month) and calendar months for everything else.
	 * Six at most, ending at the lens-selected period — or, with no lens, at
	 * today's period for an active project, so a month with nothing logged yet
	 * still shows as the tinted column rather than vanishing. Entries outside
	 * the columns still count in the all-time total.
	 *
	 * Rows are grouped by tag group only when the project's tags span more than
	 * one — with every tag in "Activity" there is nothing to separate. Untagged
	 * time is one last row, and only when there is any.
	 *
	 * @param int         $project_id Project ID.
	 * @param object      $project    Project row.
	 * @param array|null  $window     Active period window (recurring period lens).
	 * @param object|null $stats      Lifetime stats (first/last entry dates).
	 * @return array|null {
	 *     @type array  $periods            [ { key, from, to, label, full_label, is_current } ], oldest first.
	 *     @type string $period_range       Human span of the columns, for the panel header.
	 *     @type array  $groups             [ { label, rows: [ { tag_id, name, cells[], all_minutes, share, url } ] } ].
	 *     @type array  $untagged           One row in the same shape, or null.
	 *     @type int    $total_minutes      All-time project minutes (each entry counted once).
	 *     @type int[]  $period_totals      Per-column minutes, each entry counted once.
	 *     @type bool   $has_multi_tag      Whether any entry carries more than one tag.
	 *     @type bool   $show_group_headers Whether to print a header row per group.
	 * } Null when there is nothing to show.
	 */
	private static function build_where( $project_id, $project, $window, $stats ) {
		$periods = self::where_periods( $project, $window, $stats );
		if ( empty( $periods ) ) {
			return null;
		}

		$buckets = array();
		foreach ( $periods as $p ) {
			$buckets[] = array(
				'from' => $p['from'],
				'to'   => $p['to'],
			);
		}
		$data  = PLTT_Entries::get_tag_minutes_by_bucket( (int) $project_id, $buckets );
		$ncols = count( $periods );
		$total = (int) array_sum( $data['totals'] );
		if ( $total <= 0 ) {
			return null;
		}

		$meta = array();
		foreach ( (array) PLTT_Tags::get_all() as $tag ) {
			$meta[ (int) $tag->id ] = $tag;
		}

		$by_group = array();
		$untagged = null;
		$row_sum  = 0;
		foreach ( $data['cells'] as $tag_id => $bucket_minutes ) {
			$all = (int) array_sum( $bucket_minutes );
			if ( $all <= 0 ) {
				continue;
			}
			// A tag deleted between the two queries; nothing to name it by.
			if ( 0 !== $tag_id && ! isset( $meta[ $tag_id ] ) ) {
				continue;
			}

			$cells = array();
			for ( $i = 0; $i < $ncols; $i++ ) {
				$cells[ $i ] = isset( $bucket_minutes[ $i ] ) ? (int) $bucket_minutes[ $i ] : 0;
			}
			$row = array(
				'tag_id'      => $tag_id,
				'name'        => ( 0 === $tag_id ) ? __( 'Untagged', 'plain-language-time-tracker' ) : $meta[ $tag_id ]->name,
				'cells'       => $cells,
				'all_minutes' => $all,
				'share'       => $all / $total,
				// The row's identifier links to its records: Overview's Entries
				// view scoped to this project + this tag over the project's whole
				// span, which is the set the All-time figure counts.
				'url'         => self::tag_entries_url( $project, $stats, ( 0 === $tag_id ) ? 'without_tag' : $meta[ $tag_id ]->name ),
			);
			$row_sum += $all;

			if ( 0 === $tag_id ) {
				$untagged = $row;
				continue;
			}
			$group                = isset( $meta[ $tag_id ]->group_name ) ? (string) $meta[ $tag_id ]->group_name : '';
			$by_group[ $group ][] = $row;
		}
		if ( empty( $by_group ) && null === $untagged ) {
			return null;
		}

		// Groups in the Tags page's order, ungrouped last.
		$ordered = array();
		foreach ( PLTT_Tags::get_all_groups() as $g ) {
			if ( isset( $by_group[ $g ] ) ) {
				$ordered[ $g ] = $by_group[ $g ];
			}
		}
		foreach ( $by_group as $g => $rows ) {
			if ( '' !== $g && ! isset( $ordered[ $g ] ) ) {
				$ordered[ $g ] = $rows;
			}
		}
		if ( isset( $by_group[''] ) ) {
			$ordered[''] = $by_group[''];
		}

		$groups = array();
		foreach ( $ordered as $g => $rows ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					$by_minutes = $b['all_minutes'] <=> $a['all_minutes'];
					return $by_minutes ?: strcasecmp( $a['name'], $b['name'] );
				}
			);
			$groups[] = array(
				'label' => ( '' === $g ) ? __( 'Ungrouped', 'plain-language-time-tracker' ) : $g,
				'rows'  => $rows,
			);
		}

		$period_totals = array();
		for ( $i = 0; $i < $ncols; $i++ ) {
			$period_totals[ $i ] = isset( $data['totals'][ $i ] ) ? (int) $data['totals'][ $i ] : 0;
		}

		return array(
			'periods'            => $periods,
			'period_range'       => pltt_format_date_range( $periods[0]['from'], $periods[ $ncols - 1 ]['to'] ),
			'groups'             => $groups,
			'untagged'           => $untagged,
			'total_minutes'      => $total,
			'period_totals'      => $period_totals,
			'has_multi_tag'      => $row_sum > $total,
			'show_group_headers' => count( $groups ) > 1,
		);
	}

	/**
	 * Overview's Entries view, scoped to one project and one tag, project-lifetime.
	 *
	 * Same shape as unbilled_entries_url(): the range ends today so an archived
	 * project's span still covers its last entry, and Overview derives the
	 * client from the project when only the project is given.
	 *
	 * @param object      $project Project row.
	 * @param object|null $stats   Lifetime stats (first entry date).
	 * @param string      $tag     Tag name, or 'without_tag' for untagged entries.
	 * @return string
	 */
	private static function tag_entries_url( $project, $stats, $tag ) {
		$today = pltt_get_current_date();
		$from  = ! empty( $stats->first_entry_date ) ? $stats->first_entry_date : $today;
		$to    = ( ! empty( $stats->last_entry_date ) && $stats->last_entry_date > $today ) ? $stats->last_entry_date : $today;

		return add_query_arg(
			array(
				'page'       => 'pltt-reports',
				'view'       => 'detailed',
				'client_id'  => (int) $project->client_id,
				'project_id' => (int) $project->id,
				'tag'        => $tag,
				'from'       => $from,
				'to'         => $to,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The grid's columns: up to $count consecutive periods, oldest first.
	 *
	 * Unit: the lens unit for a retainer, calendar months otherwise. The last
	 * column is the lens-selected period when one is set; otherwise today's
	 * period while the project is active, and the last entry's period once it
	 * is archived. Walks back from there until $count columns or the period
	 * holding the first entry, whichever comes first — a two-month-old project
	 * gets two columns, not four dashes.
	 *
	 * @param object      $project Project row.
	 * @param array|null  $window  Active period window.
	 * @param object|null $stats   Lifetime stats.
	 * @param int         $count   Maximum number of columns.
	 * @return array [ { key, from, to, label, full_label, is_current } ].
	 */
	private static function where_periods( $project, $window, $stats, $count = 6 ) {
		$first = $stats->first_entry_date ?? '';
		$last  = $stats->last_entry_date ?? '';
		if ( ! $first || ! $last ) {
			return array();
		}

		$is_recurring = ( 'recurring' === pltt_get_billing_type( $project ) );
		$unit         = ( $is_recurring && ! empty( $window['unit'] ) ) ? $window['unit'] : 'month';
		$tz           = wp_timezone();
		$today        = current_time( 'Y-m-d' );
		$is_lens      = ! empty( $window['is_period'] ) && ! empty( $window['anchor'] );

		if ( $is_lens ) {
			$end_date = $window['anchor'];
		} elseif ( 'archived' !== ( $project->status ?? 'active' ) ) {
			$end_date = $today;
		} else {
			$end_date = $last;
		}

		$end   = pltt_period_start( new DateTimeImmutable( $end_date, $tz ), $unit );
		$floor = pltt_period_start( new DateTimeImmutable( $first, $tz ), $unit );
		if ( $end < $floor ) {
			$end = $floor;
		}

		// The tinted column: the lens period, or today's when there is no lens.
		$current_key = $is_lens
			? $end->format( 'Y-m-d' )
			: pltt_period_start( new DateTimeImmutable( $today, $tz ), $unit )->format( 'Y-m-d' );

		$starts = array();
		$cursor = $end;
		while ( count( $starts ) < $count && $cursor >= $floor ) {
			$starts[] = $cursor;
			$cursor   = pltt_period_start( $cursor->modify( '-1 day' ), $unit );
		}
		$starts = array_reverse( $starts );

		$end_year = $end->format( 'Y' );
		$periods  = array();
		foreach ( $starts as $start ) {
			$key       = $start->format( 'Y-m-d' );
			$periods[] = array(
				'key'        => $key,
				'from'       => $key,
				'to'         => pltt_period_end( $start, $unit )->format( 'Y-m-d' ),
				'label'      => pltt_period_short_label( $start, $unit, $start->format( 'Y' ) !== $end_year ),
				'full_label' => pltt_period_label( $start, $unit ),
				'is_current' => ( $key === $current_key ),
			);
		}

		return $periods;
	}

	/**
	 * Stat-card figures, tailored to the project's billing type.
	 *
	 * Returns { items: [ {label, value, value_suffix, sub, attention, is_empty}, ... ] };
	 * the template just renders each item as a .card. An empty/`null` value renders
	 * as an em-dash.
	 *
	 * @param object      $project Project row.
	 * @param object|null $stats   Aggregate stats (windowed to the active period when one is set).
	 * @param float       $rate    Resolved hourly rate.
	 * @param array|null  $window  Active period window (recurring period lens).
	 * @return array
	 */
	private static function build_cards( $project, $stats, $rate, $window = null, $lifetime = null ) {
		switch ( pltt_get_billing_type( $project ) ) {
			case 'fixed':
				$items = self::cards_fixed( $project, $stats, $rate );
				break;
			case 'recurring':
				$items = self::cards_recurring( $project, $stats, $rate, $window, $lifetime );
				break;
			case 'none':
				$items = self::cards_internal( $project, $stats );
				break;
			default:
				$items = self::cards_hourly( $project, $stats, $rate );
				break;
		}

		return array( 'items' => $items );
	}

	/**
	 * Build a single stat-card item.
	 *
	 * @param string $label Card label.
	 * @param string $value Pre-formatted value ('' renders as an em-dash).
	 * @param array  $opts  Optional: suffix, sub, attention (bool), over (bool),
	 *                      sub_link ([ 'url' => string, 'label' => string ]).
	 * @return array
	 */
	private static function card( $label, $value, $opts = array() ) {
		return array(
			'label'        => $label,
			'value'        => (string) $value,
			'is_empty'     => ( '' === (string) $value ),
			'value_suffix' => isset( $opts['suffix'] ) ? $opts['suffix'] : '',
			'sub'          => isset( $opts['sub'] ) ? $opts['sub'] : '',
			'attention'    => ! empty( $opts['attention'] ),
			// Ochre value — money that's owed, or hours past a limit. Never red.
			'over'         => ! empty( $opts['over'] ),
			// Grey value — a settled or not-owed figure ($0.00 billed, nothing out).
			'muted'        => ! empty( $opts['muted'] ),
			// A link on the basis line ("15 entries ›"), joined to $sub with " · ".
			'sub_link'     => isset( $opts['sub_link'] ) ? $opts['sub_link'] : null,
			// Pre-built basis HTML (already escaped by its builder) — used where a
			// shared figure helper assembles the line, links and all.
			'sub_html'     => isset( $opts['sub_html'] ) ? $opts['sub_html'] : '',
		);
	}

	/**
	 * Fixed Budget lineup: total hours, effective rate (fee ÷ hours), budget, fixed fee.
	 *
	 * @param object      $project Project row.
	 * @param object|null $stats   Aggregate stats.
	 * @param float       $rate    Resolved hourly rate.
	 * @return array[]
	 */
	private static function cards_fixed( $project, $stats, $rate ) {
		$total_minutes = isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0;
		$entry_count   = isset( $stats->total_count ) ? (int) $stats->total_count : 0;
		$fee           = self::num( $project->budget_fee );

		$budgeted_minutes = pltt_budgeted_minutes( $project, $rate );

		// 1 — Total hours.
		$cards = array(
			self::card(
				__( 'Total hours', 'plain-language-time-tracker' ),
				pltt_format_duration( $total_minutes ),
				array(
					'sub' => $entry_count > 0
						? sprintf(
							/* translators: %s: entry count. */
							_n( '%s entry', '%s entries', $entry_count, 'plain-language-time-tracker' ),
							number_format_i18n( $entry_count )
						)
						: '',
				)
			),
		);

		// 2 — Budget left / Budget overrun. The value is the actionable number —
		// hours remaining or hours past the budget — with the ratio and percentage
		// on the basis line (ui/pltt-budget-figure.html).
		if ( $budgeted_minutes > 0 ) {
			$diff  = $total_minutes - $budgeted_minutes;
			$over  = $diff > 0;
			$basis = sprintf(
				/* translators: 1: hours used; 2: hours budgeted; 3: percent used. */
				__( '%1$s of %2$s used · %3$d%%', 'plain-language-time-tracker' ),
				pltt_format_duration( $total_minutes ),
				pltt_format_duration( $budgeted_minutes ),
				(int) round( $total_minutes / $budgeted_minutes * 100 )
			);
			$cards[] = self::card(
				$over
					? __( 'Budget overrun', 'plain-language-time-tracker' )
					: __( 'Budget left', 'plain-language-time-tracker' ),
				pltt_format_duration( abs( $diff ) ),
				array(
					'sub'  => $basis,
					'over' => $over,
				)
			);
		} else {
			$cards[] = self::card( __( 'Budget left', 'plain-language-time-tracker' ), '', array( 'sub' => __( 'no budget set', 'plain-language-time-tracker' ) ) );
		}

		// 3 — The fee itself, which the hours never change. No basis line: the model
		// badge and the terms line above already say the fee is fixed.
		$cards[] = self::card(
			__( 'Project fee', 'plain-language-time-tracker' ),
			$fee > 0 ? pltt_format_currency( $fee ) : ''
		);

		// 4 — Effective rate, ARCHIVED ONLY. Mid-project it falls monotonically
		// from an absurd high (two hours into a $3,870 job it reads $1,935/hr), so
		// it flatters early and accuses late; it is only true once work stops.
		// Matches the "live EHR tracking — archived projects only" call in PROJECT.md.
		if ( 'archived' === $project->status ) {
			$ehr = pltt_effective_rate( $fee, $total_minutes );
			if ( $ehr > 0 ) {
				$ehr_basis = sprintf(
					/* translators: 1: the fixed fee; 2: hours logged. */
					__( '%1$s ÷ %2$s', 'plain-language-time-tracker' ),
					pltt_format_currency( $fee ),
					pltt_format_duration( $total_minutes )
				);
				if ( $rate > 0 ) {
					$ehr_basis .= ' · ' . sprintf(
						/* translators: %s: the project's nominal hourly rate. */
						__( 'target %s', 'plain-language-time-tracker' ),
						pltt_format_currency_compact( round( $rate ) )
					);
				}
				$cards[] = self::card(
					__( 'Effective rate', 'plain-language-time-tracker' ),
					self::rate_str( $ehr ),
					array( 'sub' => $ehr_basis )
				);
			}
		}

		return $cards;
	}

	/**
	 * Hourly lineup: total hours, billable amount, not yet billed, billed to date.
	 *
	 * The four figures an hourly project can honestly fill — how much work, what
	 * it's worth, what's owed, what's settled (ui/pltt-scope-by-type.html §01).
	 * No budget figure: an hourly project has no ceiling to measure against, which
	 * is what makes it hourly. No effective-rate figure either — the rate is fixed
	 * and already stated on the block's terms line, so it would only restate it.
	 *
	 * "Not yet billed" and "Billed to date" come from billing records, not from the
	 * legacy per-entry billed flag: outstanding is the uncovered set the billing
	 * surface would actually bill.
	 *
	 * @param object      $project Project row.
	 * @param object|null $stats   Aggregate stats.
	 * @param float       $rate    Resolved hourly rate.
	 * @return array[]
	 */
	private static function cards_hourly( $project, $stats, $rate ) {
		$total_minutes    = isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0;
		$billable_minutes = isset( $stats->billable_minutes ) ? (int) $stats->billable_minutes : 0;
		$billable_amount  = isset( $stats->billable_amount ) ? (float) $stats->billable_amount : 0.0;
		$entry_count      = isset( $stats->total_count ) ? (int) $stats->total_count : 0;

		// Outstanding = verified, billable, uncovered entries, all time. Same scope
		// the Review & bill flow commits, so the figure can't disagree with it.
		$scope             = PLTT_Billing::get_scope( $project, 'hourly' );
		$unbilled_amount   = $scope ? (float) $scope['unbilled'] : 0.0;
		$unbilled_entries  = $scope ? (array) $scope['entries'] : array();
		$unbilled_count    = count( $unbilled_entries );

		$rec           = PLTT_Billing_Records::query( array( 'project_ids' => array( (int) $project->id ), 'limit' => 0 ) );
		$billed        = (float) $rec['billed'];
		$record_count  = (int) $rec['total'];

		// 1 — Total hours.
		$cards = array(
			self::card(
				__( 'Total hours', 'plain-language-time-tracker' ),
				pltt_format_duration( $total_minutes ),
				array(
					'sub' => $entry_count > 0
						? sprintf(
							/* translators: %s: entry count. */
							_n( '%s entry', '%s entries', $entry_count, 'plain-language-time-tracker' ),
							number_format_i18n( $entry_count )
						)
						: '',
				)
			),
		);

		// 2 — Billable amount, with the billable share of logged time as its basis.
		$billable_basis = sprintf(
			/* translators: %s: billable hours, e.g. "59h 10m". */
			__( '%s billable', 'plain-language-time-tracker' ),
			pltt_format_duration( $billable_minutes )
		);
		if ( $total_minutes > 0 ) {
			$billable_basis .= ' · ' . sprintf(
				/* translators: %d: percent of logged time that is billable. */
				__( '%d%%', 'plain-language-time-tracker' ),
				(int) round( $billable_minutes / $total_minutes * 100 )
			);
		}
		$cards[] = self::card(
			__( 'Billable amount', 'plain-language-time-tracker' ),
			$billable_amount > 0 ? pltt_format_currency( $billable_amount ) : '',
			array( 'sub' => $billable_minutes > 0 ? $billable_basis : '' )
		);

		// 3 — Billed to date. No "View records" link: the ledger those records live
		// in is already further down this same page.
		$billed_opts = array( 'muted' => $billed <= 0.0 );
		if ( $record_count > 0 ) {
			$billed_opts['sub'] = sprintf(
				/* translators: %s: number of billing records. */
				_n( '%s record', '%s records', $record_count, 'plain-language-time-tracker' ),
				number_format_i18n( $record_count )
			);
		} else {
			$billed_opts['sub'] = __( 'no bill records yet', 'plain-language-time-tracker' );
		}
		$cards[] = self::card(
			__( 'Billed to date', 'plain-language-time-tracker' ),
			pltt_format_currency( $billed ),
			$billed_opts
		);

		// 4 — Not yet billed. Slot 4 on every type that bills: the spec's rules for
		// this figure are positional (period states, links-only basis, the backlog
		// append), so it has to sit where they say. Ochre while something is owed;
		// the basis line is the route to those entries, nothing else.
		$unbilled_opts = array(
			'over'  => $unbilled_amount > 0,
			'muted' => 0.0 === $unbilled_amount,
		);
		if ( $unbilled_count > 0 ) {
			$unbilled_opts['sub_link'] = array(
				'url'   => self::unbilled_entries_url( $project, $unbilled_entries ),
				'label' => sprintf(
					/* translators: %s: number of unbilled entries. */
					_n( '%s entry', '%s entries', $unbilled_count, 'plain-language-time-tracker' ),
					number_format_i18n( $unbilled_count )
				),
			);
		} else {
			$unbilled_opts['sub'] = __( 'nothing outstanding', 'plain-language-time-tracker' );
		}
		$cards[] = self::card(
			__( 'Not yet billed', 'plain-language-time-tracker' ),
			pltt_format_currency( $unbilled_amount ),
			$unbilled_opts
		);

		return $cards;
	}

	/**
	 * Link to the Entries view showing this project's outstanding work.
	 *
	 * Spans the oldest unbilled entry through today — the same range the billing
	 * surface uses for its own "Review & bill" link, minus the bill=1 flag: the
	 * basis line points at information, never at an action (spec §3c).
	 *
	 * @param object   $project Project row.
	 * @param object[] $entries Unbilled entries, oldest first.
	 * @return string
	 */
	private static function unbilled_entries_url( $project, $entries ) {
		$from = pltt_get_current_date();
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry->entry_date ) && $entry->entry_date < $from ) {
				$from = $entry->entry_date;
			}
		}

		return add_query_arg(
			array(
				'page'       => 'pltt-reports',
				'view'       => 'detailed',
				'client_id'  => (int) $project->client_id,
				'project_id' => (int) $project->id,
				'from'       => $from,
				'to'         => pltt_get_current_date(),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Retainer lineup, tailored to the active scope.
	 *
	 * Period view (the default): This period vs allocation · Overage billable ·
	 * Avg / month (lifetime) · Lifetime hours. Full view: Total hours · Avg / month
	 * vs allocation · Months active · Allocation. Never a blank "Monthly fee" card —
	 * these retainers price by allocation, not a stored fee.
	 *
	 * @param object      $project  Project row.
	 * @param object|null $stats    Scope stats (period stats in period view; lifetime in full).
	 * @param float       $rate     Resolved hourly rate.
	 * @param array|null  $window   Active period lens window.
	 * @param object|null $lifetime Lifetime stats (for the avg/lifetime context cards).
	 * @return array[]
	 */
	private static function cards_recurring( $project, $stats, $rate, $window = null, $lifetime = null ) {
		$alloc_minutes = (int) round( self::num( $project->budget_hours ) * 60 );
		$life          = ( null !== $lifetime ) ? $lifetime : $stats;
		$life_minutes  = isset( $life->total_minutes ) ? (int) $life->total_minutes : 0;
		$months        = self::months_between( $life->first_entry_date ?? '', $life->last_entry_date ?? '' );
		$avg_minutes   = $months > 0 ? (int) round( $life_minutes / $months ) : $life_minutes;

		// Average per period vs allocation. The basis names the allocation itself
		// ("29% of the 2h included") rather than the word "allocation" — the number
		// it's a percentage OF should be readable without looking it up. That also
		// makes the "of 2h" value suffix redundant, so the value stands alone.
		$avg = self::card( self::average_label( $project ), pltt_format_duration( $avg_minutes ) );
		if ( $alloc_minutes > 0 ) {
			$avg_pct          = (int) round( $avg_minutes / $alloc_minutes * 100 );
			$avg['sub']       = sprintf(
				/* translators: 1: percent of allocation; 2: the included hours, e.g. "3h". */
				__( '%1$d%% of the %2$s included', 'plain-language-time-tracker' ),
				$avg_pct,
				pltt_format_duration( $alloc_minutes )
			);
			$avg['attention'] = $avg_pct > 100;
		}

		// ── Period view: the selected/most-recent period against its allocation ──
		if ( $window && ! empty( $window['is_period'] ) ) {
			$used_minutes = isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0;
			$over_minutes = $alloc_minutes > 0 ? max( 0, $used_minutes - $alloc_minutes ) : 0;
			$overage      = round( ( $over_minutes / 60.0 ) * $rate, 2 );

			$used = self::card( __( 'This period', 'plain-language-time-tracker' ), pltt_format_duration( $used_minutes ) );
			if ( $alloc_minutes > 0 ) {
				$pct = (int) round( $used_minutes / $alloc_minutes * 100 );
				/* translators: %s: hour allocation per period. */
				$used['value_suffix'] = sprintf( __( 'of %s', 'plain-language-time-tracker' ), pltt_format_duration( $alloc_minutes ) );
				/* translators: %d: percent of the period's allocation used. */
				$used['sub']          = sprintf( __( '%d%% of allocation', 'plain-language-time-tracker' ), $pct );
				$used['attention']    = $pct > 100;
			}

			$overage_card = self::card(
				__( 'Overage billable', 'plain-language-time-tracker' ),
				pltt_format_currency_compact( $overage ),
				array(
					'sub'       => $over_minutes > 0
						/* translators: %s: hours over allocation. */
						? sprintf( __( '%s over allocation', 'plain-language-time-tracker' ), pltt_format_duration( $over_minutes ) )
						: __( 'within allocation', 'plain-language-time-tracker' ),
					'attention' => $over_minutes > 0,
				)
			);

			// 4 — the period's billing status, from the same state machine the
			// filtered-Entries scope block uses, so the two can't disagree about
			// the same month. Replaces the old "Lifetime" card: the lifetime total
			// is what the Full view is for, and what this period is worth and
			// whether it's been billed is the question you're actually here with.
			$today     = pltt_get_current_date();
			$p_start   = (string) $window['from'];
			$p_end     = (string) $window['to'];
			// The last day is INSIDE the period (Rule 1) — see the note on
			// $is_closed in pltt_build_single_project_scope_figures().
			$is_closed = ( $p_end && $p_end < $today );
			$status    = pltt_retainer_period_status_figure(
				$project,
				$p_start,
				$p_end,
				$overage,
				$over_minutes > 0,
				$is_closed
			);
			$status_card = self::card(
				$status['figure']['label'],
				$status['figure']['value'],
				array(
					'sub_html' => $status['figure']['basis'],
					'over'     => ! empty( $status['figure']['over'] ),
					'muted'    => ! empty( $status['figure']['muted'] ),
				)
			);

			return array( $used, $overage_card, $avg, $status_card );
		}

		// ── Full (lifetime) view ──
		//
		// A different question from the period view: not "what do I bill for this
		// month" but "is this retainer healthy across its whole run"
		// (ui/pltt-scope-by-type.html §02). Average per month is the load-bearing
		// figure — it's the one that starts a conversation about resizing the plan.
		$summary  = self::retainer_summary( $project );
		$periods  = (int) $summary['periods'];
		$adj      = self::period_adjective( $project );
		$avg_life = $periods > 0 ? (int) round( $life_minutes / $periods ) : $life_minutes;

		// 1 — Total hours across the run.
		$total = self::card(
			__( 'Total hours', 'plain-language-time-tracker' ),
			pltt_format_duration( $life_minutes ),
			array(
				'sub' => $periods > 0
					? sprintf(
						/* translators: 1: number of periods; 2: cadence adjective, e.g. "monthly". */
						_n( 'Across %1$d %2$s period', 'Across %1$d %2$s periods', $periods, 'plain-language-time-tracker' ),
						$periods,
						$adj
					)
					: '',
			)
		);

		// 2 — Average per period against the allocation, plus how many ran over.
		$avg_basis = '';
		if ( $alloc_minutes > 0 ) {
			$avg_basis = sprintf(
				/* translators: 1: percent of allocation; 2: the included hours, e.g. "3h". */
				__( '%1$d%% of the %2$s included', 'plain-language-time-tracker' ),
				(int) round( $avg_life / $alloc_minutes * 100 ),
				pltt_format_duration( $alloc_minutes )
			);
			if ( $periods > 0 ) {
				$avg_basis .= ' · ' . sprintf(
					/* translators: 1: periods over allocation; 2: total periods. */
					__( '%1$d of %2$d over', 'plain-language-time-tracker' ),
					(int) $summary['over_periods'],
					$periods
				);
			}
		}
		$avg_life_card = self::card(
			self::average_label( $project ),
			pltt_format_duration( $avg_life ),
			array(
				'sub'  => $avg_basis,
				'over' => ( $alloc_minutes > 0 && $avg_life > $alloc_minutes ),
			)
		);

		// 3 — What all that overage is worth.
		$overage_life = (float) $summary['overage_amount'];
		$overage_card = self::card(
			__( 'Overage billable', 'plain-language-time-tracker' ),
			pltt_format_currency( $overage_life ),
			array(
				'sub'   => $summary['over_periods'] > 0
					? sprintf(
						/* translators: 1: number of periods over; 2: cadence adjective, e.g. "monthly". */
						_n( 'Sum of %1$d %2$s overage', 'Sum of %1$d %2$s overages', (int) $summary['over_periods'], 'plain-language-time-tracker' ),
						(int) $summary['over_periods'],
						$adj
					)
					: __( 'never over allocation', 'plain-language-time-tracker' ),
				'muted' => $overage_life <= 0.0,
			)
		);

		// 4 — What of it is still owed. The basis line is the route to those
		// periods on the Billing surface, nothing else.
		$unbilled_life = (float) $summary['unbilled_amount'];
		$unbilled_opts = array(
			'over'  => $unbilled_life > 0,
			'muted' => $unbilled_life <= 0.0,
		);
		if ( $summary['unbilled_periods'] > 0 ) {
			$unbilled_opts['sub_link'] = array(
				'url'   => add_query_arg(
					array( 'page' => 'pltt-invoicing', 'view' => 'ready' ),
					admin_url( 'admin.php' )
				) . '#pltt-bill-proj-' . (int) $project->id,
				'label' => sprintf(
					/* translators: %d: number of periods with unbilled overage. */
					_n( '%d period', '%d periods', (int) $summary['unbilled_periods'], 'plain-language-time-tracker' ),
					(int) $summary['unbilled_periods']
				),
			);
		} else {
			$unbilled_opts['sub'] = __( 'nothing outstanding', 'plain-language-time-tracker' );
		}
		$unbilled_card = self::card(
			__( 'Not yet billed', 'plain-language-time-tracker' ),
			pltt_format_currency( $unbilled_life ),
			$unbilled_opts
		);

		return array( $total, $avg_life_card, $overage_card, $unbilled_card );
	}

	/**
	 * The retainer's cadence as an adjective — "monthly", "weekly", …
	 *
	 * @param object $project Project row.
	 * @return string
	 */
	private static function period_adjective( $project ) {
		$words = array(
			'weekly'    => __( 'weekly', 'plain-language-time-tracker' ),
			'monthly'   => __( 'monthly', 'plain-language-time-tracker' ),
			'quarterly' => __( 'quarterly', 'plain-language-time-tracker' ),
			'yearly'    => __( 'yearly', 'plain-language-time-tracker' ),
		);
		return isset( $words[ $project->recurring_period ] ) ? $words[ $project->recurring_period ] : $words['monthly'];
	}

	/**
	 * "Average per month" and friends — the label names the allocation period, so
	 * the figure can't be mistaken for a filtered span.
	 *
	 * @param object $project Project row.
	 * @return string
	 */
	private static function average_label( $project ) {
		$labels = array(
			'weekly'    => __( 'Average per week', 'plain-language-time-tracker' ),
			'monthly'   => __( 'Average per month', 'plain-language-time-tracker' ),
			'quarterly' => __( 'Average per quarter', 'plain-language-time-tracker' ),
			'yearly'    => __( 'Average per year', 'plain-language-time-tracker' ),
		);
		return isset( $labels[ $project->recurring_period ] ) ? $labels[ $project->recurring_period ] : $labels['monthly'];
	}

	/**
	 * Internal lineup (non-billable, descriptive): total hours, entries, months active.
	 *
	 * @param object      $project Project row (unused; kept for signature parity).
	 * @param object|null $stats   Aggregate stats.
	 * @return array[]
	 */
	private static function cards_internal( $project, $stats ) {
		$total_minutes = isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0;
		$count         = isset( $stats->total_count ) ? (int) $stats->total_count : 0;

		// Internal work has no rate, no bill and no budget, so three of the standard
		// figures have nothing to show. Rather than four dashes, carry only what's
		// true: how much time, what share of the whole, when it was last touched
		// (ui/pltt-scope-by-type.html §05).
		$cards = array(
			self::card(
				__( 'Total hours', 'plain-language-time-tracker' ),
				pltt_format_duration( $total_minutes ),
				array(
					'sub' => $count > 0
						? sprintf(
							/* translators: %s: entry count. */
							_n( '%s entry', '%s entries', $count, 'plain-language-time-tracker' ),
							number_format_i18n( $count )
						)
						: '',
				)
			),
		);

		// Share of everything tracked, lifetime against lifetime — never a project's
		// whole run divided by a single year. Descriptive framing only: "12% of
		// tracked time", never "only 12% billable".
		$all          = PLTT_Entries::get_stats( array() );
		$all_minutes  = isset( $all->total_minutes ) ? (int) $all->total_minutes : 0;
		$cards[]      = self::card(
			__( 'Share of tracked time', 'plain-language-time-tracker' ),
			( $all_minutes > 0 && $total_minutes > 0 )
				? sprintf( '%d%%', (int) round( $total_minutes / $all_minutes * 100 ) )
				: '',
			array(
				'sub' => $all_minutes > 0
					? sprintf(
						/* translators: %s: all time logged across every project. */
						__( 'of %s logged', 'plain-language-time-tracker' ),
						pltt_format_duration( $all_minutes )
					)
					: '',
			)
		);

		// Most recent touch — the date, with what it was on the basis line.
		$last_date = ! empty( $stats->last_entry_date ) ? $stats->last_entry_date : '';
		$last_desc = '';
		if ( $last_date ) {
			$recent = PLTT_Entries::get_all(
				array(
					'project_id' => (int) $project->id,
					'date_from'  => $last_date,
					'date_to'    => $last_date,
					'orderby'    => 'duration_minutes',
					'order'      => 'DESC',
					'limit'      => 1,
				)
			);
			if ( ! empty( $recent[0]->description ) ) {
				$last_desc = (string) $recent[0]->description;
			}
		}
		$cards[] = self::card(
			__( 'Most recent', 'plain-language-time-tracker' ),
			$last_date ? date_i18n( 'M j', strtotime( $last_date ) ) : '',
			array( 'sub' => $last_desc )
		);

		return $cards;
	}

	/**
	 * The budget, moved onto the volume chart (ui/pltt-budget-on-chart.html).
	 *
	 * This replaced the "Budget consumed" meter panel. The meter stated proportion,
	 * and the stat cards above it already state proportion in words — "32h 45m of
	 * 38h 42m used · 84%" — so the panel was the third telling. What no figure can
	 * say is WHEN the limit was reached and how much work happened after it, which
	 * is a fact about the calendar and belongs on the chart of the calendar.
	 *
	 * Returns up to three independent pieces, each null when its own gate fails:
	 *
	 *   cross → fixed fee, over budget: the day the budget ran out, and where that
	 *           day falls across the chart's columns.
	 *   pace  → fixed fee, still under: one sentence projecting when it runs out.
	 *   alloc → retainer on the by-month lifetime chart: the allocation as a
	 *           horizontal line, since a retainer goes over every month rather
	 *           than at one moment.
	 *
	 * Hourly and internal have no limit, so they get none of it.
	 *
	 * @param object      $project Project row.
	 * @param object|null $stats   Lifetime aggregate stats.
	 * @param float       $rate    Resolved hourly rate.
	 * @param array|null  $window  Active period window (recurring period lens).
	 * @param array|null  $chart   The pltt_build_period_chart_data() result, for column positions.
	 * @return array|null { cross, pace, alloc }, or null when the type has no limit.
	 */
	private static function build_budget( $project, $stats, $rate, $window = null, $chart = null ) {
		$type = pltt_get_billing_type( $project );
		if ( 'fixed' !== $type && 'recurring' !== $type ) {
			return null;
		}

		// One ceiling rule for both types: hours if set, else the fee at the resolved
		// rate. A fee-budgeted project with no rate to divide by has no ceiling, and
		// says nothing rather than drawing 0% of a number it can't compute — which is
		// what the old meter did. A fixed project budgeted only in HOURS now gets a
		// line for the first time; the meter skipped those, because it gauged dollars.
		$ceiling = (int) pltt_budgeted_minutes( $project, $rate );
		if ( $ceiling <= 0 || empty( $chart['buckets'] ) ) {
			return null;
		}

		$out = array(
			'cross' => null,
			'alloc' => null,
		);

		if ( 'recurring' === $type ) {
			$out['alloc'] = self::budget_alloc( $ceiling, $window, $chart );
			return $out;
		}

		// A pace sentence for projects still under budget ("At 6h 58m a month, the
		// budget runs out around Jan 13, 2027") was built here and DROPPED
		// 2026-08-13 before it shipped — Patrick's call, while we were still
		// settling its wording. Recoverable from git; don't rebuild it on a hunch.
		$total = isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0;
		if ( $total > $ceiling ) {
			$out['cross'] = self::budget_cross( $project, $stats, $ceiling, $total, $chart );
		}

		return $out;
	}

	/**
	 * The day a fixed budget ran out, and where it sits across the chart.
	 *
	 * The position is worked out from the DATE, not from a running total of the
	 * bars: the chart draws verified time only while the budget counts everything,
	 * so a cumulative walked across the bars would put the mark somewhere the cards
	 * disagree with. A date lands in exactly one column either way.
	 *
	 * @param object      $project Project row.
	 * @param object|null $stats   Lifetime stats.
	 * @param int         $ceiling Budgeted minutes.
	 * @param int         $total   Minutes logged.
	 * @param array       $chart   Chart data.
	 * @return array|null
	 */
	private static function budget_cross( $project, $stats, $ceiling, $total, $chart ) {
		$first = ! empty( $stats->first_entry_date ) ? $stats->first_entry_date : '';
		$last  = ! empty( $stats->last_entry_date ) ? $stats->last_entry_date : '';
		if ( ! $first || ! $last ) {
			return null;
		}

		$daily      = PLTT_Entries::get_daily_minutes_for_project( (int) $project->id, $first, $last );
		$cumulative = 0;
		$cross_date = '';
		foreach ( $daily as $date => $minutes ) {
			$cumulative += (int) $minutes;
			if ( $cumulative >= $ceiling ) {
				$cross_date = $date;
				break;
			}
		}
		if ( ! $cross_date ) {
			return null;
		}

		$pos = self::chart_position( $chart, $cross_date );
		if ( null === $pos ) {
			// The crossing predates the charted window (or postdates it). Nothing to
			// mark — and nothing invented at the chart's edge to suggest otherwise.
			return null;
		}

		return array(
			'date'         => $cross_date,
			'date_label'   => date_i18n( 'M j', strtotime( $cross_date ) ),
			'over_minutes' => $total - $ceiling,
			'f'            => $pos['f'],
			'floor'        => $pos['floor'],
			'n'            => $pos['n'],
		);
	}

	/**
	 * The retainer allocation, as the line each month's bar is split against.
	 *
	 * A retainer has no single moment it went over — it goes over every month — so
	 * the question "when" becomes "which months", and the answer is every bar with
	 * amber on top of it. The allocation was drawn as a horizontal rule as well for
	 * one build; it came out (2026-08-13) because the chart's own average line says
	 * more about how a retainer is running than a limit already visible as the seam
	 * in every bar.
	 *
	 * Only on the lifetime by-month view: a month's allocation applied to a chart of
	 * days would split each day against a whole month's hours, which is nonsense. In
	 * the single-period view the cards already carry the period's own figures.
	 *
	 * @param int        $ceiling Allocation minutes.
	 * @param array|null $window  Active period window.
	 * @param array      $chart   Chart data.
	 * @return array|null
	 */
	private static function budget_alloc( $ceiling, $window, $chart ) {
		$is_period = is_array( $window ) && ! empty( $window['is_period'] );
		if ( $is_period || 'month' !== ( $chart['bucket_size'] ?? '' ) ) {
			return null;
		}

		return array( 'minutes' => $ceiling );
	}

	/**
	 * Per-request memo for the retainer period scan.
	 *
	 * It walks every allocation period with a query apiece, so anything on this
	 * screen that needs "how many periods, how many over" shares one answer.
	 *
	 * @param object $project Project row.
	 * @return array PLTT_Billing::get_retainer_summary() result.
	 */
	private static function retainer_summary( $project ) {
		static $cache = array();
		$key = (int) $project->id;
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = PLTT_Billing::get_retainer_summary( $project );
		}
		return $cache[ $key ];
	}

	/**
	 * Where a date sits across the chart's columns, as a fractional column index.
	 *
	 * Returns the index plus how far into that column's own span the date falls, so
	 * a week or month bucket puts the mark at the right point inside the bar rather
	 * than snapped to its edge. `floor` is handed over with it because the CSS has to
	 * add one column gap per whole column to its left, and CSS can't floor.
	 *
	 * @param array  $chart Chart data.
	 * @param string $date  Y-m-d.
	 * @return array|null { f, floor, n }
	 */
	private static function chart_position( $chart, $date ) {
		$buckets = $chart['buckets'] ?? array();
		$n       = count( $buckets );
		foreach ( $buckets as $i => $bucket ) {
			$start = $bucket['start'] ?? '';
			$end   = $bucket['end'] ?? '';
			if ( ! $start || ! $end || $date < $start || $date > $end ) {
				continue;
			}
			$span = max( 1, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1 );
			$into = (int) round( ( strtotime( $date ) - strtotime( $start ) ) / DAY_IN_SECONDS );
			// Mid-day, so a one-day bucket marks its own centre rather than its edge.
			return array(
				'f'     => round( $i + ( ( $into + 0.5 ) / $span ), 4 ),
				'floor' => $i,
				'n'     => $n,
			);
		}
		return null;
	}

	/**
	 * Normalize a possibly-empty numeric DB field to a float.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private static function num( $value ) {
		return ( null !== $value && '' !== $value ) ? (float) $value : 0.0;
	}

	/**
	 * Format a rate as "$X/hr" (whole dollars).
	 *
	 * @param float $rate Hourly rate.
	 * @return string
	 */
	private static function rate_str( $rate ) {
		/* translators: %s: dollar amount per hour. */
		return sprintf( __( '%s/hr', 'plain-language-time-tracker' ), pltt_format_currency_compact( round( $rate ) ) );
	}

	/**
	 * Calendar months spanned by two dates, inclusive (>= 1 when both are set).
	 *
	 * @param string $first First date (Y-m-d).
	 * @param string $last  Last date (Y-m-d).
	 * @return int
	 */
	private static function months_between( $first, $last ) {
		if ( ! $first || ! $last ) {
			return 0;
		}
		$a = strtotime( $first );
		$b = strtotime( $last );
		return ( (int) gmdate( 'Y', $b ) - (int) gmdate( 'Y', $a ) ) * 12
			+ ( (int) gmdate( 'n', $b ) - (int) gmdate( 'n', $a ) ) + 1;
	}

}

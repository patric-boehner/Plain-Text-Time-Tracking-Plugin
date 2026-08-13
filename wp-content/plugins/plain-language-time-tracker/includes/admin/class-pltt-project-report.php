<?php
/**
 * Project Report — read-only aggregations for the Report tab.
 *
 * Pure data layer: turns a project's stats into the type-aware hero, the stat
 * cards, and the volume ("Hours by …") chart. No output, no per-entry queries in
 * loops. Consumed by templates/partials/project-detail-report.php.
 *
 * The per-tag "Where the time went" bars and the "Activity over time" swimlane
 * (and all their entry/tag grouping + timeline-axis machinery) were removed
 * 2026-07-18 — see docs/removed-project-report-sections.md to rebuild them.
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
	 *     @type array  $hero        Type-aware hero view-model (or null).
	 *     @type array  $cards       Stat-card figures.
	 *     @type array  $chart       Volume ("Hours by …") chart data (or null).
	 *     @type array  $window      Active period-lens window (or null).
	 * }
	 *
	 * Note: this used to also return `groupings` / `timeline_groupings` /
	 * `default_group` / `axis` / `budget_line` for the "Where the time went" bars
	 * and "Activity over time" swimlane. Both sections were removed 2026-07-18 —
	 * see docs/removed-project-report-sections.md to rebuild them.
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
			'hero'        => self::build_hero( $project, $stats, $rate, $window ),
			'cards'       => self::build_cards( $project, $card_stats, $rate, $window, $stats ),
			'chart'       => $chart,
			'window'      => is_array( $window ) ? $window : null,
		);
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
		$summary  = PLTT_Billing::get_retainer_summary( $project );
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
	 * Type-aware hero: the gauge block beneath the scope block.
	 *
	 * Only the two types with a limit get one — the gauge is the meter, and a meter
	 * needs something to measure against:
	 *
	 * Fixed   → "Budget consumed" gauge + Remaining / Hours / Effective-rate rows.
	 * Retainer→ current-period "This period" allocation gauge + Overage / Avg / Fee rows.
	 * Hourly / Internal → none.
	 *
	 * Kept strictly factual — no pace projections or "went over N of M" framing.
	 *
	 * @param object      $project Project row.
	 * @param object|null $stats   Lifetime aggregate stats.
	 * @param float       $rate    Resolved hourly rate.
	 * @param array|null  $window  Active period window (recurring period lens).
	 * @return array|null Hero view-model, or null when the type has no hero.
	 */
	private static function build_hero( $project, $stats, $rate, $window = null ) {
		switch ( pltt_get_billing_type( $project ) ) {
			case 'fixed':
				// The fixed hero gauges dollars against the fee, so a project
				// budgeted only in hours has nothing for it to draw either.
				if ( self::num( $project->budget_fee ) <= 0 ) {
					return null;
				}
				return self::hero_fixed( $project, $stats, $rate );
			case 'recurring':
				// A retainer with no allocation recorded has no ceiling. It used to
				// draw a gauge reading 0% of nothing, which says less than no gauge
				// at all. Matches what the rest of the app already does with these:
				// the Overview summary's Budget column shows a dash
				// (templates/reports.php), and the day's consumption notice skips
				// them outright (pltt_consumption_status).
				if ( self::num( $project->budget_hours ) <= 0 ) {
					return null;
				}
				return self::hero_recurring( $project, $stats, $rate, $window );
			default:
				// Hourly and internal have no limit to gauge against, so there is
				// nothing for a hero to show that the scope block's figures don't
				// already say (ui/pltt-scope-by-type.html §01, §05).
				return null;
		}
	}

	/**
	 * Fixed-budget hero: value consumed against the fixed fee.
	 *
	 * @param object      $project Project row.
	 * @param object|null $stats   Lifetime stats.
	 * @param float       $rate    Resolved hourly rate.
	 * @return array
	 */
	private static function hero_fixed( $project, $stats, $rate ) {
		$total_minutes = isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0;
		$budget        = self::num( $project->budget_fee );
		// Value burned = hours × rate (fixed-fee entries are non-billable, so
		// billable_amount is $0 — mirror the Reports allocation bar's spent_dollars).
		$consumed  = round( ( $total_minutes / 60.0 ) * $rate, 2 );
		$remaining = round( $budget - $consumed, 2 );
		$pct       = $budget > 0 ? ( $consumed / $budget ) : 0.0;
		$seg       = self::gauge_segments( $consumed, $budget );

		return array(
			'type'     => 'fixed',
			'mode'     => 'gauge',
			'tag'      => __( 'Budget consumed', 'plain-language-time-tracker' ),
			'gauge'    => array(
				'pct'        => $pct,
				'state'      => self::gauge_state( $pct ),
				'within_pct' => $seg['within'],
				'over_pct'   => $seg['over'],
				// What the limit is, printed on the marker so the meter reads
				// without its caption.
				'marker'     => __( 'budget', 'plain-language-time-tracker' ),
				/* translators: 1: value consumed; 2: the budget. */
				'basis'      => sprintf(
					__( '%1$s against a %2$s budget', 'plain-language-time-tracker' ),
					pltt_format_currency( $consumed ),
					pltt_format_currency( max( 0.0, $budget ) )
				),
				'delta'      => self::gauge_delta(
					$pct,
					$remaining >= 0
						/* translators: %s: dollars remaining. */
						? sprintf( __( '%s left', 'plain-language-time-tracker' ), pltt_format_currency( $remaining ) )
						/* translators: %s: dollars over budget. */
						: sprintf( __( '%s over', 'plain-language-time-tracker' ), pltt_format_currency( abs( $remaining ) ) )
				),
			),
			// No mini-rows: remaining, hours and effective rate are all figures in
			// the scope block above. What's left is the meter, which is the one
			// thing the block can't say — proportion.
			'minirows' => array(),
		);
	}

	/**
	 * Retainer hero: an allocation-period usage gauge.
	 *
	 * Follows the page's period lens — stepping the lens to a month re-points the
	 * gauge to that month. With no period selected (Full scope) it defaults to the
	 * LATEST period that had activity, so a retainer whose most recent work was
	 * last month never headlines an empty "this month" gauge.
	 *
	 * @param object      $project Project row.
	 * @param object|null $stats   Lifetime stats.
	 * @param float       $rate    Resolved hourly rate.
	 * @param array|null  $window  Active period lens window (from the controller).
	 * @return array
	 */
	private static function hero_recurring( $project, $stats, $rate, $window = null ) {
		$alloc_minutes = (int) round( self::num( $project->budget_hours ) * 60 );
		$today         = pltt_get_current_date();

		$follows_lens = is_array( $window ) && ! empty( $window['is_period'] );
		if ( $follows_lens ) {
			$p_start = (string) $window['from'];
			$p_end   = (string) $window['to'];
		} else {
			// Default: the latest period that had activity (falls back to today when
			// there are no entries — the hero doesn't render in that case anyway).
			$ref_date = ! empty( $stats->last_entry_date ) ? $stats->last_entry_date : $today;
			list( $p_start, $p_end ) = pltt_get_allocation_period_bounds( $project, $ref_date );
		}

		$used_minutes = 0;
		if ( $p_start && $p_end ) {
			$ps = PLTT_Entries::get_stats( array( 'project_id' => (int) $project->id, 'date_from' => $p_start, 'date_to' => $p_end ) );
			$used_minutes = isset( $ps->total_minutes ) ? (int) $ps->total_minutes : 0;
		}

		// build_hero() only reaches here with a real allocation, so there is always
		// a limit to be under or over.
		$pct      = $used_minutes / $alloc_minutes;
		$over_min = max( 0, $used_minutes - $alloc_minutes );
		$rem_min  = max( 0, $alloc_minutes - $used_minutes );

		if ( $over_min > 0 ) {
			/* translators: %s: hours over the allocation. */
			$cap = sprintf( __( '%s over', 'plain-language-time-tracker' ), pltt_format_duration( $over_min ) );
		} else {
			/* translators: %s: hours remaining in the allocation. */
			$cap = sprintf( __( '%s left', 'plain-language-time-tracker' ), pltt_format_duration( $rem_min ) );
		}

		$seg = self::gauge_segments( $used_minutes, $alloc_minutes );

		return array(
			'type'     => 'recurring',
			'mode'     => 'gauge',
			// Same label as the fixed-fee meter: both answer "how much of the
			// limit is gone", and the marker below names which limit it is. The
			// tag used to carry the period too ("Period · July 2026"), which made
			// the page state the same month three times — date filter, scope
			// block, and here.
			'tag'      => __( 'Budget consumed', 'plain-language-time-tracker' ),
			'gauge'    => array(
				'pct'        => $pct,
				'state'      => self::gauge_state( $pct ),
				'within_pct' => $seg['within'],
				'over_pct'   => $seg['over'],
				// The marker states the allocation itself — "3h included" — so the
				// meter says what the limit is without the caption underneath.
				/* translators: %s: the period's hour allocation, e.g. "3h". */
				'marker'     => sprintf( __( '%s included', 'plain-language-time-tracker' ), pltt_format_duration( $alloc_minutes ) ),
				/* translators: 1: hours used this period; 2: the hour allocation. */
				'basis'      => sprintf(
					__( '%1$s against the %2$s included', 'plain-language-time-tracker' ),
					pltt_format_duration( $used_minutes ),
					pltt_format_duration( $alloc_minutes )
				),
				'delta'      => self::gauge_delta( $pct, $cap ),
			),
			// No mini-rows: overage, average and the allocation are all figures in
			// the scope block above. What's left is the meter — proportion, which
			// is the one thing the block can't state in words.
			'minirows' => array(),
		);
	}

	/**
	 * Classify gauge fill into a state color: ok / warn (>=85%) / over (>100%).
	 *
	 * @param float $pct Fraction used (0..>1).
	 * @return string
	 */
	private static function gauge_state( $pct ) {
		if ( $pct > 1.0 ) {
			return 'over';
		}
		return $pct >= 0.85 ? 'warn' : 'ok';
	}

	/**
	 * Split a used/total pair into within-limit and overage bar segments (percent
	 * of the full track), mirroring pltt_render_allocation_bar: when over, the bar
	 * fills 100% split proportionally so the overage magnitude is visible; when
	 * under, a single segment fills used/total.
	 *
	 * @param float $used  Amount used (minutes or dollars).
	 * @param float $total Limit (allocation minutes or budget dollars).
	 * @return array{within: float, over: float}
	 */
	/**
	 * The meter's right-hand caption: "84% · 5h 57m left", "249% · $5,771.67 over".
	 *
	 * Exactly 100% reads "fully used" rather than "0 left" — at the limit is a
	 * state worth naming, and "0m left" reads like a rounding artefact.
	 *
	 * Tested against the true ratio, not the rounded percent: one minute past a
	 * 3h allocation is 100.06%, which rounds to 100 but does put an amber sliver
	 * on the bar. Saying "fully used" beside it would have the caption contradict
	 * the meter. It reads "100% · 1m over" instead — blunt, but true.
	 *
	 * @param float  $pct   Used ÷ limit (1.0 = exactly at the limit).
	 * @param string $delta Already-formatted remainder, e.g. "5h 57m left".
	 * @return string
	 */
	private static function gauge_delta( $pct, $delta ) {
		$whole = (int) round( $pct * 100 );
		if ( abs( $pct - 1.0 ) < 0.00001 ) {
			/* translators: shown when consumption exactly equals the limit. */
			return __( '100% · fully used', 'plain-language-time-tracker' );
		}
		/* translators: 1: percent of the limit used; 2: what is left or over. */
		return sprintf( __( '%1$d%% · %2$s', 'plain-language-time-tracker' ), $whole, $delta );
	}

	private static function gauge_segments( $used, $total ) {
		if ( $total > 0 && $used > $total ) {
			$within = ( $total / $used ) * 100;
			return array( 'within' => $within, 'over' => 100 - $within );
		}
		return array(
			'within' => $total > 0 ? min( 100.0, ( $used / $total ) * 100 ) : 0.0,
			'over'   => 0.0,
		);
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
	 * "vs $X target" sub-line.
	 *
	 * @param float $rate Target hourly rate.
	 * @return string
	 */
	private static function vs_target( $rate ) {
		/* translators: %s: target hourly rate. */
		return sprintf( __( 'vs %s target', 'plain-language-time-tracker' ), pltt_format_currency_compact( round( $rate ) ) );
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

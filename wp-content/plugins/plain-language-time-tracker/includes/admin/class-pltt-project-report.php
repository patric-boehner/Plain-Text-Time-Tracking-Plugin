<?php
/**
 * Project Report — read-only lifetime aggregations for the Report tab.
 *
 * Pure data layer: one pass over a project's entries + their tags produces the
 * stat-card figures and the per-tag-group bar buckets. No output, no per-entry
 * queries in loops. Consumed by templates/partials/project-detail-report.php.
 *
 * Phase 2: stat cards (fixed-budget lineup) + "Where the time went" bars.
 * The swimlane timeline (per-day ticks, gaps) is Phase 3.
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
	 * Synthetic key for the "tags with no group" grouping.
	 */
	const UNGROUPED = '__ungrouped__';

	/**
	 * Synthetic key for the "no tag in this group" bucket.
	 */
	const UNTAGGED = '__untagged__';

	/**
	 * Idle-gap threshold (days). A run of >= this many days with nothing logged
	 * breaks a timeline lane into separate segments. The single tunable for the
	 * "Activity over time" timeline — raise if lanes break too often.
	 */
	const GAP_THRESHOLD_DAYS = 7;

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
	 *     @type array  $cards         Stat-card figures.
	 *     @type array  $groupings     Map of grouping key => grouping data (buckets, etc.).
	 *     @type string $default_group The grouping key to show first.
	 * }
	 */
	public static function build( $project_id, $project, $client = null, $stats = null, $window = null, $windowed_stats = null ) {
		if ( null === $stats ) {
			$stats = PLTT_Entries::get_stats( array( 'project_id' => $project_id ) );
		}

		// Lifetime entries — drive the swimlane (always the full arc) and the
		// group-by toggle's dimension set. Only id/date/duration are read here, so
		// request just those instead of every column (OPT-N-C); tags are loaded
		// separately by id below.
		$entries = PLTT_Entries::get_all(
			array(
				'project_id' => $project_id,
				'orderby'    => 'entry_date',
				'order'      => 'ASC',
				'fields'     => array( 'id', 'entry_date', 'duration_minutes' ),
			)
		);

		$entry_ids     = array();
		foreach ( $entries as $e ) {
			$entry_ids[] = (int) $e->id;
		}
		$tags_by_entry = PLTT_Tags::get_for_entries( $entry_ids );
		$name_to_group = PLTT_Tags::get_name_to_group_map();
		// Load the group list once and feed both build_groupings() calls (OPT-N-B).
		$group_names   = PLTT_Tags::get_all_groups();

		$rate = (float) pltt_resolve_billable_rate( (int) $project->client_id, (int) $project_id );

		// Lifetime groupings: the swimlane (always the full arc) and the toggle's
		// dimension set/default both read from these.
		$timeline_groupings = self::build_groupings( $entries, $tags_by_entry, $name_to_group, $group_names );

		// Windowed slice → stat cards + "Where the time went" bars + volume chart.
		// For the full/lifetime view (every non-recurring project, and recurring
		// projects in "Full" scope) the window is the whole span, so these collapse
		// back to the all-time figures and nothing about today's behavior changes.
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

			$win_entries = array();
			foreach ( $entries as $e ) {
				if ( $e->entry_date >= $window['from'] && $e->entry_date <= $window['to'] ) {
					$win_entries[] = $e;
				}
			}
			$bar_groupings = self::build_groupings( $win_entries, $tags_by_entry, $name_to_group, $group_names );
		} else {
			$card_stats    = $stats;
			$bar_groupings = $timeline_groupings;
		}

		// Volume chart spans the active window (== lifetime span when not windowed).
		$chart_from = is_array( $window ) && ! empty( $window['from'] ) ? $window['from'] : ( $stats->first_entry_date ?? '' );
		$chart_to   = is_array( $window ) && ! empty( $window['to'] ) ? $window['to'] : ( $stats->last_entry_date ?? '' );
		$chart      = ( $chart_from && $chart_to )
			? pltt_build_period_chart_data( $chart_from, $chart_to, array( 'project_id' => (int) $project_id ) )
			: null;

		return array(
			'has_entries'        => ( isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0 ) > 0,
			'hero'               => self::build_hero( $project, $stats, $rate, $project_id, $window ),
			'cards'              => self::build_cards( $project, $card_stats, $rate, $window, $stats ),
			'groupings'          => $bar_groupings,        // windowed — "Where the time went".
			'timeline_groupings' => $timeline_groupings,   // lifetime — swimlane.
			'default_group'      => self::pick_default_group( $timeline_groupings ),
			'axis'               => self::build_axis( $stats ),
			'budget_line'        => self::build_budget_line( $project, $stats, $entries, $rate ),
			'chart'              => $chart,
			'window'             => is_array( $window ) ? $window : null,
		);
	}

	/**
	 * Choose which grouping shows first: prefer a phase-like one, else the first.
	 *
	 * @param array $groupings Exposed groupings (key => data).
	 * @return string Grouping key (empty string if none).
	 */
	private static function pick_default_group( $groupings ) {
		foreach ( $groupings as $key => $data ) {
			if ( ! empty( $data['is_phase'] ) ) {
				return $key;
			}
		}
		return (string) array_key_first( $groupings );
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
	 * @param array  $opts  Optional: suffix, sub, attention (bool).
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
		$fee           = self::num( $project->budget_fee );

		$budgeted_minutes = pltt_budgeted_minutes( $project, $rate );
		$ehr              = pltt_effective_rate( $fee, $total_minutes );

		// Total hours + over/under budget.
		$total = self::card( __( 'Total hours', 'plain-language-time-tracker' ), pltt_format_duration( $total_minutes ) );
		if ( $budgeted_minutes > 0 ) {
			$ou = $total_minutes - $budgeted_minutes;
			if ( $ou > 0 ) {
				/* translators: %s: duration over budget. */
				$total['sub']       = sprintf( __( '+%s over budget', 'plain-language-time-tracker' ), pltt_format_duration( $ou ) );
				$total['attention'] = true;
			} elseif ( $ou < 0 ) {
				/* translators: %s: duration under budget. */
				$total['sub'] = sprintf( __( '%s under budget', 'plain-language-time-tracker' ), pltt_format_duration( abs( $ou ) ) );
			} else {
				$total['sub'] = __( 'on budget', 'plain-language-time-tracker' );
			}
		}

		// Budget card.
		if ( $budgeted_minutes > 0 ) {
			$pct    = (int) round( $total_minutes / $budgeted_minutes * 100 );
			$budget = self::card(
				__( 'Budget', 'plain-language-time-tracker' ),
				pltt_format_duration( $total_minutes ),
				array(
					/* translators: %s: budgeted hours. */
					'suffix'    => sprintf( __( 'of %s', 'plain-language-time-tracker' ), pltt_format_duration( $budgeted_minutes ) ),
					/* translators: %d: percent of budget used. */
					'sub'       => sprintf( __( '%d%% used', 'plain-language-time-tracker' ), $pct ),
					'attention' => $pct > 100,
				)
			);
		} else {
			$budget = self::card( __( 'Budget', 'plain-language-time-tracker' ), '', array( 'sub' => __( 'no budget set', 'plain-language-time-tracker' ) ) );
		}

		return array(
			$total,
			self::card(
				__( 'Effective rate', 'plain-language-time-tracker' ),
				$ehr > 0 ? self::rate_str( $ehr ) : '',
				array( 'sub' => $rate > 0 ? self::vs_target( $rate ) : '' )
			),
			$budget,
			self::card(
				__( 'Fixed fee', 'plain-language-time-tracker' ),
				$fee > 0 ? pltt_format_currency_compact( $fee ) : '',
				array(
					/* translators: %s: hourly rate used for budgeting. */
					'sub' => ( $fee > 0 && $rate > 0 ) ? sprintf( __( '%s/hr budgeted', 'plain-language-time-tracker' ), pltt_format_currency_compact( round( $rate ) ) ) : '',
				)
			),
		);
	}

	/**
	 * Hourly lineup: total hours, billable amount, effective rate, unbilled.
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
		$unbilled         = isset( $stats->unbilled_billable_minutes ) ? (int) $stats->unbilled_billable_minutes : 0;
		$ehr              = pltt_effective_rate( $billable_amount, $total_minutes );

		return array(
			self::card( __( 'Total hours', 'plain-language-time-tracker' ), pltt_format_duration( $total_minutes ) ),
			self::card(
				__( 'Billable', 'plain-language-time-tracker' ),
				$billable_amount > 0 ? pltt_format_currency_compact( $billable_amount ) : '',
				array(
					/* translators: %s: billable hours. */
					'sub' => sprintf( __( '%s billable', 'plain-language-time-tracker' ), pltt_format_duration( $billable_minutes ) ),
				)
			),
			self::card(
				__( 'Effective rate', 'plain-language-time-tracker' ),
				$ehr > 0 ? self::rate_str( $ehr ) : '',
				array(
					/* translators: %s: hourly rate. */
					'sub' => $rate > 0 ? sprintf( __( 'vs %s rate', 'plain-language-time-tracker' ), pltt_format_currency_compact( round( $rate ) ) ) : '',
				)
			),
			self::card(
				__( 'Unbilled', 'plain-language-time-tracker' ),
				$unbilled > 0 ? pltt_format_duration( $unbilled ) : '',
				$unbilled > 0
					? array(
						'sub'       => __( 'to invoice', 'plain-language-time-tracker' ),
						'attention' => true,
					)
					: array( 'sub' => __( 'all invoiced', 'plain-language-time-tracker' ) )
			),
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

		// Avg / month vs allocation (shared by both views).
		$avg = self::card( __( 'Avg / month', 'plain-language-time-tracker' ), pltt_format_duration( $avg_minutes ) );
		if ( $alloc_minutes > 0 ) {
			$avg_pct             = (int) round( $avg_minutes / $alloc_minutes * 100 );
			/* translators: %s: hour allocation per period. */
			$avg['value_suffix'] = sprintf( __( 'of %s', 'plain-language-time-tracker' ), pltt_format_duration( $alloc_minutes ) );
			/* translators: %d: percent of allocation used. */
			$avg['sub']          = sprintf( __( '%d%% of allocation', 'plain-language-time-tracker' ), $avg_pct );
			$avg['attention']    = $avg_pct > 100;
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

			$lifetime_card = self::card(
				__( 'Lifetime', 'plain-language-time-tracker' ),
				pltt_format_duration( $life_minutes ),
				array(
					'sub' => $months > 0
						/* translators: %d: number of months active. */
						? sprintf( _n( 'over %d month', 'over %d months', $months, 'plain-language-time-tracker' ), $months )
						: '',
				)
			);

			return array( $used, $overage_card, $avg, $lifetime_card );
		}

		// ── Full (lifetime) view ──
		$months_card = self::card(
			__( 'Months active', 'plain-language-time-tracker' ),
			$months > 0
				/* translators: %d: number of months. */
				? sprintf( _n( '%d month', '%d months', $months, 'plain-language-time-tracker' ), $months )
				: ''
		);

		$period_word = array(
			'weekly'    => __( 'per week', 'plain-language-time-tracker' ),
			'monthly'   => __( 'per month', 'plain-language-time-tracker' ),
			'quarterly' => __( 'per quarter', 'plain-language-time-tracker' ),
			'yearly'    => __( 'per year', 'plain-language-time-tracker' ),
		);
		$alloc_card = self::card(
			__( 'Allocation', 'plain-language-time-tracker' ),
			$alloc_minutes > 0 ? pltt_format_duration( $alloc_minutes ) : '',
			array( 'sub' => isset( $period_word[ $project->recurring_period ] ) ? $period_word[ $project->recurring_period ] : '' )
		);

		return array(
			self::card( __( 'Total hours', 'plain-language-time-tracker' ), pltt_format_duration( $life_minutes ) ),
			$avg,
			$months_card,
			$alloc_card,
		);
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
		$months        = self::months_between( $stats->first_entry_date ?? '', $stats->last_entry_date ?? '' );

		return array(
			self::card( __( 'Total hours', 'plain-language-time-tracker' ), pltt_format_duration( $total_minutes ) ),
			self::card(
				__( 'Entries', 'plain-language-time-tracker' ),
				$count > 0 ? number_format_i18n( $count ) : '0'
			),
			self::card(
				__( 'Active span', 'plain-language-time-tracker' ),
				$months > 0
					/* translators: %d: number of months. */
					? sprintf( _n( '%d month', '%d months', $months, 'plain-language-time-tracker' ), $months )
					: ''
			),
		);
	}

	/**
	 * Type-aware hero: the headline block above the cards.
	 *
	 * Hourly  → "Earned to date" figure + Billed / Unbilled / Effective-rate rows.
	 * Fixed   → "Budget consumed" gauge + Remaining / Hours / Effective-rate rows.
	 * Retainer→ current-period "This period" allocation gauge + Overage / Avg / Fee rows.
	 * Internal→ no hero (nothing billable to headline); returns null.
	 *
	 * Kept strictly factual — no pace projections or "went over N of M" framing.
	 *
	 * @param object      $project    Project row.
	 * @param object|null $stats      Lifetime aggregate stats.
	 * @param float       $rate       Resolved hourly rate.
	 * @param int         $project_id Project ID (for the billed/absorbed lookup).
	 * @return array|null Hero view-model, or null when the type has no hero.
	 */
	private static function build_hero( $project, $stats, $rate, $project_id, $window = null ) {
		switch ( pltt_get_billing_type( $project ) ) {
			case 'fixed':
				return self::hero_fixed( $project, $stats, $rate );
			case 'recurring':
				return self::hero_recurring( $project, $stats, $rate, $window );
			case 'none':
				return null;
			default:
				return self::hero_hourly( $project, $stats, $rate, $project_id );
		}
	}

	/**
	 * Hourly hero: lifetime earned value, split into billed / unbilled.
	 *
	 * Every billable entry's value is either frozen onto a record (billed or
	 * absorbed) or still uncovered (unbilled), so unbilled = earned − billed −
	 * absorbed. That avoids a second coverage query.
	 *
	 * @param object      $project    Project row.
	 * @param object|null $stats      Lifetime stats.
	 * @param float       $rate       Resolved hourly rate.
	 * @param int         $project_id Project ID.
	 * @return array
	 */
	private static function hero_hourly( $project, $stats, $rate, $project_id ) {
		$earned        = isset( $stats->billable_amount ) ? (float) $stats->billable_amount : 0.0;
		$total_minutes = isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0;

		$rec      = PLTT_Billing_Records::query( array( 'project_ids' => array( (int) $project_id ), 'limit' => 0 ) );
		$billed   = (float) $rec['billed'];
		$absorbed = (float) $rec['absorbed'];
		$unbilled = max( 0.0, round( $earned - $billed - $absorbed, 2 ) );
		$ehr      = pltt_effective_rate( $earned, $total_minutes );

		$rows = array(
			array( 'label' => __( 'Billed', 'plain-language-time-tracker' ), 'value' => pltt_format_currency( $billed ) ),
			array( 'label' => __( 'Unbilled', 'plain-language-time-tracker' ), 'value' => pltt_format_currency( $unbilled ), 'accent' => $unbilled > 0 ),
			array( 'label' => __( 'Effective rate', 'plain-language-time-tracker' ), 'value' => $ehr > 0 ? self::rate_str( $ehr ) : '—' ),
		);
		if ( $absorbed > 0 ) {
			$rows[] = array( 'label' => __( 'Absorbed', 'plain-language-time-tracker' ), 'value' => pltt_format_currency( $absorbed ) );
		}

		return array(
			'type'          => 'hourly',
			'mode'          => 'figure',
			'tag'           => __( 'Earned to date', 'plain-language-time-tracker' ),
			'period'        => '',
			'figure'        => pltt_format_currency( $earned ),
			'figure_suffix' => '· ' . pltt_format_duration( $total_minutes ),
			'note'          => $rate > 0
				/* translators: %s: hourly rate, e.g. "$100/hr". */
				? sprintf( __( 'All-time billable value at %s.', 'plain-language-time-tracker' ), self::rate_str( $rate ) )
				: __( 'All-time billable value.', 'plain-language-time-tracker' ),
			'gauge'         => null,
			'minirows'      => $rows,
		);
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
		$ehr       = pltt_effective_rate( $budget, $total_minutes );
		$seg       = self::gauge_segments( $consumed, $budget );

		return array(
			'type'     => 'fixed',
			'mode'     => 'gauge',
			'tag'      => __( 'Budget consumed', 'plain-language-time-tracker' ),
			'period'   => '',
			'gauge'    => array(
				'pct'        => $pct,
				'state'      => self::gauge_state( $pct ),
				'within_pct' => $seg['within'],
				'over_pct'   => $seg['over'],
				'used'  => pltt_format_currency( $consumed ),
				'total' => pltt_format_currency( max( 0.0, $budget ) ),
				'cap'   => $remaining >= 0
					/* translators: %s: dollars remaining. */
					? sprintf( __( '%s left', 'plain-language-time-tracker' ), pltt_format_currency( $remaining ) )
					/* translators: %s: dollars over budget. */
					: sprintf( __( '%s over', 'plain-language-time-tracker' ), pltt_format_currency( abs( $remaining ) ) ),
				'note'  => $budget > 0
					/* translators: 1: percent used; 2: hours logged. */
					? sprintf( __( '%1$d%% used · %2$s logged', 'plain-language-time-tracker' ), (int) round( $pct * 100 ), pltt_format_duration( $total_minutes ) )
					: __( 'no budget set', 'plain-language-time-tracker' ),
			),
			'minirows' => array(
				array( 'label' => __( 'Remaining', 'plain-language-time-tracker' ), 'value' => pltt_format_currency( $remaining ), 'accent' => $remaining < 0 ),
				array( 'label' => __( 'Hours logged', 'plain-language-time-tracker' ), 'value' => pltt_format_duration( $total_minutes ) ),
				array( 'label' => __( 'Effective rate', 'plain-language-time-tracker' ), 'value' => $ehr > 0 ? self::rate_str( $ehr ) : '—' ),
			),
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
		$fee           = self::num( $project->budget_fee );
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

		$is_current = ( $p_start && $p_end && $today >= $p_start && $today <= $p_end );

		// With no allocation configured there's nothing to be "over" — a retainer
		// missing budget_hours is misconfigured, so show plain usage, not overage.
		$has_alloc = $alloc_minutes > 0;
		$pct       = $has_alloc ? ( $used_minutes / $alloc_minutes ) : 0.0;
		$over_min  = $has_alloc ? max( 0, $used_minutes - $alloc_minutes ) : 0;
		$rem_min   = $has_alloc ? max( 0, $alloc_minutes - $used_minutes ) : 0;
		$overage   = round( ( $over_min / 60.0 ) * $rate, 2 );

		// Lifetime average per month, for context.
		$life_minutes = isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0;
		$months       = self::months_between( $stats->first_entry_date ?? '', $stats->last_entry_date ?? '' );
		$avg_minutes  = $months > 0 ? (int) round( $life_minutes / $months ) : $life_minutes;

		// Caption: overage / remaining when there's an allocation; plain usage when not.
		if ( ! $has_alloc ) {
			/* translators: %s: hours logged this period. */
			$cap = sprintf( __( '%s logged', 'plain-language-time-tracker' ), pltt_format_duration( $used_minutes ) );
		} elseif ( $over_min > 0 ) {
			/* translators: 1: hours over allocation; 2: overage dollars. */
			$cap = sprintf( __( '%1$s over · %2$s', 'plain-language-time-tracker' ), pltt_format_duration( $over_min ), pltt_format_currency( $overage ) );
		} else {
			/* translators: %s: hours remaining in the allocation. */
			$cap = sprintf( __( '%s remaining', 'plain-language-time-tracker' ), pltt_format_duration( $rem_min ) );
		}

		// Tag: "This period" when the shown period is live; a stepped-to period is
		// just "Period" (the label names which); the default fallback is "Latest".
		if ( $is_current ) {
			$tag = __( 'This period', 'plain-language-time-tracker' );
		} elseif ( $follows_lens ) {
			$tag = __( 'Period', 'plain-language-time-tracker' );
		} else {
			$tag = __( 'Latest period', 'plain-language-time-tracker' );
		}

		// Third mini-row: the monthly fee when one is set, else the allocation
		// (always meaningful for a real retainer — avoids a blank "Monthly fee").
		if ( $fee > 0 ) {
			$fee_row = array( 'label' => __( 'Monthly fee', 'plain-language-time-tracker' ), 'value' => pltt_format_currency( $fee ) );
		} else {
			$fee_row = array( 'label' => __( 'Allocation', 'plain-language-time-tracker' ), 'value' => $has_alloc ? pltt_format_duration( $alloc_minutes ) : '—' );
		}

		$seg = self::gauge_segments( $used_minutes, $alloc_minutes );

		return array(
			'type'     => 'recurring',
			'mode'     => 'gauge',
			'tag'      => $tag,
			'period'   => ( $p_start && $p_end ) ? self::period_label( $project, $p_start, $p_end ) : '',
			'gauge'    => array(
				'pct'        => $pct,
				'state'      => self::gauge_state( $pct ),
				'within_pct' => $seg['within'],
				'over_pct'   => $seg['over'],
				'used'  => pltt_format_duration( $used_minutes ),
				'total' => $has_alloc ? pltt_format_duration( $alloc_minutes ) : '—',
				'cap'   => $cap,
				'note'  => $has_alloc
					/* translators: %d: percent of allocation used. */
					? sprintf( __( '%d%% of allocation', 'plain-language-time-tracker' ), (int) round( $pct * 100 ) )
					: __( 'no allocation set', 'plain-language-time-tracker' ),
			),
			'minirows' => array(
				array( 'label' => __( 'Overage this period', 'plain-language-time-tracker' ), 'value' => $overage > 0 ? pltt_format_currency( $overage ) : '—', 'accent' => $overage > 0 ),
				array( 'label' => __( 'Avg / month', 'plain-language-time-tracker' ), 'value' => pltt_format_duration( $avg_minutes ) ),
				$fee_row,
			),
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
	 * Human label for an allocation period: "July 2026" for a calendar month,
	 * "2026" for a full year, else a date range.
	 *
	 * @param object $project Project row (for recurring_period).
	 * @param string $start   Period start (Y-m-d).
	 * @param string $end     Period end (Y-m-d).
	 * @return string
	 */
	private static function period_label( $project, $start, $end ) {
		$period = isset( $project->recurring_period ) ? $project->recurring_period : '';
		if ( 'monthly' === $period ) {
			return date_i18n( 'F Y', strtotime( $start ) );
		}
		if ( 'yearly' === $period ) {
			return date_i18n( 'Y', strtotime( $start ) );
		}
		return date_i18n( 'M j', strtotime( $start ) ) . ' – ' . date_i18n( 'M j, Y', strtotime( $end ) );
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

	/**
	 * Build every candidate grouping and decide which to expose.
	 *
	 * @param array $entries       Entry rows (ASC by date).
	 * @param array $tags_by_entry entry_id => [tag names].
	 * @param array $name_to_group tag name => group name (grouped tags only).
	 * @param array $group_names   All tag group names (loaded once by the caller; OPT-N-B).
	 * @return array Map of grouping key => grouping data. Only exposed groupings are returned.
	 */
	private static function build_groupings( $entries, $tags_by_entry, $name_to_group, $group_names ) {
		$candidate_keys = $group_names;
		$candidate_keys[] = self::UNGROUPED;

		$built = array();
		foreach ( $candidate_keys as $key ) {
			$built[ $key ] = self::build_one_grouping( $key, $entries, $tags_by_entry, $name_to_group );
		}

		// Expose only groupings that have at least one real (non-untagged) bucket
		// with logged time. If none qualify (e.g. all entries untagged), fall back
		// to Ungrouped so the section still renders honestly.
		$exposed = array();
		foreach ( $built as $key => $data ) {
			if ( $data['has_tagged'] ) {
				$exposed[ $key ] = $data;
			}
		}
		if ( empty( $exposed ) ) {
			$exposed[ self::UNGROUPED ] = $built[ self::UNGROUPED ];
		}

		return $exposed;
	}

	/**
	 * Build one grouping's buckets.
	 *
	 * @param string $key           Grouping key (group name or UNGROUPED).
	 * @param array  $entries       Entry rows.
	 * @param array  $tags_by_entry entry_id => [tag names].
	 * @param array  $name_to_group tag name => group name.
	 * @return array Grouping data: label, buckets, total_minutes, max_minutes, has_tagged, is_phase.
	 */
	private static function build_one_grouping( $key, $entries, $tags_by_entry, $name_to_group ) {
		$is_ungrouped = ( self::UNGROUPED === $key );
		$is_phase     = ! $is_ungrouped && self::looks_like_phase( $key );

		$buckets        = array(); // tag name => ['minutes'=>int, 'dates'=>set, 'first'=>, 'last'=>]
		$untagged       = array( 'minutes' => 0, 'dates' => array(), 'first' => null, 'last' => null );
		$grouping_total = 0;

		foreach ( $entries as $entry ) {
			$mins = (int) $entry->duration_minutes;
			if ( $mins <= 0 ) {
				continue;
			}
			$date     = $entry->entry_date;
			$all_tags = isset( $tags_by_entry[ (int) $entry->id ] ) ? $tags_by_entry[ (int) $entry->id ] : array();

			// Tags belonging to this grouping.
			$in_group = array();
			foreach ( $all_tags as $t ) {
				$has_group = isset( $name_to_group[ $t ] ) && '' !== $name_to_group[ $t ];
				if ( $is_ungrouped ) {
					if ( ! $has_group ) {
						$in_group[] = $t;
					}
				} elseif ( $has_group && $name_to_group[ $t ] === $key ) {
					$in_group[] = $t;
				}
			}

			$grouping_total += $mins;

			if ( empty( $in_group ) ) {
				self::accumulate( $untagged, $mins, $date );
				continue;
			}

			// Multi-tag: attribute full duration to each matching tag (pct may exceed 100%).
			foreach ( $in_group as $t ) {
				if ( ! isset( $buckets[ $t ] ) ) {
					$buckets[ $t ] = array( 'minutes' => 0, 'dates' => array(), 'first' => null, 'last' => null );
				}
				self::accumulate( $buckets[ $t ], $mins, $date );
			}
		}

		// Finalize tagged buckets.
		$out         = array();
		$max_minutes = 0;
		$has_tagged  = false;
		foreach ( $buckets as $name => $b ) {
			$out[]       = self::finalize_bucket( $name, $name, false, $b, $grouping_total );
			$max_minutes = max( $max_minutes, $b['minutes'] );
			$has_tagged  = true;
		}

		// Order: phase groupings chronologically (build order); others by hours desc.
		if ( $is_phase ) {
			usort(
				$out,
				static function ( $a, $b ) {
					return strcmp( (string) $a['first_date'], (string) $b['first_date'] );
				}
			);
		} else {
			usort(
				$out,
				static function ( $a, $b ) {
					return $b['minutes'] <=> $a['minutes'];
				}
			);
		}

		// Untagged bucket sorts last, if any.
		if ( $untagged['minutes'] > 0 ) {
			$out[]       = self::finalize_bucket( self::UNTAGGED, self::untagged_label( $key, $is_phase, $is_ungrouped ), true, $untagged, $grouping_total );
			$max_minutes = max( $max_minutes, $untagged['minutes'] );
		}

		$label = $is_ungrouped ? __( 'Ungrouped', 'plain-language-time-tracker' ) : $key;

		return array(
			'key'             => $key,
			'label'           => $label,
			'description'     => self::grouping_description( $label, $is_phase, $is_ungrouped ),
			'buckets'         => $out,
			'total_minutes'   => $grouping_total,
			'max_minutes'     => $max_minutes,
			'has_tagged'      => $has_tagged,
			'is_phase'        => $is_phase,
		);
	}

	/**
	 * One-line helper sentence shown under the "Where the time went" header.
	 *
	 * @param string $label        Grouping label.
	 * @param bool   $is_phase     Phase-like grouping.
	 * @param bool   $is_ungrouped Ungrouped grouping.
	 * @return string
	 */
	private static function grouping_description( $label, $is_phase, $is_ungrouped ) {
		if ( $is_phase ) {
			return __( 'Hours per phase, in build order. The longest bar consumed the most time.', 'plain-language-time-tracker' );
		}
		if ( $is_ungrouped ) {
			return __( 'Hours by tag, largest first.', 'plain-language-time-tracker' );
		}
		/* translators: %s: tag group name. */
		return sprintf( __( 'Hours by %s tag, largest first.', 'plain-language-time-tracker' ), $label );
	}

	/**
	 * Add minutes + date to a bucket accumulator.
	 *
	 * @param array  $bucket Accumulator (by reference).
	 * @param int    $mins   Minutes to add.
	 * @param string $date   Entry date (Y-m-d).
	 */
	private static function accumulate( &$bucket, $mins, $date ) {
		$bucket['minutes'] += $mins;
		if ( ! isset( $bucket['dates'][ $date ] ) ) {
			$bucket['dates'][ $date ] = 0;
		}
		$bucket['dates'][ $date ] += $mins;
		if ( null === $bucket['first'] || $date < $bucket['first'] ) {
			$bucket['first'] = $date;
		}
		if ( null === $bucket['last'] || $date > $bucket['last'] ) {
			$bucket['last'] = $date;
		}
	}

	/**
	 * Turn a bucket accumulator into the final bar shape.
	 *
	 * @param string $key            Bucket key.
	 * @param string $label          Display label.
	 * @param bool   $is_untagged    Whether this is the untagged bucket.
	 * @param array  $b              Accumulator.
	 * @param int    $grouping_total Denominator for pct (entries counted once).
	 * @return array
	 */
	private static function finalize_bucket( $key, $label, $is_untagged, $b, $grouping_total ) {
		$span_days = 0;
		if ( $b['first'] && $b['last'] ) {
			$first     = strtotime( $b['first'] );
			$last      = strtotime( $b['last'] );
			$span_days = (int) floor( ( $last - $first ) / DAY_IN_SECONDS ) + 1;
		}

		$per_day = $b['dates'];
		ksort( $per_day );

		return array(
			'key'         => $key,
			'label'       => $label,
			'is_untagged' => (bool) $is_untagged,
			'minutes'     => (int) $b['minutes'],
			'pct'         => $grouping_total > 0 ? ( $b['minutes'] / $grouping_total ) : 0.0,
			'first_date'  => $b['first'],
			'last_date'   => $b['last'],
			'span_days'   => $span_days,
			'worked_days' => count( $b['dates'] ),
			'segments'    => self::compute_segments( $per_day ),
		);
	}

	/**
	 * Split a bucket's worked days into solid segments for the timeline.
	 *
	 * A new segment starts whenever the gap to the next worked day is >= the
	 * threshold; the space between segments is rendered as a dashed connector.
	 *
	 * @param array $per_day Sorted map of date (Y-m-d) => minutes.
	 * @return array List of { start, end, minutes, worked_days }.
	 */
	private static function compute_segments( $per_day ) {
		$dates = array_keys( $per_day );
		if ( empty( $dates ) ) {
			return array();
		}

		$segments = array();
		$start    = $dates[0];
		$prev     = $dates[0];
		$minutes  = $per_day[ $dates[0] ];
		$worked   = 1;
		$count    = count( $dates );

		for ( $i = 1; $i < $count; $i++ ) {
			$d   = $dates[ $i ];
			$gap = (int) floor( ( strtotime( $d ) - strtotime( $prev ) ) / DAY_IN_SECONDS );
			if ( $gap >= self::GAP_THRESHOLD_DAYS ) {
				$segments[] = array(
					'start'       => $start,
					'end'         => $prev,
					'minutes'     => $minutes,
					'worked_days' => $worked,
				);
				$start   = $d;
				$minutes = 0;
				$worked  = 0;
			}
			$minutes += $per_day[ $d ];
			$worked++;
			$prev = $d;
		}

		$segments[] = array(
			'start'       => $start,
			'end'         => $prev,
			'minutes'     => $minutes,
			'worked_days' => $worked,
		);

		return $segments;
	}

	/**
	 * Build the shared calendar axis (project first -> last entry) for the timeline.
	 *
	 * @param object|null $stats Aggregate stats (first_entry_date / last_entry_date).
	 * @return array|null { start, end, start_ts, end_ts, span_days, months[] } or null if no entries.
	 */
	private static function build_axis( $stats ) {
		$start = $stats->first_entry_date ?? '';
		$end   = $stats->last_entry_date ?? '';
		if ( ! $start || ! $end ) {
			return null;
		}

		$start_ts = strtotime( $start );
		$end_ts   = strtotime( $end );
		$span_secs = max( 1, $end_ts - $start_ts );

		// Month gridlines/labels from the start month through the end month.
		$months = array();
		$cursor = strtotime( gmdate( 'Y-m-01', $start_ts ) );
		$guard  = 0;
		while ( $cursor <= $end_ts && $guard < 240 ) {
			$pct      = ( $cursor - $start_ts ) / $span_secs * 100;
			$months[] = array(
				'label'    => date_i18n( 'M', $cursor ),
				'year'     => (int) gmdate( 'Y', $cursor ),
				'is_jan'   => '01' === gmdate( 'm', $cursor ),
				'pct'      => max( 0.0, min( 100.0, $pct ) ),
				'gridline' => ( $pct > 0.5 && $pct < 99.5 ),
			);
			$cursor = strtotime( '+1 month', $cursor );
			$guard++;
		}

		return array(
			'start'     => $start,
			'end'       => $end,
			'start_ts'  => $start_ts,
			'end_ts'    => $end_ts,
			'span_days' => (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1,
			'months'    => $months,
		);
	}

	/**
	 * Position (0-100%) of a date along the axis.
	 *
	 * @param array  $axis Axis from build_axis().
	 * @param string $date Date (Y-m-d).
	 * @return float Percent 0-100.
	 */
	public static function axis_pct( $axis, $date ) {
		if ( empty( $axis ) || empty( $axis['end_ts'] ) ) {
			return 0.0;
		}
		$span = max( 1, $axis['end_ts'] - $axis['start_ts'] );
		$pct  = ( strtotime( $date ) - $axis['start_ts'] ) / $span * 100;
		return max( 0.0, min( 100.0, $pct ) );
	}

	/**
	 * Budget-crossing line for the timeline overlay.
	 *
	 * The date where cumulative hours first reached the project's budget. This
	 * is a fact about the project, not the active group-by — position is summed
	 * across all entries in date order regardless of grouping, so the template
	 * lands it once via axis_pct() and the line holds still as lanes regroup.
	 *
	 * Gated to fixed-budget projects that actually went over: hourly/internal
	 * have no budget concept, and a project finishing under budget never crosses.
	 * Retainers use the monthly, in-period threshold marker in the Reports entry
	 * stream instead (pltt_compute_overage_threshold) — different surface, resets
	 * each period — so they're excluded here.
	 *
	 * @param object      $project Project row.
	 * @param object|null $stats   Aggregate stats.
	 * @param array       $entries Project entries, oldest-first.
	 * @param float       $rate    Resolved hourly rate.
	 * @return array|null { date, overage_minutes } or null when no line applies.
	 */
	private static function build_budget_line( $project, $stats, $entries, $rate ) {
		if ( 'fixed' !== pltt_get_billing_type( $project ) ) {
			return null;
		}

		// Budgeted minutes: explicit hours, else fee ÷ rate (canonical cascade).
		$budgeted_minutes = pltt_budgeted_minutes( $project, $rate );
		if ( $budgeted_minutes <= 0 ) {
			return null;
		}

		$total_minutes = isset( $stats->total_minutes ) ? (int) $stats->total_minutes : 0;
		if ( $total_minutes <= $budgeted_minutes ) {
			return null; // Came in at or under budget — no crossing.
		}

		// First date where the running cumulative reaches the budget.
		$cumulative = 0;
		$cross_date = null;
		foreach ( $entries as $e ) {
			$cumulative += (int) $e->duration_minutes;
			if ( $cumulative >= $budgeted_minutes ) {
				$cross_date = $e->entry_date;
				break;
			}
		}
		if ( null === $cross_date ) {
			return null;
		}

		return array(
			'date'            => $cross_date,
			'overage_minutes' => $total_minutes - $budgeted_minutes,
		);
	}

	/**
	 * Whether a group name reads like a project-phase group.
	 *
	 * @param string $group_name Group name.
	 * @return bool
	 */
	private static function looks_like_phase( $group_name ) {
		return (bool) preg_match( '/phase/i', $group_name );
	}

	/**
	 * Label for the "no tag in this group" bucket.
	 *
	 * @param string $key          Grouping key.
	 * @param bool   $is_phase     Phase-like grouping.
	 * @param bool   $is_ungrouped Ungrouped grouping.
	 * @return string
	 */
	private static function untagged_label( $key, $is_phase, $is_ungrouped ) {
		if ( $is_phase ) {
			return __( 'Unphased', 'plain-language-time-tracker' );
		}
		if ( preg_match( '/flag/i', (string) $key ) ) {
			return __( 'No flag', 'plain-language-time-tracker' );
		}
		if ( $is_ungrouped ) {
			return __( 'Untagged', 'plain-language-time-tracker' );
		}
		/* translators: %s: tag group name. */
		return sprintf( __( 'No %s tag', 'plain-language-time-tracker' ), strtolower( $key ) );
	}
}

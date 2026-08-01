<?php
/**
 * Billing engine — the read model that turns a project into its
 * ready-to-invoice scope(s).
 *
 * TWO reconciliation mechanics, not one. They ask the same question in different
 * shapes, but since 1.9.53 they agree on the answer: a committed record is
 * immutable, and nothing that happens afterwards can reopen it.
 *
 *   hourly  — set difference against the frozen coverage snapshot. Outstanding =
 *             billable + verified entries in range whose IDs aren't in
 *             billing_record_entries. No dollar arithmetic, so a committed record
 *             is immutable: a late-logged entry inside an already-billed window
 *             stays Unbilled rather than being swept in, and a later rate change
 *             can't re-value it (entries carry their own rate snapshot).
 *
 *   retainer — dollar remainder against a FROZEN basis:
 *                  unbilled(period) = basis − Σ billed − Σ absorbed
 *             where basis is the live calculation only while the period has no
 *             records, and Σ calculated_amount once it does
 *             (PLTT_Billing_Records::reconciliation_basis). Because absorbed is
 *             written as (calculated − billed), the two sum back to calculated,
 *             so a billed period settles to exactly 0.00 and stays there.
 *
 * This closes billing-state-review-2026-07-25.md §3.1, where retainer reconciled
 * live and hourly reconciled frozen: a rate change or a back-filled entry used to
 * re-value a closed period and reopen it as though it had never been invoiced.
 * The record always stored calculated_amount; the read path just ignored it.
 *
 * CONSEQUENCE, chosen deliberately (2026-08-01): there is no supplemental record.
 * A retainer period with any record is closed, so a second commit on it returns
 * 'nothing_to_bill'. If a closed period genuinely changed, DELETE the record and
 * re-commit — the coverage rows drop with it and the period returns to open.
 *
 * Retainer `overage_amount` in get_retainer_summary() is left on the LIVE figure
 * on purpose: it describes how much overage happened, not how much is owed.
 *
 * Retainer `calculated` is derived from the CLEAN `overage_minutes` field of
 * pltt_compute_overage_threshold() (used − allocation, pure period arithmetic)
 * × rate — never from its `overage_amount` field, which has a documented
 * straddle-boundary defect (helpers.php) and no consumers.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes ready-to-invoice scopes and exposes billing history.
 */
class PLTT_Billing {

	/** Dollar epsilon below which a remainder is treated as nothing outstanding. */
	const EPSILON = 0.005;

	/**
	 * All outstanding scopes for a project — the project page's "ready to invoice"
	 * prompts. Lightweight: no manifest entries (see get_scope() for those).
	 *
	 * @param object $project      Project row.
	 * @param bool   $with_entries Attach each scope's manifest entries (for the
	 *                             invoicing queue's inline expansion / descriptions).
	 * @return array<int, array> Scope descriptors with unbilled > 0.
	 */
	public static function get_ready_to_invoice( $project, $with_entries = false, $date_from = null, $date_to = null ) {
		$type = pltt_get_billing_type( $project );

		if ( 'hourly' === $type ) {
			$scope = self::build_hourly_scope( $project, $with_entries, $date_from, $date_to );
			return $scope ? array( $scope ) : array();
		}

		if ( 'recurring' === $type ) {
			return self::build_retainer_scopes( $project, $with_entries, null, $date_from, $date_to );
		}

		// Fixed / internal produce no record from time.
		return array();
	}

	/**
	 * Resolve a single outstanding scope for the billing surface / commit handler,
	 * WITH its manifest entries attached. Returns null if nothing is outstanding
	 * for that scope (e.g. already billed, or never over).
	 *
	 * @param object      $project      Project row.
	 * @param string      $billing_type 'hourly' | 'retainer_overage'.
	 * @param string|null $period_start Retainer month first day ('Y-m-d'); null for hourly.
	 * @return array|null
	 */
	public static function get_scope( $project, $billing_type, $period_start = null, $date_from = null, $date_to = null ) {
		if ( 'hourly' === $billing_type ) {
			return self::build_hourly_scope( $project, true, $date_from, $date_to );
		}

		if ( 'retainer_overage' === $billing_type && $period_start ) {
			$scopes = self::build_retainer_scopes( $project, true, $period_start );
			return $scopes ? $scopes[0] : null;
		}

		return null;
	}

	/**
	 * Read-only billing history for a project (full ledger, not period-scoped).
	 *
	 * @param int $project_id Project ID.
	 * @return array<int, object>
	 */
	public static function get_for_project_history( $project_id ) {
		return PLTT_Billing_Records::get_for_project( $project_id );
	}

	/**
	 * The set of entry IDs "covered" by this project's committed billing records.
	 *
	 * Read straight from the frozen coverage snapshot (billing_record_entries),
	 * written once when each record was committed — NOT recomputed from live date
	 * or threshold math. This is what keeps history immutable: a late-logged entry
	 * inside an already-billed window stays Unbilled instead of being swept in.
	 *
	 * "Covered" means the entry sits inside a record's snapshot — billed OR
	 * absorbed, never distinguishing the two (that split is record-level). Used to
	 * tint covered entries on the Reports detailed view.
	 *
	 * @param int $project_id Project ID.
	 * @return int[] De-duplicated covered entry IDs.
	 */
	public static function get_covered_entry_ids( $project_id ) {
		return array_keys( PLTT_Billing_Record_Entries::get_covered_for_project( (int) $project_id ) );
	}

	/**
	 * Per-entry coverage labels for display: entry_id => "Invoiced · record #N · period".
	 *
	 * Drives the covered-row marker's tooltip on the Reports detailed view — the
	 * record pointer the snapshot makes possible. Read from the frozen snapshot;
	 * each entry maps to the record that captured it.
	 *
	 * @param int $project_id Project ID.
	 * @return array<int,string> entry_id => human label.
	 */
	public static function get_covered_entry_meta( $project_id ) {
		$project_id = (int) $project_id;
		$map        = PLTT_Billing_Record_Entries::get_covered_for_project( $project_id );
		if ( empty( $map ) ) {
			return array();
		}

		$labels = array();
		foreach ( PLTT_Billing_Records::get_for_project( $project_id ) as $rec ) {
			$period = pltt_format_billing_period( $rec );
			/* translators: 1: billing record id, 2: billing period label */
			$labels[ (int) $rec->id ] = trim( sprintf( __( 'Invoiced · record #%1$d · %2$s', 'plain-language-time-tracker' ), (int) $rec->id, $period ) );
		}

		$meta = array();
		foreach ( $map as $entry_id => $record_id ) {
			$meta[ (int) $entry_id ] = $labels[ (int) $record_id ] ?? __( 'Invoiced', 'plain-language-time-tracker' );
		}
		return $meta;
	}

	/**
	 * Earliest entry date for a project ('Y-m-d'), or '' if it has none. Used as
	 * the retainer scan floor when no range is supplied (queue/all-time).
	 *
	 * @param object $project Project row.
	 * @return string
	 */
	private static function earliest_entry_date( $project ) {
		$rows = PLTT_Entries::get_all(
			array(
				'project_id' => (int) $project->id,
				'orderby'    => 'entry_date',
				'order'      => 'ASC',
				'limit'      => 1,
				'fields'     => array( 'entry_date' ),
			)
		);
		return ! empty( $rows ) ? (string) $rows[0]->entry_date : '';
	}

	/**
	 * Commit one billing record — the single write path shared by the billing
	 * surface form handler and the invoicing-page AJAX handler.
	 *
	 * The scope is ALWAYS recomputed server-side, but the posted amount is taken as
	 * given (floored at zero): it may be lower than the calculation (absorption —
	 * trimming to zero fully absorbs the scope) or higher (a rounded-up invoice,
	 * which records a negative absorbed). The calculation informs the default; it
	 * does not constrain what you're allowed to say you invoiced.
	 *
	 * What "the scope" means depends on the type (see the class header): hourly is
	 * the uncovered entries in range, so a record simply claims them. Retainer is
	 * the period's outstanding dollar remainder — gross minus what prior records
	 * already billed/absorbed — which is what lets a second, supplemental record
	 * on the same period settle the rest.
	 *
	 * @param array $args {
	 *     @type int    $project_id    Required.
	 *     @type string $billing_type  `hourly` | `retainer_overage`.
	 *     @type string $period_start  Retainer month first day, or '' / null (hourly).
	 *     @type float  $billed_amount Posted amount; only ever trims the bill down (absorption).
	 *     @type int[]  $excluded_entry_ids Hourly only — entries to drop from the record (stay open).
	 *     @type string $description   Invoice line text.
	 *     @type string $marked_at     Optional 'Y-m-d' — the date the invoice went out.
	 *                                 Defaults to today. Set it when back-filling so
	 *                                 the record lands in the month it was billed.
	 * }
	 * @return int|WP_Error Record id, or WP_Error (codes: invalid_project, nothing_to_bill, db_insert_failed).
	 */
	public static function commit( $args ) {
		$project_id = isset( $args['project_id'] ) ? (int) $args['project_id'] : 0;
		if ( $project_id <= 0 ) {
			return new WP_Error( 'invalid_project', __( 'That project could not be found.', 'plain-language-time-tracker' ) );
		}

		$project = PLTT_Projects::get( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'invalid_project', __( 'That project could not be found.', 'plain-language-time-tracker' ) );
		}

		$billing_type = isset( $args['billing_type'] ) ? (string) $args['billing_type'] : '';
		$period_start = ( 'retainer_overage' === $billing_type && ! empty( $args['period_start'] ) )
			? $args['period_start']
			: null;

		// Hourly scope is recomputed over the same range the card billed, so the
		// record stores exactly what it covered.
		$date_from = ! empty( $args['date_from'] ) ? $args['date_from'] : null;
		$date_to   = ! empty( $args['date_to'] ) ? $args['date_to'] : null;

		$scope = self::get_scope( $project, $billing_type, $period_start, $date_from, $date_to );
		if ( ! $scope ) {
			return new WP_Error( 'nothing_to_bill', __( 'Nothing outstanding to bill for that scope.', 'plain-language-time-tracker' ) );
		}

		// Per-entry exclusion (hourly only): the user can drop entries from the
		// record; they stay uncovered → Unbilled → billable next time. Excluding
		// trims BOTH the frozen coverage AND the calculated amount (an excluded
		// entry isn't charged). Retainer bills the period as a whole — no exclusion.
		// Two ways to pick entries (hourly only), both intersected with the
		// recomputed scope so nothing ineligible or already-covered can slip in:
		//   - included_entry_ids: bill EXACTLY these (the inline surface — the boxes
		//     the user left checked on the visible rows).
		//   - excluded_entry_ids: bill the whole scope MINUS these (the queue modal).
		// Retainer bills the period as a whole — neither applies.
		$included = null;
		$excluded = array();
		if ( 'hourly' === $scope['billing_type'] ) {
			if ( isset( $args['included_entry_ids'] ) && array() !== (array) $args['included_entry_ids'] && '' !== $args['included_entry_ids'] ) {
				$included = array_flip( array_map( 'intval', (array) $args['included_entry_ids'] ) );
			} elseif ( ! empty( $args['excluded_entry_ids'] ) ) {
				$excluded = array_flip( array_map( 'intval', (array) $args['excluded_entry_ids'] ) );
			}
		}

		// The entries this record captures, and what they're worth.
		$covered_ids    = array();
		$included_total = 0.0;
		foreach ( $scope['entries'] as $entry ) {
			$eid = (int) $entry->id;
			if ( null !== $included ) {
				if ( ! isset( $included[ $eid ] ) ) {
					continue;
				}
			} elseif ( isset( $excluded[ $eid ] ) ) {
				continue;
			}
			$covered_ids[]   = $eid;
			$included_total += pltt_resolve_entry_amount( $entry );
		}

		// An explicit include-set that matched none of the eligible entries (stale
		// ids) would otherwise commit a $0 record covering nothing.
		if ( null !== $included && empty( $covered_ids ) ) {
			return new WP_Error( 'nothing_to_bill', __( 'None of the selected entries are still billable.', 'plain-language-time-tracker' ) );
		}

		// Calculated = the included entries' worth. With the full scope (no include
		// list, nothing excluded) this is the scope remainder; recomputing keeps it
		// honest when entries are dropped.
		$record_calculated = ( null === $included && empty( $excluded ) ) ? (float) $scope['unbilled'] : round( $included_total, 2 );

		// The posted amount is what was actually invoiced. It may sit below the
		// calculation (absorption — trimming to zero fully absorbs the scope) or
		// above it (a rounded-up invoice). Floor at zero, no ceiling.
		$billed = max( 0.0, (float) ( $args['billed_amount'] ?? 0 ) );

		// Record + coverage snapshot are one atomic unit: a record that failed to
		// freeze its covered entries would leave them looking Unbilled and open to
		// double-billing.
		PLTT_Database::begin_transaction();

		$record_id = PLTT_Billing_Records::create(
			array(
				'project_id'         => $project_id,
				'billing_type'       => $scope['billing_type'],
				'period_start'       => $scope['period_start'],
				'period_end'         => $scope['period_end'],
				'rate'               => $scope['rate'],
				'calculated_amount'  => $record_calculated,
				'billed_amount'      => $billed,
				'billed_minutes'     => $scope['minutes'],
				'allocation_minutes' => $scope['allocation_minutes'],
				'description'        => isset( $args['description'] ) ? (string) $args['description'] : '',
				'marked_at'          => isset( $args['marked_at'] ) ? (string) $args['marked_at'] : '',
			)
		);

		if ( is_wp_error( $record_id ) ) {
			PLTT_Database::rollback_transaction();
			return $record_id;
		}

		if ( ! PLTT_Billing_Record_Entries::insert_many( $record_id, $covered_ids ) ) {
			PLTT_Database::rollback_transaction();
			return new WP_Error( 'db_insert_failed', __( 'Could not save the billing record.', 'plain-language-time-tracker' ) );
		}

		PLTT_Database::commit_transaction();
		return $record_id;
	}

	/**
	 * The cross-project invoiced ledger — committed billing records newest first,
	 * enriched with client + project names, filtered + paginated for the Billed
	 * history view. Totals are over the FULL filtered set, not just the page.
	 *
	 * @param array $args {
	 *     @type string $date_from    Lower bound on marked_at (Y-m-d) or ''.
	 *     @type string $date_to      Upper bound on marked_at (Y-m-d) or ''.
	 *     @type int    $client_id    Restrict to one client (0 = all).
	 *     @type string $billing_type One of PLTT_Billing_Records::TYPES, or '' for any.
	 *     @type int    $paged        1-based page number.
	 *     @type int    $per_page     Page size.
	 * }
	 * @return array{
	 *     rows: array<int, array{record: object, client_name: string, project_name: string}>,
	 *     total_billed: float,
	 *     total_absorbed: float,
	 *     count: int,
	 *     paged: int,
	 *     per_page: int,
	 *     total_pages: int,
	 * }
	 */
	public static function get_invoiced_log( $args = array() ) {
		$defaults = array(
			'date_from'    => '',
			'date_to'      => '',
			'client_id'    => 0,
			'billing_type' => '',
			'paged'        => 1,
			'per_page'     => 25,
		);
		$args = array_merge( $defaults, $args );

		$paged    = max( 1, (int) $args['paged'] );
		$per_page = max( 1, (int) $args['per_page'] );

		// A client filter resolves to that client's project IDs — records carry only
		// project_id, not client_id. null = no client restriction.
		$project_ids = null;
		if ( (int) $args['client_id'] > 0 ) {
			$project_ids = array();
			foreach ( PLTT_Projects::get_by_client( (int) $args['client_id'], false ) as $p ) {
				$project_ids[] = (int) $p->id;
			}
		}

		$q = PLTT_Billing_Records::query(
			array(
				'date_from'    => (string) $args['date_from'],
				'date_to'      => (string) $args['date_to'],
				'billing_type' => (string) $args['billing_type'],
				'project_ids'  => $project_ids,
				'limit'        => $per_page,
				'offset'       => ( $paged - 1 ) * $per_page,
			)
		);

		$records = $q['rows'];

		$page_project_ids = array();
		foreach ( $records as $r ) {
			if ( ! empty( $r->project_id ) ) {
				$page_project_ids[] = (int) $r->project_id;
			}
		}
		$projects = PLTT_Projects::get_multiple( array_unique( $page_project_ids ) );

		$client_ids = array();
		foreach ( $projects as $p ) {
			if ( ! empty( $p->client_id ) ) {
				$client_ids[] = (int) $p->client_id;
			}
		}
		$clients = PLTT_Clients::get_multiple( array_unique( $client_ids ) );

		$rows = array();
		foreach ( $records as $r ) {
			$project = isset( $projects[ (int) $r->project_id ] ) ? $projects[ (int) $r->project_id ] : null;
			$client  = ( $project && isset( $clients[ (int) $project->client_id ] ) ) ? $clients[ (int) $project->client_id ] : null;

			$rows[] = array(
				'record'       => $r,
				'client_name'  => $client ? $client->name : '',
				'project_name' => $project ? $project->name : __( '(deleted project)', 'plain-language-time-tracker' ),
			);
		}

		return array(
			'rows'           => $rows,
			'total_billed'   => $q['billed'],
			'total_absorbed' => $q['absorbed'],
			'count'          => $q['total'],
			'paged'          => $paged,
			'per_page'       => $per_page,
			'total_pages'    => (int) max( 1, ceil( $q['total'] / $per_page ) ),
		);
	}

	/**
	 * Billed + absorbed totals over a marked_at window — backs the "Billed / Absorbed
	 * this month" cards on the Ready-to-bill view. No row fetch.
	 *
	 * @param string $date_from Lower bound (Y-m-d) or ''.
	 * @param string $date_to   Upper bound (Y-m-d) or ''.
	 * @return array{billed: float, absorbed: float, count: int}
	 */
	public static function get_billed_totals( $date_from = '', $date_to = '' ) {
		$q = PLTT_Billing_Records::query(
			array(
				'date_from' => (string) $date_from,
				'date_to'   => (string) $date_to,
				'limit'     => 0,
			)
		);
		return array(
			'billed'   => $q['billed'],
			'absorbed' => $q['absorbed'],
			'count'    => $q['total'],
		);
	}

	/**
	 * The cross-project invoicing queue: everything currently outstanding across
	 * all active projects, grouped by client for the Reports Invoicing view.
	 *
	 * Not period-filtered — it's "what can I bill right now." Hourly is pooled by
	 * unbilled status (all-time from the cutoff); retainer is per overage month.
	 * That's the same model the project page uses; this just aggregates it.
	 *
	 * @return array{
	 *     clients: array<int, array{client: ?object, scopes: array, total: float}>,
	 *     grand_total: float,
	 *     scope_count: int,
	 * } Clients ordered by name; clients with nothing outstanding are omitted.
	 */
	public static function get_invoicing_queue() {
		$projects = PLTT_Projects::get_all( array( 'status' => 'active' ) );

		$by_client   = array();
		$grand_total = 0.0;
		$scope_count = 0;

		foreach ( $projects as $project ) {
			$scopes = self::get_ready_to_invoice( $project, true );
			if ( empty( $scopes ) ) {
				continue;
			}

			$cid = (int) $project->client_id;
			if ( ! isset( $by_client[ $cid ] ) ) {
				$by_client[ $cid ] = array(
					'client' => PLTT_Clients::get( $cid ),
					'scopes' => array(),
					'total'  => 0.0,
				);
			}

			foreach ( $scopes as $scope ) {
				$by_client[ $cid ]['scopes'][] = $scope;
				$by_client[ $cid ]['total']   += (float) $scope['unbilled'];
				$grand_total                   += (float) $scope['unbilled'];
				$scope_count++;
			}
		}

		// Order clients by name (nulls last).
		uasort(
			$by_client,
			static function ( $a, $b ) {
				$an = $a['client'] ? strtolower( $a['client']->name ) : 'zzz';
				$bn = $b['client'] ? strtolower( $b['client']->name ) : 'zzz';
				return strcmp( $an, $bn );
			}
		);

		return array(
			'clients'     => array_values( $by_client ),
			'grand_total' => round( $grand_total, 2 ),
			'scope_count' => $scope_count,
		);
	}

	/**
	 * Build the single hourly scope: outstanding = Σ amount of verified, billable
	 * entries in [date_from, date_to] that aren't already covered by a record.
	 * Open-ended when a bound is null (the queue passes neither → all-time uncovered).
	 *
	 * @param object      $project      Project row.
	 * @param bool        $with_entries Attach manifest entries.
	 * @param string|null $date_from    Range start ('Y-m-d') or null.
	 * @param string|null $date_to      Range end ('Y-m-d') or null.
	 * @return array|null
	 */
	private static function build_hourly_scope( $project, $with_entries, $date_from = null, $date_to = null ) {
		$rate = pltt_resolve_billable_rate( (int) $project->client_id, (int) $project->id );

		// Coverage-based outstanding: billable, verified entries in the range that
		// aren't already covered by a committed record. No dollar-remainder rule —
		// coverage already excludes billed/absorbed work, so it can't bleed across
		// ranges the way `calculated − Σbilled` would.
		$args = array(
			'project_id' => (int) $project->id,
			'billable'   => 1,
			'verified'   => 1,
			'orderby'    => 'entry_date',
			'order'      => 'ASC',
		);
		if ( $date_from ) {
			$args['date_from'] = $date_from;
		}
		if ( $date_to ) {
			$args['date_to'] = $date_to;
		}
		$rows    = PLTT_Entries::get_all( $args );
		$covered = array_flip( self::get_covered_entry_ids( (int) $project->id ) );

		$entries    = array();
		$calculated = 0.0;
		foreach ( $rows as $row ) {
			if ( isset( $covered[ (int) $row->id ] ) ) {
				continue;
			}
			$calculated += pltt_resolve_entry_amount( $row );
			$entries[]   = $row;
		}
		$calculated = round( $calculated, 2 );

		if ( $calculated <= self::EPSILON ) {
			return null;
		}

		return array(
			'project'            => $project,
			'billing_type'      => 'hourly',
			'period_start'       => $date_from ? $date_from : null,
			'period_end'         => $date_to ? $date_to : current_time( 'Y-m-d' ),
			'period_label'       => __( 'Unbilled to date', 'plain-language-time-tracker' ),
			'rate'               => $rate,
			'calculated'         => $calculated,
			'billed'             => 0.0,
			'absorbed'           => 0.0,
			'unbilled'           => $calculated,
			'minutes'            => null,
			'allocation_minutes' => null,
			'entries'            => $with_entries ? $entries : array(),
		);
	}

	/**
	 * Whole-run summary of a retainer, period by period.
	 *
	 * The project report's lifetime view asks a different question from the
	 * billing surface: not "what can I bill right now" but "is this retainer
	 * healthy across its whole run" — so this counts EVERY period the project was
	 * active in, including the ones that stayed within allocation and the ones
	 * already billed. Walks the same period bounds build_retainer_scopes() does.
	 *
	 * @param object      $project   Project row.
	 * @param string|null $date_from Restrict to periods from this date on ('Y-m-d');
	 *                               null scans from the project's first entry.
	 * @param string|null $date_to   Restrict to periods up to this date ('Y-m-d'),
	 *                               clamped to today; null scans through today.
	 * @return array {
	 *     @type int    $periods            Allocation periods scanned.
	 *     @type int    $over_periods       How many of those went over allocation.
	 *     @type float  $overage_amount     Σ chargeable overage — INCLUDES a still-open
	 *                                      period, because the work happened.
	 *     @type float  $unbilled_amount    Σ overage not yet covered by a record,
	 *                                      CLOSED periods only — an open period isn't
	 *                                      waiting to be billed, it isn't finished.
	 *     @type int    $unbilled_periods   Closed periods with an unbilled remainder.
	 *     @type int    $open_periods       Periods in range that are still running.
	 *     @type string $open_period_label  The open period's name, e.g. "July 2026".
	 *     @type int    $allocation_minutes The per-period allocation.
	 * }
	 */
	public static function get_retainer_summary( $project, $date_from = null, $date_to = null ) {
		$out = array(
			'periods'            => 0,
			'over_periods'       => 0,
			'overage_amount'     => 0.0,
			'unbilled_amount'    => 0.0,
			'unbilled_periods'   => 0,
			'open_periods'       => 0,
			'open_period_label'  => '',
			'allocation_minutes' => (int) pltt_budgeted_minutes( $project ),
		);

		if ( 'recurring' !== pltt_get_billing_type( $project ) ) {
			return $out;
		}

		$rate  = pltt_resolve_billable_rate( (int) $project->client_id, (int) $project->id );
		$today = current_time( 'Y-m-d' );
		$tz    = wp_timezone();

		// Scan start: the range start when given, else the project's first entry.
		// Scan end: the range end clamped to today — a period that hasn't happened
		// yet has nothing to report. Same bounds build_retainer_scopes() uses.
		$ref = $date_from ? $date_from : self::earliest_entry_date( $project );
		if ( ! $ref ) {
			return $out;
		}
		$scan_end = ( $date_to && $date_to < $today ) ? $date_to : $today;

		$guard = 0;
		while ( $ref <= $scan_end && $guard < 500 ) {
			$guard++;

			list( $period_start, $period_end ) = pltt_get_allocation_period_bounds( $project, $ref );
			if ( ! $period_start || ! $period_end ) {
				break;
			}

			$out['periods']++;

			// A period still running can't be billed — billing it would mean either
			// a second record for the month or a partial one, which breaks the
			// one-record-per-period assumption the model rests on. It still counts
			// toward hours and overage (that work happened); it just can't count
			// toward what's waiting to be invoiced.
			$is_open = ( $period_end >= $today );
			if ( $is_open ) {
				$out['open_periods']++;
				$out['open_period_label'] = self::format_period_label( $period_start, $period_end, $project->recurring_period );
			}

			$threshold = pltt_compute_overage_threshold(
				$project,
				array(
					'date_from' => $period_start,
					'date_to'   => $period_end,
				)
			);

			if ( 'over' === $threshold['state'] ) {
				$calculated = round( pltt_billable_amount( (int) $threshold['overage_minutes'], $rate ), 2 );

				$out['over_periods']++;
				$out['overage_amount'] += $calculated;

				if ( ! $is_open ) {
					$sums = PLTT_Billing_Records::sum_billed( (int) $project->id, 'retainer_overage', $period_start );
					// Same basis rule as build_retainer_scopes(): a period with records
					// reconciles against what it was billed from, so it can't reopen.
					// overage_amount above stays live on purpose — it describes what
					// happened, not what is owed.
					$basis    = PLTT_Billing_Records::reconciliation_basis( $sums, $calculated );
					$unbilled = round( $basis - $sums['billed'] - $sums['absorbed'], 2 );
					if ( $unbilled > self::EPSILON ) {
						$out['unbilled_amount'] += $unbilled;
						$out['unbilled_periods']++;
					}
				}
			}

			try {
				$next = ( new DateTimeImmutable( $period_end, $tz ) )->modify( '+1 day' );
			} catch ( Exception $e ) {
				break;
			}
			$ref = $next->format( 'Y-m-d' );
		}

		$out['overage_amount']  = round( $out['overage_amount'], 2 );
		$out['unbilled_amount'] = round( $out['unbilled_amount'], 2 );

		return $out;
	}

	/**
	 * Build retainer-overage scopes, one per allocation period that is over
	 * allocation AND has an unbilled remainder. Scans the periods intersecting
	 * [date_from, date_to]; with no range it scans from the project's first entry
	 * through today (the rolling cutoff is gone).
	 *
	 * @param object      $project      Project row.
	 * @param bool        $with_entries Attach manifest entries (overage entries only).
	 * @param string|null $only_period  When set, build just the period whose start
	 *                                  equals this 'Y-m-d' (surface/commit path).
	 * @param string|null $date_from    Range start ('Y-m-d') or null (all-time).
	 * @param string|null $date_to      Range end ('Y-m-d') or null (through today).
	 * @return array<int, array>
	 */
	private static function build_retainer_scopes( $project, $with_entries, $only_period = null, $date_from = null, $date_to = null ) {
		$rate  = pltt_resolve_billable_rate( (int) $project->client_id, (int) $project->id );
		$today = current_time( 'Y-m-d' );
		$tz    = wp_timezone();

		$scopes = array();

		// Scan start: an explicit period (commit), else the filter range start, else
		// the project's first entry (queue/all-time — the rolling cutoff is gone).
		// Scan end: the range end clamped to today.
		if ( $only_period ) {
			$ref = $only_period;
		} elseif ( $date_from ) {
			$ref = $date_from;
		} else {
			$ref = self::earliest_entry_date( $project );
		}
		if ( ! $ref ) {
			return $scopes;
		}
		$scan_end = ( ! $only_period && $date_to && $date_to < $today ) ? $date_to : $today;

		// Step period by period using the project's natural allocation bounds.
		$guard = 0;
		while ( $ref <= $scan_end && $guard < 500 ) {
			$guard++;

			list( $period_start, $period_end ) = pltt_get_allocation_period_bounds( $project, $ref );
			if ( ! $period_start || ! $period_end ) {
				break; // Not a recurring project.
			}

			$threshold = pltt_compute_overage_threshold(
				$project,
				array(
					'date_from' => $period_start,
					'date_to'   => $period_end,
				)
			);

			if ( 'over' === $threshold['state'] ) {
				$overage_minutes = (int) $threshold['overage_minutes'];
				$calculated      = round( pltt_billable_amount( $overage_minutes, $rate ), 2 );
				$sums            = PLTT_Billing_Records::sum_billed( (int) $project->id, 'retainer_overage', $period_start );
				// Reconcile against the terms the period was billed under, not a live
				// recompute — once it has records it settles to zero and stays there.
				$basis           = PLTT_Billing_Records::reconciliation_basis( $sums, $calculated );
				$unbilled        = round( $basis - $sums['billed'] - $sums['absorbed'], 2 );

				if ( $unbilled > self::EPSILON ) {
					$entries = array();
					if ( $with_entries && ! empty( $threshold['overage_entry_ids'] ) ) {
						$entries = self::load_entries_by_ids(
							(int) $project->id,
							$period_start,
							$period_end,
							array_map( 'intval', $threshold['overage_entry_ids'] )
						);
					}

					$scopes[] = array(
						'project'            => $project,
						'billing_type'      => 'retainer_overage',
						'period_start'       => $period_start,
						'period_end'         => $period_end,
						'period_label'       => self::format_period_label( $period_start, $period_end, $project->recurring_period ),
						'rate'               => $rate,
						'calculated'         => $calculated,
						'billed'             => $sums['billed'],
						'absorbed'           => $sums['absorbed'],
						'unbilled'           => $unbilled,
						'minutes'            => $overage_minutes,
						'allocation_minutes' => (int) $threshold['allocation_minutes'],
						'entries'            => $entries,
					);
				}
			}

			if ( $only_period ) {
				break;
			}

			// Advance to the day after this period ends.
			try {
				$next = ( new DateTimeImmutable( $period_end, $tz ) )->modify( '+1 day' );
			} catch ( Exception $e ) {
				break;
			}
			$ref = $next->format( 'Y-m-d' );
		}

		return $scopes;
	}

	/**
	 * Load a period's entries and keep only the overage ones (the manifest).
	 *
	 * @param int    $project_id   Project ID.
	 * @param string $period_start 'Y-m-d'.
	 * @param string $period_end   'Y-m-d'.
	 * @param int[]  $keep_ids     Entry IDs to keep.
	 * @return array<int, object>
	 */
	private static function load_entries_by_ids( $project_id, $period_start, $period_end, $keep_ids ) {
		$lookup  = array_flip( $keep_ids );
		$entries = PLTT_Entries::get_all(
			array(
				'project_id' => $project_id,
				'date_from'  => $period_start,
				'date_to'    => $period_end,
				'orderby'    => 'entry_date',
				'order'      => 'ASC',
			)
		);

		return array_values(
			array_filter(
				$entries,
				static function ( $e ) use ( $lookup ) {
					return isset( $lookup[ (int) $e->id ] );
				}
			)
		);
	}

	/**
	 * Human label for a billing period. Full calendar months read "June 2026";
	 * anything else reads "Jun 2 – Jun 8, 2026".
	 *
	 * @param string $period_start     'Y-m-d'.
	 * @param string $period_end       'Y-m-d'.
	 * @param string $recurring_period weekly|monthly|quarterly|yearly.
	 * @return string
	 */
	public static function format_period_label( $period_start, $period_end, $recurring_period ) {
		$start = strtotime( $period_start );
		$end   = strtotime( $period_end );

		if ( 'monthly' === $recurring_period
			&& gmdate( 'd', $start ) === '01'
			&& gmdate( 'Y-m-d', $end ) === gmdate( 'Y-m-t', $start ) ) {
			return gmdate( 'F Y', $start );
		}

		if ( 'yearly' === $recurring_period ) {
			return gmdate( 'Y', $start );
		}

		return gmdate( 'M j', $start ) . ' – ' . gmdate( 'M j, Y', $end );
	}
}

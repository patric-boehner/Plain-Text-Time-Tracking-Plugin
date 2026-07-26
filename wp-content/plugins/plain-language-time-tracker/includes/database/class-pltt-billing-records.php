<?php
/**
 * Billing record CRUD operations.
 *
 * A billing record is one durable per-scope summary written at commit time
 * (verify -> adjust -> commit). Entries carry no link back to it; what a record
 * covered lives in billing_record_entries (the frozen coverage snapshot).
 *
 * A fully-absorbed record is just billed_amount = 0 (absorbed = calculated),
 * reached by trimming the amount to zero; there is no status column. The posted
 * amount can only ever LOWER the bill — billed is clamped to calculated here and
 * in PLTT_Billing::commit(), so absorbed is never negative and an invoice larger
 * than the calculation cannot be recorded.
 *
 * How these rows are read back differs by type — hourly reconciles on coverage,
 * retainer on a live-recomputed dollar remainder. See the PLTT_Billing header for
 * both mechanics and the drift consequence of the retainer one.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles billing record database operations.
 */
class PLTT_Billing_Records {

	/**
	 * Allowed billing_type values (snapshot of how the record was billed).
	 */
	const TYPES = array( 'hourly', 'retainer_overage' );

	/**
	 * Get a single record by ID.
	 *
	 * @param int $id Record ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'billing_records' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Insert a billing record.
	 *
	 * Caller is responsible for computing calculated/billed/absorbed correctly;
	 * this layer validates the type allowlist and clamps the money fields so a
	 * bad post can never write absorbed < 0 or billed > calculated.
	 *
	 * @param array $data {
	 *     @type int    $project_id         Required.
	 *     @type string $billing_type       Required; one of self::TYPES.
	 *     @type string $period_start       Optional 'Y-m-d' or null (hourly).
	 *     @type string $period_end         Optional 'Y-m-d' or null.
	 *     @type float  $rate               Optional snapshot rate.
	 *     @type float  $calculated_amount  Required.
	 *     @type float  $billed_amount      Required (0 = fully absorbed).
	 *     @type int    $billed_minutes     Optional.
	 *     @type int    $allocation_minutes Optional (retainer only).
	 *     @type string $description        Optional.
	 *     @type string $marked_at          Optional 'Y-m-d' — the date the invoice
	 *                                      actually went out. Defaults to now.
	 * }
	 * @return int|WP_Error Inserted ID, or WP_Error on validation/DB failure.
	 */
	public static function create( $data ) {
		global $wpdb;

		$project_id = isset( $data['project_id'] ) ? (int) $data['project_id'] : 0;
		if ( $project_id <= 0 ) {
			return new WP_Error( 'invalid_project', __( 'A project is required.', 'plain-language-time-tracker' ) );
		}

		$billing_type = isset( $data['billing_type'] ) ? (string) $data['billing_type'] : '';
		if ( ! in_array( $billing_type, self::TYPES, true ) ) {
			return new WP_Error( 'invalid_billing_type', __( 'Unknown billing type.', 'plain-language-time-tracker' ) );
		}

		$calculated = isset( $data['calculated_amount'] ) ? round( (float) $data['calculated_amount'], 2 ) : 0.0;
		$billed     = isset( $data['billed_amount'] ) ? round( (float) $data['billed_amount'], 2 ) : 0.0;

		// Clamp: never negative, never bill more than the honest figure.
		$calculated = max( 0.0, $calculated );
		$billed     = min( max( 0.0, $billed ), $calculated );
		$absorbed   = round( $calculated - $billed, 2 );

		// When the invoice actually went out. Defaults to now; a supplied date lets
		// a back-filled record land in the month it was really billed, so the
		// marked_at-windowed figures (Billed/Absorbed this month, the ledger's date
		// column and its ordering) report the truth instead of the data-entry day.
		// Today's date keeps the full timestamp so same-day records still order by
		// time; a past date gets midnight, with the id DESC tiebreak covering it.
		$marked_at = current_time( 'mysql' );
		if ( ! empty( $data['marked_at'] ) ) {
			$marked_date = pltt_sanitize_date_strict( $data['marked_at'] );
			if ( '' !== $marked_date && $marked_date !== current_time( 'Y-m-d' ) ) {
				$marked_at = $marked_date . ' 00:00:00';
			}
		}

		$insert = array(
			'project_id'        => $project_id,
			'billing_type'      => $billing_type,
			'rate'              => isset( $data['rate'] ) ? round( (float) $data['rate'], 2 ) : null,
			'calculated_amount' => $calculated,
			'billed_amount'     => $billed,
			'absorbed_amount'   => $absorbed,
			'description'       => isset( $data['description'] ) ? (string) $data['description'] : '',
			'marked_at'         => $marked_at,
		);
		$formats = array( '%d', '%s', '%f', '%f', '%f', '%f', '%s', '%s' );

		// Nullable columns: only include when set, so wpdb writes a real NULL via
		// the schema default rather than coercing null through a %d/%s format.
		if ( ! empty( $data['period_start'] ) ) {
			$insert['period_start'] = $data['period_start'];
			$formats[]              = '%s';
		}
		if ( ! empty( $data['period_end'] ) ) {
			$insert['period_end'] = $data['period_end'];
			$formats[]            = '%s';
		}
		if ( isset( $data['billed_minutes'] ) && null !== $data['billed_minutes'] ) {
			$insert['billed_minutes'] = (int) $data['billed_minutes'];
			$formats[]                = '%d';
		}
		if ( isset( $data['allocation_minutes'] ) && null !== $data['allocation_minutes'] ) {
			$insert['allocation_minutes'] = (int) $data['allocation_minutes'];
			$formats[]                    = '%d';
		}

		$table = PLTT_Database::get_table_name( 'billing_records' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $table, $insert, $formats );

		if ( false === $result ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not save the billing record.', 'plain-language-time-tracker' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Every billing record, newest first — the cross-project invoiced ledger.
	 *
	 * @return array<int, object>
	 */
	public static function get_all() {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'billing_records' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY marked_at DESC, id DESC" );
	}

	/**
	 * Filtered, paginated slice of the cross-project ledger, plus aggregate totals
	 * over the FULL filtered set (not just the page) — powers the Billed history
	 * view's table + summary cards.
	 *
	 * @param array $args {
	 *     @type string     $date_from    Inclusive lower bound on marked_at (Y-m-d) or ''.
	 *     @type string     $date_to      Inclusive upper bound on marked_at (Y-m-d) or ''.
	 *     @type string     $billing_type One of self::TYPES, or '' for any.
	 *     @type int[]|null $project_ids  Restrict to these project IDs (client filter);
	 *                                    null = no restriction, [] = match nothing.
	 *     @type int        $limit        Page size; 0 = totals only, no rows fetched.
	 *     @type int        $offset       Row offset for pagination.
	 * }
	 * @return array{rows: array<int, object>, total: int, billed: float, absorbed: float}
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'billing_records' );

		$defaults = array(
			'date_from'    => '',
			'date_to'      => '',
			'billing_type' => '',
			'project_ids'  => null,
			'limit'        => 0,
			'offset'       => 0,
		);
		$args = array_merge( $defaults, $args );

		$where   = array( '1=1' );
		$prepare = array();

		if ( '' !== $args['date_from'] ) {
			$where[]   = 'marked_at >= %s';
			$prepare[] = $args['date_from'] . ' 00:00:00';
		}
		if ( '' !== $args['date_to'] ) {
			$where[]   = 'marked_at <= %s';
			$prepare[] = $args['date_to'] . ' 23:59:59';
		}
		if ( '' !== $args['billing_type'] ) {
			$where[]   = 'billing_type = %s';
			$prepare[] = $args['billing_type'];
		}
		if ( is_array( $args['project_ids'] ) ) {
			if ( empty( $args['project_ids'] ) ) {
				// A client filter that resolves to no projects matches nothing.
				return array( 'rows' => array(), 'total' => 0, 'billed' => 0.0, 'absorbed' => 0.0 );
			}
			$ids        = array_map( 'intval', $args['project_ids'] );
			$where[]    = 'project_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$prepare    = array_merge( $prepare, $ids );
		}

		$where_sql = implode( ' AND ', $where );

		// Aggregate over the full filtered set (for the summary cards + pagination).
		$agg_sql = "SELECT COUNT(*) AS total, COALESCE(SUM(billed_amount),0) AS billed, COALESCE(SUM(absorbed_amount),0) AS absorbed FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$agg = empty( $prepare )
			? $wpdb->get_row( $agg_sql )
			: $wpdb->get_row( $wpdb->prepare( $agg_sql, $prepare ) );

		$result = array(
			'rows'     => array(),
			'total'    => $agg ? (int) $agg->total : 0,
			'billed'   => $agg ? round( (float) $agg->billed, 2 ) : 0.0,
			'absorbed' => $agg ? round( (float) $agg->absorbed, 2 ) : 0.0,
		);

		if ( (int) $args['limit'] > 0 && $result['total'] > 0 ) {
			$rows_sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY marked_at DESC, id DESC LIMIT %d OFFSET %d";
			$rows_prepare   = array_merge( $prepare, array( (int) $args['limit'], (int) $args['offset'] ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result['rows'] = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_prepare ) );
		}

		return $result;
	}

	/**
	 * All records for a project, newest first — the read-only billing history.
	 *
	 * @param int $project_id Project ID.
	 * @return array<int, object>
	 */
	public static function get_for_project( $project_id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'billing_records' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE project_id = %d ORDER BY marked_at DESC, id DESC",
				(int) $project_id
			)
		);
	}

	/**
	 * Sum what existing records already account for over a scope, so the engine
	 * can subtract it from calculated(scope) to get the unbilled remainder.
	 *
	 * Scope = project + billing_type, optionally narrowed to a retainer month by
	 * period_start (an exact match on the month's first day — how retainer records
	 * store it). Hourly records carry no period_start, so they are summed in full.
	 *
	 * @param int         $project_id   Project ID.
	 * @param string      $billing_type One of self::TYPES.
	 * @param string|null $period_start Optional 'Y-m-d' to scope to one retainer month.
	 * @return array{billed: float, absorbed: float}
	 */
	public static function sum_billed( $project_id, $billing_type, $period_start = null ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'billing_records' );

		$where   = array( 'project_id = %d', 'billing_type = %s' );
		$prepare = array( (int) $project_id, (string) $billing_type );

		if ( ! empty( $period_start ) ) {
			$where[]   = 'period_start = %s';
			$prepare[] = $period_start;
		}

		$sql = "SELECT COALESCE(SUM(billed_amount), 0) AS billed,
			COALESCE(SUM(absorbed_amount), 0) AS absorbed
			FROM {$table} WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $prepare ) );

		return array(
			'billed'   => $row ? (float) $row->billed : 0.0,
			'absorbed' => $row ? (float) $row->absorbed : 0.0,
		);
	}
}

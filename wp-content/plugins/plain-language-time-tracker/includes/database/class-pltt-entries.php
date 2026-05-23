<?php
/**
 * Time Entry CRUD operations.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles time entry database operations.
 */
class PLTT_Entries {

	/**
	 * Get a single entry by ID.
	 *
	 * @param int $id Entry ID.
	 * @return object|null Entry object or null.
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'time_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	/**
	 * Get entries with filters.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of entry objects.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'time_entries' );

		$defaults = array(
			'date'           => '',
			'date_from'      => '',
			'date_to'        => '',
			'client_id'      => 0,
			'project_id'     => 0,
			'verified'       => null,
			'tag'            => '',
			'billable'       => null,
			'billed'         => null,
			'client_negate'  => 0,
			'project_negate' => 0,
			'tag_negate'     => 0,
			'orderby'        => 'entry_date',
			'order'          => 'DESC',
			'limit'          => 0,
			'offset'         => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where   = array();
		$prepare = array();

		// Single date filter.
		if ( ! empty( $args['date'] ) ) {
			$where[]   = 'entry_date = %s';
			$prepare[] = $args['date'];
		}

		// Date range filters.
		if ( ! empty( $args['date_from'] ) ) {
			$where[]   = 'entry_date >= %s';
			$prepare[] = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]   = 'entry_date <= %s';
			$prepare[] = $args['date_to'];
		}

		// Shared filters: client, project, tag, billable, billed.
		$common  = self::build_filter_clauses( $args, '', $table );
		$where   = array_merge( $where, $common['where'] );
		$prepare = array_merge( $prepare, $common['prepare'] );

		// Verified filter.
		if ( null !== $args['verified'] ) {
			$where[]   = 'verified = %d';
			$prepare[] = $args['verified'] ? 1 : 0;
		}

		$sql = "SELECT * FROM {$table}";

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		// Ordering.
		$valid_orderby = array( 'id', 'entry_date', 'start_time', 'duration_minutes', 'created_at' );
		$orderby       = in_array( $args['orderby'], $valid_orderby, true )
			? $args['orderby']
			: 'entry_date';
		$order         = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		// Secondary sort by start_time for same-day entries.
		if ( 'entry_date' === $orderby ) {
			$sql .= " ORDER BY {$orderby} {$order}, start_time ASC";
		} else {
			$sql .= " ORDER BY {$orderby} {$order}";
		}

		// Limit and offset.
		if ( $args['limit'] > 0 ) {
			$sql      .= ' LIMIT %d';
			$prepare[] = $args['limit'];

			if ( $args['offset'] > 0 ) {
				$sql      .= ' OFFSET %d';
				$prepare[] = $args['offset'];
			}
		}

		if ( ! empty( $prepare ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return $wpdb->get_results( $wpdb->prepare( $sql, $prepare ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql );
	}

	/**
	 * Get entries for a specific date.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return array Array of entry objects.
	 */
	public static function get_by_date( $date ) {
		return self::get_all(
			array(
				'date'    => $date,
				'orderby' => 'start_time',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * Create a new time entry.
	 *
	 * @param array $data Entry data.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function create( $data ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'time_entries' );

		$client_id  = ! empty( $data['client_id'] ) ? absint( $data['client_id'] ) : null;
		$project_id = ! empty( $data['project_id'] ) ? absint( $data['project_id'] ) : null;

		// Determine billable default: explicit value wins; otherwise inherit from project; fall back to 1.
		if ( array_key_exists( 'billable', $data ) ) {
			$billable = ! empty( $data['billable'] ) ? 1 : 0;
		} elseif ( null !== $project_id ) {
			$projects_table = PLTT_Database::get_table_name( 'projects' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$project_default = $wpdb->get_var( $wpdb->prepare( "SELECT billability_default FROM {$projects_table} WHERE id = %d", $project_id ) );
			$billable        = null !== $project_default ? (int) $project_default : 1;
		} else {
			$billable = 1;
		}

		$insert_data = array(
			'entry_date'       => pltt_sanitize_date( $data['entry_date'] ?? '' ),
			'start_time'       => sanitize_text_field( $data['start_time'] ?? '' ),
			'end_time'         => sanitize_text_field( $data['end_time'] ?? '' ),
			'duration_minutes' => absint( $data['duration_minutes'] ?? 0 ),
			'raw_text'         => sanitize_textarea_field( $data['raw_text'] ?? '' ),
			'description'      => sanitize_textarea_field( $data['description'] ?? '' ),
			'verified'         => ! empty( $data['verified'] ) ? 1 : 0,
			'billable'         => $billable,
		);

		$formats = array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d' );

		// Only include nullable fields when they have values.
		// wpdb->insert() converts NULL to 0 with %d format; omitting them lets MySQL use NULL.
		if ( null !== $client_id ) {
			$insert_data['client_id'] = $client_id;
			$formats[]                = '%d';
		}
		if ( null !== $project_id ) {
			$insert_data['project_id'] = $project_id;
			$formats[]                 = '%d';
		}
		if ( empty( $insert_data['entry_date'] ) || empty( $insert_data['start_time'] ) ) {
			return false;
		}

		// Start our own transaction only if the caller has not already opened one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$own_transaction = ! (bool) $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( $own_transaction ) {
			$wpdb->query( 'START TRANSACTION' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			$insert_data,
			$formats
		);

		if ( ! $result ) {
			if ( $own_transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return false;
		}

		$insert_id = $wpdb->insert_id;

		// Sync tags to the junction table; roll back the entry if sync fails.
		$sync_ok = true;
		if ( ! empty( $data['tags'] ) ) {
			$sync_ok = PLTT_Tags::sync_entry_tags( $insert_id, $data['tags'] );
		}

		if ( $own_transaction ) {
			$wpdb->query( $sync_ok ? 'COMMIT' : 'ROLLBACK' );
		}

		return $sync_ok ? $insert_id : false;
	}

	/**
	 * Update a time entry.
	 *
	 * @param int   $id   Entry ID.
	 * @param array $data Data to update.
	 * @return bool True on success.
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'time_entries' );

		$update_data  = array();
		$formats      = array();
		$null_fields  = array();

		$allowed_fields = array(
			'entry_date'       => '%s',
			'start_time'       => '%s',
			'end_time'         => '%s',
			'duration_minutes' => '%d',
			'raw_text'         => '%s',
			'description'      => '%s',
			'client_id'        => '%d',
			'project_id'       => '%d',
			'verified'         => '%d',
			'billable'         => '%d',
			'billable_rate'    => '%f',
			'billable_amount'  => '%f',
			'billed'           => '%d',
		);

		// Nullable fields: wpdb->update() cannot set columns to NULL with %d format.
		$nullable_fields = array( 'client_id', 'project_id' );

		foreach ( $allowed_fields as $field => $format ) {
			if ( array_key_exists( $field, $data ) ) {
				if ( in_array( $field, $nullable_fields, true ) ) {
					if ( ! empty( $data[ $field ] ) ) {
						$update_data[ $field ] = '%d' === $format ? absint( $data[ $field ] ) : sanitize_text_field( $data[ $field ] );
						$formats[]             = $format;
					} else {
						$null_fields[] = $field;
					}
				} elseif ( 'duration_minutes' === $field ) {
					$update_data[ $field ] = absint( $data[ $field ] );
					$formats[]             = $format;
				} elseif ( in_array( $field, array( 'verified', 'billable', 'billed' ), true ) ) {
					$update_data[ $field ] = ! empty( $data[ $field ] ) ? 1 : 0;
					$formats[]             = $format;
				} elseif ( 'entry_date' === $field ) {
					$update_data[ $field ] = pltt_sanitize_date( $data[ $field ] );
					$formats[]             = $format;
				} elseif ( '%f' === $format ) {
					$update_data[ $field ] = (float) $data[ $field ];
					$formats[]             = $format;
				} else {
					$update_data[ $field ] = sanitize_text_field( $data[ $field ] );
					$formats[]             = $format;
				}
			}
		}

		if ( empty( $update_data ) && empty( $null_fields ) ) {
			return false;
		}

		// Only own the transaction if the caller has not already opened one
		// (matches the pattern in create()).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$own_transaction = ! (bool) $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( $own_transaction ) {
			$wpdb->query( 'START TRANSACTION' );
		}

		$result = true;

		// Update non-null fields via wpdb->update().
		if ( ! empty( $update_data ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = false !== $wpdb->update(
				$table,
				$update_data,
				array( 'id' => $id ),
				$formats,
				array( '%d' )
			);
		}

		// Set nullable fields to NULL directly since wpdb->update() converts NULL to 0 with %d.
		if ( $result && ! empty( $null_fields ) ) {
			$result = pltt_set_nullable_fields( $table, $id, $null_fields );
		}

		// Sync tags when the caller provides a 'tags' key.
		if ( $result && array_key_exists( 'tags', $data ) ) {
			$result = PLTT_Tags::sync_entry_tags( $id, $data['tags'] );
		}

		if ( $own_transaction ) {
			$wpdb->query( $result ? 'COMMIT' : 'ROLLBACK' );
		}

		return $result;
	}

	/**
	 * Delete a time entry.
	 *
	 * @param int $id Entry ID.
	 * @return bool True on success.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'time_entries' );

		// Remove tag associations before deleting the entry.
		PLTT_Tags::delete_for_entry( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete all entries for a specific date.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return int|false Number of deleted rows or false on error.
	 */
	public static function delete_by_date( $date ) {
		global $wpdb;
		$table            = PLTT_Database::get_table_name( 'time_entries' );
		$entry_tags_table = PLTT_Database::get_table_name( 'entry_tags' );

		// OPT-L2: Delete junction rows in a single DELETE JOIN instead of looping per entry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE et FROM {$entry_tags_table} et
				INNER JOIN {$table} e ON et.entry_id = e.id
				WHERE e.entry_date = %s",
				$date
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->delete(
			$table,
			array( 'entry_date' => $date ),
			array( '%s' )
		);
	}

	/**
	 * Get aggregate stats for a date range.
	 *
	 * Returns entry count, total/billable minutes, and verified count
	 * in a single query so the reports page doesn't need to load all rows.
	 *
	 * @param array $args Query arguments (date_from, date_to, client_id, project_id, tag, billable).
	 * @return object Stats with total_count, total_minutes, billable_minutes, unbilled_billable_minutes, verified_count, first_entry_date, last_entry_date.
	 */
	public static function get_stats( $args = array() ) {
		global $wpdb;
		$table          = PLTT_Database::get_table_name( 'time_entries' );
		$projects_table = PLTT_Database::get_table_name( 'projects' );
		$clients_table  = PLTT_Database::get_table_name( 'clients' );

		$where   = array();
		$prepare = array();

		if ( ! empty( $args['date_from'] ) ) {
			$where[]   = 'e.entry_date >= %s';
			$prepare[] = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]   = 'e.entry_date <= %s';
			$prepare[] = $args['date_to'];
		}

		// Shared filters: client, project, tag, billable, billed.
		$common  = self::build_filter_clauses( $args, 'e.', 'e' );
		$where   = array_merge( $where, $common['where'] );
		$prepare = array_merge( $prepare, $common['prepare'] );

		// SEC-L1: Resolve via is_internal flag (DB 1.9.3+) rather than hardcoded ID.
		// pltt_get_internal_client_id() is request-cached; the fallback handles fresh installs
		// where the migration has not yet run.
		$internal_client_id = pltt_get_internal_client_id();
		$exclude_clause     = $internal_client_id > 0
			? "e.client_id != {$internal_client_id}"
			: "LOWER(c.name) != 'internal'";

		$sql = "SELECT
			COUNT(*) AS total_count,
			COALESCE(SUM(e.duration_minutes), 0) AS total_minutes,
			COALESCE(SUM(CASE WHEN e.billable = 1 THEN e.duration_minutes ELSE 0 END), 0) AS billable_minutes,
			COALESCE(SUM(CASE WHEN e.billable = 1 AND COALESCE(e.billed, 0) = 0 THEN e.duration_minutes ELSE 0 END), 0) AS unbilled_billable_minutes,
			COALESCE(SUM(CASE WHEN ({$exclude_clause}) THEN e.duration_minutes ELSE 0 END), 0) AS client_total_minutes,
			COALESCE(SUM(CASE WHEN ({$exclude_clause}) AND e.billable = 1 THEN e.duration_minutes ELSE 0 END), 0) AS client_billable_minutes,
			SUM(CASE WHEN e.verified = 1 THEN 1 ELSE 0 END) AS verified_count,
			COALESCE(SUM(CASE WHEN e.billable = 1 THEN COALESCE(e.billable_amount, ROUND(e.duration_minutes / 60.0 * COALESCE(p.hourly_rate, c.hourly_rate, 0), 2)) ELSE 0 END), 0) AS billable_amount,
			COUNT(DISTINCT CASE WHEN ({$exclude_clause}) THEN e.project_id END) AS active_projects,
			COUNT(DISTINCT CASE WHEN ({$exclude_clause}) THEN e.client_id END) AS active_clients,
			MIN(e.entry_date) AS first_entry_date,
			MAX(e.entry_date) AS last_entry_date
			FROM {$table} e
			LEFT JOIN {$projects_table} p ON e.project_id = p.id
			LEFT JOIN {$clients_table} c ON e.client_id = c.id";

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		if ( ! empty( $prepare ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return $wpdb->get_row( $wpdb->prepare( $sql, $prepare ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $sql );
	}

	/**
	 * Get aggregate stats grouped by client_id or project_id in a single query.
	 *
	 * OPT-N1/N2/N3: Replaces N×get_stats() calls in templates/clients.php,
	 * templates/projects.php, and class-pltt-reports.php's allocation loop.
	 *
	 * @param string $group_by Either 'client_id' or 'project_id'.
	 * @param array  $args     Optional filters: date_from, date_to, plus any
	 *                         build_filter_clauses() args (billable/billed/tag/etc).
	 *                         When grouping by project_id you may pass
	 *                         project_ids => int[] to restrict the result; same
	 *                         for client_ids when grouping by client_id.
	 * @return array<int, object> Map keyed by the group column. Each value has
	 *                            total_count, total_minutes, billable_minutes,
	 *                            unbilled_billable_minutes, billable_amount,
	 *                            first_entry_date, last_entry_date.
	 */
	public static function get_stats_grouped_by( $group_by, $args = array() ) {
		global $wpdb;

		$allowed_groups = array( 'client_id', 'project_id' );
		if ( ! in_array( $group_by, $allowed_groups, true ) ) {
			return array();
		}

		$entries_table  = PLTT_Database::get_table_name( 'time_entries' );
		$projects_table = PLTT_Database::get_table_name( 'projects' );
		$clients_table  = PLTT_Database::get_table_name( 'clients' );

		$where   = array( "e.{$group_by} IS NOT NULL" );
		$prepare = array();

		if ( ! empty( $args['date_from'] ) ) {
			$where[]   = 'e.entry_date >= %s';
			$prepare[] = $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]   = 'e.entry_date <= %s';
			$prepare[] = $args['date_to'];
		}

		// Optional ID restriction (so we only aggregate rows we're going to read).
		$ids_arg = $args[ $group_by . 's' ] ?? array();
		$ids_arg = array_filter( array_map( 'absint', (array) $ids_arg ) );
		if ( ! empty( $ids_arg ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids_arg ), '%d' ) );
			$where[]      = "e.{$group_by} IN ({$placeholders})";
			$prepare      = array_merge( $prepare, $ids_arg );
		}

		// Shared filters: client, project, tag, billable, billed.
		$common  = self::build_filter_clauses( $args, 'e.', 'e' );
		$where   = array_merge( $where, $common['where'] );
		$prepare = array_merge( $prepare, $common['prepare'] );

		$sql = "SELECT
			e.{$group_by} AS group_key,
			COUNT(*) AS total_count,
			COALESCE(SUM(e.duration_minutes), 0) AS total_minutes,
			COALESCE(SUM(CASE WHEN e.billable = 1 THEN e.duration_minutes ELSE 0 END), 0) AS billable_minutes,
			COALESCE(SUM(CASE WHEN e.billable = 1 AND COALESCE(e.billed, 0) = 0 THEN e.duration_minutes ELSE 0 END), 0) AS unbilled_billable_minutes,
			COALESCE(SUM(CASE WHEN e.billable = 1 THEN COALESCE(e.billable_amount, ROUND(e.duration_minutes / 60.0 * COALESCE(p.hourly_rate, c.hourly_rate, 0), 2)) ELSE 0 END), 0) AS billable_amount,
			MIN(e.entry_date) AS first_entry_date,
			MAX(e.entry_date) AS last_entry_date
			FROM {$entries_table} e
			LEFT JOIN {$projects_table} p ON e.project_id = p.id
			LEFT JOIN {$clients_table} c ON e.client_id = c.id
			WHERE " . implode( ' AND ', $where ) . "
			GROUP BY e.{$group_by}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = empty( $prepare )
			? $wpdb->get_results( $sql )
			: $wpdb->get_results( $wpdb->prepare( $sql, $prepare ) );

		$out = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$key         = (int) $row->group_key;
				unset( $row->group_key );
				$out[ $key ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Get summary by project for a date range.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param array  $args      Optional filters (client_id, project_id, tag, billable).
	 * @return array Array of project summaries.
	 */
	public static function get_summary_by_project( $date_from, $date_to, $args = array() ) {
		global $wpdb;
		$entries_table  = PLTT_Database::get_table_name( 'time_entries' );
		$projects_table = PLTT_Database::get_table_name( 'projects' );
		$clients_table  = PLTT_Database::get_table_name( 'clients' );

		$where   = array( 'e.entry_date >= %s', 'e.entry_date <= %s', 'e.verified = 1' );
		$prepare = array( $date_from, $date_to );

		// Shared filters: client, project, tag, billable, billed.
		$common  = self::build_filter_clauses( $args, 'e.', 'e' );
		$where   = array_merge( $where, $common['where'] );
		$prepare = array_merge( $prepare, $common['prepare'] );

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.id AS project_id,
					p.name AS project_name,
					p.status AS project_status,
					p.budget_hours,
					p.budget_fee,
					p.recurring_period,
					p.billability_default,
					c.id AS client_id,
					c.name AS client_name,
					SUM(e.duration_minutes) AS total_minutes,
					COALESCE(SUM(CASE WHEN e.billable = 1 THEN e.duration_minutes ELSE 0 END), 0) AS billable_minutes,
					COALESCE(SUM(CASE WHEN e.billable = 1 THEN COALESCE(e.billable_amount, ROUND(e.duration_minutes / 60.0 * COALESCE(p.hourly_rate, c.hourly_rate, 0), 2)) ELSE 0 END), 0) AS billable_amount,
					COUNT(e.id) AS entry_count
				FROM {$entries_table} e
				LEFT JOIN {$projects_table} p ON e.project_id = p.id
				LEFT JOIN {$clients_table} c ON e.client_id = c.id
				WHERE {$where_sql}
				GROUP BY p.id, p.name, p.status, p.budget_hours, p.budget_fee, p.recurring_period, p.billability_default, c.id, c.name
				ORDER BY p.name ASC, c.name ASC",
				$prepare
			)
		);
	}

	/**
	 * Get the highest-hours projects for the given period.
	 *
	 * Used by the "Top Projects" summary card. Excludes the Internal client
	 * (so the card reflects client-facing work).
	 *
	 * @param string $date_from Start date (Y-m-d).
	 * @param string $date_to   End date (Y-m-d).
	 * @param array  $args      Optional filters (same shape as get_summary_by_project()).
	 * @param int    $limit     How many top projects to return (default 2).
	 * @return array Rows of { project_id, project_name, client_name, total_minutes, entry_count }; empty if no data.
	 */
	public static function get_top_projects_for_period( $date_from, $date_to, $args = array(), $limit = 2 ) {
		global $wpdb;
		$entries_table  = PLTT_Database::get_table_name( 'time_entries' );
		$projects_table = PLTT_Database::get_table_name( 'projects' );
		$clients_table  = PLTT_Database::get_table_name( 'clients' );

		$where   = array( 'e.entry_date >= %s', 'e.entry_date <= %s', 'e.verified = 1', 'e.project_id IS NOT NULL' );
		$prepare = array( $date_from, $date_to );

		// Exclude the Internal client so the "Top Project" card reflects client-facing work.
		$internal_client_id = pltt_get_internal_client_id();
		if ( $internal_client_id > 0 ) {
			$where[] = 'e.client_id != %d';
			$prepare[] = $internal_client_id;
		}

		// Shared filters: client, project, tag, billable, billed.
		$common  = self::build_filter_clauses( $args, 'e.', 'e' );
		$where   = array_merge( $where, $common['where'] );
		$prepare = array_merge( $prepare, $common['prepare'] );

		$where_sql = implode( ' AND ', $where );
		$limit     = max( 1, (int) $limit );
		$prepare[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.id AS project_id,
					p.name AS project_name,
					c.name AS client_name,
					SUM(e.duration_minutes) AS total_minutes,
					COUNT(e.id) AS entry_count
				FROM {$entries_table} e
				LEFT JOIN {$projects_table} p ON e.project_id = p.id
				LEFT JOIN {$clients_table} c ON e.client_id = c.id
				WHERE {$where_sql}
				GROUP BY p.id, p.name, c.name
				ORDER BY total_minutes DESC, p.name ASC
				LIMIT %d",
				$prepare
			)
		);
	}

	/**
	 * Get billable/non-billable daily totals for the reports chart.
	 *
	 * Returns one row per entry_date in the range, with the same filter set
	 * as get_summary_by_project() so the chart matches the surrounding tables.
	 * The caller is responsible for aggregating daily rows into week/month
	 * buckets via pltt_build_chart_buckets().
	 *
	 * @param string $date_from Start date (Y-m-d).
	 * @param string $date_to   End date (Y-m-d).
	 * @param array  $args      Optional filters (client_id, project_id, tag, billable, billed, negate flags).
	 * @return array Rows of { entry_date, billable_minutes, client_flat_minutes, internal_minutes }.
	 */
	public static function get_chart_daily_totals( $date_from, $date_to, $args = array() ) {
		global $wpdb;
		$entries_table = PLTT_Database::get_table_name( 'time_entries' );
		$clients_table = PLTT_Database::get_table_name( 'clients' );

		$where   = array( 'e.entry_date >= %s', 'e.entry_date <= %s', 'e.verified = 1' );
		$prepare = array( $date_from, $date_to );

		// Shared filters: client, project, tag, billable, billed.
		$common  = self::build_filter_clauses( $args, 'e.', 'e' );
		$where   = array_merge( $where, $common['where'] );
		$prepare = array_merge( $prepare, $common['prepare'] );

		$where_sql = implode( ' AND ', $where );

		// SEC-L1 pattern: resolve via is_internal flag (DB 1.9.3+) rather than hardcoded ID.
		$internal_client_id = pltt_get_internal_client_id();
		$internal_clause    = $internal_client_id > 0
			? "e.client_id = {$internal_client_id}"
			: "LOWER(c.name) = 'internal'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					e.entry_date,
					COALESCE(SUM(CASE WHEN e.billable = 1 THEN e.duration_minutes ELSE 0 END), 0) AS billable_minutes,
					COALESCE(SUM(CASE WHEN e.billable = 0 AND NOT ({$internal_clause}) THEN e.duration_minutes ELSE 0 END), 0) AS client_flat_minutes,
					COALESCE(SUM(CASE WHEN e.billable = 0 AND ({$internal_clause}) THEN e.duration_minutes ELSE 0 END), 0) AS internal_minutes
				FROM {$entries_table} e
				LEFT JOIN {$clients_table} c ON e.client_id = c.id
				WHERE {$where_sql}
				GROUP BY e.entry_date
				ORDER BY e.entry_date ASC",
				$prepare
			)
		);
	}

	/**
	 * Build shared WHERE clause array for common entry filters.
	 *
	 * Centralises the client, project, tag, billable, and billed filter
	 * logic that is otherwise duplicated across get_all(), get_stats(),
	 * and get_summary_by_project().
	 *
	 * @param array  $args       Query arguments (client_id, client_negate, project_id,
	 *                           project_negate, tag, tag_negate, billable, billed).
	 * @param string $col_prefix Column prefix, '' for bare columns or 'e.' for aliased.
	 * @param string $entry_ref  Table alias or name used in tag subquery entry_id reference.
	 * @return array{ where: string[], prepare: mixed[] }
	 */
	private static function build_filter_clauses( array $args, $col_prefix = '', $entry_ref = '' ) {
		$where   = array();
		$prepare = array();

		// Client filter.
		if ( ! empty( $args['client_id'] ) && (int) $args['client_id'] > 0 ) {
			$col = $col_prefix . 'client_id';
			if ( ! empty( $args['client_negate'] ) ) {
				$where[]   = "({$col} IS NULL OR {$col} != %d)";
				$prepare[] = absint( $args['client_id'] );
			} else {
				$where[]   = "{$col} = %d";
				$prepare[] = absint( $args['client_id'] );
			}
		}

		// Project filter.
		$p_col = $col_prefix . 'project_id';
		if ( 'without_project' === ( isset( $args['project_id'] ) ? $args['project_id'] : '' ) ) {
			$where[] = "{$p_col} IS NULL";
		} elseif ( ! empty( $args['project_id'] ) && (int) $args['project_id'] > 0 ) {
			if ( ! empty( $args['project_negate'] ) ) {
				$where[]   = "({$p_col} IS NULL OR {$p_col} != %d)";
				$prepare[] = absint( $args['project_id'] );
			} else {
				$where[]   = "{$p_col} = %d";
				$prepare[] = absint( $args['project_id'] );
			}
		}

		// Tag filter (junction table).
		$entry_tags_table = PLTT_Database::get_table_name( 'entry_tags' );
		$tags_table       = PLTT_Database::get_table_name( 'tags' );
		$entry_id_col     = $entry_ref ? "{$entry_ref}.id" : 'id';
		$tag_val          = isset( $args['tag'] ) ? $args['tag'] : '';
		if ( 'without_tag' === $tag_val ) {
			$where[] = "NOT EXISTS (SELECT 1 FROM {$entry_tags_table} WHERE entry_id = {$entry_id_col})";
		} elseif ( ! empty( $tag_val ) ) {
			if ( ! empty( $args['tag_negate'] ) ) {
				$where[]   = "NOT EXISTS (SELECT 1 FROM {$entry_tags_table} et JOIN {$tags_table} t ON et.tag_id = t.id WHERE et.entry_id = {$entry_id_col} AND t.name = %s)";
				$prepare[] = $tag_val;
			} else {
				$where[]   = "EXISTS (SELECT 1 FROM {$entry_tags_table} et JOIN {$tags_table} t ON et.tag_id = t.id WHERE et.entry_id = {$entry_id_col} AND t.name = %s)";
				$prepare[] = $tag_val;
			}
		}

		// Billable filter.
		if ( isset( $args['billable'] ) && null !== $args['billable'] ) {
			$where[]   = $col_prefix . 'billable = %d';
			$prepare[] = $args['billable'] ? 1 : 0;
		}

		// Billed filter (invoiced status).
		if ( isset( $args['billed'] ) && null !== $args['billed'] ) {
			$where[]   = $col_prefix . 'billed = %d';
			$prepare[] = $args['billed'] ? 1 : 0;
		}

		return array(
			'where'   => $where,
			'prepare' => $prepare,
		);
	}
}

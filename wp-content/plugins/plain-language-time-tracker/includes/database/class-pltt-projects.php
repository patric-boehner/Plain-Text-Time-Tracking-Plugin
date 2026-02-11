<?php
/**
 * Project CRUD operations.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles project database operations.
 */
class PLTT_Projects {

	/**
	 * Get a single project by ID.
	 *
	 * @param int $id Project ID.
	 * @return object|null Project object or null.
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'projects' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	/**
	 * Get all projects.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of project objects.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'projects' );

		$defaults = array(
			'client_id' => 0,
			'status'    => '',
			'orderby'   => 'name',
			'order'     => 'ASC',
			'search'    => '',
			'limit'     => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where   = array();
		$prepare = array();

		// Client filter.
		if ( $args['client_id'] > 0 ) {
			$where[]   = 'client_id = %d';
			$prepare[] = $args['client_id'];
		}

		// Status filter.
		if ( ! empty( $args['status'] ) ) {
			$where[]   = 'status = %s';
			$prepare[] = $args['status'];
		}

		// Search filter.
		if ( ! empty( $args['search'] ) ) {
			$where[]   = 'name LIKE %s';
			$prepare[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$sql = "SELECT * FROM {$table}";

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		// Ordering.
		$orderby = in_array( $args['orderby'], array( 'id', 'name', 'created_at', 'status' ), true )
			? $args['orderby']
			: 'name';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$sql    .= " ORDER BY {$orderby} {$order}";

		// Limit.
		if ( $args['limit'] > 0 ) {
			$sql      .= ' LIMIT %d';
			$prepare[] = $args['limit'];
		}

		if ( ! empty( $prepare ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return $wpdb->get_results( $wpdb->prepare( $sql, $prepare ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql );
	}

	/**
	 * Get projects for a specific client.
	 *
	 * @param int  $client_id   Client ID.
	 * @param bool $active_only Only return active projects.
	 * @return array Array of project objects.
	 */
	public static function get_by_client( $client_id, $active_only = true ) {
		$args = array(
			'client_id' => $client_id,
			'orderby'   => 'name',
			'order'     => 'ASC',
		);

		if ( $active_only ) {
			$args['status'] = 'active';
		}

		return self::get_all( $args );
	}

	/**
	 * Create a new project.
	 *
	 * @param array $data Project data.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function create( $data ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'projects' );

		$insert_data = array(
			'client_id'   => absint( $data['client_id'] ?? 0 ),
			'name'        => sanitize_text_field( $data['name'] ?? '' ),
			'status'      => 'active',
			'description' => sanitize_textarea_field( $data['description'] ?? '' ),
		);

		$formats = array( '%d', '%s', '%s', '%s' );

		if ( empty( $insert_data['name'] ) || empty( $insert_data['client_id'] ) ) {
			return false;
		}

		// Nullable: omit from array when NULL so MySQL uses column default.
		if ( isset( $data['hourly_rate'] ) && '' !== $data['hourly_rate'] ) {
			$insert_data['hourly_rate'] = floatval( $data['hourly_rate'] );
			$formats[]                  = '%f';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			$insert_data,
			$formats
		);

		if ( $result ) {
			pltt_flush_project_cache();
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Update a project.
	 *
	 * @param int   $id   Project ID.
	 * @param array $data Data to update.
	 * @return bool True on success.
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'projects' );

		$update_data = array();
		$formats     = array();
		$null_fields = array();

		if ( array_key_exists( 'name', $data ) ) {
			$update_data['name'] = sanitize_text_field( $data['name'] );
			$formats[]           = '%s';
		}

		if ( array_key_exists( 'client_id', $data ) ) {
			$update_data['client_id'] = absint( $data['client_id'] );
			$formats[]                = '%d';
		}

		if ( array_key_exists( 'status', $data ) ) {
			$update_data['status'] = in_array( $data['status'], array( 'active', 'archived' ), true )
				? $data['status']
				: 'active';
			$formats[]             = '%s';
		}

		if ( array_key_exists( 'description', $data ) ) {
			$update_data['description'] = sanitize_textarea_field( $data['description'] );
			$formats[]                  = '%s';
		}

		if ( array_key_exists( 'hourly_rate', $data ) ) {
			if ( '' !== $data['hourly_rate'] && null !== $data['hourly_rate'] ) {
				$update_data['hourly_rate'] = floatval( $data['hourly_rate'] );
				$formats[]                  = '%f';
			} else {
				$null_fields[] = 'hourly_rate';
			}
		}

		if ( empty( $update_data ) && empty( $null_fields ) ) {
			return false;
		}

		$result = true;

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

		// Set nullable fields to NULL directly since wpdb converts NULL to 0.
		if ( $result && ! empty( $null_fields ) ) {
			$set_clauses = array();
			foreach ( $null_fields as $field ) {
				$set_clauses[] = "`{$field}` = NULL";
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = false !== $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"UPDATE {$table} SET " . implode( ', ', $set_clauses ) . ' WHERE id = %d',
					$id
				)
			);
		}

		if ( $result ) {
			pltt_flush_project_cache();
		}

		return $result;
	}

	/**
	 * Archive a project.
	 *
	 * @param int $id Project ID.
	 * @return bool True on success.
	 */
	public static function archive( $id ) {
		return self::update( $id, array( 'status' => 'archived' ) );
	}

	/**
	 * Restore an archived project.
	 *
	 * @param int $id Project ID.
	 * @return bool True on success.
	 */
	public static function restore( $id ) {
		return self::update( $id, array( 'status' => 'active' ) );
	}

	/**
	 * Delete a project.
	 *
	 * @param int $id Project ID.
	 * @return bool True on success.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'projects' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get the most recently used project for a client.
	 *
	 * Looks at recent time entries within PLTT_PREDICTION_WINDOW_DAYS.
	 *
	 * @param int $client_id Client ID.
	 * @return object|null Project object or null.
	 */
	public static function get_recent_for_client( $client_id ) {
		global $wpdb;
		$entries_table  = PLTT_Database::get_table_name( 'time_entries' );
		$projects_table = PLTT_Database::get_table_name( 'projects' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.* FROM {$projects_table} p
				INNER JOIN {$entries_table} e ON p.id = e.project_id
				WHERE p.client_id = %d
				AND p.status = 'active'
				AND e.entry_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				GROUP BY p.id
				ORDER BY MAX(e.entry_date) DESC, MAX(e.start_time) DESC
				LIMIT 1",
				$client_id,
				PLTT_PREDICTION_WINDOW_DAYS
			)
		);
	}

	/**
	 * Get active projects for a client, sorted by most recent usage.
	 *
	 * Projects used most recently appear first, followed by unused projects alphabetically.
	 * Optionally includes specific archived projects by ID (e.g. when an entry already references them).
	 *
	 * @param int   $client_id          Client ID.
	 * @param array $include_project_ids Extra project IDs to include even if archived.
	 * @return array Array of project objects.
	 */
	public static function get_by_client_recent_first( $client_id, $include_project_ids = array() ) {
		global $wpdb;
		$projects_table = PLTT_Database::get_table_name( 'projects' );
		$entries_table  = PLTT_Database::get_table_name( 'time_entries' );

		$include_project_ids = array_filter( array_map( 'absint', $include_project_ids ) );

		if ( ! empty( $include_project_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $include_project_ids ), '%d' ) );
			$status_clause = "AND ( p.status = 'active' OR p.id IN ({$placeholders}) )";
			$prepare_args  = array_merge( array( $client_id ), $include_project_ids );
		} else {
			$status_clause = "AND p.status = 'active'";
			$prepare_args  = array( $client_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.* FROM {$projects_table} p
				LEFT JOIN {$entries_table} e ON p.id = e.project_id
				WHERE p.client_id = %d
				{$status_clause}
				GROUP BY p.id
				ORDER BY MAX(e.entry_date) DESC, p.name ASC",
				$prepare_args
			)
		);
	}

	/**
	 * Count projects, optionally by status.
	 *
	 * @param string $status Optional status filter.
	 * @return int Project count.
	 */
	public static function count( $status = '' ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'projects' );

		if ( ! empty( $status ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}

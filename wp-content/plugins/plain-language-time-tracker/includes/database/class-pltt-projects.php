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
	 * Get multiple projects by ID in a single query.
	 *
	 * @param int[] $ids Array of project IDs.
	 * @return array ID-keyed map of project objects (missing IDs are omitted).
	 */
	public static function get_multiple( $ids ) {
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return array();
		}

		global $wpdb;
		$table        = PLTT_Database::get_table_name( 'projects' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids )
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->id ] = $row;
		}
		return $map;
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
		$sql    .= " ORDER BY CASE WHEN status = 'archived' THEN 1 ELSE 0 END ASC, {$orderby} {$order}";

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
			'client_id'           => absint( $data['client_id'] ?? 0 ),
			'name'                => sanitize_text_field( $data['name'] ?? '' ),
			'status'              => 'active',
			'description'         => sanitize_textarea_field( $data['description'] ?? '' ),
			'billability_default' => isset( $data['billability_default'] ) ? ( $data['billability_default'] ? 1 : 0 ) : 1,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%d' );

		// Validate required fields.
		if ( empty( $insert_data['client_id'] ) ) {
			return new WP_Error( 'missing_client', __( 'Client is required.', 'plain-language-time-tracker' ) );
		}

		if ( empty( $insert_data['name'] ) || '' === trim( $insert_data['name'] ) ) {
			return new WP_Error( 'missing_name', __( 'Project name is required.', 'plain-language-time-tracker' ) );
		}

		// Nullable: omit from array when NULL so MySQL uses column default.
		if ( isset( $data['hourly_rate'] ) && '' !== $data['hourly_rate'] ) {
			$rate       = floatval( $data['hourly_rate'] );
			$rate_valid = pltt_validate_hourly_rate( $rate );
			if ( is_wp_error( $rate_valid ) ) {
				return $rate_valid;
			}
			$insert_data['hourly_rate'] = $rate;
			$formats[]                  = '%f';
		}

		// Nullable: recurring_period is NULL when not set.
		if ( isset( $data['recurring_period'] ) && '' !== $data['recurring_period'] ) {
			$allowed_periods = array( 'monthly' );
			if ( in_array( $data['recurring_period'], $allowed_periods, true ) ) {
				$insert_data['recurring_period'] = $data['recurring_period'];
				$formats[]                       = '%s';
			}
		}

		// Nullable: budget_hours is NULL when not set.
		if ( isset( $data['budget_hours'] ) && '' !== $data['budget_hours'] ) {
			$hours = floatval( $data['budget_hours'] );
			if ( $hours >= 0 ) {
				$insert_data['budget_hours'] = $hours;
				$formats[]                   = '%f';
			}
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
			$name = sanitize_text_field( $data['name'] );

			// Validate required field.
			if ( empty( $name ) || '' === trim( $name ) ) {
				return new WP_Error( 'missing_name', __( 'Project name is required.', 'plain-language-time-tracker' ) );
			}

			$update_data['name'] = $name;
			$formats[]           = '%s';
		}

		if ( array_key_exists( 'client_id', $data ) ) {
			$client_id = absint( $data['client_id'] );

			// Validate required field.
			if ( empty( $client_id ) ) {
				return new WP_Error( 'missing_client', __( 'Client is required.', 'plain-language-time-tracker' ) );
			}

			$update_data['client_id'] = $client_id;
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
				$rate       = floatval( $data['hourly_rate'] );
				$rate_valid = pltt_validate_hourly_rate( $rate );
				if ( is_wp_error( $rate_valid ) ) {
					return $rate_valid;
				}
				$update_data['hourly_rate'] = $rate;
				$formats[]                  = '%f';
			} else {
				$null_fields[] = 'hourly_rate';
			}
		}

		if ( array_key_exists( 'billability_default', $data ) ) {
			$update_data['billability_default'] = $data['billability_default'] ? 1 : 0;
			$formats[]                          = '%d';
		}

		if ( array_key_exists( 'recurring_period', $data ) ) {
			if ( '' !== $data['recurring_period'] && null !== $data['recurring_period'] ) {
				$allowed_periods = array( 'monthly' );
				if ( in_array( $data['recurring_period'], $allowed_periods, true ) ) {
					$update_data['recurring_period'] = $data['recurring_period'];
					$formats[]                       = '%s';
				}
			} else {
				$null_fields[] = 'recurring_period';
			}
		}

		if ( array_key_exists( 'budget_hours', $data ) ) {
			if ( '' !== $data['budget_hours'] && null !== $data['budget_hours'] ) {
				$hours = floatval( $data['budget_hours'] );
				if ( $hours >= 0 ) {
					$update_data['budget_hours'] = $hours;
					$formats[]                   = '%f';
				}
			} else {
				$null_fields[] = 'budget_hours';
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
			$result = pltt_set_nullable_fields( $table, $id, $null_fields );
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
	 * Get active projects for multiple clients in a single query, sorted by most recent usage.
	 *
	 * Same ordering as get_by_client_recent_first() but fetches all clients at once to avoid N+1.
	 * Returns an array keyed by client_id, each containing an array of project objects.
	 *
	 * @param int[] $client_ids            Array of client IDs.
	 * @param array $extra_ids_by_client   Map of client_id => array of extra project IDs to include even if archived.
	 * @return array client_id => project[] map.
	 */
	public static function get_for_clients( $client_ids, $extra_ids_by_client = array() ) {
		$client_ids = array_filter( array_map( 'absint', $client_ids ) );
		if ( empty( $client_ids ) ) {
			return array();
		}

		global $wpdb;
		$projects_table = PLTT_Database::get_table_name( 'projects' );
		$entries_table  = PLTT_Database::get_table_name( 'time_entries' );

		// Collect all extra project IDs across all clients.
		$all_extra_ids = array();
		foreach ( $extra_ids_by_client as $extra_ids ) {
			foreach ( $extra_ids as $pid ) {
				$all_extra_ids[] = absint( $pid );
			}
		}
		$all_extra_ids = array_filter( array_unique( $all_extra_ids ) );

		$client_placeholders = implode( ',', array_fill( 0, count( $client_ids ), '%d' ) );

		if ( ! empty( $all_extra_ids ) ) {
			$extra_placeholders = implode( ',', array_fill( 0, count( $all_extra_ids ), '%d' ) );
			$status_clause      = "AND ( p.status = 'active' OR p.id IN ({$extra_placeholders}) )";
			$prepare_args       = array_merge( $client_ids, $all_extra_ids );
		} else {
			$status_clause = "AND p.status = 'active'";
			$prepare_args  = $client_ids;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.* FROM {$projects_table} p
				LEFT JOIN {$entries_table} e ON p.id = e.project_id
				WHERE p.client_id IN ({$client_placeholders})
				{$status_clause}
				GROUP BY p.id
				ORDER BY p.client_id ASC, MAX(e.entry_date) DESC, p.name ASC",
				$prepare_args
			)
		);

		// Group results by client_id.
		$grouped = array();
		foreach ( $rows as $project ) {
			$cid = (int) $project->client_id;
			if ( ! isset( $grouped[ $cid ] ) ) {
				$grouped[ $cid ] = array();
			}
			$grouped[ $cid ][] = $project;
		}

		return $grouped;
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

	/**
	 * Permanently delete a project.
	 *
	 * Only succeeds if the project has no time entries assigned to it.
	 *
	 * @param int $id Project ID.
	 * @return bool|WP_Error True on success, WP_Error if entries exist, false on DB failure.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$entries_table = PLTT_Database::get_table_name( 'time_entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entry_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$entries_table} WHERE project_id = %d", $id )
		);

		if ( $entry_count > 0 ) {
			return new WP_Error(
				'project_has_entries',
				sprintf(
					/* translators: %d: number of entries */
					_n(
						'Cannot delete project. Please delete or reassign its %d time entry first.',
						'Cannot delete project. Please delete or reassign its %d time entries first.',
						$entry_count,
						'plain-language-time-tracker'
					),
					$entry_count
				)
			);
		}

		// Clear stale alias references so the alias predictor does not suggest a deleted project.
		$aliases_table = PLTT_Database::get_table_name( 'aliases' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$aliases_table} SET project_id = NULL WHERE project_id = %d", $id )
		);

		$table = PLTT_Database::get_table_name( 'projects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}
}

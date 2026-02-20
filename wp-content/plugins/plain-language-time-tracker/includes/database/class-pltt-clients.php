<?php
/**
 * Client CRUD operations.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles client database operations.
 */
class PLTT_Clients {

	/**
	 * Get a single client by ID.
	 *
	 * @param int $id Client ID.
	 * @return object|null Client object or null.
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'clients' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	/**
	 * Get all clients.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of client objects.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'clients' );

		$defaults = array(
			'orderby' => 'name',
			'order'   => 'ASC',
			'search'  => '',
			'limit'   => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$sql = "SELECT * FROM {$table}";

		// Search filter.
		if ( ! empty( $args['search'] ) ) {
			$search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$sql   .= $wpdb->prepare( ' WHERE name LIKE %s', $search );
		}

		// Ordering.
		$orderby = in_array( $args['orderby'], array( 'id', 'name', 'created_at' ), true )
			? $args['orderby']
			: 'name';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$sql    .= " ORDER BY {$orderby} {$order}";

		// Limit.
		if ( $args['limit'] > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $args['limit'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql );
	}

	/**
	 * Create a new client.
	 *
	 * @param array $data Client data (name, description).
	 * @return int|false Insert ID or false on failure.
	 */
	public static function create( $data ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'clients' );

		$insert_data = array(
			'name'        => sanitize_text_field( $data['name'] ?? '' ),
			'description' => sanitize_textarea_field( $data['description'] ?? '' ),
		);

		$formats = array( '%s', '%s' );

		// Validate required field.
		if ( empty( $insert_data['name'] ) || '' === trim( $insert_data['name'] ) ) {
			return new WP_Error( 'missing_name', __( 'Client name is required.', 'plain-language-time-tracker' ) );
		}

		// Nullable: omit from array when NULL so MySQL uses column default.
		if ( isset( $data['hourly_rate'] ) && '' !== $data['hourly_rate'] ) {
			$rate = floatval( $data['hourly_rate'] );

			// Validate hourly rate bounds.
			if ( $rate < 0 || $rate > 10000 ) {
				return new WP_Error( 'invalid_rate', __( 'Hourly rate must be between $0 and $10,000.', 'plain-language-time-tracker' ) );
			}

			$insert_data['hourly_rate'] = $rate;
			$formats[]                  = '%f';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			$insert_data,
			$formats
		);

		if ( $result ) {
			pltt_flush_client_cache();
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Update a client.
	 *
	 * @param int   $id   Client ID.
	 * @param array $data Data to update.
	 * @return bool True on success.
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'clients' );

		$update_data = array();
		$formats     = array();
		$null_fields = array();

		if ( array_key_exists( 'name', $data ) ) {
			$name = sanitize_text_field( $data['name'] );

			// Validate required field.
			if ( empty( $name ) || '' === trim( $name ) ) {
				return new WP_Error( 'missing_name', __( 'Client name is required.', 'plain-language-time-tracker' ) );
			}

			$update_data['name'] = $name;
			$formats[]           = '%s';
		}

		if ( array_key_exists( 'description', $data ) ) {
			$update_data['description'] = sanitize_textarea_field( $data['description'] );
			$formats[]                  = '%s';
		}

		if ( array_key_exists( 'hourly_rate', $data ) ) {
			if ( '' !== $data['hourly_rate'] && null !== $data['hourly_rate'] ) {
				$rate = floatval( $data['hourly_rate'] );

				// Validate hourly rate bounds.
				if ( $rate < 0 || $rate > 10000 ) {
					return new WP_Error( 'invalid_rate', __( 'Hourly rate must be between $0 and $10,000.', 'plain-language-time-tracker' ) );
				}

				$update_data['hourly_rate'] = $rate;
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
			pltt_flush_client_cache();
		}

		return $result;
	}

	/**
	 * Delete a client.
	 *
	 * @param int $id Client ID.
	 * @return bool|WP_Error True on success, WP_Error if client has projects.
	 */
	public static function delete( $id ) {
		global $wpdb;

		// Check if client has any projects.
		$projects_table = PLTT_Database::get_table_name( 'projects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$project_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$projects_table} WHERE client_id = %d", $id )
		);

		if ( $project_count > 0 ) {
			return new WP_Error(
				'client_has_projects',
				sprintf(
					/* translators: %d: number of projects */
					_n(
						'Cannot delete client. Please delete or reassign its %d project first.',
						'Cannot delete client. Please delete or reassign its %d projects first.',
						$project_count,
						'plain-language-time-tracker'
					),
					$project_count
				)
			);
		}

		// Check if client has any direct time entries.
		$entries_table = PLTT_Database::get_table_name( 'time_entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entry_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$entries_table} WHERE client_id = %d", $id )
		);

		if ( $entry_count > 0 ) {
			return new WP_Error(
				'client_has_entries',
				sprintf(
					/* translators: %d: number of entries */
					_n(
						'Cannot delete client. Please delete or reassign its %d time entry first.',
						'Cannot delete client. Please delete or reassign its %d time entries first.',
						$entry_count,
						'plain-language-time-tracker'
					),
					$entry_count
				)
			);
		}

		$table = PLTT_Database::get_table_name( 'clients' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false !== $result ) {
			pltt_flush_client_cache();
			return true;
		}

		return false;
	}

	/**
	 * Get client by name (for alias matching).
	 *
	 * @param string $name Client name.
	 * @return object|null Client object or null.
	 */
	public static function get_by_name( $name ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'clients' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s", $name )
		);
	}

	/**
	 * Count total clients.
	 *
	 * @return int Client count.
	 */
	public static function count() {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'clients' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}

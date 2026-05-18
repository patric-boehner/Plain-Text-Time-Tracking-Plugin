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
	 * Get multiple clients by ID in a single query.
	 *
	 * @param int[] $ids Array of client IDs.
	 * @return array ID-keyed map of client objects (missing IDs are omitted).
	 */
	public static function get_multiple( $ids ) {
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return array();
		}

		global $wpdb;
		$table        = PLTT_Database::get_table_name( 'clients' );
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
			$rate       = floatval( $data['hourly_rate'] );
			$rate_valid = pltt_validate_hourly_rate( $rate );
			if ( is_wp_error( $rate_valid ) ) {
				return $rate_valid;
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

		// Clear stale alias references so the alias predictor does not suggest a deleted client.
		$aliases_table = PLTT_Database::get_table_name( 'aliases' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$aliases_table} SET client_id = NULL WHERE client_id = %d", $id )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false !== $result ) {
			return true;
		}

		return false;
	}

}

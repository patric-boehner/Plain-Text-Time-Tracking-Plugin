<?php
/**
 * Tag CRUD operations.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles tag database operations.
 */
class PLTT_Tags {

	/**
	 * Get a single tag by ID.
	 *
	 * @param int $id Tag ID.
	 * @return object|null Tag object or null.
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tags' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	/**
	 * Get a single tag by name.
	 *
	 * @param string $name Tag name (case-insensitive).
	 * @return object|null Tag object or null.
	 */
	public static function get_by_name( $name ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tags' );
		$name  = strtolower( trim( $name ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s", $name )
		);
	}

	/**
	 * Get all tags ordered by name.
	 *
	 * @return array Array of tag objects.
	 */
	public static function get_all() {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tags' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );
	}

	/**
	 * Get all tags with their entry usage counts.
	 *
	 * @return array Array of tag objects each with a `usage_count` property.
	 */
	public static function get_all_with_counts() {
		global $wpdb;
		$tags_table       = PLTT_Database::get_table_name( 'tags' );
		$entry_tags_table = PLTT_Database::get_table_name( 'entry_tags' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			"SELECT t.*, COUNT(et.entry_id) AS usage_count
			FROM {$tags_table} t
			LEFT JOIN {$entry_tags_table} et ON t.id = et.tag_id
			GROUP BY t.id
			ORDER BY t.name ASC"
		);
	}

	/**
	 * Create a new tag.
	 *
	 * @param string $name Tag name.
	 * @return int|false Insert ID or false on failure (including duplicate).
	 */
	public static function create( $name ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tags' );
		$name  = strtolower( trim( $name ) );

		if ( empty( $name ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array( 'name' => $name ),
			array( '%s' )
		);

		if ( $result ) {
			pltt_flush_tag_cache();
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Rename a tag.
	 *
	 * @param int    $id       Tag ID.
	 * @param string $new_name New tag name.
	 * @return bool True on success.
	 */
	public static function rename( $id, $new_name ) {
		global $wpdb;
		$table    = PLTT_Database::get_table_name( 'tags' );
		$new_name = strtolower( trim( $new_name ) );

		if ( empty( $new_name ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array( 'name' => $new_name ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			pltt_flush_tag_cache();
			return true;
		}

		return false;
	}

	/**
	 * Delete a tag and all its junction table rows.
	 *
	 * @param int $id Tag ID.
	 * @return bool True on success.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$tags_table       = PLTT_Database::get_table_name( 'tags' );
		$entry_tags_table = PLTT_Database::get_table_name( 'entry_tags' );

		// Delete junction rows first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $entry_tags_table, array( 'tag_id' => $id ), array( '%d' ) );

		// Delete the tag itself.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $tags_table, array( 'id' => $id ), array( '%d' ) );

		if ( false !== $result ) {
			pltt_flush_tag_cache();
			return true;
		}

		return false;
	}

	/**
	 * Get tag names for a single entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array Array of tag name strings.
	 */
	public static function get_for_entry( $entry_id ) {
		global $wpdb;
		$tags_table       = PLTT_Database::get_table_name( 'tags' );
		$entry_tags_table = PLTT_Database::get_table_name( 'entry_tags' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.name FROM {$tags_table} t
				INNER JOIN {$entry_tags_table} et ON t.id = et.tag_id
				WHERE et.entry_id = %d
				ORDER BY t.name ASC",
				$entry_id
			)
		);
	}

	/**
	 * Bulk-load tag names for multiple entries.
	 *
	 * @param int[] $entry_ids Array of entry IDs.
	 * @return array Map of entry_id => array of tag name strings.
	 */
	public static function get_for_entries( $entry_ids ) {
		global $wpdb;

		$entry_ids = array_map( 'intval', $entry_ids );
		$entry_ids = array_filter( $entry_ids );

		if ( empty( $entry_ids ) ) {
			return array();
		}

		$tags_table       = PLTT_Database::get_table_name( 'tags' );
		$entry_tags_table = PLTT_Database::get_table_name( 'entry_tags' );
		$placeholders     = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT et.entry_id, t.name FROM {$entry_tags_table} et
				INNER JOIN {$tags_table} t ON et.tag_id = t.id
				WHERE et.entry_id IN ({$placeholders})
				ORDER BY t.name ASC",
				$entry_ids
			)
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->entry_id ][] = $row->name;
		}

		return $map;
	}

	/**
	 * Sync tags for an entry: creates missing tags, replaces all junction rows.
	 *
	 * @param int          $entry_id  Entry ID.
	 * @param string|array $tag_names Array of tag name strings or CSV string.
	 * @return bool True on success, false if a DB operation failed.
	 */
	public static function sync_entry_tags( $entry_id, $tag_names ) {
		global $wpdb;
		$tags_table       = PLTT_Database::get_table_name( 'tags' );
		$entry_tags_table = PLTT_Database::get_table_name( 'entry_tags' );

		// Normalize to array of lowercase names.
		if ( ! is_array( $tag_names ) ) {
			$tag_names = explode( ',', $tag_names );
		}
		$tag_names = array_unique(
			array_filter(
				array_map(
					function( $n ) {
						return strtolower( trim( $n ) );
					},
					$tag_names
				)
			)
		);

		// Delete existing junction rows for this entry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $entry_tags_table, array( 'entry_id' => $entry_id ), array( '%d' ) );
		if ( false === $deleted ) {
			return false;
		}

		if ( empty( $tag_names ) ) {
			return true;
		}

		// Ensure all tags exist in the tags table.
		foreach ( $tag_names as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query(
				$wpdb->prepare( "INSERT IGNORE INTO {$tags_table} (name) VALUES (%s)", $name )
			);
		}

		// Build name → id map for only the tags we need.
		$placeholders = implode( ',', array_fill( 0, count( $tag_names ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$tag_rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT id, name FROM {$tags_table} WHERE name IN ({$placeholders})",
				$tag_names
			)
		);

		if ( null === $tag_rows ) {
			return false;
		}

		$tag_id_map = array();
		foreach ( $tag_rows as $row ) {
			$tag_id_map[ $row->name ] = (int) $row->id;
		}

		// Insert new junction rows.
		$success = true;
		foreach ( $tag_names as $name ) {
			if ( isset( $tag_id_map[ $name ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$r = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$entry_tags_table} (entry_id, tag_id) VALUES (%d, %d)",
						$entry_id,
						$tag_id_map[ $name ]
					)
				);
				if ( false === $r ) {
					$success = false;
				}
			}
		}

		pltt_flush_tag_cache();
		return $success;
	}

	/**
	 * Delete all tag associations for an entry.
	 *
	 * @param int $entry_id Entry ID.
	 */
	public static function delete_for_entry( $entry_id ) {
		global $wpdb;
		$entry_tags_table = PLTT_Database::get_table_name( 'entry_tags' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $entry_tags_table, array( 'entry_id' => $entry_id ), array( '%d' ) );
	}
}

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
	 * Normalize a group name. Empty/whitespace becomes NULL.
	 *
	 * @param string|null $group_name Raw group name.
	 * @return string|null Trimmed string clamped to 100 chars, or null.
	 */
	private static function normalize_group_name( $group_name ) {
		if ( null === $group_name ) {
			return null;
		}
		$group_name = trim( (string) $group_name );
		if ( '' === $group_name ) {
			return null;
		}
		return mb_substr( $group_name, 0, 100 );
	}

	/**
	 * Create a new tag.
	 *
	 * @param string      $name       Tag name.
	 * @param string|null $group_name Optional group name (NULL or '' means ungrouped).
	 * @return int|false Insert ID or false on failure (including duplicate).
	 */
	public static function create( $name, $group_name = null ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tags' );
		$name  = strtolower( trim( $name ) );

		if ( empty( $name ) ) {
			return false;
		}

		$group_name = self::normalize_group_name( $group_name );

		$data    = array( 'name' => $name );
		$formats = array( '%s' );
		if ( null !== $group_name ) {
			$data['group_name'] = $group_name;
			$formats[]          = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $table, $data, $formats );

		if ( $result ) {
			pltt_flush_tag_cache();
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Rename a tag. Optionally update the group at the same time.
	 *
	 * Pass `false` for $group_name to leave the existing group untouched.
	 * Pass `null` or empty string to clear the group.
	 *
	 * @param int              $id         Tag ID.
	 * @param string           $new_name   New tag name.
	 * @param string|null|bool $group_name Group name, '' / null to clear, or false to leave unchanged.
	 * @return bool True on success.
	 */
	public static function rename( $id, $new_name, $group_name = false ) {
		global $wpdb;
		$table    = PLTT_Database::get_table_name( 'tags' );
		$new_name = strtolower( trim( $new_name ) );

		if ( empty( $new_name ) ) {
			return false;
		}

		$data    = array( 'name' => $new_name );
		$formats = array( '%s' );
		if ( false !== $group_name ) {
			// Caller wants to set/clear the group. set_group() handles NULL writes
			// (wpdb->update can't write NULL via the value array).
			$normalized = self::normalize_group_name( $group_name );
			if ( null === $normalized ) {
				// Defer the NULL write to a separate call after the name update.
				$clear_group = true;
			} else {
				$data['group_name'] = $normalized;
				$formats[]          = '%s';
				$clear_group        = false;
			}
		} else {
			$clear_group = false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		if ( $clear_group ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET group_name = NULL WHERE id = %d", $id ) );
		}

		pltt_flush_tag_cache();
		return true;
	}

	/**
	 * Set the group for a single tag. Pass null or '' to clear.
	 *
	 * @param int         $id         Tag ID.
	 * @param string|null $group_name Group name, or null/empty to clear.
	 * @return bool True on success.
	 */
	public static function set_group( $id, $group_name ) {
		global $wpdb;
		$table      = PLTT_Database::get_table_name( 'tags' );
		$normalized = self::normalize_group_name( $group_name );

		if ( null === $normalized ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET group_name = NULL WHERE id = %d", $id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table,
				array( 'group_name' => $normalized ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		if ( false === $result ) {
			return false;
		}

		pltt_flush_tag_cache();
		return true;
	}

	/**
	 * Assign a group to many tags in one statement. Pass null/empty to remove from group.
	 *
	 * @param int[]       $tag_ids    Array of tag IDs.
	 * @param string|null $group_name Group name, or null/empty to clear.
	 * @return int Number of rows affected.
	 */
	public static function bulk_set_group( array $tag_ids, $group_name ) {
		global $wpdb;

		$tag_ids = array_filter( array_map( 'intval', $tag_ids ) );
		if ( empty( $tag_ids ) ) {
			return 0;
		}

		$table        = PLTT_Database::get_table_name( 'tags' );
		$placeholders = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
		$normalized   = self::normalize_group_name( $group_name );

		if ( null === $normalized ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"UPDATE {$table} SET group_name = NULL WHERE id IN ({$placeholders})",
					$tag_ids
				)
			);
		} else {
			$params = array_merge( array( $normalized ), $tag_ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"UPDATE {$table} SET group_name = %s WHERE id IN ({$placeholders})",
					$params
				)
			);
		}

		if ( false === $result ) {
			return 0;
		}

		pltt_flush_tag_cache();
		return (int) $result;
	}

	/**
	 * Get all distinct, non-empty group names in alphabetical order.
	 *
	 * @return string[] Array of group name strings.
	 */
	public static function get_all_groups() {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tags' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			"SELECT DISTINCT group_name FROM {$table} WHERE group_name IS NOT NULL AND group_name <> '' ORDER BY group_name ASC"
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build a map of tag name => group name for tags that have a group assigned.
	 *
	 * Used by the picker to render labeled sections.
	 *
	 * @return array Map of tag_name => group_name.
	 */
	public static function get_name_to_group_map() {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tags' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT name, group_name FROM {$table} WHERE group_name IS NOT NULL AND group_name <> ''"
		);

		$map = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$map[ $row->name ] = $row->group_name;
			}
		}
		return $map;
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

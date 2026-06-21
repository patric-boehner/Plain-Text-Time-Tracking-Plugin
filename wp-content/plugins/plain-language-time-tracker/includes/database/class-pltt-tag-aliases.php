<?php
/**
 * Tag-alias seeding (deterministic keyword -> tag prediction).
 *
 * Parallel to PLTT_Aliases but pointed at tags, and intentionally leaner: a
 * keyword maps to one tag with a use-count for the prune signal. No confidence
 * or learning machinery yet — the tag learner is deferred. Seeds let the parser
 * pre-fill tags so they aren't hand-set on every entry during processing.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles keyword->tag seed storage and matching.
 */
class PLTT_Tag_Aliases {

	/**
	 * Get a tag-alias row by ID.
	 *
	 * @param int $id Row ID.
	 * @return object|null Row or null.
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tag_aliases' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * Get a tag-alias row by keyword (case-insensitive).
	 *
	 * @param string $keyword Keyword text.
	 * @return object|null Row or null.
	 */
	public static function get_by_keyword( $keyword ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tag_aliases' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE LOWER(keyword) = LOWER(%s)", $keyword )
		);
	}

	/**
	 * Get all tag-alias rows.
	 *
	 * @return array Row objects.
	 */
	public static function get_all() {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tag_aliases' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$table}" );
	}

	/**
	 * Get the keyword seeds bound to a tag (for the Tags-page chip manager).
	 *
	 * @param int $tag_id Tag ID.
	 * @return array Row objects, most-used first.
	 */
	public static function get_for_tag( $tag_id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tag_aliases' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE tag_id = %d ORDER BY use_count DESC, keyword ASC",
				absint( $tag_id )
			)
		);
	}

	/**
	 * Seed (or repoint) a keyword -> tag mapping.
	 *
	 * Idempotent on keyword (UNIQUE): an existing keyword is repointed to the
	 * given tag rather than duplicated.
	 *
	 * @param string $keyword Keyword text.
	 * @param int    $tag_id  Tag to map to.
	 * @return int|false Row ID or false on failure.
	 */
	public static function seed( $keyword, $tag_id ) {
		global $wpdb;
		$table   = PLTT_Database::get_table_name( 'tag_aliases' );
		$keyword = sanitize_text_field( $keyword );
		$tag_id  = absint( $tag_id );

		if ( '' === $keyword || ! $tag_id ) {
			return false;
		}

		$existing = self::get_by_keyword( $keyword );

		if ( $existing ) {
			if ( (int) $existing->tag_id !== $tag_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update( $table, array( 'tag_id' => $tag_id ), array( 'id' => $existing->id ), array( '%d' ), array( '%d' ) );
			}
			return (int) $existing->id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'keyword'   => $keyword,
				'tag_id'    => $tag_id,
				'use_count' => 0,
				'last_used' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s' )
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Delete a tag-alias (chip-manager prune).
	 *
	 * @param int $id Row ID.
	 * @return int|false Rows deleted or false.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tag_aliases' );
		$id    = absint( $id );

		if ( ! $id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Increment a tag-alias's use-count (prune signal).
	 *
	 * @param int $id Row ID.
	 */
	public static function record_usage( $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'tag_aliases' );
		$id    = absint( $id );

		if ( ! $id ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET use_count = use_count + 1, last_used = %s WHERE id = %d",
				current_time( 'mysql' ),
				$id
			)
		);
	}

	/**
	 * Match seeded keywords in text and return the rows that fire.
	 *
	 * Word-boundary, case-insensitive. Pass pre-loaded rows (from get_all()) to
	 * avoid a DB query per entry when parsing a whole log.
	 *
	 * @param string     $text Text to scan.
	 * @param array|null $rows Pre-loaded tag-alias rows, or null to load.
	 * @return array Matched row objects.
	 */
	public static function match( $text, $rows = null ) {
		if ( null === $rows ) {
			$rows = self::get_all();
		}

		$matches = array();
		foreach ( $rows as $row ) {
			$pattern = '/\b' . preg_quote( $row->keyword, '/' ) . '\b/i';
			if ( preg_match( $pattern, $text ) ) {
				$matches[] = $row;
			}
		}

		return $matches;
	}
}

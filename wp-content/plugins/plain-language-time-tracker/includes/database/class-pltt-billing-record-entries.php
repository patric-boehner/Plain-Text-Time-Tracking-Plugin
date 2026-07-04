<?php
/**
 * Billing record → entry coverage snapshot.
 *
 * A join table frozen at commit: the exact set of time entries a finalized
 * billing record captured. This is what makes entry billing status immutable
 * — "Invoiced" is read from these rows, never recomputed from live date/
 * threshold math, so a late-logged entry inside an already-billed window
 * correctly stays Unbilled instead of being retroactively swept in.
 *
 * What a record captures, by type (computed once, in PLTT_Billing::commit()):
 *   - hourly: the billable+verified entries in the billed window, minus any
 *     the user excluded.
 *   - retainer_overage: the period's overage entries (those past the threshold).
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for the billing_record_entries coverage snapshot.
 */
class PLTT_Billing_Record_Entries {

	/**
	 * Freeze a record's covered entries.
	 *
	 * Idempotent-ish: an empty set is a no-op success (e.g. a retainer record
	 * whose period had no overage entries — still a valid record).
	 *
	 * @param int   $record_id  The billing record id.
	 * @param int[] $entry_ids  Entry ids the record covers.
	 * @return bool False on DB failure.
	 */
	public static function insert_many( $record_id, array $entry_ids ) {
		global $wpdb;

		$record_id = (int) $record_id;
		$entry_ids = array_values( array_unique( array_filter( array_map( 'intval', $entry_ids ) ) ) );

		if ( $record_id <= 0 || empty( $entry_ids ) ) {
			return true;
		}

		$table       = PLTT_Database::get_table_name( 'billing_record_entries' );
		$placeholders = array();
		$values       = array();
		foreach ( $entry_ids as $entry_id ) {
			$placeholders[] = '(%d, %d)';
			$values[]       = $record_id;
			$values[]       = $entry_id;
		}

		$sql = "INSERT INTO {$table} (record_id, entry_id) VALUES " . implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return false !== $result;
	}

	/**
	 * Covered entries for one project: entry_id => covering record_id.
	 *
	 * Joins through billing_records so it reflects exactly the project's
	 * finalized records.
	 *
	 * @param int $project_id Project id.
	 * @return array<int,int> entry_id => record_id.
	 */
	public static function get_covered_for_project( $project_id ) {
		global $wpdb;

		$bre = PLTT_Database::get_table_name( 'billing_record_entries' );
		$br  = PLTT_Database::get_table_name( 'billing_records' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bre.entry_id, bre.record_id
				FROM {$bre} bre
				INNER JOIN {$br} br ON br.id = bre.record_id
				WHERE br.project_id = %d",
				(int) $project_id
			)
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->entry_id ] = (int) $row->record_id;
		}
		return $map;
	}

	/**
	 * Every covered entry id, across all projects — the global "what's invoiced"
	 * set (used by the unbilled-outside-range notice). Flat, deduplicated.
	 *
	 * @return int[]
	 */
	public static function get_all_covered_entry_ids() {
		global $wpdb;
		$bre = PLTT_Database::get_table_name( 'billing_record_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( "SELECT DISTINCT entry_id FROM {$bre}" );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Remove a record's coverage rows (for the rare case a record is deleted).
	 *
	 * @param int $record_id Billing record id.
	 * @return void
	 */
	public static function delete_for_record( $record_id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'billing_record_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'record_id' => (int) $record_id ), array( '%d' ) );
	}
}

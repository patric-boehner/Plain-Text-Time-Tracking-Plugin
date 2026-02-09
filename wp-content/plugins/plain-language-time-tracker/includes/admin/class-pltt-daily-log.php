<?php
/**
 * Daily Log screen.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Daily Log admin screen.
 */
class PLTT_Daily_Log {

	/**
	 * Render the Daily Log screen.
	 */
	public static function render() {
		$date = isset( $_GET['date'] ) ? pltt_sanitize_date( wp_unslash( $_GET['date'] ) ) : pltt_get_current_date();
		$log  = self::get_log( $date );

		include PLTT_PLUGIN_DIR . 'templates/daily-log.php';
	}

	/**
	 * Get the daily log content for a date.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return object|null Log object or null.
	 */
	public static function get_log( $date ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'daily_logs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE log_date = %s", $date )
		);
	}

	/**
	 * Save the daily log content.
	 *
	 * @param string $date    Date in Y-m-d format.
	 * @param string $content Log content.
	 * @return bool True on success.
	 */
	public static function save_log( $date, $content ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'daily_logs' );

		$existing = self::get_log( $date );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table,
				array(
					'content'   => $content,
					'processed' => 0,
				),
				array( 'log_date' => $date ),
				array( '%s', '%d' ),
				array( '%s' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert(
				$table,
				array(
					'log_date'  => $date,
					'content'   => $content,
					'processed' => 0,
				),
				array( '%s', '%s', '%d' )
			);
		}

		return false !== $result;
	}

	/**
	 * Delete a daily log record.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return bool True on success.
	 */
	public static function delete_log( $date ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'daily_logs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'log_date' => $date ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Mark a log as processed.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return bool True on success.
	 */
	public static function mark_processed( $date ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'daily_logs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array( 'processed' => 1 ),
			array( 'log_date' => $date ),
			array( '%d' ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Get all daily logs with entry counts and total minutes.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $month  Month filter in YYYY-MM format.
	 *     @type int    $limit  Number of results.
	 *     @type int    $offset Offset for pagination.
	 * }
	 * @return array Array of log objects.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;
		$logs_table    = PLTT_Database::get_table_name( 'daily_logs' );
		$entries_table = PLTT_Database::get_table_name( 'time_entries' );

		$where  = '';
		$values = array();

		if ( ! empty( $args['month'] ) ) {
			$where    = 'WHERE dl.log_date LIKE %s';
			$values[] = $args['month'] . '%';
		}

		$limit  = isset( $args['limit'] ) ? absint( $args['limit'] ) : 20;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$sql = "SELECT dl.log_date, dl.content, dl.processed,
					COUNT(te.id) AS entry_count,
					COALESCE(SUM(te.duration_minutes), 0) AS total_minutes
				FROM {$logs_table} dl
				LEFT JOIN {$entries_table} te ON dl.log_date = te.entry_date
				{$where}
				GROUP BY dl.id, dl.log_date, dl.content, dl.processed
				ORDER BY dl.log_date DESC
				LIMIT %d OFFSET %d";

		$values[] = $limit;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Count all daily logs.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $month Month filter in YYYY-MM format.
	 * }
	 * @return int Total count.
	 */
	public static function count_all( $args = array() ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'daily_logs' );

		$where  = '';
		$values = array();

		if ( ! empty( $args['month'] ) ) {
			$where    = 'WHERE log_date LIKE %s';
			$values[] = $args['month'] . '%';
		}

		$sql = "SELECT COUNT(*) FROM {$table} {$where}";

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get distinct months that have daily logs.
	 *
	 * @return array Array of month strings in YYYY-MM format.
	 */
	public static function get_logged_months() {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'daily_logs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col(
			"SELECT DISTINCT DATE_FORMAT(log_date, '%Y-%m') AS month FROM {$table} ORDER BY month DESC"
		);
	}

	/**
	 * Get the previous date that has a log.
	 *
	 * @param string $date Current date.
	 * @return string|null Previous date or null.
	 */
	public static function get_previous_date( $date ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'daily_logs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT log_date FROM {$table} WHERE log_date < %s ORDER BY log_date DESC LIMIT 1",
				$date
			)
		);
	}

	/**
	 * Get the next date that has a log.
	 *
	 * @param string $date Current date.
	 * @return string|null Next date or null.
	 */
	public static function get_next_date( $date ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'daily_logs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT log_date FROM {$table} WHERE log_date > %s ORDER BY log_date ASC LIMIT 1",
				$date
			)
		);
	}
}

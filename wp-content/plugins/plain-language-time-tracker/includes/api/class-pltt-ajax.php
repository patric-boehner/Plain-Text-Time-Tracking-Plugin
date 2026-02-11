<?php
/**
 * AJAX handlers.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all AJAX requests.
 */
class PLTT_Ajax {

	/**
	 * Initialize AJAX hooks.
	 */
	public static function init() {
		// Daily log operations.
		add_action( 'wp_ajax_pltt_save_daily_log', array( __CLASS__, 'save_daily_log' ) );
		add_action( 'wp_ajax_pltt_process_log', array( __CLASS__, 'process_log' ) );
		add_action( 'wp_ajax_pltt_delete_daily_log', array( __CLASS__, 'delete_daily_log' ) );

		// Entry operations.
		add_action( 'wp_ajax_pltt_delete_entry', array( __CLASS__, 'delete_entry' ) );

		// Client operations (create only - used from review screen modals).
		add_action( 'wp_ajax_pltt_create_client', array( __CLASS__, 'create_client' ) );

		// Project operations (create and get - used from review screen).
		add_action( 'wp_ajax_pltt_get_projects', array( __CLASS__, 'get_projects' ) );
		add_action( 'wp_ajax_pltt_create_project', array( __CLASS__, 'create_project' ) );

	}

	/**
	 * Verify AJAX request.
	 *
	 * @return bool True if valid.
	 */
	private static function verify_request() {
		if ( ! check_ajax_referer( 'pltt_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid security token.', 'plain-language-time-tracker' ), 403 );
			return false;
		}

		if ( ! pltt_user_can_access() ) {
			wp_send_json_error( __( 'You do not have permission to perform this action.', 'plain-language-time-tracker' ), 403 );
			return false;
		}

		return true;
	}

	/**
	 * Save daily log content.
	 */
	public static function save_daily_log() {
		self::verify_request();

		$date    = isset( $_POST['date'] ) ? pltt_sanitize_date( wp_unslash( $_POST['date'] ) ) : '';
		$content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

		if ( empty( $date ) ) {
			wp_send_json_error( __( 'Invalid date.', 'plain-language-time-tracker' ) );
		}

		$result = PLTT_Daily_Log::save_log( $date, $content );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Saved', 'plain-language-time-tracker' ) ) );
		} else {
			wp_send_json_error( __( 'Failed to save.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Process log and return parsed entries.
	 */
	public static function process_log() {
		self::verify_request();

		$date    = isset( $_POST['date'] ) ? pltt_sanitize_date( wp_unslash( $_POST['date'] ) ) : '';
		$content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

		if ( empty( $date ) ) {
			wp_send_json_error( __( 'Invalid date.', 'plain-language-time-tracker' ) );
		}

		// Save the log first.
		PLTT_Daily_Log::save_log( $date, $content );

		// Parse entries.
		$entries = PLTT_Time_Parser::parse_log( $content, $date );

		if ( empty( $entries ) ) {
			wp_send_json_error( __( 'No valid time entries found.', 'plain-language-time-tracker' ) );
		}

		// Validate entries.
		$validation = PLTT_Time_Parser::validate( $entries );

		// Delete existing entries and create new ones in a transaction
		// so a failure mid-loop doesn't leave partial data.
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		PLTT_Entries::delete_by_date( $date );

		$all_created = true;

		// Only client is predicted; project is chosen by user on the review screen.
		foreach ( $entries as $entry ) {
			$result = PLTT_Entries::create(
				array(
					'entry_date'       => $date,
					'start_time'       => $entry['start_time'] ?? '',
					'end_time'         => $entry['end_time'] ?? '',
					'duration_minutes' => $entry['duration_minutes'] ?? 0,
					'raw_text'         => $entry['raw_text'] ?? '',
					'description'      => $entry['description'] ?? '',
					'client_id'        => $entry['predicted_client_id'] ?? null,
					'tags'             => $entry['tags'] ?? '',
				)
			);

			if ( false === $result ) {
				$all_created = false;
				break;
			}
		}

		if ( $all_created ) {
			$wpdb->query( 'COMMIT' );
			PLTT_Daily_Log::mark_processed( $date );
		} else {
			$wpdb->query( 'ROLLBACK' );
			wp_send_json_error( __( 'Error saving entries. No changes were made.', 'plain-language-time-tracker' ) );
		}

		wp_send_json_success(
			array(
				'entries'    => $entries,
				'validation' => $validation,
				'redirect'   => pltt_get_admin_url( 'review', array( 'date' => $date ) ),
			)
		);
	}

	/**
	 * Delete a time entry.
	 */
	public static function delete_entry() {
		self::verify_request();

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;

		if ( empty( $entry_id ) ) {
			wp_send_json_error( __( 'Invalid entry ID.', 'plain-language-time-tracker' ) );
		}

		$result = PLTT_Entries::delete( $entry_id );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Entry deleted.', 'plain-language-time-tracker' ) ) );
		} else {
			wp_send_json_error( __( 'Failed to delete entry.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Create a new client.
	 */
	public static function create_client() {
		self::verify_request();

		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$hourly_rate = isset( $_POST['hourly_rate'] ) ? wp_unslash( $_POST['hourly_rate'] ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( __( 'Client name is required.', 'plain-language-time-tracker' ) );
		}

		$client_data = array(
			'name'        => $name,
			'description' => $description,
		);
		if ( '' !== $hourly_rate ) {
			$client_data['hourly_rate'] = $hourly_rate;
		}

		$client_id = PLTT_Clients::create( $client_data );

		if ( $client_id ) {
			$client = PLTT_Clients::get( $client_id );
			wp_send_json_success(
				array(
					'client'  => $client,
					'message' => __( 'Client created.', 'plain-language-time-tracker' ),
				)
			);
		} else {
			wp_send_json_error( __( 'Failed to create client.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Get projects for a client.
	 */
	public static function get_projects() {
		self::verify_request();

		$client_id          = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$current_project_id = isset( $_POST['current_project_id'] ) ? absint( $_POST['current_project_id'] ) : 0;

		$include_ids = $current_project_id > 0 ? array( $current_project_id ) : array();
		$projects    = PLTT_Projects::get_by_client_recent_first( $client_id, $include_ids );

		wp_send_json_success(
			array(
				'projects' => $projects,
			)
		);
	}

	/**
	 * Create a new project.
	 */
	public static function create_project() {
		self::verify_request();

		$client_id   = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$hourly_rate = isset( $_POST['hourly_rate'] ) ? wp_unslash( $_POST['hourly_rate'] ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( __( 'Project name is required.', 'plain-language-time-tracker' ) );
		}

		if ( empty( $client_id ) ) {
			wp_send_json_error( __( 'Client is required.', 'plain-language-time-tracker' ) );
		}

		$project_data = array(
			'client_id'   => $client_id,
			'name'        => $name,
			'description' => $description,
		);
		if ( '' !== $hourly_rate ) {
			$project_data['hourly_rate'] = $hourly_rate;
		}

		$project_id = PLTT_Projects::create( $project_data );

		if ( $project_id ) {
			$project = PLTT_Projects::get( $project_id );
			wp_send_json_success(
				array(
					'project' => $project,
					'message' => __( 'Project created.', 'plain-language-time-tracker' ),
				)
			);
		} else {
			wp_send_json_error( __( 'Failed to create project.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Delete a daily log and its associated time entries.
	 */
	public static function delete_daily_log() {
		self::verify_request();

		$log_date = isset( $_POST['log_date'] ) ? pltt_sanitize_date( wp_unslash( $_POST['log_date'] ) ) : '';

		if ( empty( $log_date ) ) {
			wp_send_json_error( __( 'Invalid date.', 'plain-language-time-tracker' ) );
		}

		// Delete all time entries for this date first.
		$deleted_entries = PLTT_Entries::delete_by_date( $log_date );

		// Delete the daily log record.
		$result = PLTT_Daily_Log::delete_log( $log_date );

		if ( $result ) {
			wp_send_json_success(
				array(
					'message'         => __( 'Log deleted.', 'plain-language-time-tracker' ),
					'deleted_entries' => is_numeric( $deleted_entries ) ? (int) $deleted_entries : 0,
				)
			);
		} else {
			wp_send_json_error( __( 'Failed to delete log.', 'plain-language-time-tracker' ) );
		}
	}

}

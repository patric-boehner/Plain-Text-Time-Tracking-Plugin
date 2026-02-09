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
		add_action( 'wp_ajax_pltt_get_daily_log', array( __CLASS__, 'get_daily_log' ) );
		add_action( 'wp_ajax_pltt_process_log', array( __CLASS__, 'process_log' ) );
		add_action( 'wp_ajax_pltt_delete_daily_log', array( __CLASS__, 'delete_daily_log' ) );

		// Entry operations.
		add_action( 'wp_ajax_pltt_save_entries', array( __CLASS__, 'save_entries' ) );
		add_action( 'wp_ajax_pltt_update_entry', array( __CLASS__, 'update_entry' ) );
		add_action( 'wp_ajax_pltt_delete_entry', array( __CLASS__, 'delete_entry' ) );

		// Client operations.
		add_action( 'wp_ajax_pltt_get_clients', array( __CLASS__, 'get_clients' ) );
		add_action( 'wp_ajax_pltt_create_client', array( __CLASS__, 'create_client' ) );
		add_action( 'wp_ajax_pltt_update_client', array( __CLASS__, 'update_client' ) );
		add_action( 'wp_ajax_pltt_delete_client', array( __CLASS__, 'delete_client' ) );

		// Project operations.
		add_action( 'wp_ajax_pltt_get_projects', array( __CLASS__, 'get_projects' ) );
		add_action( 'wp_ajax_pltt_create_project', array( __CLASS__, 'create_project' ) );
		add_action( 'wp_ajax_pltt_update_project', array( __CLASS__, 'update_project' ) );
		add_action( 'wp_ajax_pltt_delete_project', array( __CLASS__, 'delete_project' ) );

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
	 * Get daily log content.
	 */
	public static function get_daily_log() {
		self::verify_request();

		$date = isset( $_POST['date'] ) ? pltt_sanitize_date( wp_unslash( $_POST['date'] ) ) : '';

		if ( empty( $date ) ) {
			wp_send_json_error( __( 'Invalid date.', 'plain-language-time-tracker' ) );
		}

		$log = PLTT_Daily_Log::get_log( $date );

		wp_send_json_success(
			array(
				'content'   => $log ? $log->content : '',
				'processed' => $log ? (bool) $log->processed : false,
			)
		);
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
	 * Save verified entries.
	 */
	public static function save_entries() {
		self::verify_request();

		$date    = isset( $_POST['date'] ) ? pltt_sanitize_date( wp_unslash( $_POST['date'] ) ) : '';
		$entries = isset( $_POST['entries'] ) ? json_decode( wp_unslash( $_POST['entries'] ), true ) : array();

		if ( empty( $date ) ) {
			wp_send_json_error( __( 'Invalid date.', 'plain-language-time-tracker' ) );
		}

		if ( empty( $entries ) || ! is_array( $entries ) ) {
			wp_send_json_error( __( 'No entries to save.', 'plain-language-time-tracker' ) );
		}

		$result = PLTT_Review::save_entries( $date, $entries );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result['message'] );
		}
	}

	/**
	 * Update a single time entry.
	 */
	public static function update_entry() {
		self::verify_request();

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;

		if ( empty( $entry_id ) ) {
			wp_send_json_error( __( 'Invalid entry ID.', 'plain-language-time-tracker' ) );
		}

		$existing = PLTT_Entries::get( $entry_id );
		if ( ! $existing ) {
			wp_send_json_error( __( 'Entry not found.', 'plain-language-time-tracker' ) );
		}

		$data = array();

		if ( isset( $_POST['entry_date'] ) ) {
			$data['entry_date'] = pltt_sanitize_date( wp_unslash( $_POST['entry_date'] ) );
		}
		if ( isset( $_POST['description'] ) ) {
			$data['description'] = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
		}
		if ( isset( $_POST['start_time'] ) ) {
			$data['start_time'] = sanitize_text_field( wp_unslash( $_POST['start_time'] ) );
		}
		if ( isset( $_POST['end_time'] ) ) {
			$data['end_time'] = sanitize_text_field( wp_unslash( $_POST['end_time'] ) );
		}
		if ( isset( $_POST['duration_minutes'] ) ) {
			$data['duration_minutes'] = absint( $_POST['duration_minutes'] );
		}
		if ( isset( $_POST['client_id'] ) ) {
			$data['client_id'] = absint( $_POST['client_id'] );
		}
		if ( isset( $_POST['project_id'] ) ) {
			$data['project_id'] = absint( $_POST['project_id'] );
		}
		if ( isset( $_POST['tags'] ) ) {
			$data['tags'] = sanitize_text_field( wp_unslash( $_POST['tags'] ) );
		}
		if ( isset( $_POST['billable'] ) ) {
			$data['billable'] = absint( $_POST['billable'] );
		}

		$result = PLTT_Entries::update( $entry_id, $data );

		if ( $result ) {
			// Return updated entry with resolved names for UI refresh.
			$updated = PLTT_Entries::get( $entry_id );

			$client_name  = '';
			$project_name = '';
			if ( ! empty( $updated->client_id ) ) {
				$client = PLTT_Clients::get( $updated->client_id );
				$client_name = $client ? $client->name : '';
			}
			if ( ! empty( $updated->project_id ) ) {
				$project = PLTT_Projects::get( $updated->project_id );
				$project_name = $project ? $project->name : '';
			}

			wp_send_json_success(
				array(
					'message'      => __( 'Entry updated.', 'plain-language-time-tracker' ),
					'entry'        => $updated,
					'client_name'  => $client_name,
					'project_name' => $project_name,
				)
			);
		} else {
			wp_send_json_error( __( 'Failed to update entry.', 'plain-language-time-tracker' ) );
		}
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
	 * Get all clients.
	 */
	public static function get_clients() {
		self::verify_request();

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$clients = PLTT_Clients::get_all(
			array(
				'search'  => $search,
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);

		wp_send_json_success( array( 'clients' => $clients ) );
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
	 * Update a client.
	 */
	public static function update_client() {
		self::verify_request();

		$client_id   = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( empty( $client_id ) ) {
			wp_send_json_error( __( 'Invalid client ID.', 'plain-language-time-tracker' ) );
		}

		$update_data = array(
			'name'        => $name,
			'description' => $description,
		);

		// Pass hourly_rate through — empty string clears it to NULL.
		if ( isset( $_POST['hourly_rate'] ) ) {
			$update_data['hourly_rate'] = wp_unslash( $_POST['hourly_rate'] );
		}

		$result = PLTT_Clients::update( $client_id, $update_data );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Client updated.', 'plain-language-time-tracker' ) ) );
		} else {
			wp_send_json_error( __( 'Failed to update client.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Delete a client.
	 */
	public static function delete_client() {
		self::verify_request();

		$client_id = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;

		if ( empty( $client_id ) ) {
			wp_send_json_error( __( 'Invalid client ID.', 'plain-language-time-tracker' ) );
		}

		$result = PLTT_Clients::delete( $client_id );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Client deleted.', 'plain-language-time-tracker' ) ) );
		} else {
			wp_send_json_error( __( 'Failed to delete client.', 'plain-language-time-tracker' ) );
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
	 * Update a project.
	 */
	public static function update_project() {
		self::verify_request();

		$project_id  = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$status      = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( empty( $project_id ) ) {
			wp_send_json_error( __( 'Invalid project ID.', 'plain-language-time-tracker' ) );
		}

		$data = array();
		if ( ! empty( $name ) ) {
			$data['name'] = $name;
		}
		if ( ! empty( $status ) ) {
			$data['status'] = $status;
		}
		if ( isset( $_POST['description'] ) ) {
			$data['description'] = $description;
		}
		if ( isset( $_POST['hourly_rate'] ) ) {
			$data['hourly_rate'] = wp_unslash( $_POST['hourly_rate'] );
		}

		$result = PLTT_Projects::update( $project_id, $data );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Project updated.', 'plain-language-time-tracker' ) ) );
		} else {
			wp_send_json_error( __( 'Failed to update project.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Delete a project.
	 */
	public static function delete_project() {
		self::verify_request();

		$project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

		if ( empty( $project_id ) ) {
			wp_send_json_error( __( 'Invalid project ID.', 'plain-language-time-tracker' ) );
		}

		$result = PLTT_Projects::delete( $project_id );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Project deleted.', 'plain-language-time-tracker' ) ) );
		} else {
			wp_send_json_error( __( 'Failed to delete project.', 'plain-language-time-tracker' ) );
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

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
		add_action( 'wp_ajax_pltt_update_daily_log', array( __CLASS__, 'update_daily_log' ) );
		add_action( 'wp_ajax_pltt_process_log', array( __CLASS__, 'process_log' ) );
		add_action( 'wp_ajax_pltt_delete_daily_log', array( __CLASS__, 'delete_daily_log' ) );

		// Entry operations.
		add_action( 'wp_ajax_pltt_delete_entry', array( __CLASS__, 'delete_entry' ) );
		add_action( 'wp_ajax_pltt_update_entry_field', array( __CLASS__, 'update_entry_field' ) );
		add_action( 'wp_ajax_pltt_save_entry', array( __CLASS__, 'save_entry' ) );

		// Client operations (create only - used from review screen modals).
		add_action( 'wp_ajax_pltt_create_client', array( __CLASS__, 'create_client' ) );

		// Project operations (create and get - used from review screen).
		add_action( 'wp_ajax_pltt_get_projects', array( __CLASS__, 'get_projects' ) );
		add_action( 'wp_ajax_pltt_create_project', array( __CLASS__, 'create_project' ) );

		// Tag operations (create - used from review screen modal).
		add_action( 'wp_ajax_pltt_create_tag', array( __CLASS__, 'create_tag' ) );

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
		if ( ! self::verify_request() ) {
			return;
		}

		$date    = isset( $_POST['date'] ) ? pltt_sanitize_date( wp_unslash( $_POST['date'] ) ) : '';
		$content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

		if ( empty( $date ) ) {
			wp_send_json_error( __( 'Invalid date.', 'plain-language-time-tracker' ) );
			return;
		}

		$result = PLTT_Daily_Log::save_log( $date, $content );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Saved', 'plain-language-time-tracker' ) ) );
		} else {
			wp_send_json_error( __( 'Failed to save.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Update daily log content (preserves processed state).
	 */
	public static function update_daily_log() {
		if ( ! self::verify_request() ) {
			return;
		}

		$date    = isset( $_POST['date'] ) ? pltt_sanitize_date( wp_unslash( $_POST['date'] ) ) : '';
		$content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

		if ( empty( $date ) ) {
			wp_send_json_error( __( 'Invalid date.', 'plain-language-time-tracker' ) );
			return;
		}

		$result = PLTT_Daily_Log::save_log( $date, $content, true ); // Preserve processed.

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Notes updated', 'plain-language-time-tracker' ) ) );
		} else {
			wp_send_json_error( __( 'Failed to update.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Process log and return parsed entries.
	 */
	public static function process_log() {
		if ( ! self::verify_request() ) {
			return;
		}

		// SEC-M3: Use the strict sanitizer here — silently falling back to today
		// would cause an invalid/forged date to wipe today's entries.
		$date    = isset( $_POST['date'] ) ? pltt_sanitize_date_strict( wp_unslash( $_POST['date'] ) ) : '';
		$content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

		if ( empty( $date ) ) {
			wp_send_json_error( __( 'Invalid date.', 'plain-language-time-tracker' ) );
			return;
		}

		// Save the log first.
		PLTT_Daily_Log::save_log( $date, $content );

		// Parse entries.
		$entries = PLTT_Time_Parser::parse_log( $content, $date );

		if ( empty( $entries ) ) {
			wp_send_json_error( __( 'No valid time entries found.', 'plain-language-time-tracker' ) );
			return;
		}

		// Validate entries.
		$validation = PLTT_Time_Parser::validate( $entries );

		// Delete existing entries and create new ones in a transaction
		// so a failure mid-loop doesn't leave partial data. PLTT_Entries::create()
		// participates in this same nesting-aware transaction (TRC-DB23).
		PLTT_Database::begin_transaction();

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
				// SEC-M4: Log only non-sensitive fields. raw_text/description may
				// contain client-confidential work descriptions.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'PLTT: Entry creation failed for date %s. start=%s end=%s', $date, $entry['start_time'] ?? '?', $entry['end_time'] ?? '?' ) );
				break;
			}
		}

		if ( $all_created ) {
			PLTT_Database::commit_transaction();
			PLTT_Daily_Log::mark_processed( $date );
		} else {
			PLTT_Database::rollback_transaction();
			wp_send_json_error( __( 'Error saving entries. No changes were made.', 'plain-language-time-tracker' ) );
			return;
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
	 * Update a single field on a time entry (billable, billed, or tags).
	 *
	 * Used by the inline editing controls on the Reports page.
	 */
	public static function update_entry_field() {
		if ( ! self::verify_request() ) {
			return;
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$field    = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$value    = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

		if ( empty( $entry_id ) ) {
			wp_send_json_error( __( 'Invalid entry ID.', 'plain-language-time-tracker' ) );
			return;
		}

		$allowed_fields = array( 'billable', 'billed', 'tags' );
		if ( ! in_array( $field, $allowed_fields, true ) ) {
			wp_send_json_error( __( 'Invalid field.', 'plain-language-time-tracker' ) );
			return;
		}

		if ( 'tags' === $field ) {
			$tag_names = '' !== $value ? explode( ',', $value ) : array();
			$result    = PLTT_Tags::sync_entry_tags( $entry_id, $tag_names );
			if ( false === $result ) {
				wp_send_json_error( __( 'Failed to update tags.', 'plain-language-time-tracker' ) );
				return;
			}
		} elseif ( 'billable' === $field ) {
			$int_value = (int) $value;

			// SEC-H3: Ensure value is strictly 0 or 1.
			if ( ! in_array( $int_value, array( 0, 1 ), true ) ) {
				wp_send_json_error( __( 'Invalid value.', 'plain-language-time-tracker' ) );
				return;
			}

			// SEC-M6/TRC-7: wrap the read-compute-write in a transaction and route
			// through PLTT_Entries::update() (which joins this same nesting-aware
			// transaction) so the duration_minutes value used to compute
			// billable_amount can't drift between read and write.
			PLTT_Database::begin_transaction();

			$update_data = array( 'billable' => $int_value );

			if ( 1 === $int_value ) {
				// Recalculate rate and amount when marking as billable.
				$entry = PLTT_Entries::get( $entry_id );
				if ( $entry && $entry->duration_minutes > 0 ) {
					$hourly_rate                    = pltt_resolve_billable_rate( (int) $entry->client_id, (int) $entry->project_id );
					$update_data['billable_rate']   = $hourly_rate;
					$update_data['billable_amount'] = pltt_billable_amount( $entry->duration_minutes, $hourly_rate );
				}
			} else {
				// Reset rate and amount when marking as non-billable.
				$update_data['billable_rate']   = 0.00;
				$update_data['billable_amount'] = 0.00;
			}

			$result = PLTT_Entries::update( $entry_id, $update_data );
			if ( $result ) {
				PLTT_Database::commit_transaction();
			} else {
				PLTT_Database::rollback_transaction();
			}

			if ( ! $result ) {
				wp_send_json_error( __( 'Failed to update entry.', 'plain-language-time-tracker' ) );
				return;
			}

			wp_send_json_success( array(
				'message'         => __( 'Saved.', 'plain-language-time-tracker' ),
				'billable_amount' => $update_data['billable_amount'] ?? 0.0,
			) );
			return;
		} else {
			// 'billed' field — SEC-H3: Ensure value is strictly 0 or 1.
			$int_value = (int) $value;
			if ( ! in_array( $int_value, array( 0, 1 ), true ) ) {
				wp_send_json_error( __( 'Invalid value.', 'plain-language-time-tracker' ) );
				return;
			}

			$result = PLTT_Entries::update( $entry_id, array( 'billed' => $int_value ) );
			if ( ! $result ) {
				wp_send_json_error( __( 'Failed to update entry.', 'plain-language-time-tracker' ) );
				return;
			}
		}

		wp_send_json_success( array( 'message' => __( 'Saved.', 'plain-language-time-tracker' ) ) );
	}

	/**
	 * Delete a time entry.
	 */
	public static function delete_entry() {
		if ( ! self::verify_request() ) {
			return;
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;

		if ( empty( $entry_id ) ) {
			wp_send_json_error( __( 'Invalid entry ID.', 'plain-language-time-tracker' ) );
			return;
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
		if ( ! self::verify_request() ) {
			return;
		}

		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$hourly_rate = isset( $_POST['hourly_rate'] ) ? wp_unslash( $_POST['hourly_rate'] ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( __( 'Client name is required.', 'plain-language-time-tracker' ) );
			return;
		}

		$client_data = array(
			'name'        => $name,
			'description' => $description,
		);
		if ( '' !== $hourly_rate ) {
			// SEC-M7: validate at the handler boundary too — the data layer also checks.
			$rate_float = floatval( $hourly_rate );
			$valid_rate = pltt_validate_hourly_rate( $rate_float );
			if ( is_wp_error( $valid_rate ) ) {
				wp_send_json_error( __( 'Invalid hourly rate.', 'plain-language-time-tracker' ) );
				return;
			}
			$client_data['hourly_rate'] = $rate_float;
		}

		$client_id = PLTT_Clients::create( $client_data );

		// TRC-API2: a WP_Error is truthy — guard it before passing the id onward.
		if ( is_wp_error( $client_id ) || ! $client_id ) {
			$message = is_wp_error( $client_id )
				? $client_id->get_error_message()
				: __( 'Failed to create client.', 'plain-language-time-tracker' );
			wp_send_json_error( $message );
			return;
		}

		$client = PLTT_Clients::get( $client_id );
		wp_send_json_success(
			array(
				'client'  => $client,
				'message' => __( 'Client created.', 'plain-language-time-tracker' ),
			)
		);
	}

	/**
	 * Get projects for a client.
	 */
	public static function get_projects() {
		if ( ! self::verify_request() ) {
			return;
		}

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
		if ( ! self::verify_request() ) {
			return;
		}

		$client_id           = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$name                = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$description         = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$hourly_rate         = isset( $_POST['hourly_rate'] ) ? wp_unslash( $_POST['hourly_rate'] ) : '';
		$recurring_period    = isset( $_POST['recurring_period'] ) ? sanitize_text_field( wp_unslash( $_POST['recurring_period'] ) ) : '';
		$budget_hours        = isset( $_POST['budget_hours'] ) ? wp_unslash( $_POST['budget_hours'] ) : '';
		$budget_fee          = isset( $_POST['budget_fee'] ) ? wp_unslash( $_POST['budget_fee'] ) : '';
		// non_billable=1 means the checkbox was checked → billability_default=0; absent = billable by default.
		$billability_default = isset( $_POST['non_billable'] ) && '1' === $_POST['non_billable'] ? 0 : 1;

		if ( empty( $name ) ) {
			wp_send_json_error( __( 'Project name is required.', 'plain-language-time-tracker' ) );
			return;
		}

		if ( empty( $client_id ) ) {
			wp_send_json_error( __( 'Client is required.', 'plain-language-time-tracker' ) );
			return;
		}

		// SEC-H4: Validate recurring_period against allowlist.
		if ( ! in_array( $recurring_period, PLTT_ALLOWED_RECURRING_PERIODS, true ) ) {
			wp_send_json_error( __( 'Invalid recurring period.', 'plain-language-time-tracker' ) );
			return;
		}

		$project_data = array(
			'client_id'           => $client_id,
			'name'                => $name,
			'description'         => $description,
			'billability_default' => $billability_default,
		);
		if ( '' !== $hourly_rate ) {
			// SEC-M7: validate at the handler boundary.
			$rate_float = floatval( $hourly_rate );
			$valid_rate = pltt_validate_hourly_rate( $rate_float );
			if ( is_wp_error( $valid_rate ) ) {
				wp_send_json_error( __( 'Invalid hourly rate.', 'plain-language-time-tracker' ) );
				return;
			}
			$project_data['hourly_rate'] = $rate_float;
		}
		if ( '' !== $recurring_period ) {
			$project_data['recurring_period'] = $recurring_period;
		}
		if ( '' !== $budget_hours ) {
			$hours_float = floatval( $budget_hours );
			if ( $hours_float < 0 ) {
				wp_send_json_error( __( 'Budget hours must be zero or positive.', 'plain-language-time-tracker' ) );
				return;
			}
			$project_data['budget_hours'] = $hours_float;
		}
		if ( '' !== $budget_fee ) {
			$fee_float = floatval( $budget_fee );
			if ( $fee_float < 0 ) {
				wp_send_json_error( __( 'Budget fee must be zero or positive.', 'plain-language-time-tracker' ) );
				return;
			}
			$project_data['budget_fee'] = $fee_float;
			unset( $project_data['budget_hours'] ); // mutual exclusion: fee overrides hours
		}

		$project_id = PLTT_Projects::create( $project_data );

		// TRC-API3: a WP_Error is truthy — guard it before passing the id onward.
		if ( is_wp_error( $project_id ) || ! $project_id ) {
			$message = is_wp_error( $project_id )
				? $project_id->get_error_message()
				: __( 'Failed to create project.', 'plain-language-time-tracker' );
			wp_send_json_error( $message );
			return;
		}

		$project = PLTT_Projects::get( $project_id );
		wp_send_json_success(
			array(
				'project' => $project,
				'message' => __( 'Project created.', 'plain-language-time-tracker' ),
			)
		);
	}

	/**
	 * Create a new tag (used from the review screen modal).
	 */
	public static function create_tag() {
		if ( ! self::verify_request() ) {
			return;
		}

		$tag_name = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$tag_name = strtolower( trim( $tag_name ) );

		if ( empty( $tag_name ) ) {
			wp_send_json_error( __( 'Tag name is required.', 'plain-language-time-tracker' ) );
			return;
		}

		// SEC-M8: tag.name is varchar(100). Reject explicitly rather than letting
		// MySQL silently truncate (which would collide on the truncated prefix
		// with another near-identical tag).
		if ( mb_strlen( $tag_name ) > 100 ) {
			wp_send_json_error( __( 'Tag name too long (max 100 characters).', 'plain-language-time-tracker' ) );
			return;
		}

		if ( PLTT_Tags::get_by_name( $tag_name ) ) {
			wp_send_json_error( __( 'A tag with that name already exists.', 'plain-language-time-tracker' ) );
			return;
		}

		$result = PLTT_Tags::create( $tag_name );

		if ( $result ) {
			wp_send_json_success( array( 'tag' => $tag_name ) );
		} else {
			wp_send_json_error( __( 'Failed to create tag.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Update a single entry from the per-row edit form on the review screen.
	 *
	 * Returns the rendered row markup so the JS can swap it into the entries
	 * list without a page reload.
	 */
	public static function save_entry() {
		if ( ! self::verify_request() ) {
			return;
		}

		$entry_id   = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		if ( $entry_id <= 0 ) {
			wp_send_json_error( __( 'Entry ID is required.', 'plain-language-time-tracker' ) );
			return;
		}
		$entry_date = isset( $_POST['entry_date'] ) ? pltt_sanitize_date( wp_unslash( $_POST['entry_date'] ) ) : '';
		$start_time = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
		$end_time   = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
		$duration   = isset( $_POST['duration_minutes'] ) ? absint( $_POST['duration_minutes'] ) : 0;
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$client_id   = isset( $_POST['client_id'] ) && '' !== $_POST['client_id'] ? absint( $_POST['client_id'] ) : 0;
		$project_id  = isset( $_POST['project_id'] ) && '' !== $_POST['project_id'] ? absint( $_POST['project_id'] ) : 0;
		$tags        = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';
		$billable    = isset( $_POST['billable'] ) && '1' === (string) $_POST['billable'] ? 1 : 0;

		if ( empty( $entry_date ) ) {
			wp_send_json_error( __( 'A date is required.', 'plain-language-time-tracker' ) );
			return;
		}

		// Reject malformed times before they reach date_create().
		$time_pattern = '/^\d{1,2}:\d{2}(:\d{2})?$/';
		if ( '' === $start_time || ! preg_match( $time_pattern, $start_time ) ) {
			wp_send_json_error( __( 'A valid start time is required.', 'plain-language-time-tracker' ) );
			return;
		}
		if ( '' !== $end_time && ! preg_match( $time_pattern, $end_time ) ) {
			wp_send_json_error( __( 'End time is invalid.', 'plain-language-time-tracker' ) );
			return;
		}

		// SEC-M8 parity: each tag maps to tag.name varchar(100). Reject explicitly
		// rather than letting MySQL truncate on INSERT — a truncated name then fails
		// the exact-match lookup in PLTT_Tags::sync_entry_tags() and is silently
		// dropped from the entry. Mirrors the cap in create_tag().
		if ( '' !== $tags ) {
			foreach ( explode( ',', $tags ) as $single_tag ) {
				if ( mb_strlen( trim( $single_tag ) ) > 100 ) {
					wp_send_json_error( __( 'One or more tags are too long (max 100 characters each).', 'plain-language-time-tracker' ) );
					return;
				}
			}
		}

		// Recalculate duration server-side when both times are present so a
		// tampered duration_minutes can't disagree with the stored start/end.
		if ( '' !== $end_time ) {
			$start_mins = pltt_time_to_minutes( $start_time );
			$end_mins   = pltt_time_to_minutes( $end_time );
			if ( false === $start_mins || false === $end_mins ) {
				wp_send_json_error( __( 'Time values could not be parsed.', 'plain-language-time-tracker' ) );
				return;
			}
			$duration = ( $end_mins >= $start_mins )
				? ( $end_mins - $start_mins )
				: ( 1440 - $start_mins + $end_mins );
		}

		// Overlapping times are intentionally allowed — entries may legitimately
		// run concurrently (e.g. logging a coworking block alongside the work
		// done during it). No overlap validation here.

		$data = array(
			'entry_date'       => $entry_date,
			'start_time'       => $start_time,
			'end_time'         => $end_time,
			'duration_minutes' => $duration,
			'description'      => $description,
			'client_id'        => $client_id > 0 ? $client_id : null,
			'project_id'       => $project_id > 0 ? $project_id : null,
			'tags'             => $tags,
			'billable'         => $billable,
			'verified'         => 1,
		);

		// Snapshot billable rate + amount when billable.
		if ( $billable && $duration > 0 ) {
			$hourly_rate            = pltt_resolve_billable_rate( $client_id, $project_id );
			$data['billable_rate']  = $hourly_rate;
			$data['billable_amount'] = pltt_billable_amount( $duration, $hourly_rate );
		} else {
			$data['billable_rate']   = 0.00;
			$data['billable_amount'] = 0.00;
		}

		$existing = PLTT_Entries::get( $entry_id );
		if ( ! $existing ) {
			wp_send_json_error( __( 'Entry not found.', 'plain-language-time-tracker' ) );
			return;
		}

		$result = PLTT_Entries::update( $entry_id, $data );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to save entry.', 'plain-language-time-tracker' ) );
			return;
		}

		// Render the updated row as HTML so the JS can swap it in without a page reload.
		$saved_entry = PLTT_Entries::get( $entry_id );
		ob_start();
		PLTT_Review::render_entry_row( $saved_entry );
		$row_html = ob_get_clean();

		wp_send_json_success(
			array(
				'message'  => __( 'Saved.', 'plain-language-time-tracker' ),
				'entry_id' => (int) $entry_id,
				'row_html' => $row_html,
			)
		);
	}

	/**
	 * Delete a daily log and its associated time entries.
	 */
	public static function delete_daily_log() {
		if ( ! self::verify_request() ) {
			return;
		}

		// SEC-M3: strict date — this handler deletes data for the supplied date.
		$log_date = isset( $_POST['log_date'] ) ? pltt_sanitize_date_strict( wp_unslash( $_POST['log_date'] ) ) : '';

		if ( empty( $log_date ) ) {
			wp_send_json_error( __( 'Invalid date.', 'plain-language-time-tracker' ) );
			return;
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

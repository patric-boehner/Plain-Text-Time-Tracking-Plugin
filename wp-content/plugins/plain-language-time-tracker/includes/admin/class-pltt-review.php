<?php
/**
 * Review & Verify screen.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Review & Verify admin screen.
 */
class PLTT_Review {

	/**
	 * Render the Review screen.
	 */
	public static function render() {
		$date = isset( $_GET['date'] ) ? pltt_sanitize_date( wp_unslash( $_GET['date'] ) ) : pltt_get_current_date();

		// Always load entries from database - never re-parse.
		$entries = self::get_entries_for_date( $date );
		$clients = PLTT_Clients::get_all();

		// Collect project IDs referenced by entries, grouped by client.
		// These may be archived but must appear in the entry's dropdown.
		$extra_project_ids_by_client = array();
		foreach ( $entries as $entry ) {
			$cid = $entry['predicted_client_id'] ?? 0;
			$pid = $entry['predicted_project_id'] ?? 0;
			if ( $cid > 0 && $pid > 0 ) {
				$extra_project_ids_by_client[ $cid ][] = $pid;
			}
		}

		// Pre-load projects for each client that appears in the entries.
		$projects_by_client = array();
		foreach ( $entries as $entry ) {
			$cid = $entry['predicted_client_id'] ?? 0;
			if ( $cid > 0 && ! isset( $projects_by_client[ $cid ] ) ) {
				$extras = $extra_project_ids_by_client[ $cid ] ?? array();
				$projects_by_client[ $cid ] = PLTT_Projects::get_by_client_recent_first( $cid, $extras );
			}
		}

		// Collect all known tags for autocomplete.
		$all_tags = PLTT_Entries::get_all_tags();
		sort( $all_tags );

		include PLTT_PLUGIN_DIR . 'templates/review.php';
	}

	/**
	 * Get entries for a date from the database.
	 *
	 * Always loads from DB - never re-parses the log.
	 * Re-processing only happens when user explicitly clicks "Process" on Daily Log.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return array Array of entry data.
	 */
	public static function get_entries_for_date( $date ) {
		$entries = PLTT_Entries::get_all(
			array(
				'date'    => $date,
				'orderby' => 'start_time',
				'order'   => 'ASC',
			)
		);

		if ( empty( $entries ) ) {
			return array();
		}

		return self::format_entries_for_review( $entries );
	}

	/**
	 * Format database entries for the review screen.
	 *
	 * @param array $entries Array of entry objects from database.
	 * @return array Formatted entries.
	 */
	private static function format_entries_for_review( $entries ) {
		$formatted = array();

		foreach ( $entries as $entry ) {
			$formatted[] = array(
				'id'                   => $entry->id,
				'entry_date'           => $entry->entry_date,
				'start_time'           => $entry->start_time,
				'end_time'             => $entry->end_time,
				'duration_minutes'     => $entry->duration_minutes,
				'raw_text'             => $entry->raw_text,
				'description'          => $entry->description,
				'client_id'            => $entry->client_id,
				'project_id'           => $entry->project_id,
				'predicted_client_id'  => $entry->client_id,
				'predicted_project_id' => $entry->project_id,
				'tags'                 => $entry->tags,
				'verified'             => $entry->verified,
				'billable'             => $entry->billable,
			);
		}

		return $formatted;
	}

	/**
	 * Save entries from review screen.
	 *
	 * Updates existing entries in the database.
	 * Entries are created during processing, this just updates them.
	 *
	 * @param string $date    Date in Y-m-d format.
	 * @param array  $entries Array of entry data from form.
	 * @return array Result with success status and message.
	 */
	public static function save_entries( $date, $entries ) {
		$saved_count = 0;
		$error_count = 0;

		foreach ( $entries as $entry_data ) {
			$entry_id = ! empty( $entry_data['id'] ) ? absint( $entry_data['id'] ) : 0;

			// Skip entries without an ID - they should have been created during processing.
			if ( $entry_id <= 0 ) {
				continue;
			}

			// Load original entry before updating (for alias learning).
			$original = PLTT_Entries::get( $entry_id );

			// Update all editable fields from the review screen.
			// Mark as verified when saved from review screen.
			$data = array(
				'description' => sanitize_textarea_field( $entry_data['description'] ?? '' ),
				'client_id'   => ! empty( $entry_data['client_id'] ) ? absint( $entry_data['client_id'] ) : null,
				'project_id'  => ! empty( $entry_data['project_id'] ) ? absint( $entry_data['project_id'] ) : null,
				'tags'        => sanitize_text_field( $entry_data['tags'] ?? '' ),
				'billable'    => ! empty( $entry_data['billable'] ) ? 1 : 0,
				'verified'    => 1,
			);

			// Include date/time fields if provided.
			if ( ! empty( $entry_data['entry_date'] ) ) {
				$data['entry_date'] = $entry_data['entry_date'];
			}
			if ( isset( $entry_data['start_time'] ) && '' !== $entry_data['start_time'] ) {
				$data['start_time'] = $entry_data['start_time'];
			}
			if ( isset( $entry_data['end_time'] ) ) {
				$data['end_time'] = $entry_data['end_time'];
			}
			if ( isset( $entry_data['duration_minutes'] ) ) {
				$data['duration_minutes'] = $entry_data['duration_minutes'];
			}

			$result = PLTT_Entries::update( $entry_id, $data );

			if ( false !== $result ) {
				++$saved_count;

				// Learn client aliases from user's selection.
				if ( $original ) {
					self::learn_client_alias( $original, $data );
				}
			} else {
				++$error_count;
			}
		}

		return array(
			'success' => $error_count === 0,
			'saved'   => $saved_count,
			'errors'  => $error_count,
			'message' => $error_count === 0
				? sprintf(
					/* translators: %d: number of entries saved */
					_n( '%d entry saved.', '%d entries saved.', $saved_count, 'plain-language-time-tracker' ),
					$saved_count
				)
				: sprintf(
					/* translators: 1: entries saved, 2: errors */
					__( '%1$d entries saved, %2$d errors.', 'plain-language-time-tracker' ),
					$saved_count,
					$error_count
				),
		);
	}

	/**
	 * Learn client aliases from user's selection.
	 *
	 * Compares the predicted client (before save) with the user's choice to
	 * update alias confidence. Also creates new aliases from text patterns
	 * when the user selects a client.
	 *
	 * @param object $original Original entry from database (before save).
	 * @param array  $saved    Saved data with user's selections.
	 */
	private static function learn_client_alias( $original, $saved ) {
		$text = trim( ( $original->description ?? '' ) . ' ' . ( $original->raw_text ?? '' ) );

		if ( empty( $text ) ) {
			return;
		}

		$saved_client    = ! empty( $saved['client_id'] ) ? (int) $saved['client_id'] : 0;
		$original_client = ! empty( $original->client_id ) ? (int) $original->client_id : 0;

		// Re-derive the alias match to find which alias (if any) predicted the client.
		$alias_match = PLTT_Aliases::get_best_client_match( $text );

		if ( $alias_match ) {
			$predicted_client = (int) $alias_match->client_id;
			$was_correct      = ( $predicted_client === $saved_client );
			PLTT_Aliases::record_usage( $alias_match->id, $was_correct );
		}

		// Create new client aliases from text patterns.
		if ( $saved_client > 0 ) {
			$potentials = PLTT_Aliases::extract_potential( $text );

			foreach ( $potentials as $potential ) {
				$existing = PLTT_Aliases::get_by_text( $potential );
				if ( ! $existing ) {
					PLTT_Aliases::create(
						array(
							'alias_text' => $potential,
							'client_id'  => $saved_client,
						)
					);
				}
			}
		}
	}
}

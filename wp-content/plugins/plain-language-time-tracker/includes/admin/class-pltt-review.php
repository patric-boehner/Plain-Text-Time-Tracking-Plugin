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
		$data    = self::get_entries_for_date( $date );
		$entries = $data['entries'];
		$summary = $data['summary'];
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

		// Load daily log for notes reference.
		$log = PLTT_Daily_Log::get_log( $date );

		// Collect all known tags for autocomplete.
		$all_tags = array_column( PLTT_Tags::get_all(), 'name' );
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
	 * @return array Array with 'entries' and 'summary' keys.
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
			return array(
				'entries' => array(),
				'summary' => array(
					'billable_minutes' => 0,
					'billable_amount'  => 0.0,
				),
			);
		}

		return self::format_entries_for_review( $entries );
	}

	/**
	 * Format database entries for the review screen.
	 *
	 * Enriches entries with computed fields for display:
	 * - billable_amount: Calculated billable amount using hourly rates
	 *
	 * @param array $entries Array of entry objects from database.
	 * @return array Array with 'entries' and 'summary' keys.
	 */
	private static function format_entries_for_review( $entries ) {
		$formatted = array();

		// Collect unique entry, project, and client IDs to minimize DB queries.
		$entry_ids   = array();
		$project_ids = array();
		$client_ids  = array();
		foreach ( $entries as $entry ) {
			$entry_ids[] = (int) $entry->id;
			if ( ! empty( $entry->project_id ) ) {
				$project_ids[] = (int) $entry->project_id;
			}
			if ( ! empty( $entry->client_id ) ) {
				$client_ids[] = (int) $entry->client_id;
			}
		}

		// Bulk-load tags for all entries in a single query.
		$tags_by_entry = PLTT_Tags::get_for_entries( $entry_ids );

		// Fetch all referenced projects and clients in bulk (single query each).
		$projects_cache = PLTT_Projects::get_multiple( array_unique( $project_ids ) );
		$clients_cache  = PLTT_Clients::get_multiple( array_unique( $client_ids ) );

		// Initialize summary totals.
		$summary = array(
			'billable_minutes' => 0,
			'billable_amount'  => 0.0,
		);

		// Process each entry.
		foreach ( $entries as $entry ) {
			$project_id = ! empty( $entry->project_id ) ? (int) $entry->project_id : 0;
			$client_id  = ! empty( $entry->client_id ) ? (int) $entry->client_id : 0;

			// Calculate billable amount for this entry.
			// Use stored values for verified entries, calculate on-the-fly for unverified.
			$billable_amount = 0.0;
			if ( ! empty( $entry->billable ) && $entry->duration_minutes > 0 ) {
				// Use stored billable_amount if available (verified entries).
				if ( ! empty( $entry->verified ) && null !== $entry->billable_amount ) {
					$billable_amount = (float) $entry->billable_amount;
				} else {
					// Calculate dynamically for unverified entries.
					$hourly_rate = 0.0;

					// Project rate takes precedence, fallback to client rate, fallback to default, fallback to 0.
					if ( $project_id > 0 && isset( $projects_cache[ $project_id ] ) ) {
						$hourly_rate = (float) ( $projects_cache[ $project_id ]->hourly_rate ?? 0 );
					}

					if ( 0.0 === $hourly_rate && $client_id > 0 && isset( $clients_cache[ $client_id ] ) ) {
						$hourly_rate = (float) ( $clients_cache[ $client_id ]->hourly_rate ?? 0 );
					}

					if ( 0.0 === $hourly_rate && defined( 'PLTT_DEFAULT_HOURLY_RATE' ) ) {
						$hourly_rate = (float) PLTT_DEFAULT_HOURLY_RATE;
					}

					$billable_amount = round( ( $entry->duration_minutes / 60.0 ) * $hourly_rate, 2 );
				}

				// Add to summary totals.
				$summary['billable_minutes'] += $entry->duration_minutes;
				$summary['billable_amount']  += $billable_amount;
			}

			// Format entry with enriched data.
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
				'tags'                 => implode( ',', $tags_by_entry[ (int) $entry->id ] ?? array() ),
				'verified'             => $entry->verified,
				'billable'             => $entry->billable,
				'billable_amount'      => $billable_amount,
				'billed'               => $entry->billed ?? 0,
			);
		}

		return array(
			'entries' => $formatted,
			'summary' => $summary,
		);
	}

	/**
	 * Save entries from review screen.
	 *
	 * Updates existing entries in the database.
	 * Entries are created during processing, this just updates them.
	 * Snapshots billable_rate and billable_amount when verifying.
	 *
	 * @param string $date    Date in Y-m-d format.
	 * @param array  $entries Array of entry data from form.
	 * @return array Result with success status and message.
	 */
	public static function save_entries( $date, $entries ) {
		$saved_count = 0;
		$error_count = 0;

		// Build caches for clients and projects to resolve rates.
		$projects_cache = array();
		$clients_cache  = array();

		// Pre-load all referenced clients and projects.
		foreach ( $entries as $entry_data ) {
			$client_id  = ! empty( $entry_data['client_id'] ) ? absint( $entry_data['client_id'] ) : 0;
			$project_id = ! empty( $entry_data['project_id'] ) ? absint( $entry_data['project_id'] ) : 0;

			if ( $client_id > 0 && ! isset( $clients_cache[ $client_id ] ) ) {
				$client = PLTT_Clients::get( $client_id );
				if ( $client ) {
					$clients_cache[ $client_id ] = $client;
				}
			}

			if ( $project_id > 0 && ! isset( $projects_cache[ $project_id ] ) ) {
				$project = PLTT_Projects::get( $project_id );
				if ( $project ) {
					$projects_cache[ $project_id ] = $project;
				}
			}
		}

		foreach ( $entries as $entry_data ) {
			$entry_id = ! empty( $entry_data['id'] ) ? absint( $entry_data['id'] ) : 0;

			// Skip entries without an ID - they should have been created during processing.
			if ( $entry_id <= 0 ) {
				continue;
			}

			// Load original entry before updating (for alias learning and rate snapshotting).
			$original = PLTT_Entries::get( $entry_id );

			// Update all editable fields from the review screen.
			// Mark as verified when saved from review screen.
			$data = array(
				'description' => sanitize_textarea_field( $entry_data['description'] ?? '' ),
				'client_id'   => ! empty( $entry_data['client_id'] ) ? absint( $entry_data['client_id'] ) : null,
				'project_id'  => ! empty( $entry_data['project_id'] ) ? absint( $entry_data['project_id'] ) : null,
				'tags'        => sanitize_text_field( $entry_data['tags'] ?? '' ),
				'billable'    => ! empty( $entry_data['billable'] ) ? 1 : 0,
				'billed'      => ! empty( $entry_data['billed'] ) ? 1 : 0,
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

			// Recalculate duration server-side when both times are present.
			// Discards the client-supplied value to prevent tampering.
			if ( isset( $data['start_time'] ) && isset( $data['end_time'] ) && '' !== $data['start_time'] ) {
				$start_mins            = pltt_time_to_minutes( $data['start_time'] );
				$end_mins              = pltt_time_to_minutes( $data['end_time'] );
				$data['duration_minutes'] = ( $end_mins >= $start_mins )
					? ( $end_mins - $start_mins )
					: ( 1440 - $start_mins + $end_mins ); // Overnight span.
			} elseif ( isset( $entry_data['duration_minutes'] ) ) {
				$data['duration_minutes'] = absint( $entry_data['duration_minutes'] );
			}

			// Snapshot billable rate and amount when verifying.
			$duration_minutes = isset( $data['duration_minutes'] ) ? (int) $data['duration_minutes'] : ( $original ? (int) $original->duration_minutes : 0 );

			if ( $data['billable'] && $duration_minutes > 0 ) {
				// Check if this is a newly verified entry or already verified.
				$was_verified = $original && ! empty( $original->verified );

				if ( ! $was_verified ) {
					// Newly verified: resolve and snapshot the rate.
					$data['billable_rate']   = self::resolve_billable_rate( $data, $clients_cache, $projects_cache );
					$data['billable_amount'] = round( ( $duration_minutes / 60.0 ) * $data['billable_rate'], 2 );
				} else {
					// Already verified: keep existing rate, recalculate amount if duration changed.
					if ( null !== $original->billable_rate ) {
						$data['billable_rate']   = $original->billable_rate;
						$data['billable_amount'] = round( ( $duration_minutes / 60.0 ) * $original->billable_rate, 2 );
					}
				}
			} else {
				// Not billable or no duration: set to 0.
				$data['billable_rate']   = 0.00;
				$data['billable_amount'] = 0.00;
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
	 * Resolve billable rate using hierarchy.
	 *
	 * Resolution order: Project rate → Client rate → Default rate → $0.
	 *
	 * @param array $entry_data      Entry data with client_id and project_id.
	 * @param array $clients_cache   Cache of client objects.
	 * @param array $projects_cache  Cache of project objects.
	 * @return float Resolved hourly rate.
	 */
	private static function resolve_billable_rate( $entry_data, $clients_cache, $projects_cache ) {
		$client_id  = ! empty( $entry_data['client_id'] ) ? (int) $entry_data['client_id'] : 0;
		$project_id = ! empty( $entry_data['project_id'] ) ? (int) $entry_data['project_id'] : 0;

		// 1. Check project rate.
		if ( $project_id > 0 && isset( $projects_cache[ $project_id ] ) ) {
			$project_rate = (float) ( $projects_cache[ $project_id ]->hourly_rate ?? 0 );
			if ( $project_rate > 0 ) {
				return $project_rate;
			}
		}

		// 2. Check client rate.
		if ( $client_id > 0 && isset( $clients_cache[ $client_id ] ) ) {
			$client_rate = (float) ( $clients_cache[ $client_id ]->hourly_rate ?? 0 );
			if ( $client_rate > 0 ) {
				return $client_rate;
			}
		}

		// 3. Use default rate.
		if ( defined( 'PLTT_DEFAULT_HOURLY_RATE' ) ) {
			return (float) PLTT_DEFAULT_HOURLY_RATE;
		}

		// 4. Fallback to $0.
		return 0.00;
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

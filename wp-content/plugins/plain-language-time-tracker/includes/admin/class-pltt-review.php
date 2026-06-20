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
		$unique_client_ids           = array();
		foreach ( $entries as $entry ) {
			$cid = $entry['predicted_client_id'] ?? 0;
			$pid = $entry['predicted_project_id'] ?? 0;
			if ( $cid > 0 ) {
				$unique_client_ids[] = $cid;
				if ( $pid > 0 ) {
					$extra_project_ids_by_client[ $cid ][] = $pid;
				}
			}
		}

		// OPT-M1: Bulk-load projects for all clients in a single query instead of N+1.
		$projects_by_client = PLTT_Projects::get_for_clients(
			array_unique( $unique_client_ids ),
			$extra_project_ids_by_client
		);

		// Load daily log for notes reference.
		$log = PLTT_Daily_Log::get_log( $date );

		// Collect all known tags for autocomplete.
		$all_tags = array_column( PLTT_Tags::get_all(), 'name' );
		sort( $all_tags );

		include PLTT_PLUGIN_DIR . 'templates/review.php';
	}

	/**
	 * Render the compact display row + hidden form row for one saved entry.
	 *
	 * Used by the pltt_save_entry AJAX endpoint to return updated row markup
	 * after a per-row save so the JS can swap it into the list without a page
	 * reload. The markup MUST match the layout produced by
	 * templates/partials/review-edit-existing.php.
	 *
	 * @param object $entry Time-entry row from the DB.
	 */
	public static function render_entry_row( $entry ) {
		$date = $entry->entry_date;

		// Re-fetch tags for the entry from the junction table.
		$tags_by_entry = PLTT_Tags::get_for_entries( array( (int) $entry->id ) );
		$entry_tags    = $tags_by_entry[ (int) $entry->id ] ?? array();

		// Format into the shape entry-form-row.php expects (matches format_entries_for_review).
		$formatted = array(
			'id'               => (int) $entry->id,
			'entry_date'       => $entry->entry_date,
			'start_time'       => $entry->start_time,
			'end_time'         => $entry->end_time,
			'duration_minutes' => (int) $entry->duration_minutes,
			'description'      => $entry->description,
			'client_id'        => (int) $entry->client_id,
			'project_id'       => (int) $entry->project_id,
			'tags'             => implode( ',', $entry_tags ),
			'billable'         => (int) $entry->billable,
			'verified'         => (int) $entry->verified,
		);

		$clients = PLTT_Clients::get_all();

		$projects_by_client = array();
		if ( ! empty( $entry->client_id ) ) {
			$extra = ! empty( $entry->project_id ) ? array( (int) $entry->client_id => array( (int) $entry->project_id ) ) : array();
			$projects_by_client = PLTT_Projects::get_for_clients(
				array( (int) $entry->client_id ),
				$extra
			);
		}

		$is_billable = ! empty( $formatted['billable'] );
		?>
		<tr class="pltt-entry-row pltt-entry-compact" data-entry-id="<?php echo esc_attr( $formatted['id'] ); ?>">
			<td class="pltt-time-cell">
				<div class="pltt-time-display">
					<span class="pltt-date-text"><?php echo esc_html( pltt_format_date( $formatted['entry_date'], 'M j, Y' ) ); ?></span>
					<span class="pltt-time-separator">&middot;</span>
					<span class="pltt-time-text">
						<?php
						echo esc_html( pltt_format_time( $formatted['start_time'] ) );
						if ( ! empty( $formatted['end_time'] ) ) {
							echo ' &ndash; ' . esc_html( pltt_format_time( $formatted['end_time'] ) );
						}
						?>
					</span>
					<div class="row-actions">
						<span class="edit"><a href="#edit" class="pltt-edit-entry" role="button"><?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?></a> | </span>
						<span class="trash"><a href="#delete" class="pltt-delete-entry submitdelete" role="button"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></a></span>
					</div>
				</div>
			</td>
			<td class="pltt-duration-cell">
				<?php echo ! empty( $formatted['duration_minutes'] ) ? esc_html( pltt_format_duration( $formatted['duration_minutes'] ) ) : '--'; ?>
			</td>
			<td class="pltt-entry-desc-cell">
				<span class="pltt-entry-desc-text"><?php echo esc_html( $formatted['description'] ); ?></span>
				<?php
				$client  = ! empty( $formatted['client_id'] ) ? PLTT_Clients::get( $formatted['client_id'] ) : null;
				$project = ! empty( $formatted['project_id'] ) ? PLTT_Projects::get( $formatted['project_id'] ) : null;
				$meta    = array();
				if ( $client ) {
					$meta[] = '<span class="pltt-entry-client">' . esc_html( $client->name ) . '</span>';
				}
				if ( $project ) {
					$meta[] = '<span class="pltt-entry-project">' . esc_html( $project->name ) . '</span>';
				}
				if ( ! empty( $meta ) ) :
				?>
					<div class="pltt-entry-meta">
						<?php echo implode( '<span class="pltt-entry-meta-sep"> · </span>', $meta ); // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped above ?>
					</div>
				<?php endif; ?>
			</td>
			<td class="pltt-tag-cell">
				<div class="pltt-tag-pills">
					<?php pltt_render_tag_badges( $entry_tags ); ?>
				</div>
			</td>
			<td class="pltt-billable-indicator">
				<span class="pltt-billable-symbol <?php echo $is_billable ? 'is-billable' : 'not-billable'; ?>"
					aria-label="<?php echo $is_billable ? esc_attr__( 'Billable', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable', 'plain-language-time-tracker' ); ?>"
					title="<?php echo $is_billable ? esc_attr__( 'Billable', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable', 'plain-language-time-tracker' ); ?>">$</span>
			</td>
		</tr>
		<?php
		// Hidden form row beneath the compact row.
		$form_entry  = $formatted;
		$mode        = 'edit';
		$row_visible = false;
		$colspan     = 5;
		include PLTT_PLUGIN_DIR . 'templates/partials/entry-form-row.php';
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
					// OPT-DUP5 / TRC-3: route through the canonical rate-resolution
					// helper instead of duplicating the cascade.
					$hourly_rate     = pltt_resolve_billable_rate( $client_id, $project_id, $clients_cache, $projects_cache );
					$billable_amount = pltt_billable_amount( $entry->duration_minutes, $hourly_rate );
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

		// Attach AM/PM mix-up warnings (computed across the full sorted list).
		$entry_warnings = pltt_compute_entry_warnings( $formatted );
		foreach ( $formatted as &$entry_ref ) {
			$entry_ref['warnings'] = $entry_warnings[ (int) $entry_ref['id'] ] ?? array();
		}
		unset( $entry_ref );

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

		// OPT-M2: Bulk-load all referenced clients and projects in two queries instead of N+1.
		$client_ids  = array_filter( array_unique( array_map(
			function( $e ) { return ! empty( $e['client_id'] ) ? absint( $e['client_id'] ) : 0; },
			$entries
		) ) );
		$project_ids = array_filter( array_unique( array_map(
			function( $e ) { return ! empty( $e['project_id'] ) ? absint( $e['project_id'] ) : 0; },
			$entries
		) ) );

		$clients_cache  = PLTT_Clients::get_multiple( $client_ids );
		$projects_cache = PLTT_Projects::get_multiple( $project_ids );

		// OPT-L7: Pre-load all tags once so learn_alias() doesn't re-query per entry.
		$all_tag_names = array_column( PLTT_Tags::get_all(), 'name' );

		foreach ( $entries as $entry_data ) {
			$entry_id = ! empty( $entry_data['id'] ) ? absint( $entry_data['id'] ) : 0;

			// Skip entries without an ID - they should have been created during processing.
			if ( $entry_id <= 0 ) {
				continue;
			}

			// Load original entry before updating (for alias learning and rate snapshotting).
			$original = PLTT_Entries::get( $entry_id );

			// SEC-H2: Reject rows whose entry_id belongs to a different date. A
			// forged or CSRF-chained submit must not be able to overwrite entries
			// outside the date this form was rendered for.
			if ( ! $original || $original->entry_date !== $date ) {
				++$error_count;
				continue;
			}

			// SEC-H3: Validate per-row start_time/end_time against a strict HH:MM[:SS]
			// pattern before they reach date_create() / pltt_time_to_minutes().
			$time_pattern = '/^\d{1,2}:\d{2}(:\d{2})?$/';
			if ( isset( $entry_data['start_time'] ) && '' !== $entry_data['start_time']
				&& ! preg_match( $time_pattern, $entry_data['start_time'] ) ) {
				++$error_count;
				continue;
			}
			if ( isset( $entry_data['end_time'] ) && '' !== $entry_data['end_time']
				&& ! preg_match( $time_pattern, $entry_data['end_time'] ) ) {
				++$error_count;
				continue;
			}

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

			// Recalculate duration server-side when both times are present.
			// Discards the client-supplied value to prevent tampering.
			if ( isset( $data['start_time'] ) && isset( $data['end_time'] ) && '' !== $data['start_time'] ) {
				$start_mins = pltt_time_to_minutes( $data['start_time'] );
				$end_mins   = pltt_time_to_minutes( $data['end_time'] );
				if ( false === $start_mins || false === $end_mins ) {
					// Malformed time — skip this entry rather than store a negative duration.
					++$error_count;
					continue;
				}
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

				$rate_was_zero = $original && 0.0 === (float) $original->billable_rate;

				if ( ! $was_verified || $rate_was_zero ) {
					// Newly verified, or previously verified as non-billable (rate=0): resolve fresh.
					$data['billable_rate']   = self::resolve_billable_rate( $data, $clients_cache, $projects_cache );
					$data['billable_amount'] = pltt_billable_amount( $duration_minutes, $data['billable_rate'] );
				} else {
					// Already verified with a real rate: keep it, recalculate amount if duration changed.
					if ( null !== $original->billable_rate ) {
						$data['billable_rate']   = $original->billable_rate;
						$data['billable_amount'] = pltt_billable_amount( $duration_minutes, $original->billable_rate );
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

				// Learn client + project aliases from the user's selection.
				if ( $original ) {
					self::learn_alias( $original, $data, $all_tag_names, $projects_cache );
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
	 * OPT-M3: Delegates to the shared pltt_resolve_billable_rate() helper in helpers.php.
	 * Passes pre-loaded caches to avoid extra DB queries.
	 *
	 * @param array $entry_data      Entry data with client_id and project_id.
	 * @param array $clients_cache   Cache of client objects (id => object).
	 * @param array $projects_cache  Cache of project objects (id => object).
	 * @return float Resolved hourly rate.
	 */
	private static function resolve_billable_rate( $entry_data, $clients_cache, $projects_cache ) {
		$client_id  = ! empty( $entry_data['client_id'] ) ? (int) $entry_data['client_id'] : 0;
		$project_id = ! empty( $entry_data['project_id'] ) ? (int) $entry_data['project_id'] : 0;
		return pltt_resolve_billable_rate( $client_id, $project_id, $clients_cache, $projects_cache );
	}

	/**
	 * Learn client + project aliases from the user's selection.
	 *
	 * Scores the aliases that drove the prediction against the user's choice
	 * (the project-bearing alias on project accuracy, the client-bearing alias
	 * on client accuracy) and creates new aliases from text patterns. New
	 * tokens become client aliases, except tokens that are part of the chosen
	 * project's name — those bind to the project, giving future entries a
	 * direct alias-to-project hit. Gating project binding on the project name
	 * keeps a client-name token (reused across a client's projects) from being
	 * poisoned to a single project.
	 *
	 * @param object     $original       Original entry from database (before save).
	 * @param array      $saved          Saved data with user's selections.
	 * @param array|null $known_tags     Pre-loaded tag names to pass to extract_potential() (OPT-L7).
	 * @param array      $projects_cache Pre-loaded projects (id => object) to avoid a per-entry query.
	 */
	private static function learn_alias( $original, $saved, $known_tags = null, $projects_cache = array() ) {
		$text = trim( ( $original->description ?? '' ) . ' ' . ( $original->raw_text ?? '' ) );

		if ( empty( $text ) ) {
			return;
		}

		$saved_client  = ! empty( $saved['client_id'] ) ? (int) $saved['client_id'] : 0;
		$saved_project = ! empty( $saved['project_id'] ) ? (int) $saved['project_id'] : 0;

		// Score the prediction drivers. Judge a project-bearing alias on project
		// accuracy (its job) and a client-bearing alias on client accuracy. When
		// the same row drives both, score it once (on the project) so use_count
		// isn't double-incremented.
		$project_match = PLTT_Aliases::get_best_project_match( $text );
		if ( $project_match ) {
			$project_correct = ( $saved_project > 0 && (int) $project_match->project_id === $saved_project );
			PLTT_Aliases::record_usage( $project_match->id, $project_correct );
		}

		$client_match = PLTT_Aliases::get_best_client_match( $text );
		if ( $client_match && ( ! $project_match || (int) $client_match->id !== (int) $project_match->id ) ) {
			$client_correct = ( (int) $client_match->client_id === $saved_client );
			PLTT_Aliases::record_usage( $client_match->id, $client_correct );
		}

		if ( $saved_client <= 0 ) {
			return;
		}

		// Name tokens distinctive to the chosen project — the only new tokens we
		// bind to it. A token shared by several projects' names is a poor signal.
		$project_tokens = array();
		if ( $saved_project > 0 ) {
			$project = $projects_cache[ $saved_project ] ?? PLTT_Projects::get( $saved_project );
			if ( $project && ! empty( $project->name ) ) {
				$project_tokens = self::distinctive_project_tokens( $project );
			}
		}

		// Create new aliases from text patterns.
		// OPT-L7: Pass pre-loaded tag names to avoid a DB call per entry.
		$potentials = PLTT_Aliases::extract_potential( $text, $known_tags );

		foreach ( $potentials as $potential ) {
			if ( PLTT_Aliases::get_by_text( $potential ) ) {
				continue; // Don't clobber an existing alias's learned target/confidence.
			}

			$alias_data = array(
				'alias_text' => $potential,
				'client_id'  => $saved_client,
			);

			// Bind to the project only when this token is part of the project name.
			if ( $saved_project > 0 && in_array( strtolower( $potential ), $project_tokens, true ) ) {
				$alias_data['project_id'] = $saved_project;
			}

			PLTT_Aliases::create( $alias_data );
		}
	}

	/**
	 * Break a name into lowercased word tokens (3+ letters) for alias matching.
	 *
	 * @param string $name Name to tokenize.
	 * @return array Lowercased word tokens.
	 */
	private static function tokenize_name( $name ) {
		preg_match_all( '/[A-Za-z]{3,}/', $name, $matches );
		return ! empty( $matches[0] ) ? array_map( 'strtolower', $matches[0] ) : array();
	}

	/**
	 * Name tokens that are distinctive to a single project.
	 *
	 * A token shared by several projects' names (e.g. "website", "care") is a
	 * poor project signal, so only tokens unique to this one project across all
	 * active projects are returned. The token→projects map is built once per
	 * request.
	 *
	 * @param object $project Project object (needs id, name).
	 * @return array Lowercased distinctive tokens for this project.
	 */
	private static function distinctive_project_tokens( $project ) {
		static $token_projects = null;

		if ( null === $token_projects ) {
			$token_projects = array();
			foreach ( PLTT_Projects::get_all( array( 'status' => 'active' ) ) as $p ) {
				foreach ( array_unique( self::tokenize_name( $p->name ) ) as $tok ) {
					$token_projects[ $tok ][ (int) $p->id ] = true;
				}
			}
		}

		$distinctive = array();
		foreach ( array_unique( self::tokenize_name( $project->name ) ) as $tok ) {
			if ( isset( $token_projects[ $tok ] ) && 1 === count( $token_projects[ $tok ] ) ) {
				$distinctive[] = $tok;
			}
		}

		return $distinctive;
	}
}

<?php
/**
 * Time parsing logic.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses plain text time logs into structured entries.
 */
class PLTT_Time_Parser {

	/**
	 * Parse a full day's log into time entries.
	 *
	 * @param string $text Raw log text.
	 * @param string $date Date for the entries (Y-m-d).
	 * @return array Array of parsed entry data.
	 */
	public static function parse_log( $text, $date ) {
		$lines   = self::split_into_lines( $text );
		$entries = array();

		foreach ( $lines as $line ) {
			$parsed = self::parse_line( $line );
			if ( $parsed ) {
				$parsed['entry_date'] = $date;
				$entries[]            = $parsed;
			}
		}

		// Calculate durations for sequential entries.
		$entries = self::calculate_durations( $entries );

		// Try to match clients/projects using aliases.
		$entries = self::apply_predictions( $entries );

		return $entries;
	}

	/**
	 * Split text into individual lines.
	 *
	 * @param string $text Raw text.
	 * @return array Array of non-empty lines.
	 */
	private static function split_into_lines( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		return array_filter( array_map( 'trim', $lines ) );
	}

	/**
	 * Parse a single line into entry data.
	 *
	 * Supported formats:
	 * - @9:15am - Task description
	 * - @14:30 - Task description
	 * - @9:15am - 10:30am - Task description (explicit range)
	 * - @10:30am - done (end marker)
	 *
	 * @param string $line Single line of text.
	 * @return array|false Parsed data or false if not a valid entry.
	 */
	public static function parse_line( $line ) {
		// Skip empty lines or lines without timestamp.
		if ( empty( $line ) || strpos( $line, '@' ) === false ) {
			return false;
		}

		// Pattern for time: 9:15am, 9:15 am, 14:30, etc.
		$time_pattern = '(\d{1,2}:\d{2}\s*(?:am|pm)?|\d{1,2}(?:am|pm))';

		// Try to match explicit time range: @9:15am - 10:30am - Description.
		$range_pattern = '/^@' . $time_pattern . '\s*-\s*' . $time_pattern . '\s*-\s*(.+)$/i';
		if ( preg_match( $range_pattern, $line, $matches ) ) {
			return array(
				'start_time'  => self::normalize_time( $matches[1] ),
				'end_time'    => self::normalize_time( $matches[2] ),
				'raw_text'    => $line,
				'description' => self::clean_description( $matches[3] ),
				'tags'        => implode( ',', pltt_extract_tags( $matches[3] ) ),
				'is_end'      => false,
			);
		}

		// Try to match single timestamp: @9:15am - Description.
		$single_pattern = '/^@' . $time_pattern . '\s*-?\s*(.*)$/i';
		if ( preg_match( $single_pattern, $line, $matches ) ) {
			$description = trim( $matches[2] );

			// Check if this is an end marker.
			$is_end = self::is_end_marker( $description );

			return array(
				'start_time'  => self::normalize_time( $matches[1] ),
				'end_time'    => null,
				'raw_text'    => $line,
				'description' => $is_end ? '' : self::clean_description( $description ),
				'tags'        => $is_end ? '' : implode( ',', pltt_extract_tags( $description ) ),
				'is_end'      => $is_end,
			);
		}

		return false;
	}

	/**
	 * Normalize time string to H:i:s format.
	 *
	 * @param string $time Time string (e.g., "9:15am", "14:30").
	 * @return string Normalized time in H:i:s format.
	 */
	public static function normalize_time( $time ) {
		$time = trim( strtolower( $time ) );

		// Remove spaces.
		$time = str_replace( ' ', '', $time );

		// Handle formats without colon (e.g., "9am").
		if ( preg_match( '/^(\d{1,2})(am|pm)$/i', $time, $matches ) ) {
			$time = $matches[1] . ':00' . $matches[2];
		}

		// Parse and format without timezone conversion.
		$dt = date_create( '2000-01-01 ' . $time );
		if ( ! $dt ) {
			return '00:00:00';
		}

		return $dt->format( 'H:i:s' );
	}

	/**
	 * Check if description is an end marker.
	 *
	 * Handles variations like "done", "Done!", "done.", "done for today", etc.
	 *
	 * @param string $description Description text.
	 * @return bool True if this is an end marker.
	 */
	private static function is_end_marker( $description ) {
		$end_markers = array( 'done', 'end', 'finished', 'stop', 'break', 'lunch', 'eod', 'pause' );

		// Normalize: lowercase, trim, remove trailing punctuation.
		$clean = strtolower( trim( $description ) );
		$clean = rtrim( $clean, '.!,' );

		// Empty description is an end marker.
		if ( empty( $clean ) ) {
			return true;
		}

		// Exact match.
		if ( in_array( $clean, $end_markers, true ) ) {
			return true;
		}

		// Check if starts with an end marker word (e.g., "done for today").
		foreach ( $end_markers as $marker ) {
			if ( strpos( $clean, $marker ) === 0 ) {
				// Make sure it's a word boundary (not "donation" matching "done").
				$next_char_pos = strlen( $marker );
				if ( strlen( $clean ) === $next_char_pos || ! ctype_alpha( $clean[ $next_char_pos ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Clean description text.
	 *
	 * Removes hashtags and extra whitespace.
	 *
	 * @param string $description Raw description.
	 * @return string Cleaned description.
	 */
	private static function clean_description( $description ) {
		// Remove hashtags.
		$clean = pltt_remove_tags( $description );

		// Normalize whitespace.
		$clean = preg_replace( '/\s+/', ' ', $clean );

		return trim( $clean );
	}

	/**
	 * Calculate durations for sequential entries.
	 *
	 * @param array $entries Array of parsed entries.
	 * @return array Entries with duration_minutes populated.
	 */
	public static function calculate_durations( $entries ) {
		$count = count( $entries );

		for ( $i = 0; $i < $count; $i++ ) {
			// Skip end markers.
			if ( ! empty( $entries[ $i ]['is_end'] ) ) {
				continue;
			}

			// If explicit end_time is set, calculate from that.
			if ( ! empty( $entries[ $i ]['end_time'] ) ) {
				$start_minutes = pltt_time_to_minutes( $entries[ $i ]['start_time'] );
				$end_minutes   = pltt_time_to_minutes( $entries[ $i ]['end_time'] );

				if ( false !== $start_minutes && false !== $end_minutes ) {
					$entries[ $i ]['duration_minutes'] = max( 0, $end_minutes - $start_minutes );
				}
				continue;
			}

			// Look for next entry to determine end time.
			if ( $i + 1 < $count ) {
				$next_start = $entries[ $i + 1 ]['start_time'];
				$this_start = $entries[ $i ]['start_time'];

				$start_minutes = pltt_time_to_minutes( $this_start );
				$end_minutes   = pltt_time_to_minutes( $next_start );

				if ( false !== $start_minutes && false !== $end_minutes ) {
					$entries[ $i ]['end_time']         = $next_start;
					$entries[ $i ]['duration_minutes'] = max( 0, $end_minutes - $start_minutes );
				}
			} else {
				// Last entry without explicit end - leave duration null.
				$entries[ $i ]['duration_minutes'] = null;
			}
		}

		// Remove end marker entries and empty entries.
		// End markers were only used for duration calculation.
		$entries = array_filter(
			$entries,
			function ( $entry ) {
				// Remove if it's an end marker.
				if ( ! empty( $entry['is_end'] ) ) {
					return false;
				}
				// Remove if description is empty (likely an end marker that wasn't flagged).
				if ( empty( trim( $entry['description'] ?? '' ) ) ) {
					return false;
				}
				return true;
			}
		);

		return array_values( $entries );
	}

	/**
	 * Apply alias predictions to entries.
	 *
	 * Predicts the client from the best client-bearing alias, and — via a
	 * direct alias-to-project hit — the project from the best project-bearing
	 * alias. When no alias resolves a project, the review screen still falls
	 * back to its most-recent-active-project recency ordering.
	 *
	 * @param array $entries Array of entries.
	 * @return array Entries with predicted client_id and (when hit) project_id.
	 */
	public static function apply_predictions( $entries ) {
		foreach ( $entries as &$entry ) {
			$description = $entry['description'] ?? '';
			$raw_text    = $entry['raw_text'] ?? '';
			$text        = $description . ' ' . $raw_text;

			// Client from the best client-bearing alias.
			$client_match = PLTT_Aliases::get_best_client_match( $text );

			if ( $client_match && ! empty( $client_match->client_id ) ) {
				$entry['predicted_client_id'] = $client_match->client_id;
				$entry['client_confidence']   = $client_match->confidence;
			}

			// Direct alias-to-project hit. Only accept it when it agrees with
			// the predicted client (or no client was predicted) so the two
			// predictions can't contradict; a lone project alias also supplies
			// its parent client.
			$project_match = PLTT_Aliases::get_best_project_match( $text );

			if ( $project_match && ! empty( $project_match->project_id ) ) {
				$predicted_client = (int) ( $entry['predicted_client_id'] ?? 0 );

				if ( ! $predicted_client || (int) $project_match->client_id === $predicted_client ) {
					$entry['predicted_project_id'] = $project_match->project_id;
					$entry['project_confidence']   = $project_match->confidence;

					if ( ! $predicted_client && ! empty( $project_match->client_id ) ) {
						$entry['predicted_client_id'] = $project_match->client_id;
						$entry['client_confidence']   = $project_match->confidence;
					}
				}
			}
		}

		return $entries;
	}

	/**
	 * Validate parsed entries.
	 *
	 * @param array $entries Array of entries.
	 * @return array Validation results with errors.
	 */
	public static function validate( $entries ) {
		$errors = array();

		foreach ( $entries as $index => $entry ) {
			// Check for missing start time.
			if ( empty( $entry['start_time'] ) || '00:00:00' === $entry['start_time'] ) {
				$errors[] = sprintf(
					/* translators: %d: entry number */
					__( 'Entry %d has an invalid start time.', 'plain-language-time-tracker' ),
					$index + 1
				);
			}

			// Check for missing description.
			if ( empty( $entry['description'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: entry number */
					__( 'Entry %d has no description.', 'plain-language-time-tracker' ),
					$index + 1
				);
			}

			// Check for extremely long durations (> 12 hours).
			if ( ! empty( $entry['duration_minutes'] ) && $entry['duration_minutes'] > 720 ) {
				$errors[] = sprintf(
					/* translators: %d: entry number */
					__( 'Entry %d has an unusually long duration (over 12 hours). Please verify.', 'plain-language-time-tracker' ),
					$index + 1
				);
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}
}

<?php
/**
 * Helper functions.
 *
 * Pure utility functions with no side effects.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format minutes as hours and minutes string.
 *
 * OPT-L6 SYNC: Output format must match PLTT.formatDuration() in assets/js/shared.js.
 * Update both if the format changes.
 *
 * @param int $minutes Total minutes.
 * @return string Formatted duration (e.g., "2h 30m").
 */
function pltt_format_duration( $minutes ) {
	if ( ! is_numeric( $minutes ) || $minutes < 0 ) {
		return '0m';
	}

	$minutes = (int) round( $minutes );
	$hours   = (int) floor( $minutes / 60 );
	$mins    = $minutes % 60;

	if ( $hours > 0 && $mins > 0 ) {
		return sprintf( '%dh %dm', $hours, $mins );
	} elseif ( $hours > 0 ) {
		return sprintf( '%dh', $hours );
	} else {
		return sprintf( '%dm', $mins );
	}
}

/**
 * Format minutes as decimal hours.
 *
 * @param int $minutes Total minutes.
 * @return string Formatted hours (e.g., "2.5").
 */
function pltt_format_hours( $minutes ) {
	if ( ! is_numeric( $minutes ) || $minutes < 0 ) {
		return '0.00';
	}

	return number_format( $minutes / 60, 2 );
}

/**
 * Build the PlttTooltip attributes for a decimal-hours hover hint.
 *
 * Figures are shown in h/m (easy to read), but the exact decimal-hours value is
 * what gets typed into invoice line items. This attaches a hover/focus tooltip
 * that reveals that decimal (e.g. "= 34.92 h") without cluttering the display.
 * Returns a ready-to-echo, pre-escaped attribute string; echo it inside a tag.
 *
 * @param int $minutes Total minutes.
 * @return string Escaped `data-pltt-tip` attribute string.
 */
function pltt_decimal_hint_attrs( $minutes ) {
	/* translators: %s: exact hours as a decimal, e.g. "34.92". */
	$line = sprintf( __( '= %s h', 'plain-language-time-tracker' ), pltt_format_hours( $minutes ) );
	$rows = array( array( '', $line ) );

	return 'data-pltt-tip data-tip-color="none" tabindex="0" data-tip-rows=\'' . esc_attr( wp_json_encode( $rows ) ) . '\'';
}

/**
 * Convert time string to minutes since midnight.
 *
 * @param string $time Time string (e.g., "9:15am", "14:30").
 * @return int|false Minutes since midnight, or false on failure.
 */
function pltt_time_to_minutes( $time ) {
	$dt = date_create( '2000-01-01 ' . $time );
	if ( ! $dt ) {
		return false;
	}

	$hours   = (int) $dt->format( 'G' );
	$minutes = (int) $dt->format( 'i' );

	return ( $hours * 60 ) + $minutes;
}

/**
 * Format a Y-m-d date string for display.
 *
 * Interprets the date in the WordPress timezone so that the displayed
 * day matches the stored date (avoids UTC midnight shifting to the
 * previous day in western timezones).
 *
 * @param string $date   Date in Y-m-d format.
 * @param string $format PHP date format string.
 * @return string Formatted date.
 */
function pltt_format_date( $date, $format = 'l, F j, Y' ) {
	$dt = new DateTimeImmutable( $date, wp_timezone() );
	return wp_date( $format, $dt->getTimestamp() );
}

/**
 * Format a stored H:i:s time string for display.
 *
 * Converts 24-hour database time (e.g. "09:15:00") to a friendly
 * format (e.g. "9:15am") without any timezone conversion.
 *
 * @param string $time   Time in H:i:s format.
 * @param string $format PHP date format string for time portion.
 * @return string Formatted time, or empty string if invalid.
 */
function pltt_format_time( $time, $format = 'g:ia' ) {
	if ( empty( $time ) ) {
		return '';
	}
	$dt = date_create( '2000-01-01 ' . $time );
	return $dt ? $dt->format( $format ) : '';
}

/**
 * Get current date in Y-m-d format.
 *
 * @return string Current date.
 */
function pltt_get_current_date() {
	return current_time( 'Y-m-d' );
}

/**
 * Validate date string format.
 *
 * @param string $date Date string to validate.
 * @return bool True if valid Y-m-d format.
 */
function pltt_validate_date( $date ) {
	if ( ! is_string( $date ) ) {
		return false;
	}

	$d = DateTime::createFromFormat( 'Y-m-d', $date );
	return $d && $d->format( 'Y-m-d' ) === $date;
}

/**
 * Render admin success/error notices from query params. OPT-DUP1.
 *
 * Reads $_GET['pltt_message'] and $_GET['pltt_error'] and echoes the matching
 * notice div if the code is in the provided allowlist map. Codes not in the
 * map are silently ignored (defense against arbitrary query-string injection).
 *
 * @param array $messages Map of message code => localized success label.
 * @param array $errors   Map of error code   => localized error label.
 */
function pltt_render_admin_notices( $messages = array(), $errors = array() ) {
	if ( isset( $_GET['pltt_message'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message_code = sanitize_text_field( wp_unslash( $_GET['pltt_message'] ) );
		if ( isset( $messages[ $message_code ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $message_code ] ) . '</p></div>';
		}
	}

	if ( isset( $_GET['pltt_error'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error_code = sanitize_text_field( wp_unslash( $_GET['pltt_error'] ) );
		if ( isset( $errors[ $error_code ] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $error_code ] ) . '</p></div>';
		}
	}
}

/**
 * Sanitize and validate a date, returning current date if invalid.
 *
 * For destructive operations (delete/replace based on the supplied date),
 * use pltt_sanitize_date_strict() instead — silently falling back to today
 * would cause an invalid date to wipe today's data.
 *
 * @param string $date Date string.
 * @return string Valid date in Y-m-d format.
 */
function pltt_sanitize_date( $date ) {
	$date = sanitize_text_field( $date );
	return pltt_validate_date( $date ) ? $date : pltt_get_current_date();
}

/**
 * Sanitize a date, returning empty string when invalid.
 *
 * Use this in destructive paths (process_log, delete_daily_log,
 * save_entries) so that bad input fails closed instead of silently
 * targeting today's data. SEC-M3.
 *
 * @param string $date Date string.
 * @return string Valid Y-m-d date, or '' when invalid.
 */
function pltt_sanitize_date_strict( $date ) {
	$date = sanitize_text_field( $date );
	return pltt_validate_date( $date ) ? $date : '';
}

/**
 * Extract hashtags from text.
 *
 * Supports multi-word tags. Tags continue until they hit:
 * - Another # (new tag)
 * - Comma, period, or dash with spaces
 * - End of string
 *
 * @param string $text Text to search.
 * @return array Array of tags (without # prefix).
 */
function pltt_extract_tags( $text ) {
	preg_match_all( '/#([a-zA-Z0-9_-]+(?:\s+[a-zA-Z0-9_-]+)*?)(?=\s*#|[,.]|\s+-\s+|$)/', $text, $matches );

	// Trim whitespace from each tag and filter empty values.
	$tags = array_map( 'trim', $matches[1] );
	$tags = array_filter( $tags );

	return array_unique( $tags );
}

/**
 * Remove hashtags from text.
 *
 * Removes multi-word tags that end at punctuation or another #.
 *
 * @param string $text Text to clean.
 * @return string Text without hashtags.
 */
function pltt_remove_tags( $text ) {
	return trim( preg_replace( '/#[a-zA-Z0-9_-]+(?:\s+[a-zA-Z0-9_-]+)*?(?=\s*#|[,.]|\s+-\s+|$)/', '', $text ) );
}

/**
 * Determine which half of the day an entry's raw text indicates.
 *
 * Returns 'am' if only am tokens are present, 'pm' if only pm tokens are
 * present, 'mixed' if both, or null if neither. Used by the Review screen
 * to detect AM/PM mix-ups across adjacent entries.
 *
 * @param string $raw_text Original log line.
 * @return string|null 'am', 'pm', 'mixed', or null.
 */
function pltt_entry_ampm_side( $raw_text ) {
	if ( empty( $raw_text ) ) {
		return null;
	}
	$has_am = (bool) preg_match( '/\bam\b/i', $raw_text );
	$has_pm = (bool) preg_match( '/\bpm\b/i', $raw_text );
	if ( $has_am && $has_pm ) {
		return 'mixed';
	}
	if ( $has_am ) {
		return 'am';
	}
	if ( $has_pm ) {
		return 'pm';
	}
	return null;
}

/**
 * Compute per-entry warning flags for the Review screen.
 *
 * Expects entries sorted by start_time ASC (the order PLTT_Review uses).
 * Returns a map of entry_id => warning reasons. Three independent checks:
 *
 *   - 'long_duration': duration > 4h. Catches the common case where an
 *     inflated duration comes from a filtered `Done` end marker whose
 *     own AM/PM was mistyped — that raw text is gone by render time,
 *     so duration is the only remaining signal.
 *   - 'island': raw text's am/pm side disagrees with both neighbors AND duration > 3h.
 *   - 'backwards': parse-order rank (id ASC) ≠ chronological rank AND duration > 3h.
 *
 * @param array $entries Formatted entries with id, start_time, duration_minutes, raw_text.
 * @return array Map: entry_id => array of reason flags.
 */
function pltt_compute_entry_warnings( array $entries ) {
	$warnings = array();
	if ( empty( $entries ) ) {
		return $warnings;
	}

	// Within a date, entry IDs are assigned in parse/line order
	// (PLTT_Ajax::process_log deletes and recreates in order).
	$by_id = $entries;
	usort(
		$by_id,
		function ( $a, $b ) {
			return ( (int) ( $a['id'] ?? 0 ) ) <=> ( (int) ( $b['id'] ?? 0 ) );
		}
	);
	$id_rank = array();
	foreach ( $by_id as $rank => $e ) {
		if ( ! empty( $e['id'] ) ) {
			$id_rank[ (int) $e['id'] ] = $rank;
		}
	}

	foreach ( $entries as $time_rank => $entry ) {
		$id       = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
		$duration = (int) ( $entry['duration_minutes'] ?? 0 );
		$raw      = $entry['raw_text'] ?? '';
		if ( $id <= 0 ) {
			continue;
		}

		if ( $duration > 240 ) {
			$warnings[ $id ]['long_duration'] = true;
		}

		if ( $duration > 180 ) {
			$side      = pltt_entry_ampm_side( $raw );
			$prev      = $entries[ $time_rank - 1 ] ?? null;
			$next      = $entries[ $time_rank + 1 ] ?? null;
			$prev_side = $prev ? pltt_entry_ampm_side( $prev['raw_text'] ?? '' ) : null;
			$next_side = $next ? pltt_entry_ampm_side( $next['raw_text'] ?? '' ) : null;
			if (
				in_array( $side, array( 'am', 'pm' ), true )
				&& ( $prev_side || $next_side )
				&& ( null === $prev_side || $prev_side !== $side )
				&& ( null === $next_side || $next_side !== $side )
			) {
				$warnings[ $id ]['island'] = true;
			}
		}

		if ( $duration > 180 && isset( $id_rank[ $id ] ) && $id_rank[ $id ] !== $time_rank ) {
			$warnings[ $id ]['backwards'] = true;
		}
	}

	return $warnings;
}

/**
 * Check if current user can access the time tracker.
 *
 * @return bool True if user has access.
 */
function pltt_user_can_access() {
	return current_user_can( 'manage_options' );
}

/**
 * Return the ID of the internal (non-billable) client.
 *
 * Looks up the client with is_internal = 1, added in DB version 1.9.3.
 * Result is cached in a static for the lifetime of the request.
 * Returns 0 if no internal client exists (safe to use in comparisons).
 *
 * @return int Client ID, or 0 if not found.
 */
function pltt_get_internal_client_id() {
	static $id = null;
	if ( null === $id ) {
		global $wpdb;
		$table = PLTT_Database::get_table_name( 'clients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( "SELECT id FROM {$table} WHERE is_internal = 1 LIMIT 1" );
		$id     = $result ? (int) $result : 0;
	}
	return $id;
}

/**
 * Get the admin page URL for a specific screen.
 *
 * @param string $screen Screen slug (daily-log, review, reports).
 * @param array  $args   Additional query args.
 * @return string Admin page URL.
 */
function pltt_get_admin_url( $screen = 'daily-log', $args = array() ) {
	$base_args = array( 'page' => 'pltt-time-tracker' );

	if ( 'daily-log' !== $screen ) {
		$base_args['screen'] = $screen;
	}

	return add_query_arg( array_merge( $base_args, $args ), admin_url( 'admin.php' ) );
}

/**
 * Format a dollar amount for display.
 *
 * @param float|string|null $amount Dollar amount.
 * @return string Formatted currency string (e.g. "$150.00") or "—" if null/zero.
 */
function pltt_format_currency( $amount ) {
	if ( null === $amount || '' === $amount ) {
		return '—';
	}

	$amount = floatval( $amount );

	return '$' . number_format( $amount, 2 );
}

/**
 * Format a dollar amount without cents when the value is whole.
 *
 * "$300", "$5,800", but "$150.50" keeps its cents. Used in compact contexts
 * like the project context card's info line where a trailing ".00" is noise.
 *
 * @param float|string|null $amount Dollar amount.
 * @return string Formatted currency string, or "—" if null/empty.
 */
function pltt_format_currency_compact( $amount ) {
	if ( null === $amount || '' === $amount ) {
		return '—';
	}

	$amount = floatval( $amount );

	return floor( $amount ) === $amount
		? '$' . number_format( $amount, 0 )
		: '$' . number_format( $amount, 2 );
}

/**
 * Calculate the previous period dates for comparison.
 *
 * Given a date range, returns the equivalent previous period (same duration, shifted back).
 *
 * @param string $date_from Current period start date (Y-m-d).
 * @param string $date_to   Current period end date (Y-m-d).
 * @return array Associative array with 'from' and 'to' keys.
 */
function pltt_get_previous_period( $date_from, $date_to ) {
	$start = new DateTimeImmutable( $date_from, wp_timezone() );
	$end   = new DateTimeImmutable( $date_to, wp_timezone() );

	$days       = (int) $start->diff( $end )->format( '%a' ) + 1;
	$prev_end   = $start->modify( '-1 day' );
	$prev_start = $prev_end->modify( '-' . ( $days - 1 ) . ' days' );

	return array(
		'from' => $prev_start->format( 'Y-m-d' ),
		'to'   => $prev_end->format( 'Y-m-d' ),
	);
}

/**
 * Format a date range as a human-readable label.
 *
 * Mirrors the JS label logic in reports.js so the server-side initial render
 * matches what JS would produce after navigation.
 *
 * @param string $from Start date (Y-m-d).
 * @param string $to   End date (Y-m-d).
 * @return string Human-readable label.
 */
function pltt_format_date_range( $from, $to ) {
	$tz       = wp_timezone();
	$from_dt  = new DateTimeImmutable( $from, $tz );
	$to_dt    = new DateTimeImmutable( $to, $tz );

	$from_y  = $from_dt->format( 'Y' );
	$to_y    = $to_dt->format( 'Y' );
	$from_m  = $from_dt->format( 'n' );
	$to_m    = $to_dt->format( 'n' );
	$from_d  = $from_dt->format( 'j' );
	$to_d    = $to_dt->format( 'j' );

	// Full calendar year: Jan 1 – Dec 31 of the same year.
	if ( $from_y === $to_y && '1' === $from_m && '1' === $from_d && '12' === $to_dt->format( 'n' ) && '31' === $to_dt->format( 'j' ) ) {
		return $from_y;
	}

	// Full calendar month: 1st → last day of the same month.
	if ( $from_y === $to_y && $from_m === $to_m && '1' === $from_d ) {
		$last_day = $from_dt->format( 't' );
		if ( $to_d === $last_day ) {
			return $from_dt->format( 'F Y' );
		}
	}

	// Same month and year: "Mar 1–21, 2026".
	if ( $from_y === $to_y && $from_m === $to_m ) {
		return $from_dt->format( 'M j' ) . '–' . $to_d . ', ' . $from_y;
	}

	// Same year, different month: "Mar 12 – Apr 30, 2026".
	if ( $from_y === $to_y ) {
		return $from_dt->format( 'M j' ) . ' – ' . $to_dt->format( 'M j' ) . ', ' . $from_y;
	}

	// Cross-year: "Dec 15, 2025 – Jan 15, 2026".
	return $from_dt->format( 'M j, Y' ) . ' – ' . $to_dt->format( 'M j, Y' );
}

/**
 * Pick a bucket size (day/week/month) for the reports chart based on range length.
 *
 * @param string $date_from Start date (Y-m-d).
 * @param string $date_to   End date (Y-m-d).
 * @return string 'day' | 'week' | 'month'
 */
function pltt_resolve_bucket_size( $date_from, $date_to ) {
	$start = new DateTimeImmutable( $date_from, wp_timezone() );
	$end   = new DateTimeImmutable( $date_to, wp_timezone() );
	$days  = (int) $start->diff( $end )->format( '%a' ) + 1;

	if ( $days <= 31 ) {
		return 'day';
	}
	if ( $days <= 92 ) {
		return 'week';
	}
	return 'month';
}

/**
 * Build chart bucket descriptors for a date range.
 *
 * Returns one entry per bucket between $date_from and $date_to (inclusive),
 * each with start/end dates, a key for matching against daily rows, and
 * short/long labels for the visual bar and the screen-reader data table.
 *
 * Weekly buckets respect the WP `start_of_week` option so they align with
 * the user's expected week boundaries.
 *
 * @param string $date_from   Start date (Y-m-d).
 * @param string $date_to     End date (Y-m-d).
 * @param string $bucket_size 'day' | 'week' | 'month'.
 * @return array<int, array{key:string, start:string, end:string, short:string, long:string}>
 */
function pltt_build_chart_buckets( $date_from, $date_to, $bucket_size ) {
	$tz    = wp_timezone();
	$start = new DateTimeImmutable( $date_from, $tz );
	$end   = new DateTimeImmutable( $date_to, $tz );

	$cross_year = $start->format( 'Y' ) !== $end->format( 'Y' );

	if ( 'day' === $bucket_size ) {
		$buckets = array();
		$cursor  = $start;
		while ( $cursor <= $end ) {
			$ymd       = $cursor->format( 'Y-m-d' );
			$dow       = (int) $cursor->format( 'N' ); // 1=Mon..7=Sun
			$buckets[] = array(
				'key'         => $ymd,
				'start'       => $ymd,
				'end'         => $ymd,
				'short_top'   => $cursor->format( 'D' ), // Short day-of-week, e.g. "Mon".
				'short'       => $cursor->format( 'n/j' ), // Numeric date, e.g. "5/4".
				'long'        => $cursor->format( 'F j, Y' ),
				'is_weekend'  => ( $dow >= 6 ),
			);
			$cursor = $cursor->modify( '+1 day' );
		}
		return $buckets;
	}

	if ( 'month' === $bucket_size ) {
		$buckets = array();
		$cursor  = $start->modify( 'first day of this month' );
		$last    = $end->modify( 'first day of this month' );
		while ( $cursor <= $last ) {
			$month_start = $cursor->format( 'Y-m-01' );
			$month_end   = $cursor->format( 'Y-m-t' );
			// Clip to the requested range edges for the screen-reader cell.
			$clipped_start = max( $month_start, $date_from );
			$clipped_end   = min( $month_end, $date_to );
			$buckets[]     = array(
				'key'   => $cursor->format( 'Y-m' ),
				'start' => $clipped_start,
				'end'   => $clipped_end,
				'short' => $cross_year ? $cursor->format( "M 'y" ) : $cursor->format( 'M' ),
				'long'  => $cursor->format( 'F Y' ),
			);
			$cursor = $cursor->modify( '+1 month' );
		}
		return $buckets;
	}

	// Weekly buckets, aligned to start_of_week.
	$week_start_dow = (int) get_option( 'start_of_week', 0 ); // 0=Sun..6=Sat
	$start_dow      = (int) $start->format( 'w' );
	$shift          = ( $start_dow - $week_start_dow + 7 ) % 7;
	$cursor         = $start->modify( "-{$shift} days" );

	$buckets = array();
	while ( $cursor <= $end ) {
		$week_start = $cursor;
		$week_end   = $cursor->modify( '+6 days' );

		// Clip to the requested range edges for the screen-reader cell.
		$clipped_start = max( $week_start->format( 'Y-m-d' ), $date_from );
		$clipped_end   = min( $week_end->format( 'Y-m-d' ), $date_to );

		$buckets[] = array(
			'key'   => $week_start->format( 'Y-m-d' ),
			'start' => $clipped_start,
			'end'   => $clipped_end,
			'short' => $cross_year ? $week_start->format( "M j 'y" ) : $week_start->format( 'M j' ),
			/* translators: %s: human-readable week start date, e.g. "May 4, 2026". */
			'long'  => sprintf( __( 'Week of %s', 'plain-language-time-tracker' ), $week_start->format( 'F j, Y' ) ),
		);
		$cursor = $cursor->modify( '+7 days' );
	}
	return $buckets;
}

/**
 * Build the volume-chart context for a date range and filter set.
 *
 * Resolves a bucket size (day/week/month) from the range length, lays out the
 * buckets, folds verified daily totals into them, and computes the y-axis max,
 * the active-bucket average, and which bucket holds "today". Shared by the
 * Reports summary chart and the Project Detail volume chart so the two stay in
 * lock-step (OPT-DUP).
 *
 * @param string $date_from   Start date (Y-m-d).
 * @param string $date_to     End date (Y-m-d).
 * @param array  $filter_args Entry filters passed to get_chart_daily_totals()
 *                            (e.g. project_id, client_id, tag, billable, billed).
 * @return array{
 *     buckets:array, bucket_size:string, max_minutes:int, avg_minutes:int, today_key:string
 * }
 */
/**
 * Round a chart's max minutes up to a "nice" y-axis ceiling, in hours.
 *
 * Keeps the axis-scale rule out of the view (OPT-PERF-B): ≤1h → 1, ≤5h → next
 * whole hour, ≤20h → next even hour, else → next multiple of 5.
 *
 * @param int $max_minutes Tallest bar's minutes.
 * @return float Ceiling in hours (multiply by 60 for the minutes ceiling).
 */
function pltt_chart_y_ceiling( $max_minutes ) {
	$max_hours = (int) $max_minutes / 60;
	if ( $max_hours <= 1 ) {
		return 1.0;
	}
	if ( $max_hours <= 5 ) {
		return ceil( $max_hours );
	}
	if ( $max_hours <= 20 ) {
		return ceil( $max_hours / 2 ) * 2;
	}
	return ceil( $max_hours / 5 ) * 5;
}

function pltt_build_period_chart_data( $date_from, $date_to, $filter_args = array() ) {
	$bucket_size = pltt_resolve_bucket_size( $date_from, $date_to );
	$buckets     = pltt_build_chart_buckets( $date_from, $date_to, $bucket_size );

	$max_minutes = 0;
	$avg_minutes = 0;
	$today_key   = '';

	if ( empty( $buckets ) ) {
		return compact( 'buckets', 'bucket_size', 'max_minutes', 'avg_minutes', 'today_key' );
	}

	$daily_rows = PLTT_Entries::get_chart_daily_totals( $date_from, $date_to, $filter_args );

	// Initialize totals on each bucket and build a key -> index map for O(1) lookup.
	$bucket_index = array();
	foreach ( $buckets as $i => $bucket ) {
		$buckets[ $i ]['billable_minutes']    = 0;
		$buckets[ $i ]['client_flat_minutes'] = 0;
		$buckets[ $i ]['internal_minutes']    = 0;
		$bucket_index[ $bucket['key'] ]       = $i;
	}

	foreach ( $daily_rows as $row ) {
		$ymd = $row->entry_date;
		if ( 'day' === $bucket_size ) {
			$key = $ymd;
		} elseif ( 'month' === $bucket_size ) {
			$key = substr( $ymd, 0, 7 );
		} else {
			// Weekly: find the bucket whose start <= ymd <= end.
			$key = null;
			foreach ( $buckets as $bucket ) {
				if ( $ymd >= $bucket['start'] && $ymd <= $bucket['end'] ) {
					$key = $bucket['key'];
					break;
				}
			}
		}

		if ( null === $key || ! isset( $bucket_index[ $key ] ) ) {
			continue;
		}

		$i = $bucket_index[ $key ];
		$buckets[ $i ]['billable_minutes']    += (int) $row->billable_minutes;
		$buckets[ $i ]['client_flat_minutes'] += (int) $row->client_flat_minutes;
		$buckets[ $i ]['internal_minutes']    += (int) $row->internal_minutes;
	}

	// Max for the y-axis scale; average over only buckets with logged time so
	// empty days/weeks/months (weekends, leave, future days) don't dilute it.
	$total_minutes  = 0;
	$active_buckets = 0;
	foreach ( $buckets as $i => $bucket ) {
		$bucket_total = (int) $bucket['billable_minutes']
			+ (int) $bucket['client_flat_minutes']
			+ (int) $bucket['internal_minutes'];
		// Store the per-bucket total so the view doesn't recompute it (OPT-PERF-C).
		$buckets[ $i ]['total_minutes'] = $bucket_total;
		if ( $bucket_total > $max_minutes ) {
			$max_minutes = $bucket_total;
		}
		$total_minutes += $bucket_total;
		if ( $bucket_total > 0 ) {
			$active_buckets++;
		}
	}
	$avg_minutes = $active_buckets > 0 ? (int) round( $total_minutes / $active_buckets ) : 0;

	// Identify which bucket (if any) contains today, for the "today" marker.
	$today_ymd = pltt_get_current_date();
	if ( $today_ymd >= $date_from && $today_ymd <= $date_to ) {
		if ( 'day' === $bucket_size ) {
			$today_key = $today_ymd;
		} elseif ( 'month' === $bucket_size ) {
			$today_key = substr( $today_ymd, 0, 7 );
		} else {
			$week_start_dow = (int) get_option( 'start_of_week', 0 );
			$today_dt       = new DateTimeImmutable( $today_ymd, wp_timezone() );
			$today_dow      = (int) $today_dt->format( 'w' );
			$shift          = ( $today_dow - $week_start_dow + 7 ) % 7;
			$today_key      = $today_dt->modify( "-{$shift} days" )->format( 'Y-m-d' );
		}
	}

	return compact( 'buckets', 'bucket_size', 'max_minutes', 'avg_minutes', 'today_key' );
}

/**
 * Map a project recurring_period to a chart stepping unit.
 *
 * @param string $recurring_period '' | weekly | monthly | quarterly | yearly.
 * @return string 'week' | 'month' | 'quarter' | 'year'.
 */
function pltt_chart_period_unit( $recurring_period ) {
	switch ( $recurring_period ) {
		case 'weekly':
			return 'week';
		case 'quarterly':
			return 'quarter';
		case 'yearly':
			return 'year';
		case 'monthly':
		default:
			return 'month';
	}
}

/**
 * Canonical start date of the period (of the given unit) that contains $date.
 *
 * @param DateTimeImmutable $date Any date inside the period.
 * @param string            $unit 'week' | 'month' | 'quarter' | 'year'.
 * @return DateTimeImmutable Period start (midnight).
 */
function pltt_period_start( DateTimeImmutable $date, $unit ) {
	switch ( $unit ) {
		case 'week':
			$wsd   = (int) get_option( 'start_of_week', 0 );
			$dow   = (int) $date->format( 'w' );
			$shift = ( $dow - $wsd + 7 ) % 7;
			return $date->modify( "-{$shift} days" );
		case 'quarter':
			$month  = (int) $date->format( 'n' );
			$qstart = (int) ( floor( ( $month - 1 ) / 3 ) * 3 ) + 1;
			return $date->setDate( (int) $date->format( 'Y' ), $qstart, 1 );
		case 'year':
			return $date->setDate( (int) $date->format( 'Y' ), 1, 1 );
		case 'month':
		default:
			return $date->modify( 'first day of this month' );
	}
}

/**
 * Canonical end date of a period that starts at $start.
 *
 * @param DateTimeImmutable $start Period start.
 * @param string            $unit  'week' | 'month' | 'quarter' | 'year'.
 * @return DateTimeImmutable Period end (inclusive).
 */
function pltt_period_end( DateTimeImmutable $start, $unit ) {
	switch ( $unit ) {
		case 'week':
			return $start->modify( '+6 days' );
		case 'quarter':
			return $start->modify( '+3 months -1 day' );
		case 'year':
			return $start->modify( '+1 year -1 day' );
		case 'month':
		default:
			return $start->modify( 'last day of this month' );
	}
}

/**
 * Human label for a period that starts at $start.
 *
 * @param DateTimeImmutable $start Period start.
 * @param string            $unit  'week' | 'month' | 'quarter' | 'year'.
 * @return string e.g. "June 2026", "Q2 2026", "Week of Jun 9, 2026", "2026".
 */
function pltt_period_label( DateTimeImmutable $start, $unit ) {
	switch ( $unit ) {
		case 'week':
			/* translators: %s: week start date, e.g. "Jun 9, 2026". */
			return sprintf( __( 'Week of %s', 'plain-language-time-tracker' ), $start->format( 'M j, Y' ) );
		case 'quarter':
			$q = (int) ( floor( ( (int) $start->format( 'n' ) - 1 ) / 3 ) + 1 );
			/* translators: 1: quarter number, 2: four-digit year. */
			return sprintf( __( 'Q%1$d %2$s', 'plain-language-time-tracker' ), $q, $start->format( 'Y' ) );
		case 'year':
			return $start->format( 'Y' );
		case 'month':
		default:
			return $start->format( 'F Y' );
	}
}

/**
 * Resolve the active period window for the Project Detail report.
 *
 * Recurring projects get a steppable per-period lens — the stat cards, the
 * "Where the time went" bars, and the volume chart all reflect the chosen
 * window, while the swimlane stays the full lifetime arc. Every other billing
 * type stays on the full lifetime span with no control. Scope/period are driven
 * by the chart_scope ('full'|'period') and chart_period (Y-m-d anchor) query
 * args; anything out of range is clamped to the project's active span.
 *
 * @param string $billing_type     Resolved billing type (recurring/fixed/hourly/none).
 * @param string $recurring_period Project recurring_period (drives the step unit).
 * @param string $first_date       Project first entry date (Y-m-d).
 * @param string $last_date        Project last entry date (Y-m-d).
 * @param string $req_scope        Requested scope from the query ('full'|'period').
 * @param string $req_anchor       Requested period anchor from the query (Y-m-d).
 * @return array{
 *     show_control:bool, scope:string, unit:string, from:string, to:string,
 *     anchor:?string, prev_anchor:?string, next_anchor:?string, is_latest:bool,
 *     period_label:string, can_step:bool
 * }
 */
function pltt_resolve_project_chart_window( $billing_type, $recurring_period, $first_date, $last_date, $req_scope = '', $req_anchor = '' ) {
	$unit = pltt_chart_period_unit( $recurring_period );

	$full = array(
		'show_control' => false,
		'scope'        => 'full',
		'is_period'    => false,
		'unit'         => $unit,
		'from'         => $first_date,
		'to'           => $last_date,
		'anchor'       => null,
		'prev_anchor'  => null,
		'next_anchor'  => null,
		'is_latest'    => true,
		'period_label' => '',
		'can_step'     => false,
	);

	// Only recurring projects get the period lens; everything else is lifetime.
	if ( 'recurring' !== $billing_type || ! $first_date || ! $last_date ) {
		return $full;
	}

	$tz           = wp_timezone();
	$first        = new DateTimeImmutable( $first_date, $tz );
	$last         = new DateTimeImmutable( $last_date, $tz );
	$first_pstart = pltt_period_start( $first, $unit );
	$last_pstart  = pltt_period_start( $last, $unit );

	// The control always shows for recurring projects (so you can switch to Full);
	// stepping is only meaningful when the project spans more than one period.
	$full['show_control'] = true;
	$full['can_step']     = ( $first_pstart->format( 'Y-m-d' ) !== $last_pstart->format( 'Y-m-d' ) );

	$scope = in_array( $req_scope, array( 'full', 'period' ), true ) ? $req_scope : 'period';
	if ( 'full' === $scope ) {
		return $full;
	}

	// Period scope: resolve the anchor (default = most recent active period).
	$anchor = null;
	if ( $req_anchor ) {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $req_anchor, $tz );
		if ( $parsed instanceof DateTimeImmutable ) {
			$anchor = pltt_period_start( $parsed, $unit );
		}
	}
	if ( null === $anchor ) {
		$anchor = $last_pstart;
	}
	// Clamp to the project's active span of periods.
	if ( $anchor < $first_pstart ) {
		$anchor = $first_pstart;
	}
	if ( $anchor > $last_pstart ) {
		$anchor = $last_pstart;
	}

	$pend = pltt_period_end( $anchor, $unit );
	$prev = pltt_period_start( $anchor->modify( '-1 day' ), $unit );
	$next = pltt_period_start( $pend->modify( '+1 day' ), $unit );

	return array(
		'show_control' => true,
		'scope'        => 'period',
		'is_period'    => true,
		'unit'         => $unit,
		'from'         => $anchor->format( 'Y-m-d' ),
		'to'           => $pend->format( 'Y-m-d' ),
		'anchor'       => $anchor->format( 'Y-m-d' ),
		'prev_anchor'  => ( $prev >= $first_pstart ) ? $prev->format( 'Y-m-d' ) : null,
		'next_anchor'  => ( $next <= $last_pstart ) ? $next->format( 'Y-m-d' ) : null,
		'is_latest'    => ! ( $next <= $last_pstart ),
		'period_label' => pltt_period_label( $anchor, $unit ),
		'can_step'     => $full['can_step'],
	);
}

/**
 * Get cached aliases list.
 *
 * @return array Array of alias objects.
 */
function pltt_get_cached_aliases() {
	$cache_key = 'pltt_aliases_list';
	$aliases   = get_transient( $cache_key );

	if ( false === $aliases ) {
		$aliases = PLTT_Aliases::get_all();
		set_transient( $cache_key, $aliases, HOUR_IN_SECONDS );
	}

	return $aliases;
}

/**
 * Flush aliases cache.
 */
function pltt_flush_alias_cache() {
	delete_transient( 'pltt_aliases_list' );
}

/**
 * Apply alias chip-manager changes for a client or project settings form.
 *
 * Seeds added aliases at full confidence and prunes removed ones. Removals are
 * scoped to the entity being edited so a stale or forged form can't delete an
 * alias that belongs to a different client/project.
 *
 * @param array    $add        Alias texts to seed.
 * @param array    $remove     Alias IDs to prune.
 * @param int      $client_id  Owning client ID (also the project's parent).
 * @param int|null $project_id Owning project ID, or null for a client alias.
 */
function pltt_apply_alias_chip_changes( $add, $remove, $client_id, $project_id = null ) {
	$client_id  = absint( $client_id );
	$project_id = ! empty( $project_id ) ? absint( $project_id ) : null;

	if ( is_array( $add ) ) {
		foreach ( $add as $text ) {
			$text = sanitize_text_field( $text );
			if ( '' !== $text ) {
				PLTT_Aliases::seed( $text, $client_id, $project_id );
			}
		}
	}

	if ( is_array( $remove ) ) {
		foreach ( $remove as $alias_id ) {
			$alias_id = absint( $alias_id );
			$existing = $alias_id ? PLTT_Aliases::get( $alias_id ) : null;
			if ( ! $existing ) {
				continue;
			}

			if ( null !== $project_id ) {
				// Project alias: must belong to this project.
				if ( (int) $existing->project_id === $project_id ) {
					PLTT_Aliases::delete( $alias_id );
				}
			} elseif ( (int) $existing->client_id === $client_id && empty( $existing->project_id ) ) {
				// Client-level alias: must belong to this client and carry no project.
				PLTT_Aliases::delete( $alias_id );
			}
		}
	}
}

/**
 * Apply tag keyword chip-manager changes for the Tags settings form.
 *
 * Seeds added keywords (keyword -> tag) and prunes removed ones. Removals are
 * scoped to the tag being edited so a stale/forged form can't delete a keyword
 * bound to a different tag. Mirrors pltt_apply_alias_chip_changes().
 *
 * @param array $add    Keyword texts to seed.
 * @param array $remove Tag-alias row IDs to prune.
 * @param int   $tag_id Owning tag ID.
 */
function pltt_apply_tag_keyword_changes( $add, $remove, $tag_id ) {
	$tag_id = absint( $tag_id );
	if ( ! $tag_id ) {
		return;
	}

	if ( is_array( $add ) ) {
		foreach ( $add as $keyword ) {
			$keyword = sanitize_text_field( $keyword );
			if ( '' !== $keyword ) {
				PLTT_Tag_Aliases::seed( $keyword, $tag_id );
			}
		}
	}

	if ( is_array( $remove ) ) {
		foreach ( $remove as $row_id ) {
			$row_id   = absint( $row_id );
			$existing = $row_id ? PLTT_Tag_Aliases::get( $row_id ) : null;
			if ( $existing && (int) $existing->tag_id === $tag_id ) {
				PLTT_Tag_Aliases::delete( $row_id );
			}
		}
	}
}

/**
 * Render tag badges for a comma-separated tags string or array.
 *
 * Outputs badge spans for each tag, or an em-dash if empty.
 *
 * @param string|array $tags Comma-separated tag string or array of tag names.
 */
function pltt_render_tag_badges( $tags ) {
	$tag_list = is_array( $tags ) ? $tags : array_map( 'trim', explode( ',', $tags ) );
	$tag_list = array_filter( $tag_list );

	if ( ! empty( $tag_list ) ) {
		foreach ( $tag_list as $tag_item ) {
			echo '<span class="pltt-badge pltt-badge-tag">' . esc_html( ucfirst( $tag_item ) ) . '</span>';
		}
	} else {
		echo '<span class="pltt-empty">&mdash;</span>';
	}
}

/**
 * Render pagination controls.
 *
 * Outputs the standard WordPress-style tablenav pagination block.
 *
 * @param int    $paged          Current page number.
 * @param int    $total_pages    Total number of pages.
 * @param int    $total_items    Total number of items.
 * @param string $base_url       Base URL for pagination links.
 * @param string $singular_label Singular item label for display (e.g., 'entry').
 * @param string $plural_label   Plural item label for display (e.g., 'entries').
 */
function pltt_render_pagination( $paged, $total_pages, $total_items, $base_url, $singular_label, $plural_label ) {
	if ( $total_pages <= 1 ) {
		return;
	}
	?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				if ( 'log' === $singular_label ) {
					printf(
						/* translators: %s: number of logs */
						esc_html( _n( '%s log', '%s logs', $total_items, 'plain-language-time-tracker' ) ),
						esc_html( number_format_i18n( $total_items ) )
					);
				} else {
					printf(
						/* translators: %s: number of entries */
						esc_html( _n( '%s entry', '%s entries', $total_items, 'plain-language-time-tracker' ) ),
						esc_html( number_format_i18n( $total_items ) )
					);
				}
				?>
			</span>
			<span class="pagination-links">
				<?php if ( $paged > 1 ) : ?>
					<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $base_url ) ); ?>">
						&laquo;
					</a>
					<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ); ?>">
						&lsaquo;
					</a>
				<?php else : ?>
					<span class="tablenav-pages-navspan button disabled">&laquo;</span>
					<span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
				<?php endif; ?>

				<span class="paging-input">
					<?php echo esc_html( $paged ); ?>
					<?php esc_html_e( 'of', 'plain-language-time-tracker' ); ?>
					<span class="total-pages"><?php echo esc_html( $total_pages ); ?></span>
				</span>

				<?php if ( $paged < $total_pages ) : ?>
					<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ); ?>">
						&rsaquo;
					</a>
					<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ); ?>">
						&raquo;
					</a>
				<?php else : ?>
					<span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
					<span class="tablenav-pages-navspan button disabled">&raquo;</span>
				<?php endif; ?>
			</span>
		</div>
	</div>
	<?php
}

/**
 * Set nullable columns to NULL for a given row.
 *
 * wpdb->update() cannot write NULL with a %d format specifier — it stores 0
 * instead.  This helper issues a raw UPDATE so the columns are genuinely NULL.
 *
 * @param string $table  Fully-qualified table name.
 * @param int    $id     Row ID.
 * @param array  $fields Column names to set to NULL.
 * @return bool True on success, false on DB error.
 */
function pltt_set_nullable_fields( $table, $id, $fields ) {
	if ( empty( $fields ) ) {
		return true;
	}

	// SEC-M1: Defense-in-depth — column names are interpolated into raw SQL,
	// so reject anything not on the known-nullable list before building the
	// statement. esc_sql() only escapes string literals, not identifiers.
	$allowed = array(
		'client_id',
		'project_id',
		'hourly_rate',
		'recurring_period',
		'budget_hours',
		'budget_fee',
		'last_used',
		'group_name',
	);
	$fields = array_values( array_intersect( $fields, $allowed ) );
	if ( empty( $fields ) ) {
		return true;
	}

	global $wpdb;

	$set_clauses = array();
	foreach ( $fields as $field ) {
		$set_clauses[] = '`' . esc_sql( $field ) . '` = NULL';
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	return false !== $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'UPDATE ' . $table . ' SET ' . implode( ', ', $set_clauses ) . ' WHERE id = %d',
			$id
		)
	);
}

/**
 * Validate an hourly rate value.
 *
 * @param float $rate Rate to validate.
 * @return true|WP_Error True if valid, WP_Error if out of range.
 */
function pltt_validate_hourly_rate( $rate ) {
	if ( $rate < 0 || $rate > 10000 ) {
		return new WP_Error( 'invalid_rate', __( 'Hourly rate must be between $0 and $10,000.', 'plain-language-time-tracker' ) );
	}
	return true;
}

/**
 * Resolve the billable hourly rate for an entry.
 *
 * Resolution order: project rate → client rate → PLTT_DEFAULT_HOURLY_RATE → $0.
 *
 * Accepts optional pre-loaded caches (id-keyed object maps) to avoid extra DB queries.
 * On a cache MISS (or when no cache is passed) the rate is loaded from the DB via
 * PLTT_Projects::get() / PLTT_Clients::get() — supplying a cache is an optimization,
 * not a guarantee that the DB is never hit (TRC-BIZ-DOC1).
 *
 * OPT-M3: Consolidates duplicate rate logic from PLTT_Ajax::update_entry_field()
 * and PLTT_Review::resolve_billable_rate() into a single canonical implementation.
 * SYNC: The JS-side amount calculation in assets/js/reports.js must match this logic.
 *
 * @param int   $client_id      Client ID (0 if none).
 * @param int   $project_id     Project ID (0 if none).
 * @param array $clients_cache  Optional pre-loaded clients map (id => object).
 * @param array $projects_cache Optional pre-loaded projects map (id => object).
 * @return float Resolved hourly rate.
 */
function pltt_resolve_billable_rate( $client_id, $project_id, $clients_cache = array(), $projects_cache = array() ) {
	$client_id  = (int) $client_id;
	$project_id = (int) $project_id;

	// 1. Check project rate.
	if ( $project_id > 0 ) {
		$project = isset( $projects_cache[ $project_id ] ) ? $projects_cache[ $project_id ] : PLTT_Projects::get( $project_id );
		if ( $project && (float) $project->hourly_rate > 0 ) {
			return (float) $project->hourly_rate;
		}
	}

	// 2. Check client rate.
	if ( $client_id > 0 ) {
		$client = isset( $clients_cache[ $client_id ] ) ? $clients_cache[ $client_id ] : PLTT_Clients::get( $client_id );
		if ( $client && (float) $client->hourly_rate > 0 ) {
			return (float) $client->hourly_rate;
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
 * Compute the billable dollar amount for a block of time.
 *
 * This is the plugin's canonical "minutes × rate → dollars" rule (OPT-DUP-A).
 * Route every billable-amount snapshot through it so the rounding mode can't
 * drift between call sites.
 *
 * SYNC: the SQL fallback in PLTT_Entries::billable_amount_expr() and the JS
 * amount math in assets/js/reports.js must match this (round half-up, 2 dp).
 *
 * @param int   $minutes Duration in minutes.
 * @param float $rate    Hourly rate.
 * @return float Amount rounded to cents.
 */
function pltt_billable_amount( $minutes, $rate ) {
	return round( ( (int) $minutes / 60.0 ) * (float) $rate, 2 );
}

/**
 * Resolve a project's budgeted time in minutes.
 *
 * Canonical budget cascade (OPT-DUP-C): explicit budget_hours × 60, else
 * budget_fee ÷ hourly rate × 60 when a positive rate is known, else 0. Keeps the
 * hours-before-fee precedence in one place so the three report surfaces can't
 * diverge.
 *
 * @param object     $project Project row (budget_hours, budget_fee, client_id, id).
 * @param float|null $rate    Resolved hourly rate. When null and the fee path is
 *                            needed, it is resolved via pltt_resolve_billable_rate().
 * @return int Budgeted minutes (0 when no allocation can be determined).
 */
function pltt_budgeted_minutes( $project, $rate = null ) {
	$budget_hours = isset( $project->budget_hours ) ? (float) $project->budget_hours : 0.0;
	if ( $budget_hours > 0 ) {
		return (int) round( $budget_hours * 60 );
	}

	$budget_fee = isset( $project->budget_fee ) ? (float) $project->budget_fee : 0.0;
	if ( $budget_fee > 0 ) {
		if ( null === $rate ) {
			$rate = pltt_resolve_billable_rate( (int) $project->client_id, (int) $project->id );
		}
		if ( (float) $rate > 0 ) {
			return (int) round( ( $budget_fee / (float) $rate ) * 60 );
		}
	}

	return 0;
}

/**
 * Effective hourly rate: dollars ÷ hours, or 0 when either side is non-positive.
 *
 * One rule for the "fee ÷ hours" (fixed budget) and "billable $ ÷ hours" (hourly)
 * display cards (OPT-DUP-E), so the >0 guards can't drift between surfaces.
 *
 * @param float $amount  Dollar amount.
 * @param int   $minutes Minutes worked.
 * @return float Rate per hour (0.0 when amount or minutes is non-positive).
 */
function pltt_effective_rate( $amount, $minutes ) {
	$amount  = (float) $amount;
	$minutes = (int) $minutes;
	return ( $minutes > 0 && $amount > 0 ) ? ( $amount / ( $minutes / 60 ) ) : 0.0;
}

/**
 * The billable dollar amount for a single entry.
 *
 * Uses the stored snapshot (billable_amount) when present, else a live fallback
 * of duration × resolved rate (OPT-DUP-G). Mirrors the SQL fallback in
 * PLTT_Entries::billable_amount_expr().
 *
 * @param object $entry Entry row (billable_amount, duration_minutes, client_id, project_id).
 * @return float
 */
function pltt_resolve_entry_amount( $entry ) {
	if ( isset( $entry->billable_amount ) && null !== $entry->billable_amount && '' !== $entry->billable_amount ) {
		return (float) $entry->billable_amount;
	}
	$rate = pltt_resolve_billable_rate( (int) $entry->client_id, (int) $entry->project_id );
	return pltt_billable_amount( (int) $entry->duration_minutes, $rate );
}

/**
 * Period-over-period change indicator for a stat card (OPT-DUP-F).
 *
 * Returns the percent change plus the matching CSS status class and arrow glyph.
 * A zero prior value is treated as +100%. The ±5% neutral band and the glyphs
 * live here so the Billable Hours and Billable Amount cards can't drift.
 *
 * SYNC: assets/js/reports.js updateBillableCards() mirrors this for the live
 * inline-billable toggle — keep the threshold and glyphs in step.
 *
 * @param float $curr Current-period value.
 * @param float $prev Prior-period value.
 * @return array{pct:float,class:string,icon:string}
 */
function pltt_pct_change_indicator( $curr, $prev ) {
	$curr = (float) $curr;
	$prev = (float) $prev;
	$pct  = $prev > 0 ? ( ( $curr - $prev ) / $prev * 100 ) : 100;

	if ( abs( $pct ) < 5 ) {
		return array( 'pct' => $pct, 'class' => 'status-neutral', 'icon' => '→' );
	}
	if ( $pct > 0 ) {
		return array( 'pct' => $pct, 'class' => 'status-increase', 'icon' => '↑' );
	}
	return array( 'pct' => $pct, 'class' => 'status-decrease', 'icon' => '↓' );
}

/**
 * Render the overage threshold marker row inside an entry table.
 *
 * Spans the full table width. Styled as chrome (dashed amber borders, warm
 * gradient bg), not a data row.
 *
 * @param int    $colspan   Number of columns in the parent table.
 * @param string $primary   Primary label (e.g. "Allocation reached · 10h used").
 * @param string $secondary Secondary label (e.g. "Entries below are overage candidates").
 */
function pltt_render_threshold_marker_row( $colspan, $primary, $secondary = '' ) {
	?>
	<tr class="pltt-threshold-marker-row">
		<td colspan="<?php echo esc_attr( (int) $colspan ); ?>">
			<div class="pltt-threshold-marker">
				<span class="pltt-threshold-icon" aria-hidden="true">⚑</span>
				<div class="pltt-threshold-labels">
					<span class="pltt-threshold-primary"><?php echo esc_html( $primary ); ?></span>
					<?php if ( '' !== $secondary ) : ?>
						<span class="pltt-threshold-secondary"><?php echo esc_html( $secondary ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</td>
	</tr>
	<?php
}

/**
 * Render an entry table.
 *
 * Outputs a complete <table> with entry rows showing description,
 * client, project, tags, time, duration, and billable indicator.
 * When inline_edit is true, Tags, Billable, and Invoiced are interactive.
 *
 * @param array $entries Array of entry objects.
 * @param array $options {
 *     Optional. Display options.
 *
 *     @type bool   $show_amount                Whether to show the Amount column. Default false.
 *     @type string $table_class                Additional CSS class for the table element.
 *     @type bool   $inline_edit                Whether to render interactive inline edit controls. Default false.
 *     @type array  $all_tags                   All available tag name strings. Required when inline_edit is true.
 *     @type array  $overage_entry_ids          Entry IDs to flag with the .pltt-row-overage class.
 *     @type int    $threshold_marker_before    Insert a threshold marker row immediately before the row matching this entry ID.
 *     @type string $threshold_marker_primary   Marker primary label text.
 *     @type string $threshold_marker_secondary Marker secondary label text.
 * }
 */
function pltt_render_entry_table( $entries, $options = array() ) {
	$show_amount = ! empty( $options['show_amount'] );
	$table_class = ! empty( $options['table_class'] ) ? ' ' . $options['table_class'] : '';
	$inline_edit = ! empty( $options['inline_edit'] );
	$all_tags    = $inline_edit && ! empty( $options['all_tags'] ) ? $options['all_tags'] : array();

	// Overage decision-support options.
	$overage_lookup          = ! empty( $options['overage_entry_ids'] )
		? array_flip( array_map( 'intval', (array) $options['overage_entry_ids'] ) )
		: array();
	$threshold_marker_before = isset( $options['threshold_marker_before'] ) ? (int) $options['threshold_marker_before'] : 0;
	$marker_primary          = isset( $options['threshold_marker_primary'] ) ? (string) $options['threshold_marker_primary'] : '';
	$marker_secondary        = isset( $options['threshold_marker_secondary'] ) ? (string) $options['threshold_marker_secondary'] : '';

	if ( empty( $entries ) ) {
		return;
	}

	// Colspan for the threshold marker row: Desc, Tags, Time, Duration, Billable
	// (+1 if inline_edit adds the Inv. column, +1 if show_amount adds Amount).
	$colspan = 5;
	if ( $inline_edit ) {
		$colspan++;
	}
	if ( $show_amount ) {
		$colspan++;
	}

	// Collect unique project and client IDs to avoid N+1 queries.
	$project_ids = array();
	$client_ids  = array();
	foreach ( $entries as $entry ) {
		if ( ! empty( $entry->project_id ) ) {
			$project_ids[] = (int) $entry->project_id;
		}
		if ( ! empty( $entry->client_id ) ) {
			$client_ids[] = (int) $entry->client_id;
		}
	}

	// Fetch all referenced projects and clients in bulk (single query each).
	$projects_cache = PLTT_Projects::get_multiple( array_unique( $project_ids ) );
	$clients_cache  = PLTT_Clients::get_multiple( array_unique( $client_ids ) );

	// Bulk-load tags from the junction table to avoid N+1 queries.
	$entry_ids     = array_map( fn( $e ) => (int) $e->id, $entries );
	$tags_by_entry = PLTT_Tags::get_for_entries( $entry_ids );
	?>
	<table class="widefat<?php echo esc_attr( $table_class ); ?>">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Description', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Time', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></th>
				<?php if ( $inline_edit ) : ?>
					<th class="pltt-invoiced-col"><?php esc_html_e( 'Inv.', 'plain-language-time-tracker' ); ?></th>
				<?php endif; ?>
				<?php if ( $show_amount ) : ?>
					<th class="pltt-amount-col"><?php esc_html_e( 'Amount', 'plain-language-time-tracker' ); ?></th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $entries as $entry ) :
				// Use pre-fetched data to avoid N+1 queries.
				$client       = ! empty( $entry->client_id ) && isset( $clients_cache[ $entry->client_id ] ) ? $clients_cache[ $entry->client_id ] : null;
				$project      = ! empty( $entry->project_id ) && isset( $projects_cache[ $entry->project_id ] ) ? $projects_cache[ $entry->project_id ] : null;
				$entry_tags   = $tags_by_entry[ (int) $entry->id ] ?? array();
				$is_billed    = ! empty( $entry->billed );
				$is_billable  = ! empty( $entry->billable );
				$entry_id_int = (int) $entry->id;

				// Insert the threshold marker immediately before the boundary entry.
				if ( $threshold_marker_before && $entry_id_int === $threshold_marker_before ) {
					pltt_render_threshold_marker_row( $colspan, $marker_primary, $marker_secondary );
				}

				$tr_classes = array();
				if ( $is_billed ) {
					$tr_classes[] = 'pltt-billed';
				}
				if ( isset( $overage_lookup[ $entry_id_int ] ) ) {
					$tr_classes[] = 'pltt-row-overage';
				}
				$class_attr = $tr_classes ? ' class="' . esc_attr( implode( ' ', $tr_classes ) ) . '"' : '';
				?>
				<tr<?php echo $class_attr; ?><?php echo $inline_edit ? ' data-entry-id="' . esc_attr( $entry->id ) . '"' : ''; ?>>
					<td class="pltt-entry-desc-cell">
						<?php if ( $is_billed && ! $inline_edit ) : ?>
							<span class="screen-reader-text"><?php esc_html_e( 'Invoiced:', 'plain-language-time-tracker' ); ?></span>
						<?php endif; ?>
						<span class="pltt-entry-desc-text"><?php echo esc_html( $entry->description ); ?></span>
						<?php
						$meta_parts = array();
						if ( $client )  {
							$meta_parts[] = '<span class="pltt-entry-client">' . esc_html( $client->name ) . '</span>';
						}
						if ( $project ) {
							$meta_parts[] = '<span class="pltt-entry-project">' . esc_html( $project->name ) . '</span>';
						}
						$meta_html = implode( '<span class="pltt-entry-meta-sep"> · </span>', $meta_parts );
						if ( $meta_html ) :
						?>
							<div class="pltt-entry-meta">
								<?php echo $meta_html; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped above ?>
							</div>
						<?php endif; ?>
					</td>
					<td class="pltt-tag-cell">
						<?php if ( $inline_edit ) : ?>
							<div class="pltt-tag-input-wrap" data-entry-id="<?php echo esc_attr( $entry->id ); ?>">
								<input type="hidden" class="pltt-tags" value="<?php echo esc_attr( implode( ',', $entry_tags ) ); ?>">
							</div>
						<?php else : ?>
							<div class="pltt-tag-pills"><?php pltt_render_tag_badges( $entry_tags ); ?></div>
						<?php endif; ?>
					</td>
					<td class="pltt-time-cell">
						<?php
						echo esc_html( pltt_format_time( $entry->start_time ) );
						if ( $entry->end_time ) {
							echo ' - ' . esc_html( pltt_format_time( $entry->end_time ) );
						}
						?>
					</td>
					<td class="pltt-duration-cell">
						<?php echo esc_html( pltt_format_duration( $entry->duration_minutes ) ); ?>
					</td>
					<td class="pltt-billable-indicator">
						<?php if ( $inline_edit ) : ?>
							<button type="button"
								class="pltt-billable-symbol pltt-inline-toggle <?php echo $is_billable ? 'is-billable' : 'not-billable'; ?>"
								data-entry-id="<?php echo esc_attr( $entry->id ); ?>"
								data-field="billable"
								data-value="<?php echo $is_billable ? '1' : '0'; ?>"
								data-minutes="<?php echo esc_attr( (int) $entry->duration_minutes ); ?>"
								aria-label="<?php echo $is_billable ? esc_attr__( 'Billable — click to toggle', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable — click to toggle', 'plain-language-time-tracker' ); ?>"
								title="<?php echo $is_billable ? esc_attr__( 'Billable — click to toggle', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable — click to toggle', 'plain-language-time-tracker' ); ?>">$</button>
						<?php else : ?>
							<span class="pltt-billable-symbol <?php echo $is_billable ? 'is-billable' : 'not-billable'; ?>" aria-label="<?php echo $is_billable ? esc_attr__( 'Billable', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable', 'plain-language-time-tracker' ); ?>" title="<?php echo $is_billable ? esc_attr__( 'Billable', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable', 'plain-language-time-tracker' ); ?>">$</span>
						<?php endif; ?>
					</td>
					<?php if ( $inline_edit ) : ?>
						<td class="pltt-invoiced-col">
							<button type="button"
								class="pltt-invoiced-toggle <?php echo $is_billed ? 'is-invoiced' : 'not-invoiced'; ?>"
								data-entry-id="<?php echo esc_attr( $entry->id ); ?>"
								data-field="billed"
								data-value="<?php echo $is_billed ? '1' : '0'; ?>"
								aria-label="<?php echo $is_billed ? esc_attr__( 'Invoiced — click to toggle', 'plain-language-time-tracker' ) : esc_attr__( 'Not invoiced — click to toggle', 'plain-language-time-tracker' ); ?>"
								title="<?php echo $is_billed ? esc_attr__( 'Invoiced — click to toggle', 'plain-language-time-tracker' ) : esc_attr__( 'Not invoiced — click to toggle', 'plain-language-time-tracker' ); ?>"
								<?php echo ! $is_billable ? 'style="visibility:hidden"' : ''; ?>>
								<?php echo $is_billed ? '✓' : '○'; ?>
							</button>
						</td>
					<?php endif; ?>
					<?php if ( $show_amount ) :
						$billable_amount = 0.0;
						if ( $is_billable && $entry->duration_minutes > 0 ) {
							if ( null !== $entry->billable_amount ) {
								// Prefer the amount frozen at verification time.
								$billable_amount = (float) $entry->billable_amount;
							} else {
								// Fallback: resolve rate on-the-fly using the canonical helper.
								$hourly_rate     = pltt_resolve_billable_rate( (int) $entry->client_id, (int) $entry->project_id, $clients_cache, $projects_cache );
								$billable_amount = pltt_billable_amount( $entry->duration_minutes, $hourly_rate );
							}
						}
						?>
						<td class="pltt-duration-cell pltt-amount-col"><?php echo $billable_amount > 0 ? esc_html( pltt_format_currency( $billable_amount ) ) : '<span class="pltt-empty">—</span>'; ?></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Derive the billing type for a project object or summary row.
 *
 * Returns one of: 'recurring' | 'fixed' | 'none' | 'hourly'.
 *
 * NOTE: budget_hours MUST be checked before billability_default because Fixed
 * Budget projects have billability_default = 0, which would otherwise classify
 * them as Internal ('none').
 *
 * @param object $project Any object with recurring_period, budget_hours, billability_default properties.
 * @return string
 */
function pltt_get_billing_type( $project ) {
	if ( ! empty( $project->recurring_period ) ) {
		return 'recurring';
	} elseif ( ! empty( $project->budget_hours ) || ! empty( $project->budget_fee ) ) {
		return 'fixed';
	} elseif ( empty( $project->billability_default ) ) {
		return 'none';
	}
	return 'hourly';
}

/**
 * Echo a labeled badge for a billing type. OPT-DUP6.
 *
 * @param string $billing_type One of: recurring, fixed, hourly, none.
 */
function pltt_render_billing_type_badge( $billing_type ) {
	$styles = array(
		'none'      => array( '', __( 'Internal', 'plain-language-time-tracker' ) ),
		'recurring' => array( 'pltt-badge-info', __( 'Monthly', 'plain-language-time-tracker' ) ),
		'fixed'     => array( 'pltt-badge-purple', __( 'Fixed Budget', 'plain-language-time-tracker' ) ),
		'hourly'    => array( 'pltt-badge-success', __( 'Hourly', 'plain-language-time-tracker' ) ),
	);
	list( $class, $label ) = $styles[ $billing_type ] ?? $styles['hourly'];
	$class                 = trim( 'pltt-badge ' . $class );
	echo '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
}

/**
 * Build the one-line project info string for the project context card.
 *
 * Format by billing type:
 *   Hourly:    "Hourly · $150/hr"
 *   Retainer:  "Retainer · $300/mo · 3 hrs · $150/hr over"
 *   Fixed Fee: "Fixed Fee · $5,800 · 38h 53m budget"
 *   Internal:  "Internal"
 *
 * @param object $project Project row (client_id, id, recurring_period, budget_hours, budget_fee).
 * @return string Middot-joined info line (unescaped; caller escapes).
 */
function pltt_format_project_info_line( $project ) {
	$type  = pltt_get_billing_type( $project );
	$parts = array();

	switch ( $type ) {
		case 'recurring':
			$parts[] = __( 'Retainer', 'plain-language-time-tracker' );

			if ( ! empty( $project->budget_fee ) ) {
				$period_abbr = array(
					'weekly'    => __( 'wk', 'plain-language-time-tracker' ),
					'monthly'   => __( 'mo', 'plain-language-time-tracker' ),
					'quarterly' => __( 'qtr', 'plain-language-time-tracker' ),
					'yearly'    => __( 'yr', 'plain-language-time-tracker' ),
				);
				$abbr    = $period_abbr[ $project->recurring_period ] ?? '';
				$parts[] = pltt_format_currency_compact( $project->budget_fee ) . ( $abbr ? '/' . $abbr : '' );
			}

			if ( ! empty( $project->budget_hours ) ) {
				$parts[] = sprintf(
					/* translators: %s: number of budgeted hours, e.g. "3" or "3.5". */
					__( '%s hrs', 'plain-language-time-tracker' ),
					rtrim( rtrim( number_format( (float) $project->budget_hours, 2 ), '0' ), '.' )
				);
			}

			$rate = pltt_resolve_billable_rate( (int) $project->client_id, (int) $project->id );
			if ( $rate > 0 ) {
				$parts[] = sprintf(
					/* translators: %s: hourly overage rate, e.g. "$150". */
					__( '%s/hr', 'plain-language-time-tracker' ),
					pltt_format_currency_compact( $rate )
				);
			}
			break;

		case 'fixed':
			$parts[] = __( 'Fixed Fee', 'plain-language-time-tracker' );

			if ( ! empty( $project->budget_fee ) ) {
				$parts[] = pltt_format_currency_compact( $project->budget_fee );
			}

			if ( ! empty( $project->budget_hours ) ) {
				$parts[] = sprintf(
					/* translators: %s: budgeted time, e.g. "38h 53m". */
					__( '%s budget', 'plain-language-time-tracker' ),
					pltt_format_duration( (float) $project->budget_hours * 60 )
				);
			}
			break;

		case 'none':
			$parts[] = __( 'Internal', 'plain-language-time-tracker' );
			break;

		case 'hourly':
		default:
			$parts[] = __( 'Hourly', 'plain-language-time-tracker' );

			$rate = pltt_resolve_billable_rate( (int) $project->client_id, (int) $project->id );
			if ( $rate > 0 ) {
				$parts[] = sprintf(
					/* translators: %s: hourly rate, e.g. "$150". */
					__( '%s/hr', 'plain-language-time-tracker' ),
					pltt_format_currency_compact( $rate )
				);
			}
			break;
	}

	return implode( ' · ', $parts );
}


/**
 * Resolve the start/end YYYY-MM-DD bounds for a project's current allocation period.
 *
 * Recurring projects reset each period (weekly aligned to start_of_week, monthly,
 * quarterly, yearly). Fixed-fee and non-recurring projects accumulate against the
 * budget all-time, signaled by [null, null].
 *
 * @param object $project        Project row with recurring_period set (or empty).
 * @param string $reference_date YYYY-MM-DD within the period of interest.
 * @return array{0:?string,1:?string}
 */
function pltt_get_allocation_period_bounds( $project, $reference_date ) {
	if ( empty( $project->recurring_period ) ) {
		return array( null, null );
	}

	$tz = wp_timezone();
	try {
		$dt = new DateTimeImmutable( $reference_date, $tz );
	} catch ( Exception $e ) {
		return array( null, null );
	}

	switch ( $project->recurring_period ) {
		case 'weekly':
			$week_start_dow = (int) get_option( 'start_of_week', 0 );
			$dow            = (int) $dt->format( 'w' );
			$shift          = ( $dow - $week_start_dow + 7 ) % 7;
			$start          = $dt->modify( "-{$shift} days" );
			$end            = $start->modify( '+6 days' );
			break;

		case 'monthly':
			$start = $dt->modify( 'first day of this month' );
			$end   = $dt->modify( 'last day of this month' );
			break;

		case 'quarterly':
			$month         = (int) $dt->format( 'n' );
			$q_start_month = ( (int) floor( ( $month - 1 ) / 3 ) * 3 ) + 1;
			$start         = $dt->setDate( (int) $dt->format( 'Y' ), $q_start_month, 1 );
			$end           = $start->modify( '+2 months' )->modify( 'last day of this month' );
			break;

		case 'yearly':
			$year  = (int) $dt->format( 'Y' );
			$start = $dt->setDate( $year, 1, 1 );
			$end   = $dt->setDate( $year, 12, 31 );
			break;

		default:
			return array( null, null );
	}

	return array( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
}

/**
 * Compute the cumulative allocation boundary for a single allocation-bearing project.
 *
 * Cumulative is measured across the project's natural allocation period (current
 * month for monthly retainers, etc.) — not the user's filter range. This means a
 * user viewing a single week of a monthly retainer still sees the same boundary
 * as if they were viewing the whole month.
 *
 * The user's tag/billable/billed filters are intentionally ignored here: "what
 * entries crossed the boundary" depends on every entry in the period, not on the
 * subset the user is currently viewing.
 *
 * @param object $project     Project with budget_hours / budget_fee / recurring_period.
 * @param array  $filter_args Active filter args (uses date_from as the period anchor).
 * @return array{
 *     state: 'over'|'within'|'unavailable',
 *     allocation_minutes: int,
 *     used_minutes: int,
 *     remaining_minutes: int,
 *     overage_minutes: int,
 *     overage_amount: float,
 *     marked_billable_minutes: int,
 *     marked_billable_amount: float,
 *     marker_entry_id: ?int,
 *     overage_entry_ids: int[],
 *     boundary_time: ?string,
 *     period_start: ?string,
 *     period_end: ?string,
 *     reason: ?string,
 * }
 */
function pltt_compute_overage_threshold( $project, $filter_args ) {
	$out = array(
		'state'              => 'unavailable',
		'allocation_minutes' => 0,
		'used_minutes'       => 0,
		'remaining_minutes'  => 0,
		'overage_minutes'    => 0,
		'overage_amount'     => 0.0,
		'marked_billable_minutes' => 0,
		'marked_billable_amount'  => 0.0,
		'marker_entry_id'    => null,
		'overage_entry_ids'  => array(),
		'boundary_time'      => null,
		'period_start'       => null,
		'period_end'         => null,
		'reason'             => null,
	);

	if ( empty( $project ) ) {
		$out['reason'] = 'no_project';
		return $out;
	}

	// Resolve allocation in minutes (canonical hours-before-fee cascade).
	$alloc_minutes = pltt_budgeted_minutes( $project );

	if ( $alloc_minutes <= 0 ) {
		$out['reason'] = 'no_allocation';
		return $out;
	}
	$out['allocation_minutes'] = $alloc_minutes;

	// Resolve the allocation period bounds.
	$reference_date = ! empty( $filter_args['date_from'] ) ? $filter_args['date_from'] : current_time( 'Y-m-d' );
	list( $period_start, $period_end ) = pltt_get_allocation_period_bounds( $project, $reference_date );
	$out['period_start'] = $period_start;
	$out['period_end']   = $period_end;

	// For recurring projects, the user's range must stay inside the period.
	// Fixed-fee skips this — its allocation is all-time.
	if ( ! empty( $project->recurring_period ) && $period_start && $period_end ) {
		$range_from = $filter_args['date_from'] ?? null;
		$range_to   = $filter_args['date_to'] ?? null;
		if ( ( $range_from && $range_from < $period_start ) || ( $range_to && $range_to > $period_end ) ) {
			$out['reason'] = 'range_spans_periods';
			return $out;
		}
	}

	// Fetch all entries in the period for this project, oldest-first.
	$period_args = array(
		'project_id' => (int) $project->id,
		'orderby'    => 'entry_date',
		'order'      => 'ASC',
	);
	if ( $period_start ) {
		$period_args['date_from'] = $period_start;
	}
	if ( $period_end ) {
		$period_args['date_to'] = $period_end;
	}

	$period_entries = PLTT_Entries::get_all( $period_args );

	$cumulative     = 0;
	$marker_id      = null;
	$overage_ids    = array();
	$overage_amount = 0.0;

	// Running totals of what the user has actually flipped to billable across the
	// whole period — independent of the calculated allocation boundary. The card
	// compares these against the calculated overage to surface over/under-billing.
	$marked_minutes = 0;
	$marked_amount  = 0.0;

	foreach ( $period_entries as $e ) {
		$dur  = (int) $e->duration_minutes;
		$next = $cumulative + $dur;

		if ( ! empty( $e->billable ) && $dur > 0 ) {
			$marked_minutes += $dur;
			$marked_amount  += pltt_resolve_entry_amount( $e );
		}

		$is_overage = false;
		if ( $cumulative >= $alloc_minutes ) {
			$is_overage = true;
		} elseif ( $next > $alloc_minutes ) {
			$is_overage = true;
		}

		if ( $is_overage ) {
			if ( null === $marker_id ) {
				$marker_id = (int) $e->id;

				// Clock time the allocation was actually consumed: this entry's
				// start plus the minutes into it that filled the remaining budget.
				// ($cumulative is still the running total BEFORE this entry here.)
				if ( ! empty( $e->start_time ) ) {
					$start_mins = pltt_time_to_minutes( $e->start_time );
					if ( false !== $start_mins ) {
						$into          = max( 0, $alloc_minutes - $cumulative );
						$boundary_mins = min( $start_mins + $into, ( 23 * 60 ) + 59 );
						$out['boundary_time'] = pltt_format_time(
							sprintf( '%02d:%02d:00', intdiv( $boundary_mins, 60 ), $boundary_mins % 60 )
						);
					}
				}
			}
			$overage_ids[] = (int) $e->id;

			// Accumulate only dollars from entries the user has flipped to billable.
			//
			// NOTE: this counts the FULL amount of each billable entry past the
			// crossing point, including the within-allocation portion of the entry
			// that straddles the line. That overstates true overage dollars when an
			// entry spans the boundary (see the "billable flag vs. allocation line"
			// open question documented in project memory). Kept as-is intentionally
			// so the billable flag stays the single source of truth for now.
			if ( ! empty( $e->billable ) && $dur > 0 ) {
				$overage_amount += pltt_resolve_entry_amount( $e );
			}
		}

		$cumulative = $next;
	}

	$out['used_minutes']            = $cumulative;
	$out['marked_billable_minutes'] = $marked_minutes;
	$out['marked_billable_amount']  = round( $marked_amount, 2 );

	if ( ! empty( $overage_ids ) ) {
		$out['state']             = 'over';
		$out['overage_minutes']   = max( 0, $cumulative - $alloc_minutes );
		$out['overage_amount']    = round( $overage_amount, 2 );
		$out['marker_entry_id']   = $marker_id;
		$out['overage_entry_ids'] = $overage_ids;
	} else {
		$out['state']             = 'within';
		$out['remaining_minutes'] = max( 0, $alloc_minutes - $cumulative );
	}

	return $out;
}

/**
 * Render the allocation bar HTML for a project.
 *
 * Outputs a progress bar showing how much of the budget/allocation has been used.
 * Labels use plain-language duration ("67h 48m left · 11%"); a title tooltip shows
 * decimal hours. For fee-based fixed budgets, pass $fee_args with 'spent_dollars' and
 * 'budget_dollars' keys — the bar still displays hours (caller pre-computes budget_hours
 * from budget_fee ÷ rate), and the tooltip adds the dollar breakdown.
 *
 * @param float       $alloc_mins     Minutes logged in the relevant allocation period.
 * @param float       $budget_hours   Allocation budget in hours.
 * @param string      $billing_type   'recurring' or 'fixed'.
 * @param array|null  $fee_args       Optional. Keys: 'spent_dollars' (float), 'budget_dollars' (float).
 * @param string|null $label_override Optional. Replaces the computed bar label (e.g. the
 *                                    project context card's "5h 15m used · 2h 15m over" caption).
 */
function pltt_render_allocation_bar( $alloc_mins, $budget_hours, $billing_type, $fee_args = null, $label_override = null ) {
	$alloc_hours = $alloc_mins / 60;
	$pct         = $budget_hours > 0 ? ( $alloc_hours / $budget_hours ) * 100 : 0;
	$is_over     = $pct >= 100;
	$pct_display = (int) round( $pct );

	if ( $is_over ) {
		// When over: bar fills 100% of the wrap, split between within-budget (type color)
		// and overage (amber). Both segments are proportional to the total used time.
		$within_seg_pct = $alloc_hours > 0 ? ( $budget_hours / $alloc_hours ) * 100 : 0;
		$over_seg_pct   = 100 - $within_seg_pct;
		$delta_fmt      = pltt_format_duration( ( $alloc_hours - $budget_hours ) * 60 );
		$label          = $delta_fmt . ' ' . __( 'over', 'plain-language-time-tracker' ) . ' · ' . $pct_display . '%';
	} else {
		// Within budget: single type-colored segment, rest of wrap shows background.
		$within_seg_pct = $pct;
		$over_seg_pct   = 0;
		$delta_fmt      = pltt_format_duration( ( $budget_hours - $alloc_hours ) * 60 );
		$label          = $delta_fmt . ' ' . __( 'left', 'plain-language-time-tracker' ) . ' · ' . $pct_display . '%';
	}

	if ( null !== $label_override ) {
		$label = $label_override;
	}

	$hrs_unit  = __( 'hrs', 'plain-language-time-tracker' );
	$used_hrs  = pltt_format_hours( $alloc_mins ) . ' ' . $hrs_unit;
	if ( null !== $fee_args ) {
		$alloc_rows = array(
			array( __( 'Used', 'plain-language-time-tracker' ), $used_hrs ),
			array( __( 'Spent', 'plain-language-time-tracker' ), pltt_format_currency( $fee_args['spent_dollars'] ) ),
			array( __( 'Budget', 'plain-language-time-tracker' ), pltt_format_currency( $fee_args['budget_dollars'] ) ),
		);
	} else {
		$alloc_rows = array(
			array( __( 'Used', 'plain-language-time-tracker' ), $used_hrs ),
			array( __( 'Budget', 'plain-language-time-tracker' ), pltt_format_hours( $budget_hours * 60 ) . ' ' . $hrs_unit ),
		);
	}

	?>
	<div
		class="pltt-alloc-cell"
		data-pltt-tip
		data-tip-title="<?php esc_attr_e( 'Allocation', 'plain-language-time-tracker' ); ?>"
		data-tip-color="none"
		data-tip-rows='<?php echo esc_attr( wp_json_encode( $alloc_rows ) ); ?>'
	>
		<div class="pltt-alloc-bar-wrap<?php echo $is_over ? ' pltt-alloc-over' : ''; ?>">
			<span class="pltt-alloc-seg pltt-alloc-seg-within"
				  style="width:<?php echo esc_attr( $within_seg_pct ); ?>%"></span>
			<?php if ( $is_over ) : ?>
				<span class="pltt-alloc-seg pltt-alloc-seg-over"
					  style="width:<?php echo esc_attr( $over_seg_pct ); ?>%"></span>
			<?php endif; ?>
		</div>
		<span class="pltt-alloc-label<?php echo $is_over ? ' pltt-alloc-over' : ''; ?>">
			<?php echo esc_html( $label ); ?>
		</span>
	</div>
	<?php
}

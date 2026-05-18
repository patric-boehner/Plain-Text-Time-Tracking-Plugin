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
 * Convert minutes since midnight to time string.
 *
 * @param int    $minutes Minutes since midnight.
 * @param string $format  Time format (12 or 24).
 * @return string Formatted time.
 */
function pltt_minutes_to_time( $minutes, $format = '12' ) {
	$hours = floor( $minutes / 60 );
	$mins  = $minutes % 60;

	if ( '24' === $format ) {
		return sprintf( '%02d:%02d', $hours, $mins );
	}

	$period = $hours >= 12 ? 'pm' : 'am';
	$hours  = $hours % 12;
	if ( 0 === $hours ) {
		$hours = 12;
	}

	return sprintf( '%d:%02d%s', $hours, $mins, $period );
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
 * Get current time in H:i:s format.
 *
 * @return string Current time.
 */
function pltt_get_current_time() {
	return current_time( 'H:i:s' );
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
 * Sanitize and validate a date, returning current date if invalid.
 *
 * @param string $date Date string.
 * @return string Valid date in Y-m-d format.
 */
function pltt_sanitize_date( $date ) {
	$date = sanitize_text_field( $date );
	return pltt_validate_date( $date ) ? $date : pltt_get_current_date();
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
 * Check whether any time token in raw_text omits am/pm.
 *
 * Mirrors the time regex in PLTT_Time_Parser::parse_line(). Returns true
 * if at least one numeric time-like token in the line has no am/pm suffix
 * — the condition under which PHP silently defaults the time to AM.
 *
 * @param string $raw_text Original log line.
 * @return bool True if at least one time token lacks am/pm.
 */
function pltt_raw_text_has_ambiguous_time( $raw_text ) {
	if ( empty( $raw_text ) ) {
		return false;
	}
	if ( ! preg_match_all( '/\b\d{1,2}(?::\d{2})?\s*(am|pm)?\b/i', $raw_text, $matches ) ) {
		return false;
	}
	foreach ( $matches[1] as $ampm ) {
		if ( '' === $ampm ) {
			return true;
		}
	}
	return false;
}

/**
 * Compute per-entry warning flags for the Review screen.
 *
 * Expects entries sorted by start_time ASC (the order PLTT_Review uses).
 * Returns a map of entry_id => warning reasons. Three independent checks:
 *
 *   - 'long_duration': duration > 6h. Catches the common case where an
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

		if ( $duration > 360 ) {
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
 * Count working days (weekdays only) between two dates, inclusive.
 *
 * @param string $date_from Start date (Y-m-d format).
 * @param string $date_to   End date (Y-m-d format).
 * @return int Number of weekdays (Monday-Friday) in the range.
 */
function pltt_count_working_days( $date_from, $date_to ) {
	$start = new DateTimeImmutable( $date_from, wp_timezone() );
	$end   = new DateTimeImmutable( $date_to, wp_timezone() );
	$end   = $end->modify( '+1 day' );

	$interval = new DateInterval( 'P1D' );
	$period   = new DatePeriod( $start, $interval, $end );

	$working_days = 0;
	foreach ( $period as $date ) {
		$day_of_week = (int) $date->format( 'N' );
		if ( $day_of_week <= 5 ) {
			$working_days++;
		}
	}

	return $working_days;
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
 * Get cached clients list.
 *
 * @return array Array of client objects.
 */
function pltt_get_cached_clients() {
	$cache_key = 'pltt_clients_list';
	$clients   = get_transient( $cache_key );

	if ( false === $clients ) {
		$clients = PLTT_Clients::get_all();
		set_transient( $cache_key, $clients, HOUR_IN_SECONDS );
	}

	return $clients;
}

/**
 * Flush clients cache.
 */
function pltt_flush_client_cache() {
	delete_transient( 'pltt_clients_list' );
}

/**
 * Get cached projects list.
 *
 * @param array $args Query arguments.
 * @return array Array of project objects.
 */
function pltt_get_cached_projects( $args = array() ) {
	// Only cache default "get all" queries.
	if ( empty( $args ) ) {
		$cache_key = 'pltt_projects_list';
		$projects  = get_transient( $cache_key );

		if ( false === $projects ) {
			$projects = PLTT_Projects::get_all();
			set_transient( $cache_key, $projects, HOUR_IN_SECONDS );
		}

		return $projects;
	}

	// Don't cache filtered queries.
	return PLTT_Projects::get_all( $args );
}

/**
 * Flush projects cache.
 */
function pltt_flush_project_cache() {
	delete_transient( 'pltt_projects_list' );
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
 * Get cached tags list.
 *
 * @return array Array of tag objects.
 */
function pltt_get_cached_tags() {
	$cache_key = 'pltt_tags_list';
	$tags      = get_transient( $cache_key );

	if ( false === $tags ) {
		$tags = PLTT_Tags::get_all();
		set_transient( $cache_key, $tags, HOUR_IN_SECONDS );
	}

	return $tags;
}

/**
 * Flush tags cache.
 */
function pltt_flush_tag_cache() {
	delete_transient( 'pltt_tags_list' );
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
 * If a cache is provided but the ID is not found in it, the DB is NOT queried as a fallback —
 * pass empty arrays to have this function load from DB on-demand.
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
 *     @type bool   $show_amount Whether to show the Amount column. Default false.
 *     @type string $table_class Additional CSS class for the table element.
 *     @type bool   $inline_edit Whether to render interactive inline edit controls. Default false.
 *     @type array  $all_tags    All available tag name strings. Required when inline_edit is true.
 * }
 */
function pltt_render_entry_table( $entries, $options = array() ) {
	$show_amount = ! empty( $options['show_amount'] );
	$table_class = ! empty( $options['table_class'] ) ? ' ' . $options['table_class'] : '';
	$inline_edit = ! empty( $options['inline_edit'] );
	$all_tags    = $inline_edit && ! empty( $options['all_tags'] ) ? $options['all_tags'] : array();

	if ( empty( $entries ) ) {
		return;
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
				?>
				<tr<?php echo $is_billed ? ' class="pltt-billed"' : ''; ?><?php echo $inline_edit ? ' data-entry-id="' . esc_attr( $entry->id ) . '"' : ''; ?>>
					<td class="pltt-entry-desc-cell<?php echo $is_billable ? ' pltt-desc-billable' : ''; ?>">
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
								$billable_amount = round( ( $entry->duration_minutes / 60.0 ) * $hourly_rate, 2 );
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
 * Render the allocation bar HTML for a project.
 *
 * Outputs a progress bar showing how much of the budget/allocation has been used.
 * Labels use plain-language duration ("67h 48m left · 11%"); a title tooltip shows
 * decimal hours. For fee-based fixed budgets, pass $fee_args with 'spent_dollars' and
 * 'budget_dollars' keys — the bar still displays hours (caller pre-computes budget_hours
 * from budget_fee ÷ rate), and the tooltip adds the dollar breakdown.
 *
 * @param float       $alloc_mins   Minutes logged in the relevant allocation period.
 * @param float       $budget_hours Allocation budget in hours.
 * @param string      $billing_type 'recurring' or 'fixed'.
 * @param array|null  $fee_args     Optional. Keys: 'spent_dollars' (float), 'budget_dollars' (float).
 */
function pltt_render_allocation_bar( $alloc_mins, $budget_hours, $billing_type, $fee_args = null ) {
	$alloc_hours = $alloc_mins / 60;
	$pct         = $budget_hours > 0 ? ( $alloc_hours / $budget_hours ) * 100 : 0;
	$is_over     = $pct >= 100;
	$bar_width   = min( $pct, 100 );
	$pct_display = round( $pct );

	if ( $is_over ) {
		$delta_fmt = pltt_format_duration( ( $alloc_hours - $budget_hours ) * 60 );
		$label     = $delta_fmt . ' ' . __( 'over', 'plain-language-time-tracker' ) . ' · ' . $pct_display . '%';
	} else {
		$delta_fmt = pltt_format_duration( ( $budget_hours - $alloc_hours ) * 60 );
		$label     = $delta_fmt . ' ' . __( 'left', 'plain-language-time-tracker' ) . ' · ' . $pct_display . '%';
	}

	if ( null !== $fee_args ) {
		$tooltip = pltt_format_hours( $alloc_mins ) . ' ' . __( 'hrs', 'plain-language-time-tracker' )
			. ' · ' . __( 'Spent:', 'plain-language-time-tracker' ) . ' ' . pltt_format_currency( $fee_args['spent_dollars'] )
			. ' · ' . __( 'Budget:', 'plain-language-time-tracker' ) . ' ' . pltt_format_currency( $fee_args['budget_dollars'] );
	} else {
		$tooltip = pltt_format_hours( $alloc_mins ) . ' ' . __( 'hrs', 'plain-language-time-tracker' )
			. ' · ' . __( 'Budget:', 'plain-language-time-tracker' ) . ' ' . pltt_format_hours( $budget_hours * 60 ) . ' ' . __( 'hrs', 'plain-language-time-tracker' );
	}
	?>
	<div class="pltt-alloc-cell" title="<?php echo esc_attr( $tooltip ); ?>">
		<div class="pltt-alloc-bar-wrap">
			<div class="pltt-alloc-bar<?php echo $is_over ? ' pltt-alloc-over' : ''; ?>"
				 style="width:<?php echo esc_attr( $bar_width ); ?>%"></div>
		</div>
		<span class="pltt-alloc-label<?php echo $is_over ? ' pltt-alloc-over' : ''; ?>">
			<?php echo esc_html( $label ); ?>
		</span>
	</div>
	<?php
}

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
 * @param int $minutes Total minutes.
 * @return string Formatted duration (e.g., "2h 30m").
 */
function pltt_format_duration( $minutes ) {
	if ( ! is_numeric( $minutes ) || $minutes < 0 ) {
		return '0m';
	}

	$hours = floor( $minutes / 60 );
	$mins  = $minutes % 60;

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
 * @param string $text Text to search.
 * @return array Array of tags (without # prefix).
 */
function pltt_extract_tags( $text ) {
	preg_match_all( '/#([a-zA-Z0-9_-]+)/', $text, $matches );
	return array_unique( $matches[1] );
}

/**
 * Remove hashtags from text.
 *
 * @param string $text Text to clean.
 * @return string Text without hashtags.
 */
function pltt_remove_tags( $text ) {
	return trim( preg_replace( '/#[a-zA-Z0-9_-]+/', '', $text ) );
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

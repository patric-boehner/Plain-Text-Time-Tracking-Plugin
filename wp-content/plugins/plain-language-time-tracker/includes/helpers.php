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
	<table class="widefat striped<?php echo esc_attr( $table_class ); ?>">
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
					<td class="pltt-entry-desc-cell">
						<?php if ( $is_billed && ! $inline_edit ) : ?>
							<span class="screen-reader-text"><?php esc_html_e( 'Invoiced:', 'plain-language-time-tracker' ); ?></span>
						<?php endif; ?>
						<?php echo esc_html( $entry->description ); ?>
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
								$billable_amount = (float) $entry->billable_amount;
							} else {
								$hourly_rate = 0.0;
								if ( $project && $project->hourly_rate > 0 ) {
									$hourly_rate = (float) $project->hourly_rate;
								} elseif ( $client && $client->hourly_rate > 0 ) {
									$hourly_rate = (float) $client->hourly_rate;
								} elseif ( defined( 'PLTT_DEFAULT_HOURLY_RATE' ) ) {
									$hourly_rate = (float) PLTT_DEFAULT_HOURLY_RATE;
								}
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
	} elseif ( ! empty( $project->budget_hours ) ) {
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
 * For recurring projects the suffix is "hrs/mo"; for fixed budgets "hrs est.".
 *
 * @param float  $alloc_mins   Minutes logged in the relevant allocation period.
 * @param float  $budget_hours Allocation budget in hours.
 * @param string $billing_type 'recurring' or 'fixed'.
 */
function pltt_render_allocation_bar( $alloc_mins, $budget_hours, $billing_type ) {
	$alloc_hours = $alloc_mins / 60;
	$pct         = $budget_hours > 0 ? ( $alloc_hours / $budget_hours ) * 100 : 0;
	$is_over     = $pct >= 100;
	$bar_width   = min( $pct, 100 );
	$pct_display = round( $pct );
	if ( $is_over ) {
		$delta_fmt = pltt_format_hours( ( $alloc_hours - $budget_hours ) * 60 );
		$label     = $delta_fmt . ' ' . __( 'hrs over', 'plain-language-time-tracker' ) . ' · ' . $pct_display . '%';
	} else {
		$delta_fmt = pltt_format_hours( ( $budget_hours - $alloc_hours ) * 60 );
		$label     = $delta_fmt . ' ' . __( 'hrs left', 'plain-language-time-tracker' ) . ' · ' . $pct_display . '%';
	}
	?>
	<div class="pltt-alloc-cell">
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

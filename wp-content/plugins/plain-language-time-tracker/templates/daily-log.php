<?php
/**
 * Daily Log template.
 *
 * @package PlainLanguageTimeTracker
 *
 * @var string $date Current date.
 * @var object|null $log Daily log object.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$content       = $log ? $log->content : '';
$is_processed  = $log && $log->processed;
$previous_date = PLTT_Daily_Log::get_previous_date( $date );
$next_date     = PLTT_Daily_Log::get_next_date( $date );
$today         = pltt_get_current_date();

// Inline editor context (from PLTT_Daily_Log::render via PLTT_Review::get_editor_context).
$entries            = $editor['entries'];
$clients            = $editor['clients'];
$projects_by_client = $editor['projects_by_client'];
$all_tags           = $editor['all_tags'];
$summary            = $editor['summary'];
$has_entries        = ! empty( $entries );

// Day total for the summary cards under the log.
$total_minutes = 0;
foreach ( $entries as $entry ) {
	$total_minutes += $entry['duration_minutes'] ?? 0;
}

// When arriving from Reports' "Edit" (which now routes here), keep a way back.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET routing.
$return_to_raw = isset( $_GET['return_to'] ) ? esc_url_raw( wp_unslash( $_GET['return_to'] ) ) : '';
$return_to     = $return_to_raw ? wp_validate_redirect( $return_to_raw, '' ) : '';
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<?php
		// Light header — Today is a day, not a scope with terms: three lines, no
		// figures inside (those sit in the number bar below). The H1 reads "Today"
		// on the current day and the weekday date when navigated back; the badge
		// and date fold up here from the old .pltt-date-display block.
		$is_today_view = ( $date === $today );
		$date_full     = date_i18n( 'l, F j, Y', strtotime( $date ) );
		$entry_count   = count( $entries );
		if ( $has_entries ) {
			$today_l2 = sprintf(
				/* translators: 1: entry-count phrase (e.g. "7 entries"); 2: total time logged (e.g. "6h 00m"). */
				esc_html__( '%1$s recorded · %2$s logged', 'plain-language-time-tracker' ),
				esc_html( sprintf( _n( '%s entry', '%s entries', $entry_count, 'plain-language-time-tracker' ), number_format_i18n( $entry_count ) ) ),
				esc_html( pltt_format_duration( (int) $total_minutes ) )
			);
		} else {
			$today_l2 = esc_html__( 'Nothing recorded yet', 'plain-language-time-tracker' );
		}
		?>
		<div class="pltt-light-header">
			<div class="pltt-lh-titlerow">
				<h1><?php echo esc_html( $is_today_view ? __( 'Today', 'plain-language-time-tracker' ) : $date_full ); ?></h1>
				<?php if ( $is_processed ) : ?>
					<span class="pltt-badge pltt-badge-success"><?php esc_html_e( 'Processed', 'plain-language-time-tracker' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="pltt-lh-l2"><?php echo $today_l2; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php if ( $is_today_view ) : ?>
			<?php endif; ?>
		</div>
		<?php
		// OPT-DUP1: display success/error notices via shared helper. Inside the
		// header div on purpose — see memory: WordPress JS relocates notices
		// that are too far from the H1.
		pltt_render_admin_notices(
			array(
				'entries_saved'       => __( 'Entries saved successfully.', 'plain-language-time-tracker' ),
				'nothing_reprocessed' => __( 'Nothing new to process — every timestamp is already part of a finalized entry.', 'plain-language-time-tracker' ),
			),
			array(
				'invalid_date'     => __( 'Invalid date.', 'plain-language-time-tracker' ),
				'no_entries'       => __( 'No entries to save.', 'plain-language-time-tracker' ),
				'save_failed'      => __( 'Failed to save entries.', 'plain-language-time-tracker' ),
				'too_many_entries' => __( 'Too many entries in one save (max 200).', 'plain-language-time-tracker' ),
			)
		);
		?>

	<?php // Date nav sits in the header (top-right), where the Today · History toggle used to be. ?>
	<div class="pltt-daily-log-nav pltt-daily-log-nav-row">
			<div class="pltt-date-nav pltt-date-nav-single"
				role="group"
				aria-label="<?php esc_attr_e( 'Date navigation', 'plain-language-time-tracker' ); ?>">

				<?php if ( $previous_date ) : ?>
					<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $previous_date ) ) ); ?>"
						class="pltt-date-nav-step pltt-date-nav-prev"
						aria-label="<?php esc_attr_e( 'Previous day', 'plain-language-time-tracker' ); ?>"></a>
				<?php endif; ?>

				<div class="pltt-date-nav-picker">
					<button type="button" class="pltt-date-nav-label" id="pltt-date-nav-trigger"
						aria-label="<?php esc_attr_e( 'Pick a date', 'plain-language-time-tracker' ); ?>">
						<span class="pltt-date-nav-label-main"><?php echo esc_html( pltt_format_date( $date ) ); ?></span>
					</button>
					<input type="date" id="pltt-log-date" value="<?php echo esc_attr( $date ); ?>" max="<?php echo esc_attr( $today ); ?>" class="pltt-date-nav-hidden-input" tabindex="-1">
				</div>

				<?php if ( $next_date && $next_date <= $today ) : ?>
					<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $next_date ) ) ); ?>"
						class="pltt-date-nav-step pltt-date-nav-next"
						aria-label="<?php esc_attr_e( 'Next day', 'plain-language-time-tracker' ); ?>"></a>
				<?php endif; ?>
			</div>

			<?php if ( $date !== $today ) : ?>
				<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Today', 'plain-language-time-tracker' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $return_to ) : ?>
		<p class="pltt-back-link">
			<a href="<?php echo esc_url( $return_to ); ?>">&larr; <?php esc_html_e( 'Back to Reports', 'plain-language-time-tracker' ); ?></a>
		</p>
	<?php endif; ?>

	<div class="pltt-log-container <?php echo $is_processed ? 'pltt-log-processed' : ''; ?>">
		<textarea
			id="pltt-log-textarea"
			class="pltt-log-textarea"
			placeholder="<?php esc_attr_e( "Type @ to insert the current time...\n\nExample:\n@9:15am - Email catchup\n@10:30am - Client meeting #meeting\n@12:00pm - done", 'plain-language-time-tracker' ); ?>"
		><?php echo esc_textarea( $content ); ?></textarea>

		<div class="pltt-log-footer">
			<div class="pltt-log-hint">
				<p>
					<code>@</code> <?php esc_html_e( 'Insert timestamp', 'plain-language-time-tracker' ); ?> &nbsp;|&nbsp;
					<code>#tag</code> <?php esc_html_e( 'Add tags', 'plain-language-time-tracker' ); ?> &nbsp;|&nbsp;
					<code>done</code> <?php esc_html_e( 'End current task', 'plain-language-time-tracker' ); ?>
				</p>
			</div>

			<div class="pltt-log-actions">
				<span id="pltt-save-indicator" class="pltt-save-indicator"></span>
				<?php if ( $is_processed ) : ?>
					<!-- Primary: Update Notes (preserves processed state) -->
					<button type="button" id="pltt-update-notes-btn" class="button button-primary button-large">
						<?php esc_html_e( 'Update Notes', 'plain-language-time-tracker' ); ?>
					</button>
					<!-- Secondary: Process Entries (destructive action) -->
					<button type="button" id="pltt-process-btn" class="button button-secondary">
						<?php esc_html_e( 'Process Entries', 'plain-language-time-tracker' ); ?>
					</button>
				<?php else : ?>
					<!-- Unprocessed: Save (secondary) + Process (primary) -->
					<button type="button" id="pltt-save-btn" class="button button-secondary">
						<?php esc_html_e( 'Save', 'plain-language-time-tracker' ); ?>
					</button>
					<button type="button" id="pltt-process-btn" class="button button-primary button-large">
						<?php esc_html_e( 'Process Time Entries', 'plain-language-time-tracker' ); ?> &rarr;
					</button>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php if ( $is_post_parse ) : ?>
		<?php // Just processed: confirm the parsed blocks in place — no separate Review screen. ?>
		<div class="pltt-existing-entries" id="pltt-entries">
			<h3><?php esc_html_e( 'Review parsed entries', 'plain-language-time-tracker' ); ?></h3>
			<input type="hidden" id="pltt-entry-date" value="<?php echo esc_attr( $date ); ?>">
			<?php include PLTT_PLUGIN_DIR . 'templates/partials/review-post-parse.php'; ?>
		</div>
	<?php elseif ( $has_entries ) : ?>
		<div class="pltt-existing-entries" id="pltt-entries">
			<h3><?php esc_html_e( 'Recorded Entries', 'plain-language-time-tracker' ); ?></h3>

			<?php // Pared day summary (matches Reports): hours, billable hours, amount. ?>
			<div class="pltt-summary-cards pltt-numbar">
				<div class="card">
					<div class="card-label"><?php esc_html_e( 'Total Hours', 'plain-language-time-tracker' ); ?></div>
					<div class="card-value"><?php echo esc_html( pltt_format_hours( $total_minutes ) ); ?></div>
				</div>
				<div class="card">
					<div class="card-label"><?php esc_html_e( 'Billable Hours', 'plain-language-time-tracker' ); ?></div>
					<div class="card-value"><?php echo esc_html( pltt_format_hours( $summary['billable_minutes'] ) ); ?></div>
				</div>
				<?php if ( (float) $summary['billable_amount'] > 0 ) : ?>
					<div class="card">
						<div class="card-label"><?php esc_html_e( 'Billable Amount', 'plain-language-time-tracker' ); ?></div>
						<div class="card-value"><?php echo esc_html( pltt_format_currency( $summary['billable_amount'] ) ); ?></div>
					</div>
				<?php endif; ?>
			</div>

			<?php
			// Ceilings reached by the day that was just committed. Only after a
			// save — mid-flow this figure competes with the task in hand and lands
			// exactly when nothing can be done about it. Here it's a report, and
			// stays silent when the day stayed inside every ceiling.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
			if ( isset( $_GET['pltt_message'] ) && 'entries_saved' === sanitize_text_field( wp_unslash( $_GET['pltt_message'] ) ) ) {
				pltt_render_consumption_notice( $date );
			}
			?>

			<input type="hidden" id="pltt-entry-date" value="<?php echo esc_attr( $date ); ?>">

			<?php
			// Inline editable list (compact rows + expandable edit form) — wired by
			// review.js IIFE 2, the same editor the review screen uses.
			include PLTT_PLUGIN_DIR . 'templates/partials/entries-editor.php';
			?>
		</div>
	<?php endif; ?>

	<?php if ( $has_entries ) : ?>
		<?php
		// Tag globals + create-new modals — needed by both the post-parse confirm
		// table and the settled inline editor.
		// SEC-L1: JSON_HEX_TAG/AMP so a "</script>" inside a tag/group name can't
		// break out of the inline <script>.
		wp_add_inline_script(
			'pltt-review',
			'var plttAllTags = ' . wp_json_encode( $all_tags, JSON_HEX_TAG | JSON_HEX_AMP ) . ';var plttTagGroups = ' . wp_json_encode( PLTT_Tags::get_name_to_group_map(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);

		include PLTT_PLUGIN_DIR . 'templates/partials/entry-editor-modals.php';
		?>
	<?php endif; ?>
	<?php // Notice params are stripped from the URL by PLTT.cleanNoticeParams() in shared.js. ?>
</div>

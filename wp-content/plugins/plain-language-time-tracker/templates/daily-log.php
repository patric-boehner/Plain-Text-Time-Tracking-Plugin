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

// Check if there are existing entries for this date.
$existing_entries = PLTT_Entries::get_by_date( $date );
$has_entries      = ! empty( $existing_entries );
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Daily Log', 'plain-language-time-tracker' ); ?></h1>
		<div class="pltt-daily-log-nav">
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

		<?php
		// OPT-DUP1: display success/error notices via shared helper. Inside the
		// header div on purpose — see memory: WordPress JS relocates notices
		// that are too far from the H1.
		pltt_render_admin_notices(
			array(
				'entries_saved' => __( 'Entries saved successfully.', 'plain-language-time-tracker' ),
			),
			array(
				'invalid_date'     => __( 'Invalid date.', 'plain-language-time-tracker' ),
				'no_entries'       => __( 'No entries to save.', 'plain-language-time-tracker' ),
				'save_failed'      => __( 'Failed to save entries.', 'plain-language-time-tracker' ),
				'too_many_entries' => __( 'Too many entries in one save (max 200).', 'plain-language-time-tracker' ),
			)
		);
		?>
	</div>

	<div class="pltt-date-display">
		<h2><?php echo esc_html( pltt_format_date( $date ) ); ?></h2>
		<?php if ( $has_entries ) : ?>
			<span class="pltt-badge pltt-badge-info">
				<?php
				printf(
					/* translators: %d: number of entries */
					esc_html( _n( '%d entry recorded', '%d entries recorded', count( $existing_entries ), 'plain-language-time-tracker' ) ),
					count( $existing_entries )
				);
				?>
			</span>
		<?php endif; ?>
		<?php if ( $is_processed ) : ?>
			<span class="pltt-badge pltt-badge-success"><?php esc_html_e( 'Processed', 'plain-language-time-tracker' ); ?></span>
		<?php endif; ?>
	</div>

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

	<?php if ( $has_entries ) : ?>
		<div class="pltt-existing-entries">
			<h3><?php esc_html_e( 'Recorded Entries', 'plain-language-time-tracker' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'You have already processed entries for this date.', 'plain-language-time-tracker' ); ?>
			</p>

			<?php pltt_render_entry_table( $existing_entries, array( 'table_class' => 'pltt-daily-log-entries' ) ); ?>

			<div class="pltt-existing-entries-footer">
				<a href="<?php echo esc_url( pltt_get_admin_url( 'review', array( 'date' => $date ) ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Edit Entries', 'plain-language-time-tracker' ); ?> &rarr;
				</a>
			</div>
		</div>
	<?php endif; ?>
	<?php // Notice params are stripped from the URL by PLTT.cleanNoticeParams() in shared.js. ?>
</div>

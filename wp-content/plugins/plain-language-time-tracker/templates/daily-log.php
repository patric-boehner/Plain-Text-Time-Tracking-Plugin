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
		<div class="pltt-date-nav">
			<?php if ( $previous_date ) : ?>
				<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $previous_date ) ) ); ?>" class="button">
					&larr; <?php esc_html_e( 'Previous', 'plain-language-time-tracker' ); ?>
				</a>
			<?php endif; ?>

			<input type="date" id="pltt-log-date" value="<?php echo esc_attr( $date ); ?>" max="<?php echo esc_attr( $today ); ?>">

			<?php if ( $next_date && $next_date <= $today ) : ?>
				<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $next_date ) ) ); ?>" class="button">
					<?php esc_html_e( 'Next', 'plain-language-time-tracker' ); ?> &rarr;
				</a>
			<?php endif; ?>

			<?php if ( $date !== $today ) : ?>
				<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Today', 'plain-language-time-tracker' ); ?>
				</a>
			<?php endif; ?>
		</div>
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

	<div class="pltt-log-container">
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
				<button type="button" id="pltt-process-btn" class="button button-primary button-large">
					<?php esc_html_e( 'Process Time Entries', 'plain-language-time-tracker' ); ?> &rarr;
				</button>
			</div>
		</div>
	</div>

	<?php if ( $has_entries ) : ?>
		<div class="pltt-existing-entries">
			<h3><?php esc_html_e( 'Recorded Entries', 'plain-language-time-tracker' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'You have already processed entries for this date.', 'plain-language-time-tracker' ); ?>
				<a href="<?php echo esc_url( pltt_get_admin_url( 'review', array( 'date' => $date ) ) ); ?>">
					<?php esc_html_e( 'View or edit entries', 'plain-language-time-tracker' ); ?> &rarr;
				</a>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'plain-language-time-tracker' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'plain-language-time-tracker' ); ?></th>
						<th><?php esc_html_e( 'Description', 'plain-language-time-tracker' ); ?></th>
						<th><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
						<th><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></th>
						<th><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></th>
						<th><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $existing_entries as $entry ) :
						$client  = ! empty( $entry->client_id ) ? PLTT_Clients::get( $entry->client_id ) : null;
						$project = ! empty( $entry->project_id ) ? PLTT_Projects::get( $entry->project_id ) : null;
						?>
						<tr>
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
							<td><?php echo esc_html( $entry->description ); ?></td>
							<td><?php echo $client ? esc_html( $client->name ) : '<span class="pltt-empty">—</span>'; ?></td>
							<td><?php echo $project ? esc_html( $project->name ) : '<span class="pltt-empty">—</span>'; ?></td>
							<td><?php echo ! empty( $entry->tags ) ? esc_html( $entry->tags ) : '<span class="pltt-empty">—</span>'; ?></td>
							<td><?php echo $entry->billable ? '<span class="pltt-status-billable">Yes</span>' : '<span class="pltt-empty">—</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>

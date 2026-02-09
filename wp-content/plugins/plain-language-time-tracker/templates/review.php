<?php
/**
 * Review & Verify template.
 *
 * @package PlainLanguageTimeTracker
 *
 * @var string $date    Current date.
 * @var array  $entries Parsed entries.
 * @var array  $clients All clients.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$total_minutes = 0;
foreach ( $entries as $entry ) {
	$total_minutes += $entry['duration_minutes'] ?? 0;
}
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Review Time Entries', 'plain-language-time-tracker' ); ?></h1>
		<div class="pltt-header-actions">
			<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $date ) ) ); ?>" class="button">
				&larr; <?php esc_html_e( 'Back to Notes', 'plain-language-time-tracker' ); ?>
			</a>
		</div>
	</div>

	<div class="pltt-date-display">
		<h2><?php echo esc_html( pltt_format_date( $date ) ); ?></h2>
		<input type="hidden" id="pltt-entry-date" value="<?php echo esc_attr( $date ); ?>">
	</div>

	<?php if ( empty( $entries ) ) : ?>
		<div class="pltt-notice pltt-notice-warning">
			<p><?php esc_html_e( 'No time entries found for this date. Go back and add some notes with timestamps.', 'plain-language-time-tracker' ); ?></p>
		</div>
	<?php else : ?>

		<div class="pltt-summary-cards">
			<div class="card">
				<div class="card-value"><?php echo esc_html( count( $entries ) ); ?></div>
				<div class="card-label"><?php esc_html_e( 'Entries', 'plain-language-time-tracker' ); ?></div>
			</div>
			<div class="card">
				<div class="card-value"><?php echo esc_html( pltt_format_hours( $total_minutes ) ); ?></div>
				<div class="card-label"><?php esc_html_e( 'Total Hours', 'plain-language-time-tracker' ); ?></div>
			</div>
		</div>

		<form id="pltt-review-form">
			<table class="pltt-review-table widefat">
				<thead>
					<tr>
						<th class="pltt-col-time"><?php esc_html_e( 'Date / Time', 'plain-language-time-tracker' ); ?></th>
						<th class="pltt-col-duration"><?php esc_html_e( 'Duration', 'plain-language-time-tracker' ); ?></th>
						<th class="pltt-col-description"><?php esc_html_e( 'Description', 'plain-language-time-tracker' ); ?></th>
						<th class="pltt-col-client"><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
						<th class="pltt-col-project"><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></th>
						<th class="pltt-col-tags"><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></th>
						<th class="pltt-col-billable"><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></th>
						<th class="pltt-col-actions"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $index => $entry ) : ?>
						<?php
						$entry_id           = $entry['id'] ?? 0;
						$predicted_client   = $entry['predicted_client_id'] ?? 0;
						$predicted_project  = $entry['predicted_project_id'] ?? 0;
						$client_confidence  = $entry['client_confidence'] ?? 0;
						$confidence_class   = $client_confidence >= PLTT_CONFIDENCE_THRESHOLD ? 'high' : ( $client_confidence >= 0.4 ? 'medium' : 'low' );
						$has_prediction     = $predicted_client > 0;
						?>
						<tr class="pltt-entry-row" data-entry-id="<?php echo esc_attr( $entry_id ); ?>" data-index="<?php echo esc_attr( $index ); ?>" data-original-project-id="<?php echo esc_attr( $predicted_project ); ?>">
							<td class="pltt-time-cell">
								<div class="pltt-time-display">
									<span class="pltt-date-text"><?php echo esc_html( pltt_format_date( $entry['entry_date'] ?? $date, 'M j, Y' ) ); ?></span>
									<span class="pltt-time-text">
										<?php
										echo esc_html( pltt_format_time( $entry['start_time'] ) );
										if ( ! empty( $entry['end_time'] ) ) {
											echo ' &ndash; ' . esc_html( pltt_format_time( $entry['end_time'] ) );
										}
										?>
									</span>
									<a href="#edit_time" class="pltt-edit-time hide-if-no-js" role="button"><?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?></a>
								</div>
								<div class="pltt-time-edit inline-edit-row hide-if-js">
									<input
										type="date"
										name="entries[<?php echo esc_attr( $index ); ?>][entry_date]"
										class="pltt-entry-date-input"
										value="<?php echo esc_attr( $entry['entry_date'] ?? $date ); ?>"
									>
									<div class="pltt-time-inputs">
										<input
											type="time"
											name="entries[<?php echo esc_attr( $index ); ?>][start_time]"
											class="pltt-start-time"
											value="<?php echo esc_attr( $entry['start_time'] ?? '' ); ?>"
										>
										<span class="pltt-time-separator">&ndash;</span>
										<input
											type="time"
											name="entries[<?php echo esc_attr( $index ); ?>][end_time]"
											class="pltt-end-time"
											value="<?php echo esc_attr( $entry['end_time'] ?? '' ); ?>"
										>
									</div>
									<div class="submit inline-edit-save">
										<button type="button" class="pltt-save-time button button-primary save"><?php esc_html_e( 'Update', 'plain-language-time-tracker' ); ?></button>
										<button type="button" class="pltt-cancel-time button cancel"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
									</div>
								</div>
								<input type="hidden" name="entries[<?php echo esc_attr( $index ); ?>][raw_text]" value="<?php echo esc_attr( $entry['raw_text'] ?? '' ); ?>">
								<?php if ( $entry_id ) : ?>
									<input type="hidden" name="entries[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $entry_id ); ?>">
								<?php endif; ?>
							</td>
							<td class="pltt-duration-cell">
								<span class="pltt-duration-display"><?php echo ! empty( $entry['duration_minutes'] ) ? esc_html( pltt_format_duration( $entry['duration_minutes'] ) ) : '--'; ?></span>
								<input type="hidden" name="entries[<?php echo esc_attr( $index ); ?>][duration_minutes]" class="pltt-duration-minutes" value="<?php echo esc_attr( $entry['duration_minutes'] ?? 0 ); ?>">
							</td>
							<td>
								<input
									type="text"
									name="entries[<?php echo esc_attr( $index ); ?>][description]"
									class="pltt-description regular-text"
									value="<?php echo esc_attr( $entry['description'] ?? '' ); ?>"
								>
							</td>
							<td class="<?php echo $has_prediction ? 'pltt-predicted pltt-confidence-' . esc_attr( $confidence_class ) : ''; ?>">
								<select name="entries[<?php echo esc_attr( $index ); ?>][client_id]" class="pltt-client-select">
									<option value=""><?php esc_html_e( 'Select client...', 'plain-language-time-tracker' ); ?></option>
									<?php foreach ( $clients as $client ) : ?>
										<option value="<?php echo esc_attr( $client->id ); ?>" <?php selected( $predicted_client, $client->id ); ?>>
											<?php echo esc_html( $client->name ); ?>
										</option>
									<?php endforeach; ?>
									<option value="new">+ <?php esc_html_e( 'Add new client...', 'plain-language-time-tracker' ); ?></option>
								</select>
								<?php if ( $has_prediction && $client_confidence > 0 ) : ?>
									<span class="pltt-confidence-indicator" title="<?php printf( esc_attr__( 'Confidence: %s%%', 'plain-language-time-tracker' ), round( $client_confidence * 100 ) ); ?>">
										<?php echo $client_confidence >= PLTT_CONFIDENCE_THRESHOLD ? '★' : '☆'; ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<select name="entries[<?php echo esc_attr( $index ); ?>][project_id]" class="pltt-project-select">
									<option value=""><?php esc_html_e( 'Select project...', 'plain-language-time-tracker' ); ?></option>
									<?php if ( $predicted_client > 0 && ! empty( $projects_by_client[ $predicted_client ] ) ) : ?>
										<?php foreach ( $projects_by_client[ $predicted_client ] as $project ) : ?>
											<?php
											$is_archived = ( 'archived' === $project->status );
											// Only show archived projects if this entry references them.
											if ( $is_archived && (int) $project->id !== (int) $predicted_project ) {
												continue;
											}
											$label = $is_archived
												? $project->name . ' ' . __( '(Archived)', 'plain-language-time-tracker' )
												: $project->name;
											?>
											<option
												value="<?php echo esc_attr( $project->id ); ?>"
												<?php selected( $predicted_project, $project->id ); ?>
												<?php if ( $is_archived ) : ?>
													data-archived="1"
												<?php endif; ?>
											>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
									<option value="new">+ <?php esc_html_e( 'Add new project...', 'plain-language-time-tracker' ); ?></option>
								</select>
							</td>
							<td>
								<div class="pltt-tag-input-wrap">
									<input
										type="hidden"
										name="entries[<?php echo esc_attr( $index ); ?>][tags]"
										class="pltt-tags"
										value="<?php echo esc_attr( $entry['tags'] ?? '' ); ?>"
									>
								</div>
							</td>
							<td class="pltt-col-billable">
								<input
									type="checkbox"
									name="entries[<?php echo esc_attr( $index ); ?>][billable]"
									class="pltt-billable"
									value="1"
									<?php checked( ! empty( $entry['billable'] ) ); ?>
								>
							</td>
							<td class="pltt-col-actions">
								<button type="button" class="pltt-delete-entry button-link-delete" title="<?php esc_attr_e( 'Delete entry', 'plain-language-time-tracker' ); ?>">
									<span class="dashicons dashicons-trash"></span>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="pltt-form-actions">
				<div class="pltt-form-actions-left">
					<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $date ) ) ); ?>" class="button button-secondary">
						&larr; <?php esc_html_e( 'Back to Notes', 'plain-language-time-tracker' ); ?>
					</a>
				</div>
				<div class="pltt-form-actions-right">
					<span id="pltt-save-status"></span>
					<button type="submit" id="pltt-save-all" class="button button-primary button-large">
						<?php esc_html_e( 'Save All Entries', 'plain-language-time-tracker' ); ?>
					</button>
				</div>
			</div>
		</form>

	<?php endif; ?>
</div>

<script>var plttAllTags = <?php echo wp_json_encode( $all_tags ); ?>;</script>

<!-- New Client Modal -->
<div id="pltt-client-modal" class="pltt-modal" style="display: none;">
	<div class="pltt-modal-content">
		<h3><?php esc_html_e( 'Add New Client', 'plain-language-time-tracker' ); ?></h3>
		<p>
			<label for="pltt-new-client-name"><?php esc_html_e( 'Client Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-new-client-name" class="regular-text">
		</p>
		<p class="pltt-modal-actions">
			<button type="button" id="pltt-save-client" class="button button-primary"><?php esc_html_e( 'Create Client', 'plain-language-time-tracker' ); ?></button>
			<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</p>
	</div>
</div>

<!-- New Project Modal -->
<div id="pltt-project-modal" class="pltt-modal" style="display: none;">
	<div class="pltt-modal-content">
		<h3><?php esc_html_e( 'Add New Project', 'plain-language-time-tracker' ); ?></h3>
		<p>
			<label for="pltt-new-project-name"><?php esc_html_e( 'Project Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-new-project-name" class="regular-text">
			<input type="hidden" id="pltt-new-project-client-id">
		</p>
		<p class="pltt-modal-actions">
			<button type="button" id="pltt-save-project" class="button button-primary"><?php esc_html_e( 'Create Project', 'plain-language-time-tracker' ); ?></button>
			<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</p>
	</div>
</div>

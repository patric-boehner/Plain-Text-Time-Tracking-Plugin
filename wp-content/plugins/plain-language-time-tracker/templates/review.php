<?php
/**
 * Review & Verify template.
 *
 * @package PlainLanguageTimeTracker
 *
 * @var string $date               Current date.
 * @var array  $entries            Parsed entries.
 * @var array  $summary            Summary stats (billable_minutes, billable_amount).
 * @var array  $clients            All clients.
 * @var array  $projects_by_client Projects grouped by client.
 * @var array  $all_tags           All known tags.
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
		<p class="description" style="padding: 20px; text-align: center; background: #fff3cd; border-left: 4px solid #ffc107;">
			<?php esc_html_e( 'No time entries found for this date. Go back and add some notes with timestamps.', 'plain-language-time-tracker' ); ?>
		</p>
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
			<div class="card">
				<div class="card-value"><?php echo esc_html( pltt_format_hours( $summary['billable_minutes'] ) ); ?></div>
				<div class="card-label"><?php esc_html_e( 'Billable Hours', 'plain-language-time-tracker' ); ?></div>
			</div>
			<?php if ( (float) $summary['billable_amount'] > 0 ) : ?>
				<div class="card">
					<div class="card-value"><?php echo esc_html( pltt_format_currency( $summary['billable_amount'] ) ); ?></div>
					<div class="card-label"><?php esc_html_e( 'Billable Amount', 'plain-language-time-tracker' ); ?></div>
				</div>
			<?php endif; ?>
		</div>

		<?php
		// Check if any entries are already saved (id > 0) to conditionally show billed column.
		$has_saved_entries = false;
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['id'] ) && $entry['id'] > 0 ) {
				$has_saved_entries = true;
				break;
			}
		}
		?>
		<form id="pltt-review-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pltt_save_entries">
			<input type="hidden" name="date" value="<?php echo esc_attr( $date ); ?>">
			<input type="hidden" name="entries" id="pltt-entries-data" value="">
			<?php wp_nonce_field( 'pltt_save_entries', '_wpnonce', true, true ); ?>
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
						<?php if ( $has_saved_entries ) : ?>
							<th class="pltt-col-billed"><?php esc_html_e( 'Invoiced', 'plain-language-time-tracker' ); ?></th>
						<?php endif; ?>
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

						$row_classes = array( 'pltt-entry-row' );
						?>
						<tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>" data-entry-id="<?php echo esc_attr( $entry_id ); ?>" data-index="<?php echo esc_attr( $index ); ?>" data-original-project-id="<?php echo esc_attr( $predicted_project ); ?>">
							<td class="pltt-time-cell">
								<div class="pltt-time-display">
									<span class="pltt-date-text"><?php echo esc_html( pltt_format_date( $entry['entry_date'] ?? $date, 'M j, Y' ) ); ?></span> <span class="pltt-time-separator">&middot;</span>
									<span class="pltt-time-text">
										<?php
										echo esc_html( pltt_format_time( $entry['start_time'] ) );
										if ( ! empty( $entry['end_time'] ) ) {
											echo ' &ndash; ' . esc_html( pltt_format_time( $entry['end_time'] ) );
										}
										?>
									</span>
									<div class="row-actions">
										<span class="edit"><a href="#edit_time" class="pltt-edit-time" role="button"><?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?></a> | </span>
										<span class="trash"><a href="#delete" class="pltt-delete-entry submitdelete" role="button"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></a></span>
									</div>
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
											step="60"
											name="entries[<?php echo esc_attr( $index ); ?>][start_time]"
											class="pltt-start-time"
											value="<?php echo esc_attr( substr( $entry['start_time'] ?? '', 0, 5 ) ); ?>"
										>
										<span class="pltt-time-separator">&ndash;</span>
										<input
											type="time"
											step="60"
											name="entries[<?php echo esc_attr( $index ); ?>][end_time]"
											class="pltt-end-time"
											value="<?php echo esc_attr( substr( $entry['end_time'] ?? '', 0, 5 ) ); ?>"
										>
									</div>
									<div class="submit inline-edit-save">
										<button type="button" class="pltt-save-time button button-primary save"><?php esc_html_e( 'Update', 'plain-language-time-tracker' ); ?></button>
										<button type="button" class="pltt-cancel-time button cancel"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
									</div>
								</div>
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
									title="<?php echo esc_attr( $entry['description'] ?? '' ); ?>"
								>
								<input type="hidden" name="entries[<?php echo esc_attr( $index ); ?>][raw_text]" value="<?php echo esc_attr( $entry['raw_text'] ?? '' ); ?>">
								<?php if ( $entry_id ) : ?>
									<input type="hidden" name="entries[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $entry_id ); ?>">
								<?php endif; ?>
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
							<td class="pltt-col-billable pltt-billable-indicator">
								<span class="pltt-billable-symbol <?php echo ! empty( $entry['billable'] ) ? 'is-billable' : 'not-billable'; ?>">$</span>
								<input
									type="checkbox"
									name="entries[<?php echo esc_attr( $index ); ?>][billable]"
									class="pltt-billable"
									value="1"
									<?php checked( ! empty( $entry['billable'] ) ); ?>
								>
							</td>
							<?php if ( $has_saved_entries ) : ?>
								<td class="pltt-col-billed">
									<input
										type="checkbox"
										name="entries[<?php echo esc_attr( $index ); ?>][billed]"
										class="pltt-billed"
										value="1"
										<?php checked( ! empty( $entry['billed'] ) ); ?>
										<?php disabled( empty( $entry['billable'] ) ); ?>
									>
								</td>
							<?php endif; ?>
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
<div id="pltt-client-modal" class="pltt-modal pltt-hidden">
	<div class="pltt-modal-content">
		<h3><?php esc_html_e( 'Add New Client', 'plain-language-time-tracker' ); ?></h3>
		<p>
			<label for="pltt-new-client-name"><?php esc_html_e( 'Client Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-new-client-name" class="regular-text">
		</p>
		<p>
			<label for="pltt-new-client-rate"><?php esc_html_e( 'Hourly Rate (Optional)', 'plain-language-time-tracker' ); ?></label>
			<input type="number" id="pltt-new-client-rate" step="0.01" min="0" placeholder="<?php echo esc_attr( PLTT_DEFAULT_HOURLY_RATE ); ?>" class="regular-text">
		</p>
		<p class="pltt-modal-actions">
			<button type="button" id="pltt-save-client" class="button button-primary"><?php esc_html_e( 'Create Client', 'plain-language-time-tracker' ); ?></button>
			<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</p>
	</div>
</div>

<!-- New Project Modal -->
<div id="pltt-project-modal" class="pltt-modal pltt-hidden">
	<div class="pltt-modal-content">
		<h3><?php esc_html_e( 'Add New Project', 'plain-language-time-tracker' ); ?></h3>
		<p>
			<label for="pltt-new-project-name"><?php esc_html_e( 'Project Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-new-project-name" class="regular-text">
			<input type="hidden" id="pltt-new-project-client-id">
		</p>
		<p>
			<label for="pltt-new-project-rate"><?php esc_html_e( 'Hourly Rate (Optional)', 'plain-language-time-tracker' ); ?></label>
			<input type="number" id="pltt-new-project-rate" step="0.01" min="0" placeholder="<?php esc_attr_e( 'Inherits from client', 'plain-language-time-tracker' ); ?>" class="regular-text">
		</p>
		<p class="pltt-modal-actions">
			<button type="button" id="pltt-save-project" class="button button-primary"><?php esc_html_e( 'Create Project', 'plain-language-time-tracker' ); ?></button>
			<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</p>
	</div>
</div>

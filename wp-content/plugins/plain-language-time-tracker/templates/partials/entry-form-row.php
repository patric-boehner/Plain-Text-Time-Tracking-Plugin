<?php
/**
 * Edit form for a single entry, rendered as a hidden table row beneath the
 * entry's compact display row on the review screen.
 *
 * @package PlainLanguageTimeTracker
 *
 * @var array  $form_entry         Pre-populated values from the entry being edited.
 * @var int    $colspan            Column count for the parent table.
 * @var array  $clients            All clients.
 * @var array  $projects_by_client Projects grouped by client (for the selected client only — others load via AJAX).
 * @var bool   $row_visible        Whether the form row should be visible on initial render.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$entry_id      = (int) ( $form_entry['id'] ?? 0 );
$selected_cid  = (int) ( $form_entry['client_id'] ?? 0 );
$selected_pid  = (int) ( $form_entry['project_id'] ?? 0 );
$internal_cid  = pltt_get_internal_client_id();
$is_billable   = ! empty( $form_entry['billable'] );
// Whether the per-entry billable control applies to the selected project (hidden
// for retainer/fixed-fee). Captured in the project-option loop below; defaults to
// shown when no project is selected yet.
$selected_flag_applies = true;
$row_classes   = array( 'pltt-entry-form-row' );
if ( ! $row_visible ) {
	$row_classes[] = 'pltt-hidden';
}
?>
<tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
	data-form-for-entry-id="<?php echo esc_attr( $entry_id ); ?>">
	<td colspan="<?php echo (int) $colspan; ?>">
		<div class="pltt-entry-form" data-entry-id="<?php echo esc_attr( $entry_id ); ?>">
			<div class="pltt-entry-form-header">
				<span class="dashicons dashicons-edit" aria-hidden="true"></span>
				<h3 class="pltt-entry-form-title"><?php esc_html_e( 'Editing entry', 'plain-language-time-tracker' ); ?></h3>
			</div>

			<div class="pltt-entry-form-fields">
				<div class="pltt-field pltt-field-description">
					<label for="pltt-form-description-edit-<?php echo esc_attr( $entry_id ); ?>">
						<?php esc_html_e( 'Description', 'plain-language-time-tracker' ); ?>
					</label>
					<input
						type="text"
						id="pltt-form-description-edit-<?php echo esc_attr( $entry_id ); ?>"
						class="pltt-form-description regular-text"
						value="<?php echo esc_attr( $form_entry['description'] ?? '' ); ?>"
					>
				</div>

				<div class="pltt-field-row pltt-field-row-time">
					<div class="pltt-field pltt-field-date">
						<label for="pltt-form-date-edit-<?php echo esc_attr( $entry_id ); ?>">
							<?php esc_html_e( 'Date', 'plain-language-time-tracker' ); ?>
						</label>
						<input
							type="date"
							id="pltt-form-date-edit-<?php echo esc_attr( $entry_id ); ?>"
							class="pltt-form-date"
							value="<?php echo esc_attr( $form_entry['entry_date'] ?? '' ); ?>"
						>
					</div>
					<div class="pltt-field pltt-field-start">
						<label for="pltt-form-start-edit-<?php echo esc_attr( $entry_id ); ?>">
							<?php esc_html_e( 'Start', 'plain-language-time-tracker' ); ?>
						</label>
						<input
							type="time"
							step="60"
							id="pltt-form-start-edit-<?php echo esc_attr( $entry_id ); ?>"
							class="pltt-form-start"
							value="<?php echo esc_attr( substr( $form_entry['start_time'] ?? '', 0, 5 ) ); ?>"
						>
					</div>
					<div class="pltt-field pltt-field-end">
						<label for="pltt-form-end-edit-<?php echo esc_attr( $entry_id ); ?>">
							<?php esc_html_e( 'End', 'plain-language-time-tracker' ); ?>
						</label>
						<input
							type="time"
							step="60"
							id="pltt-form-end-edit-<?php echo esc_attr( $entry_id ); ?>"
							class="pltt-form-end"
							value="<?php echo esc_attr( substr( $form_entry['end_time'] ?? '', 0, 5 ) ); ?>"
						>
					</div>
					<div class="pltt-field pltt-field-duration">
						<label for="pltt-form-duration-edit-<?php echo esc_attr( $entry_id ); ?>">
							<?php esc_html_e( 'Duration (min)', 'plain-language-time-tracker' ); ?>
						</label>
						<input
							type="number"
							min="0"
							step="1"
							id="pltt-form-duration-edit-<?php echo esc_attr( $entry_id ); ?>"
							class="pltt-form-duration"
							value="<?php echo esc_attr( $form_entry['duration_minutes'] ?? '' ); ?>"
						>
					</div>
				</div>

				<div class="pltt-field-row pltt-field-row-assignment">
					<div class="pltt-field pltt-field-client">
						<label for="pltt-form-client-edit-<?php echo esc_attr( $entry_id ); ?>">
							<?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?>
						</label>
						<select
							id="pltt-form-client-edit-<?php echo esc_attr( $entry_id ); ?>"
							class="pltt-form-client"
						>
							<option value=""><?php esc_html_e( 'Select client...', 'plain-language-time-tracker' ); ?></option>
							<?php foreach ( $clients as $client ) : ?>
								<option
									value="<?php echo esc_attr( $client->id ); ?>"
									<?php if ( (int) $client->id === $internal_cid ) : ?>data-is-internal="1"<?php endif; ?>
									<?php selected( $selected_cid, $client->id ); ?>
								><?php echo esc_html( $client->name ); ?></option>
							<?php endforeach; ?>
							<option value="new">+ <?php esc_html_e( 'Add new client...', 'plain-language-time-tracker' ); ?></option>
						</select>
					</div>
					<div class="pltt-field pltt-field-project">
						<label for="pltt-form-project-edit-<?php echo esc_attr( $entry_id ); ?>">
							<?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?>
						</label>
						<select
							id="pltt-form-project-edit-<?php echo esc_attr( $entry_id ); ?>"
							class="pltt-form-project"
							data-current-project-id="<?php echo esc_attr( $selected_pid ); ?>"
						>
							<option value=""><?php esc_html_e( 'Select project...', 'plain-language-time-tracker' ); ?></option>
							<?php if ( $selected_cid > 0 && ! empty( $projects_by_client[ $selected_cid ] ) ) : ?>
								<?php foreach ( $projects_by_client[ $selected_cid ] as $project ) : ?>
									<?php
									$is_archived = ( 'archived' === $project->status );
									if ( $is_archived && (int) $project->id !== $selected_pid ) {
										continue;
									}
									$label = $is_archived
										? $project->name . ' ' . __( '(Archived)', 'plain-language-time-tracker' )
										: $project->name;
									$opt_flag_applies = pltt_billable_flag_applies( $project );
									if ( (int) $project->id === $selected_pid ) {
										$selected_flag_applies = $opt_flag_applies;
									}
									?>
									<option
										value="<?php echo esc_attr( $project->id ); ?>"
										<?php selected( $selected_pid, $project->id ); ?>
										data-billability-default="<?php echo (int) $project->billability_default; ?>"
										data-billable-flag="<?php echo $opt_flag_applies ? '1' : '0'; ?>"
										<?php if ( $is_archived ) : ?>data-archived="1"<?php endif; ?>
									>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
							<option value="new">+ <?php esc_html_e( 'Add new project...', 'plain-language-time-tracker' ); ?></option>
						</select>
					</div>
					<div class="pltt-field pltt-field-tags">
						<label><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></label>
						<div class="pltt-tag-input-wrap">
							<input
								type="hidden"
								class="pltt-tags pltt-form-tags"
								value="<?php echo esc_attr( $form_entry['tags'] ?? '' ); ?>"
							>
						</div>
					</div>
					<div class="pltt-field pltt-field-billable<?php echo $selected_flag_applies ? '' : ' pltt-hidden'; ?>">
						<label><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></label>
						<button type="button"
							class="pltt-billable-symbol pltt-inline-toggle pltt-form-billable-btn <?php echo $is_billable ? 'is-billable' : 'not-billable'; ?>"
							aria-pressed="<?php echo $is_billable ? 'true' : 'false'; ?>"
							aria-label="<?php echo $is_billable ? esc_attr__( 'Billable — click to toggle', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable — click to toggle', 'plain-language-time-tracker' ); ?>"
							title="<?php echo $is_billable ? esc_attr__( 'Billable — click to toggle', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable — click to toggle', 'plain-language-time-tracker' ); ?>">$</button>
						<input
							type="checkbox"
							class="pltt-form-billable"
							value="1"
							hidden
							<?php checked( $is_billable ); ?>
						>
					</div>
				</div>
			</div>

			<div class="pltt-entry-form-error" role="alert" aria-live="polite"></div>

			<div class="pltt-entry-form-actions">
				<span class="pltt-form-status" aria-live="polite"></span>
				<button type="button" class="button pltt-form-cancel">
					<?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?>
				</button>
				<button type="button" class="button button-primary pltt-form-save">
					<?php esc_html_e( 'Save Entry', 'plain-language-time-tracker' ); ?>
				</button>
			</div>
		</div>
	</td>
</tr>

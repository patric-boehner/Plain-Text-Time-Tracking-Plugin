<?php
/**
 * Post-parse review state: column-input table + "Save All Entries" batch commit.
 *
 * Rendered by review.php when any entry on the current date is unverified
 * (i.e. just emerged from journal parsing and not yet confirmed).
 *
 * @package PlainLanguageTimeTracker
 *
 * @var string $date               Current date.
 * @var array  $entries            Parsed entries.
 * @var array  $clients            All clients.
 * @var array  $projects_by_client Projects grouped by client.
 * @var string $return_to          Optional safe-redirect URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Finalize status pill: nudge-don't-block. Green (clear) when nothing is unset
// and no guess is unconfirmed; untagged is an informational count only.
$rc = isset( $resolution_counts ) ? $resolution_counts : array(
	'needs_assigning' => 0,
	'to_confirm'      => 0,
	'untagged'        => 0,
);
$rc_clear   = 0 === (int) $rc['needs_assigning'] && 0 === (int) $rc['to_confirm'];
$pill_parts = array();
if ( $rc['needs_assigning'] > 0 ) {
	/* translators: %d: number of entries with no client/project assigned */
	$pill_parts[] = sprintf( _n( '%d needs assigning', '%d need assigning', $rc['needs_assigning'], 'plain-language-time-tracker' ), $rc['needs_assigning'] );
}
if ( $rc['to_confirm'] > 0 ) {
	/* translators: %d: number of guessed projects to confirm */
	$pill_parts[] = sprintf( _n( '%d to confirm', '%d to confirm', $rc['to_confirm'], 'plain-language-time-tracker' ), $rc['to_confirm'] );
}
if ( $rc['untagged'] > 0 ) {
	/* translators: %d: number of untagged entries */
	$pill_parts[] = sprintf( _n( '%d untagged', '%d untagged', $rc['untagged'], 'plain-language-time-tracker' ), $rc['untagged'] );
}
?>
<?php
// Standard WP notice styling for consistency with the plugin's other notices.
// The `inline` class keeps core's JS from relocating it up to the H1, so it
// stays here above the table; no `is-dismissible` (it reflects state, not a
// one-time message). Green when ready to save, amber while anything is pending.
?>
<div id="pltt-finalize-status" class="notice <?php echo $rc_clear ? 'notice-success' : 'notice-warning'; ?> inline"
	data-needs-assigning="<?php echo esc_attr( $rc['needs_assigning'] ); ?>"
	data-to-confirm="<?php echo esc_attr( $rc['to_confirm'] ); ?>"
	data-untagged="<?php echo esc_attr( $rc['untagged'] ); ?>">
	<p>
		<?php
		echo $rc_clear
			? esc_html__( 'All set — ready to save', 'plain-language-time-tracker' )
			: esc_html( implode( ' · ', $pill_parts ) );
		?>
	</p>
</div>
<form id="pltt-review-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="pltt_save_entries">
	<input type="hidden" name="date" value="<?php echo esc_attr( $date ); ?>">
	<input type="hidden" name="entries" id="pltt-entries-data" value="">
	<?php if ( $return_to ) : ?>
		<input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>">
	<?php endif; ?>
	<?php wp_nonce_field( 'pltt_save_entries', '_wpnonce', true, true ); ?>
	<?php
	// The first (status) column carries the AM/PM warning icon and the
	// needs-review dot. Hide it entirely when nothing in the table uses it.
	$show_status_col = false;
	foreach ( $entries as $status_entry ) {
		$status_state = $status_entry['resolution_state'] ?? '';
		if ( ! empty( $status_entry['warnings'] ) || 'guessed' === $status_state || 'unset' === $status_state ) {
			$show_status_col = true;
			break;
		}
	}
	?>
	<table class="pltt-review-table widefat<?php echo $show_status_col ? '' : ' pltt-no-status-col'; ?>">
		<thead>
			<tr>
				<th class="pltt-col-warning" scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Status', 'plain-language-time-tracker' ); ?></span></th>
				<th class="pltt-col-time"><?php esc_html_e( 'Date / Time', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-col-duration"><?php esc_html_e( 'Duration', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-col-description"><?php esc_html_e( 'Description', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-col-client"><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-col-project"><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-col-tags"><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-col-billable"><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $entries as $index => $entry ) : ?>
				<?php
				$entry_id           = $entry['id'] ?? 0;
				$predicted_client   = $entry['predicted_client_id'] ?? 0;
				$predicted_project  = $entry['predicted_project_id'] ?? 0;
				$client_confidence  = $entry['client_confidence'] ?? 0;
				$has_prediction     = $predicted_client > 0;
				$entry_warnings     = ! empty( $entry['warnings'] ) ? $entry['warnings'] : array();
				$has_warning        = ! empty( $entry_warnings );

				$row_classes = array( 'pltt-entry-row' );
				if ( $has_warning ) {
					$row_classes[] = 'pltt-entry-row-warning';
				}

				$warning_tooltip = '';
				if ( $has_warning ) {
					$reasons = array();
					if ( ! empty( $entry_warnings['long_duration'] ) ) {
						$reasons[] = __( 'Duration is over 4 hours — check whether AM/PM is correct.', 'plain-language-time-tracker' );
					}
					if ( ! empty( $entry_warnings['island'] ) ) {
						$reasons[] = __( 'AM/PM differs from the surrounding entries.', 'plain-language-time-tracker' );
					}
					if ( ! empty( $entry_warnings['backwards'] ) ) {
						$reasons[] = __( 'This entry was typed out of order — check whether AM/PM is correct.', 'plain-language-time-tracker' );
					}
					$warning_tooltip = implode( ' ', $reasons );
				}
				?>
				<tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>" data-entry-id="<?php echo esc_attr( $entry_id ); ?>" data-index="<?php echo esc_attr( $index ); ?>" data-original-project-id="<?php echo esc_attr( $predicted_project ); ?>" data-resolution-state="<?php echo esc_attr( $entry['resolution_state'] ?? '' ); ?>">
					<td class="pltt-warning-cell">
						<?php if ( $has_warning ) : ?>
							<span class="pltt-warning-indicator dashicons dashicons-warning" role="img" aria-label="<?php echo esc_attr( $warning_tooltip ); ?>" title="<?php echo esc_attr( $warning_tooltip ); ?>"></span>
						<?php elseif ( in_array( $entry['resolution_state'] ?? '', array( 'guessed', 'unset' ), true ) ) : ?>
							<?php
							$status_label = 'unset' === $entry['resolution_state']
								? __( 'Needs a client and project', 'plain-language-time-tracker' )
								: __( 'Project guessed from your most recent — confirm or change', 'plain-language-time-tracker' );
							?>
							<span class="pltt-status-dot pltt-status-<?php echo esc_attr( $entry['resolution_state'] ); ?>" role="img" aria-label="<?php echo esc_attr( $status_label ); ?>" title="<?php echo esc_attr( $status_label ); ?>"></span>
						<?php endif; ?>
					</td>
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
						<?php if ( $entry_id ) : ?>
							<input type="hidden" name="entries[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $entry_id ); ?>">
						<?php endif; ?>
					</td>
					<td>
						<select name="entries[<?php echo esc_attr( $index ); ?>][client_id]" class="pltt-client-select">
							<option value=""><?php esc_html_e( 'Select client...', 'plain-language-time-tracker' ); ?></option>
							<?php foreach ( $clients as $client ) : ?>
								<option
									value="<?php echo esc_attr( $client->id ); ?>"
									<?php if ( (int) $client->id === pltt_get_internal_client_id() ) : ?>data-is-internal="1"<?php endif; ?>
									<?php selected( $predicted_client, $client->id ); ?>
								><?php echo esc_html( $client->name ); ?></option>
							<?php endforeach; ?>
							<option value="new">+ <?php esc_html_e( 'Add new client...', 'plain-language-time-tracker' ); ?></option>
						</select>
						<?php if ( $has_prediction && $client_confidence > 0 ) : ?>
							<?php /* translators: %s: confidence percentage */ ?>
							<span class="pltt-confidence-indicator" title="<?php printf( esc_attr__( 'Auto-matched (confidence: %s%%)', 'plain-language-time-tracker' ), esc_attr( round( $client_confidence * 100 ) ) ); ?>">
								<?php esc_html_e( 'auto', 'plain-language-time-tracker' ); ?>
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
										data-billability-default="<?php echo (int) $project->billability_default; ?>"
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
						<?php
						// Pre-filled tags on a DRAFT entry are parser predictions — flag the
						// wrap so the picker renders them dashed ("records if left") until
						// edited. A verified entry's tags are already confirmed, never dashed
						// (matters on a reprocessed day, where finalized + draft rows mix).
						$has_predicted_tags = empty( $entry['verified'] )
							&& '' !== trim( (string) ( $entry['tags'] ?? '' ) );
						?>
						<div class="pltt-tag-input-wrap"<?php echo $has_predicted_tags ? ' data-predicted="1"' : ''; ?>>
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

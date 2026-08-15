<?php
/**
 * "Edit entry" modal — the one place a recorded entry is edited.
 *
 * Opened from a row's kebab menu (spec §6/§7) on the Overview entries table and
 * on Today. It replaces the per-entry expanding form that used to be rendered
 * hidden beneath every row: one element per page, populated on open by
 * PlttEntryEditor (assets/js/pltt-entry-editor.js) from the pltt_get_entry
 * action.
 *
 * The layout is the old inline form's, deliberately unchanged — same
 * .pltt-field* classes, same two rows of four, and .pltt-entry-form still
 * wrapping them so every selector in entry-editor.css keeps matching. What the
 * modal shell provides, the form no longer draws for itself: the <h3> is the
 * old .pltt-entry-form-header band, and .pltt-modal-actions is the old
 * .pltt-entry-form-actions footer.
 *
 * The client list is rendered here; the project list is not. Projects belong to
 * whichever client the entry has, so the select is filled on open by the
 * pltt_get_projects action — the same cascade that runs when you change client.
 *
 * Depends on entry-editor-modals.php being on the page too: the client and
 * project selects both offer "+ Add new …", which opens those modals.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pltt_edit_modal_clients     = PLTT_Clients::get_all();
$pltt_edit_modal_internal_id = pltt_get_internal_client_id();
?>
<div id="pltt-entry-edit-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-entry-edit-modal-title">
	<div class="pltt-modal-content pltt-modal-wide">
		<h3 id="pltt-entry-edit-modal-title"><?php esc_html_e( 'Editing entry', 'plain-language-time-tracker' ); ?></h3>

		<div class="pltt-entry-form" data-entry-id="">
			<div class="pltt-entry-form-fields">
				<div class="pltt-field pltt-field-description">
					<label for="pltt-form-description-edit">
						<?php esc_html_e( 'Description', 'plain-language-time-tracker' ); ?>
					</label>
					<input
						type="text"
						id="pltt-form-description-edit"
						class="pltt-form-description regular-text"
						value=""
					>
				</div>

				<div class="pltt-field-row pltt-field-row-time">
					<div class="pltt-field pltt-field-date">
						<label for="pltt-form-date-edit">
							<?php esc_html_e( 'Date', 'plain-language-time-tracker' ); ?>
						</label>
						<input type="date" id="pltt-form-date-edit" class="pltt-form-date" value="">
					</div>
					<div class="pltt-field pltt-field-start">
						<label for="pltt-form-start-edit">
							<?php esc_html_e( 'Start', 'plain-language-time-tracker' ); ?>
						</label>
						<input type="time" step="60" id="pltt-form-start-edit" class="pltt-form-start" value="">
					</div>
					<div class="pltt-field pltt-field-end">
						<label for="pltt-form-end-edit">
							<?php esc_html_e( 'End', 'plain-language-time-tracker' ); ?>
						</label>
						<input type="time" step="60" id="pltt-form-end-edit" class="pltt-form-end" value="">
					</div>
					<div class="pltt-field pltt-field-duration">
						<label for="pltt-form-duration-edit">
							<?php esc_html_e( 'Duration (min)', 'plain-language-time-tracker' ); ?>
						</label>
						<input type="number" min="0" step="1" id="pltt-form-duration-edit" class="pltt-form-duration" value="">
					</div>
				</div>

				<div class="pltt-field-row pltt-field-row-assignment">
					<div class="pltt-field pltt-field-client">
						<label for="pltt-form-client-edit">
							<?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?>
						</label>
						<select id="pltt-form-client-edit" class="pltt-form-client">
							<option value=""><?php esc_html_e( 'Select client...', 'plain-language-time-tracker' ); ?></option>
							<?php foreach ( $pltt_edit_modal_clients as $pltt_edit_modal_client ) : ?>
								<option
									value="<?php echo esc_attr( $pltt_edit_modal_client->id ); ?>"
									<?php if ( (int) $pltt_edit_modal_client->id === $pltt_edit_modal_internal_id ) : ?>data-is-internal="1"<?php endif; ?>
								><?php echo esc_html( $pltt_edit_modal_client->name ); ?></option>
							<?php endforeach; ?>
							<option value="new">+ <?php esc_html_e( 'Add new client...', 'plain-language-time-tracker' ); ?></option>
						</select>
					</div>
					<div class="pltt-field pltt-field-project">
						<label for="pltt-form-project-edit">
							<?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?>
						</label>
						<?php // Filled on open — see the file header. ?>
						<select id="pltt-form-project-edit" class="pltt-form-project" data-current-project-id="">
							<option value=""><?php esc_html_e( 'Select project...', 'plain-language-time-tracker' ); ?></option>
							<option value="new">+ <?php esc_html_e( 'Add new project...', 'plain-language-time-tracker' ); ?></option>
						</select>
					</div>
					<div class="pltt-field pltt-field-tags">
						<label><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></label>
						<div class="pltt-tag-input-wrap">
							<input type="hidden" class="pltt-tags pltt-form-tags" value="">
						</div>
					</div>
					<div class="pltt-field pltt-field-billable">
						<label><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></label>
						<button type="button"
							class="pltt-billable-symbol pltt-inline-toggle pltt-form-billable-btn not-billable"
							aria-pressed="false"
							aria-label="<?php esc_attr_e( 'Not billable — click to toggle', 'plain-language-time-tracker' ); ?>"
							title="<?php esc_attr_e( 'Not billable — click to toggle', 'plain-language-time-tracker' ); ?>">$</button>
						<input type="checkbox" class="pltt-form-billable" value="1" hidden>
					</div>
				</div>
			</div>

			<div class="pltt-entry-form-error" role="alert" aria-live="polite"></div>
		</div>

		<div class="pltt-modal-actions">
			<span class="pltt-form-status" aria-live="polite"></span>
			<div class="pltt-modal-actions-right">
				<button type="button" class="pltt-modal-close button pltt-form-cancel">
					<?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?>
				</button>
				<button type="button" class="button button-primary pltt-form-save">
					<?php esc_html_e( 'Save Entry', 'plain-language-time-tracker' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>

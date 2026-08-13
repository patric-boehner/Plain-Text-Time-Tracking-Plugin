<?php
/**
 * Add/Edit Project modal — the single project settings surface.
 *
 * Included by the Projects list (templates/projects.php) and by project detail
 * (templates/project-detail.php), so "edit a project" opens the same form from
 * either screen and there is only one place project fields are defined.
 *
 * The trigger is any element carrying the project's data-* attributes with a
 * `.pltt-edit-project` control inside or on it — a table row on the list, the
 * Settings button on detail. The handler reads `closest('[data-project-id]')`
 * rather than `closest('tr')` for exactly that reason: the data carrier is a
 * contract, not a table.
 *
 * Required data-* on the carrier: project-id, name, client-id, status, rate,
 * billability-default, recurring-period, billing-type, budget-hours, budget-fee,
 * entry-count, unbilled-minutes.
 *
 * Optional in scope:
 *   $clients  array  Client rows for the select. Loaded here when absent.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Self-sufficient: the list already has this in scope, detail does not.
if ( ! isset( $clients ) ) {
	$clients = PLTT_Clients::get_all();
}
?>

<!-- Add/Edit Project Modal -->
<div id="pltt-project-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-project-modal-title">
	<div class="pltt-modal-content">
		<h3 id="pltt-project-modal-title"><?php esc_html_e( 'Add Project', 'plain-language-time-tracker' ); ?></h3>
		<form id="pltt-project-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php // TRC-UI2: the only native submit is the update path; create is handled by AJAX (pltt_create_project). ?>
			<input type="hidden" name="action" id="pltt-project-form-action" value="pltt_update_project">
			<input type="hidden" id="pltt-edit-project-id" name="project_id" value="">
			<?php wp_nonce_field( 'pltt_update_project', '_wpnonce', true, true ); ?>
			<p>
				<label for="pltt-project-client"><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></label>
				<select id="pltt-project-client" name="client_id" class="widefat" required>
					<option value=""><?php esc_html_e( 'Select client...', 'plain-language-time-tracker' ); ?></option>
					<?php // Prefixed loop var: $client is the owning-client row on project detail. ?>
					<?php foreach ( $clients as $pltt_modal_client ) : ?>
						<option value="<?php echo esc_attr( $pltt_modal_client->id ); ?>"><?php echo esc_html( $pltt_modal_client->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="pltt-project-name"><?php esc_html_e( 'Project Name', 'plain-language-time-tracker' ); ?></label>
				<input type="text" id="pltt-project-name" name="name" class="regular-text widefat" required>
			</p>
			<?php include PLTT_PLUGIN_DIR . 'templates/partials/project-billing-fields.php'; ?>

			<div id="pltt-project-status-group" class="pltt-hidden">
				<hr class="pltt-form-separator">
				<p>
					<label for="pltt-project-status"><?php esc_html_e( 'Project Status', 'plain-language-time-tracker' ); ?></label>
					<select id="pltt-project-status" name="status" class="widefat">
						<option value="active"><?php esc_html_e( 'Active', 'plain-language-time-tracker' ); ?></option>
						<option value="archived"><?php esc_html_e( 'Archived', 'plain-language-time-tracker' ); ?></option>
					</select>
				</p>
			</div>

			<div class="pltt-modal-actions">
				<div class="pltt-modal-actions-left">
					<button type="button" id="pltt-delete-project-btn" class="button button-link-delete pltt-modal-delete-btn"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></button>
				</div>
				<div class="pltt-modal-actions-right">
					<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
					<button type="submit" id="pltt-save-project-btn" class="button button-primary"><?php esc_html_e( 'Save', 'plain-language-time-tracker' ); ?></button>
				</div>
			</div>
		</form>

		<!-- Archive/Restore form (hidden, submitted via JS) -->
		<form id="pltt-archive-project-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pltt-hidden">
			<input type="hidden" name="action" value="pltt_update_project">
			<input type="hidden" name="project_id" id="pltt-archive-project-id" value="">
			<input type="hidden" name="status" id="pltt-archive-project-status" value="">
			<?php wp_nonce_field( 'pltt_update_project', '_wpnonce', true, true ); ?>
		</form>

		<!-- Delete form (hidden, submitted via JS) -->
		<form id="pltt-delete-project-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pltt-hidden">
			<input type="hidden" name="action" value="pltt_delete_project">
			<input type="hidden" name="project_id" id="pltt-delete-project-id" value="">
			<?php wp_nonce_field( 'pltt_delete_project', '_wpnonce', true, true ); ?>
		</form>
	</div>
</div>

<script>
(function() {
	'use strict';

	function el(id) { return document.getElementById(id); }

	// Notice params are stripped from the URL by PLTT.cleanNoticeParams() in shared.js.

	// Add Project button — Projects list only; absent on project detail.
	var addProjectBtn = el('pltt-add-project-btn');
	if (addProjectBtn) {
		addProjectBtn.addEventListener('click', function() {
			if (this.disabled) return;
			el('pltt-project-modal-title').textContent = '<?php echo esc_js( __( 'Add Project', 'plain-language-time-tracker' ) ); ?>';
			el('pltt-edit-project-id').value = '';
			el('pltt-project-client').value = '';
			el('pltt-project-name').value = '';
			el('pltt-project-rate').value = '';
			el('pltt-project-budget-hours').value = '';
			el('pltt-project-budget-fee').value = '';
			el('pltt-project-status-group').classList.add('pltt-hidden');
			el('pltt-project-status').value = 'active';
			el('pltt-delete-project-btn').classList.remove('visible');
			PlttProjectType.select('hourly', true);
			PLTT.showModal('pltt-project-modal');
		});
	}

	// Edit Project triggers — the row action on the list, the Settings button on
	// project detail. Both read the project from the nearest [data-project-id]
	// carrier, so neither screen needs its own copy of this.
	document.querySelectorAll('.pltt-edit-project').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			var row = this.closest('[data-project-id]');
			if (!row) return;
			var isArchived = row.dataset.status === 'archived';
			var isDeletable = row.dataset.entryCount === '0';
			el('pltt-project-modal-title').textContent = '<?php echo esc_js( __( 'Edit Project', 'plain-language-time-tracker' ) ); ?>';
			el('pltt-edit-project-id').value = row.dataset.projectId;
			el('pltt-project-client').value = row.dataset.clientId;
			el('pltt-project-name').value = row.dataset.name;
			el('pltt-project-rate').value = row.dataset.rate || '';
			el('pltt-project-recurring-period').value = row.dataset.recurringPeriod || '';
			el('pltt-project-budget-hours').value = row.dataset.budgetHours || '';
			el('pltt-project-budget-fee').value = row.dataset.budgetFee || '';
			// Set the type (and its field UI) after the budget values are in place, so
			// fixed-fee can infer hours-vs-fee mode from the populated inputs.
			PlttProjectType.select(row.dataset.billingType || 'hourly', false);
			el('pltt-project-non-billable').checked = row.dataset.billabilityDefault === '0';

			el('pltt-project-status-group').classList.remove('pltt-hidden');
			el('pltt-project-status').value = isArchived ? 'archived' : 'active';

			var deleteBtn = el('pltt-delete-project-btn');
			if (isDeletable) {
				deleteBtn.classList.add('visible');
			} else {
				deleteBtn.classList.remove('visible');
			}

			el('pltt-project-form-action').value = 'pltt_update_project';
			PLTT.showModal('pltt-project-modal');
		});
	});

	// Confirm archive when the project still has unbilled billable time.
	// Returns true if the user confirms (or if no confirmation is needed).
	function confirmArchiveIfUnbilled(newStatus, unbilledMinutes) {
		if (newStatus !== 'archived' || !unbilledMinutes || unbilledMinutes <= 0) {
			return true;
		}
		var formatted = (window.PLTT && PLTT.formatDuration) ? PLTT.formatDuration(unbilledMinutes) : (unbilledMinutes + 'm');
		var msg = '<?php echo esc_js( __( 'This project has %s of billable time that hasn\'t been invoiced. Archive anyway?', 'plain-language-time-tracker' ) ); ?>'.replace('%s', formatted);
		return window.confirm(msg);
	}

	// Archive/Restore Project links (row-actions) — Projects list only.
	document.querySelectorAll('.pltt-archive-project').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			var row = this.closest('[data-project-id]');
			if (!row) return;
			var newStatus = this.dataset.newStatus;
			var unbilled = parseInt(row.dataset.unbilledMinutes || '0', 10);
			if (!confirmArchiveIfUnbilled(newStatus, unbilled)) {
				return;
			}
			el('pltt-archive-project-id').value = row.dataset.projectId;
			el('pltt-archive-project-status').value = newStatus;
			el('pltt-archive-project-form').submit();
		});
	});

	// Project form submission.
	el('pltt-project-form').addEventListener('submit', function(e) {
		const id = el('pltt-edit-project-id').value;

		if (!id) {
			e.preventDefault();
			const clientId = el('pltt-project-client').value;
			const name = el('pltt-project-name').value.trim();
			const hourlyRate = el('pltt-project-rate').value;
			const budgetHours = el('pltt-project-budget-hours').value;
			const budgetFee = el('pltt-project-budget-fee').value;
			const recurringPeriod = el('pltt-project-recurring-period').value;
			const nonBillable = el('pltt-project-non-billable').checked ? '1' : '0';

			PLTT.ajax('pltt_create_project', { client_id: clientId, name: name, hourly_rate: hourlyRate, budget_hours: budgetHours, budget_fee: budgetFee, recurring_period: recurringPeriod, non_billable: nonBillable }, function(response) {
				if (response.success) {
					window.location.href = window.location.pathname + '?page=pltt-projects&pltt_message=project_created';
				} else {
					alert(response.data || 'Error saving project.');
				}
			});
			return;
		}

		// Editing: confirm before archiving a project that still has uninvoiced billable time.
		var statusSel = el('pltt-project-status');
		var editRow = document.querySelector('[data-project-id="' + id + '"]');
		if (statusSel && statusSel.value === 'archived' && editRow && editRow.dataset.status !== 'archived') {
			var unbilled = parseInt(editRow.dataset.unbilledMinutes || '0', 10);
			if (!confirmArchiveIfUnbilled('archived', unbilled)) {
				e.preventDefault();
			}
		}
	});

	// Delete Project button (in modal).
	el('pltt-delete-project-btn').addEventListener('click', function() {
		if (!confirm('<?php echo esc_js( __( 'Delete this project? This cannot be undone.', 'plain-language-time-tracker' ) ); ?>')) {
			return;
		}
		var id = el('pltt-edit-project-id').value;
		el('pltt-delete-project-id').value = id;
		el('pltt-delete-project-form').submit();
	});
})();
</script>

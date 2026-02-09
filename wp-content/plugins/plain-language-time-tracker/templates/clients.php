<?php
/**
 * Clients & Projects management template.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clients  = PLTT_Clients::get_all();
$projects = PLTT_Projects::get_all();
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Clients & Projects', 'plain-language-time-tracker' ); ?></h1>
	</div>

	<div class="pltt-two-column">
		<!-- Clients Section -->
		<div class="pltt-column">
			<div class="pltt-section">
				<div class="pltt-section-header">
					<h2><?php esc_html_e( 'Clients', 'plain-language-time-tracker' ); ?></h2>
					<button type="button" id="pltt-add-client-btn" class="button button-primary">
						<?php esc_html_e( 'Add Client', 'plain-language-time-tracker' ); ?>
					</button>
				</div>

				<?php if ( empty( $clients ) ) : ?>
					<p class="description"><?php esc_html_e( 'No clients yet. Add your first client to get started.', 'plain-language-time-tracker' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'plain-language-time-tracker' ); ?></th>
								<th><?php esc_html_e( 'Rate', 'plain-language-time-tracker' ); ?></th>
								<th><?php esc_html_e( 'Projects', 'plain-language-time-tracker' ); ?></th>
								<th class="pltt-col-actions"></th>
							</tr>
						</thead>
						<tbody id="pltt-clients-list">
							<?php foreach ( $clients as $client ) : ?>
								<?php
								$client_projects = array_filter(
									$projects,
									function ( $p ) use ( $client ) {
										return $p->client_id === $client->id;
									}
								);
								?>
								<tr data-client-id="<?php echo esc_attr( $client->id ); ?>">
									<td>
										<strong><?php echo esc_html( $client->name ); ?></strong>
										<?php if ( $client->description ) : ?>
											<br><small class="description"><?php echo esc_html( $client->description ); ?></small>
										<?php endif; ?>
									</td>
									<td><?php echo null !== $client->hourly_rate ? esc_html( pltt_format_currency( $client->hourly_rate ) ) : '<span class="pltt-empty">—</span>'; ?></td>
									<td><?php echo count( $client_projects ); ?></td>
									<td class="pltt-col-actions">
										<button type="button" class="button button-small pltt-edit-client" data-id="<?php echo esc_attr( $client->id ); ?>" data-name="<?php echo esc_attr( $client->name ); ?>" data-description="<?php echo esc_attr( $client->description ); ?>" data-rate="<?php echo esc_attr( $client->hourly_rate ?? '' ); ?>">
											<?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<!-- Projects Section -->
		<div class="pltt-column">
			<div class="pltt-section">
				<div class="pltt-section-header">
					<h2><?php esc_html_e( 'Projects', 'plain-language-time-tracker' ); ?></h2>
					<button type="button" id="pltt-add-project-btn" class="button button-primary" <?php echo empty( $clients ) ? 'disabled' : ''; ?>>
						<?php esc_html_e( 'Add Project', 'plain-language-time-tracker' ); ?>
					</button>
				</div>

				<?php if ( empty( $projects ) ) : ?>
					<p class="description"><?php esc_html_e( 'No projects yet. Add clients first, then create projects.', 'plain-language-time-tracker' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'plain-language-time-tracker' ); ?></th>
								<th><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
								<th><?php esc_html_e( 'Rate', 'plain-language-time-tracker' ); ?></th>
								<th><?php esc_html_e( 'Status', 'plain-language-time-tracker' ); ?></th>
								<th class="pltt-col-actions"></th>
							</tr>
						</thead>
						<tbody id="pltt-projects-list">
							<?php foreach ( $projects as $project ) : ?>
								<?php
								$project_client = PLTT_Clients::get( $project->client_id );
								?>
								<tr data-project-id="<?php echo esc_attr( $project->id ); ?>">
									<td>
										<strong><?php echo esc_html( $project->name ); ?></strong>
									</td>
									<td><?php echo $project_client ? esc_html( $project_client->name ) : '—'; ?></td>
									<td><?php echo null !== $project->hourly_rate ? esc_html( pltt_format_currency( $project->hourly_rate ) ) : '<span class="pltt-empty">—</span>'; ?></td>
									<td>
										<?php if ( 'archived' === $project->status ) : ?>
											<span class="pltt-badge pltt-badge-warning"><?php esc_html_e( 'Archived', 'plain-language-time-tracker' ); ?></span>
										<?php else : ?>
											<span class="pltt-badge pltt-badge-success"><?php esc_html_e( 'Active', 'plain-language-time-tracker' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="pltt-col-actions">
										<button type="button" class="button button-small pltt-edit-project" data-id="<?php echo esc_attr( $project->id ); ?>" data-name="<?php echo esc_attr( $project->name ); ?>" data-client-id="<?php echo esc_attr( $project->client_id ); ?>" data-status="<?php echo esc_attr( $project->status ); ?>" data-rate="<?php echo esc_attr( $project->hourly_rate ?? '' ); ?>">
											<?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<!-- Add/Edit Client Modal -->
<div id="pltt-client-modal" class="pltt-modal" style="display: none;">
	<div class="pltt-modal-content">
		<h3 id="pltt-client-modal-title"><?php esc_html_e( 'Add Client', 'plain-language-time-tracker' ); ?></h3>
		<input type="hidden" id="pltt-edit-client-id" value="">
		<p>
			<label for="pltt-client-name"><?php esc_html_e( 'Client Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-client-name" class="regular-text" style="width: 100%;">
		</p>
		<p>
			<label for="pltt-client-description"><?php esc_html_e( 'Description (optional)', 'plain-language-time-tracker' ); ?></label>
			<textarea id="pltt-client-description" rows="3" style="width: 100%;"></textarea>
		</p>
		<p>
			<label for="pltt-client-rate"><?php esc_html_e( 'Hourly Rate (optional)', 'plain-language-time-tracker' ); ?></label>
			<input type="number" id="pltt-client-rate" step="0.01" min="0" style="width: 100%;" placeholder="0.00">
		</p>
		<p class="pltt-modal-actions">
			<button type="button" id="pltt-delete-client-btn" class="button button-link-delete" style="display: none; margin-right: auto;"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></button>
			<button type="button" id="pltt-save-client-btn" class="button button-primary"><?php esc_html_e( 'Save', 'plain-language-time-tracker' ); ?></button>
			<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</p>
	</div>
</div>

<!-- Add/Edit Project Modal -->
<div id="pltt-project-modal" class="pltt-modal" style="display: none;">
	<div class="pltt-modal-content">
		<h3 id="pltt-project-modal-title"><?php esc_html_e( 'Add Project', 'plain-language-time-tracker' ); ?></h3>
		<input type="hidden" id="pltt-edit-project-id" value="">
		<p>
			<label for="pltt-project-client"><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></label>
			<select id="pltt-project-client" style="width: 100%;">
				<option value=""><?php esc_html_e( 'Select client...', 'plain-language-time-tracker' ); ?></option>
				<?php foreach ( $clients as $client ) : ?>
					<option value="<?php echo esc_attr( $client->id ); ?>"><?php echo esc_html( $client->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="pltt-project-name"><?php esc_html_e( 'Project Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-project-name" class="regular-text" style="width: 100%;">
		</p>
		<p>
			<label for="pltt-project-rate"><?php esc_html_e( 'Hourly Rate (optional)', 'plain-language-time-tracker' ); ?></label>
			<input type="number" id="pltt-project-rate" step="0.01" min="0" style="width: 100%;" placeholder="0.00">
			<small class="description"><?php esc_html_e( 'Leave blank to use client rate.', 'plain-language-time-tracker' ); ?></small>
		</p>
		<p class="pltt-modal-actions">
			<button type="button" id="pltt-archive-project-btn" class="button button-link-delete" style="display: none; margin-right: auto;"><?php esc_html_e( 'Archive', 'plain-language-time-tracker' ); ?></button>
			<button type="button" id="pltt-save-project-btn" class="button button-primary"><?php esc_html_e( 'Save', 'plain-language-time-tracker' ); ?></button>
			<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</p>
	</div>
</div>

<style>
.pltt-two-column {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 30px;
}

.pltt-section {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 20px;
}

.pltt-section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 15px;
	padding-bottom: 10px;
	border-bottom: 1px solid #dcdcde;
}

.pltt-section-header h2 {
	margin: 0;
}

#pltt-client-modal .pltt-modal-actions,
#pltt-project-modal .pltt-modal-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

@media screen and (max-width: 1200px) {
	.pltt-two-column {
		grid-template-columns: 1fr;
	}
}
</style>

<script>
(function() {
	'use strict';

	// Add Client button.
	document.getElementById('pltt-add-client-btn').addEventListener('click', function() {
		document.getElementById('pltt-client-modal-title').textContent = '<?php echo esc_js( __( 'Add Client', 'plain-language-time-tracker' ) ); ?>';
		document.getElementById('pltt-edit-client-id').value = '';
		document.getElementById('pltt-client-name').value = '';
		document.getElementById('pltt-client-description').value = '';
		document.getElementById('pltt-client-rate').value = '';
		document.getElementById('pltt-delete-client-btn').style.display = 'none';
		PLTT.showModal('pltt-client-modal');
	});

	// Edit Client buttons.
	document.querySelectorAll('.pltt-edit-client').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.getElementById('pltt-client-modal-title').textContent = '<?php echo esc_js( __( 'Edit Client', 'plain-language-time-tracker' ) ); ?>';
			document.getElementById('pltt-edit-client-id').value = this.dataset.id;
			document.getElementById('pltt-client-name').value = this.dataset.name;
			document.getElementById('pltt-client-description').value = this.dataset.description || '';
			document.getElementById('pltt-client-rate').value = this.dataset.rate || '';
			document.getElementById('pltt-delete-client-btn').style.display = '';
			PLTT.showModal('pltt-client-modal');
		});
	});

	// Save Client.
	document.getElementById('pltt-save-client-btn').addEventListener('click', function() {
		const id = document.getElementById('pltt-edit-client-id').value;
		const name = document.getElementById('pltt-client-name').value.trim();
		const description = document.getElementById('pltt-client-description').value.trim();
		const hourlyRate = document.getElementById('pltt-client-rate').value;

		if (!name) {
			alert('Please enter a client name.');
			return;
		}

		const action = id ? 'pltt_update_client' : 'pltt_create_client';
		const data = { name: name, description: description, hourly_rate: hourlyRate };
		if (id) data.client_id = id;

		PLTT.ajax(action, data, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data || 'Error saving client.');
			}
		});
	});

	// Delete Client button (in modal).
	document.getElementById('pltt-delete-client-btn').addEventListener('click', function() {
		if (!confirm('Are you sure you want to delete this client? This will also delete all associated projects.')) {
			return;
		}
		var id = document.getElementById('pltt-edit-client-id').value;
		PLTT.ajax('pltt_delete_client', { client_id: id }, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data || 'Error deleting client.');
			}
		});
	});

	// Add Project button.
	var addProjectBtn = document.getElementById('pltt-add-project-btn');
	if (addProjectBtn) {
		addProjectBtn.addEventListener('click', function() {
			if (this.disabled) return;
			document.getElementById('pltt-project-modal-title').textContent = '<?php echo esc_js( __( 'Add Project', 'plain-language-time-tracker' ) ); ?>';
			document.getElementById('pltt-edit-project-id').value = '';
			document.getElementById('pltt-project-client').value = '';
			document.getElementById('pltt-project-name').value = '';
			document.getElementById('pltt-project-rate').value = '';
			document.getElementById('pltt-archive-project-btn').style.display = 'none';
			PLTT.showModal('pltt-project-modal');
		});
	}

	// Edit Project buttons.
	document.querySelectorAll('.pltt-edit-project').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var isArchived = this.dataset.status === 'archived';
			document.getElementById('pltt-project-modal-title').textContent = '<?php echo esc_js( __( 'Edit Project', 'plain-language-time-tracker' ) ); ?>';
			document.getElementById('pltt-edit-project-id').value = this.dataset.id;
			document.getElementById('pltt-project-client').value = this.dataset.clientId;
			document.getElementById('pltt-project-name').value = this.dataset.name;
			document.getElementById('pltt-project-rate').value = this.dataset.rate || '';

			var archiveBtn = document.getElementById('pltt-archive-project-btn');
			archiveBtn.style.display = '';
			archiveBtn.textContent = isArchived ? '<?php echo esc_js( __( 'Restore', 'plain-language-time-tracker' ) ); ?>' : '<?php echo esc_js( __( 'Archive', 'plain-language-time-tracker' ) ); ?>';
			archiveBtn.dataset.newStatus = isArchived ? 'active' : 'archived';

			PLTT.showModal('pltt-project-modal');
		});
	});

	// Save Project.
	document.getElementById('pltt-save-project-btn').addEventListener('click', function() {
		const id = document.getElementById('pltt-edit-project-id').value;
		const clientId = document.getElementById('pltt-project-client').value;
		const name = document.getElementById('pltt-project-name').value.trim();
		const hourlyRate = document.getElementById('pltt-project-rate').value;

		if (!clientId) {
			alert('Please select a client.');
			return;
		}
		if (!name) {
			alert('Please enter a project name.');
			return;
		}

		const action = id ? 'pltt_update_project' : 'pltt_create_project';
		const data = { client_id: clientId, name: name, hourly_rate: hourlyRate };
		if (id) data.project_id = id;

		PLTT.ajax(action, data, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data || 'Error saving project.');
			}
		});
	});

	// Archive/Restore Project button (in modal).
	document.getElementById('pltt-archive-project-btn').addEventListener('click', function() {
		var id = document.getElementById('pltt-edit-project-id').value;
		var newStatus = this.dataset.newStatus;
		PLTT.ajax('pltt_update_project', { project_id: id, status: newStatus }, function(response) {
			if (response.success) {
				location.reload();
			}
		});
	});
})();
</script>

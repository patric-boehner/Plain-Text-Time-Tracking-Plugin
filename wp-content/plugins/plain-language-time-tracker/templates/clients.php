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
	<h1><?php esc_html_e( 'Clients & Projects', 'plain-language-time-tracker' ); ?></h1>

	<?php
	// Display success/error messages.
	if ( isset( $_GET['pltt_message'] ) ) {
		$message_code = sanitize_text_field( wp_unslash( $_GET['pltt_message'] ) );
		$messages     = array(
			'client_created'  => __( 'Client created successfully.', 'plain-language-time-tracker' ),
			'client_updated'  => __( 'Client updated successfully.', 'plain-language-time-tracker' ),
			'client_deleted'  => __( 'Client deleted successfully.', 'plain-language-time-tracker' ),
			'project_created' => __( 'Project created successfully.', 'plain-language-time-tracker' ),
			'project_updated' => __( 'Project updated successfully.', 'plain-language-time-tracker' ),
		);
		if ( isset( $messages[ $message_code ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $message_code ] ) . '</p></div>';
		}
	}

	if ( isset( $_GET['pltt_error'] ) ) {
		$error_code = sanitize_text_field( wp_unslash( $_GET['pltt_error'] ) );

		// Check for custom error message (e.g., from validation errors).
		if ( isset( $_GET['pltt_error_message'] ) ) {
			$error_message = sanitize_text_field( wp_unslash( $_GET['pltt_error_message'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error_message ) . '</p></div>';
		} else {
			$errors = array(
				'invalid_client_id'      => __( 'Invalid client ID.', 'plain-language-time-tracker' ),
				'client_update_failed'   => __( 'Failed to update client.', 'plain-language-time-tracker' ),
				'client_delete_failed'   => __( 'Failed to delete client.', 'plain-language-time-tracker' ),
				'invalid_project_id'     => __( 'Invalid project ID.', 'plain-language-time-tracker' ),
				'project_update_failed'  => __( 'Failed to update project.', 'plain-language-time-tracker' ),
			);
			if ( isset( $errors[ $error_code ] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $error_code ] ) . '</p></div>';
			}
		}
	}
	?>

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
<div id="pltt-client-modal" class="pltt-modal pltt-hidden">
	<div class="pltt-modal-content">
		<h3 id="pltt-client-modal-title"><?php esc_html_e( 'Add Client', 'plain-language-time-tracker' ); ?></h3>
		<form id="pltt-client-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" id="pltt-client-form-action" value="pltt_create_client">
			<input type="hidden" id="pltt-edit-client-id" name="client_id" value="">
			<?php wp_nonce_field( 'pltt_update_client', '_wpnonce', true, true ); ?>
			<p>
				<label for="pltt-client-name"><?php esc_html_e( 'Client Name', 'plain-language-time-tracker' ); ?></label>
				<input type="text" id="pltt-client-name" name="name" class="regular-text widefat" required>
			</p>
			<p>
				<label for="pltt-client-description"><?php esc_html_e( 'Description (optional)', 'plain-language-time-tracker' ); ?></label>
				<textarea id="pltt-client-description" name="description" rows="3" class="widefat"></textarea>
			</p>
			<p>
				<label for="pltt-client-rate"><?php esc_html_e( 'Hourly Rate (optional)', 'plain-language-time-tracker' ); ?></label>
				<input type="number" id="pltt-client-rate" name="hourly_rate" step="0.01" min="0" class="widefat" placeholder="0.00">
			</p>
			<p class="pltt-modal-actions">
				<button type="button" id="pltt-delete-client-btn" class="button button-link-delete pltt-modal-delete-btn"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></button>
				<button type="submit" id="pltt-save-client-btn" class="button button-primary"><?php esc_html_e( 'Save', 'plain-language-time-tracker' ); ?></button>
				<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
			</p>
		</form>

		<!-- Delete form (hidden, submitted via JS) -->
		<form id="pltt-delete-client-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pltt-hidden">
			<input type="hidden" name="action" value="pltt_delete_client">
			<input type="hidden" name="client_id" id="pltt-delete-client-id" value="">
			<?php wp_nonce_field( 'pltt_delete_client', '_wpnonce', true, true ); ?>
		</form>
	</div>
</div>

<!-- Add/Edit Project Modal -->
<div id="pltt-project-modal" class="pltt-modal pltt-hidden">
	<div class="pltt-modal-content">
		<h3 id="pltt-project-modal-title"><?php esc_html_e( 'Add Project', 'plain-language-time-tracker' ); ?></h3>
		<form id="pltt-project-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" id="pltt-project-form-action" value="pltt_create_project">
			<input type="hidden" id="pltt-edit-project-id" name="project_id" value="">
			<?php wp_nonce_field( 'pltt_update_project', '_wpnonce', true, true ); ?>
			<p>
				<label for="pltt-project-client"><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></label>
				<select id="pltt-project-client" name="client_id" class="widefat" required>
					<option value=""><?php esc_html_e( 'Select client...', 'plain-language-time-tracker' ); ?></option>
					<?php foreach ( $clients as $client ) : ?>
						<option value="<?php echo esc_attr( $client->id ); ?>"><?php echo esc_html( $client->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="pltt-project-name"><?php esc_html_e( 'Project Name', 'plain-language-time-tracker' ); ?></label>
				<input type="text" id="pltt-project-name" name="name" class="regular-text widefat" required>
			</p>
			<p>
				<label for="pltt-project-rate"><?php esc_html_e( 'Hourly Rate (optional)', 'plain-language-time-tracker' ); ?></label>
				<input type="number" id="pltt-project-rate" name="hourly_rate" step="0.01" min="0" class="widefat" placeholder="0.00">
				<small class="description"><?php esc_html_e( 'Leave blank to use client rate.', 'plain-language-time-tracker' ); ?></small>
			</p>
			<p class="pltt-modal-actions">
				<button type="button" id="pltt-archive-project-btn" class="button button-link-delete pltt-modal-delete-btn"><?php esc_html_e( 'Archive', 'plain-language-time-tracker' ); ?></button>
				<button type="submit" id="pltt-save-project-btn" class="button button-primary"><?php esc_html_e( 'Save', 'plain-language-time-tracker' ); ?></button>
				<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
			</p>
		</form>

		<!-- Archive/Restore form (hidden, submitted via JS) -->
		<form id="pltt-archive-project-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pltt-hidden">
			<input type="hidden" name="action" value="pltt_update_project">
			<input type="hidden" name="project_id" id="pltt-archive-project-id" value="">
			<input type="hidden" name="status" id="pltt-archive-project-status" value="">
			<?php wp_nonce_field( 'pltt_update_project', '_wpnonce', true, true ); ?>
		</form>
	</div>
</div>

<script>
(function() {
	'use strict';

	// Clean up URL parameters after displaying notices (prevents notice from persisting on reload).
	if (window.location.search.includes('pltt_message') || window.location.search.includes('pltt_error')) {
		var url = new URL(window.location.href);
		url.searchParams.delete('pltt_message');
		url.searchParams.delete('pltt_error');
		url.searchParams.delete('pltt_error_message');
		window.history.replaceState({}, '', url.toString());
	}

	// Add Client button.
	document.getElementById('pltt-add-client-btn').addEventListener('click', function() {
		document.getElementById('pltt-client-modal-title').textContent = '<?php echo esc_js( __( 'Add Client', 'plain-language-time-tracker' ) ); ?>';
		document.getElementById('pltt-edit-client-id').value = '';
		document.getElementById('pltt-client-name').value = '';
		document.getElementById('pltt-client-description').value = '';
		document.getElementById('pltt-client-rate').value = '';
		document.getElementById('pltt-delete-client-btn').classList.remove('visible');
		document.getElementById('pltt-client-form-action').value = 'pltt_create_client';
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
			document.getElementById('pltt-delete-client-btn').classList.add('visible');
			document.getElementById('pltt-client-form-action').value = 'pltt_update_client';
			PLTT.showModal('pltt-client-modal');
		});
	});

	// Client form submission - use AJAX for create, form POST for update.
	document.getElementById('pltt-client-form').addEventListener('submit', function(e) {
		const id = document.getElementById('pltt-edit-client-id').value;

		// If creating (no ID), use AJAX (needed for review screen modal).
		if (!id) {
			e.preventDefault();
			const name = document.getElementById('pltt-client-name').value.trim();
			const description = document.getElementById('pltt-client-description').value.trim();
			const hourlyRate = document.getElementById('pltt-client-rate').value;

			PLTT.ajax('pltt_create_client', { name: name, description: description, hourly_rate: hourlyRate }, function(response) {
				if (response.success) {
					window.location.href = window.location.pathname + '?page=pltt-clients&pltt_message=client_created';
				} else {
					alert(response.data || 'Error saving client.');
				}
			});
		}
		// If updating (has ID), let form submit naturally to admin-post.php.
	});

	// Delete Client button (in modal).
	document.getElementById('pltt-delete-client-btn').addEventListener('click', function() {
		if (!confirm('Are you sure you want to delete this client? (Deletion will be blocked if the client has any projects.)')) {
			return;
		}
		var id = document.getElementById('pltt-edit-client-id').value;
		document.getElementById('pltt-delete-client-id').value = id;
		document.getElementById('pltt-delete-client-form').submit();
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
			document.getElementById('pltt-archive-project-btn').classList.remove('visible');
			document.getElementById('pltt-project-form-action').value = 'pltt_create_project';
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
			archiveBtn.classList.add('visible');
			archiveBtn.textContent = isArchived ? '<?php echo esc_js( __( 'Restore', 'plain-language-time-tracker' ) ); ?>' : '<?php echo esc_js( __( 'Archive', 'plain-language-time-tracker' ) ); ?>';
			archiveBtn.dataset.newStatus = isArchived ? 'active' : 'archived';

			document.getElementById('pltt-project-form-action').value = 'pltt_update_project';
			PLTT.showModal('pltt-project-modal');
		});
	});

	// Project form submission - use AJAX for create, form POST for update.
	document.getElementById('pltt-project-form').addEventListener('submit', function(e) {
		const id = document.getElementById('pltt-edit-project-id').value;

		// If creating (no ID), use AJAX (needed for review screen modal).
		if (!id) {
			e.preventDefault();
			const clientId = document.getElementById('pltt-project-client').value;
			const name = document.getElementById('pltt-project-name').value.trim();
			const hourlyRate = document.getElementById('pltt-project-rate').value;

			PLTT.ajax('pltt_create_project', { client_id: clientId, name: name, hourly_rate: hourlyRate }, function(response) {
				if (response.success) {
					window.location.href = window.location.pathname + '?page=pltt-clients&pltt_message=project_created';
				} else {
					alert(response.data || 'Error saving project.');
				}
			});
		}
		// If updating (has ID), let form submit naturally to admin-post.php.
	});

	// Archive/Restore Project button (in modal).
	document.getElementById('pltt-archive-project-btn').addEventListener('click', function() {
		var id = document.getElementById('pltt-edit-project-id').value;
		var newStatus = this.dataset.newStatus;
		document.getElementById('pltt-archive-project-id').value = id;
		document.getElementById('pltt-archive-project-status').value = newStatus;
		document.getElementById('pltt-archive-project-form').submit();
	});
})();
</script>

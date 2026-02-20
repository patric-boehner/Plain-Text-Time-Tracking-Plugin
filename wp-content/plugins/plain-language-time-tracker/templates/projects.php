<?php
/**
 * Projects management template.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clients  = PLTT_Clients::get_all();
$projects = PLTT_Projects::get_all();

// Build client lookup array to avoid N+1 queries.
$clients_by_id = array();
foreach ( $clients as $client ) {
	$clients_by_id[ $client->id ] = $client;
}

// Pre-fetch all project stats to avoid N+1 queries.
$project_stats_by_id = array();
if ( ! empty( $projects ) ) {
	foreach ( $projects as $project ) {
		$stats                                = PLTT_Entries::get_stats( array( 'project_id' => $project->id ) );
		$project_stats_by_id[ $project->id ] = $stats;
	}
}
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Projects', 'plain-language-time-tracker' ); ?></h1>
		<?php
		// Display success/error messages.
		if ( isset( $_GET['pltt_message'] ) ) {
			$message_code = sanitize_text_field( wp_unslash( $_GET['pltt_message'] ) );
			$messages     = array(
				'project_created' => __( 'Project created successfully.', 'plain-language-time-tracker' ),
				'project_updated' => __( 'Project updated successfully.', 'plain-language-time-tracker' ),
				'project_deleted' => __( 'Project deleted successfully.', 'plain-language-time-tracker' ),
			);
			if ( isset( $messages[ $message_code ] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $message_code ] ) . '</p></div>';
			}
		}

		if ( isset( $_GET['pltt_error'] ) ) {
			$error_code = sanitize_text_field( wp_unslash( $_GET['pltt_error'] ) );

			if ( isset( $_GET['pltt_error_message'] ) ) {
				$error_message = sanitize_text_field( wp_unslash( $_GET['pltt_error_message'] ) );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error_message ) . '</p></div>';
			} else {
				$errors = array(
					'invalid_project_id'    => __( 'Invalid project ID.', 'plain-language-time-tracker' ),
					'project_update_failed' => __( 'Failed to update project.', 'plain-language-time-tracker' ),
				);
				if ( isset( $errors[ $error_code ] ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $error_code ] ) . '</p></div>';
				}
			}
		}
		?>
		<div class="pltt-header-actions">
			<button type="button" id="pltt-add-project-btn" class="button button-primary" <?php echo empty( $clients ) ? 'disabled' : ''; ?>>
				<?php esc_html_e( 'Add Project', 'plain-language-time-tracker' ); ?>
			</button>
		</div>
	</div>

	<?php if ( empty( $clients ) ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: link to clients page */
					esc_html__( 'You need to create at least one client before adding projects. %s', 'plain-language-time-tracker' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=pltt-clients' ) ) . '">' . esc_html__( 'Go to Clients', 'plain-language-time-tracker' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php elseif ( empty( $projects ) ) : ?>
		<p class="description"><?php esc_html_e( 'No projects yet. Add your first project to get started.', 'plain-language-time-tracker' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Rate', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Hours', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Status', 'plain-language-time-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody id="pltt-projects-list">
				<?php foreach ( $projects as $project ) : ?>
					<?php
					// Use pre-fetched data to avoid N+1 queries.
					$project_client = $clients_by_id[ $project->client_id ] ?? null;
					$project_stats  = $project_stats_by_id[ $project->id ] ?? null;
					?>
					<?php $project_entry_count = isset( $project_stats->total_count ) ? (int) $project_stats->total_count : 0; ?>
				<tr data-project-id="<?php echo esc_attr( $project->id ); ?>" data-name="<?php echo esc_attr( $project->name ); ?>" data-client-id="<?php echo esc_attr( $project->client_id ); ?>" data-status="<?php echo esc_attr( $project->status ); ?>" data-rate="<?php echo esc_attr( $project->hourly_rate ?? '' ); ?>" data-entry-count="<?php echo esc_attr( $project_entry_count ); ?>">
						<td>
							<strong><?php echo esc_html( $project->name ); ?></strong>
							<div class="row-actions">
								<span class="edit"><a href="#edit" class="pltt-edit-project" role="button"><?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?></a> | </span>
								<?php if ( 'archived' === $project->status ) : ?>
									<span><a href="#restore" class="pltt-archive-project" data-new-status="active" role="button"><?php esc_html_e( 'Restore', 'plain-language-time-tracker' ); ?></a></span>
								<?php else : ?>
									<span class="trash"><a href="#archive" class="pltt-archive-project submitdelete" data-new-status="archived" role="button"><?php esc_html_e( 'Archive', 'plain-language-time-tracker' ); ?></a></span>
								<?php endif; ?>
							</div>
						</td>
						<td><?php echo $project_client ? esc_html( $project_client->name ) : '—'; ?></td>
						<td><?php echo null !== $project->hourly_rate ? esc_html( pltt_format_currency( $project->hourly_rate ) ) : '<span class="pltt-empty">—</span>'; ?></td>
						<td class="pltt-duration-cell"><?php echo esc_html( pltt_format_hours( $project_stats->total_minutes ?? 0 ) ); ?></td>
						<td><?php echo esc_html( pltt_format_currency( $project_stats->billable_amount ?? 0 ) ); ?></td>
						<td>
							<?php if ( 'archived' === $project->status ) : ?>
								<span class="pltt-badge pltt-badge-warning"><?php esc_html_e( 'Archived', 'plain-language-time-tracker' ); ?></span>
							<?php else : ?>
								<span class="pltt-badge pltt-badge-success"><?php esc_html_e( 'Active', 'plain-language-time-tracker' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<!-- Add/Edit Project Modal -->
<div id="pltt-project-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-project-modal-title">
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
				<button type="button" id="pltt-delete-project-btn" class="button button-link-delete pltt-modal-delete-btn"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></button>
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

	// Clean up URL parameters after displaying notices.
	if (window.location.search.includes('pltt_message') || window.location.search.includes('pltt_error')) {
		var url = new URL(window.location.href);
		url.searchParams.delete('pltt_message');
		url.searchParams.delete('pltt_error');
		url.searchParams.delete('pltt_error_message');
		window.history.replaceState({}, '', url.toString());
	}

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
			document.getElementById('pltt-delete-project-btn').classList.remove('visible');
			document.getElementById('pltt-project-form-action').value = 'pltt_create_project';
			PLTT.showModal('pltt-project-modal');
		});
	}

	// Edit Project links (row-actions).
	document.querySelectorAll('.pltt-edit-project').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			var row = this.closest('tr');
			var isArchived = row.dataset.status === 'archived';
			var isDeletable = row.dataset.entryCount === '0';
			document.getElementById('pltt-project-modal-title').textContent = '<?php echo esc_js( __( 'Edit Project', 'plain-language-time-tracker' ) ); ?>';
			document.getElementById('pltt-edit-project-id').value = row.dataset.projectId;
			document.getElementById('pltt-project-client').value = row.dataset.clientId;
			document.getElementById('pltt-project-name').value = row.dataset.name;
			document.getElementById('pltt-project-rate').value = row.dataset.rate || '';

			var archiveBtn = document.getElementById('pltt-archive-project-btn');
			archiveBtn.classList.add('visible');
			archiveBtn.textContent = isArchived ? '<?php echo esc_js( __( 'Restore', 'plain-language-time-tracker' ) ); ?>' : '<?php echo esc_js( __( 'Archive', 'plain-language-time-tracker' ) ); ?>';
			archiveBtn.dataset.newStatus = isArchived ? 'active' : 'archived';

			var deleteBtn = document.getElementById('pltt-delete-project-btn');
			if (isDeletable) {
				deleteBtn.classList.add('visible');
			} else {
				deleteBtn.classList.remove('visible');
			}

			document.getElementById('pltt-project-form-action').value = 'pltt_update_project';
			PLTT.showModal('pltt-project-modal');
		});
	});

	// Archive/Restore Project links (row-actions).
	document.querySelectorAll('.pltt-archive-project').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			var row = this.closest('tr');
			document.getElementById('pltt-archive-project-id').value = row.dataset.projectId;
			document.getElementById('pltt-archive-project-status').value = this.dataset.newStatus;
			document.getElementById('pltt-archive-project-form').submit();
		});
	});

	// Project form submission.
	document.getElementById('pltt-project-form').addEventListener('submit', function(e) {
		const id = document.getElementById('pltt-edit-project-id').value;

		if (!id) {
			e.preventDefault();
			const clientId = document.getElementById('pltt-project-client').value;
			const name = document.getElementById('pltt-project-name').value.trim();
			const hourlyRate = document.getElementById('pltt-project-rate').value;

			PLTT.ajax('pltt_create_project', { client_id: clientId, name: name, hourly_rate: hourlyRate }, function(response) {
				if (response.success) {
					window.location.href = window.location.pathname + '?page=pltt-projects&pltt_message=project_created';
				} else {
					alert(response.data || 'Error saving project.');
				}
			});
		}
	});

	// Archive/Restore Project button (in modal).
	document.getElementById('pltt-archive-project-btn').addEventListener('click', function() {
		var id = document.getElementById('pltt-edit-project-id').value;
		var newStatus = this.dataset.newStatus;
		document.getElementById('pltt-archive-project-id').value = id;
		document.getElementById('pltt-archive-project-status').value = newStatus;
		document.getElementById('pltt-archive-project-form').submit();
	});

	// Delete Project button (in modal).
	document.getElementById('pltt-delete-project-btn').addEventListener('click', function() {
		if (!confirm('<?php echo esc_js( __( 'Delete this project? This cannot be undone.', 'plain-language-time-tracker' ) ); ?>')) {
			return;
		}
		var id = document.getElementById('pltt-edit-project-id').value;
		document.getElementById('pltt-delete-project-id').value = id;
		document.getElementById('pltt-delete-project-form').submit();
	});
})();
</script>

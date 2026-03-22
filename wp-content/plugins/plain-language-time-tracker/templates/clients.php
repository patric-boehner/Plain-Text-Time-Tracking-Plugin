<?php
/**
 * Clients management template.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clients  = PLTT_Clients::get_all();
$projects = PLTT_Projects::get_all();

// Group projects by client_id to avoid repeated filtering in loop.
$projects_by_client = array();
foreach ( $projects as $project ) {
	if ( ! isset( $projects_by_client[ $project->client_id ] ) ) {
		$projects_by_client[ $project->client_id ] = array();
	}
	$projects_by_client[ $project->client_id ][] = $project;
}

// Pre-fetch entry counts per client to determine if delete is safe.
$entry_counts_by_client = array();
if ( ! empty( $clients ) ) {
	foreach ( $clients as $client ) {
		$stats = PLTT_Entries::get_stats( array( 'client_id' => $client->id ) );
		$entry_counts_by_client[ $client->id ] = isset( $stats->total_count ) ? (int) $stats->total_count : 0;
	}
}
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Clients', 'plain-language-time-tracker' ); ?></h1>
		<?php
		// Display success/error messages.
		if ( isset( $_GET['pltt_message'] ) ) {
			$message_code = sanitize_text_field( wp_unslash( $_GET['pltt_message'] ) );
			$messages     = array(
				'client_created' => __( 'Client created successfully.', 'plain-language-time-tracker' ),
				'client_updated' => __( 'Client updated successfully.', 'plain-language-time-tracker' ),
				'client_deleted' => __( 'Client deleted successfully.', 'plain-language-time-tracker' ),
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
					'invalid_client_id'    => __( 'Invalid client ID.', 'plain-language-time-tracker' ),
					'client_update_failed' => __( 'Failed to update client.', 'plain-language-time-tracker' ),
					'client_delete_failed' => __( 'Failed to delete client.', 'plain-language-time-tracker' ),
				);
				if ( isset( $errors[ $error_code ] ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $error_code ] ) . '</p></div>';
				}
			}
		}
		?>
		<div class="pltt-header-actions">
			<button type="button" id="pltt-add-client-btn" class="button button-primary">
				<?php esc_html_e( 'Add Client', 'plain-language-time-tracker' ); ?>
			</button>
		</div>
	</div>

	<?php if ( empty( $clients ) ) : ?>
		<p class="description"><?php esc_html_e( 'No clients yet. Add your first client to get started.', 'plain-language-time-tracker' ); ?></p>
	<?php else : ?>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Rate', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Projects', 'plain-language-time-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody id="pltt-clients-list">
				<?php foreach ( $clients as $client ) : ?>
					<?php
					// Use pre-grouped data to avoid repeated filtering.
					$client_projects    = $projects_by_client[ $client->id ] ?? array();
					$client_proj_count  = count( $client_projects );
					$client_entry_count = $entry_counts_by_client[ $client->id ] ?? 0;
					$client_deletable   = 0 === $client_proj_count && 0 === $client_entry_count;
					?>
					<tr data-client-id="<?php echo esc_attr( $client->id ); ?>" data-name="<?php echo esc_attr( $client->name ); ?>" data-description="<?php echo esc_attr( $client->description ); ?>" data-rate="<?php echo esc_attr( $client->hourly_rate ?? '' ); ?>" data-projects-count="<?php echo esc_attr( $client_proj_count ); ?>" data-entry-count="<?php echo esc_attr( $client_entry_count ); ?>">
						<td>
							<strong><?php echo esc_html( $client->name ); ?></strong>
							<?php if ( $client->description ) : ?>
								<br><small class="description"><?php echo esc_html( $client->description ); ?></small>
							<?php endif; ?>
							<div class="row-actions">
								<span class="edit"><a href="#edit" class="pltt-edit-client" role="button"><?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?></a><?php if ( $client_deletable ) : ?> | <?php endif; ?></span>
								<?php if ( $client_deletable ) : ?>
									<span class="trash"><a href="#delete" class="pltt-delete-client submitdelete" role="button"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></a></span>
								<?php endif; ?>
							</div>
						</td>
						<td><?php
						if ( null !== $client->hourly_rate ) {
							echo esc_html( pltt_format_currency( $client->hourly_rate ) );
						} elseif ( defined( 'PLTT_DEFAULT_HOURLY_RATE' ) ) {
							echo esc_html( pltt_format_currency( PLTT_DEFAULT_HOURLY_RATE ) . ' / ' . __( 'default', 'plain-language-time-tracker' ) );
						} else {
							echo '<span class="pltt-empty">—</span>';
						}
					?></td>
						<td><?php echo esc_html( $client_proj_count ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<!-- Add/Edit Client Modal -->
<div id="pltt-client-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-client-modal-title">
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
				<div class="pltt-input-adornment-wrap">
				<span class="pltt-adornment pltt-adornment-prefix">$</span>
				<input type="text" inputmode="decimal" id="pltt-client-rate" name="hourly_rate" class="widefat pltt-currency-input" placeholder="0.00">
			</div>
			</p>
			<div class="pltt-modal-actions">
				<div class="pltt-modal-actions-left">
					<button type="button" id="pltt-delete-client-btn" class="button button-link-delete pltt-modal-delete-btn"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></button>
				</div>
				<div class="pltt-modal-actions-right">
					<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
					<button type="submit" id="pltt-save-client-btn" class="button button-primary"><?php esc_html_e( 'Save', 'plain-language-time-tracker' ); ?></button>
				</div>
			</div>
		</form>

		<!-- Delete form (hidden, submitted via JS) -->
		<form id="pltt-delete-client-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pltt-hidden">
			<input type="hidden" name="action" value="pltt_delete_client">
			<input type="hidden" name="client_id" id="pltt-delete-client-id" value="">
			<?php wp_nonce_field( 'pltt_delete_client', '_wpnonce', true, true ); ?>
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

	// Edit Client links (row-actions).
	document.querySelectorAll('.pltt-edit-client').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			var row = this.closest('tr');
			var isDeletable = row.dataset.projectsCount === '0' && row.dataset.entryCount === '0';
			document.getElementById('pltt-client-modal-title').textContent = '<?php echo esc_js( __( 'Edit Client', 'plain-language-time-tracker' ) ); ?>';
			document.getElementById('pltt-edit-client-id').value = row.dataset.clientId;
			document.getElementById('pltt-client-name').value = row.dataset.name;
			document.getElementById('pltt-client-description').value = row.dataset.description || '';
			document.getElementById('pltt-client-rate').value = row.dataset.rate || '';
			var deleteBtn = document.getElementById('pltt-delete-client-btn');
			if (isDeletable) {
				deleteBtn.classList.add('visible');
			} else {
				deleteBtn.classList.remove('visible');
			}
			document.getElementById('pltt-client-form-action').value = 'pltt_update_client';
			PLTT.showModal('pltt-client-modal');
		});
	});

	// Delete Client links (row-actions).
	document.querySelectorAll('.pltt-delete-client').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			if (!confirm('<?php echo esc_js( __( 'Delete this client? This cannot be undone.', 'plain-language-time-tracker' ) ); ?>')) {
				return;
			}
			var row = this.closest('tr');
			document.getElementById('pltt-delete-client-id').value = row.dataset.clientId;
			document.getElementById('pltt-delete-client-form').submit();
		});
	});

	// Client form submission.
	document.getElementById('pltt-client-form').addEventListener('submit', function(e) {
		const id = document.getElementById('pltt-edit-client-id').value;

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
	});

	// Delete Client button (in modal).
	document.getElementById('pltt-delete-client-btn').addEventListener('click', function() {
		if (!confirm('<?php echo esc_js( __( 'Delete this client? This cannot be undone.', 'plain-language-time-tracker' ) ); ?>')) {
			return;
		}
		var id = document.getElementById('pltt-edit-client-id').value;
		document.getElementById('pltt-delete-client-id').value = id;
		document.getElementById('pltt-delete-client-form').submit();
	});
})();
</script>

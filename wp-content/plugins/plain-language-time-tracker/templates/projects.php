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

// Pre-fetch current-month and previous-month stats for recurring projects.
// Current month is used for the allocation bar; previous month is a fallback when the month just started.
$tz                        = wp_timezone();
$current_month_from        = current_time( 'Y-m-01' );
$current_month_to          = pltt_get_current_date();
$prev_month_dt             = ( new DateTimeImmutable( 'now', $tz ) )->modify( 'first day of last month' );
$prev_month_from           = $prev_month_dt->format( 'Y-m-01' );
$prev_month_to             = $prev_month_dt->format( 'Y-m-t' );
$project_month_stats_by_id = array();
$project_prev_month_by_id  = array();
if ( ! empty( $projects ) ) {
	foreach ( $projects as $project ) {
		if ( ! empty( $project->recurring_period ) && ! empty( $project->budget_hours ) ) {
			$project_month_stats_by_id[ $project->id ] = PLTT_Entries::get_stats( array(
				'project_id' => $project->id,
				'date_from'  => $current_month_from,
				'date_to'    => $current_month_to,
			) );
			$project_prev_month_by_id[ $project->id ] = PLTT_Entries::get_stats( array(
				'project_id' => $project->id,
				'date_from'  => $prev_month_from,
				'date_to'    => $prev_month_to,
			) );
		}
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
					<th><?php esc_html_e( 'Type', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Rate', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Hours', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Allocation / Budget', 'plain-language-time-tracker' ); ?></th>
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

					$billing_type = pltt_get_billing_type( $project );
					?>
					<?php $project_entry_count = isset( $project_stats->total_count ) ? (int) $project_stats->total_count : 0; ?>
				<tr data-project-id="<?php echo esc_attr( $project->id ); ?>" data-name="<?php echo esc_attr( $project->name ); ?>" data-client-id="<?php echo esc_attr( $project->client_id ); ?>" data-status="<?php echo esc_attr( $project->status ); ?>" data-rate="<?php echo esc_attr( $project->hourly_rate ?? '' ); ?>" data-billability-default="<?php echo esc_attr( $project->billability_default ?? '1' ); ?>" data-recurring-period="<?php echo esc_attr( $project->recurring_period ?? '' ); ?>" data-billing-type="<?php echo esc_attr( $billing_type ); ?>" data-budget-hours="<?php echo esc_attr( $project->budget_hours ?? '' ); ?>" data-entry-count="<?php echo esc_attr( $project_entry_count ); ?>">
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
						<td>
						<?php if ( 'none' === $billing_type ) : ?>
							<span class="pltt-badge"><?php esc_html_e( 'Internal', 'plain-language-time-tracker' ); ?></span>
						<?php elseif ( 'recurring' === $billing_type ) : ?>
							<span class="pltt-badge pltt-badge-info"><?php esc_html_e( 'Monthly', 'plain-language-time-tracker' ); ?></span>
						<?php elseif ( 'fixed' === $billing_type ) : ?>
							<span class="pltt-badge pltt-badge-purple"><?php esc_html_e( 'Fixed Budget', 'plain-language-time-tracker' ); ?></span>
						<?php else : ?>
							<span class="pltt-badge pltt-badge-success"><?php esc_html_e( 'Hourly', 'plain-language-time-tracker' ); ?></span>
						<?php endif; ?>
					</td>
						<td>
						<?php echo null !== $project->hourly_rate ? esc_html( pltt_format_currency( $project->hourly_rate ) ) : '<span class="pltt-empty">—</span>'; ?>
					</td>
						<?php
						// Hours column: always all-time total regardless of billing type.
						// Allocation bar: current month for recurring (vs monthly allocation), all-time for fixed (vs estimate).
						$total_mins = isset( $project_stats->total_minutes ) ? (float) $project_stats->total_minutes : 0;
						$has_alloc  = ( 'recurring' === $billing_type || 'fixed' === $billing_type ) && ! empty( $project->budget_hours );
						if ( $has_alloc ) {
							$budget_hours = (float) $project->budget_hours;
							if ( 'recurring' === $billing_type ) {
								$month_stats = $project_month_stats_by_id[ $project->id ] ?? null;
								$alloc_mins  = isset( $month_stats->total_minutes ) ? (float) $month_stats->total_minutes : 0;
								// If no current-month entries yet, fall back to previous month so the bar isn't empty.
								if ( 0.0 === $alloc_mins ) {
									$prev_stats = $project_prev_month_by_id[ $project->id ] ?? null;
									$alloc_mins = isset( $prev_stats->total_minutes ) ? (float) $prev_stats->total_minutes : 0;
								}
							} else {
								$alloc_mins = $total_mins;
							}
						}
						?>
						<td class="pltt-duration-cell">
							<?php echo esc_html( pltt_format_hours( $total_mins ) ); ?>
					</td>
						<td class="pltt-duration-cell">
						<?php if ( $has_alloc ) :
							pltt_render_allocation_bar( $alloc_mins, $budget_hours, $billing_type );
						else : ?>
							<span class="pltt-empty">—</span>
						<?php endif; ?>
					</td>
						<td><?php echo (float) ( $project_stats->billable_amount ?? 0 ) > 0 ? esc_html( pltt_format_currency( $project_stats->billable_amount ) ) : '<span class="pltt-empty">—</span>'; ?></td>
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
				<label for="pltt-project-billing-type"><?php esc_html_e( 'Billing Type', 'plain-language-time-tracker' ); ?></label>
				<select id="pltt-project-billing-type" class="widefat">
					<option value="hourly"><?php esc_html_e( 'Hourly', 'plain-language-time-tracker' ); ?></option>
					<option value="fixed"><?php esc_html_e( 'Fixed Budget', 'plain-language-time-tracker' ); ?></option>
					<option value="recurring"><?php esc_html_e( 'Recurring', 'plain-language-time-tracker' ); ?></option>
					<option value="none"><?php esc_html_e( 'None / Internal', 'plain-language-time-tracker' ); ?></option>
				</select>
			</p>

			<!-- Type-specific settings box: shown for Fixed Fee and Recurring only -->
			<div id="pltt-project-billing-settings" class="pltt-billing-settings pltt-hidden">
				<p class="pltt-billing-settings-label" id="pltt-billing-settings-title"></p>
				<div id="pltt-billing-settings-fields">
					<div id="pltt-project-recurring-group" class="pltt-hidden">
						<label for="pltt-project-recurring-period"><?php esc_html_e( 'Recurring Period', 'plain-language-time-tracker' ); ?></label>
						<select id="pltt-project-recurring-period" name="recurring_period" class="widefat">
							<option value=""><?php esc_html_e( '— None', 'plain-language-time-tracker' ); ?></option>
							<option value="monthly"><?php esc_html_e( 'Monthly', 'plain-language-time-tracker' ); ?></option>
						</select>
					</div>
					<div id="pltt-project-budget-group">
						<label id="pltt-project-budget-label" for="pltt-project-budget-hours"><?php esc_html_e( 'Hour Budget', 'plain-language-time-tracker' ); ?> <span class="pltt-optional"><?php esc_html_e( '(optional)', 'plain-language-time-tracker' ); ?></span></label>
						<input type="number" id="pltt-project-budget-hours" name="budget_hours" step="0.5" min="0" class="widefat" placeholder="0">
					</div>
				</div>
				<p class="description" id="pltt-billing-settings-desc"></p>
			</div>

			<hr class="pltt-form-separator">

			<p id="pltt-project-rate-group">
				<label for="pltt-project-rate"><?php esc_html_e( 'Hourly Rate', 'plain-language-time-tracker' ); ?> <span class="pltt-optional"><?php esc_html_e( '(optional)', 'plain-language-time-tracker' ); ?></span></label>
				<input type="number" id="pltt-project-rate" name="hourly_rate" step="0.01" min="0" class="widefat" placeholder="0.00">
				<small class="description" id="pltt-rate-description"><?php esc_html_e( 'Leave blank to use client rate.', 'plain-language-time-tracker' ); ?></small>
			</p>
			<p id="pltt-project-nonbillable-group">
				<label>
					<input type="checkbox" id="pltt-project-non-billable" name="non_billable" value="1">
					<?php esc_html_e( 'Non-billable project', 'plain-language-time-tracker' ); ?>
				</label>
				<small class="description" id="pltt-nonbillable-description"><?php esc_html_e( 'Entries default to non-billable. Can be overridden per entry.', 'plain-language-time-tracker' ); ?></small>
			</p>

			<hr class="pltt-form-separator">

			<div class="pltt-modal-actions">
				<div class="pltt-modal-actions-left">
					<button type="button" id="pltt-archive-project-btn" class="button button-link-delete pltt-modal-delete-btn"><?php esc_html_e( 'Archive', 'plain-language-time-tracker' ); ?></button>
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

	var BILLING_DESCRIPTIONS = {
		rate: {
			hourly:    '<?php echo esc_js( __( 'Leave blank to use client rate.', 'plain-language-time-tracker' ) ); ?>',
			fixed:     '<?php echo esc_js( __( 'Used to calculate implied effective rate. Leave blank to use client rate.', 'plain-language-time-tracker' ) ); ?>',
			recurring: '<?php echo esc_js( __( 'Used for overage billing. Leave blank to use client rate.', 'plain-language-time-tracker' ) ); ?>',
			none:      '<?php echo esc_js( __( 'Not applicable for internal projects.', 'plain-language-time-tracker' ) ); ?>'
		},
		nonbillable: {
			hourly:    '<?php echo esc_js( __( 'Entries default to non-billable. Can be overridden per entry.', 'plain-language-time-tracker' ) ); ?>',
			fixed:     '<?php echo esc_js( __( 'Entries default to non-billable. Can be overridden per entry.', 'plain-language-time-tracker' ) ); ?>',
			recurring: '<?php echo esc_js( __( 'Checked by default for recurring projects. Can be overridden.', 'plain-language-time-tracker' ) ); ?>',
			none:      '<?php echo esc_js( __( 'Always non-billable for internal projects.', 'plain-language-time-tracker' ) ); ?>'
		}
	};

	function applyBillingTypeUI(type, setDefaults) {
		var rateField        = el('pltt-project-rate');
		var rateGroup        = el('pltt-project-rate-group');
		var settingsBox      = el('pltt-project-billing-settings');
		var settingsTitle    = el('pltt-billing-settings-title');
		var settingsFields   = el('pltt-billing-settings-fields');
		var settingsDesc     = el('pltt-billing-settings-desc');
		var recurringGroup   = el('pltt-project-recurring-group');
		var recurringSelect  = el('pltt-project-recurring-period');
		var budgetLabel      = el('pltt-project-budget-label');
		var nonBillableGroup = el('pltt-project-nonbillable-group');
		var nonBillable      = el('pltt-project-non-billable');

		// Reset disabled/greyed states first.
		rateField.disabled = false;
		rateGroup.classList.remove('pltt-field-disabled');
		nonBillableGroup.classList.remove('pltt-field-disabled');

		// Update dynamic descriptions.
		el('pltt-rate-description').textContent = BILLING_DESCRIPTIONS.rate[type];
		el('pltt-nonbillable-description').textContent = BILLING_DESCRIPTIONS.nonbillable[type];

		if (type === 'hourly') {
			settingsBox.classList.add('pltt-hidden');
			if (setDefaults) { nonBillable.checked = false; recurringSelect.value = ''; }

		} else if (type === 'fixed') {
			settingsBox.classList.remove('pltt-hidden');
			settingsTitle.textContent = '<?php echo esc_js( __( 'FIXED BUDGET SETTINGS', 'plain-language-time-tracker' ) ); ?>';
			settingsFields.classList.remove('pltt-grid');
			recurringGroup.classList.add('pltt-hidden');
			budgetLabel.firstChild.textContent = '<?php echo esc_js( __( 'Hour Budget', 'plain-language-time-tracker' ) ); ?> ';
			settingsDesc.textContent = '<?php echo esc_js( __( 'Hours in the fixed budget. Entries remain non-billable unless manually marked otherwise.', 'plain-language-time-tracker' ) ); ?>';
			if (setDefaults) { nonBillable.checked = false; recurringSelect.value = ''; }

		} else if (type === 'recurring') {
			settingsBox.classList.remove('pltt-hidden');
			settingsTitle.textContent = '<?php echo esc_js( __( 'RECURRING SETTINGS', 'plain-language-time-tracker' ) ); ?>';
			settingsFields.classList.add('pltt-grid');
			recurringGroup.classList.remove('pltt-hidden');
			budgetLabel.firstChild.textContent = '<?php echo esc_js( __( 'Hour Allocation', 'plain-language-time-tracker' ) ); ?> ';
			settingsDesc.textContent = '<?php echo esc_js( __( 'Hours included per period. Entries over the allocation remain non-billable unless manually marked otherwise.', 'plain-language-time-tracker' ) ); ?>';
			if (setDefaults) { nonBillable.checked = true; recurringSelect.value = 'monthly'; }

		} else if (type === 'none') {
			settingsBox.classList.add('pltt-hidden');
			rateField.disabled = true;
			rateGroup.classList.add('pltt-field-disabled');
			nonBillable.checked = true;
			nonBillableGroup.classList.add('pltt-field-disabled'); // visual lock only; not HTML disabled so it still submits
			if (setDefaults) { recurringSelect.value = ''; el('pltt-project-budget-hours').value = ''; }
		}
	}

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
			document.getElementById('pltt-project-budget-hours').value = '';
			document.getElementById('pltt-archive-project-btn').classList.remove('visible');
			document.getElementById('pltt-delete-project-btn').classList.remove('visible');
			document.getElementById('pltt-project-form-action').value = 'pltt_create_project';
			document.getElementById('pltt-project-billing-type').value = 'hourly';
			applyBillingTypeUI('hourly', true);
			PLTT.showModal('pltt-project-modal');
		});
	}

	// Billing Type dropdown change.
	var billingTypeSelect = document.getElementById('pltt-project-billing-type');
	if (billingTypeSelect) {
		billingTypeSelect.addEventListener('change', function() {
			applyBillingTypeUI(this.value, true);
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
			document.getElementById('pltt-project-recurring-period').value = row.dataset.recurringPeriod || '';
			document.getElementById('pltt-project-billing-type').value = row.dataset.billingType || 'hourly';
			document.getElementById('pltt-project-budget-hours').value = row.dataset.budgetHours || '';
			applyBillingTypeUI(row.dataset.billingType || 'hourly', false);
			document.getElementById('pltt-project-non-billable').checked = row.dataset.billabilityDefault === '0';

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
			const budgetHours = document.getElementById('pltt-project-budget-hours').value;
			const recurringPeriod = document.getElementById('pltt-project-recurring-period').value;
			const nonBillable = document.getElementById('pltt-project-non-billable').checked ? '1' : '0';

			PLTT.ajax('pltt_create_project', { client_id: clientId, name: name, hourly_rate: hourlyRate, budget_hours: budgetHours, recurring_period: recurringPeriod, non_billable: nonBillable }, function(response) {
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

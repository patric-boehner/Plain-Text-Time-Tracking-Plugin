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

// OPT-N2: bulk-load all per-project stats in one query instead of N×get_stats().
$project_stats_by_id = array();
if ( ! empty( $projects ) ) {
	$project_ids         = wp_list_pluck( $projects, 'id' );
	$project_stats_by_id = PLTT_Entries::get_stats_grouped_by( 'project_id', array( 'project_ids' => $project_ids ) );
}
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Projects', 'plain-language-time-tracker' ); ?></h1>
		<?php
		// OPT-DUP1: display success/error notices via shared helper.
		pltt_render_admin_notices(
			array(
				'project_created' => __( 'Project created successfully.', 'plain-language-time-tracker' ),
				'project_updated' => __( 'Project updated successfully.', 'plain-language-time-tracker' ),
				'project_deleted' => __( 'Project deleted successfully.', 'plain-language-time-tracker' ),
			),
			array(
				'invalid_project_id'       => __( 'Invalid project ID.', 'plain-language-time-tracker' ),
				'project_update_failed'    => __( 'Failed to update project.', 'plain-language-time-tracker' ),
				'invalid_status'           => __( 'Invalid project status.', 'plain-language-time-tracker' ),
				'invalid_recurring_period' => __( 'Invalid recurring period.', 'plain-language-time-tracker' ),
				'invalid_rate'             => __( 'Hourly rate must be between 0 and 10,000.', 'plain-language-time-tracker' ),
				'missing_client'           => __( 'Please choose a client for this project.', 'plain-language-time-tracker' ),
				'missing_name'             => __( 'Please enter a project name.', 'plain-language-time-tracker' ),
			)
		);
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
		<?php
		$group_mode = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : 'status';
		if ( ! in_array( $group_mode, array( 'status', 'type' ), true ) ) {
			$group_mode = 'status';
		}

		$project_groups = array();

		if ( 'type' === $group_mode ) {
			// Group all projects by billing type (archived blend into their type).
			$by_type = array();
			foreach ( $projects as $project ) {
				$by_type[ pltt_get_billing_type( $project ) ][] = $project;
			}
			$type_labels = array(
				'hourly'    => __( 'Hourly', 'plain-language-time-tracker' ),
				'recurring' => __( 'Monthly', 'plain-language-time-tracker' ),
				'fixed'     => __( 'Fixed Budget', 'plain-language-time-tracker' ),
				'none'      => __( 'Internal', 'plain-language-time-tracker' ),
			);
			foreach ( $type_labels as $type_key => $type_label ) {
				if ( empty( $by_type[ $type_key ] ) ) {
					continue;
				}
				$project_groups[] = array(
					'label'    => $type_label,
					'projects' => $by_type[ $type_key ],
					'tbody_id' => '',
				);
			}
		} else {
			// 'status' — default grouping: Active + Archived as separate buckets.
			$active_projects   = array_filter( $projects, fn( $p ) => 'archived' !== $p->status );
			$archived_projects = array_filter( $projects, fn( $p ) => 'archived' === $p->status );

			$project_groups[] = array(
				'label'    => __( 'Active', 'plain-language-time-tracker' ),
				'projects' => $active_projects,
				'tbody_id' => 'pltt-projects-list',
			);
			if ( ! empty( $archived_projects ) ) {
				$project_groups[] = array(
					'label'    => __( 'Archived', 'plain-language-time-tracker' ),
					'projects' => $archived_projects,
					'tbody_id' => '',
				);
			}
		}
		?>
		<form method="get" action="" class="pltt-projects-toolbar">
			<input type="hidden" name="page" value="pltt-projects">
			<label for="pltt-group-by"><?php esc_html_e( 'Group by:', 'plain-language-time-tracker' ); ?></label>
			<select name="group" id="pltt-group-by" onchange="this.form.submit()">
				<option value="status" <?php selected( $group_mode, 'status' ); ?>><?php esc_html_e( 'Status', 'plain-language-time-tracker' ); ?></option>
				<option value="type" <?php selected( $group_mode, 'type' ); ?>><?php esc_html_e( 'Type', 'plain-language-time-tracker' ); ?></option>
			</select>
		</form>
		<?php foreach ( $project_groups as $group ) : ?>
			<div class="pltt-project-group">
				<div class="pltt-project-group-header">
					<span class="pltt-project-group-title"><?php echo esc_html( $group['label'] ); ?></span>
				</div>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Type', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Rate', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Budget', 'plain-language-time-tracker' ); ?></th>
							<th class="pltt-col-billable"><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody<?php echo $group['tbody_id'] ? ' id="' . esc_attr( $group['tbody_id'] ) . '"' : ''; ?>>
						<?php foreach ( $group['projects'] as $project ) : ?>
							<?php
							// Use pre-fetched data to avoid N+1 queries.
							$project_client = $clients_by_id[ $project->client_id ] ?? null;
							$project_stats  = $project_stats_by_id[ $project->id ] ?? null;

							$billing_type = pltt_get_billing_type( $project );
							?>
							<?php
						$project_entry_count        = isset( $project_stats->total_count ) ? (int) $project_stats->total_count : 0;
						$project_unbilled_minutes   = isset( $project_stats->unbilled_billable_minutes ) ? (int) $project_stats->unbilled_billable_minutes : 0;
						$row_class                  = ( 'archived' === $project->status && 'status' !== $group_mode ) ? 'pltt-row-archived' : '';

						$view_url = PLTT_Project_Detail::get_url( $project->id );
						?>
						<tr<?php echo $row_class ? ' class="' . esc_attr( $row_class ) . '"' : ''; ?> data-project-id="<?php echo esc_attr( $project->id ); ?>" data-unbilled-minutes="<?php echo esc_attr( $project_unbilled_minutes ); ?>" data-name="<?php echo esc_attr( $project->name ); ?>" data-client-id="<?php echo esc_attr( $project->client_id ); ?>" data-status="<?php echo esc_attr( $project->status ); ?>" data-rate="<?php echo esc_attr( $project->hourly_rate ?? '' ); ?>" data-billability-default="<?php echo esc_attr( $project->billability_default ?? '1' ); ?>" data-recurring-period="<?php echo esc_attr( $project->recurring_period ?? '' ); ?>" data-billing-type="<?php echo esc_attr( $billing_type ); ?>" data-budget-hours="<?php echo esc_attr( $project->budget_hours ?? '' ); ?>" data-budget-fee="<?php echo esc_attr( $project->budget_fee ?? '' ); ?>" data-entry-count="<?php echo esc_attr( $project_entry_count ); ?>">
							<td>
								<strong><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $project->name ); ?></a></strong>
								<?php if ( 'archived' === $project->status && 'status' !== $group_mode ) : ?>
									<span class="pltt-badge pltt-badge-archived"><?php esc_html_e( 'Archived', 'plain-language-time-tracker' ); ?></span>
								<?php endif; ?>
								<div class="row-actions">
									<span class="edit"><a href="#edit" class="pltt-edit-project" role="button"><?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?></a> | </span>
									<?php if ( 'archived' === $project->status ) : ?>
										<span><a href="#restore" class="pltt-archive-project" data-new-status="active" role="button"><?php esc_html_e( 'Restore', 'plain-language-time-tracker' ); ?></a> | </span>
									<?php else : ?>
										<span class="trash"><a href="#archive" class="pltt-archive-project submitdelete" data-new-status="archived" role="button"><?php esc_html_e( 'Archive', 'plain-language-time-tracker' ); ?></a> | </span>
									<?php endif; ?>
									<span class="view"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View', 'plain-language-time-tracker' ); ?></a></span>
								</div>
							</td>
							<td>
								<?php echo $project_client ? esc_html( $project_client->name ) : '—'; ?>
							</td>
							<td>
								<?php pltt_render_billing_type_badge( $billing_type ); ?>
							</td>
							<td><?php
								if ( 'none' === $billing_type ) {
									echo '<span class="pltt-empty">—</span>';
								} elseif ( null !== $project->hourly_rate ) {
									echo esc_html( pltt_format_currency( $project->hourly_rate ) );
								} elseif ( $project_client && null !== $project_client->hourly_rate ) {
									echo esc_html( pltt_format_currency( $project_client->hourly_rate ) . ' / ' . __( 'client', 'plain-language-time-tracker' ) );
								} elseif ( defined( 'PLTT_DEFAULT_HOURLY_RATE' ) ) {
									echo esc_html( pltt_format_currency( PLTT_DEFAULT_HOURLY_RATE ) . ' / ' . __( 'default', 'plain-language-time-tracker' ) );
								} else {
									echo '<span class="pltt-empty">—</span>';
								}
							?></td>
							<td><?php
								$period_abbr = array( 'weekly' => 'wk', 'monthly' => 'mo', 'quarterly' => 'qtr', 'yearly' => 'yr' );
								if ( 'recurring' === $billing_type && ! empty( $project->budget_hours ) ) {
									$abbr = $period_abbr[ $project->recurring_period ] ?? $project->recurring_period;
									echo esc_html( number_format( (float) $project->budget_hours, 0 ) . ' hrs / ' . $abbr );
								} elseif ( 'fixed' === $billing_type && ! empty( $project->budget_fee ) ) {
									echo esc_html( pltt_format_currency( $project->budget_fee ) );
								} elseif ( 'fixed' === $billing_type && ! empty( $project->budget_hours ) ) {
									echo esc_html( number_format( (float) $project->budget_hours, 0 ) . ' hrs' );
								} else {
									echo '<span class="pltt-empty">—</span>';
								}
							?></td>
							<td class="pltt-col-billable">
								<span class="pltt-billable-symbol <?php echo 'none' !== $billing_type && (int) ( $project->billability_default ?? 1 ) === 1 ? 'is-billable' : 'not-billable'; ?>">$</span>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>

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
					<div id="pltt-project-budget-mode-group" class="pltt-hidden">
						<label for="pltt-project-budget-mode"><?php esc_html_e( 'Track Budget By', 'plain-language-time-tracker' ); ?></label>
						<select id="pltt-project-budget-mode" class="widefat">
							<option value="hours"><?php esc_html_e( 'Hours', 'plain-language-time-tracker' ); ?></option>
							<option value="fee"><?php esc_html_e( 'Project Fee', 'plain-language-time-tracker' ); ?></option>
						</select>
					</div>
					<div id="pltt-project-budget-value-group">
						<div id="pltt-budget-hours-wrap">
							<label id="pltt-project-budget-label" for="pltt-project-budget-hours"><?php esc_html_e( 'Hour Budget', 'plain-language-time-tracker' ); ?> <span class="pltt-optional"><?php esc_html_e( '(optional)', 'plain-language-time-tracker' ); ?></span></label>
							<div class="pltt-input-adornment-wrap">
							<input type="number" id="pltt-project-budget-hours" name="budget_hours" step="0.5" min="0" class="widefat" placeholder="0">
							<span class="pltt-adornment pltt-adornment-suffix">hr</span>
						</div>
						</div>
						<div id="pltt-budget-fee-wrap" class="pltt-hidden">
							<label for="pltt-project-budget-fee"><?php esc_html_e( 'Total Fee ($)', 'plain-language-time-tracker' ); ?> <span class="pltt-optional"><?php esc_html_e( '(optional)', 'plain-language-time-tracker' ); ?></span></label>
							<div class="pltt-input-adornment-wrap">
							<span class="pltt-adornment pltt-adornment-prefix">$</span>
							<input type="text" inputmode="decimal" id="pltt-project-budget-fee" name="budget_fee" class="widefat pltt-currency-input" placeholder="0.00">
						</div>
						</div>
					</div>
				</div>
				<p class="description" id="pltt-billing-settings-desc"></p>
			</div>

			<hr class="pltt-form-separator">

			<p id="pltt-project-rate-group">
				<label for="pltt-project-rate"><?php esc_html_e( 'Hourly Rate', 'plain-language-time-tracker' ); ?> <span class="pltt-optional"><?php esc_html_e( '(optional)', 'plain-language-time-tracker' ); ?></span></label>
				<div class="pltt-input-adornment-wrap">
				<span class="pltt-adornment pltt-adornment-prefix">$</span>
				<input type="text" inputmode="decimal" id="pltt-project-rate" name="hourly_rate" class="widefat pltt-currency-input" placeholder="0.00">
			</div>
				<small class="description" id="pltt-rate-description"><?php esc_html_e( 'Leave blank to use client rate.', 'plain-language-time-tracker' ); ?></small>
			</p>
			<p id="pltt-project-nonbillable-group">
				<label>
					<input type="checkbox" id="pltt-project-non-billable" name="non_billable" value="1">
					<?php esc_html_e( 'Non-billable project', 'plain-language-time-tracker' ); ?>
				</label>
				<small class="description" id="pltt-nonbillable-description"><?php esc_html_e( 'Entries default to non-billable. Can be overridden per entry.', 'plain-language-time-tracker' ); ?></small>
			</p>

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

			<hr class="pltt-form-separator">

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

	var BILLING_DESCRIPTIONS = {
		rate: {
			hourly:    '<?php echo esc_js( __( 'Leave blank to use client rate.', 'plain-language-time-tracker' ) ); ?>',
			fixed:     '<?php echo esc_js( __( 'Used to calculate implied effective rate. Leave blank to use client rate.', 'plain-language-time-tracker' ) ); ?>',
			recurring: '<?php echo esc_js( __( 'Used for overage billing. Leave blank to use client rate.', 'plain-language-time-tracker' ) ); ?>',
			none:      '<?php echo esc_js( __( 'Not applicable for internal projects.', 'plain-language-time-tracker' ) ); ?>'
		},
		nonbillable: {
			hourly:    '<?php echo esc_js( __( 'Entries default to billable. Check this to default new entries to non-billable instead.', 'plain-language-time-tracker' ) ); ?>',
			fixed:     '<?php echo esc_js( __( 'Entries default to non-billable (fixed-fee work is invoiced separately, not from time × rate).', 'plain-language-time-tracker' ) ); ?>',
			recurring: '<?php echo esc_js( __( 'Entries default to non-billable (within-allocation retainer time is covered by the flat fee). Manually mark overage entries billable when invoicing.', 'plain-language-time-tracker' ) ); ?>',
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
		var budgetModeGroup  = el('pltt-project-budget-mode-group');
		var budgetLabel      = el('pltt-project-budget-label');
		var nonBillableGroup = el('pltt-project-nonbillable-group');
		var nonBillable      = el('pltt-project-non-billable');
		var hoursInput       = el('pltt-project-budget-hours');
		var feeInput         = el('pltt-project-budget-fee');

		// Reset disabled/greyed states first.
		rateField.disabled = false;
		rateGroup.classList.remove('pltt-field-disabled');
		nonBillableGroup.classList.remove('pltt-field-disabled');

		// Update dynamic descriptions.
		el('pltt-rate-description').textContent = BILLING_DESCRIPTIONS.rate[type];
		el('pltt-nonbillable-description').textContent = BILLING_DESCRIPTIONS.nonbillable[type];

		if (type === 'hourly') {
			settingsBox.classList.add('pltt-hidden');
			hoursInput.disabled      = true;
			feeInput.disabled        = true;
			recurringSelect.disabled = true;
			hoursInput.required      = false;
			feeInput.required        = false;
			if (setDefaults) { nonBillable.checked = false; }

		} else if (type === 'fixed') {
			settingsBox.classList.remove('pltt-hidden');
			settingsTitle.textContent = '<?php echo esc_js( __( 'FIXED BUDGET SETTINGS', 'plain-language-time-tracker' ) ); ?>';
			settingsFields.classList.add('pltt-grid');
			recurringGroup.classList.add('pltt-hidden');
			budgetModeGroup.classList.remove('pltt-hidden');
			budgetLabel.firstChild.textContent = '<?php echo esc_js( __( 'Hour Budget', 'plain-language-time-tracker' ) ); ?> ';
			settingsDesc.textContent = '';
			recurringSelect.disabled = true;
			hoursInput.required      = true;
			feeInput.required        = true;
			var hoursOptional = budgetLabel.querySelector('.pltt-optional');
			if (hoursOptional) hoursOptional.classList.add('pltt-hidden');
			var feeLabel = el('pltt-budget-fee-wrap').querySelector('label');
			var feeOptional = feeLabel ? feeLabel.querySelector('.pltt-optional') : null;
			if (feeOptional) feeOptional.classList.add('pltt-hidden');
			// Infer mode from current values so switching away and back preserves the user's entry.
			var budgetMode = (feeInput.value !== '') ? 'fee' : 'hours';
			el('pltt-project-budget-mode').value = budgetMode;
			applyBudgetModeUI(budgetMode);
			// Fixed-fee dollars come from the flat fee, not time × rate — default entries to non-billable.
			if (setDefaults) { nonBillable.checked = true; }

		} else if (type === 'recurring') {
			settingsBox.classList.remove('pltt-hidden');
			settingsTitle.textContent = '<?php echo esc_js( __( 'RECURRING SETTINGS', 'plain-language-time-tracker' ) ); ?>';
			settingsFields.classList.add('pltt-grid');
			recurringGroup.classList.remove('pltt-hidden');
			budgetModeGroup.classList.add('pltt-hidden');
			el('pltt-project-budget-mode').value = 'hours';
			budgetLabel.firstChild.textContent = '<?php echo esc_js( __( 'Hour Allocation', 'plain-language-time-tracker' ) ); ?> ';
			settingsDesc.textContent = '<?php echo esc_js( __( 'Hours included per period. Within-allocation time is covered by the flat fee; mark overage entries billable manually when invoicing.', 'plain-language-time-tracker' ) ); ?>';
			recurringSelect.disabled = false;
			feeInput.disabled        = true;
			hoursInput.disabled      = false;
			hoursInput.required      = false;
			feeInput.required        = false;
			var hoursOptional = budgetLabel.querySelector('.pltt-optional');
			if (hoursOptional) hoursOptional.classList.remove('pltt-hidden');
			if (setDefaults) {
				nonBillable.checked = true;
				if (recurringSelect.value === '') { recurringSelect.value = 'monthly'; }
			}

		} else if (type === 'none') {
			settingsBox.classList.add('pltt-hidden');
			rateField.disabled = true;
			rateGroup.classList.add('pltt-field-disabled');
			nonBillable.checked = true;
			nonBillableGroup.classList.add('pltt-field-disabled'); // visual lock only; not HTML disabled so it still submits
			hoursInput.disabled      = true;
			feeInput.disabled        = true;
			recurringSelect.disabled = true;
			hoursInput.required      = false;
			feeInput.required        = false;
		}
	}

	function applyBudgetModeUI(mode) {
		var hoursWrap  = el('pltt-budget-hours-wrap');
		var feeWrap    = el('pltt-budget-fee-wrap');
		var hoursInput = el('pltt-project-budget-hours');
		var feeInput   = el('pltt-project-budget-fee');
		if (!hoursWrap || !feeWrap) return;
		if (mode === 'fee') {
			hoursWrap.classList.add('pltt-hidden');
			feeWrap.classList.remove('pltt-hidden');
			hoursInput.disabled = true;
			feeInput.disabled   = false;
		} else {
			hoursWrap.classList.remove('pltt-hidden');
			feeWrap.classList.add('pltt-hidden');
			hoursInput.disabled = false;
			feeInput.disabled   = true;
		}
	}

	// Notice params are stripped from the URL by PLTT.cleanNoticeParams() in shared.js.

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
			document.getElementById('pltt-project-budget-fee').value = '';
			document.getElementById('pltt-project-status-group').classList.add('pltt-hidden');
			document.getElementById('pltt-project-status').value = 'active';
			document.getElementById('pltt-delete-project-btn').classList.remove('visible');
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

	var budgetModeSelect = el('pltt-project-budget-mode');
	if (budgetModeSelect) {
		budgetModeSelect.addEventListener('change', function() {
			applyBudgetModeUI(this.value);
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
			document.getElementById('pltt-project-budget-fee').value = row.dataset.budgetFee || '';
			var billingType = row.dataset.billingType || 'hourly';
			applyBillingTypeUI(billingType, false);
			document.getElementById('pltt-project-non-billable').checked = row.dataset.billabilityDefault === '0';

			document.getElementById('pltt-project-status-group').classList.remove('pltt-hidden');
			document.getElementById('pltt-project-status').value = isArchived ? 'archived' : 'active';

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

	// Archive/Restore Project links (row-actions).
	document.querySelectorAll('.pltt-archive-project').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			var row = this.closest('tr');
			var newStatus = this.dataset.newStatus;
			var unbilled = parseInt(row.dataset.unbilledMinutes || '0', 10);
			if (!confirmArchiveIfUnbilled(newStatus, unbilled)) {
				return;
			}
			document.getElementById('pltt-archive-project-id').value = row.dataset.projectId;
			document.getElementById('pltt-archive-project-status').value = newStatus;
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
			const budgetFee = document.getElementById('pltt-project-budget-fee').value;
			const recurringPeriod = document.getElementById('pltt-project-recurring-period').value;
			const nonBillable = document.getElementById('pltt-project-non-billable').checked ? '1' : '0';

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
		var statusSel = document.getElementById('pltt-project-status');
		var editRow = document.querySelector('tr[data-project-id="' + id + '"]');
		if (statusSel && statusSel.value === 'archived' && editRow && editRow.dataset.status !== 'archived') {
			var unbilled = parseInt(editRow.dataset.unbilledMinutes || '0', 10);
			if (!confirmArchiveIfUnbilled('archived', unbilled)) {
				e.preventDefault();
			}
		}
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

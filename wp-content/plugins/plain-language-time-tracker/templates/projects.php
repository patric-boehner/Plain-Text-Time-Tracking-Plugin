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
		<?php
		pltt_render_empty_state(
			__( 'No projects yet.', 'plain-language-time-tracker' ),
			__( 'Add your first project to get started.', 'plain-language-time-tracker' )
		);
		?>
	<?php else : ?>
		<?php
		$group_mode = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : 'type';
		if ( ! in_array( $group_mode, array( 'client', 'type' ), true ) ) {
			$group_mode = 'type';
		}

		// Active / Archived / All status filter (default: Active).
		$status_filter = isset( $_GET['pstatus'] ) ? sanitize_key( wp_unslash( $_GET['pstatus'] ) ) : 'active';
		if ( ! in_array( $status_filter, array( 'active', 'archived', 'all' ), true ) ) {
			$status_filter = 'active';
		}

		$count_all      = count( $projects );
		$count_archived = count( array_filter( $projects, fn( $p ) => 'archived' === $p->status ) );
		$count_active   = $count_all - $count_archived;

		if ( 'archived' === $status_filter ) {
			$visible_projects = array_filter( $projects, fn( $p ) => 'archived' === $p->status );
		} elseif ( 'all' === $status_filter ) {
			$visible_projects = $projects;
		} else {
			$visible_projects = array_filter( $projects, fn( $p ) => 'archived' !== $p->status );
		}

		$project_groups = array();

		if ( 'type' === $group_mode ) {
			// Group the visible projects by billing type (archived blend into their type).
			$by_type = array();
			foreach ( $visible_projects as $project ) {
				$by_type[ pltt_get_billing_type( $project ) ][] = $project;
			}
			$type_labels = array(
				'hourly'    => __( 'Hourly', 'plain-language-time-tracker' ),
				'recurring' => __( 'Monthly retainer', 'plain-language-time-tracker' ),
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
			// 'client' grouping: one bucket per client, ordered by client name.
			$by_client = array();
			foreach ( $visible_projects as $project ) {
				$by_client[ $project->client_id ][] = $project;
			}
			$client_names = array();
			foreach ( array_keys( $by_client ) as $cid ) {
				$client_names[ $cid ] = isset( $clients_by_id[ $cid ] )
					? $clients_by_id[ $cid ]->name
					: __( '(Unknown client)', 'plain-language-time-tracker' );
			}
			asort( $client_names, SORT_NATURAL | SORT_FLAG_CASE );
			foreach ( $client_names as $cid => $cname ) {
				$project_groups[] = array(
					'label'    => $cname,
					'projects' => $by_client[ $cid ],
					'tbody_id' => '',
				);
			}
		}

		// Status-filter links preserve the current grouping ('type' is the default).
		$pltt_status_base = admin_url( 'admin.php?page=pltt-projects' );
		if ( 'type' !== $group_mode ) {
			$pltt_status_base = add_query_arg( 'group', $group_mode, $pltt_status_base );
		}
		$pltt_status_links = array(
			'active'   => array( __( 'Active', 'plain-language-time-tracker' ), $count_active ),
			'archived' => array( __( 'Archived', 'plain-language-time-tracker' ), $count_archived ),
			'all'      => array( __( 'All', 'plain-language-time-tracker' ), $count_all ),
		);
		?>
		<div class="pltt-projects-toolbar">
			<ul class="subsubsub">
				<?php
				$pltt_status_i    = 0;
				$pltt_status_last = count( $pltt_status_links ) - 1;
				foreach ( $pltt_status_links as $pltt_status_key => $pltt_status_info ) :
					?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'pstatus', $pltt_status_key, $pltt_status_base ) ); ?>"
							class="<?php echo $status_filter === $pltt_status_key ? 'current' : ''; ?>">
							<?php echo esc_html( $pltt_status_info[0] ); ?>
							<span class="count">(<?php echo (int) $pltt_status_info[1]; ?>)</span>
						</a><?php echo $pltt_status_i < $pltt_status_last ? ' |' : ''; ?>
					</li>
					<?php
					++$pltt_status_i;
				endforeach;
				?>
			</ul>
			<form method="get" action="" class="pltt-projects-groupby">
				<input type="hidden" name="page" value="pltt-projects">
				<input type="hidden" name="pstatus" value="<?php echo esc_attr( $status_filter ); ?>">
				<label for="pltt-group-by"><?php esc_html_e( 'Group by:', 'plain-language-time-tracker' ); ?></label>
				<select name="group" id="pltt-group-by" onchange="this.form.submit()">
					<option value="type" <?php selected( $group_mode, 'type' ); ?>><?php esc_html_e( 'Type', 'plain-language-time-tracker' ); ?></option>
					<option value="client" <?php selected( $group_mode, 'client' ); ?>><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></option>
				</select>
			</form>
		</div>
		<?php if ( empty( $project_groups ) ) : ?>
			<?php pltt_render_empty_state( __( 'No projects match this filter.', 'plain-language-time-tracker' ) ); ?>
		<?php endif; ?>
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
						// Archived projects now share client/type groups with active ones, so
						// always distinguish them with the row tint + badge.
						$row_class                  = ( 'archived' === $project->status ) ? 'pltt-row-archived' : '';

						$view_url = PLTT_Project_Detail::get_url( $project->id );
						?>
						<tr<?php echo $row_class ? ' class="' . esc_attr( $row_class ) . '"' : ''; ?> data-project-id="<?php echo esc_attr( $project->id ); ?>" data-unbilled-minutes="<?php echo esc_attr( $project_unbilled_minutes ); ?>" data-name="<?php echo esc_attr( $project->name ); ?>" data-client-id="<?php echo esc_attr( $project->client_id ); ?>" data-status="<?php echo esc_attr( $project->status ); ?>" data-rate="<?php echo esc_attr( $project->hourly_rate ?? '' ); ?>" data-billability-default="<?php echo esc_attr( $project->billability_default ?? '1' ); ?>" data-recurring-period="<?php echo esc_attr( $project->recurring_period ?? '' ); ?>" data-billing-type="<?php echo esc_attr( $billing_type ); ?>" data-budget-hours="<?php echo esc_attr( $project->budget_hours ?? '' ); ?>" data-budget-fee="<?php echo esc_attr( $project->budget_fee ?? '' ); ?>" data-entry-count="<?php echo esc_attr( $project_entry_count ); ?>">
							<td>
								<strong><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $project->name ); ?></a></strong>
								<?php if ( 'archived' === $project->status ) : ?>
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
								<?php if ( pltt_billable_flag_applies( $project ) ) : ?>
									<span class="pltt-billable-symbol <?php echo (int) ( $project->billability_default ?? 1 ) === 1 ? 'is-billable' : 'not-billable'; ?>">$</span>
								<?php else : ?>
									<span class="pltt-empty">—</span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>

<?php
// The modal and its behaviour live in a partial so project detail can open the
// same form (its Settings button is another .pltt-edit-project trigger).
include PLTT_PLUGIN_DIR . 'templates/partials/project-modal.php';
?>

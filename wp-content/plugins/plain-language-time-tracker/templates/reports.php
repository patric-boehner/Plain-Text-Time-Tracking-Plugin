<?php
/**
 * Reports template.
 *
 * @package PlainLanguageTimeTracker
 *
 * @var string   $date_from     Start date.
 * @var string   $date_to       End date.
 * @var string   $view          Current view (detailed or summary).
 * @var int      $client_id     Selected client ID (0 = all).
 * @var int      $project_id    Selected project ID (0 = all).
 * @var string   $tag           Selected tag ('' = all).
 * @var int|null $billable      Billable filter (null = all, 1 = billable, 0 = non-billable).
 * @var int|null $billed        Billed/invoiced filter (null = all, 1 = billed, 0 = unbilled).
 * @var int      $client_negate  Whether to negate the client filter (0 or 1).
 * @var int      $project_negate Whether to negate the project filter (0 or 1).
 * @var int      $tag_negate     Whether to negate the tag filter (0 or 1).
 * @var array    $entries        Paginated time entries (detailed view).
 * @var array    $summary        Project summary rows (summary view).
 * @var object   $stats          Aggregate stats for the filtered results.
 * @var int      $total_entries  Total entry count.
 * @var int      $total_pages    Total pages.
 * @var int      $paged          Current page number.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Calculate preset date ranges.
$today      = pltt_get_current_date();
$week_start = (int) get_option( 'start_of_week', 0 );
$now_dt     = new DateTimeImmutable( $today, wp_timezone() );
$current_dow      = (int) $now_dt->format( 'w' );
$days_since_start = ( $current_dow - $week_start + 7 ) % 7;

$presets = array(
	array(
		'label' => __( 'This Week', 'plain-language-time-tracker' ),
		'from'  => $now_dt->modify( "-{$days_since_start} days" )->format( 'Y-m-d' ),
		'to'    => $today,
	),
	array(
		'label' => __( 'Last Week', 'plain-language-time-tracker' ),
		'from'  => $now_dt->modify( '-' . ( $days_since_start + 7 ) . ' days' )->format( 'Y-m-d' ),
		'to'    => $now_dt->modify( '-' . ( $days_since_start + 1 ) . ' days' )->format( 'Y-m-d' ),
	),
	array(
		'label' => __( 'This Month', 'plain-language-time-tracker' ),
		'from'  => $now_dt->format( 'Y-m-01' ),
		'to'    => $today,
	),
	array(
		'label' => __( 'Last Month', 'plain-language-time-tracker' ),
		'from'  => $now_dt->modify( 'first day of last month' )->format( 'Y-m-01' ),
		'to'    => $now_dt->modify( 'first day of last month' )->format( 'Y-m-t' ),
	),
	array(
		'label' => __( 'This Year', 'plain-language-time-tracker' ),
		'from'  => $now_dt->format( 'Y-01-01' ),
		'to'    => $today,
	),
);

// Load data for filter dropdown options.
$all_clients  = PLTT_Clients::get_all();
$all_projects = PLTT_Projects::get_all();
$all_tags     = array_column( PLTT_Tags::get_all(), 'name' );
sort( $all_tags );

// Build projects grouped by client for JS cascade.
$projects_by_client = array();
foreach ( $all_projects as $proj ) {
	$cid = (string) $proj->client_id;
	if ( ! isset( $projects_by_client[ $cid ] ) ) {
		$projects_by_client[ $cid ] = array();
	}
	$projects_by_client[ $cid ][] = array(
		'id'   => (int) $proj->id,
		'name' => $proj->name,
	);
}

// Build client name map for optgroup labels.
$client_names = array();
foreach ( $all_clients as $c ) {
	$client_names[ (string) $c->id ] = $c->name;
}

// Build base URL that preserves all active filters (used for view tabs + pagination).
$filter_params = array(
	'page' => 'pltt-reports',
	'from' => $date_from,
	'to'   => $date_to,
);
if ( $client_id > 0 ) {
	$filter_params['client_id'] = $client_id;
}
if ( $project_id > 0 ) {
	$filter_params['project_id'] = $project_id;
}
if ( '' !== $tag ) {
	$filter_params['tag'] = $tag;
}
if ( null !== $billable ) {
	$filter_params['billable'] = $billable;
}
if ( null !== $billed ) {
	$filter_params['billed'] = $billed;
}
if ( $client_negate ) {
	$filter_params['client_negate'] = 1;
}
if ( $project_negate ) {
	$filter_params['project_negate'] = 1;
}
if ( $tag_negate ) {
	$filter_params['tag_negate'] = 1;
}

$tab_base_url = add_query_arg( $filter_params, admin_url( 'admin.php' ) );
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Time Entries', 'plain-language-time-tracker' ); ?></h1>
		<div class="pltt-view-toggle">
			<a href="<?php echo esc_url( add_query_arg( 'view', 'summary', $tab_base_url ) ); ?>"
				class="button <?php echo 'summary' === $view ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Summary', 'plain-language-time-tracker' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'view', 'detailed', $tab_base_url ) ); ?>"
				class="button <?php echo 'detailed' === $view ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Detailed', 'plain-language-time-tracker' ); ?>
			</a>
		</div>
	</div>

	<form method="get" action="" class="pltt-report-filters-form">
		<input type="hidden" name="page" value="pltt-reports">
		<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">

		<div class="pltt-filter-row pltt-filter-row-date">
				<?php
				$active_preset = '';
				foreach ( $presets as $preset ) {
					if ( $date_from === $preset['from'] && $date_to === $preset['to'] ) {
						$active_preset = $preset['label'];
						break;
					}
				}
				?>
				<div class="pltt-filter-group">
					<label for="pltt-date-preset"><?php esc_html_e( 'Range', 'plain-language-time-tracker' ); ?></label>
					<select id="pltt-date-preset">
						<option value=""><?php echo $active_preset ? '—' : esc_html__( 'Custom', 'plain-language-time-tracker' ); ?></option>
						<?php foreach ( $presets as $preset ) :
							$selected = ( $date_from === $preset['from'] && $date_to === $preset['to'] );
							?>
							<option value="<?php echo esc_attr( $preset['from'] . '|' . $preset['to'] ); ?>"
								<?php selected( $selected ); ?>>
								<?php echo esc_html( $preset['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="pltt-filter-group">
					<label for="pltt-date-start"><?php esc_html_e( 'From', 'plain-language-time-tracker' ); ?></label>
					<input type="date" name="from" id="pltt-date-start" value="<?php echo esc_attr( $date_from ); ?>">
				</div>

				<div class="pltt-filter-group">
					<label for="pltt-date-end"><?php esc_html_e( 'To', 'plain-language-time-tracker' ); ?></label>
					<input type="date" name="to" id="pltt-date-end" value="<?php echo esc_attr( $date_to ); ?>">
				</div>
			</div>

		<div class="pltt-report-filters">
			<div class="pltt-filter-row">
				<div class="pltt-filter-group">
					<label for="pltt-filter-client"><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></label>
					<div class="pltt-filter-input-wrap">
						<button type="button"
							class="pltt-negate-toggle <?php echo $client_negate ? 'pltt-negate-active' : ''; ?>"
							data-target="client_negate"
							<?php echo $client_id <= 0 ? 'style="display:none"' : ''; ?>
							title="<?php esc_attr_e( 'Toggle include/exclude', 'plain-language-time-tracker' ); ?>">
							<?php echo $client_negate ? esc_html__( 'not', 'plain-language-time-tracker' ) : esc_html__( 'is', 'plain-language-time-tracker' ); ?>
						</button>
						<input type="hidden" name="client_negate" value="<?php echo esc_attr( $client_negate ); ?>">
						<select name="client_id" id="pltt-filter-client">
							<option value=""><?php esc_html_e( 'All Clients', 'plain-language-time-tracker' ); ?></option>
							<?php foreach ( $all_clients as $c ) : ?>
								<option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $client_id, (int) $c->id ); ?>>
									<?php echo esc_html( $c->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="pltt-filter-group">
					<label for="pltt-filter-project"><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></label>
					<div class="pltt-filter-input-wrap">
						<button type="button"
							class="pltt-negate-toggle <?php echo $project_negate ? 'pltt-negate-active' : ''; ?>"
							data-target="project_negate"
							<?php echo $project_id <= 0 ? 'style="display:none"' : ''; ?>
							title="<?php esc_attr_e( 'Toggle include/exclude', 'plain-language-time-tracker' ); ?>">
							<?php echo $project_negate ? esc_html__( 'not', 'plain-language-time-tracker' ) : esc_html__( 'is', 'plain-language-time-tracker' ); ?>
						</button>
						<input type="hidden" name="project_negate" value="<?php echo esc_attr( $project_negate ); ?>">
						<select name="project_id" id="pltt-filter-project">
							<option value=""><?php esc_html_e( 'All Projects', 'plain-language-time-tracker' ); ?></option>
							<option value="without_project" <?php selected( $project_id, 'without_project' ); ?>><?php esc_html_e( '— Without Projects —', 'plain-language-time-tracker' ); ?></option>
							<?php
							if ( $client_id > 0 ) {
								// Single client selected: flat list (one client, grouping adds nothing).
								$visible_projects = $projects_by_client[ (string) $client_id ] ?? array();
								foreach ( $visible_projects as $p ) :
									$pid   = is_array( $p ) ? $p['id'] : (int) $p->id;
									$pname = is_array( $p ) ? $p['name'] : $p->name;
									?>
									<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $project_id, $pid ); ?>>
										<?php echo esc_html( $pname ); ?>
									</option>
								<?php endforeach;
							} else {
								// All clients: group projects under client optgroup labels.
								foreach ( $projects_by_client as $cid => $cprojects ) :
									$cname = $client_names[ $cid ] ?? __( 'Unknown Client', 'plain-language-time-tracker' );
									?>
									<optgroup label="<?php echo esc_attr( $cname ); ?>">
										<?php foreach ( $cprojects as $p ) :
											$pid   = is_array( $p ) ? $p['id'] : (int) $p->id;
											$pname = is_array( $p ) ? $p['name'] : $p->name;
											?>
											<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $project_id, $pid ); ?>>
												<?php echo esc_html( $pname ); ?>
											</option>
										<?php endforeach; ?>
									</optgroup>
								<?php endforeach;
							}
							?>
						</select>
					</div>
				</div>

				<div class="pltt-filter-group">
					<label for="pltt-filter-tag"><?php esc_html_e( 'Tag', 'plain-language-time-tracker' ); ?></label>
					<div class="pltt-filter-input-wrap">
						<button type="button"
							class="pltt-negate-toggle <?php echo $tag_negate ? 'pltt-negate-active' : ''; ?>"
							data-target="tag_negate"
							<?php echo '' === $tag ? 'style="display:none"' : ''; ?>
							title="<?php esc_attr_e( 'Toggle include/exclude', 'plain-language-time-tracker' ); ?>">
							<?php echo $tag_negate ? esc_html__( 'not', 'plain-language-time-tracker' ) : esc_html__( 'is', 'plain-language-time-tracker' ); ?>
						</button>
						<input type="hidden" name="tag_negate" value="<?php echo esc_attr( $tag_negate ); ?>">
						<select name="tag" id="pltt-filter-tag">
							<option value=""><?php esc_html_e( 'All Tags', 'plain-language-time-tracker' ); ?></option>
							<option value="without_tag" <?php selected( $tag, 'without_tag' ); ?>><?php esc_html_e( '— Without Tag —', 'plain-language-time-tracker' ); ?></option>
							<?php foreach ( $all_tags as $t ) : ?>
								<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tag, $t ); ?>>
									<?php echo esc_html( ucwords( $t ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="pltt-filter-group">
					<label for="pltt-filter-billable"><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></label>
					<select name="billable" id="pltt-filter-billable">
						<option value="" <?php selected( null === $billable ); ?>><?php esc_html_e( 'All', 'plain-language-time-tracker' ); ?></option>
						<option value="1" <?php selected( 1, $billable ); ?>><?php esc_html_e( 'Billable Only', 'plain-language-time-tracker' ); ?></option>
						<option value="0" <?php selected( 0, $billable ); ?>><?php esc_html_e( 'Non-Billable Only', 'plain-language-time-tracker' ); ?></option>
					</select>
				</div>

				<div class="pltt-filter-group">
					<label for="pltt-filter-billed"><?php esc_html_e( 'Invoiced', 'plain-language-time-tracker' ); ?></label>
					<select name="billed" id="pltt-filter-billed">
						<option value="" <?php selected( null === $billed ); ?>><?php esc_html_e( 'All', 'plain-language-time-tracker' ); ?></option>
						<option value="0" <?php selected( 0, $billed ); ?>><?php esc_html_e( 'Uninvoiced Only', 'plain-language-time-tracker' ); ?></option>
						<option value="1" <?php selected( 1, $billed ); ?>><?php esc_html_e( 'Invoiced Only', 'plain-language-time-tracker' ); ?></option>
					</select>
				</div>

				<div class="pltt-filter-group pltt-filter-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filter', 'plain-language-time-tracker' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pltt-reports' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'plain-language-time-tracker' ); ?></a>
				</div>
			</div>
		</div>
	</form>

	<?php
	wp_add_inline_script(
		'pltt-reports',
		'var plttProjectsByClient = ' . wp_json_encode( $projects_by_client ) . ';' .
		'var plttClientNames = ' . wp_json_encode( $client_names ) . ';' .
		'var plttAllTags = ' . wp_json_encode( $all_tags ) . ';',
		'before'
	);
	?>

	<?php if ( $total_entries > 0 ) : ?>
		<div class="pltt-summary-cards">

			<!-- Card 1: Active Projects -->
			<div class="card">
				<div class="card-label"><?php esc_html_e( 'Active Projects', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value"><?php echo esc_html( $stats->active_projects ); ?></div>
				<div class="card-secondary">
					<?php
					printf(
						/* translators: %d: number of clients */
						esc_html( _n( 'Across %d client', 'Across %d clients', (int) $stats->active_clients, 'plain-language-time-tracker' ) ),
						(int) $stats->active_clients
					);
					?>
				</div>
			</div>

			<!-- Card 2: Total Hours -->
			<div class="card">
				<div class="card-label"><?php esc_html_e( 'Total Hours', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value"><?php echo esc_html( pltt_format_hours( $stats->total_minutes ) ); ?></div>
				<?php if ( $working_days > 0 ) : ?>
					<div class="card-secondary">
						<?php
						$avg_per_day = $stats->total_minutes / 60 / $working_days;
						printf(
							esc_html__( '%s hrs/day avg', 'plain-language-time-tracker' ),
							esc_html( number_format( $avg_per_day, 1 ) )
						);
						?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Card 3: Billable Hours -->
			<div class="card">
				<div class="card-label"><?php esc_html_e( 'Billable Hours', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value"><?php echo esc_html( pltt_format_hours( $stats->billable_minutes ) ); ?></div>
				<div class="card-secondary">
					<span><?php printf( '%s %s', esc_html( number_format( $utilization, 1 ) . '%' ), esc_html__( 'utilization', 'plain-language-time-tracker' ) ); ?></span>
				</div>
			</div>

			<!-- Card 4: Billable Amount -->
			<?php if ( (float) $stats->billable_amount > 0 ) : ?>
				<div class="card">
					<div class="card-label"><?php esc_html_e( 'Billable Amount', 'plain-language-time-tracker' ); ?></div>
					<div class="card-value"><?php echo esc_html( pltt_format_currency( $stats->billable_amount ) ); ?></div>
					<div class="card-secondary">
						<?php
						$prev_amount = $prev_stats ? (float) $prev_stats->billable_amount : 0;
						$curr_amount = (float) $stats->billable_amount;

						if ( $prev_amount > 0 ) {
							$pct_change = ( $curr_amount - $prev_amount ) / $prev_amount * 100;
						} else {
							$pct_change = 100;
						}

						if ( abs( $pct_change ) < 5 ) {
							$change_class = 'status-neutral';
							$change_icon  = '→';
						} elseif ( $pct_change > 0 ) {
							$change_class = 'status-increase';
							$change_icon  = '↑';
						} else {
							$change_class = 'status-decrease';
							$change_icon  = '↓';
						}

						printf(
							esc_html__( 'vs. %s last period', 'plain-language-time-tracker' ),
							esc_html( pltt_format_currency( $prev_amount ) )
						);
						?>
						&bull;
						<span class="<?php echo esc_attr( $change_class ); ?>">
							<?php echo esc_html( $change_icon . ' ' . number_format( abs( $pct_change ), 0 ) . '%' ); ?>
						</span>
					</div>
				</div>
			<?php endif; ?>



		</div>
	<?php endif; ?>

	<div id="pltt-report-content" class="pltt-report-content">

		<?php if ( 'summary' === $view ) : ?>

			<?php if ( ! empty( $summary ) ) : ?>
				<table class="widefat pltt-summary-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Type', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Hours', 'plain-language-time-tracker' ); ?></th>
							<th class="pltt-budget-col"><?php esc_html_e( 'Budget', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'plain-language-time-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $summary as $row ) :
						$detail_args = array(
							'page' => 'pltt-reports',
							'view' => 'detailed',
							'from' => $date_from,
							'to'   => $date_to,
						);
						if ( ! empty( $row->client_id ) ) {
							$detail_args['client_id'] = $row->client_id;
						}
						if ( ! empty( $row->project_id ) ) {
							$detail_args['project_id'] = $row->project_id;
						}
						$detail_url = add_query_arg( $detail_args, admin_url( 'admin.php' ) );

							$billing_type_pre = pltt_get_billing_type( $row );
						?>
							<tr>
								<td class="pltt-entry-desc-cell<?php echo 'none' !== $billing_type_pre ? ' pltt-desc-billable' : ''; ?>">
									<?php if ( ! empty( $row->project_name ) ) : ?>
										<a href="<?php echo esc_url( $detail_url ); ?>"><span class="pltt-entry-desc-text"><?php echo esc_html( $row->project_name ); ?></span></a>
									<?php else : ?>
										<a href="<?php echo esc_url( $detail_url ); ?>"><span class="pltt-empty">—</span></a>
									<?php endif; ?>
									<?php if ( ! empty( $row->client_name ) ) : ?>
										<div class="pltt-entry-meta">
											<span class="pltt-entry-client"><?php echo esc_html( $row->client_name ); ?></span>
										</div>
									<?php endif; ?>
								</td>
								<td>
									<?php $billing_type = pltt_get_billing_type( $row ); ?>
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
								<?php
								$has_hours_alloc = ! empty( $row->budget_hours ) && ! empty( $row->project_id ) && isset( $alloc_stats[ $row->project_id ] );
								$has_fee_alloc   = ! empty( $row->budget_fee ) && ! empty( $row->project_id ) && isset( $alloc_stats[ $row->project_id ] );
								$has_alloc       = $has_hours_alloc || $has_fee_alloc;
								if ( $has_hours_alloc ) {
									$sa_budget_hours = (float) $row->budget_hours;
									// Recurring budgets track billable capacity; exclude non-billable hours.
									$sa_used_mins = 'recurring' === $billing_type
										? (float) $alloc_stats[ $row->project_id ]->billable_minutes
										: (float) $alloc_stats[ $row->project_id ]->total_minutes;
								}
								?>
								<?php $is_over_budget = ( $has_hours_alloc && ( $sa_used_mins / 60 ) >= $sa_budget_hours )
									|| ( $has_fee_alloc && (float) $alloc_stats[ $row->project_id ]->billable_amount >= (float) $row->budget_fee ); ?>
								<td class="pltt-duration-cell<?php echo $is_over_budget ? ' pltt-alloc-over' : ''; ?>" title="<?php echo esc_attr( pltt_format_hours( $row->total_minutes ) . ' ' . __( 'hrs', 'plain-language-time-tracker' ) ); ?>">
									<?php echo esc_html( pltt_format_duration( $row->total_minutes ) ); ?>
								</td>
								<td class="pltt-duration-cell pltt-budget-col">
									<?php if ( $has_hours_alloc ) :
										pltt_render_allocation_bar( $sa_used_mins, $sa_budget_hours, $billing_type );
									elseif ( $has_fee_alloc ) :
										$fee_rate            = pltt_resolve_billable_rate( (int) $row->client_id, (int) $row->project_id );
										$budget_hrs_from_fee = $fee_rate > 0 ? ( (float) $row->budget_fee / $fee_rate ) : 0;
										pltt_render_allocation_bar(
											(float) $alloc_stats[ $row->project_id ]->total_minutes,
											$budget_hrs_from_fee,
											$billing_type,
											array(
												'spent_dollars'  => (float) $alloc_stats[ $row->project_id ]->billable_amount,
												'budget_dollars' => (float) $row->budget_fee,
											)
										);
									else : ?>
										<span class="pltt-empty">—</span>
									<?php endif; ?>
								</td>
								<td class="pltt-duration-cell"><?php echo (float) $row->billable_amount > 0 ? esc_html( pltt_format_currency( $row->billable_amount ) ) : '<span class="pltt-empty">—</span>'; ?></td>
								</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description" style="padding: 20px; text-align: center;">
					<?php esc_html_e( 'No verified entries found for the selected filters.', 'plain-language-time-tracker' ); ?>
				</p>
			<?php endif; ?>

		<?php else : ?>

			<?php if ( ! empty( $entries ) ) : ?>
				<?php
				// Group entries by date for date-grouped display.
				$entries_by_date = array();
				foreach ( $entries as $entry ) {
					$entries_by_date[ $entry->entry_date ][] = $entry;
				}
				?>

				<?php
				$return_url = add_query_arg( $_GET, admin_url( 'admin.php' ) );
				?>

				<?php foreach ( $entries_by_date as $group_date => $group_entries ) :
					$date_obj    = new DateTimeImmutable( $group_date, wp_timezone() );
					$is_today    = $group_date === pltt_get_current_date();
					$date_label  = $is_today
						? __( 'Today', 'plain-language-time-tracker' )
						: $date_obj->format( 'F j, Y' ) . ' · ' . $date_obj->format( 'l' );
					?>
					<div class="pltt-date-group">
						<div class="pltt-date-group-header">
							<span class="pltt-date-group-title"><?php echo esc_html( $date_label ); ?></span>
							<span class="pltt-date-group-meta">
								<a href="<?php echo esc_url( pltt_get_admin_url( 'review', array( 'date' => $group_date, 'return_to' => urlencode( $return_url ) ) ) ); ?>" class="button"><?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?></a>
							</span>
						</div>
					<?php pltt_render_entry_table( $group_entries, array( 'show_amount' => true, 'inline_edit' => true, 'all_tags' => $all_tags ) ); ?>
					</div>
				<?php endforeach; ?>


				<?php
				$base_url = add_query_arg( 'view', $view, $tab_base_url );
				pltt_render_pagination( $paged, $total_pages, $total_entries, $base_url, 'entry', 'entries' );
				?>


			<?php else : ?>
				<p class="description" style="padding: 20px; text-align: center;">
					<?php esc_html_e( 'No entries found for the selected filters.', 'plain-language-time-tracker' ); ?>
				</p>
			<?php endif; ?>

		<?php endif; ?>

	</div>
</div>

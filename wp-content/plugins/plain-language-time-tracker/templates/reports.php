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
$all_tags     = PLTT_Entries::get_all_tags();
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
			<a href="<?php echo esc_url( add_query_arg( 'view', 'detailed', $tab_base_url ) ); ?>"
				class="button <?php echo 'detailed' === $view ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Detailed', 'plain-language-time-tracker' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'view', 'summary', $tab_base_url ) ); ?>"
				class="button <?php echo 'summary' === $view ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Summary', 'plain-language-time-tracker' ); ?>
			</a>
		</div>
	</div>

	<div class="pltt-report-filters">
		<form method="get" action="">
			<input type="hidden" name="page" value="pltt-reports">
			<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">

			<div class="pltt-filter-row">
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

			<div class="pltt-filter-row" style="margin-top: 15px;">
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
							<?php
							// Show all projects, or only the selected client's projects.
							$visible_projects = $client_id > 0 && isset( $projects_by_client[ (string) $client_id ] )
								? $projects_by_client[ (string) $client_id ]
								: $all_projects;
							foreach ( $visible_projects as $p ) :
								$pid   = is_array( $p ) ? $p['id'] : (int) $p->id;
								$pname = is_array( $p ) ? $p['name'] : $p->name;
								?>
								<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $project_id, $pid ); ?>>
									<?php echo esc_html( $pname ); ?>
								</option>
							<?php endforeach; ?>
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
							<?php foreach ( $all_tags as $t ) : ?>
								<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tag, $t ); ?>>
									<?php echo esc_html( $t ); ?>
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

				<div class="pltt-filter-group pltt-filter-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'plain-language-time-tracker' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pltt-reports' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'plain-language-time-tracker' ); ?></a>
				</div>
			</div>
		</form>
	</div>

	<script>var plttProjectsByClient = <?php echo wp_json_encode( $projects_by_client ); ?>;</script>

	<?php if ( $total_entries > 0 ) : ?>
		<div class="pltt-summary-cards">
			<div class="card">
				<div class="card-value"><?php echo esc_html( $total_entries ); ?></div>
				<div class="card-label"><?php esc_html_e( 'Entries', 'plain-language-time-tracker' ); ?></div>
			</div>
			<div class="card">
				<div class="card-value"><?php echo esc_html( pltt_format_hours( $stats->total_minutes ) ); ?></div>
				<div class="card-label"><?php esc_html_e( 'Total Hours', 'plain-language-time-tracker' ); ?></div>
			</div>
			<div class="card">
				<div class="card-value"><?php echo esc_html( pltt_format_hours( $stats->billable_minutes ) ); ?></div>
				<div class="card-label"><?php esc_html_e( 'Billable Hours', 'plain-language-time-tracker' ); ?></div>
			</div>
			<?php if ( (float) $stats->billable_amount > 0 ) : ?>
				<div class="card">
					<div class="card-value"><?php echo esc_html( pltt_format_currency( $stats->billable_amount ) ); ?></div>
					<div class="card-label"><?php esc_html_e( 'Billable Amount', 'plain-language-time-tracker' ); ?></div>
				</div>
			<?php endif; ?>
			<?php
			$unverified_count = $total_entries - (int) $stats->verified_count;
			if ( $unverified_count > 0 ) :
				?>
				<div class="card card-warning">
					<div class="card-value"><?php echo esc_html( $unverified_count ); ?></div>
					<div class="card-label"><?php esc_html_e( 'Unverified', 'plain-language-time-tracker' ); ?></div>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div id="pltt-report-content" class="pltt-report-content">

		<?php if ( 'summary' === $view ) : ?>

			<?php if ( ! empty( $summary ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Entries', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Hours', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Billable Hours', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Billable Amount', 'plain-language-time-tracker' ); ?></th>
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
						?>
							<tr>
								<td>
									<?php if ( ! empty( $row->project_name ) ) : ?>
										<a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $row->project_name ); ?></a>
									<?php else : ?>
										<a href="<?php echo esc_url( $detail_url ); ?>"><span class="pltt-empty">—</span></a>
									<?php endif; ?>
								</td>
								<td><?php echo ! empty( $row->client_name ) ? esc_html( $row->client_name ) : '<span class="pltt-empty">—</span>'; ?></td>
								<td><?php echo esc_html( $row->entry_count ); ?></td>
								<td class="pltt-duration-cell"><?php echo esc_html( pltt_format_hours( $row->total_minutes ) ); ?></td>
								<td class="pltt-duration-cell"><?php echo esc_html( pltt_format_hours( $row->billable_minutes ) ); ?></td>
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
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Time', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Description', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Status', 'plain-language-time-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$current_date = '';
						foreach ( $entries as $entry ) :
							$client  = ! empty( $entry->client_id ) ? PLTT_Clients::get( $entry->client_id ) : null;
							$project = ! empty( $entry->project_id ) ? PLTT_Projects::get( $entry->project_id ) : null;
							?>
							<tr>
								<td>
									<?php if ( $entry->entry_date !== $current_date ) : ?>
										<?php $current_date = $entry->entry_date; ?>
										<?php echo esc_html( pltt_format_date( $entry->entry_date, 'M j, Y' ) ); ?>
										<div class="row-actions">
											<a href="<?php echo esc_url( pltt_get_admin_url( 'review', array( 'date' => $entry->entry_date ) ) ); ?>"><?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?></a>
										</div>
									<?php endif; ?>
								</td>
								<td class="pltt-time-cell">
									<?php
									echo esc_html( pltt_format_time( $entry->start_time ) );
									if ( $entry->end_time ) {
										echo ' - ' . esc_html( pltt_format_time( $entry->end_time ) );
									}
									?>
								</td>
								<td class="pltt-duration-cell"><?php echo esc_html( pltt_format_duration( $entry->duration_minutes ) ); ?></td>
								<td><?php echo esc_html( $entry->description ); ?></td>
								<td><?php echo $client ? esc_html( $client->name ) : '<span class="pltt-empty">—</span>'; ?></td>
								<td><?php echo $project ? esc_html( $project->name ) : '<span class="pltt-empty">—</span>'; ?></td>
								<td><?php echo ! empty( $entry->tags ) ? esc_html( $entry->tags ) : '<span class="pltt-empty">—</span>'; ?></td>
								<td><?php echo $entry->billable ? '<span class="pltt-status-billable">Yes</span>' : '<span class="pltt-empty">—</span>'; ?></td>
								<td><?php echo $entry->verified ? '<span class="pltt-status-verified">Verified</span>' : '<span class="pltt-status-draft">Draft</span>'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php
								printf(
									/* translators: %s: total entries */
									esc_html( _n( '%s entry', '%s entries', $total_entries, 'plain-language-time-tracker' ) ),
									number_format_i18n( $total_entries )
								);
								?>
							</span>
							<span class="pagination-links">
								<?php
								$base_url = add_query_arg( 'view', $view, $tab_base_url );

								// First page.
								if ( $paged > 1 ) :
									?>
									<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $base_url ) ); ?>">
										&laquo;
									</a>
									<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ); ?>">
										&lsaquo;
									</a>
								<?php else : ?>
									<span class="tablenav-pages-navspan button disabled">&laquo;</span>
									<span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
								<?php endif; ?>

								<span class="paging-input">
									<?php echo esc_html( $paged ); ?>
									<?php esc_html_e( 'of', 'plain-language-time-tracker' ); ?>
									<span class="total-pages"><?php echo esc_html( $total_pages ); ?></span>
								</span>

								<?php if ( $paged < $total_pages ) : ?>
									<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ); ?>">
										&rsaquo;
									</a>
									<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ); ?>">
										&raquo;
									</a>
								<?php else : ?>
									<span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
									<span class="tablenav-pages-navspan button disabled">&raquo;</span>
								<?php endif; ?>
							</span>
						</div>
					</div>
				<?php endif; ?>

			<?php else : ?>
				<p class="description" style="padding: 20px; text-align: center;">
					<?php esc_html_e( 'No entries found for the selected filters.', 'plain-language-time-tracker' ); ?>
				</p>
			<?php endif; ?>

		<?php endif; ?>

	</div>
</div>

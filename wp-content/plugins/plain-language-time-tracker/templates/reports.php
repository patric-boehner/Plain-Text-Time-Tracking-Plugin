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
// Archived projects stay inline with their client but sort to the bottom of each group,
// and carry their status so the dropdown can render them dimmed.
$projects_by_client = array();
foreach ( $all_projects as $proj ) {
	$cid = (string) $proj->client_id;
	if ( ! isset( $projects_by_client[ $cid ] ) ) {
		$projects_by_client[ $cid ] = array();
	}
	$projects_by_client[ $cid ][] = array(
		'id'       => (int) $proj->id,
		'name'     => $proj->name,
		'archived' => ( 'archived' === $proj->status ) ? 1 : 0,
	);
}
// Sort each client's projects: active first (alphabetical), archived last (alphabetical).
foreach ( $projects_by_client as $cid => $cprojects ) {
	usort(
		$projects_by_client[ $cid ],
		function ( $a, $b ) {
			if ( $a['archived'] !== $b['archived'] ) {
				return $a['archived'] <=> $b['archived'];
			}
			return strcasecmp( $a['name'], $b['name'] );
		}
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

		<?php
			$active_preset = '';
			foreach ( $presets as $preset ) {
				if ( $date_from === $preset['from'] && $date_to === $preset['to'] ) {
					$active_preset = $preset['label'];
					break;
				}
			}
			$range_label      = pltt_format_date_range( $date_from, $date_to );
			?>
		<div class="pltt-date-nav-row">
		<div class="pltt-date-nav"
			role="group"
			aria-label="<?php esc_attr_e( 'Date range', 'plain-language-time-tracker' ); ?>"
			data-week-start="<?php echo esc_attr( $week_start ); ?>">

			<input type="hidden" name="from" id="pltt-date-from" value="<?php echo esc_attr( $date_from ); ?>">
			<input type="hidden" name="to"   id="pltt-date-to"   value="<?php echo esc_attr( $date_to ); ?>">

			<button type="button" class="pltt-date-nav-step pltt-date-nav-prev"
				aria-label="<?php esc_attr_e( 'Previous period', 'plain-language-time-tracker' ); ?>"></button>

			<div class="pltt-date-nav-picker">
				<button type="button" class="pltt-date-nav-label"
					aria-expanded="false"
					id="pltt-date-nav-trigger">
					<span class="pltt-date-nav-label-main"><?php echo esc_html( $active_preset ?: $range_label ); ?></span>
					<?php if ( $active_preset ) : ?>
						<span class="pltt-date-nav-label-sub"><?php echo esc_html( $range_label ); ?></span>
					<?php endif; ?>
				</button>

				<div class="pltt-date-nav-dropdown" hidden>

					<ul class="pltt-date-nav-options">
					<?php foreach ( $presets as $preset ) :
						$sel = ( $date_from === $preset['from'] && $date_to === $preset['to'] );
						?>
						<li><button type="button"
							class="pltt-date-nav-option"
							data-from="<?php echo esc_attr( $preset['from'] ); ?>"
							data-to="<?php echo esc_attr( $preset['to'] ); ?>"
							<?php if ( $sel ) : ?>aria-current="true"<?php endif; ?>>
							<?php echo esc_html( $preset['label'] ); ?>
						</button></li>
					<?php endforeach; ?>
					</ul>

					<hr class="pltt-date-nav-separator">

					<fieldset class="pltt-date-nav-custom-inputs">
						<legend><?php esc_html_e( 'Custom Range', 'plain-language-time-tracker' ); ?></legend>
						<label for="pltt-date-custom-from"><?php esc_html_e( 'From', 'plain-language-time-tracker' ); ?></label>
						<input type="date" id="pltt-date-custom-from" value="<?php echo esc_attr( $date_from ); ?>">
						<label for="pltt-date-custom-to"><?php esc_html_e( 'To', 'plain-language-time-tracker' ); ?></label>
						<input type="date" id="pltt-date-custom-to" value="<?php echo esc_attr( $date_to ); ?>">
						<button type="button" class="button button-primary pltt-date-nav-custom-apply">
							<?php esc_html_e( 'Apply', 'plain-language-time-tracker' ); ?>
						</button>
					</fieldset>
				</div>
			</div>

			<button type="button" class="pltt-date-nav-step pltt-date-nav-next"
				aria-label="<?php esc_attr_e( 'Next period', 'plain-language-time-tracker' ); ?>"></button>
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
									$pid       = is_array( $p ) ? $p['id'] : (int) $p->id;
									$pname     = is_array( $p ) ? $p['name'] : $p->name;
									$parchived = is_array( $p ) && ! empty( $p['archived'] );
									$plabel    = $parchived ? $pname . ' ' . __( '(Archived)', 'plain-language-time-tracker' ) : $pname;
									?>
									<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $project_id, $pid ); ?><?php echo $parchived ? ' class="pltt-project-archived"' : ''; ?>>
										<?php echo esc_html( $plabel ); ?>
									</option>
								<?php endforeach;
							} else {
								// All clients: group projects under client optgroup labels.
								foreach ( $projects_by_client as $cid => $cprojects ) :
									$cname = $client_names[ $cid ] ?? __( 'Unknown Client', 'plain-language-time-tracker' );
									?>
									<optgroup label="<?php echo esc_attr( $cname ); ?>">
										<?php foreach ( $cprojects as $p ) :
											$pid       = is_array( $p ) ? $p['id'] : (int) $p->id;
											$pname     = is_array( $p ) ? $p['name'] : $p->name;
											$parchived = is_array( $p ) && ! empty( $p['archived'] );
											$plabel    = $parchived ? $pname . ' ' . __( '(Archived)', 'plain-language-time-tracker' ) : $pname;
											?>
											<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $project_id, $pid ); ?><?php echo $parchived ? ' class="pltt-project-archived"' : ''; ?>>
												<?php echo esc_html( $plabel ); ?>
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
							<?php
							// Group tags under <optgroup> sections; ungrouped tags fall into an unlabeled section at the end.
							$tag_group_map = PLTT_Tags::get_name_to_group_map();
							$tags_by_group = array();
							$ungrouped_tags = array();
							foreach ( $all_tags as $t ) {
								if ( ! empty( $tag_group_map[ $t ] ) ) {
									$tags_by_group[ $tag_group_map[ $t ] ][] = $t;
								} else {
									$ungrouped_tags[] = $t;
								}
							}
							ksort( $tags_by_group );
							foreach ( $tags_by_group as $group_label => $group_tags ) :
								sort( $group_tags );
								?>
								<optgroup label="<?php echo esc_attr( $group_label ); ?>">
									<?php foreach ( $group_tags as $t ) : ?>
										<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tag, $t ); ?>>
											<?php echo esc_html( ucwords( $t ) ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
							<?php foreach ( $ungrouped_tags as $t ) : ?>
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
		// SEC-L1: JSON_HEX_TAG/AMP so a "</script>" inside an admin-authored name
		// can't break out of this inline <script>.
		'var plttProjectsByClient = ' . wp_json_encode( $projects_by_client, JSON_HEX_TAG | JSON_HEX_AMP ) . ';' .
		'var plttClientNames = ' . wp_json_encode( $client_names, JSON_HEX_TAG | JSON_HEX_AMP ) . ';' .
		'var plttAllTags = ' . wp_json_encode( $all_tags, JSON_HEX_TAG | JSON_HEX_AMP ) . ';' .
		'var plttTagGroups = ' . wp_json_encode( PLTT_Tags::get_name_to_group_map(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';' .
		'var plttReportStats = ' . wp_json_encode( array(
			'billableMinutes' => $stats ? (int) $stats->billable_minutes : 0,
			'billableAmount'  => $stats ? round( (float) $stats->billable_amount, 2 ) : 0,
			'prevMinutes'     => $prev_stats ? (int) $prev_stats->billable_minutes : 0,
			'prevAmount'      => $prev_stats ? round( (float) $prev_stats->billable_amount, 2 ) : 0,
		) ) . ';',
		'before'
	);
	?>

	<?php if ( ! empty( $context_client ) || $total_entries > 0 ) : ?>
		<div class="pltt-summary-cards">

			<?php if ( $is_single_project_view && ! empty( $context_projects ) ) : ?>
				<?php include PLTT_PLUGIN_DIR . 'templates/partials/project-context-card.php'; ?>
			<?php elseif ( ! empty( $context_client ) ) : ?>
				<?php include PLTT_PLUGIN_DIR . 'templates/partials/client-context-card.php'; ?>
			<?php endif; ?>

			<?php if ( $total_entries > 0 ) : ?>

			<?php if ( ! $is_single_project_view ) : ?>
			<!-- Card 1: Top Projects for the period -->
			<div class="card pltt-top-projects-card">
				<div class="card-label"><?php esc_html_e( 'Top Projects', 'plain-language-time-tracker' ); ?></div>
				<?php if ( ! empty( $top_projects ) ) : ?>
					<ol class="pltt-top-projects-list">
						<?php foreach ( $top_projects as $tp ) : ?>
							<li class="pltt-top-project">
								<span class="pltt-top-project-name" title="<?php echo esc_attr( $tp->project_name ); ?>"><?php echo esc_html( $tp->project_name ); ?></span>
								<span class="pltt-top-project-stats"><?php echo esc_html( pltt_format_duration( (int) $tp->total_minutes ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php else : ?>
					<div class="card-value pltt-card-value-empty">&mdash;</div>
					<div class="card-secondary"><?php esc_html_e( 'No client work tracked', 'plain-language-time-tracker' ); ?></div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- Card 2: Total Hours -->
			<div class="card">
				<div class="card-label"><?php esc_html_e( 'Total Hours', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value" <?php echo pltt_decimal_hint_attrs( (int) $stats->total_minutes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( pltt_format_duration( (int) $stats->total_minutes ) ); ?></div>
				<?php if ( (int) $stats->total_minutes > 0 ) :
					$client_pct   = (int) round( ( (int) $stats->client_total_minutes / (int) $stats->total_minutes ) * 100 );
					$internal_pct = 100 - $client_pct; // derived so the two always sum to 100
					?>
					<div class="card-secondary pltt-card-breakdown">
						<div class="pltt-card-breakdown-row">
							<span class="pltt-card-breakdown-label"><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></span>
							<span class="pltt-card-breakdown-value" <?php echo pltt_decimal_hint_attrs( (int) $stats->client_total_minutes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( pltt_format_duration( (int) $stats->client_total_minutes ) ); ?></span>
							<span class="pltt-card-breakdown-pct">(<?php echo esc_html( $client_pct ); ?>%)</span>
						</div>
						<div class="pltt-card-breakdown-row">
							<span class="pltt-card-breakdown-label"><?php esc_html_e( 'Internal', 'plain-language-time-tracker' ); ?></span>
							<span class="pltt-card-breakdown-value" <?php echo pltt_decimal_hint_attrs( (int) $internal_minutes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( pltt_format_duration( (int) $internal_minutes ) ); ?></span>
							<span class="pltt-card-breakdown-pct">(<?php echo esc_html( $internal_pct ); ?>%)</span>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- Card 3: Billable Hours -->
			<?php
			// Flag-based on every view: billable = entries the user marked billable.
			// The allocation/overage split is shown in the detailed entry list's
			// threshold marker, not in this card. See the "billable flag vs.
			// allocation line" open question in project memory.
			//
			// Period-over-period comparison mirrors the Billable Amount card (Card 4);
			// JS mirrors this math so the inline billable toggle can update it live.
			$prev_hours_mins = $prev_stats ? (int) $prev_stats->billable_minutes : 0;
			$curr_hours_mins = (int) $stats->billable_minutes;

			if ( $prev_hours_mins > 0 ) {
				$hours_pct_change = ( $curr_hours_mins - $prev_hours_mins ) / $prev_hours_mins * 100;
			} else {
				$hours_pct_change = 100;
			}

			if ( abs( $hours_pct_change ) < 5 ) {
				$hours_change_class = 'status-neutral';
				$hours_change_icon  = '→';
			} elseif ( $hours_pct_change > 0 ) {
				$hours_change_class = 'status-increase';
				$hours_change_icon  = '↑';
			} else {
				$hours_change_class = 'status-decrease';
				$hours_change_icon  = '↓';
			}
			?>
			<div class="card">
				<div class="card-label"><?php esc_html_e( 'Billable Hours', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value" id="pltt-stat-billable-hours" <?php echo pltt_decimal_hint_attrs( (int) $stats->billable_minutes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( pltt_format_duration( (int) $stats->billable_minutes ) ); ?></div>
				<div class="card-secondary">
					<?php if ( $is_partial_period ) : ?>
						<?php
						printf(
							/* translators: %s: billable time in the same elapsed slice of the prior period. */
							esc_html__( 'vs. %s same point last period', 'plain-language-time-tracker' ),
							esc_html( pltt_format_duration( $prev_hours_mins ) )
						);
						?>
					<?php else : ?>
						<?php
						printf(
							esc_html__( 'vs. %s last period', 'plain-language-time-tracker' ),
							esc_html( pltt_format_duration( $prev_hours_mins ) )
						);
						?>
						&bull;
						<span id="pltt-stat-hours-change" class="<?php echo esc_attr( $hours_change_class ); ?>">
							<?php echo esc_html( $hours_change_icon . ' ' . number_format( abs( $hours_pct_change ), 0 ) . '%' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<!-- Card 4: Billable Amount -->
			<?php
			// Flag-based on every view (sum of entries marked billable). See Card 3 note.
			// Always rendered (hidden at $0) so the inline billable toggle can update and
			// reveal/hide it live without rebuilding DOM. JS mirrors the math below.
			$prev_amount  = $prev_stats ? (float) $prev_stats->billable_amount : 0;
			$curr_amount  = (float) $stats->billable_amount;
			$amount_shown = $curr_amount > 0;

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
			?>
			<div class="card<?php echo $amount_shown ? '' : ' pltt-hidden'; ?>" id="pltt-stat-amount-card">
				<div class="card-label"><?php esc_html_e( 'Billable Amount', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value" id="pltt-stat-billable-amount"><?php echo esc_html( pltt_format_currency( $curr_amount ) ); ?></div>
				<div class="card-secondary">
					<?php if ( $is_partial_period ) : ?>
						<?php
						printf(
							/* translators: %s: billable amount in the same elapsed slice of the prior period. */
							esc_html__( 'vs. %s same point last period', 'plain-language-time-tracker' ),
							esc_html( pltt_format_currency( $prev_amount ) )
						);
						?>
					<?php else : ?>
						<?php
						printf(
							esc_html__( 'vs. %s last period', 'plain-language-time-tracker' ),
							esc_html( pltt_format_currency( $prev_amount ) )
						);
						?>
						&bull;
						<span id="pltt-stat-amount-change" class="<?php echo esc_attr( $change_class ); ?>">
							<?php echo esc_html( $change_icon . ' ' . number_format( abs( $pct_change ), 0 ) . '%' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<?php endif; /* total_entries > 0 */ ?>

		</div>
	<?php endif; ?>


	<?php
	// Volume bar chart (Hours by day/week/month). Markup shared with the Project
	// Detail report tab via templates/partials/chart-by-period.php.
	if ( 'summary' === $view && ! empty( $chart['buckets'] ) && ( $chart['max_minutes'] ?? 0 ) > 0 ) :
		include PLTT_PLUGIN_DIR . 'templates/partials/chart-by-period.php';
	endif;
	?>

	<div id="pltt-report-content" class="pltt-report-content">

		<?php if ( 'summary' === $view ) : ?>

			<?php
			// Global "billable time outside your date range" notification, under the
			// chart and above the summary table. One aggregate signal across all
			// non-archived, non-fixed-fee projects.
			if ( $unbilled_notice ) :
				// Expand to cover all stranded time; keep current edges if already wider.
				$un_from = min( $date_from, $unbilled_notice->earliest );
				$un_to   = max( $date_to, $unbilled_notice->latest );
				// Action lands on the actionable view: expanded range + Billable=yes,
				// Invoiced=no, preserving the current client/project/tag filters.
				$un_args = array(
					'page'     => 'pltt-reports',
					'view'     => 'summary',
					'from'     => $un_from,
					'to'       => $un_to,
					'billable' => 1,
					'billed'   => 0,
				);
				foreach ( array( 'client_id', 'project_id', 'tag', 'client_negate', 'project_negate', 'tag_negate' ) as $carry ) {
					if ( ! empty( $$carry ) ) {
						$un_args[ $carry ] = $$carry;
					}
				}
				$un_url = add_query_arg( $un_args, admin_url( 'admin.php' ) );
				?>
				<div class="pltt-unbilled-notice" data-pltt-unbilled-notice>
					<span class="pltt-unbilled-notice-icon" aria-hidden="true">!</span>
					<p class="pltt-unbilled-notice-body">
						<?php
						$un_count_phrase = sprintf( _n( '%s entry', '%s entries', (int) $unbilled_notice->entry_count, 'plain-language-time-tracker' ), number_format_i18n( (int) $unbilled_notice->entry_count ) );
						$un_amount       = isset( $unbilled_notice->total_amount ) ? (float) $unbilled_notice->total_amount : 0;
						if ( $un_amount > 0 ) {
							printf(
								/* translators: 1: entry count phrase, e.g. "4 entries"; 2: total duration, e.g. "3h 15m"; 3: approximate dollar value, e.g. "$795.00". */
								esc_html__( 'There\'s billable time outside your current date range — %1$s totaling %2$s (≈ %3$s not yet invoiced)', 'plain-language-time-tracker' ),
								esc_html( $un_count_phrase ),
								esc_html( pltt_format_duration( (int) $unbilled_notice->total_minutes ) ),
								esc_html( pltt_format_currency( $un_amount ) )
							);
						} else {
							printf(
								/* translators: 1: entry count phrase, e.g. "4 entries"; 2: total duration, e.g. "3h 15m". */
								esc_html__( 'There\'s billable time outside your current date range — %1$s totaling %2$s', 'plain-language-time-tracker' ),
								esc_html( $un_count_phrase ),
								esc_html( pltt_format_duration( (int) $unbilled_notice->total_minutes ) )
							);
						}
						?>
					</p>
					<a href="<?php echo esc_url( $un_url ); ?>" class="pltt-unbilled-notice-action">
						<?php esc_html_e( 'Expand range to show all unbilled', 'plain-language-time-tracker' ); ?>
					</a>
					<button type="button" class="pltt-unbilled-notice-dismiss" aria-label="<?php esc_attr_e( 'Dismiss this notice', 'plain-language-time-tracker' ); ?>">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
			<?php endif; ?>

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

							// OPT-DUP7: hoist billing_type once per row instead of calling twice.
							$billing_type    = pltt_get_billing_type( $row );
							$is_archived_row = ! empty( $row->project_status ) && 'archived' === $row->project_status;
						?>
							<tr<?php echo $is_archived_row ? ' class="pltt-row-archived"' : ''; ?>>
								<td class="pltt-entry-desc-cell">
									<?php if ( ! empty( $row->project_name ) ) : ?>
										<a href="<?php echo esc_url( $detail_url ); ?>"><span class="pltt-entry-desc-text"><?php echo esc_html( $row->project_name ); ?></span></a>
									<?php else : ?>
										<a href="<?php echo esc_url( $detail_url ); ?>"><span class="pltt-empty">—</span></a>
									<?php endif; ?>
									<?php if ( $is_archived_row ) : ?>
										<span class="pltt-badge pltt-badge-archived"><?php esc_html_e( 'Archived', 'plain-language-time-tracker' ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $row->client_name ) ) : ?>
										<div class="pltt-entry-meta">
											<span class="pltt-entry-client"><?php echo esc_html( $row->client_name ); ?></span>
										</div>
									<?php endif; ?>
								</td>
								<td>
									<?php pltt_render_billing_type_badge( $billing_type ); ?>
								</td>
								<?php
								$has_hours_alloc = ! empty( $row->budget_hours ) && ! empty( $row->project_id ) && isset( $alloc_stats[ $row->project_id ] );
								$has_fee_alloc   = ! empty( $row->budget_fee ) && ! empty( $row->project_id ) && isset( $alloc_stats[ $row->project_id ] );
								$has_alloc       = $has_hours_alloc || $has_fee_alloc;
								if ( $has_hours_alloc ) {
									$sa_budget_hours = (float) $row->budget_hours;
									// Allocation bar tracks consumption (all client work) regardless of billing type.
									// Under the new billable model, within-allocation retainer time is non-billable,
									// so counting only billable_minutes would under-report retainer consumption.
									$sa_used_mins = (float) $alloc_stats[ $row->project_id ]->total_minutes;
								}
								?>
								<td class="pltt-duration-cell" title="<?php echo esc_attr( pltt_format_hours( $row->total_minutes ) . ' ' . __( 'hrs', 'plain-language-time-tracker' ) ); ?>">
									<?php echo esc_html( pltt_format_duration( $row->total_minutes ) ); ?>
								</td>
								<td class="pltt-duration-cell pltt-budget-col">
									<?php if ( $has_hours_alloc ) :
										pltt_render_allocation_bar( $sa_used_mins, $sa_budget_hours, $billing_type );
									elseif ( $has_fee_alloc ) :
										$fee_rate            = pltt_resolve_billable_rate( (int) $row->client_id, (int) $row->project_id );
										$budget_hrs_from_fee = $fee_rate > 0 ? ( (float) $row->budget_fee / $fee_rate ) : 0;
										$fee_used_mins       = (float) $alloc_stats[ $row->project_id ]->total_minutes;
										pltt_render_allocation_bar(
											$fee_used_mins,
											$budget_hrs_from_fee,
											$billing_type,
											array(
												// Spent = value of time burned (hours × rate), matching the
												// project context card. billable_amount is $0 for fixed-fee
												// work since its entries are non-billable (1.9.5 model).
												'spent_dollars'  => round( ( $fee_used_mins / 60.0 ) * $fee_rate, 2 ),
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

				// Resolve overage-marker placement for the single-project alloc view.
				$marker_day_key        = null;
				$marker_primary_text   = '';
				$marker_secondary_text = '';
				$visible_overage_ids   = array();

				if ( ! empty( $context_overage ) && 'over' === $context_overage['state'] ) {
					// Tint only the overage entries actually visible on this page.
					$page_ids            = array_map( function ( $e ) { return (int) $e->id; }, $entries );
					$visible_overage_ids = array_values( array_intersect( $context_overage['overage_entry_ids'], $page_ids ) );

					if ( ! empty( $context_overage['boundary_time'] ) ) {
						$marker_primary_text = sprintf(
							/* translators: 1: clock time the allocation was crossed, e.g. "3:45pm"; 2: hours/minutes used, e.g. "3h 0m". */
							__( 'Allocation reached at %1$s · %2$s used', 'plain-language-time-tracker' ),
							$context_overage['boundary_time'],
							pltt_format_duration( $context_overage['allocation_minutes'] )
						);
					} else {
						$marker_primary_text = sprintf(
							/* translators: %s: hours/minutes used to date, e.g. "10h 0m". */
							__( 'Allocation reached · %s used', 'plain-language-time-tracker' ),
							pltt_format_duration( $context_overage['allocation_minutes'] )
						);
					}
					$marker_secondary_text = __( 'Entries below are overage candidates', 'plain-language-time-tracker' );

					// Find which day group hosts the boundary entry (may be on another page).
					$mid = (int) $context_overage['marker_entry_id'];
					foreach ( $entries_by_date as $d => $rows ) {
						foreach ( $rows as $r ) {
							if ( (int) $r->id === $mid ) {
								$marker_day_key = $d;
								break 2;
							}
						}
					}
				}
				?>

				<?php
				// SEC-M12: whitelist the query params that survive the round-trip
				// into the Review screen's back-link, so attacker-injected params
				// like pltt_error_message can't ride along.
				$return_allowed = array_flip( array(
					'page',
					'view',
					'from',
					'to',
					'client_id',
					'project_id',
					'tag',
					'billable',
					'billed',
					'client_negate',
					'project_negate',
					'tag_negate',
					'paged',
				) );
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only query for building a back-link.
				$return_params = array_intersect_key( wp_unslash( $_GET ), $return_allowed );
				// SEC-L3: sanitize each surviving value (keys are already allowlisted);
				// drop any non-scalar so an array param can't slip into the back-link.
				$return_params = array_map(
					static function ( $value ) {
						return is_scalar( $value ) ? sanitize_text_field( $value ) : '';
					},
					$return_params
				);
				$return_url = add_query_arg( $return_params, admin_url( 'admin.php' ) );
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
						<?php
						$entry_table_opts = array(
							'show_amount'       => true,
							'inline_edit'       => true,
							'all_tags'          => $all_tags,
							'overage_entry_ids' => $visible_overage_ids,
						);
						if ( $marker_day_key && $group_date === $marker_day_key ) {
							$entry_table_opts['threshold_marker_before']    = (int) $context_overage['marker_entry_id'];
							$entry_table_opts['threshold_marker_primary']   = $marker_primary_text;
							$entry_table_opts['threshold_marker_secondary'] = $marker_secondary_text;
						}
						pltt_render_entry_table( $group_entries, $entry_table_opts );
						?>
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

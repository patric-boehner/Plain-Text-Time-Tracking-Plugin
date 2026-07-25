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
 * @var string   $billing_status Billing filter: '' (all), 'unbilled', 'billed' or 'not_charged'.
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
if ( '' !== $billing_status ) {
	$filter_params['billing'] = $billing_status;
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
		<?php
		// Light header — this screen is not a single scope with terms, so it gets
		// the three-line identity (no figures inside; those sit in the number bar
		// below). Line 2 states what's true about the view; line 3 the literal dates.
		$range_label_hdr = pltt_format_date_range( $date_from, $date_to );
		if ( $stats && (int) $stats->total_minutes > 0 ) {
			$scope_line_hdr = sprintf(
				/* translators: 1: entry count; 2: total time logged (e.g. "95h 39m"). */
				esc_html__( '%1$s · %2$s logged', 'plain-language-time-tracker' ),
				esc_html( sprintf( _n( '%s entry', '%s entries', $total_entries, 'plain-language-time-tracker' ), number_format_i18n( $total_entries ) ) ),
				esc_html( pltt_format_duration( (int) $stats->total_minutes ) )
			);
		} else {
			$scope_line_hdr = esc_html( sprintf( _n( '%s entry', '%s entries', $total_entries, 'plain-language-time-tracker' ), number_format_i18n( $total_entries ) ) );
		}
		?>
		<div class="pltt-light-header">
			<div class="pltt-lh-titlerow">
				<h1><?php echo esc_html( 'summary' === $view ? __( 'Summary', 'plain-language-time-tracker' ) : __( 'Entries', 'plain-language-time-tracker' ) ); ?></h1>
			</div>
			<div class="pltt-lh-l2"><?php echo $scope_line_hdr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="pltt-lh-l3"><span class="pltt-mono"><?php echo esc_html( $range_label_hdr ); ?></span></div>
		</div>
		<div class="pltt-view-toggle">
			<a href="<?php echo esc_url( add_query_arg( 'view', 'summary', $tab_base_url ) ); ?>"
				class="button <?php echo 'summary' === $view ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Summary', 'plain-language-time-tracker' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'view', 'detailed', $tab_base_url ) ); ?>"
				class="button <?php echo 'detailed' === $view ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Entries', 'plain-language-time-tracker' ); ?>
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

				<?php
				// One Billing filter, its options taken straight from the Status
				// column (spec §4). Two dropdowns let you build "not billable AND
				// billed", which has no answer; these three states are exhaustive
				// and mutually exclusive. No "—" option: that glyph covers retainer
				// within allocation, fixed fee and internal — three unrelated things
				// the Project filter already separates.
				?>
				<div class="pltt-filter-group">
					<label for="pltt-filter-billing"><?php esc_html_e( 'Billing', 'plain-language-time-tracker' ); ?></label>
					<select name="billing" id="pltt-filter-billing">
						<option value="" <?php selected( '', $billing_status ); ?>><?php esc_html_e( 'All', 'plain-language-time-tracker' ); ?></option>
						<option value="unbilled" <?php selected( 'unbilled', $billing_status ); ?>><?php esc_html_e( 'Unbilled', 'plain-language-time-tracker' ); ?></option>
						<option value="billed" <?php selected( 'billed', $billing_status ); ?>><?php esc_html_e( 'Billed', 'plain-language-time-tracker' ); ?></option>
						<option value="not_charged" <?php selected( 'not_charged', $billing_status ); ?>><?php esc_html_e( 'Not charged', 'plain-language-time-tracker' ); ?></option>
					</select>
				</div>

				<?php
				// Clear drops the filters, not the view. Summary/Entries is
				// navigation — which page you're looking at — so clearing a filter
				// shouldn't move you to a different screen.
				$clear_url = add_query_arg(
					array(
						'page' => 'pltt-reports',
						'view' => $view,
					),
					admin_url( 'admin.php' )
				);
				?>
				<div class="pltt-filter-group pltt-filter-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filter', 'plain-language-time-tracker' ); ?></button>
					<a href="<?php echo esc_url( $clear_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'plain-language-time-tracker' ); ?></a>
				</div>
			</div>
		</div>
	</form>

	<?php
	// Reuse the map already loaded for the tag filter dropdown above (OPT-DUP8/N4),
	// falling back to a fresh load if that dropdown was skipped.
	$tag_group_map = isset( $tag_group_map ) ? $tag_group_map : PLTT_Tags::get_name_to_group_map();
	wp_add_inline_script(
		'pltt-reports',
		// SEC-L1: JSON_HEX_TAG/AMP so a "</script>" inside an admin-authored name
		// can't break out of this inline <script>.
		'var plttProjectsByClient = ' . wp_json_encode( $projects_by_client, JSON_HEX_TAG | JSON_HEX_AMP ) . ';' .
		'var plttClientNames = ' . wp_json_encode( $client_names, JSON_HEX_TAG | JSON_HEX_AMP ) . ';' .
		'var plttAllTags = ' . wp_json_encode( $all_tags, JSON_HEX_TAG | JSON_HEX_AMP ) . ';' .
		'var plttTagGroups = ' . wp_json_encode( $tag_group_map, JSON_HEX_TAG | JSON_HEX_AMP ) . ';' .
		'var plttReportStats = ' . wp_json_encode( array(
			'billableMinutes' => $stats ? (int) $stats->billable_minutes : 0,
			'billableAmount'  => $stats ? round( (float) $stats->billable_amount, 2 ) : 0,
			'prevMinutes'     => $prev_stats ? (int) $prev_stats->billable_minutes : 0,
			'prevAmount'      => $prev_stats ? round( (float) $prev_stats->billable_amount, 2 ) : 0,
		) ) . ';',
		'before'
	);
	?>

	<?php
	// Scope switch (spec §3): filtered to a single client+project → full scope
	// block (identity + figure row). Any other state (unfiltered, client-only,
	// multi) → the plain number bar; the page keeps its light header.
	$scope_project = ( $is_single_project_view && ! empty( $context_projects ) ) ? $context_projects[0] : null;
	$sb_type       = $scope_project ? pltt_get_billing_type( $scope_project ) : '';
	// Billing-aware figure set (+ optional Review & bill bar) for hourly/fixed
	// single-project scopes; null for retainer/internal → generic metric cards.
	$sp_fig = $scope_project ? pltt_build_single_project_scope_figures( $scope_project, $stats, $sb_type, $context_overage, $date_from, $date_to ) : null;
	// Show the Review & bill bar (one action) unless the select flow is already
	// active (bill=1). Backlog isn't here — it rides on figure 4's basis line.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only mode flag.
	$sp_show_bar = ( $sp_fig && ! empty( $sp_fig['bar'] ) && empty( $_GET['bill'] ) );
	?>
	<?php if ( ! empty( $context_client ) || $total_entries > 0 ) : ?>

		<?php if ( $scope_project ) :
			// Line 2: client · terms in plain language, by billing model (spec §3).
			$sb_terms = ( $context_client && ! empty( $context_client->name ) )
				? $context_client->name
				: __( 'Internal', 'plain-language-time-tracker' );
			$sb_rate = pltt_resolve_billable_rate( (int) $scope_project->client_id, (int) $scope_project->id );
			if ( 'hourly' === $sb_type && $sb_rate > 0 ) {
				/* translators: %s: hourly rate, e.g. "$100". */
				$sb_terms .= ' · ' . sprintf( __( '%s/hr', 'plain-language-time-tracker' ), pltt_format_currency_compact( $sb_rate ) );
			} elseif ( 'fixed' === $sb_type ) {
				$sb_fee     = isset( $scope_project->budget_fee ) ? (float) $scope_project->budget_fee : 0.0;
				$sb_budgmin = pltt_budgeted_minutes( $scope_project, $sb_rate );
				if ( $sb_fee > 0 && $sb_budgmin > 0 && $sb_rate > 0 ) {
					$sb_terms .= ' · ' . sprintf(
						/* translators: 1: fixed fee; 2: budgeted duration; 3: hourly rate. */
						__( '%1$s, budgeted as %2$s at %3$s/hr', 'plain-language-time-tracker' ),
						pltt_format_currency_compact( $sb_fee ),
						pltt_format_duration( $sb_budgmin ),
						pltt_format_currency_compact( $sb_rate )
					);
				} elseif ( $sb_fee > 0 ) {
					$sb_terms .= ' · ' . pltt_format_currency_compact( $sb_fee );
				}
			} elseif ( 'recurring' === $sb_type && $sb_rate > 0 ) {
				$sb_alloc = pltt_budgeted_minutes( $scope_project, $sb_rate );
				if ( $sb_alloc > 0 ) {
					$sb_period_words = array(
						'weekly'    => __( 'each week', 'plain-language-time-tracker' ),
						'monthly'   => __( 'each month', 'plain-language-time-tracker' ),
						'quarterly' => __( 'each quarter', 'plain-language-time-tracker' ),
						'yearly'    => __( 'each year', 'plain-language-time-tracker' ),
					);
					$sb_period = $sb_period_words[ $scope_project->recurring_period ?? '' ] ?? __( 'each period', 'plain-language-time-tracker' );
					$sb_terms .= ' · ' . sprintf(
						/* translators: 1: allocation duration; 2: period phrase, e.g. "each month"; 3: hourly rate. */
						__( '%1$s included %2$s at %3$s/hr', 'plain-language-time-tracker' ),
						pltt_format_duration( $sb_alloc ),
						$sb_period,
						pltt_format_currency_compact( $sb_rate )
					);
				}
			}
			?>
			<div class="pltt-scope-block<?php echo $sp_show_bar ? ' pltt-has-bar' : ''; ?>">
				<div class="pltt-scope-id">
					<div class="pltt-scope-titlerow">
						<h1 class="pltt-scope-title"><?php echo esc_html( $scope_project->name ); ?></h1>
						<?php pltt_render_billing_type_badge( $sb_type ); ?>
						<?php if ( 'archived' === ( $scope_project->status ?? '' ) ) : ?>
							<span class="pltt-badge pltt-badge-archived"><?php esc_html_e( 'Archived', 'plain-language-time-tracker' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="pltt-scope-terms"><?php echo esc_html( $sb_terms ); ?></div>
					<div class="pltt-scope-when">
						<span><?php esc_html_e( 'Showing', 'plain-language-time-tracker' ); ?></span>
						<span class="pltt-mono"><?php echo esc_html( pltt_format_date_range( $date_from, $date_to ) ); ?></span>
						<?php if ( $total_entries > 0 ) : ?>
							<span>&middot; <?php echo esc_html( sprintf( _n( '%s entry', '%s entries', $total_entries, 'plain-language-time-tracker' ), number_format_i18n( $total_entries ) ) ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<div class="pltt-summary-cards pltt-numbar">
		<?php else : ?>
			<div class="pltt-summary-cards pltt-numbar">
				<?php if ( ! empty( $context_client ) ) : ?>
					<?php include PLTT_PLUGIN_DIR . 'templates/partials/client-context-card.php'; ?>
				<?php endif; ?>
		<?php endif; ?>

			<?php if ( $sp_fig ) : ?>
				<?php // Billing-aware figures: label · value · basis. Figure 4 may be
				// muted (grey, settled/dash), amber (owed), or have no basis line. ?>
				<?php foreach ( $sp_fig['figures'] as $fig ) : ?>
					<?php // Warm tint marks a figure with work stranded outside the filter. ?>
					<div class="card<?php echo ! empty( $fig['tint'] ) ? ' pltt-numbar-tint' : ''; ?>">
						<div class="card-label"><?php echo esc_html( $fig['label'] ); ?></div>
						<div class="card-value<?php echo $fig['over'] ? ' pltt-numbar-over' : ( ! empty( $fig['muted'] ) ? ' pltt-numbar-muted' : '' ); ?>"><?php echo esc_html( $fig['value'] ); ?></div>
						<?php if ( '' !== $fig['basis'] ) : ?>
							<div class="card-secondary"><?php echo $fig['basis']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- basis built with safe inline HTML in pltt_build_single_project_scope_figures() ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php elseif ( $total_entries > 0 ) : ?>

			<!-- Card: Total Hours -->
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

			$hours_change       = pltt_pct_change_indicator( $curr_hours_mins, $prev_hours_mins );
			$hours_pct_change   = $hours_change['pct'];
			$hours_change_class = $hours_change['class'];
			$hours_change_icon  = $hours_change['icon'];
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

			$amount_change = pltt_pct_change_indicator( $curr_amount, $prev_amount );
			$pct_change    = $amount_change['pct'];
			$change_class  = $amount_change['class'];
			$change_icon   = $amount_change['icon'];
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

			<!-- Card 5: Unbilled so far — all-time outstanding, links out to Billing -->
			<?php if ( 'summary' === $view && $outstanding_total > 0 ) : ?>
			<div class="card pltt-unbilled-card">
				<div class="card-label"><?php esc_html_e( 'Unbilled so far', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value pltt-unbilled-card-value"><?php echo esc_html( pltt_format_currency( $outstanding_total ) ); ?></div>
				<div class="card-secondary">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pltt-invoicing' ) ); ?>"><?php esc_html_e( 'Go to Billing', 'plain-language-time-tracker' ); ?> &rsaquo;</a>
				</div>
			</div>
			<?php endif; ?>

							<?php endif; /* total_entries > 0 */ ?>

			</div><?php // .pltt-summary-cards ?>
			<?php if ( $scope_project ) : ?>
			</div><?php // .pltt-scope-block ?>
			<?php endif; ?>

			<?php
			// Action bar — Review & bill, attached to the block's bottom with no gap
			// (info in the block, action in the bar). Only hourly with outstanding
			// work reaches here; absent otherwise, never present-and-empty.
			if ( $sp_show_bar ) :
				$sp_bar = $sp_fig['bar'];
				?>
				<div class="pltt-bbar pltt-bbar-ready">
					<span class="pltt-bbar-dot" aria-hidden="true"></span>
					<span><strong><?php esc_html_e( 'Ready to bill', 'plain-language-time-tracker' ); ?></strong> &mdash; <span class="pltt-mono"><?php echo esc_html( $sp_bar['amount'] ); ?></span> <?php echo esc_html( $sp_bar['desc'] ); ?></span>
					<span class="pltt-bbar-spacer"></span>
					<a class="button button-primary" href="<?php echo esc_url( $sp_bar['review_url'] ); ?>"><?php esc_html_e( 'Review &amp; bill', 'plain-language-time-tracker' ); ?> &rarr;</a>
				</div>
			<?php endif; ?>

			<?php
			// The parked billing gateway + project context card used to sit here as
			// a fallback for scopes with no figure set of their own. Every project
			// type now has one — hourly and fixed dissolved theirs into the figures
			// and bar, and the retainer's multi-period span was the last gap — so
			// the fallback is gone along with both partials.
			?>

		<?php endif; /* context_client || total_entries */ ?>


	<?php
	// Volume bar chart (Hours by day/week/month). Markup shared with the Project
	// Detail report tab via templates/partials/chart-by-period.php.
	if ( 'summary' === $view && ! empty( $chart['buckets'] ) && ( $chart['max_minutes'] ?? 0 ) > 0 ) :
		include PLTT_PLUGIN_DIR . 'templates/partials/chart-by-period.php';
	endif;
	?>

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

				// Decide the Billable column ONCE across the whole result set, then
				// force it on every per-day table below. Each table has table-layout:
				// fixed, so if some days computed the column in and others out (an
				// all-retainer/internal day drops it), the day tables wouldn't line up.
				// Mirrors pltt_render_entry_table()'s own detection (null project = on).
				$billable_project_ids = array();
				foreach ( $entries as $entry ) {
					if ( ! empty( $entry->project_id ) ) {
						$billable_project_ids[] = (int) $entry->project_id;
					}
				}
				$billable_projects  = PLTT_Projects::get_multiple( array_unique( $billable_project_ids ) );
				// Client cache too — the per-day billable total below resolves rates
				// through the project → client → default cascade, and must not do it
				// with a cold cache once per entry.
				$day_client_ids = array();
				foreach ( $entries as $entry ) {
					if ( ! empty( $entry->client_id ) ) {
						$day_client_ids[] = (int) $entry->client_id;
					}
				}
				$day_clients = PLTT_Clients::get_multiple( array_unique( $day_client_ids ) );

				$show_billable_col_all = false;
				foreach ( $entries as $entry ) {
					$ep = ! empty( $entry->project_id ) && isset( $billable_projects[ (int) $entry->project_id ] )
						? $billable_projects[ (int) $entry->project_id ]
						: null;
					if ( pltt_billable_flag_applies( $ep ) ) {
						$show_billable_col_all = true;
						break;
					}
				}

				?>

				<?php
				// Covered-entry tint: entries that belong to a committed billing
				// record (single-project view only). Supplying this option also
				// retires the legacy per-entry "Inv." toggle in this view — record
				// coverage is the source of truth.
				$covered_entry_meta = ( $is_single_project_view && ! empty( $context_projects[0] ) )
					? PLTT_Billing::get_covered_entry_meta( (int) $context_projects[0]->id )
					: array();
				$covered_entry_ids = array_keys( $covered_entry_meta );

				// Billing select row: only when a bill was explicitly started via a
				// gateway (the Billing page card or the "Ready to bill" card), which set
				// bill=1 together with the correct scope range. Landing on the detailed
				// view any other way (e.g. clicking a project from Summary) must NOT
				// invoke it — that range wouldn't represent the billing scope.
				// Still gated to a single active HOURLY project (retainer/fixed bill a
				// computed number, no per-entry selection).
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only mode flag.
				$billing_mode       = ! empty( $_GET['bill'] );
				$bill_project       = ! empty( $context_projects[0] ) ? $context_projects[0] : null;
				$bill_active_single = ( $billing_mode && $is_single_project_view && $bill_project
					&& 'active' === ( $bill_project->status ?? '' ) );
				$bill_project_type  = $bill_project ? pltt_get_billing_type( $bill_project ) : '';
				// Hourly bills per selected entry (select row + bar). Recurring bills the
				// period overage as a whole — a confirm-the-number bar, no per-entry row.
				$bill_select_active  = ( $bill_active_single && 'hourly' === $bill_project_type );
				$bill_confirm_active = ( $bill_active_single && 'recurring' === $bill_project_type );

				// Record-view mode: arrived from a "View record" link (record_id set).
				// Read-only — swaps the bill bar for a "Line items" bar on the committed
				// record. Gated to the single project the record belongs to.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only mode flag.
				$record_view_id = ! empty( $_GET['record_id'] ) ? (int) $_GET['record_id'] : 0;
				$record_view    = null;
				if ( $record_view_id > 0 && $is_single_project_view && $bill_project ) {
					$maybe_record = PLTT_Billing_Records::get( $record_view_id );
					if ( $maybe_record && (int) $maybe_record->project_id === (int) $bill_project->id ) {
						$record_view = $maybe_record;
					}
				}
				?>


				<?php foreach ( $entries_by_date as $group_date => $group_entries ) :
					$date_obj = new DateTimeImmutable( $group_date, wp_timezone() );
					$is_today = $group_date === pltt_get_current_date();
					// The date IS the link (spec §7) — it opens Today at that date,
					// which is this screen's only route to the raw log and to the
					// journal lines that never became entries. No trailing "Open day
					// log" link beside it, and no day-level kebab: one action is a
					// link, not a menu.
					// The year appears only when the day isn't in the current one —
					// "June 25" reads cleaner for the common case, but a range that
					// reaches back past January would otherwise be ambiguous.
					$day_url   = pltt_get_admin_url( 'daily-log', array( 'date' => $group_date ) );
					$day_format = ( $date_obj->format( 'Y' ) === $now_dt->format( 'Y' ) ) ? 'F j' : 'F j, Y';
					$day_label = $is_today
						? __( 'Today', 'plain-language-time-tracker' )
						: $date_obj->format( $day_format );
					// Day totals sit at the right of the header (spec §2.3), where the
					// Edit button used to be. Editing an entry is the row menu's job.
					//
					// Time and billable amount — the two figures the table's own
					// columns add up to. The amount sums exactly the entries the
					// Amount column shows a figure for (billable only), so the header
					// and the rows below it can't disagree.
					$day_minutes = 0;
					$day_amount  = 0.0;
					foreach ( $group_entries as $group_entry ) {
						$day_minutes += (int) $group_entry->duration_minutes;

						if ( empty( $group_entry->billable ) || (int) $group_entry->duration_minutes <= 0 ) {
							continue;
						}
						if ( null !== $group_entry->billable_amount ) {
							// The amount frozen at verification time wins.
							$day_amount += (float) $group_entry->billable_amount;
						} else {
							$day_amount += pltt_billable_amount(
								(int) $group_entry->duration_minutes,
								pltt_resolve_billable_rate( (int) $group_entry->client_id, (int) $group_entry->project_id, $day_clients, $billable_projects )
							);
						}
					}
					?>
					<div class="pltt-date-group">
						<div class="pltt-date-group-header">
							<span class="pltt-date-group-title">
								<a class="pltt-day-link" href="<?php echo esc_url( $day_url ); ?>"><?php echo esc_html( $day_label ); ?></a>
								<span class="pltt-day-dow"><?php echo esc_html( $date_obj->format( 'l' ) ); ?></span>
							</span>
							<span class="pltt-date-group-meta">
								<span class="pltt-mono"><?php echo esc_html( pltt_format_duration( $day_minutes ) ); ?></span>
								<?php if ( $day_amount > 0 ) : ?>
									&middot;
									<span class="pltt-mono"><?php echo esc_html( pltt_format_currency( $day_amount ) ); ?></span>
								<?php endif; ?>
							</span>
						</div>
						<?php
						$entry_table_opts = array(
							'show_amount'        => true,
							'inline_edit'        => true,
							'all_tags'           => $all_tags,
							// Same column set on every day table so they line up.
							'force_billable_col' => $show_billable_col_all,
						);
						if ( $is_single_project_view ) {
							// Covered mode: tint record-covered rows + mark them with the
							// record pointer; drops the Inv. column.
							$entry_table_opts['covered_entry_ids']  = $covered_entry_ids;
							$entry_table_opts['covered_entry_meta'] = $covered_entry_meta;
						}
						if ( $bill_select_active ) {
							// The "Include in bill" select row — pick entries to bill.
							// The table class shifts the positional column widths one
							// column right to make room for it (see reports.css).
							$entry_table_opts['billing_select'] = true;
							$entry_table_opts['table_class']    = 'pltt-has-billselect';
						}
						pltt_render_entry_table( $group_entries, $entry_table_opts );
						?>
					</div>
				<?php endforeach; ?>


				<?php
				$base_url = add_query_arg( 'view', $view, $tab_base_url );
				pltt_render_pagination( $paged, $total_pages, $total_entries, $base_url, 'entry', 'entries' );

				// Docked bill bar + Record-bill modal (single project). Hourly tallies
				// the select row above; recurring confirms the period overage as a
				// whole. billing-select-bar.php picks its mode from the project type.
				if ( $bill_select_active || $bill_confirm_active ) {
					$project = $context_projects[0];
					include PLTT_PLUGIN_DIR . 'templates/partials/billing-select-bar.php';
				} elseif ( $record_view ) {
					// Read-only "View record" landing: a Line-items bar over the record.
					$rv        = pltt_build_billing_record_view( $record_view );
					$dialog_id = 'pltt-recordview-' . (int) $record_view->id;
					include PLTT_PLUGIN_DIR . 'templates/partials/billing-record-bar.php';
				}
				?>


			<?php else : ?>
				<p class="description" style="padding: 20px; text-align: center;">
					<?php esc_html_e( 'No entries found for the selected filters.', 'plain-language-time-tracker' ); ?>
				</p>
			<?php endif; ?>

		<?php endif; ?>

	</div>
</div>

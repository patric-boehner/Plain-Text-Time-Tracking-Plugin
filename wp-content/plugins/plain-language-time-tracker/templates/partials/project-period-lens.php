<?php
/**
 * Date filter for project detail (recurring projects only).
 *
 * A period picker, not a chart control: choosing a period re-scopes the figure
 * cards, the "Showing" line and the chart together. It therefore sits in the title
 * row with the other page-level scope controls.
 *
 * Renders the shared month picker (templates/partials/month-picker.php) — the same
 * control History uses — so "pick a month" looks and behaves the same in both
 * places. Options here are plain links carrying chart_scope/chart_period, because a
 * retainer's periods come from the project's own recurring cycle rather than an
 * arbitrary from/to range.
 *
 * "All time" is the pinned lead option rather than a separate Full/By-month toggle:
 * one control answers "which period", instead of two that have to agree.
 *
 * Absent for every other billing type and for projects with no entries:
 * pltt_resolve_project_chart_window() only sets show_control with a real span.
 *
 * Expects in scope: $project, $window, $is_period, $period_options.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $window ) || empty( $window['show_control'] ) ) {
	return;
}

$pl_base_args = array(
	'page'       => 'pltt-projects',
	'action'     => 'view',
	'project_id' => (int) $project->id,
);

/**
 * URL for a given scope/anchor.
 *
 * @param array  $base   Base query args.
 * @param string $scope  'full' or 'period'.
 * @param string $anchor Period anchor (Y-m-d), or '' for none.
 * @return string
 */
$pl_url = function ( $base, $scope, $anchor = '' ) {
	$args = array_merge( $base, array( 'chart_scope' => $scope ) );
	if ( '' !== $anchor ) {
		$args['chart_period'] = $anchor;
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
};

// Group the periods by year for the picker's year switcher. build_period_options()
// hands them back newest-first, so the years come out newest-first too.
$mp_years = array();
foreach ( $period_options as $pl_opt ) {
	$pl_year = $pl_opt['year'];
	if ( ! isset( $mp_years[ $pl_year ] ) ) {
		$mp_years[ $pl_year ] = array();
	}
	$mp_years[ $pl_year ][] = array(
		// Inside a year group the year itself is redundant — the switcher states it.
		'label'  => $pl_opt['short_label'],
		'url'    => $pl_url( $pl_base_args, 'period', $pl_opt['anchor'] ),
		'active' => ( $is_period && $pl_opt['is_current'] ),
	);
}

// Which year opens first: the selected one, else the most recent.
$mp_active_year = '';
if ( $is_period && ! empty( $window['anchor'] ) ) {
	$mp_active_year = substr( (string) $window['anchor'], 0, 4 );
}
if ( '' === $mp_active_year || ! isset( $mp_years[ $mp_active_year ] ) ) {
	$mp_active_year = (string) array_key_first( $mp_years );
}

$mp_label = $is_period ? $window['period_label'] : __( 'All time', 'plain-language-time-tracker' );
$mp_aria  = __( 'Period navigation', 'plain-language-time-tracker' );
$mp_lead  = array(
	'label'  => __( 'All time', 'plain-language-time-tracker' ),
	'url'    => $pl_url( $pl_base_args, 'full' ),
	'active' => ! $is_period,
);

// Stepping only means something within a period. On "All time" both arrows render
// disabled, so the control keeps its width instead of shifting under the pointer.
$mp_prev = ( $is_period && ! empty( $window['prev_anchor'] ) )
	? $pl_url( $pl_base_args, 'period', $window['prev_anchor'] )
	: '';
$mp_next = ( $is_period && ! empty( $window['next_anchor'] ) )
	? $pl_url( $pl_base_args, 'period', $window['next_anchor'] )
	: '';

$mp_reset = ( $is_period && empty( $window['is_latest'] ) )
	? array(
		'url'   => $pl_url( $pl_base_args, 'period' ),
		'label' => __( 'Latest period', 'plain-language-time-tracker' ),
	)
	: null;

include PLTT_PLUGIN_DIR . 'templates/partials/month-picker.php';

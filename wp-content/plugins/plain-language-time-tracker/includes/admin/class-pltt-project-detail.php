<?php
/**
 * Project detail screen controller.
 *
 * Renders the full-page project view (Report + Settings tabs) reached via
 * admin.php?page=pltt-projects&action=view&project_id=N&tab=report|settings.
 *
 * Phase 1: page scaffold, ARIA tabs, and a Settings tab that reuses the existing
 * project save path (admin_post_pltt_update_project). The Report tab is a
 * placeholder until Phase 2/3 land the lifetime aggregations.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the project detail screen.
 */
class PLTT_Project_Detail {

	/**
	 * Dispatch the detail screen — a lifetime, read-only report.
	 *
	 * Project settings are edited via the modal on the Projects list, not here.
	 * Capability is already checked by the caller (PLTT_Admin::render_projects_page).
	 * Bails cleanly to a not-found view on a missing/invalid project — no wp_die,
	 * so the admin chrome and a way back to the list are preserved.
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0;
		$project    = $project_id ? PLTT_Projects::get( $project_id ) : null;

		if ( ! $project ) {
			self::render_not_found();
			return;
		}

		$stats        = PLTT_Entries::get_stats( array( 'project_id' => $project_id ) );
		$billing_type = pltt_get_billing_type( $project );
		$list_url     = admin_url( 'admin.php?page=pltt-projects' );
		$client       = ! empty( $project->client_id ) ? PLTT_Clients::get( (int) $project->client_id ) : null;

		// Period lens (recurring projects only): scope + period drive the cards,
		// the "Where the time went" bars, and the volume chart. Read-only GET nav,
		// no nonce — mirrors the Reports date nav.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$req_scope  = isset( $_GET['chart_scope'] ) ? sanitize_key( wp_unslash( $_GET['chart_scope'] ) ) : '';
		$req_anchor = isset( $_GET['chart_period'] ) ? sanitize_text_field( wp_unslash( $_GET['chart_period'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$window = pltt_resolve_project_chart_window(
			$billing_type,
			isset( $project->recurring_period ) ? $project->recurring_period : '',
			$stats->first_entry_date ?? '',
			$stats->last_entry_date ?? '',
			$req_scope,
			$req_anchor
		);

		// In a period view the subhead reflects the selected period, not the
		// lifetime span, and its entry count is that period's.
		$is_period     = ! empty( $window['is_period'] );
		$subhead_stats = $is_period
			? PLTT_Entries::get_stats( array( 'project_id' => $project_id, 'date_from' => $window['from'], 'date_to' => $window['to'] ) )
			: $stats;
		$subhead = self::build_subhead( $project, $subhead_stats, $billing_type, $window, $client );

		// Pass $subhead_stats through as the windowed stats so build() reuses them
		// instead of re-running the same windowed get_stats() (OPT-N-A).
		$report = PLTT_Project_Report::build( $project_id, $project, null, $stats, $window, $subhead_stats );

		include PLTT_PLUGIN_DIR . 'templates/project-detail.php';
	}

	/**
	 * Build the subhead line shown under the H1.
	 *
	 * Format: "{Client} · {first–last span} · {N entries}". The lead piece is the
	 * client name; the billing type is intentionally NOT repeated here because it
	 * already shows as a badge beside the H1. Clientless projects fall back to the
	 * type label so the line still leads with something. Pieces with no data are
	 * omitted (e.g. a project with no entries shows just the lead piece).
	 *
	 * @param object      $project      Project row.
	 * @param object|null $stats        Aggregate stats from PLTT_Entries::get_stats()
	 *                                  (windowed to the active period when one is set).
	 * @param string      $billing_type Resolved billing type.
	 * @param array|null  $window       Active period window; when scope is 'period'
	 *                                  the date piece becomes the period label.
	 * @param object|null $client       Owning client row (for the lead name).
	 * @return string Middot-joined, already-escaped-safe plain text (caller escapes).
	 */
	private static function build_subhead( $project, $stats, $billing_type, $window = null, $client = null ) {
		$type_labels = array(
			'hourly'    => __( 'Hourly', 'plain-language-time-tracker' ),
			'recurring' => __( 'Monthly', 'plain-language-time-tracker' ),
			'fixed'     => __( 'Fixed Budget', 'plain-language-time-tracker' ),
			'none'      => __( 'Internal', 'plain-language-time-tracker' ),
		);

		// Lead with the client name; fall back to the type label only when the
		// project has no client (the type badge by the H1 already names the type).
		$lead  = ( $client && ! empty( $client->name ) )
			? $client->name
			: ( $type_labels[ $billing_type ] ?? $type_labels['hourly'] );
		$parts = array( $lead );

		if ( ! empty( $window['is_period'] ) ) {
			// Period view: show the period itself (e.g. "June 2026"), not the span.
			$parts[] = $window['period_label'];
		} else {
			$first = $stats->first_entry_date ?? '';
			$last  = $stats->last_entry_date ?? '';
			if ( $first && $last ) {
				$first_fmt = date_i18n( 'M j, Y', strtotime( $first ) );
				$last_fmt  = date_i18n( 'M j, Y', strtotime( $last ) );
				$parts[]   = ( $first === $last ) ? $first_fmt : $first_fmt . ' – ' . $last_fmt;
			}
		}

		$count = isset( $stats->total_count ) ? (int) $stats->total_count : 0;
		if ( $count > 0 ) {
			/* translators: %s: number of time entries. */
			$parts[] = sprintf( _n( '%s entry', '%s entries', $count, 'plain-language-time-tracker' ), number_format_i18n( $count ) );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Render a clean "project not found" view inside the admin chrome.
	 */
	private static function render_not_found() {
		$list_url = admin_url( 'admin.php?page=pltt-projects' );
		?>
		<div class="wrap pltt-wrap pltt-project-detail">
			<div class="pltt-header">
				<h1><?php esc_html_e( 'Project not found', 'plain-language-time-tracker' ); ?></h1>
			</div>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'That project could not be found. It may have been deleted.', 'plain-language-time-tracker' ); ?></p>
			</div>
			<p>
				<a href="<?php echo esc_url( $list_url ); ?>" class="button">
					<?php esc_html_e( '← Back to Projects', 'plain-language-time-tracker' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Build the URL to a project's detail (report) page.
	 *
	 * @param int $project_id Project ID.
	 * @return string Escaped-safe admin URL (caller escapes).
	 */
	public static function get_url( $project_id ) {
		return add_query_arg(
			array(
				'page'       => 'pltt-projects',
				'action'     => 'view',
				'project_id' => (int) $project_id,
			),
			admin_url( 'admin.php' )
		);
	}
}

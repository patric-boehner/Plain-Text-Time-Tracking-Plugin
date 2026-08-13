<?php
/**
 * Project detail page — a lifetime, read-only report.
 *
 * Project settings live in the edit modal on the Projects list, not here; this
 * page is just the report. Rendered by PLTT_Project_Detail::render(). Expects
 * in scope:
 *   $project      object       Project row.
 *   $stats        object|null  Aggregate stats (PLTT_Entries::get_stats).
 *   $report       array        Report data (PLTT_Project_Report::build).
 *   $billing_type string       Resolved billing type.
 *   $subhead      string       Subhead line under the H1.
 *   $list_url     string       URL back to the Projects list.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_archived = ( 'archived' === $project->status );
?>

<div class="wrap pltt-wrap pltt-project-detail<?php echo $is_archived ? ' pltt-detail-archived' : ''; ?>">

	<div class="pltt-header pltt-detail-header pltt-detail-actionsbar">
		<?php
		// Identity (name, badge, terms, span) now lives in the scope block below.
		// This screen-reader-only H1 keeps the page named for assistive tech and
		// gives WordPress an anchor so admin notices stay put near the top.
		?>
		<h1 class="screen-reader-text"><?php echo esc_html( $project->name ); ?></h1>

		<?php
		// Left: the way out. Back points up and out of this screen rather than acting
		// on it, so it sits where the title would be, not among the page's controls.
		?>
		<div class="pltt-header-back">
			<a href="<?php echo esc_url( $list_url ); ?>" class="button">
				&larr; <?php esc_html_e( 'Back to Projects', 'plain-language-time-tracker' ); ?>
			</a>
		</div>

		<?php
		// Right: the controls that act on this page — date filter, then Settings.
		// Settings opens the same modal the Projects list uses; the button itself is
		// the [data-project-id] carrier the shared handler reads, so the attribute
		// names must match the list's <tr>.
		$pltt_unbilled_minutes = isset( $stats->unbilled_billable_minutes ) ? (int) $stats->unbilled_billable_minutes : 0;
		$pltt_entry_count      = isset( $stats->total_count ) ? (int) $stats->total_count : 0;
		?>
		<div class="pltt-header-actions">
			<?php
			// Recurring projects only; the partial renders nothing for other types.
			include PLTT_PLUGIN_DIR . 'templates/partials/project-period-lens.php';
			?>
			<button type="button"
				class="button pltt-edit-project"
				data-project-id="<?php echo esc_attr( $project->id ); ?>"
				data-name="<?php echo esc_attr( $project->name ); ?>"
				data-client-id="<?php echo esc_attr( $project->client_id ); ?>"
				data-status="<?php echo esc_attr( $project->status ); ?>"
				data-rate="<?php echo esc_attr( $project->hourly_rate ?? '' ); ?>"
				data-billability-default="<?php echo esc_attr( $project->billability_default ?? '1' ); ?>"
				data-recurring-period="<?php echo esc_attr( $project->recurring_period ?? '' ); ?>"
				data-billing-type="<?php echo esc_attr( $billing_type ); ?>"
				data-budget-hours="<?php echo esc_attr( $project->budget_hours ?? '' ); ?>"
				data-budget-fee="<?php echo esc_attr( $project->budget_fee ?? '' ); ?>"
				data-entry-count="<?php echo esc_attr( $pltt_entry_count ); ?>"
				data-unbilled-minutes="<?php echo esc_attr( $pltt_unbilled_minutes ); ?>">
				<?php esc_html_e( 'Settings', 'plain-language-time-tracker' ); ?>
			</button>
		</div>

		<?php
		// Billing-commit results land back here. Notices stay inside the header
		// wrapper near the H1 (WordPress relocates ones that drift too far).
		// A settings save from this page redirects back here (wp_get_referer), so
		// the project_* results have to be reportable here too, not only on the list.
		pltt_render_admin_notices(
			array(
				'billed'          => __( 'Billing recorded.', 'plain-language-time-tracker' ),
				'project_updated' => __( 'Project updated successfully.', 'plain-language-time-tracker' ),
			),
			array(
				'nothing_to_bill' => __( 'Nothing outstanding to bill for that scope.', 'plain-language-time-tracker' ),
				'invalid_project' => __( 'That project could not be found.', 'plain-language-time-tracker' ),
				'db_insert_failed' => __( 'Could not save the billing record. Please try again.', 'plain-language-time-tracker' ),
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
	</div>

	<?php include PLTT_PLUGIN_DIR . 'templates/partials/project-detail-report.php'; ?>

	<?php
	// Same settings form as the Projects list — the Settings button above is one of
	// its .pltt-edit-project triggers.
	include PLTT_PLUGIN_DIR . 'templates/partials/project-modal.php';
	?>
</div>

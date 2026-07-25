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

		<div class="pltt-header-actions">
			<a href="<?php echo esc_url( $list_url ); ?>" class="button">
				&larr; <?php esc_html_e( 'Back to Projects', 'plain-language-time-tracker' ); ?>
			</a>
		</div>

		<?php
		// Billing-commit results land back here. Notices stay inside the header
		// wrapper near the H1 (WordPress relocates ones that drift too far).
		pltt_render_admin_notices(
			array(
				'billed' => __( 'Billing recorded.', 'plain-language-time-tracker' ),
			),
			array(
				'nothing_to_bill' => __( 'Nothing outstanding to bill for that scope.', 'plain-language-time-tracker' ),
				'invalid_project' => __( 'That project could not be found.', 'plain-language-time-tracker' ),
				'db_insert_failed' => __( 'Could not save the billing record. Please try again.', 'plain-language-time-tracker' ),
			)
		);
		?>
	</div>

	<?php include PLTT_PLUGIN_DIR . 'templates/partials/project-detail-report.php'; ?>
</div>

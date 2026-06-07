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

	<div class="pltt-header pltt-detail-header">
		<div class="pltt-detail-titlewrap">
			<div class="pltt-detail-title">
				<h1><?php echo esc_html( $project->name ); ?></h1>
				<?php pltt_render_billing_type_badge( $billing_type ); ?>
				<?php if ( $is_archived ) : ?>
					<span class="pltt-badge pltt-badge-archived"><?php esc_html_e( 'Archived', 'plain-language-time-tracker' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="pltt-detail-subhead"><?php echo esc_html( $subhead ); ?></p>
		</div>

		<div class="pltt-header-actions">
			<a href="<?php echo esc_url( $list_url ); ?>" class="button">
				&larr; <?php esc_html_e( 'Back to Projects', 'plain-language-time-tracker' ); ?>
			</a>
		</div>
	</div>

	<?php include PLTT_PLUGIN_DIR . 'templates/partials/project-detail-report.php'; ?>
</div>

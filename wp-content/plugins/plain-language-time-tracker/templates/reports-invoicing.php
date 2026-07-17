<?php
/**
 * Billing — two views under one workspace, switched by a tab toggle:
 *
 *   ready   — outstanding work grouped by client (the "Review & bill" cards),
 *             with this-month activity summary cards on top. Selection + commit
 *             still happen inside the detailed entries view; this page lists no
 *             entries, just who owes you.
 *   history — the frozen ledger of committed records: filtered (date / client /
 *             type), paginated, with range-scoped summary cards. "View record"
 *             reopens the copyable line items; no sent/paid tracking.
 *
 * Rendered by PLTT_Admin::render_invoicing_page(). Vars in scope:
 *   always  — $view ('ready' | 'history')
 *   ready   — $queue (get_invoicing_queue), $mtd (get_billed_totals, this month)
 *   history — $log (get_invoiced_log), $all_clients, $date_from, $date_to,
 *             $h_client_id, $h_type
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$reports_base = admin_url( 'admin.php' );
$ready_url    = add_query_arg( array( 'page' => 'pltt-invoicing', 'view' => 'ready' ), $reports_base );
$history_url  = add_query_arg( array( 'page' => 'pltt-invoicing', 'view' => 'history' ), $reports_base );
?>

<div class="wrap pltt-wrap pltt-billing-ledger">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Billing', 'plain-language-time-tracker' ); ?></h1>
		<div class="pltt-view-toggle">
			<a href="<?php echo esc_url( $ready_url ); ?>"
				class="button <?php echo 'ready' === $view ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Ready to bill', 'plain-language-time-tracker' ); ?>
			</a>
			<a href="<?php echo esc_url( $history_url ); ?>"
				class="button <?php echo 'history' === $view ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Billed history', 'plain-language-time-tracker' ); ?>
			</a>
		</div>
	</div>

	<?php
	if ( 'history' === $view ) {
		include PLTT_PLUGIN_DIR . 'templates/partials/billing-history.php';
	} else {
		include PLTT_PLUGIN_DIR . 'templates/partials/billing-ready.php';
	}
	?>
</div>

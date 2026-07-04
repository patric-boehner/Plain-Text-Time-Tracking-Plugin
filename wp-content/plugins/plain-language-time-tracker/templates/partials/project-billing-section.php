<?php
/**
 * Project billing section — the "Review & bill" entry point + billing history,
 * rendered inside the Reports single-project card (detailed view only).
 *
 * "Review & bill" now opens the INLINE billing surface (?billing=1 on this same
 * detailed view) rather than a modal — see templates/partials/billing-panel.php.
 * This section just shows the outstanding total(s), the entry link, and the
 * lifetime billing-history ledger (collapsed). Renders nothing when the project
 * has neither.
 *
 * Outstanding scopes are driven by the billing MODEL, not the report's date
 * filter: hourly = all-time uncovered, retainer = each open overage period.
 *
 * Expects in scope (set by project-context-card.php):
 *   $project        — the single project object.
 *   $context_client — owning client object (may be empty).
 *   $filter_args    — active filter args (used only to carry the browse range into
 *                     billing mode so exiting returns to it).
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $project ) ) {
	return;
}

// Active projects only have something live to bill; an archived project keeps its
// history but loses the Review & bill action. Scope is the model's outstanding
// total — NOT the report's date range.
$ready_scopes = ( 'active' === ( $project->status ?? '' ) )
	? PLTT_Billing::get_ready_to_invoice( $project )
	: array();

$billing_history = PLTT_Billing::get_for_project_history( (int) $project->id );

// No outstanding work and no record of past billing — render no footprint.
if ( empty( $ready_scopes ) && empty( $billing_history ) ) {
	return;
}

?>
<div class="pltt-pcc-billing">
	<?php if ( ! empty( $ready_scopes ) ) : ?>
		<?php foreach ( $ready_scopes as $scope ) : ?>
			<?php
			// Label pairs with the amount on one line. Retainer scopes name their
			// period (there can be several); hourly is the single running total.
			$label = ( 'retainer_overage' === $scope['billing_type'] )
				? sprintf(
					/* translators: %s: billing period label, e.g. "June 2026". */
					__( '%s overage', 'plain-language-time-tracker' ),
					$scope['period_label']
				)
				: __( 'To bill', 'plain-language-time-tracker' );
			?>
			<div class="pltt-pcc-bill-scope">
				<div class="pltt-pcc-bill-head">
					<span class="pltt-pcc-bill-label"><?php echo esc_html( $label ); ?></span>
					<span class="pltt-pcc-bill-amount"><?php echo esc_html( pltt_format_currency( $scope['unbilled'] ) ); ?></span>
				</div>
			</div>
		<?php endforeach; ?>
		<?php // Insights is read-only; the action navigates to the Billing page. ?>
		<a class="button pltt-pcc-bill-review" href="<?php echo esc_url( admin_url( 'admin.php?page=pltt-invoicing' ) ); ?>">
			<?php esc_html_e( 'Review &amp; bill on Billing', 'plain-language-time-tracker' ); ?> &rarr;
		</a>
	<?php elseif ( ! empty( $billed_period ) ) : ?>
		<?php // Settled: the shown retainer period has a committed record (set by project-context-card.php). ?>
		<p class="pltt-pcc-bill-billed">
			<span class="dashicons dashicons-yes" aria-hidden="true"></span>
			<?php
			printf(
				/* translators: 1: billed amount; 2: period label, e.g. "June 2026". */
				esc_html__( 'Billed %1$s · %2$s', 'plain-language-time-tracker' ),
				esc_html( pltt_format_currency( $billed_period['amount'] ) ),
				esc_html( $billed_period['label'] )
			);
			?>
		</p>
	<?php elseif ( ! empty( $billing_history ) ) : ?>
		<p class="pltt-pcc-billing-caughtup">
			<span class="dashicons dashicons-yes" aria-hidden="true"></span>
			<?php esc_html_e( 'Nothing to invoice right now.', 'plain-language-time-tracker' ); ?>
		</p>
	<?php endif; ?>

	<?php
	if ( ! empty( $billing_history ) ) :
		// A catch-all project can accrue years of records — don't list them here.
		// Link to the full ledger on the project's report page instead.
		$history_count = count( $billing_history );
		$history_url   = add_query_arg( 'tab', 'report', PLTT_Project_Detail::get_url( (int) $project->id ) ) . '#pltt-billing-history';
		?>
		<p class="pltt-pcc-billing-history-link">
			<a href="<?php echo esc_url( $history_url ); ?>">
				<?php
				/* translators: %d: number of past billing records. */
				echo esc_html( sprintf( _n( '%d previous invoice', '%d previous invoices', $history_count, 'plain-language-time-tracker' ), $history_count ) );
				?>
			</a>
		</p>
	<?php endif; ?>
</div>

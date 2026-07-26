<?php
/**
 * Billing select bar + commit modal for the Insights detailed view.
 *
 * Shown on the single-project detailed view for an HOURLY project. The entry
 * table renders the "Include in bill" select row (billing_select); this adds the
 * docked action bar that tallies the current selection and the Record-bill modal
 * it opens. Selection lives on the entries (the select row) — the modal is
 * commit-only: amount (lower to absorb) + description. Committing posts
 * pltt_commit_billing with the checked entries as included_entry_ids, wired in
 * assets/js/billing-select.js.
 *
 * Expects in scope:
 *   $project        — the single project object.
 *   $context_client — owning client (may be empty).
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Two modes, by project type:
//   hourly    → per-entry select; the bar tallies the checked rows (starts empty).
//   recurring → confirm-the-number; the bar shows the period's overage and bills
//               the period as a whole (no per-entry picking). The period comes from
//               the current range start (the retainer card's "Review & bill" opens
//               the view on period_start..period_end).
$pltt_bill_confirm = ( 'recurring' === pltt_get_billing_type( $project ) );
if ( $pltt_bill_confirm ) {
	$pltt_bill_period = isset( $date_from ) ? $date_from : null;
	$scope            = $pltt_bill_period ? PLTT_Billing::get_scope( $project, 'retainer_overage', $pltt_bill_period ) : null;
} else {
	$pltt_bill_period = '';
	$scope            = PLTT_Billing::get_scope( $project, 'hourly', null );
}
if ( empty( $scope ) ) {
	return;
}

$client_name = ! empty( $context_client ) ? $context_client->name : '';

// Scope view-model: the default description plus the structured list / AI prompt,
// shared with the "Line items" copy dialog (billing-copy-dialog.php) below.
$v                   = pltt_build_billing_scope_view( $scope, $client_name );
$default_description = $v['default_desc'];
$remainder           = number_format( (float) $scope['unbilled'], 2, '.', '' );
?>

<div class="pltt-billsel-bar"<?php echo $pltt_bill_confirm ? ' data-confirm' : ' hidden'; ?> aria-live="polite">
	<span class="pltt-billsel-summary">
		<?php if ( $pltt_bill_confirm ) : ?>
			<strong class="pltt-billsel-total"><?php echo esc_html( pltt_format_currency( (float) $scope['unbilled'] ) ); ?></strong>
			<?php esc_html_e( 'over allocation', 'plain-language-time-tracker' ); ?>
			<?php if ( ! empty( $v['date_range'] ) ) : ?> · <?php echo esc_html( $v['date_range'] ); ?><?php endif; ?>
		<?php else : ?>
			<strong class="pltt-billsel-count">0</strong> <?php esc_html_e( 'entries selected', 'plain-language-time-tracker' ); ?>
			· <span class="pltt-billsel-total">$0.00</span>
		<?php endif; ?>
	</span>
	<span class="pltt-billsel-spacer"></span>
	<button type="button" class="button pltt-billsel-lineitems" data-lineitems-dialog="pltt-billcopy-<?php echo esc_attr( $v['uid'] ); ?>">
		<?php esc_html_e( 'Line items…', 'plain-language-time-tracker' ); ?>
	</button>
	<button type="button" class="button button-primary pltt-billsel-open" data-open-billsel>
		<?php echo $pltt_bill_confirm ? esc_html__( 'Bill overage', 'plain-language-time-tracker' ) : esc_html__( 'Bill selected', 'plain-language-time-tracker' ); ?> &rarr;
	</button>
</div>

<dialog id="pltt-billsel-dialog" class="pltt-bill-dialog" closedby="any" aria-labelledby="pltt-billsel-title">
	<button type="button" class="pltt-modal-x" data-close aria-label="<?php esc_attr_e( 'Close', 'plain-language-time-tracker' ); ?>">&times;</button>
	<form class="pltt-billsel-form">
		<input type="hidden" name="project_id" value="<?php echo esc_attr( (int) $project->id ); ?>">
		<input type="hidden" name="billing_type" value="<?php echo $pltt_bill_confirm ? 'retainer_overage' : 'hourly'; ?>">
		<input type="hidden" name="period" value="<?php echo esc_attr( (string) $pltt_bill_period ); ?>">
		<input type="hidden" name="date_from" value="">
		<input type="hidden" name="date_to" value="">

		<h2 id="pltt-billsel-title" class="pltt-bill-dialog-title"><?php esc_html_e( 'Record Bill', 'plain-language-time-tracker' ); ?></h2>
		<p class="pltt-bill-dialog-proj"><?php echo esc_html( '' !== $client_name ? $client_name . ' · ' . $project->name : $project->name ); ?></p>
		<?php if ( ! empty( $v['date_range'] ) ) : ?>
			<?php // Date range, formatted per project type by pltt_build_billing_scope_view(): retainer = its period label; hourly = the span of the entries. ?>
			<p class="pltt-bill-dialog-range">
				<?php echo esc_html( $v['date_range'] ); ?>
			</p>
		<?php endif; ?>
		<p class="pltt-bill-dialog-sub">
			<?php if ( $pltt_bill_confirm ) : ?>
				<?php echo esc_html( $v['derivation'] ); ?>
			<?php else : ?>
				<strong class="pltt-billsel-count">0</strong> <?php esc_html_e( 'entries selected', 'plain-language-time-tracker' ); ?>
				· <span class="pltt-billsel-calc">$0.00</span>
			<?php endif; ?>
		</p>

		<div class="pltt-billing-amount-row">
			<label class="pltt-billing-amount-label" for="pltt-billsel-amount">
				<?php esc_html_e( 'Amount to bill', 'plain-language-time-tracker' ); ?>
			</label>
			<div class="pltt-billing-amount-field">
				<span class="pltt-billing-currency">$</span>
				<input type="number" id="pltt-billsel-amount" name="billed_amount" step="0.01" min="0" value="<?php echo esc_attr( $remainder ); ?>" class="pltt-billing-amount-input pltt-billsel-amount">
			</div>
			<p class="pltt-billing-hint">
				<?php esc_html_e( 'Lower the amount to absorb part of the charge; the difference is recorded as absorbed.', 'plain-language-time-tracker' ); ?>
			</p>
		</div>

		<?php // When the invoice went out — back-date it so the record lands in the month it was billed. ?>
		<div class="pltt-billing-amount-row">
			<label class="pltt-billing-amount-label" for="pltt-billsel-date">
				<?php esc_html_e( 'Invoice date', 'plain-language-time-tracker' ); ?>
			</label>
			<div class="pltt-billing-amount-field">
				<input type="date" id="pltt-billsel-date" name="marked_at" value="<?php echo esc_attr( pltt_get_current_date() ); ?>" class="pltt-billing-date-input">
			</div>
			<p class="pltt-billing-hint">
				<?php esc_html_e( 'Defaults to today. Back-date it when recording an invoice you already sent.', 'plain-language-time-tracker' ); ?>
			</p>
		</div>

		<?php
		// The invoice text / line items live in the separate "Line items" modal.
		// This modal just commits the record; it stores a deterministic default
		// label (shown in the ledger) via this hidden field.
		?>
		<input type="hidden" name="description" value="<?php echo esc_attr( $default_description ); ?>">

		<p class="pltt-billing-hint pltt-billsel-lineitems-hint">
			<?php esc_html_e( 'Need the invoice text? Use “Line items” on the bar to copy it.', 'plain-language-time-tracker' ); ?>
		</p>

		<p class="pltt-billing-error" role="alert" hidden></p>

		<div class="pltt-billing-actions">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Record bill', 'plain-language-time-tracker' ); ?> <span class="pltt-billsel-submit-amt"></span>
			</button>
			<button type="button" class="pltt-billing-cancel" data-close><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</div>
	</form>
</dialog>

<?php // "Line items" copy modal (Default / Structured + AI prompt) — peer to Record bill. ?>
<?php include PLTT_PLUGIN_DIR . 'templates/partials/billing-copy-dialog.php'; ?>

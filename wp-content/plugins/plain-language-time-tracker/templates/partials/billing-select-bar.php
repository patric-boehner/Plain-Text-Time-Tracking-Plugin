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
 *   $date_from      — range start ('Y-m-d'); the span the bill covers.
 *   $date_to        — range end ('Y-m-d').
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
// Normalised locals — the Overview always supplies both bounds (it defaults to
// month-to-date), but this partial has always tolerated them being unset.
$pltt_from = isset( $date_from ) && $date_from ? (string) $date_from : '';
$pltt_to   = isset( $date_to ) && $date_to ? (string) $date_to : '';

$pltt_bill_confirm = ( 'recurring' === pltt_get_billing_type( $project ) );
if ( $pltt_bill_confirm ) {
	$pltt_bill_period = $pltt_from ? $pltt_from : null;
	$scope            = $pltt_bill_period ? PLTT_Billing::get_scope( $project, 'retainer_overage', $pltt_bill_period ) : null;
} else {
	// Scoped to the range on screen (Rule 3). This used to ask for the all-time
	// scope, so the bar's total, the prefilled amount and the modal's "Billing
	// <span>" all described every unbilled entry ever while the rows you could
	// actually tick were the filtered ones — four spans on one screen, and only
	// the ticked boxes constrained the charge. One span now, stated on the bar.
	$pltt_bill_period = '';
	$scope            = PLTT_Billing::get_scope( $project, 'hourly', null, $pltt_from, $pltt_to );
}
if ( empty( $scope ) ) {
	return;
}

$client_name = ! empty( $context_client ) ? $context_client->name : '';

// Billable rows in this range that didn't fit on the list, and so have no
// checkbox. Counted against what actually rendered rather than assumed from the
// cap, because ineligible rows (unverified, already covered) take slots too.
// Zero in every ordinary case — but if it ever isn't, the bar says so, because a
// silent version of exactly this is what let a bill cover only the first page.
//
// There is no widen control here. Review & bill already opens everything
// outstanding, and the one place that offers to widen is figure 4's backlog line
// in the scope block above — a second control for the same thing read as
// duplication, which it was.
$pltt_offlist = 0;
if ( ! $pltt_bill_confirm ) {
	$pltt_rendered = array();
	foreach ( ( isset( $entries ) && is_array( $entries ) ? $entries : array() ) as $pltt_row ) {
		$pltt_rendered[ (int) $pltt_row->id ] = true;
	}
	foreach ( $scope['entries'] as $pltt_e ) {
		if ( ! isset( $pltt_rendered[ (int) $pltt_e->id ] ) ) {
			$pltt_offlist++;
		}
	}
}

// Scope view-model: the default description plus the AI prompt variants,
// shared with the "Line items" copy dialog (billing-copy-dialog.php) below.
$v                   = pltt_build_billing_scope_view( $scope, $client_name );
$default_description = $v['default_desc'];
$remainder           = number_format( (float) $scope['unbilled'], 2, '.', '' );
?>

<?php
// Not hidden any more. The bar used to start hidden and be revealed by JS once a
// row was ticked; it now states the span, the widen link and the way out, all of
// which must be there from first paint and must survive unticking every row.
// Rendering the opening tally server-side also means no flash of "0 entries" and
// a usable bar without JS.
$pltt_start_count = $pltt_bill_confirm ? 0 : max( 0, count( $scope['entries'] ) - $pltt_offlist );
$pltt_start_total = $pltt_bill_confirm ? 0.0 : (float) $scope['unbilled'];
?>
<div class="pltt-billsel-bar"<?php echo $pltt_bill_confirm ? ' data-confirm' : ''; ?> aria-live="polite">
	<span class="pltt-billsel-summary">
		<?php if ( $pltt_bill_confirm ) : ?>
			<strong class="pltt-billsel-total"><?php echo esc_html( pltt_format_currency( (float) $scope['unbilled'] ) ); ?></strong>
			<?php esc_html_e( 'over allocation', 'plain-language-time-tracker' ); ?>
			<?php if ( ! empty( $v['date_range'] ) ) : ?> · <?php echo esc_html( $v['date_range'] ); ?><?php endif; ?>
		<?php else : ?>
			<?php // The span first — what this bill covers, stated rather than implied by whatever the filter happens to be. ?>
			<?php esc_html_e( 'Bill', 'plain-language-time-tracker' ); ?>
			<?php if ( $pltt_from && $pltt_to ) : ?>
				<span class="pltt-mono"><?php echo esc_html( pltt_format_date_range( $pltt_from, $pltt_to ) ); ?></span> ·
			<?php endif; ?>
			<strong class="pltt-billsel-count"><?php echo esc_html( (string) $pltt_start_count ); ?></strong> <?php esc_html_e( 'entries selected', 'plain-language-time-tracker' ); ?>
			· <span class="pltt-billsel-total"><?php echo esc_html( pltt_format_currency( $pltt_start_total ) ); ?></span>
			<?php if ( $pltt_offlist > 0 ) : ?>
				<?php // The one case where the list can't show everything billable. Stated, never silent — an unstated version of this is what let a bill cover only the first page. ?>
				<span class="pltt-billsel-offlist">
					&mdash;
					<?php
					printf(
						/* translators: %d: billable entries in range that are not on the list. */
						esc_html( _n( '%d billable entry in this range is not on the list and will not be billed — narrow the range', '%d billable entries in this range are not on the list and will not be billed — narrow the range', $pltt_offlist, 'plain-language-time-tracker' ) ),
						(int) $pltt_offlist
					);
					?>
				</span>
			<?php endif; ?>
		<?php endif; ?>
	</span>
	<span class="pltt-billsel-spacer"></span>
	<?php
	// Cancel — the way out of billing mode. Same view, minus the bill flag: the
	// select column and this bar go away, the range and every entry stay exactly
	// as they were, and nothing is recorded. A real link so it works without JS;
	// billing-select.js points the Escape key at this same href. Sits left of the
	// two buttons so the pair that leads to a bill stays adjacent.
	?>
	<a class="pltt-billsel-cancel" href="<?php echo esc_url( remove_query_arg( 'bill' ) ); ?>" data-exit-bill>
		<?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?>
	</a>
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
		<?php
		// The span the record covers. These were posted empty, so commit()
		// recomputed an all-time scope and stored period_start = null on every
		// hourly record — the window it billed was never written down. They now
		// carry the same range the bar, the prefill and the ticked rows describe.
		?>
		<input type="hidden" name="date_from" value="<?php echo esc_attr( $pltt_from ); ?>">
		<input type="hidden" name="date_to" value="<?php echo esc_attr( $pltt_to ); ?>">

		<?php // Title row + terms + when: the scope block's identity shape, matching billing-dialog.php so both routes to a bill look alike. The submit button already says "Record bill", so the heading names the project instead. ?>
		<div class="pltt-bill-dialog-titlerow">
			<h2 id="pltt-billsel-title" class="pltt-bill-dialog-title"><?php echo esc_html( $project->name ); ?></h2>
			<?php pltt_render_billing_type_badge( pltt_get_billing_type( $project ) ); ?>
		</div>
		<div class="pltt-scope-terms">
			<?php if ( '' !== $client_name ) : ?>
				<?php echo esc_html( $client_name ); ?> &middot;
			<?php endif; ?>
			<?php esc_html_e( 'Calculated', 'plain-language-time-tracker' ); ?>
			<?php // Live for hourly (billing-select.js retallies .pltt-billsel-calc on every tick); fixed for a retainer period, which bills as a whole. ?>
			<span class="pltt-billsel-calc"><?php echo esc_html( pltt_format_currency( $pltt_bill_confirm ? (float) $scope['unbilled'] : 0 ) ); ?></span>
		</div>
		<?php
		// Scope line — the same tinted inset the scope block uses for its line 3,
		// stating the same two facts (span + size). "Billing", not "Showing": the
		// count is what's about to be charged for, which diverges from what's on
		// screen as soon as a row is unticked. Date range is formatted per project
		// type by pltt_build_billing_scope_view(): retainer = its period label,
		// hourly = the span of the entries.
		?>
		<?php if ( ! empty( $v['date_range'] ) || ! $pltt_bill_confirm ) : ?>
			<div class="pltt-scope-when">
				<span><?php esc_html_e( 'Billing', 'plain-language-time-tracker' ); ?></span>
				<?php if ( ! empty( $v['date_range'] ) ) : ?>
					<span class="pltt-mono"><?php echo esc_html( $v['date_range'] ); ?></span>
				<?php endif; ?>
				<?php if ( ! $pltt_bill_confirm ) : ?>
					<?php // Live count — billing-select.js keeps every .pltt-billsel-count in the dialog in sync. ?>
					<span>&middot; <strong class="pltt-billsel-count">0</strong> <?php esc_html_e( 'entries', 'plain-language-time-tracker' ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php // Retainer explains its fixed figure; hourly's amount is the live one on the terms line above, and its derivation changes with every tick, so there is nothing static to state here. ?>
		<?php if ( $pltt_bill_confirm ) : ?>
			<p class="pltt-bill-dialog-sub"><?php echo esc_html( $v['derivation'] ); ?></p>
			<?php
			// Billing on the period's final day (Rule 1). A retainer period
			// reconciles as a whole, so anything logged to it after this record
			// exists is stranded — it does not resurface here or roll into next
			// month. That's a real consequence with a real repair, so the modal
			// states both rather than a vague "still open". Not a block.
			if ( ! empty( $scope['period_end'] ) && (string) $scope['period_end'] === pltt_get_current_date() ) :
				?>
				<p class="pltt-billing-hint pltt-billsel-sameday">
					<?php
					printf(
						/* translators: %s: billing period label, e.g. "July 2026". */
						esc_html__( '%s ends today. Time you log later today won\'t be on this record, and a retainer period can\'t be topped up — delete the record and redo it if that happens.', 'plain-language-time-tracker' ),
						esc_html( $scope['period_label'] )
					);
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<div class="pltt-billing-amount-row">
			<label class="pltt-billing-amount-label" for="pltt-billsel-amount">
				<?php esc_html_e( 'Amount to bill', 'plain-language-time-tracker' ); ?>
			</label>
			<div class="pltt-billing-amount-field">
				<span class="pltt-billing-currency">$</span>
				<input type="number" id="pltt-billsel-amount" name="billed_amount" step="0.01" min="0" value="<?php echo esc_attr( $remainder ); ?>" class="pltt-billing-amount-input pltt-billsel-amount">
			</div>
			<p class="pltt-billing-hint">
				<?php esc_html_e( 'Lower the amount to absorb part of the charge, or raise it to record an invoice you rounded up.', 'plain-language-time-tracker' ); ?>
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

<?php // "Line items" copy modal (default description / AI prompts) — peer to Record bill. ?>
<?php include PLTT_PLUGIN_DIR . 'templates/partials/billing-copy-dialog.php'; ?>

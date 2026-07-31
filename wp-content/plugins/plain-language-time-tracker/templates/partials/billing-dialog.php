<?php
/**
 * Billing modal — the Review & Invoice <dialog> for a single outstanding scope.
 *
 * Shared by every surface that commits a billing record (the Invoicing queue,
 * the Reports single-project card). Submits via AJAX (pltt_commit_billing),
 * wired in assets/js/invoicing.js. A <dialog> can't live inside a <tbody>, so
 * callers render these after their scope list, not inline with it.
 *
 * Expects in scope:
 *   $v           — scope view-model from pltt_build_billing_scope_view().
 *   $client_name — owning client name (dialog title).
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$scope     = $v['scope'];
$proj      = $v['proj'];
$dialog_id = 'pltt-bill-' . $v['uid'];
$remainder = number_format( (float) $scope['unbilled'], 2, '.', '' );
?>
<dialog id="<?php echo esc_attr( $dialog_id ); ?>" class="pltt-bill-dialog" closedby="any" aria-labelledby="<?php echo esc_attr( $dialog_id ); ?>-title">
	<button type="button" class="pltt-modal-x" data-close aria-label="<?php esc_attr_e( 'Close', 'plain-language-time-tracker' ); ?>">&times;</button>
	<form class="pltt-billing-form" data-scope="<?php echo esc_attr( $v['uid'] ); ?>">
		<input type="hidden" name="project_id" value="<?php echo esc_attr( (int) $proj->id ); ?>">
		<input type="hidden" name="billing_type" value="<?php echo esc_attr( $scope['billing_type'] ); ?>">
		<input type="hidden" name="period" value="<?php echo esc_attr( (string) $scope['period_start'] ); ?>">
		<?php // Hourly bills the viewed range; the record stores exactly this window. ?>
		<input type="hidden" name="date_from" value="<?php echo esc_attr( (string) $scope['period_start'] ); ?>">
		<input type="hidden" name="date_to" value="<?php echo esc_attr( (string) $scope['period_end'] ); ?>">

		<?php // Title row + terms + when: the scope block's identity shape, so the modal reads as the same object you clicked from. ?>
		<div class="pltt-bill-dialog-titlerow">
			<h2 id="<?php echo esc_attr( $dialog_id ); ?>-title" class="pltt-bill-dialog-title">
				<?php echo esc_html( $proj->name ); ?>
			</h2>
			<?php pltt_render_billing_type_badge( pltt_get_billing_type( $proj ) ); ?>
		</div>
		<div class="pltt-scope-terms">
			<?php
			$bd_terms = array();
			if ( '' !== $client_name ) {
				$bd_terms[] = $client_name;
			}
			$bd_terms[] = sprintf(
				/* translators: %s: calculated amount for this bill. */
				__( 'Calculated %s', 'plain-language-time-tracker' ),
				pltt_format_currency( $scope['unbilled'] )
			);
			echo esc_html( implode( ' · ', $bd_terms ) );
			?>
		</div>
		<?php
		// Scope line — the same tinted inset the scope block uses for its line 3,
		// and the same one the inline select bar's dialog carries, so both routes
		// to a bill state the span the same way. This modal previously jumped from
		// the project name straight to the amount, leaving the date range unsaid.
		// Date range is formatted per project type by pltt_build_billing_scope_view():
		// retainer = its period label, hourly = the span of the covered entries.
		?>
		<?php if ( ! empty( $v['date_range'] ) ) : ?>
			<div class="pltt-scope-when">
				<span><?php esc_html_e( 'Billing', 'plain-language-time-tracker' ); ?></span>
				<span class="pltt-mono"><?php echo esc_html( $v['date_range'] ); ?></span>
				<?php if ( (int) $v['count'] > 0 ) : ?>
					<span>&middot;
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of entries on the bill. */
								_n( '%s entry', '%s entries', (int) $v['count'], 'plain-language-time-tracker' ),
								number_format_i18n( (int) $v['count'] )
							)
						);
						?>
					</span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php // Just the derivation now — the amount it explains moved up to the terms line. ?>
		<p class="pltt-bill-dialog-sub"><?php echo esc_html( $v['derivation'] ); ?></p>

		<?php // Entry selection now happens inline in the queue row; this modal just records the bill. ?>
		<div class="pltt-billing-amount-row">
			<label class="pltt-billing-amount-label" for="<?php echo esc_attr( $dialog_id ); ?>-amount">
				<?php esc_html_e( 'Amount to bill', 'plain-language-time-tracker' ); ?>
			</label>
			<div class="pltt-billing-amount-field">
				<span class="pltt-billing-currency">$</span>
				<?php // No max: bill above the calculation to record a rounded-up invoice. ?>
				<input type="number" id="<?php echo esc_attr( $dialog_id ); ?>-amount" name="billed_amount" step="0.01" min="0" value="<?php echo esc_attr( $remainder ); ?>" class="pltt-billing-amount-input">
			</div>
		</div>

		<?php // When the invoice went out — back-date it so the record lands in the month it was billed. ?>
		<div class="pltt-billing-amount-row">
			<label class="pltt-billing-amount-label" for="<?php echo esc_attr( $dialog_id ); ?>-date">
				<?php esc_html_e( 'Invoice date', 'plain-language-time-tracker' ); ?>
			</label>
			<div class="pltt-billing-amount-field">
				<input type="date" id="<?php echo esc_attr( $dialog_id ); ?>-date" name="marked_at" value="<?php echo esc_attr( pltt_get_current_date() ); ?>" class="pltt-billing-date-input">
			</div>
			<p class="pltt-billing-hint">
				<?php esc_html_e( 'Defaults to today. Back-date it when recording an invoice you already sent.', 'plain-language-time-tracker' ); ?>
			</p>
		</div>

		<div class="pltt-billing-description-row">
			<label class="pltt-billing-amount-label" for="<?php echo esc_attr( $dialog_id ); ?>-desc">
				<?php esc_html_e( 'Invoice description', 'plain-language-time-tracker' ); ?>
			</label>
			<textarea id="<?php echo esc_attr( $dialog_id ); ?>-desc" name="description" rows="4" class="pltt-billing-description"><?php echo esc_textarea( $v['default_desc'] ); ?></textarea>
			<p class="pltt-billing-desc-hint">
				<?php esc_html_e( 'Need formatted line items or an AI prompt? Use “Line items” back on the row.', 'plain-language-time-tracker' ); ?>
			</p>
		</div>

		<p class="pltt-billing-error" role="alert" hidden></p>

		<div class="pltt-billing-actions">
			<button type="submit" data-action="bill" class="button button-primary">
				<?php esc_html_e( 'Close &amp; Bill', 'plain-language-time-tracker' ); ?> <span class="pltt-bill-amount"><?php echo esc_html( pltt_format_currency( $scope['unbilled'] ) ); ?></span>
			</button>
			<button type="button" class="pltt-billing-cancel" data-close><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</div>
	</form>
</dialog>

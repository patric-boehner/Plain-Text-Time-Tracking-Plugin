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
	<form class="pltt-billing-form" data-scope="<?php echo esc_attr( $v['uid'] ); ?>">
		<input type="hidden" name="project_id" value="<?php echo esc_attr( (int) $proj->id ); ?>">
		<input type="hidden" name="billing_type" value="<?php echo esc_attr( $scope['billing_type'] ); ?>">
		<input type="hidden" name="period" value="<?php echo esc_attr( (string) $scope['period_start'] ); ?>">
		<?php // Hourly bills the viewed range; the record stores exactly this window. ?>
		<input type="hidden" name="date_from" value="<?php echo esc_attr( (string) $scope['period_start'] ); ?>">
		<input type="hidden" name="date_to" value="<?php echo esc_attr( (string) $scope['period_end'] ); ?>">

		<h2 id="<?php echo esc_attr( $dialog_id ); ?>-title" class="pltt-bill-dialog-title">
			<?php
			printf(
				/* translators: 1: client name, 2: project name. */
				esc_html__( '%1$s · %2$s', 'plain-language-time-tracker' ),
				esc_html( $client_name ),
				esc_html( $proj->name )
			);
			?>
		</h2>
		<p class="pltt-bill-dialog-sub">
			<?php
			printf(
				/* translators: 1: calculated amount, 2: derivation e.g. "12h billable × $120". */
				esc_html__( 'Calculated %1$s — %2$s', 'plain-language-time-tracker' ),
				esc_html( pltt_format_currency( $scope['unbilled'] ) ),
				esc_html( $v['derivation'] )
			);
			?>
		</p>

		<?php if ( 'hourly' === $scope['billing_type'] && ! empty( $scope['entries'] ) ) : ?>
			<div class="pltt-billing-picker">
				<p class="pltt-billing-picker-hint"><?php esc_html_e( 'Uncheck an entry to leave it unbilled — it stays open for a future invoice.', 'plain-language-time-tracker' ); ?></p>
				<div class="pltt-billing-picker-scroll">
					<?php pltt_render_billing_manifest( $scope['entries'], true ); ?>
				</div>
			</div>
		<?php endif; ?>

		<div class="pltt-billing-amount-row">
			<label class="pltt-billing-amount-label" for="<?php echo esc_attr( $dialog_id ); ?>-amount">
				<?php esc_html_e( 'Amount to bill', 'plain-language-time-tracker' ); ?>
			</label>
			<div class="pltt-billing-amount-field">
				<span class="pltt-billing-currency">$</span>
				<input type="number" id="<?php echo esc_attr( $dialog_id ); ?>-amount" name="billed_amount" step="0.01" min="0" max="<?php echo esc_attr( $remainder ); ?>" value="<?php echo esc_attr( $remainder ); ?>" class="pltt-billing-amount-input">
			</div>
		</div>

		<div class="pltt-billing-description-row">
			<div class="pltt-billing-desc-head">
				<select id="<?php echo esc_attr( $dialog_id ); ?>-mode" class="pltt-billing-desc-mode" aria-label="<?php esc_attr_e( 'Description source', 'plain-language-time-tracker' ); ?>">
					<option value="default" data-text="<?php echo esc_attr( $v['default_desc'] ); ?>"><?php esc_html_e( 'Default description', 'plain-language-time-tracker' ); ?></option>
					<option value="ai_prompt" data-text="<?php echo esc_attr( $v['ai_prompt'] ); ?>"><?php esc_html_e( 'Structured list + AI prompt', 'plain-language-time-tracker' ); ?></option>
				</select>
				<button type="button" class="button pltt-billing-copy">
					<span class="pltt-copy-label"><?php esc_html_e( 'Copy', 'plain-language-time-tracker' ); ?></span>
				</button>
			</div>
			<textarea id="<?php echo esc_attr( $dialog_id ); ?>-desc" name="description" rows="4" class="pltt-billing-description"><?php echo esc_textarea( $v['default_desc'] ); ?></textarea>
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

<?php
/**
 * Billing record detail — the "View record" dialog.
 *
 * Reopens a committed record so you can get at what you invoiced: the period it
 * covered, what was billed, and the copyable invoice text (the saved
 * description, or an AI prompt rebuilt from the entries). Read-only — a record
 * never changes. Shared by the Billing ledger, the project-report billing
 * history, and the detailed view's record bar.
 *
 * Laid out to match the Record-bill modal (billing-select-bar.php, itself built
 * from ui/pltt-billing-entries.html §04): the act names the header, then the
 * project + one facts line, then the amount, then the invoice text panel. It
 * reuses that modal's .pltt-billsel-ident / -meta / -invoice blocks and the
 * .pltt-billcopy-* contract, so invoicing.js drives source-swap and copy here
 * with no extra JS.
 *
 * The frozen entry manifest used to sit above the invoice text and was removed:
 * every surface that opens this dialog is already showing those entries — the
 * detailed view has them in the table behind it, tinted as covered.
 *
 * Expects in scope:
 *   $rv        — view-model from pltt_build_billing_record_view().
 *   $dialog_id — the element id the trigger's data-lineitems-dialog points at.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<dialog id="<?php echo esc_attr( $dialog_id ); ?>" class="pltt-billcopy-dialog pltt-record-dialog" closedby="any" aria-labelledby="<?php echo esc_attr( $dialog_id ); ?>-title">
	<button type="button" class="pltt-modal-x" data-close aria-label="<?php esc_attr_e( 'Close', 'plain-language-time-tracker' ); ?>">&times;</button>
	<div class="pltt-billcopy-inner">
		<h2 id="<?php echo esc_attr( $dialog_id ); ?>-title" class="pltt-billcopy-title">
			<?php esc_html_e( 'Billing record', 'plain-language-time-tracker' ); ?>
		</h2>

		<div class="pltt-billsel-ident">
			<span class="pltt-billsel-ident-name"><?php echo esc_html( $rv['project_name'] ); ?></span>
			<?php if ( '' !== $rv['billing_type'] ) : ?>
				<?php pltt_render_billing_type_badge( $rv['billing_type'] ); ?>
			<?php endif; ?>
		</div>
		<?php // Who · when · how much work · when the invoice went out. Fixed, unlike the bill modal's — a record never changes. ?>
		<p class="pltt-billsel-meta">
			<?php if ( '' !== $rv['client_name'] ) : ?>
				<?php echo esc_html( $rv['client_name'] ); ?> &middot;
			<?php endif; ?>
			<?php if ( '' !== $rv['period'] ) : ?>
				<span class="pltt-mono"><?php echo esc_html( $rv['period'] ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $rv['minutes_label'] ) : ?>
				&middot; <span class="pltt-mono"><?php echo esc_html( $rv['minutes_label'] ); ?></span>
			<?php endif; ?>
			&middot; <?php esc_html_e( 'Billed', 'plain-language-time-tracker' ); ?>
			<span class="pltt-mono"><?php echo esc_html( $rv['billed_on'] ); ?></span>
		</p>

		<?php
		// The amount, in the same slot the bill modal puts its input — the figure
		// you're here to check reads off the same line either way. Static: this one
		// was recorded, and a record never changes.
		?>
		<div class="pltt-billing-amount-row">
			<span class="pltt-billing-amount-label"><?php esc_html_e( 'Amount billed', 'plain-language-time-tracker' ); ?></span>
			<div class="pltt-billsel-amount-line">
				<span class="pltt-mono pltt-record-amount"><?php echo esc_html( pltt_format_currency( $rv['amount'] ) ); ?></span>
				<?php if ( $rv['absorbed'] > 0 ) : ?>
					<span class="pltt-billsel-gap">
						<?php
						printf(
							/* translators: %s: absorbed amount. */
							esc_html__( 'you absorbed %s', 'plain-language-time-tracker' ),
							'<span class="pltt-mono">' . esc_html( pltt_format_currency( (float) $rv['absorbed'] ) ) . '</span>'
						);
						?>
					</span>
				<?php endif; ?>
			</div>
		</div>

		<?php // Copyable invoice text — paste into an invoice or an AI composer. ?>
		<div class="pltt-billsel-invoice">
			<div class="pltt-billcopy-head">
				<?php // The source picker names the section on screen; the label is here for screen readers. ?>
				<label class="screen-reader-text" for="<?php echo esc_attr( $dialog_id ); ?>-mode">
					<?php esc_html_e( 'Invoice text', 'plain-language-time-tracker' ); ?>
				</label>
				<select id="<?php echo esc_attr( $dialog_id ); ?>-mode" class="pltt-billcopy-mode">
					<option value="default" data-text="<?php echo esc_attr( $rv['default_desc'] ); ?>"><?php esc_html_e( 'Saved description', 'plain-language-time-tracker' ); ?></option>
					<?php foreach ( $rv['prompts'] as $prompt_key => $prompt ) : ?>
						<option value="<?php echo esc_attr( $prompt_key ); ?>" data-text="<?php echo esc_attr( $prompt['text'] ); ?>"><?php echo esc_html( $prompt['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="pltt-billsel-invoice-spacer"></span>
				<button type="button" class="button pltt-billcopy-copy">
					<span class="pltt-billcopy-copy-label"><?php esc_html_e( 'Copy', 'plain-language-time-tracker' ); ?></span>
				</button>
			</div>
			<textarea class="pltt-billcopy-text" rows="7" readonly aria-label="<?php esc_attr_e( 'Invoice text', 'plain-language-time-tracker' ); ?>"><?php echo esc_textarea( $rv['default_desc'] ); ?></textarea>
		</div>

		<div class="pltt-billcopy-actions">
			<button type="button" class="button pltt-billcopy-close" data-close><?php esc_html_e( 'Done', 'plain-language-time-tracker' ); ?></button>
		</div>
	</div>
</dialog>

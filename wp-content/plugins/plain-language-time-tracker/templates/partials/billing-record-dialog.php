<?php
/**
 * Billing record detail — the "View record" dialog.
 *
 * Reopens a committed record so you can see what went into the bill: the frozen
 * entry manifest, the period range, the date billed, and the copyable line-items
 * text (the saved description, or a structured list + AI prompt rebuilt from the
 * entries). Read-only — a record never changes. Shared by the Billing ledger and
 * the project-report billing history.
 *
 * Reuses the .pltt-billcopy-* contract so invoicing.js drives open / source-swap
 * / copy with no extra JS.
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
			<?php
			printf(
				/* translators: %s: "Client · Project". */
				esc_html__( 'Billing record — %s', 'plain-language-time-tracker' ),
				esc_html( $rv['label'] )
			);
			?>
		</h2>

		<p class="pltt-record-meta description">
			<?php
			printf(
				/* translators: 1: date billed, 2: period range, 3: amount. */
				esc_html__( 'Billed %1$s · %2$s · %3$s', 'plain-language-time-tracker' ),
				esc_html( $rv['billed_on'] ),
				esc_html( $rv['period'] ),
				esc_html( pltt_format_currency( $rv['amount'] ) )
			);
			if ( $rv['absorbed'] > 0 ) {
				echo ' ';
				printf(
					/* translators: %s: absorbed amount. */
					esc_html__( '(%s absorbed)', 'plain-language-time-tracker' ),
					esc_html( pltt_format_currency( $rv['absorbed'] ) )
				);
			}
			?>
		</p>

		<?php // The frozen manifest — what went into this bill. ?>
		<h3 class="pltt-record-subhead">
			<?php
			printf(
				/* translators: %d: number of entries. */
				esc_html( _n( '%d entry billed', '%d entries billed', count( $rv['entries'] ), 'plain-language-time-tracker' ) ),
				count( $rv['entries'] )
			);
			?>
		</h3>
		<?php if ( ! empty( $rv['entries'] ) ) : ?>
			<div class="pltt-record-manifest">
				<?php pltt_render_billing_manifest( $rv['entries'] ); ?>
			</div>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'The entries in this record are no longer available.', 'plain-language-time-tracker' ); ?></p>
		<?php endif; ?>

		<?php // Copyable invoice text — paste into an invoice or an AI composer. ?>
		<h3 class="pltt-record-subhead"><?php esc_html_e( 'Invoice line items', 'plain-language-time-tracker' ); ?></h3>
		<div class="pltt-billcopy-head">
			<select class="pltt-billcopy-mode" aria-label="<?php esc_attr_e( 'Description source', 'plain-language-time-tracker' ); ?>">
				<option value="default" data-text="<?php echo esc_attr( $rv['default_desc'] ); ?>"><?php esc_html_e( 'Saved description', 'plain-language-time-tracker' ); ?></option>
				<option value="ai_prompt" data-text="<?php echo esc_attr( $rv['ai_prompt'] ); ?>"><?php esc_html_e( 'Structured list + AI prompt', 'plain-language-time-tracker' ); ?></option>
			</select>
			<button type="button" class="button pltt-billcopy-copy">
				<span class="pltt-billcopy-copy-label"><?php esc_html_e( 'Copy', 'plain-language-time-tracker' ); ?></span>
			</button>
		</div>
		<textarea class="pltt-billcopy-text" rows="6" readonly><?php echo esc_textarea( $rv['default_desc'] ); ?></textarea>

		<div class="pltt-billcopy-actions">
			<button type="button" class="button pltt-billcopy-close" data-close><?php esc_html_e( 'Done', 'plain-language-time-tracker' ); ?></button>
		</div>
	</div>
</dialog>

<?php
/**
 * Copy line items — the one modal left in the inline billing flow.
 *
 * A pared <dialog> that lets the user pick a description source (a plain default,
 * or one of the AI prompt shapes) and copy it to the clipboard — e.g. to paste
 * into an invoice or an AI composer. It does NOT write back to the record; the
 * saved description lives in the inline panel's "Invoice description" field.
 *
 * Expects in scope:
 *   $v — scope view-model from pltt_build_billing_scope_view() (default_desc, prompts).
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dialog_id = 'pltt-billcopy-' . $v['uid'];
?>
<dialog id="<?php echo esc_attr( $dialog_id ); ?>" class="pltt-billcopy-dialog" closedby="any" aria-labelledby="<?php echo esc_attr( $dialog_id ); ?>-title">
	<button type="button" class="pltt-modal-x" data-close aria-label="<?php esc_attr_e( 'Close', 'plain-language-time-tracker' ); ?>">&times;</button>
	<div class="pltt-billcopy-inner">
		<h2 id="<?php echo esc_attr( $dialog_id ); ?>-title" class="pltt-billcopy-title"><?php esc_html_e( 'Copy line items', 'plain-language-time-tracker' ); ?></h2>

		<div class="pltt-billcopy-head">
			<select class="pltt-billcopy-mode" aria-label="<?php esc_attr_e( 'Description source', 'plain-language-time-tracker' ); ?>">
				<option value="default" data-text="<?php echo esc_attr( $v['default_desc'] ); ?>"><?php esc_html_e( 'Default description', 'plain-language-time-tracker' ); ?></option>
				<?php foreach ( $v['prompts'] as $prompt_key => $prompt ) : ?>
					<option value="<?php echo esc_attr( $prompt_key ); ?>" data-text="<?php echo esc_attr( $prompt['text'] ); ?>"><?php echo esc_html( $prompt['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button pltt-billcopy-copy">
				<span class="pltt-billcopy-copy-label"><?php esc_html_e( 'Copy', 'plain-language-time-tracker' ); ?></span>
			</button>
		</div>

		<textarea class="pltt-billcopy-text" rows="8" readonly><?php echo esc_textarea( $v['default_desc'] ); ?></textarea>

		<div class="pltt-billcopy-actions">
			<button type="button" class="button pltt-billcopy-close" data-close><?php esc_html_e( 'Done', 'plain-language-time-tracker' ); ?></button>
		</div>
	</div>
</dialog>

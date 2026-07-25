<?php
/**
 * Record-view bar for the Overview detailed view.
 *
 * The read-only counterpart to billing-select-bar.php: shown when the detailed
 * view is opened from a "View record" link (record_id set). It bills nothing —
 * it just docks a bar summarizing the committed record and a "Line items…"
 * button that reopens the copyable record dialog (billing-record-dialog.php).
 *
 * Expects in scope:
 *   $rv        — record view-model from pltt_build_billing_record_view().
 *   $dialog_id — the element id the button's data-lineitems-dialog points at.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="pltt-billsel-bar pltt-recordview-bar" aria-live="polite">
	<span class="pltt-billsel-summary">
		<strong class="pltt-billsel-total"><?php echo esc_html( pltt_format_currency( (float) $rv['amount'] ) ); ?></strong>
		<?php esc_html_e( 'billed', 'plain-language-time-tracker' ); ?>
		<?php if ( '' !== $rv['period'] ) : ?> · <?php echo esc_html( $rv['period'] ); ?><?php endif; ?>
		<?php if ( $rv['absorbed'] > 0.0 ) : ?>
			· <?php
			printf(
				/* translators: %s: absorbed amount. */
				esc_html__( '%s absorbed', 'plain-language-time-tracker' ),
				esc_html( pltt_format_currency( (float) $rv['absorbed'] ) )
			);
			?>
		<?php endif; ?>
	</span>
	<span class="pltt-billsel-spacer"></span>
	<button type="button" class="button pltt-billsel-lineitems" data-lineitems-dialog="<?php echo esc_attr( $dialog_id ); ?>">
		<?php esc_html_e( 'Line items…', 'plain-language-time-tracker' ); ?>
	</button>
</div>

<?php include PLTT_PLUGIN_DIR . 'templates/partials/billing-record-dialog.php'; ?>

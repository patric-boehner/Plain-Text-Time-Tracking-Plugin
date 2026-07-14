<?php
/**
 * Billing history table — the read-only ledger of committed records for one
 * project, newest first. A record with billed_amount = 0 is fully absorbed.
 *
 * Shared by the Project Detail report and the Reports single-project card.
 * Not period-scoped — it's the lifetime ledger.
 *
 * Expects in scope:
 *   $billing_history — array of record objects (PLTT_Billing::get_for_project_history()).
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $billing_history ) ) {
	return;
}

// Build each record's view-model once (loads its entries) so the Period column
// and the "View record" dialog share one entry-derived range.
$history_views = array();
foreach ( $billing_history as $rec ) {
	$history_views[] = array(
		'rec' => $rec,
		'rv'  => pltt_build_billing_record_view( $rec ),
	);
}
?>
<table class="widefat striped pltt-billing-history-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Billed on', 'plain-language-time-tracker' ); ?></th>
			<th><?php esc_html_e( 'Period', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-amount-col"><?php esc_html_e( 'Amount', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-amount-col"><?php esc_html_e( 'Absorbed', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-billing-history-action"></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $history_views as $view ) : ?>
			<?php
			$rec       = $view['rec'];
			$rv        = $view['rv'];
			$dialog_id = 'pltt-recordview-' . (int) $rec->id;
			?>
			<tr>
				<td class="pltt-time-cell"><?php echo esc_html( $rv['billed_on'] ); ?></td>
				<td><?php echo esc_html( $rv['period'] ); ?></td>
				<td class="pltt-amount-col"><?php echo esc_html( pltt_format_currency( $rv['amount'] ) ); ?></td>
				<td class="pltt-amount-col"><?php echo $rv['absorbed'] > 0.0 ? esc_html( pltt_format_currency( $rv['absorbed'] ) ) : '—'; ?></td>
				<td class="pltt-billing-history-action">
					<button type="button" class="button-link" data-lineitems-dialog="<?php echo esc_attr( $dialog_id ); ?>">
						<?php esc_html_e( 'View record', 'plain-language-time-tracker' ); ?>
					</button>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php // Record-detail dialogs (a <dialog> can't sit inside a <tbody>). ?>
<?php foreach ( $history_views as $view ) : ?>
	<?php
	$rv        = $view['rv'];
	$dialog_id = 'pltt-recordview-' . (int) $view['rec']->id;
	include PLTT_PLUGIN_DIR . 'templates/partials/billing-record-dialog.php';
	?>
<?php endforeach; ?>

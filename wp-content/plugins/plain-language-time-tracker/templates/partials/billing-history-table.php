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
?>
<table class="widefat striped pltt-billing-history-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Period', 'plain-language-time-tracker' ); ?></th>
			<th><?php esc_html_e( 'Billed', 'plain-language-time-tracker' ); ?></th>
			<th><?php esc_html_e( 'Absorbed', 'plain-language-time-tracker' ); ?></th>
			<th><?php esc_html_e( 'Description', 'plain-language-time-tracker' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $billing_history as $rec ) : ?>
			<?php
			$period_label = pltt_format_billing_period( $rec );
			?>
			<tr>
				<td>
					<?php echo esc_html( $period_label ); ?>
				</td>
				<td><?php echo esc_html( pltt_format_currency( (float) $rec->billed_amount ) ); ?></td>
				<td><?php echo (float) $rec->absorbed_amount > 0.0 ? esc_html( pltt_format_currency( (float) $rec->absorbed_amount ) ) : '—'; ?></td>
				<td class="pltt-billing-history-desc"><?php echo esc_html( (string) $rec->description ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

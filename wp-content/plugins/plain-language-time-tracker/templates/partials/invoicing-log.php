<?php
/**
 * Invoicing — Invoiced view: the cross-project ledger of committed billing
 * records, newest first. A record with billed_amount = 0 is fully absorbed.
 *
 * Expects $log (from PLTT_Billing::get_invoiced_log()) in scope.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $log['rows'] ) ) :
	?>
	<div class="pltt-card pltt-invoicing-empty">
		<p class="pltt-report-placeholder-lead"><?php esc_html_e( 'No invoices recorded yet.', 'plain-language-time-tracker' ); ?></p>
		<p class="description"><?php esc_html_e( 'Once you bill outstanding work, each record shows up here.', 'plain-language-time-tracker' ); ?></p>
	</div>
	<?php
	return;
endif;
?>

<p class="pltt-invoicing-lead">
	<strong><?php echo esc_html( pltt_format_currency( $log['total_billed'] ) ); ?></strong>
	<?php esc_html_e( 'invoiced', 'plain-language-time-tracker' ); ?>
	<?php if ( $log['total_absorbed'] > 0 ) : ?>
		· <strong><?php echo esc_html( pltt_format_currency( $log['total_absorbed'] ) ); ?></strong> <?php esc_html_e( 'absorbed', 'plain-language-time-tracker' ); ?>
	<?php endif; ?>
	· <span><?php echo (int) $log['count']; ?></span> <?php esc_html_e( 'records', 'plain-language-time-tracker' ); ?>
</p>

<table class="widefat striped pltt-invoiced-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Date', 'plain-language-time-tracker' ); ?></th>
			<th><?php esc_html_e( 'Client · Project', 'plain-language-time-tracker' ); ?></th>
			<th><?php esc_html_e( 'Period', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-amount-col"><?php esc_html_e( 'Billed', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-amount-col"><?php esc_html_e( 'Absorbed', 'plain-language-time-tracker' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $log['rows'] as $row ) : ?>
			<?php
			$rec         = $row['record'];
			$label       = '' !== $row['client_name']
				? $row['client_name'] . ' · ' . $row['project_name']
				: $row['project_name'];
			?>
			<tr>
				<td class="pltt-time-cell"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $rec->marked_at ) ) ); ?></td>
				<td>
					<?php echo esc_html( $label ); ?>
				</td>
				<td><?php echo esc_html( pltt_format_billing_period( $rec ) ); ?></td>
				<td class="pltt-amount-col"><?php echo esc_html( pltt_format_currency( (float) $rec->billed_amount ) ); ?></td>
				<td class="pltt-amount-col"><?php echo (float) $rec->absorbed_amount > 0 ? esc_html( pltt_format_currency( (float) $rec->absorbed_amount ) ) : '<span class="pltt-empty">&mdash;</span>'; ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

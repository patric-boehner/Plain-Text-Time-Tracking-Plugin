<?php
/**
 * Billing history table — the read-only ledger of committed records for one
 * project, newest first. A record with billed_amount = 0 is fully absorbed.
 *
 * Shared by the Project Detail report and the Reports single-project card. Mirrors
 * the Billing page's Billed-history table (same columns, same "View record"
 * behavior) minus the Client/Project columns, which are redundant in a
 * single-project context. Not period-scoped — it's the lifetime ledger.
 * "View record" opens the record inside the Overview detailed view (read-only),
 * not an in-page modal.
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

// Build each record's view-model once (loads its entries) so the Covers column
// and the "View record" link share one entry-derived range.
$history_views = array();
foreach ( $billing_history as $rec ) {
	$history_views[] = array(
		'rec' => $rec,
		'rv'  => pltt_build_billing_record_view( $rec ),
	);
}
?>
<table class="widefat striped pltt-billing-history-table pltt-stack pltt-stack-records">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Invoiced', 'plain-language-time-tracker' ); ?></th>
			<th><?php esc_html_e( 'Type', 'plain-language-time-tracker' ); ?></th>
			<th><?php esc_html_e( 'Work covered', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-amount-col"><?php esc_html_e( 'Amount', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-amount-col"><?php esc_html_e( 'Absorbed', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-rowmenu-col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'plain-language-time-tracker' ); ?></span></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $history_views as $view ) : ?>
			<?php
			$rec      = $view['rec'];
			$rv       = $view['rv'];
			$is_over  = ( 'retainer_overage' === $rec->billing_type );
			$type_lbl = $is_over ? __( 'Overage', 'plain-language-time-tracker' ) : __( 'Hourly', 'plain-language-time-tracker' );

			// Covers: retainer names its period + overage hours; hourly names its
			// entry count + span. Mirrors the Billed-history table.
			if ( $is_over ) {
				$covers = $rv['period'];
				if ( '' !== $rv['minutes_label'] ) {
					/* translators: 1: period, e.g. "June 2026"; 2: overage duration, e.g. "3h 0m". */
					$covers = sprintf( __( '%1$s · %2$s over', 'plain-language-time-tracker' ), $rv['period'], $rv['minutes_label'] );
				}
			} else {
				$n = count( $rv['entries'] );
				/* translators: 1: entry count phrase; 2: date span. */
				$covers = sprintf(
					__( '%1$s · %2$s', 'plain-language-time-tracker' ),
					sprintf( _n( '%s entry', '%s entries', $n, 'plain-language-time-tracker' ), number_format_i18n( $n ) ),
					$rv['period']
				);
			}
			?>
			<?php $view_url = pltt_billing_record_view_url( $rec, $rv['entries'] ); ?>
			<tr>
				<td class="pltt-time-cell">
					<?php
					// The date is the link, not #23 — see billing-history.php, which
					// this table mirrors. Unlinked when the project is gone.
					?>
					<?php if ( '' !== $view_url ) : ?>
						<a class="pltt-row-link" href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $rv['billed_on'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $rv['billed_on'] ); ?>
					<?php endif; ?>
					<span class="pltt-bill-record-id">#<?php echo (int) $rec->id; ?></span>
				</td>
				<td class="pltt-bill-type">
					<span class="pltt-badge <?php echo esc_attr( pltt_billing_type_badge_class( $rec->billing_type ) ); ?>"><?php echo esc_html( $type_lbl ); ?></span>
				</td>
				<td class="pltt-bill-record-covers"><?php echo esc_html( $covers ); ?></td>
				<td class="pltt-amount-col pltt-bill-rec-amount"><?php echo esc_html( pltt_format_currency( $rv['amount'] ) ); ?></td>
				<td class="pltt-amount-col pltt-bill-rec-absorbed"><?php echo $rv['absorbed'] > 0.0 ? '<span class="pltt-stack-only">' . esc_html__( 'Absorbed', 'plain-language-time-tracker' ) . ' </span>' . esc_html( pltt_format_currency( $rv['absorbed'] ) ) : '<span class="pltt-empty">&mdash;</span>'; ?></td>
				<td class="pltt-rowmenu-col">
					<?php
					pltt_render_row_menu_trigger(
						'bill-record',
						/* translators: %d: bill record number. */
						sprintf( __( 'Actions for record #%d', 'plain-language-time-tracker' ), (int) $rec->id ),
						array(
							'record-id' => (int) $rec->id,
							'confirm'   => pltt_billing_record_delete_confirm( $rec, $rv ),
							// Same destination the date links to, repeated so the menu
							// lists everything the row can do. Empty when the project is
							// gone and the line items have nowhere to open.
							'view-url'  => $view_url,
						)
					);
					?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

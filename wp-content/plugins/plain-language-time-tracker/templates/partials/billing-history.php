<?php
/**
 * Billing · Billed history — the frozen ledger of committed records.
 *
 * Range-scoped summary cards (Records / Billed / Absorbed, labels dynamic to the
 * active preset) + the shared Overview filter chrome (date-nav + client/type
 * dropdowns) over a paginated records table. Filtering, totals and pagination are
 * all server-side (PLTT_Billing::get_invoiced_log). "View record" reopens the
 * copyable line items.
 *
 * Expects $log (PLTT_Billing::get_invoiced_log), $all_clients, $date_from,
 * $date_to, $h_client_id, $h_type, $history_url and $reports_base in scope.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$today   = pltt_get_current_date();
$now_dt  = new DateTimeImmutable( $today, wp_timezone() );
$last_yr = (int) $now_dt->format( 'Y' ) - 1;

// Ledger-appropriate presets. 'phrase' feeds the dynamic card labels.
$presets = array(
	array(
		'label'  => __( 'This Month', 'plain-language-time-tracker' ),
		'phrase' => __( 'this month', 'plain-language-time-tracker' ),
		'from'   => $now_dt->format( 'Y-m-01' ),
		'to'     => $today,
	),
	array(
		'label'  => __( 'Last Month', 'plain-language-time-tracker' ),
		'phrase' => __( 'last month', 'plain-language-time-tracker' ),
		'from'   => $now_dt->modify( 'first day of last month' )->format( 'Y-m-01' ),
		'to'     => $now_dt->modify( 'first day of last month' )->format( 'Y-m-t' ),
	),
	array(
		'label'  => __( 'This Year', 'plain-language-time-tracker' ),
		'phrase' => __( 'this year', 'plain-language-time-tracker' ),
		'from'   => $now_dt->format( 'Y-01-01' ),
		'to'     => $today,
	),
	array(
		'label'  => __( 'Last Year', 'plain-language-time-tracker' ),
		'phrase' => __( 'last year', 'plain-language-time-tracker' ),
		'from'   => $last_yr . '-01-01',
		'to'     => $last_yr . '-12-31',
	),
);

// Active preset (if any) → dynamic card-label suffix. Custom ranges get no
// suffix (a range like "May 3 – Jun 12" reads badly appended to a label).
$active_phrase = '';
foreach ( $presets as $p ) {
	if ( $date_from === $p['from'] && $date_to === $p['to'] ) {
		$active_phrase = $p['phrase'];
		break;
	}
}
$range_label = pltt_format_date_range( $date_from, $date_to );

/**
 * Build a card label: "Billed this month" when a preset is active, else "Billed".
 *
 * @param string $base   Base label (already translated).
 * @param string $phrase Active preset phrase, or '' for a custom range.
 * @return string
 */
$card_label = static function ( $base, $phrase ) {
	return '' !== $phrase ? $base . ' ' . $phrase : $base;
};
?>

<form method="get" action="" class="pltt-report-filters-form pltt-bill-history-filters">
	<input type="hidden" name="page" value="pltt-invoicing">
	<input type="hidden" name="view" value="history">

	<?php
	$dn_presets    = $presets;
	$dn_from       = $date_from;
	$dn_to         = $date_to;
	$dn_week_start = (int) get_option( 'start_of_week', 0 );
	include PLTT_PLUGIN_DIR . 'templates/partials/date-nav.php';
	?>

	<div class="pltt-report-filters">
		<div class="pltt-filter-row">
			<div class="pltt-filter-group">
				<label for="pltt-bill-filter-client"><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></label>
				<select name="client_id" id="pltt-bill-filter-client">
					<option value="0"><?php esc_html_e( 'All Clients', 'plain-language-time-tracker' ); ?></option>
					<?php foreach ( $all_clients as $c ) : ?>
						<option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $h_client_id, (int) $c->id ); ?>>
							<?php echo esc_html( $c->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="pltt-filter-group">
				<label for="pltt-bill-filter-type"><?php esc_html_e( 'Type', 'plain-language-time-tracker' ); ?></label>
				<select name="type" id="pltt-bill-filter-type">
					<option value="" <?php selected( '' === $h_type ); ?>><?php esc_html_e( 'All Types', 'plain-language-time-tracker' ); ?></option>
					<option value="hourly" <?php selected( 'hourly', $h_type ); ?>><?php esc_html_e( 'Hourly', 'plain-language-time-tracker' ); ?></option>
					<option value="retainer_overage" <?php selected( 'retainer_overage', $h_type ); ?>><?php esc_html_e( 'Retainer overage', 'plain-language-time-tracker' ); ?></option>
				</select>
			</div>

			<div class="pltt-filter-group pltt-filter-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filter', 'plain-language-time-tracker' ); ?></button>
				<a href="<?php echo esc_url( $history_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'plain-language-time-tracker' ); ?></a>
			</div>
		</div>
	</div>
</form>

<div class="pltt-summary-cards pltt-bill-cards-summary">
	<div class="card">
		<div class="card-label"><?php echo esc_html( $card_label( __( 'Records', 'plain-language-time-tracker' ), $active_phrase ) ); ?></div>
		<div class="card-value"><?php echo esc_html( number_format_i18n( (int) $log['count'] ) ); ?></div>
		<div class="card-secondary"><?php echo esc_html( $range_label ); ?></div>
	</div>
	<div class="card">
		<div class="card-label"><?php echo esc_html( $card_label( __( 'Billed', 'plain-language-time-tracker' ), $active_phrase ) ); ?></div>
		<div class="card-value"><?php echo esc_html( pltt_format_currency( (float) $log['total_billed'] ) ); ?></div>
		<div class="card-secondary"><?php echo esc_html( $range_label ); ?></div>
	</div>
	<div class="card">
		<div class="card-label"><?php echo esc_html( $card_label( __( 'Absorbed', 'plain-language-time-tracker' ), $active_phrase ) ); ?></div>
		<div class="card-value pltt-bill-absorbed-value"><?php echo esc_html( pltt_format_currency( (float) $log['total_absorbed'] ) ); ?></div>
		<div class="card-secondary"><?php esc_html_e( 'written down at bill time', 'plain-language-time-tracker' ); ?></div>
	</div>
</div>

<?php if ( empty( $log['rows'] ) ) : ?>
	<div class="pltt-card pltt-bill-empty">
		<p class="pltt-report-placeholder-lead"><?php esc_html_e( 'No records match these filters.', 'plain-language-time-tracker' ); ?></p>
		<p class="description"><?php esc_html_e( 'Widen the date range or clear the filters. Once you bill outstanding work, each record shows up here.', 'plain-language-time-tracker' ); ?></p>
	</div>
<?php else : ?>
	<?php
	// Build each row's record view-model once (loads its frozen entries) so the
	// Covers column and the "View record" dialog share one derivation.
	$history_views = array();
	foreach ( $log['rows'] as $row ) {
		$history_views[] = array(
			'row' => $row,
			'rv'  => pltt_build_billing_record_view( $row['record'] ),
		);
	}
	?>
	<table class="widefat striped pltt-bill-records-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Billed', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Covers', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-amount-col"><?php esc_html_e( 'Amount', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-amount-col"><?php esc_html_e( 'Absorbed', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-bill-records-action"></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $history_views as $hv ) : ?>
				<?php
				$row       = $hv['row'];
				$rv        = $hv['rv'];
				$rec       = $row['record'];
				$dialog_id = 'pltt-recordview-' . (int) $rec->id;
				$is_over   = ( 'retainer_overage' === $rec->billing_type );
				$type_lbl  = $is_over ? __( 'Overage', 'plain-language-time-tracker' ) : __( 'Hourly', 'plain-language-time-tracker' );

				// Covers: retainer names its period + overage hours; hourly names its
				// entry count + span.
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
				<tr>
					<td class="pltt-time-cell">
						<?php echo esc_html( $rv['billed_on'] ); ?>
						<span class="pltt-bill-record-id">#<?php echo (int) $rec->id; ?></span>
					</td>
					<td>
						<?php
						echo '' !== $row['client_name']
							? esc_html( $row['client_name'] )
							: '<span class="pltt-empty">&mdash;</span>';
						?>
					</td>
					<td>
						<?php echo esc_html( $row['project_name'] ); ?>
						<span class="pltt-badge <?php echo esc_attr( pltt_billing_type_badge_class( $rec->billing_type ) ); ?>"><?php echo esc_html( $type_lbl ); ?></span>
					</td>
					<td class="pltt-bill-record-covers"><?php echo esc_html( $covers ); ?></td>
					<td class="pltt-amount-col"><?php echo esc_html( pltt_format_currency( $rv['amount'] ) ); ?></td>
					<td class="pltt-amount-col"><?php echo $rv['absorbed'] > 0.0 ? esc_html( pltt_format_currency( $rv['absorbed'] ) ) : '<span class="pltt-empty">&mdash;</span>'; ?></td>
					<td class="pltt-bill-records-action">
						<button type="button" class="button-link" data-lineitems-dialog="<?php echo esc_attr( $dialog_id ); ?>">
							<?php esc_html_e( 'View record', 'plain-language-time-tracker' ); ?>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	// Base URL that preserves every active filter (for pagination).
	$page_params = array(
		'page' => 'pltt-invoicing',
		'view' => 'history',
		'from' => $date_from,
		'to'   => $date_to,
	);
	if ( $h_client_id > 0 ) {
		$page_params['client_id'] = $h_client_id;
	}
	if ( '' !== $h_type ) {
		$page_params['type'] = $h_type;
	}
	pltt_render_pagination(
		(int) $log['paged'],
		(int) $log['total_pages'],
		(int) $log['count'],
		add_query_arg( $page_params, $reports_base ),
		'record',
		'records'
	);
	?>

	<?php // Record-detail dialogs live outside the table (a <dialog> can't sit in a <tbody>). ?>
	<?php foreach ( $history_views as $hv ) : ?>
		<?php
		$rv        = $hv['rv'];
		$dialog_id = 'pltt-recordview-' . (int) $hv['row']['record']->id;
		include PLTT_PLUGIN_DIR . 'templates/partials/billing-record-dialog.php';
		?>
	<?php endforeach; ?>

	<p class="description pltt-bill-recordnote">
		<?php esc_html_e( 'A record freezes what you billed and marks those entries billed, so “outstanding” resolves itself. There’s no sent/paid tracking — that’s your invoicing tool’s job.', 'plain-language-time-tracker' ); ?>
	</p>
<?php endif; ?>

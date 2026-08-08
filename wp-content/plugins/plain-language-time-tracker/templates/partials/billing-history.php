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

// Ledger-appropriate presets. 'phrase' feeds the dynamic card labels; 'unit' tells
// the prev/next stepper what one step means (see date-nav.php).
$presets = array(
	array(
		'label'  => __( 'This Month', 'plain-language-time-tracker' ),
		'phrase' => __( 'this month', 'plain-language-time-tracker' ),
		'from'   => $now_dt->format( 'Y-m-01' ),
		'to'     => $today,
		'unit'   => 'month',
	),
	array(
		'label'  => __( 'Last Month', 'plain-language-time-tracker' ),
		'phrase' => __( 'last month', 'plain-language-time-tracker' ),
		'from'   => $now_dt->modify( 'first day of last month' )->format( 'Y-m-01' ),
		'to'     => $now_dt->modify( 'first day of last month' )->format( 'Y-m-t' ),
		'unit'   => 'month',
	),
	array(
		'label'  => __( 'This Year', 'plain-language-time-tracker' ),
		'phrase' => __( 'this year', 'plain-language-time-tracker' ),
		'from'   => $now_dt->format( 'Y-01-01' ),
		'to'     => $today,
		'unit'   => 'year',
	),
	array(
		'label'  => __( 'Last Year', 'plain-language-time-tracker' ),
		'phrase' => __( 'last year', 'plain-language-time-tracker' ),
		'from'   => $last_yr . '-01-01',
		'to'     => $last_yr . '-12-31',
		'unit'   => 'year',
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

<div class="pltt-summary-cards pltt-numbar pltt-bill-cards-summary">
	<?php // Each card's second line names the basis as well as the dates, so a
	// figure lifted from here can't be mistaken for "work done in this range". ?>
	<div class="card">
		<div class="card-label"><?php echo esc_html( $card_label( __( 'Records invoiced', 'plain-language-time-tracker' ), $active_phrase ) ); ?></div>
		<div class="card-value"><?php echo esc_html( number_format_i18n( (int) $log['count'] ) ); ?></div>
		<div class="card-secondary"><?php echo esc_html( sprintf( /* translators: %s: date range. */ __( 'invoiced %s', 'plain-language-time-tracker' ), $range_label ) ); ?></div>
	</div>
	<div class="card">
		<div class="card-label"><?php echo esc_html( $card_label( __( 'Billed', 'plain-language-time-tracker' ), $active_phrase ) ); ?></div>
		<div class="card-value"><?php echo esc_html( pltt_format_currency( (float) $log['total_billed'] ) ); ?></div>
		<div class="card-secondary"><?php echo esc_html( sprintf( /* translators: %s: date range. */ __( 'invoiced %s', 'plain-language-time-tracker' ), $range_label ) ); ?></div>
	</div>
	<div class="card">
		<div class="card-label"><?php echo esc_html( $card_label( __( 'Absorbed', 'plain-language-time-tracker' ), $active_phrase ) ); ?></div>
		<div class="card-value pltt-bill-absorbed-value"><?php echo esc_html( pltt_format_currency( (float) $log['total_absorbed'] ) ); ?></div>
		<div class="card-secondary"><?php esc_html_e( 'written down at bill time', 'plain-language-time-tracker' ); ?></div>
	</div>
</div>

<?php if ( empty( $log['rows'] ) ) : ?>
	<?php
	pltt_render_empty_state(
		__( 'No records match these filters.', 'plain-language-time-tracker' ),
		__( 'Widen the date range or clear the filters. If you are looking for a particular job, check the month you sent the invoice rather than the month you did the work. Once you bill outstanding work, each record shows up here.', 'plain-language-time-tracker' )
	);
	?>
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
				<?php // "Invoiced" vs "Work covered": one date column and one period
				// column sat next to each other as "Billed" and "Covers", which left
				// it to the reader to work out which was which. ?>
				<th><?php esc_html_e( 'Invoiced', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Client', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Project', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Type', 'plain-language-time-tracker' ); ?></th>
				<th><?php esc_html_e( 'Work covered', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-amount-col"><?php esc_html_e( 'Amount', 'plain-language-time-tracker' ); ?></th>
				<th class="pltt-amount-col"><?php esc_html_e( 'Absorbed', 'plain-language-time-tracker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $history_views as $hv ) : ?>
				<?php
				$row     = $hv['row'];
				$rv      = $hv['rv'];
				$rec     = $row['record'];
				$is_over = ( 'retainer_overage' === $rec->billing_type );
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
				<?php $view_url = pltt_billing_record_view_url( $rec, $rv['entries'] ); ?>
				<tr>
					<td class="pltt-time-cell">
						<?php echo esc_html( $rv['billed_on'] ); ?>
						<span class="pltt-bill-record-id">#<?php echo (int) $rec->id; ?></span>
						<div class="row-actions">
							<?php // View is dropped when the project is gone — the link has nowhere to land. ?>
							<?php if ( '' !== $view_url ) : ?>
								<span class="view"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View record', 'plain-language-time-tracker' ); ?></a> | </span>
							<?php endif; ?>
							<?php // Undo a bill: drops the record + its coverage, un-billing the time. ?>
							<span class="trash">
								<a href="#delete" role="button"
									class="pltt-bill-record-delete submitdelete"
									data-record-id="<?php echo (int) $rec->id; ?>"
									data-confirm="<?php echo esc_attr( pltt_billing_record_delete_confirm( $rec, $rv ) ); ?>"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></a>
							</span>
						</div>
					</td>
					<td>
						<?php
						echo '' !== $row['client_name']
							? esc_html( $row['client_name'] )
							: '<span class="pltt-empty">&mdash;</span>';
						?>
					</td>
					<td>
						<?php
						$project_url = add_query_arg(
							array(
								'page'       => 'pltt-projects',
								'action'     => 'view',
								'project_id' => (int) $rec->project_id,
								'tab'        => 'report',
							),
							admin_url( 'admin.php' )
						);
						?>
						<a href="<?php echo esc_url( $project_url ); ?>"><?php echo esc_html( $row['project_name'] ); ?></a>
					</td>
					<td>
						<span class="pltt-badge <?php echo esc_attr( pltt_billing_type_badge_class( $rec->billing_type ) ); ?>"><?php echo esc_html( $type_lbl ); ?></span>
					</td>
					<td class="pltt-bill-record-covers"><?php echo esc_html( $covers ); ?></td>
					<td class="pltt-amount-col"><?php echo esc_html( pltt_format_currency( $rv['amount'] ) ); ?></td>
					<td class="pltt-amount-col"><?php echo $rv['absorbed'] > 0.0 ? esc_html( pltt_format_currency( $rv['absorbed'] ) ) : '<span class="pltt-empty">&mdash;</span>'; ?></td>
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

	<?php // "View record" opens the committed record inside the Overview detailed
	// view (read-only, with its Line-items dialog) — no in-page modal here.
	//
	// A standing paragraph used to sit here explaining that a record freezes what
	// was billed and that there is no sent/paid tracking. Dropped: it rendered
	// only alongside rows, so the one reader it could teach — someone meeting an
	// empty ledger — never saw it. The scope boundary lives in
	// design-philosophy.md; the mechanism in this file's docblock. ?>
<?php endif; ?>

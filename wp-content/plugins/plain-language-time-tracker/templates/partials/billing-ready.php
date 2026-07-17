<?php
/**
 * Billing · Ready to bill — outstanding work grouped by client.
 *
 * Summary cards (Outstanding / Billed this month / Absorbed this month) over the
 * existing per-project "Review & bill" scope cards. Each card links INTO the
 * detailed entries view (bill=1) where selection + commit happen; this view
 * lists no entries.
 *
 * Expects $queue (PLTT_Billing::get_invoicing_queue), $mtd
 * (PLTT_Billing::get_billed_totals for this month) and $reports_base in scope.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$q_clients = isset( $queue['clients'] ) ? $queue['clients'] : array();
?>

<div class="pltt-summary-cards pltt-bill-cards-summary">
	<div class="card">
		<div class="card-label"><?php esc_html_e( 'Outstanding', 'plain-language-time-tracker' ); ?></div>
		<div class="card-value pltt-bill-outstanding-value"><?php echo esc_html( pltt_format_currency( (float) $queue['grand_total'] ) ); ?></div>
		<div class="card-secondary">
			<?php
			printf(
				/* translators: 1: number of billable items; 2: number of clients. */
				esc_html__( '%1$s to bill · %2$s clients', 'plain-language-time-tracker' ),
				esc_html( number_format_i18n( (int) $queue['scope_count'] ) ),
				esc_html( number_format_i18n( count( $q_clients ) ) )
			);
			?>
		</div>
	</div>

	<div class="card">
		<div class="card-label"><?php esc_html_e( 'Billed this month', 'plain-language-time-tracker' ); ?></div>
		<div class="card-value"><?php echo esc_html( pltt_format_currency( (float) $mtd['billed'] ) ); ?></div>
		<div class="card-secondary">
			<?php
			printf(
				/* translators: %s: number of billing records this month. */
				esc_html( _n( '%s record', '%s records', (int) $mtd['count'], 'plain-language-time-tracker' ) ),
				esc_html( number_format_i18n( (int) $mtd['count'] ) )
			);
			?>
		</div>
	</div>

	<div class="card">
		<div class="card-label"><?php esc_html_e( 'Absorbed this month', 'plain-language-time-tracker' ); ?></div>
		<div class="card-value pltt-bill-absorbed-value"><?php echo esc_html( pltt_format_currency( (float) $mtd['absorbed'] ) ); ?></div>
		<div class="card-secondary"><?php esc_html_e( 'written down at bill time', 'plain-language-time-tracker' ); ?></div>
	</div>
</div>

<?php if ( empty( $q_clients ) ) : ?>
	<div class="pltt-card pltt-bill-empty">
		<p class="pltt-report-placeholder-lead"><?php esc_html_e( 'Nothing outstanding to bill.', 'plain-language-time-tracker' ); ?></p>
		<p class="description"><?php esc_html_e( 'When a retainer runs over, an hourly project has unbilled time, or a fixed budget is ready, it shows up here.', 'plain-language-time-tracker' ); ?></p>
	</div>
<?php else : ?>
	<?php $seen_proj = array(); // First card per project gets an anchor id (a project can have several period cards). ?>
	<?php foreach ( $q_clients as $group ) : ?>
		<?php $client_name = $group['client'] ? $group['client']->name : __( '(Unknown client)', 'plain-language-time-tracker' ); ?>
		<section class="pltt-bill-clientgroup">
			<header class="pltt-bill-clienthead">
				<h3 class="pltt-bill-clientname"><?php echo esc_html( $client_name ); ?></h3>
				<span class="pltt-bill-clienttotal">
					<?php
					printf(
						/* translators: %s: client's outstanding total, e.g. "$2,730". */
						esc_html__( '%s outstanding', 'plain-language-time-tracker' ),
						esc_html( pltt_format_currency( $group['total'] ) )
					);
					?>
				</span>
			</header>

			<div class="pltt-bill-cards">
				<?php foreach ( $group['scopes'] as $scope ) : ?>
					<?php
					$v         = pltt_build_billing_scope_view( $scope, $client_name );
					$proj      = $scope['project'];
					$is_hourly = ( 'hourly' === $scope['billing_type'] );

					// Anchor target for the Overview backlog link — only the first card
					// of each project (ids must be unique).
					$card_id = '';
					if ( ! isset( $seen_proj[ (int) $proj->id ] ) ) {
						$seen_proj[ (int) $proj->id ] = true;
						$card_id                      = 'pltt-bill-proj-' . (int) $proj->id;
					}

					// Open the detailed view on the range this scope bills, so all the
					// billable work is in view: retainer = its period; hourly/fixed = the
					// span of the unbilled entries through today.
					if ( 'retainer_overage' === $scope['billing_type'] ) {
						$range_from = (string) $scope['period_start'];
						$range_to   = (string) $scope['period_end'];
					} else {
						$range_from = pltt_get_current_date();
						foreach ( $scope['entries'] as $e ) {
							if ( $e->entry_date < $range_from ) {
								$range_from = $e->entry_date;
							}
						}
						$range_to = pltt_get_current_date();
					}
					$review_url = add_query_arg(
						array(
							'page'       => 'pltt-reports',
							'view'       => 'detailed',
							'client_id'  => (int) $proj->client_id,
							'project_id' => (int) $proj->id,
							'from'       => $range_from,
							'to'         => $range_to,
							'bill'       => 1, // Explicit "start a bill" flag — invokes the select row.
						),
						$reports_base
					);

					// One-line basis: hourly names its entry count; retainer/fixed name
					// their scope (period overage / project).
					if ( $is_hourly ) {
						$basis = sprintf(
							/* translators: %d: number of unbilled entries. */
							_n( '%d unbilled entry', '%d unbilled entries', $v['count'], 'plain-language-time-tracker' ),
							$v['count']
						);
					} else {
						$basis = $v['derivation'];
					}
					?>
					<div class="pltt-bill-card"<?php echo $card_id ? ' id="' . esc_attr( $card_id ) . '"' : ''; ?>>
						<div class="pltt-bill-card-head">
							<span class="pltt-bill-card-proj"><?php echo esc_html( $proj->name ); ?></span>
							<span class="pltt-badge <?php echo esc_attr( pltt_billing_type_badge_class( $scope['billing_type'] ) ); ?>"><?php echo esc_html( $v['type_label'] ); ?></span>
						</div>
						<?php if ( ! empty( $v['date_range'] ) ) : ?>
							<div class="pltt-bill-card-range">
								<?php echo esc_html( $v['date_range'] ); ?>
							</div>
						<?php endif; ?>
						<div class="pltt-bill-card-amount"><?php echo esc_html( pltt_format_currency( $scope['unbilled'] ) ); ?></div>
						<div class="pltt-bill-card-basis"><?php echo esc_html( $basis ); ?></div>
						<a class="button button-primary pltt-bill-card-action" href="<?php echo esc_url( $review_url ); ?>">
							<?php esc_html_e( 'Review &amp; bill', 'plain-language-time-tracker' ); ?> &rarr;
						</a>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endforeach; ?>
<?php endif; ?>

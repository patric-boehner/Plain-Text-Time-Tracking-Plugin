<?php
/**
 * Reports — Invoicing view (the cross-project queue).
 *
 * The top-of-funnel for billing: everything currently outstanding across all
 * active projects, grouped by client, each scope linking to the billing surface
 * (verify -> adjust -> commit). Not period-filtered — it's "what can I bill now."
 *
 * Rendered by PLTT_Admin::render_invoicing_page() (the Invoicing menu page).
 * A Ready-to-Invoice / Invoiced toggle switches between the outstanding queue and
 * the committed-records ledger. Expects $view ('ready'|'invoiced') in scope, plus
 * $queue (PLTT_Billing::get_invoicing_queue()) for the ready view or $log
 * (PLTT_Billing::get_invoiced_log()) for the invoiced view.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$inv_base = admin_url( 'admin.php?page=pltt-invoicing' );
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Invoicing', 'plain-language-time-tracker' ); ?></h1>
		<div class="pltt-view-toggle">
			<a href="<?php echo esc_url( add_query_arg( 'view', 'ready', $inv_base ) ); ?>"
				class="button <?php echo 'invoiced' === $view ? '' : 'button-primary'; ?>">
				<?php esc_html_e( 'Ready to Invoice', 'plain-language-time-tracker' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'view', 'invoiced', $inv_base ) ); ?>"
				class="button <?php echo 'invoiced' === $view ? 'button-primary' : ''; ?>">
				<?php esc_html_e( 'Invoiced', 'plain-language-time-tracker' ); ?>
			</a>
		</div>
	</div>

	<?php if ( 'invoiced' === $view ) : ?>
		<?php include PLTT_PLUGIN_DIR . 'templates/partials/invoicing-log.php'; ?>
	<?php elseif ( empty( $queue['clients'] ) ) : ?>
		<div class="pltt-card pltt-invoicing-empty">
			<p class="pltt-report-placeholder-lead"><?php esc_html_e( 'Nothing outstanding to invoice.', 'plain-language-time-tracker' ); ?></p>
			<p class="description"><?php esc_html_e( 'When a retainer runs over or hourly work is unbilled, it shows up here ready to bill.', 'plain-language-time-tracker' ); ?></p>
		</div>
	<?php else : ?>
		<p class="pltt-invoicing-lead">
			<strong class="pltt-inv-grand"><?php echo esc_html( pltt_format_currency( $queue['grand_total'] ) ); ?></strong>
			<?php esc_html_e( 'outstanding', 'plain-language-time-tracker' ); ?>
			· <span class="pltt-inv-items"><?php echo (int) $queue['scope_count']; ?></span> <?php esc_html_e( 'open', 'plain-language-time-tracker' ); ?>
			· <span class="pltt-inv-clients"><?php echo (int) count( $queue['clients'] ); ?></span> <?php esc_html_e( 'clients', 'plain-language-time-tracker' ); ?>
		</p>

		<?php
		foreach ( $queue['clients'] as $group ) :
			$client_name = $group['client'] ? $group['client']->name : __( '(Unknown client)', 'plain-language-time-tracker' );
			$client_id   = $group['client'] ? (int) $group['client']->id : 0;

			// Precompute a view-model per scope so the table rows and the dialogs
			// (rendered after the table — a <dialog> can't live inside <tbody>) share
			// one computation pass. pltt_build_billing_scope_view() is the shared
			// builder, so this matches the Reports single-project card exactly.
			$views = array();
			foreach ( $group['scopes'] as $scope ) {
				$views[] = pltt_build_billing_scope_view( $scope, $client_name );
			}
			?>
			<div class="pltt-card pltt-invoicing-client" data-client-id="<?php echo esc_attr( $client_id ); ?>">
				<div class="pltt-invoicing-client-head">
					<h2 class="pltt-invoicing-client-name"><?php echo esc_html( $client_name ); ?></h2>
					<span class="pltt-invoicing-client-total"><?php echo esc_html( pltt_format_currency( $group['total'] ) ); ?></span>
				</div>

				<ul class="pltt-invoicing-list">
					<?php foreach ( $views as $v ) : ?>
						<?php $scope = $v['scope']; $proj = $v['proj']; $panel_id = 'pltt-entries-' . $v['uid']; ?>
						<li class="pltt-invoicing-item" data-scope="<?php echo esc_attr( $v['uid'] ); ?>" data-amount="<?php echo esc_attr( number_format( (float) $scope['unbilled'], 2, '.', '' ) ); ?>">
							<button type="button" class="pltt-invoicing-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>">
								<span class="pltt-invoicing-proj">
									<span class="pltt-invoicing-title"><?php echo esc_html( $proj->name ); ?></span>
									<span class="pltt-invoicing-meta"><?php echo esc_html( $v['type_label'] . ' · ' . $v['date_range'] ); ?></span>
								</span>
								<span class="pltt-invoicing-col pltt-invoicing-entries">
									<?php
									/* translators: %d: number of time entries. */
									echo esc_html( sprintf( _n( '%d entry', '%d entries', $v['count'], 'plain-language-time-tracker' ), $v['count'] ) );
									?>
								</span>
								<span class="pltt-invoicing-col pltt-invoicing-hours"><?php echo esc_html( $v['hours_label'] ); ?></span>
								<span class="pltt-invoicing-col pltt-invoicing-amount"><?php echo esc_html( pltt_format_currency( $scope['unbilled'] ) ); ?></span>
								<span class="pltt-expand-caret" aria-hidden="true"></span>
							</button>

							<?php // Hourly scopes pick entries inline; retainer overage is one whole-period line. ?>
							<?php $inv_selectable = ( 'hourly' === $scope['billing_type'] && $v['count'] > 0 ); ?>
							<div class="pltt-invoicing-panel" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
								<?php if ( $v['count'] > 0 ) : ?>
									<?php if ( $inv_selectable ) : ?>
										<div class="pltt-invoicing-selectall">
											<label><input type="checkbox" class="pltt-inv-selectall-box" checked> <?php esc_html_e( 'Select all', 'plain-language-time-tracker' ); ?></label>
											<span class="pltt-invoicing-selecthint"><?php esc_html_e( 'Untick an entry to leave it unbilled — it stays open for a future invoice.', 'plain-language-time-tracker' ); ?></span>
										</div>
										<?php pltt_render_billing_manifest( $v['entries'], true ); ?>
									<?php else : ?>
										<p class="pltt-invoicing-refnote"><?php esc_html_e( 'Retainers bill the whole period’s overage — one line, not per entry.', 'plain-language-time-tracker' ); ?></p>
										<?php pltt_render_billing_manifest( $v['entries'] ); ?>
									<?php endif; ?>
								<?php endif; ?>
								<div class="pltt-invoicing-panel-foot">
									<div class="pltt-invoicing-selected">
										<?php if ( $inv_selectable ) : ?>
											<?php esc_html_e( 'Selected', 'plain-language-time-tracker' ); ?>
											(<span class="pltt-inv-sel-count"><?php echo (int) $v['count']; ?></span>)
										<?php else : ?>
											<?php esc_html_e( 'Overage', 'plain-language-time-tracker' ); ?>
										<?php endif; ?>
										<span class="pltt-inv-sel-total"><?php echo esc_html( pltt_format_currency( $scope['unbilled'] ) ); ?></span>
									</div>
									<div class="pltt-invoicing-panel-actions">
										<a class="pltt-invoicing-viewproject" href="<?php echo esc_url( PLTT_Project_Detail::get_url( (int) $proj->id ) ); ?>">
											<?php esc_html_e( 'View project', 'plain-language-time-tracker' ); ?>
										</a>
										<button type="button" class="button" data-lineitems-dialog="pltt-billcopy-<?php echo esc_attr( $v['uid'] ); ?>">
											<?php esc_html_e( 'Line items…', 'plain-language-time-tracker' ); ?>
										</button>
										<button type="button" class="button button-primary" data-bill-dialog="pltt-bill-<?php echo esc_attr( $v['uid'] ); ?>">
											<?php esc_html_e( 'Record bill', 'plain-language-time-tracker' ); ?> &rarr;
										</button>
									</div>
								</div>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php foreach ( $views as $v ) : ?>
					<?php include PLTT_PLUGIN_DIR . 'templates/partials/billing-dialog.php'; ?>
					<?php include PLTT_PLUGIN_DIR . 'templates/partials/billing-copy-dialog.php'; ?>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>

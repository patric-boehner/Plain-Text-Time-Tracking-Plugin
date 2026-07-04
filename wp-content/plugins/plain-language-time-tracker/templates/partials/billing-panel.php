<?php
/**
 * Inline billing panel — the settings box lifted out of the old Review & bill
 * modal and dropped onto the Reports single-project detailed view.
 *
 * It sits above the project's EXISTING entry list (no second list is rendered):
 * for hourly projects the user picks entries with the checkboxes already on those
 * rows; retainer bills the whole overage period, so it has no checkboxes. Nothing
 * navigates — "Review & bill" just reveals this panel in place, and Finalize
 * commits via AJAX (pltt_commit_billing), then refreshes the same filtered view.
 *
 * The only remaining modal is the "Copy line items" helper (billing-copy-dialog).
 *
 * Expects in scope:
 *   $v          — scope view-model from pltt_build_billing_scope_view().
 *   $selectable — bool; true for hourly (per-entry checkboxes drive the total).
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$scope       = $v['scope'];
$proj        = $v['proj'];
$is_hourly   = ( 'hourly' === $scope['billing_type'] );
$selectable  = ! empty( $selectable );
$uid         = $v['uid'];
$computed    = (float) $scope['unbilled'];
$computed_fx = number_format( $computed, 2, '.', '' );
$count       = isset( $v['count'] ) ? (int) $v['count'] : 0;
$rate        = (float) $scope['rate'];

if ( $is_hourly ) {
	$subtitle = sprintf(
		/* translators: 1: project name, 2: hourly rate, e.g. "$100.00". */
		__( '%1$s · hourly %2$s/hr', 'plain-language-time-tracker' ),
		$proj->name,
		pltt_format_currency( $rate )
	);
} else {
	$subtitle = sprintf(
		/* translators: %s: project name. */
		__( '%s · retainer overage', 'plain-language-time-tracker' ),
		$proj->name
	);
}
?>
<div
	class="pltt-billing-panel"
	data-scope="<?php echo esc_attr( $uid ); ?>"
	data-project-id="<?php echo esc_attr( (int) $proj->id ); ?>"
	data-billing-type="<?php echo esc_attr( $scope['billing_type'] ); ?>"
	data-period="<?php echo esc_attr( (string) $scope['period_start'] ); ?>"
	data-selectable="<?php echo $selectable ? '1' : '0'; ?>"
	data-computed="<?php echo esc_attr( $computed_fx ); ?>"
	hidden
>
	<div class="pltt-bill-panel">
		<div class="pltt-bill-panel-head">
			<div class="pltt-bill-panel-titles">
				<h2 class="pltt-bill-panel-title"><?php esc_html_e( 'New billing record', 'plain-language-time-tracker' ); ?></h2>
				<p class="pltt-bill-panel-sub"><?php echo esc_html( $subtitle ); ?></p>
				<span class="pltt-bill-scope-pill">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: %s: the date span this record covers, e.g. "Jun 5 – Jun 8, 2026". */
						esc_html__( 'Scope · %s', 'plain-language-time-tracker' ),
						esc_html( $v['date_range'] )
					);
					?>
				</span>
			</div>
			<div class="pltt-bill-panel-actions">
				<button type="button" class="button pltt-bill-copy-open" data-copy-dialog="pltt-billcopy-<?php echo esc_attr( $uid ); ?>">
					<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
					<?php esc_html_e( 'Copy line items', 'plain-language-time-tracker' ); ?>
				</button>
				<button type="button" class="button pltt-bill-cancel"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
				<button type="button" class="button button-primary pltt-bill-finalize">
					<?php esc_html_e( 'Finalize record', 'plain-language-time-tracker' ); ?>
				</button>
			</div>
		</div>

		<div class="pltt-bill-desc-row">
			<label class="pltt-bill-desc-label" for="pltt-billdesc-<?php echo esc_attr( $uid ); ?>">
				<?php esc_html_e( 'Invoice description', 'plain-language-time-tracker' ); ?>
			</label>
			<input
				type="text"
				id="pltt-billdesc-<?php echo esc_attr( $uid ); ?>"
				class="pltt-bill-desc-input regular-text"
				value="<?php echo esc_attr( $v['default_desc'] ); ?>"
			>
		</div>

		<div class="pltt-bill-stat-cards">
			<div class="pltt-bill-stat">
				<div class="card-label">
					<?php esc_html_e( 'Computed', 'plain-language-time-tracker' ); ?>
					<?php if ( $selectable ) : ?>
						<span class="pltt-bill-computed-count">
							<?php
							printf(
								/* translators: 1: checked entry count, 2: total entry count. */
								esc_html__( '%1$d of %2$d', 'plain-language-time-tracker' ),
								(int) $count,
								(int) $count
							);
							?>
						</span>
					<?php endif; ?>
				</div>
				<div class="card-value pltt-bill-computed"><?php echo esc_html( pltt_format_currency( $computed ) ); ?></div>
			</div>

			<div class="pltt-bill-stat">
				<div class="card-label">
					<label for="pltt-billamt-<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Invoice amount', 'plain-language-time-tracker' ); ?></label>
				</div>
				<div class="card-value pltt-bill-amount-field">
					<span class="pltt-bill-currency">$</span>
					<input
						type="number"
						id="pltt-billamt-<?php echo esc_attr( $uid ); ?>"
						class="pltt-bill-amount-input"
						step="0.01"
						min="0"
						max="<?php echo esc_attr( $computed_fx ); ?>"
						value="<?php echo esc_attr( $computed_fx ); ?>"
					>
				</div>
			</div>

			<div class="pltt-bill-stat pltt-bill-stat-absorbed">
				<div class="card-label"><?php esc_html_e( 'Absorbed', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value pltt-bill-absorbed"><?php echo esc_html( pltt_format_currency( 0 ) ); ?></div>
			</div>
		</div>

		<p class="pltt-bill-error" role="alert" hidden></p>
	</div>

	<?php if ( $selectable ) : ?>
		<div class="pltt-bill-select-toolbar">
			<span class="pltt-bill-select-label"><?php esc_html_e( 'Selecting entries for this record — check or uncheck rows below', 'plain-language-time-tracker' ); ?></span>
			<div class="pltt-bill-select-actions">
				<button type="button" class="button pltt-bill-check-all"><?php esc_html_e( 'Check all', 'plain-language-time-tracker' ); ?></button>
				<button type="button" class="button pltt-bill-uncheck-all"><?php esc_html_e( 'Uncheck all', 'plain-language-time-tracker' ); ?></button>
			</div>
		</div>
	<?php endif; ?>
</div>

<?php
// The one remaining modal: the Copy line items helper (default / structured list
// + AI prompt). A <dialog> renders in the top layer, so its DOM position is free.
include PLTT_PLUGIN_DIR . 'templates/partials/billing-copy-dialog.php';

<?php
/**
 * One invoice-line card on the billing surface.
 *
 * Expects in scope:
 *   $project object Project row.
 *   $scope   array  Outstanding scope from PLTT_Billing::get_scope().
 *
 * The editable amount defaults to the unbilled remainder and only ever lowers the
 * bill (absorption); the commit handler recomputes the figure server-side.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_retainer = ( 'retainer_overage' === $scope['billing_type'] );
$rate        = (float) $scope['rate'];
$remainder   = (float) $scope['unbilled'];
$entries     = isset( $scope['entries'] ) ? $scope['entries'] : array();

// Basis minutes: retainer = overage minutes; hourly = sum of manifest durations.
$basis_minutes = $scope['minutes'];
if ( null === $basis_minutes ) {
	$basis_minutes = 0;
	foreach ( $entries as $e ) {
		$basis_minutes += (int) $e->duration_minutes;
	}
}

// Derivation line under the amount.
if ( $is_retainer ) {
	$derivation = sprintf(
		/* translators: 1: overage duration, 2: hourly rate. */
		__( '%1$s over allocation × %2$s/hr', 'plain-language-time-tracker' ),
		pltt_format_duration( $basis_minutes ),
		pltt_format_currency( $rate )
	);
} else {
	$derivation = sprintf(
		/* translators: 1: billable duration, 2: hourly rate. */
		__( '%1$s billable × %2$s/hr', 'plain-language-time-tracker' ),
		pltt_format_duration( $basis_minutes ),
		pltt_format_currency( $rate )
	);
}

// Already-billed context (only when prior records exist for this scope).
$prior_billed   = (float) $scope['billed'];
$prior_absorbed = (float) $scope['absorbed'];
$has_priors     = ( $prior_billed + $prior_absorbed ) > 0.005;

// Deterministic description default (the AI composer replaces this later).
if ( $is_retainer ) {
	$default_description = sprintf(
		/* translators: %s: billing period label, e.g. "June 2026". */
		__( 'Support beyond your plan — %s', 'plain-language-time-tracker' ),
		$scope['period_label']
	);
} else {
	$descs = array();
	foreach ( $entries as $e ) {
		$d = trim( (string) $e->description );
		if ( '' !== $d ) {
			$descs[ strtolower( $d ) ] = $d;
		}
	}
	$default_description = implode( '; ', array_slice( array_values( $descs ), 0, 12 ) );
}
?>

<div class="pltt-billing-card">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pltt-billing-form">
		<?php wp_nonce_field( 'pltt_commit_billing' ); ?>
		<input type="hidden" name="action" value="pltt_commit_billing">
		<input type="hidden" name="project_id" value="<?php echo esc_attr( (int) $project->id ); ?>">
		<input type="hidden" name="billing_type" value="<?php echo esc_attr( $scope['billing_type'] ); ?>">
		<input type="hidden" name="period" value="<?php echo esc_attr( (string) $scope['period_start'] ); ?>">

		<div class="pltt-billing-card-head">
			<span class="pltt-billing-period"><?php echo esc_html( $scope['period_label'] ); ?></span>
		</div>

		<div class="pltt-billing-amount-row">
			<label class="pltt-billing-amount-label" for="pltt-billed-amount">
				<?php esc_html_e( 'Amount to bill', 'plain-language-time-tracker' ); ?>
			</label>
			<div class="pltt-billing-amount-field">
				<span class="pltt-billing-currency">$</span>
				<input
					type="number"
					id="pltt-billed-amount"
					name="billed_amount"
					step="0.01"
					min="0"
					max="<?php echo esc_attr( number_format( $remainder, 2, '.', '' ) ); ?>"
					value="<?php echo esc_attr( number_format( $remainder, 2, '.', '' ) ); ?>"
					class="pltt-billing-amount-input"
				>
			</div>
			<p class="pltt-billing-derivation">
				<?php echo esc_html( $derivation ); ?>
				<?php if ( $has_priors ) : ?>
					<span class="pltt-billing-prior">
						<?php
						printf(
							/* translators: %s: amount already accounted for by earlier records. */
							esc_html__( '· %s already billed/absorbed for this period', 'plain-language-time-tracker' ),
							esc_html( pltt_format_currency( $prior_billed + $prior_absorbed ) )
						);
						?>
					</span>
				<?php endif; ?>
			</p>
			<p class="pltt-billing-hint">
				<?php esc_html_e( 'Lower the amount to absorb part of the charge; the difference is recorded as absorbed.', 'plain-language-time-tracker' ); ?>
			</p>
		</div>

		<div class="pltt-billing-description-row">
			<label class="pltt-billing-label" for="pltt-billing-description">
				<?php esc_html_e( 'Invoice description', 'plain-language-time-tracker' ); ?>
			</label>
			<textarea
				id="pltt-billing-description"
				name="description"
				rows="3"
				class="pltt-billing-description"
			><?php echo esc_textarea( $default_description ); ?></textarea>
		</div>

		<?php if ( ! empty( $entries ) ) : ?>
			<details class="pltt-billing-manifest">
				<summary>
					<?php
					$count = count( $entries );
					printf(
						/* translators: %d: number of included entries. */
						esc_html( _n( 'Included — %d entry', 'Included — %d entries', $count, 'plain-language-time-tracker' ) ),
						(int) $count
					);
					?>
				</summary>
				<?php pltt_render_billing_manifest( $entries ); ?>
			</details>
		<?php endif; ?>

		<div class="pltt-billing-actions">
			<button type="submit" name="billing_action" value="bill" class="button button-primary button-hero">
				<?php esc_html_e( 'Bill', 'plain-language-time-tracker' ); ?>
			</button>
			<a href="<?php echo esc_url( $back_url ); ?>" class="pltt-billing-leaveopen">
				<?php esc_html_e( 'Leave open', 'plain-language-time-tracker' ); ?>
			</a>
		</div>
	</form>
</div>

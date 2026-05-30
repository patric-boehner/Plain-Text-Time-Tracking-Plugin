<?php
/**
 * Client context card — shown on the Reports page when a single client is selected.
 *
 * Expects in scope:
 *   $context_client   — client object (id, name, hourly_rate, ...)
 *   $context_projects — array of project objects to display
 *   $client_id        — selected client id (int)
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $context_client ) ) {
	return;
}

$is_internal_client = ! empty( $context_client->is_internal );
?>
<div class="card pltt-client-context-card">
	<div class="pltt-context-header">
		<h2><?php echo esc_html( $context_client->name ); ?></h2>
	</div>

	<?php if ( $is_internal_client ) : ?>
		<?php // Internal client — projects have no billable rates worth reviewing. ?>
	<?php elseif ( empty( $context_projects ) ) : ?>
		<?php
		$client_rate = (float) ( $context_client->hourly_rate ?? 0 );
		$fallback_rate   = pltt_resolve_billable_rate( (int) $context_client->id, 0 );
		$fallback_source = $client_rate > 0
			? __( 'client rate', 'plain-language-time-tracker' )
			: __( 'default', 'plain-language-time-tracker' );
		?>
		<div class="pltt-context-projects">
			<div class="pltt-context-project">
				<div class="pltt-context-project-meta">
					<?php echo esc_html( pltt_format_currency( $fallback_rate ) . '/hr (' . $fallback_source . ')' ); ?>
				</div>
			</div>
		</div>
	<?php else : ?>
		<div class="pltt-context-projects">
			<?php foreach ( $context_projects as $project ) :
				$pid          = (int) $project->id;
				$billing_type = pltt_get_billing_type( $project );
				$is_internal  = ! empty( $context_client->is_internal ) || 'none' === $billing_type;

				$meta_parts = array();

				if ( $is_internal ) {
					$meta_parts[] = __( 'N/A', 'plain-language-time-tracker' );
				} else {
					$project_rate   = (float) ( $project->hourly_rate ?? 0 );
					$effective_rate = pltt_resolve_billable_rate( (int) $context_client->id, $pid );

					if ( $project_rate > 0 ) {
						$rate_source = '';
					} elseif ( (float) ( $context_client->hourly_rate ?? 0 ) > 0 ) {
						$rate_source = __( 'client rate', 'plain-language-time-tracker' );
					} else {
						$rate_source = __( 'default', 'plain-language-time-tracker' );
					}

					$meta_parts[] = pltt_format_currency( $effective_rate ) . '/hr'
						. ( $rate_source ? ' (' . $rate_source . ')' : '' );
				}

				if ( ! empty( $project->recurring_period ) ) {
					$meta_parts[] = ucfirst( $project->recurring_period );
				}

				$budget_parts = array();
				if ( ! empty( $project->budget_hours ) ) {
					$budget_parts[] = pltt_format_hours( (float) $project->budget_hours * 60 );
				}
				if ( ! empty( $project->budget_fee ) ) {
					$budget_parts[] = pltt_format_currency( $project->budget_fee );
				}
				if ( ! empty( $budget_parts ) ) {
					$meta_parts[] = implode( ' / ', $budget_parts );
				}
				?>
				<div class="pltt-context-project">
					<div class="pltt-context-project-name"><?php echo esc_html( $project->name ); ?></div>
					<div class="pltt-context-project-meta">
						<?php echo esc_html( implode( ' · ', $meta_parts ) ); ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<?php
/**
 * Billing surface — verify, adjust, commit one billing record.
 *
 * Rendered by PLTT_Billing_Surface::render(). Expects in scope:
 *   $project      object       Project row.
 *   $scope        array|null   Outstanding scope from PLTT_Billing::get_scope(); null = nothing due.
 *   $billing_type string       'hourly' | 'retainer_overage'.
 *   $back_url     string       URL back to the project page.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$client = isset( $project->client_id ) ? PLTT_Clients::get( (int) $project->client_id ) : null;
?>

<div class="wrap pltt-wrap pltt-billing">

	<div class="pltt-header pltt-detail-header">
		<div class="pltt-detail-titlewrap">
			<div class="pltt-detail-title">
				<h1><?php esc_html_e( 'Review &amp; Invoice', 'plain-language-time-tracker' ); ?></h1>
				<?php pltt_render_billing_type_badge( pltt_get_billing_type( $project ) ); ?>
			</div>
			<p class="pltt-detail-subhead">
				<?php
				$crumb = $client ? $client->name . ' · ' . $project->name : $project->name;
				echo esc_html( $crumb );
				?>
			</p>
		</div>

		<div class="pltt-header-actions">
			<a href="<?php echo esc_url( $back_url ); ?>" class="button">
				&larr; <?php esc_html_e( 'Back to project', 'plain-language-time-tracker' ); ?>
			</a>
		</div>
	</div>

	<?php if ( ! $scope ) : ?>
		<div class="notice notice-info pltt-billing-empty">
			<p><?php esc_html_e( 'Nothing is outstanding to bill for this project right now.', 'plain-language-time-tracker' ); ?></p>
		</div>
	<?php else : ?>
		<?php include PLTT_PLUGIN_DIR . 'templates/partials/billing-card.php'; ?>
	<?php endif; ?>
</div>

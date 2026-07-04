<?php
/**
 * The billing surface — the one verify-and-commit screen.
 *
 * Reached from the project page's "Review & bill" at
 * admin.php?page=pltt-projects&action=bill&project_id=N&type=...&period=YYYY-MM-DD.
 * Renders the invoice-line card for one outstanding scope; committing posts to
 * the pltt_commit_billing form handler, which writes the billing record. Nothing
 * here writes anything — display only.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the billing surface.
 */
class PLTT_Billing_Surface {

	/**
	 * Render the surface for the requested project + scope.
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$project_id   = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0;
		$billing_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$period       = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$project = $project_id ? PLTT_Projects::get( $project_id ) : null;
		if ( ! $project ) {
			self::render_not_found();
			return;
		}

		// Derive the billing type when not supplied (defensive — the project page
		// always passes it).
		if ( ! in_array( $billing_type, PLTT_Billing_Records::TYPES, true ) ) {
			$billing_type = ( 'recurring' === pltt_get_billing_type( $project ) )
				? 'retainer_overage'
				: 'hourly';
		}

		$period_start = ( 'retainer_overage' === $billing_type && '' !== $period )
			? pltt_sanitize_date_strict( $period )
			: null;

		$scope    = PLTT_Billing::get_scope( $project, $billing_type, $period_start );
		$back_url = PLTT_Project_Detail::get_url( $project_id );

		include PLTT_PLUGIN_DIR . 'templates/billing.php';
	}

	/**
	 * Render a clean "project not found" view inside the admin chrome.
	 */
	private static function render_not_found() {
		$list_url = admin_url( 'admin.php?page=pltt-projects' );
		?>
		<div class="wrap pltt-wrap">
			<h1><?php esc_html_e( 'Project not found', 'plain-language-time-tracker' ); ?></h1>
			<p><?php esc_html_e( 'That project could not be found.', 'plain-language-time-tracker' ); ?></p>
			<p><a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Back to Projects', 'plain-language-time-tracker' ); ?></a></p>
		</div>
		<?php
	}
}

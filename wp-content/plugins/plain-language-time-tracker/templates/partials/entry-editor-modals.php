<?php
/**
 * Create-new modals (client / tag / project) for the inline entry editor.
 *
 * Shared by the review screen and the Daily Log (Today) inline editor. The save
 * buttons are wired by review.js (IIFE 2 binds them on any page that has the
 * editable list but no post-parse form).
 *
 * The project modal reuses the shared billing-type picker (project-billing-fields.php
 * + project-type-picker.js) so "Add project" here offers the same type choice as
 * the Projects page — minus the alias/status/delete bits, which don't belong in a
 * mid-entry quick-add. Client stays a deliberately light Name + rate.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- New Client Modal -->
<div id="pltt-client-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-review-client-modal-title">
	<div class="pltt-modal-content">
		<h3 id="pltt-review-client-modal-title"><?php esc_html_e( 'Add New Client', 'plain-language-time-tracker' ); ?></h3>
		<p>
			<label for="pltt-new-client-name"><?php esc_html_e( 'Client Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-new-client-name" class="regular-text widefat">
		</p>
		<p>
			<label for="pltt-new-client-rate"><?php esc_html_e( 'Hourly Rate', 'plain-language-time-tracker' ); ?> <span class="pltt-optional"><?php esc_html_e( '(optional)', 'plain-language-time-tracker' ); ?></span></label>
			<div class="pltt-input-adornment-wrap">
				<span class="pltt-adornment pltt-adornment-prefix">$</span>
				<input type="text" inputmode="decimal" id="pltt-new-client-rate" placeholder="<?php echo esc_attr( PLTT_DEFAULT_HOURLY_RATE ); ?>" class="widefat pltt-currency-input">
			</div>
		</p>
		<div class="pltt-modal-actions">
			<div class="pltt-modal-actions-right">
				<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
				<button type="button" id="pltt-save-client" class="button button-primary"><?php esc_html_e( 'Create Client', 'plain-language-time-tracker' ); ?></button>
			</div>
		</div>
	</div>
</div>

<!-- New Tag Modal -->
<div id="pltt-tag-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-review-tag-modal-title">
	<div class="pltt-modal-content">
		<h3 id="pltt-review-tag-modal-title"><?php esc_html_e( 'Add New Tag', 'plain-language-time-tracker' ); ?></h3>
		<p>
			<label for="pltt-new-tag-name"><?php esc_html_e( 'Tag Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-new-tag-name" class="regular-text widefat">
		</p>
		<div class="pltt-modal-actions">
			<div class="pltt-modal-actions-right">
				<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
				<button type="button" id="pltt-save-tag" class="button button-primary"><?php esc_html_e( 'Create Tag', 'plain-language-time-tracker' ); ?></button>
			</div>
		</div>
	</div>
</div>

<!-- New Project Modal -->
<div id="pltt-project-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-review-project-modal-title">
	<div class="pltt-modal-content">
		<h3 id="pltt-review-project-modal-title"><?php esc_html_e( 'Add New Project', 'plain-language-time-tracker' ); ?></h3>
		<p>
			<label for="pltt-project-name"><?php esc_html_e( 'Project Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-project-name" class="regular-text widefat">
			<?php // review.js presets the owning client (the entry's client) before opening. ?>
			<input type="hidden" id="pltt-new-project-client-id">
		</p>

		<?php
		// Compact: the quick-add shows the type cards only (no allocation/budget
		// inputs — those are project setup, done later in Projects). The type still
		// sets the new entry's billable default, which is what matters mid-capture.
		$pltt_billing_compact = true;
		include PLTT_PLUGIN_DIR . 'templates/partials/project-billing-fields.php';
		?>

		<div class="pltt-modal-actions">
			<div class="pltt-modal-actions-right">
				<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
				<button type="button" id="pltt-save-project" class="button button-primary"><?php esc_html_e( 'Create Project', 'plain-language-time-tracker' ); ?></button>
			</div>
		</div>
	</div>
</div>

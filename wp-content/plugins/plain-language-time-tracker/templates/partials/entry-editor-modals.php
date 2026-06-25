<?php
/**
 * Create-new modals (client / tag / project) for the inline entry editor.
 *
 * Shared by the review screen and the Daily Log (Today) inline editor. The save
 * buttons are wired by review.js (IIFE 2 binds them on any page that has the
 * editable list but no post-parse form).
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
			<input type="text" id="pltt-new-client-name" class="regular-text">
		</p>
		<p>
			<label for="pltt-new-client-rate"><?php esc_html_e( 'Hourly Rate (Optional)', 'plain-language-time-tracker' ); ?></label>
			<div class="pltt-input-adornment-wrap">
				<span class="pltt-adornment pltt-adornment-prefix">$</span>
				<input type="text" inputmode="decimal" id="pltt-new-client-rate" placeholder="<?php echo esc_attr( PLTT_DEFAULT_HOURLY_RATE ); ?>" class="regular-text pltt-currency-input">
			</div>
		</p>
		<p class="pltt-modal-actions">
			<button type="button" id="pltt-save-client" class="button button-primary"><?php esc_html_e( 'Create Client', 'plain-language-time-tracker' ); ?></button>
			<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</p>
	</div>
</div>

<!-- New Tag Modal -->
<div id="pltt-tag-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-review-tag-modal-title">
	<div class="pltt-modal-content">
		<h3 id="pltt-review-tag-modal-title"><?php esc_html_e( 'Add New Tag', 'plain-language-time-tracker' ); ?></h3>
		<p>
			<label for="pltt-new-tag-name"><?php esc_html_e( 'Tag Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-new-tag-name" class="regular-text">
		</p>
		<p class="pltt-modal-actions">
			<button type="button" id="pltt-save-tag" class="button button-primary"><?php esc_html_e( 'Create Tag', 'plain-language-time-tracker' ); ?></button>
			<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</p>
	</div>
</div>

<!-- New Project Modal -->
<div id="pltt-project-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-review-project-modal-title">
	<div class="pltt-modal-content">
		<h3 id="pltt-review-project-modal-title"><?php esc_html_e( 'Add New Project', 'plain-language-time-tracker' ); ?></h3>
		<p>
			<label for="pltt-new-project-name"><?php esc_html_e( 'Project Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-new-project-name" class="regular-text">
			<input type="hidden" id="pltt-new-project-client-id">
		</p>
		<p>
			<label for="pltt-new-project-rate"><?php esc_html_e( 'Hourly Rate (Optional)', 'plain-language-time-tracker' ); ?></label>
			<div class="pltt-input-adornment-wrap">
				<span class="pltt-adornment pltt-adornment-prefix">$</span>
				<input type="text" inputmode="decimal" id="pltt-new-project-rate" placeholder="<?php esc_attr_e( 'Inherits from client', 'plain-language-time-tracker' ); ?>" class="regular-text pltt-currency-input">
			</div>
		</p>
		<p class="pltt-modal-actions">
			<button type="button" id="pltt-save-project" class="button button-primary"><?php esc_html_e( 'Create Project', 'plain-language-time-tracker' ); ?></button>
			<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
		</p>
	</div>
</div>

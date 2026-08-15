<?php
/**
 * "Add New Tag" modal — the create-a-tag path behind the tag picker's
 * "+ Add new tag…" link.
 *
 * Included by entry-editor-modals.php (Review / Today) and directly by
 * reports.php (Overview), so every screen with an editable tag picker offers
 * the same link. Wired by PLTT.wireTagModal() in shared.js.
 *
 * The Tags admin page has its own richer #pltt-tag-modal (name + group +
 * keywords); the two never render on the same screen. Keywords stay there —
 * they're taxonomy upkeep, not something to stop for mid-capture — but the
 * group is offered here so a new tag files itself under the right heading in
 * the picker straight away.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pltt_tag_modal_groups = PLTT_Tags::get_all_groups();
?>
<div id="pltt-tag-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-review-tag-modal-title">
	<div class="pltt-modal-content">
		<h3 id="pltt-review-tag-modal-title"><?php esc_html_e( 'Add New Tag', 'plain-language-time-tracker' ); ?></h3>
		<p>
			<label for="pltt-new-tag-name"><?php esc_html_e( 'Tag Name', 'plain-language-time-tracker' ); ?></label>
			<input type="text" id="pltt-new-tag-name" class="regular-text widefat">
		</p>
		<p>
			<label for="pltt-new-tag-group-select"><?php esc_html_e( 'Group (optional)', 'plain-language-time-tracker' ); ?></label>
			<select id="pltt-new-tag-group-select" class="widefat">
				<option value=""><?php esc_html_e( '— No group —', 'plain-language-time-tracker' ); ?></option>
				<?php foreach ( $pltt_tag_modal_groups as $pltt_tag_modal_group ) : ?>
					<option value="<?php echo esc_attr( $pltt_tag_modal_group ); ?>"><?php echo esc_html( $pltt_tag_modal_group ); ?></option>
				<?php endforeach; ?>
				<option value="__new__"><?php esc_html_e( '+ New group…', 'plain-language-time-tracker' ); ?></option>
			</select>
			<?php // Shown only when "+ New group…" is picked; PLTT.wireTagModal() reads whichever is live. ?>
			<input type="text" id="pltt-new-tag-group-new" class="regular-text widefat pltt-hidden" placeholder="<?php esc_attr_e( 'New group name', 'plain-language-time-tracker' ); ?>" autocomplete="off">
		</p>
		<div class="pltt-modal-actions">
			<div class="pltt-modal-actions-right">
				<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
				<button type="button" id="pltt-save-tag" class="button button-primary"><?php esc_html_e( 'Create Tag', 'plain-language-time-tracker' ); ?></button>
			</div>
		</div>
	</div>
</div>

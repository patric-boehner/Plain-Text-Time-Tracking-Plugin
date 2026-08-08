<?php
/**
 * Tags management template.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$all_tags        = PLTT_Tags::get_all_with_counts();
$existing_groups = PLTT_Tags::get_all_groups();

// Bulk-load keyword seeds grouped by tag for the settings chip manager.
$keywords_by_tag = array();
foreach ( PLTT_Tag_Aliases::get_all() as $pltt_kw ) {
	$keywords_by_tag[ (int) $pltt_kw->tag_id ][] = array(
		'id'   => (int) $pltt_kw->id,
		'text' => $pltt_kw->keyword,
		'use'  => (int) $pltt_kw->use_count,
	);
}

// Build group buckets so the page renders one table per group.
$tag_groups     = array();
$ungrouped_tags = array();
foreach ( $all_tags as $tag ) {
	$g = ! empty( $tag->group_name ) ? $tag->group_name : '';
	if ( '' === $g ) {
		$ungrouped_tags[] = $tag;
	} else {
		$tag_groups[ $g ][] = $tag;
	}
}
ksort( $tag_groups );

$tag_sections = array();
foreach ( $tag_groups as $label => $tags ) {
	$tag_sections[] = array(
		'label' => $label,
		'tags'  => $tags,
	);
}
if ( ! empty( $ungrouped_tags ) ) {
	$tag_sections[] = array(
		'label' => __( 'Ungrouped', 'plain-language-time-tracker' ),
		'tags'  => $ungrouped_tags,
	);
}
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></h1>
		<?php
		// OPT-DUP1: display success/error notices via shared helper.
		pltt_render_admin_notices(
			array(
				'tag_created' => __( 'Tag created successfully.', 'plain-language-time-tracker' ),
				'tag_renamed' => __( 'Tag renamed successfully.', 'plain-language-time-tracker' ),
				'tag_deleted' => __( 'Tag deleted successfully.', 'plain-language-time-tracker' ),
			),
			array(
				'tag_exists'        => __( 'A tag with that name already exists.', 'plain-language-time-tracker' ),
				'tag_rename_failed' => __( 'Failed to rename tag.', 'plain-language-time-tracker' ),
				'tag_create_failed' => __( 'Failed to create tag.', 'plain-language-time-tracker' ),
				'tag_delete_failed' => __( 'Failed to delete tag.', 'plain-language-time-tracker' ),
				'tag_group_failed'  => __( 'Failed to update tag group.', 'plain-language-time-tracker' ),
				'tag_too_long'      => __( 'Tag name too long (max 100 characters).', 'plain-language-time-tracker' ),
				'invalid_tag'       => __( 'Invalid tag name.', 'plain-language-time-tracker' ),
				'no_tags_selected'  => __( 'No tags selected.', 'plain-language-time-tracker' ),
			)
		);
		?>
		<div class="pltt-header-actions">
			<button type="button" id="pltt-add-tag-btn" class="button button-primary">
				<?php esc_html_e( 'Add Tag', 'plain-language-time-tracker' ); ?>
			</button>
		</div>
	</div>

	<?php if ( empty( $all_tags ) ) : ?>
		<?php
		pltt_render_empty_state(
			__( 'No tags yet.', 'plain-language-time-tracker' ),
			__( 'Tags will appear here once you add them to time entries or create them manually.', 'plain-language-time-tracker' )
		);
		?>
	<?php else : ?>
		<?php foreach ( $tag_sections as $section ) : ?>
			<div class="pltt-tag-group">
				<div class="pltt-tag-group-header-row">
					<span class="pltt-tag-group-title"><?php echo esc_html( $section['label'] ); ?></span>
				</div>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tag', 'plain-language-time-tracker' ); ?></th>
							<th><?php esc_html_e( 'Entries', 'plain-language-time-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $section['tags'] as $tag ) : ?>
							<tr data-tag-id="<?php echo esc_attr( $tag->id ); ?>" data-tag-name="<?php echo esc_attr( $tag->name ); ?>" data-tag-group="<?php echo esc_attr( $tag->group_name ?? '' ); ?>" data-keywords="<?php echo esc_attr( wp_json_encode( $keywords_by_tag[ $tag->id ] ?? array() ) ); ?>">
								<td>
									<span class="pltt-badge pltt-badge-tag"><?php echo esc_html( ucfirst( $tag->name ) ); ?></span>
									<div class="row-actions">
										<span class="edit"><a href="#rename" class="pltt-rename-tag" role="button"><?php esc_html_e( 'Rename', 'plain-language-time-tracker' ); ?></a> | </span>
										<span class="trash"><a href="#delete" class="pltt-delete-tag submitdelete" role="button"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></a></span>
									</div>
								</td>
								<td><?php echo esc_html( (int) $tag->usage_count ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>

<!-- Hidden delete tag form -->
<form id="pltt-delete-tag-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
	<input type="hidden" name="action" value="pltt_delete_tag">
	<input type="hidden" name="tag_id" id="pltt-delete-tag-id" value="">
	<?php wp_nonce_field( 'pltt_manage_tag', '_wpnonce', true, true ); ?>
</form>

<!-- Add/Rename Tag Modal -->
<div id="pltt-tag-modal" class="pltt-modal pltt-hidden" role="dialog" aria-modal="true" aria-labelledby="pltt-tag-modal-title">
	<div class="pltt-modal-content">
		<h3 id="pltt-tag-modal-title"><?php esc_html_e( 'Add Tag', 'plain-language-time-tracker' ); ?></h3>
		<form id="pltt-tag-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" id="pltt-tag-form-action" value="pltt_create_tag">
			<input type="hidden" id="pltt-tag-id" name="tag_id" value="">
			<?php wp_nonce_field( 'pltt_manage_tag', '_wpnonce', true, true ); ?>
			<p>
				<label for="pltt-tag-name"><?php esc_html_e( 'Tag Name', 'plain-language-time-tracker' ); ?></label>
				<input type="text" id="pltt-tag-name" name="tag_name" class="regular-text widefat" required>
			</p>
			<p>
				<label for="pltt-tag-group-select"><?php esc_html_e( 'Group (optional)', 'plain-language-time-tracker' ); ?></label>
				<select id="pltt-tag-group-select" class="widefat">
					<option value=""><?php esc_html_e( '— No group —', 'plain-language-time-tracker' ); ?></option>
					<?php foreach ( $existing_groups as $g ) : ?>
						<option value="<?php echo esc_attr( $g ); ?>"><?php echo esc_html( $g ); ?></option>
					<?php endforeach; ?>
					<option value="__new__"><?php esc_html_e( '+ New group…', 'plain-language-time-tracker' ); ?></option>
				</select>
				<input type="text" id="pltt-tag-group-new" class="regular-text widefat pltt-hidden" placeholder="<?php esc_attr_e( 'New group name', 'plain-language-time-tracker' ); ?>" autocomplete="off">
				<!-- Actual value posted to the server: -->
				<input type="hidden" id="pltt-tag-group" name="group_name" value="">
			</p>
			<p>
				<label for="pltt-tag-keyword-input"><?php esc_html_e( 'Keywords (optional)', 'plain-language-time-tracker' ); ?></label>
				<div class="pltt-alias-group">
					<div class="pltt-alias-chips" data-alias-chips data-remove-label="<?php esc_attr_e( 'Remove keyword', 'plain-language-time-tracker' ); ?>">
						<div class="pltt-alias-chip-list"></div>
						<input type="text" id="pltt-tag-keyword-input" class="pltt-alias-input" placeholder="<?php esc_attr_e( 'Add a word or phrase from your notes…', 'plain-language-time-tracker' ); ?>" autocomplete="off">
						<div class="pltt-alias-hidden"></div>
					</div>
					<span class="pltt-alias-field-hint"><?php esc_html_e( 'Words or phrases in your notes that should pre-fill this tag. Type and press Enter to add one.', 'plain-language-time-tracker' ); ?></span>
				</div>
			</p>
			<p class="pltt-modal-actions">
				<button type="submit" id="pltt-save-tag-btn" class="button button-primary"><?php esc_html_e( 'Save', 'plain-language-time-tracker' ); ?></button>
				<button type="button" class="pltt-modal-close button"><?php esc_html_e( 'Cancel', 'plain-language-time-tracker' ); ?></button>
			</p>
		</form>
	</div>
</div>

<script>
(function() {
	'use strict';

	// Notice params are stripped from the URL by PLTT.cleanNoticeParams() in shared.js.

	var groupSelect = document.getElementById('pltt-tag-group-select');
	var groupNewInput = document.getElementById('pltt-tag-group-new');
	var groupHidden = document.getElementById('pltt-tag-group');

	// Created lazily: this inline script runs during body parse, before the
	// footer-enqueued alias-chips.js has defined window.PlttAliasChips.
	var tagChips = null;
	function getTagChips() {
		if (!tagChips && window.PlttAliasChips) {
			tagChips = PlttAliasChips.create(document.querySelector('#pltt-tag-form [data-alias-chips]'));
		}
		return tagChips;
	}

	/**
	 * Sync the hidden group input from the select / new-group input pair.
	 * - "" → no group
	 * - existing group → that name
	 * - "__new__" sentinel → use whatever's typed in the new-group input
	 */
	function syncGroupValue() {
		if (groupSelect.value === '__new__') {
			groupHidden.value = groupNewInput.value.trim();
		} else {
			groupHidden.value = groupSelect.value;
		}
	}

	/**
	 * Apply a group value to the modal: try to match against an existing option;
	 * otherwise show the new-group input pre-filled with the value.
	 */
	function applyGroupToModal(group) {
		group = (group || '').trim();
		groupNewInput.value = '';
		groupNewInput.classList.add('pltt-hidden');

		if (!group) {
			groupSelect.value = '';
			groupHidden.value = '';
			return;
		}

		// Look for an exact match among existing options.
		var match = Array.prototype.find.call(groupSelect.options, function(opt) {
			return opt.value === group;
		});
		if (match) {
			groupSelect.value = group;
			groupHidden.value = group;
		} else {
			// Group exists on this tag but isn't in the picker yet — treat it as a new one.
			groupSelect.value = '__new__';
			groupNewInput.classList.remove('pltt-hidden');
			groupNewInput.value = group;
			groupHidden.value = group;
		}
	}

	groupSelect.addEventListener('change', function() {
		if (groupSelect.value === '__new__') {
			groupNewInput.classList.remove('pltt-hidden');
			groupNewInput.value = '';
			groupNewInput.focus();
		} else {
			groupNewInput.classList.add('pltt-hidden');
			groupNewInput.value = '';
		}
		syncGroupValue();
	});

	groupNewInput.addEventListener('input', syncGroupValue);

	// Add Tag button.
	document.getElementById('pltt-add-tag-btn').addEventListener('click', function() {
		document.getElementById('pltt-tag-modal-title').textContent = '<?php echo esc_js( __( 'Add Tag', 'plain-language-time-tracker' ) ); ?>';
		document.getElementById('pltt-tag-id').value = '';
		document.getElementById('pltt-tag-name').value = '';
		applyGroupToModal('');
		var addTagChips = getTagChips();
		if (addTagChips) addTagChips.clear();
		document.getElementById('pltt-tag-form-action').value = 'pltt_create_tag';
		PLTT.showModal('pltt-tag-modal');
	});

	// Rename Tag links (row-actions).
	document.querySelectorAll('.pltt-rename-tag').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			var row = this.closest('tr');
			document.getElementById('pltt-tag-modal-title').textContent = '<?php echo esc_js( __( 'Rename Tag', 'plain-language-time-tracker' ) ); ?>';
			document.getElementById('pltt-tag-id').value = row.dataset.tagId;
			document.getElementById('pltt-tag-name').value = row.dataset.tagName;
			applyGroupToModal(row.dataset.tagGroup || '');
			var renameTagChips = getTagChips();
			if (renameTagChips) {
				var tagKeywords = [];
				try { tagKeywords = JSON.parse(row.dataset.keywords || '[]'); } catch (err) { tagKeywords = []; }
				renameTagChips.setExisting(tagKeywords);
			}
			document.getElementById('pltt-tag-form-action').value = 'pltt_rename_tag';
			PLTT.showModal('pltt-tag-modal');
		});
	});

	// Delete Tag links (row-actions).
	document.querySelectorAll('.pltt-delete-tag').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			if (!confirm('<?php echo esc_js( __( 'Delete this tag? This cannot be undone.', 'plain-language-time-tracker' ) ); ?>')) {
				return;
			}
			var tagId = this.closest('tr').dataset.tagId;
			document.getElementById('pltt-delete-tag-id').value = tagId;
			document.getElementById('pltt-delete-tag-form').submit();
		});
	});

	// Final safety sync before form submit (covers focus quirks).
	document.getElementById('pltt-tag-form').addEventListener('submit', syncGroupValue);
})();
</script>

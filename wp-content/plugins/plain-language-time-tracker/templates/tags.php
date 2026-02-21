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

$all_tags = PLTT_Tags::get_all_with_counts();
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></h1>
		<?php
		// Display success/error messages.
		if ( isset( $_GET['pltt_message'] ) ) {
			$message_code = sanitize_text_field( wp_unslash( $_GET['pltt_message'] ) );
			$messages     = array(
				'tag_created' => __( 'Tag created successfully.', 'plain-language-time-tracker' ),
				'tag_renamed' => __( 'Tag renamed successfully.', 'plain-language-time-tracker' ),
				'tag_deleted' => __( 'Tag deleted successfully.', 'plain-language-time-tracker' ),
			);
			if ( isset( $messages[ $message_code ] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $message_code ] ) . '</p></div>';
			}
		}

		if ( isset( $_GET['pltt_error'] ) ) {
			$error_code = sanitize_text_field( wp_unslash( $_GET['pltt_error'] ) );
			$errors     = array(
				'tag_exists'        => __( 'A tag with that name already exists.', 'plain-language-time-tracker' ),
				'tag_rename_failed' => __( 'Failed to rename tag.', 'plain-language-time-tracker' ),
				'tag_create_failed' => __( 'Failed to create tag.', 'plain-language-time-tracker' ),
				'tag_delete_failed' => __( 'Failed to delete tag.', 'plain-language-time-tracker' ),
				'invalid_tag'       => __( 'Invalid tag name.', 'plain-language-time-tracker' ),
			);
			if ( isset( $errors[ $error_code ] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $error_code ] ) . '</p></div>';
			}
		}
		?>
		<div class="pltt-header-actions">
			<button type="button" id="pltt-add-tag-btn" class="button button-primary">
				<?php esc_html_e( 'Add Tag', 'plain-language-time-tracker' ); ?>
			</button>
		</div>
	</div>

	<?php if ( empty( $all_tags ) ) : ?>
		<p class="description"><?php esc_html_e( 'No tags yet. Tags will appear here once you add them to time entries or create them manually.', 'plain-language-time-tracker' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tag', 'plain-language-time-tracker' ); ?></th>
					<th><?php esc_html_e( 'Entries', 'plain-language-time-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $all_tags as $tag ) : ?>
					<tr data-tag-id="<?php echo esc_attr( $tag->id ); ?>" data-tag-name="<?php echo esc_attr( $tag->name ); ?>">
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

	// Clean up URL parameters after displaying notices.
	if (window.location.search.includes('pltt_message') || window.location.search.includes('pltt_error')) {
		var url = new URL(window.location.href);
		url.searchParams.delete('pltt_message');
		url.searchParams.delete('pltt_error');
		window.history.replaceState({}, '', url.toString());
	}

	// Add Tag button.
	document.getElementById('pltt-add-tag-btn').addEventListener('click', function() {
		document.getElementById('pltt-tag-modal-title').textContent = '<?php echo esc_js( __( 'Add Tag', 'plain-language-time-tracker' ) ); ?>';
		document.getElementById('pltt-tag-id').value = '';
		document.getElementById('pltt-tag-name').value = '';
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
})();
</script>

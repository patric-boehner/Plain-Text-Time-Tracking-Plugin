<?php
/**
 * Review & Verify template.
 *
 * Branches between two states:
 *   - Post-parse: any entry on the date is unverified → existing column-input
 *     table with batch "Save All Entries" commit.
 *   - Editing existing: all entries verified (or none exist) → compact rows
 *     with hover Edit/Delete, expandable unified form, plus a standalone
 *     Add entry form at the top.
 *
 * @package PlainLanguageTimeTracker
 *
 * @var string $date               Current date.
 * @var array  $entries            Entries on this date (may be empty).
 * @var array  $summary            Summary stats (billable_minutes, billable_amount).
 * @var array  $clients            All clients.
 * @var array  $projects_by_client Projects grouped by client.
 * @var array  $all_tags           All known tags.
 * @var object $log                Daily log object (may be null).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$total_minutes = 0;
foreach ( $entries as $entry ) {
	$total_minutes += $entry['duration_minutes'] ?? 0;
}

// SEC-M11: validate the return_to URL at render time too; an off-host URL
// passes esc_url() but would still phish on the legit admin chrome.
$return_to_raw = isset( $_GET['return_to'] ) ? esc_url_raw( wp_unslash( $_GET['return_to'] ) ) : '';
$return_to     = $return_to_raw ? wp_validate_redirect( $return_to_raw, '' ) : '';

// Detect post-parse vs editing-existing state. Any unverified entry → post-parse.
$is_post_parse = false;
foreach ( $entries as $entry ) {
	if ( empty( $entry['verified'] ) ) {
		$is_post_parse = true;
		break;
	}
}
?>

<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Review Time Entries', 'plain-language-time-tracker' ); ?></h1>
	</div>

	<div class="pltt-date-display">
		<h2><?php echo esc_html( pltt_format_date( $date ) ); ?></h2>
		<input type="hidden" id="pltt-entry-date" value="<?php echo esc_attr( $date ); ?>">
	</div>

	<?php if ( ! empty( $entries ) ) : ?>
		<div class="pltt-summary-cards">
			<div class="card">
				<div class="card-label"><?php esc_html_e( 'Entries', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value" data-card="entry-count"><?php echo esc_html( count( $entries ) ); ?></div>
			</div>
			<div class="card">
				<div class="card-label"><?php esc_html_e( 'Total Hours', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value" data-card="hours"><?php echo esc_html( pltt_format_hours( $total_minutes ) ); ?></div>
			</div>
			<div class="card">
				<div class="card-label"><?php esc_html_e( 'Billable Hours', 'plain-language-time-tracker' ); ?></div>
				<div class="card-value"><?php echo esc_html( pltt_format_hours( $summary['billable_minutes'] ) ); ?></div>
			</div>
			<?php if ( (float) $summary['billable_amount'] > 0 ) : ?>
				<div class="card">
					<div class="card-label"><?php esc_html_e( 'Billable Amount', 'plain-language-time-tracker' ); ?></div>
					<div class="card-value"><?php echo esc_html( pltt_format_currency( $summary['billable_amount'] ) ); ?></div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $log ) && ! empty( $log->content ) ) : ?>
			<details class="pltt-notes-reference">
				<summary><?php esc_html_e( 'Show Original Notes', 'plain-language-time-tracker' ); ?></summary>
				<pre class="pltt-notes-pre"><?php echo esc_html( $log->content ); ?></pre>
			</details>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $is_post_parse ) : ?>
		<?php include PLTT_PLUGIN_DIR . 'templates/partials/review-post-parse.php'; ?>
	<?php else : ?>
		<?php include PLTT_PLUGIN_DIR . 'templates/partials/review-edit-existing.php'; ?>
	<?php endif; ?>
</div>

<?php // SEC-L1: JSON_HEX_TAG/AMP so a "</script>" inside a tag/group name can't break out of the inline <script>. ?>
<?php wp_add_inline_script( 'pltt-review', 'var plttAllTags = ' . wp_json_encode( $all_tags, JSON_HEX_TAG | JSON_HEX_AMP ) . ';var plttTagGroups = ' . wp_json_encode( PLTT_Tags::get_name_to_group_map(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' ); ?>

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

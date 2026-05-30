<?php
/**
 * Editing-existing review state: compact rows + expandable per-row edit form.
 *
 * Rendered by review.php when all entries on the current date are verified.
 *
 * @package PlainLanguageTimeTracker
 *
 * @var string $date               Current date.
 * @var array  $entries            Entries on this date.
 * @var array  $clients            All clients.
 * @var array  $projects_by_client Projects grouped by client.
 * @var string $return_to          Optional safe-redirect URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Compact-row table columns: Date/Time, Duration, Description, Tags, Billable.
$colspan = 5;

// Bulk-load clients and projects referenced by the entries on this date (avoid N+1 in the row loop).
$entry_client_ids  = array();
$entry_project_ids = array();
foreach ( $entries as $e ) {
	if ( ! empty( $e['client_id'] ) ) {
		$entry_client_ids[] = (int) $e['client_id'];
	}
	if ( ! empty( $e['project_id'] ) ) {
		$entry_project_ids[] = (int) $e['project_id'];
	}
}
$row_clients_cache  = PLTT_Clients::get_multiple( array_unique( $entry_client_ids ) );
$row_projects_cache = PLTT_Projects::get_multiple( array_unique( $entry_project_ids ) );
?>

<table class="pltt-entries-table widefat">
	<thead>
		<tr>
			<th class="pltt-col-time"><?php esc_html_e( 'Date / Time', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-col-duration"><?php esc_html_e( 'Duration', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-col-description"><?php esc_html_e( 'Description', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-col-tags"><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-col-billable"><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></th>
		</tr>
	</thead>
	<tbody id="pltt-entries-tbody">
		<?php foreach ( $entries as $entry ) : ?>
			<?php
			$entry_id    = (int) $entry['id'];
			$is_billable = ! empty( $entry['billable'] );
			?>
			<tr class="pltt-entry-row pltt-entry-compact" data-entry-id="<?php echo esc_attr( $entry_id ); ?>">
				<td class="pltt-time-cell">
					<div class="pltt-time-display">
						<span class="pltt-date-text"><?php echo esc_html( pltt_format_date( $entry['entry_date'] ?? $date, 'M j, Y' ) ); ?></span>
						<span class="pltt-time-separator">&middot;</span>
						<span class="pltt-time-text">
							<?php
							echo esc_html( pltt_format_time( $entry['start_time'] ) );
							if ( ! empty( $entry['end_time'] ) ) {
								echo ' &ndash; ' . esc_html( pltt_format_time( $entry['end_time'] ) );
							}
							?>
						</span>
						<div class="row-actions">
							<span class="edit"><a href="#edit" class="pltt-edit-entry" role="button"><?php esc_html_e( 'Edit', 'plain-language-time-tracker' ); ?></a> | </span>
							<span class="trash"><a href="#delete" class="pltt-delete-entry submitdelete" role="button"><?php esc_html_e( 'Delete', 'plain-language-time-tracker' ); ?></a></span>
						</div>
					</div>
				</td>
				<td class="pltt-duration-cell">
					<?php echo ! empty( $entry['duration_minutes'] ) ? esc_html( pltt_format_duration( $entry['duration_minutes'] ) ) : '--'; ?>
				</td>
				<td class="pltt-entry-desc-cell">
					<span class="pltt-entry-desc-text"><?php echo esc_html( $entry['description'] ?? '' ); ?></span>
					<?php
					$client  = ! empty( $entry['client_id'] ) && isset( $row_clients_cache[ $entry['client_id'] ] ) ? $row_clients_cache[ $entry['client_id'] ] : null;
					$project = ! empty( $entry['project_id'] ) && isset( $row_projects_cache[ $entry['project_id'] ] ) ? $row_projects_cache[ $entry['project_id'] ] : null;
					$meta    = array();
					if ( $client ) {
						$meta[] = '<span class="pltt-entry-client">' . esc_html( $client->name ) . '</span>';
					}
					if ( $project ) {
						$meta[] = '<span class="pltt-entry-project">' . esc_html( $project->name ) . '</span>';
					}
					if ( ! empty( $meta ) ) :
					?>
						<div class="pltt-entry-meta">
							<?php echo implode( '<span class="pltt-entry-meta-sep"> · </span>', $meta ); // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped above ?>
						</div>
					<?php endif; ?>
				</td>
				<td class="pltt-tag-cell">
					<div class="pltt-tag-pills">
						<?php pltt_render_tag_badges( ! empty( $entry['tags'] ) ? explode( ',', $entry['tags'] ) : array() ); ?>
					</div>
				</td>
				<td class="pltt-billable-indicator">
					<span class="pltt-billable-symbol <?php echo $is_billable ? 'is-billable' : 'not-billable'; ?>"
						aria-label="<?php echo $is_billable ? esc_attr__( 'Billable', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable', 'plain-language-time-tracker' ); ?>"
						title="<?php echo $is_billable ? esc_attr__( 'Billable', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable', 'plain-language-time-tracker' ); ?>">$</span>
				</td>
			</tr>
			<?php
			// Hidden form row directly beneath. Pre-populated with the entry's values.
			$form_entry  = $entry;
			$row_visible = false;
			include __DIR__ . '/entry-form-row.php';
			?>
		<?php endforeach; ?>
	</tbody>
</table>

<div class="pltt-entries-footer">
	<?php if ( $return_to ) : ?>
		<a href="<?php echo esc_url( $return_to ); ?>" class="button">
			&larr; <?php esc_html_e( 'Back to Reports', 'plain-language-time-tracker' ); ?>
		</a>
	<?php else : ?>
		<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log', array( 'date' => $date ) ) ); ?>" class="button">
			&larr; <?php esc_html_e( 'Back to Notes', 'plain-language-time-tracker' ); ?>
		</a>
	<?php endif; ?>
</div>

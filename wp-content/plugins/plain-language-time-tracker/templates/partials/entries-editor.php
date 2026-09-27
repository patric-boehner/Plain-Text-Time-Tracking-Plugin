<?php
/**
 * Recorded entries list: one compact row each, with a kebab holding the row's
 * actions (spec §6).
 *
 * Editing happens in the shared modal (entry-edit-modal.php + pltt-entry-editor.js),
 * opened from the kebab. This used to render a hidden edit form beneath every
 * row and reveal it in place, reached through hover row-actions; both are gone.
 *
 * Shared by the review screen (editing-existing state) and the Daily Log
 * (Today). Renders the `#pltt-entries-tbody` table that review.js binds to; the
 * caller is responsible for the edit modal and the three create-modals
 * (entry-edit-modal.php, entry-editor-modals.php), the `plttAllTags` /
 * `plttTagGroups` globals, the `#pltt-entry-date` hidden input, and any
 * surrounding footer/back links.
 *
 * @package PlainLanguageTimeTracker
 *
 * @var string $date               Current date.
 * @var array  $entries            Entries on this date (all verified).
 * @var array  $clients            All clients.
 * @var array  $projects_by_client Projects grouped by client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

// Compact-row columns: Date/Time, Duration, Description, Tags (+ Billable when at
// least one entry's project uses the per-entry flag — hourly), then the kebab.
// All-retainer/fixed-fee lists drop the Billable column.
$show_billable_col = false;
foreach ( $entries as $e ) {
	$p = ! empty( $e['project_id'] ) && isset( $row_projects_cache[ (int) $e['project_id'] ] ) ? $row_projects_cache[ (int) $e['project_id'] ] : null;
	if ( pltt_billable_flag_applies( $p ) ) {
		$show_billable_col = true;
		break;
	}
}
?>

<table class="pltt-entries-table widefat pltt-stack pltt-stack-entries">
	<thead>
		<tr>
			<th class="pltt-col-time"><?php esc_html_e( 'Date / Time', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-col-duration"><?php esc_html_e( 'Duration', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-col-description"><?php esc_html_e( 'Description', 'plain-language-time-tracker' ); ?></th>
			<th class="pltt-col-tags"><?php esc_html_e( 'Tags', 'plain-language-time-tracker' ); ?></th>
			<?php if ( $show_billable_col ) : ?>
				<th class="pltt-col-billable"><?php esc_html_e( 'Billable', 'plain-language-time-tracker' ); ?></th>
			<?php endif; ?>
			<th class="pltt-rowmenu-col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'plain-language-time-tracker' ); ?></span></th>
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
				<?php if ( $show_billable_col ) : ?>
					<td class="pltt-billable-indicator">
						<?php if ( pltt_billable_flag_applies( $project ) ) : ?>
							<span class="pltt-billable-symbol <?php echo $is_billable ? 'is-billable' : 'not-billable'; ?>"
								aria-label="<?php echo $is_billable ? esc_attr__( 'Billable', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable', 'plain-language-time-tracker' ); ?>"
								title="<?php echo $is_billable ? esc_attr__( 'Billable', 'plain-language-time-tracker' ) : esc_attr__( 'Not billable', 'plain-language-time-tracker' ); ?>">$</span>
						<?php endif; ?>
					</td>
				<?php endif; ?>
				<td class="pltt-rowmenu-col">
					<?php
					// No "Go to [date] log" here — this screen IS the day's log. A
					// menu item pointing at the page you're on is noise.
					pltt_render_row_menu_trigger(
						'entry',
						/* translators: %s: entry description. */
						sprintf( __( 'Actions for %s', 'plain-language-time-tracker' ), $entry['description'] ?? '' ),
						array(
							'entry-id'         => $entry_id,
							'date'             => $entry['entry_date'] ?? $date,
							'billable'         => $is_billable ? '1' : '0',
							'billable-applies' => pltt_billable_flag_applies( $project ) ? '1' : '0',
						)
					);
					?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

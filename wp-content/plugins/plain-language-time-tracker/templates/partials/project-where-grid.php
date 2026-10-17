<?php
/**
 * "Where the time went" — minutes per tag across the most recent periods,
 * on the project page.
 *
 * A table, not a chart. Rows are the tags this project's entries carry; one
 * column per recent period (six at most), then an all-time total with its
 * share of the project as a percentage. Each tag name links to Overview's Entries view filtered to this
 * project and that tag, so the records behind a row are one click away. It answers "what did I do this month?" and "where does this project's
 * time go?" in one glance, without stepping the period lens. The lens still
 * moves it: in period scope the columns end at the chosen period, and that
 * column is tinted.
 *
 * Closed by default. It is a disclosure (<details>) so the page keeps its
 * shape — one line stating what is here — until you want the breakdown. No
 * JS: the browser does the toggling.
 *
 * The stacked-table pattern is not applied here on purpose: on a phone the
 * table scrolls sideways with the tag column pinned (project-detail.css).
 *
 * Expects $report (from PLTT_Project_Report::build()) in scope. Renders
 * nothing when the project has no time to show.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$where = $report['where'] ?? null;
if ( empty( $where ) || empty( $where['periods'] ) ) {
	return;
}

$where_periods = $where['periods'];
$where_ncols   = count( $where_periods ) + 2;

/**
 * One body row. A closure so the tagged rows and the Untagged row share it.
 *
 * @param array  $row         { name, cells[], all_minutes, share }.
 * @param string $extra_class Extra class on the <tr>.
 */
$pltt_where_row = static function ( $row, $extra_class = '' ) use ( $where_periods ) {
	$pct      = (int) round( $row['share'] * 100 );
	$all_text = pltt_format_duration( (int) $row['all_minutes'] );
	?>
	<tr<?php echo $extra_class ? ' class="' . esc_attr( $extra_class ) . '"' : ''; ?>>
		<th scope="row" class="pltt-where-tag">
			<?php if ( ! empty( $row['url'] ) ) : ?>
				<a class="pltt-row-link" href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $row['name'] ); ?>
			<?php endif; ?>
		</th>
		<?php foreach ( $where_periods as $i => $p ) : ?>
			<?php $minutes = isset( $row['cells'][ $i ] ) ? (int) $row['cells'][ $i ] : 0; ?>
			<td class="pltt-where-cell pltt-measured<?php echo ! empty( $p['is_current'] ) ? ' is-current' : ''; ?>">
				<?php if ( $minutes > 0 ) : ?>
					<?php echo esc_html( pltt_format_duration( $minutes ) ); ?>
				<?php else : ?>
					<span class="pltt-empty">&mdash;</span>
				<?php endif; ?>
			</td>
		<?php endforeach; ?>
		<td class="pltt-where-all">
			<?php
			// The figure in a fixed-width slot, then its share of all time. Two
			// aligned columns of numbers; a bar was tried here and said less.
			?>
			<span class="pltt-measured pltt-where-all-figure"><?php echo esc_html( $all_text ); ?></span><span class="pltt-measured pltt-where-share"><?php echo esc_html( $pct . '%' ); ?></span>
		</td>
	</tr>
	<?php
};
?>
<details class="pltt-where-section">
	<summary class="pltt-panel-header pltt-where-summary">
		<h2 id="pltt-where-title" class="pltt-panel-title">
			<?php esc_html_e( 'Where the time went', 'plain-language-time-tracker' ); ?>
			<span class="pltt-chart-range">&middot; <?php echo esc_html( $where['period_range'] ); ?></span>
		</h2>
	</summary>
	<div class="pltt-panel-body pltt-where-body">
		<div class="pltt-where-scroll">
			<table class="pltt-where-table">
				<thead>
					<tr>
						<th scope="col" class="pltt-where-tag"><?php esc_html_e( 'Tag', 'plain-language-time-tracker' ); ?></th>
						<?php foreach ( $where_periods as $p ) : ?>
							<th scope="col" class="pltt-where-period<?php echo ! empty( $p['is_current'] ) ? ' is-current' : ''; ?>">
								<abbr title="<?php echo esc_attr( $p['full_label'] ); ?>"><?php echo esc_html( $p['label'] ); ?></abbr>
							</th>
						<?php endforeach; ?>
						<th scope="col" class="pltt-where-all">
							<?php esc_html_e( 'All time', 'plain-language-time-tracker' ); ?>
							<?php
							// The multi-tag caveat sits on the column whose percentages make
							// it visible: they can add up past 100%. Only when it applies.
							if ( ! empty( $where['has_multi_tag'] ) ) {
								pltt_render_info_note(
									__( 'Entries carrying more than one tag count in full under each tag, so these figures can add up to more than the project total and the shares to more than 100%.', 'plain-language-time-tracker' ),
									__( 'About the all-time figures', 'plain-language-time-tracker' )
								);
							}
							?>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $where['groups'] as $group ) : ?>
						<?php if ( ! empty( $where['show_group_headers'] ) ) : ?>
							<tr class="pltt-where-group">
								<th scope="rowgroup" colspan="<?php echo (int) $where_ncols; ?>"><?php echo esc_html( $group['label'] ); ?></th>
							</tr>
						<?php endif; ?>
						<?php foreach ( $group['rows'] as $row ) : ?>
							<?php $pltt_where_row( $row ); ?>
						<?php endforeach; ?>
					<?php endforeach; ?>
					<?php if ( ! empty( $where['untagged'] ) ) : ?>
						<?php $pltt_where_row( $where['untagged'], 'pltt-where-untagged' ); ?>
					<?php endif; ?>
				</tbody>
				<tfoot>
					<tr>
						<th scope="row" class="pltt-where-tag"><?php esc_html_e( 'Total', 'plain-language-time-tracker' ); ?></th>
						<?php foreach ( $where_periods as $i => $p ) : ?>
							<?php $minutes = isset( $where['period_totals'][ $i ] ) ? (int) $where['period_totals'][ $i ] : 0; ?>
							<td class="pltt-where-cell pltt-measured<?php echo ! empty( $p['is_current'] ) ? ' is-current' : ''; ?>">
								<?php if ( $minutes > 0 ) : ?>
									<?php echo esc_html( pltt_format_duration( $minutes ) ); ?>
								<?php else : ?>
									<span class="pltt-empty">&mdash;</span>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
						<td class="pltt-where-all"><span class="pltt-measured pltt-where-all-figure"><?php echo esc_html( pltt_format_duration( (int) $where['total_minutes'] ) ); ?></span></td>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>
</details>

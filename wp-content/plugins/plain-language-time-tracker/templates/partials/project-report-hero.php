<?php
/**
 * Project report hero — the type-aware headline above the stat cards.
 *
 * Two layouts, chosen by $hero['mode']:
 *   figure — a big lifetime number (hourly "Earned to date").
 *   gauge  — an allocation/budget track (fixed "Budget consumed", retainer
 *            "This period"). $hero['gauge']['state'] tints the fill ok/warn/over.
 * The right column is a small list of $hero['minirows'] ([label, value, accent?]).
 *
 * Expects $hero (from PLTT_Project_Report::build()['hero']) in scope. Renders
 * nothing when $hero is null (e.g. internal projects).
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $hero ) ) {
	return;
}

$is_gauge = ( 'gauge' === $hero['mode'] );
?>
<div class="pltt-report-hero pltt-hero-<?php echo esc_attr( $hero['type'] ); ?>">
	<div class="pltt-hero-left">
		<div class="pltt-hero-tag">
			<?php echo esc_html( $hero['tag'] ); ?>
			<?php if ( ! empty( $hero['period'] ) ) : ?>
				<span class="pltt-hero-period"><?php echo esc_html( $hero['period'] ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $is_gauge ) :
			$g      = $hero['gauge'];
			// Two segments (within-limit + overage) so the overage magnitude shows,
			// matching the Reports allocation bar.
			$within = max( 0.0, min( 100.0, (float) $g['within_pct'] ) );
			$over   = max( 0.0, min( 100.0, (float) $g['over_pct'] ) );
			?>
			<div class="pltt-hero-track pltt-gauge-<?php echo esc_attr( $g['state'] ); ?>">
				<i class="pltt-hero-seg-within" style="width:<?php echo esc_attr( number_format( $within, 2, '.', '' ) ); ?>%"></i>
				<?php if ( $over > 0 ) : ?>
					<i class="pltt-hero-seg-over" style="width:<?php echo esc_attr( number_format( $over, 2, '.', '' ) ); ?>%"></i>
				<?php endif; ?>
			</div>
			<div class="pltt-hero-trackcap">
				<span>
					<?php
					printf(
						/* translators: 1: amount used (e.g. "$5,240" or "18.5h"); 2: total (e.g. "$8,000" or "20h"). */
						esc_html__( '%1$s of %2$s', 'plain-language-time-tracker' ),
						'<b>' . esc_html( $g['used'] ) . '</b>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						esc_html( $g['total'] )
					);
					?>
				</span>
				<span class="pltt-hero-cap pltt-gauge-cap-<?php echo esc_attr( $g['state'] ); ?>"><?php echo esc_html( $g['cap'] ); ?></span>
			</div>
			<?php if ( '' !== $g['note'] ) : ?>
				<div class="pltt-hero-note"><?php echo esc_html( $g['note'] ); ?></div>
			<?php endif; ?>
		<?php else : ?>
			<div class="pltt-hero-figure">
				<?php echo esc_html( $hero['figure'] ); ?>
				<?php if ( '' !== $hero['figure_suffix'] ) : ?>
					<small><?php echo esc_html( $hero['figure_suffix'] ); ?></small>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $hero['note'] ) : ?>
				<div class="pltt-hero-note"><?php echo esc_html( $hero['note'] ); ?></div>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="pltt-hero-right">
		<?php foreach ( $hero['minirows'] as $row ) : ?>
			<div class="pltt-hero-row">
				<span class="pltt-hero-row-lbl"><?php echo esc_html( $row['label'] ); ?></span>
				<b class="<?php echo ! empty( $row['accent'] ) ? 'pltt-hero-row-accent' : ''; ?>"><?php echo esc_html( $row['value'] ); ?></b>
			</div>
		<?php endforeach; ?>
	</div>
</div>

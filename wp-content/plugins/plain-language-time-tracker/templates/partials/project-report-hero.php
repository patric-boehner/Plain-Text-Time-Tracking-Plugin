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
// Mini-rows are optional: with the scope block carrying the figures, a hero is
// often just its meter, and then it goes full width.
$has_rows = ! empty( $hero['minirows'] );
?>
<div class="pltt-report-hero pltt-hero-<?php echo esc_attr( $hero['type'] ); ?><?php echo $has_rows ? '' : ' pltt-hero-solo'; ?>">
	<?php // Same header as the volume chart's panel — see .pltt-panel-header. ?>
	<header class="pltt-panel-header pltt-hero-header">
		<h2 class="pltt-panel-title"><?php echo esc_html( $hero['tag'] ); ?></h2>
	</header>

	<div class="pltt-hero-left">

		<?php if ( $is_gauge ) :
			$g      = $hero['gauge'];
			$within = max( 0.0, min( 100.0, (float) $g['within_pct'] ) );
			$over   = max( 0.0, min( 100.0, (float) $g['over_pct'] ) );

			// The limit is a POSITION on the track, not the end of it. Under the
			// limit the track IS the limit, so the marker sits at the right edge;
			// over it, the track becomes the total and the marker slides back to
			// where the limit actually falls — at 500% it sits a fifth along, so
			// the proportion stays honest however far over you are. The bar is
			// never capped.
			$marker_pct = ( $over > 0 ) ? $within : 100.0;
			?>
			<div class="pltt-hero-track pltt-gauge-<?php echo esc_attr( $g['state'] ); ?>">
				<i class="pltt-hero-seg-within<?php echo $over > 0 ? '' : ' is-whole'; ?>" style="width:<?php echo esc_attr( number_format( $within, 2, '.', '' ) ); ?>%"></i>
				<?php if ( $over > 0 ) : ?>
					<i class="pltt-hero-seg-over" style="left:<?php echo esc_attr( number_format( $within, 2, '.', '' ) ); ?>%;width:<?php echo esc_attr( number_format( $over, 2, '.', '' ) ); ?>%"></i>
				<?php endif; ?>
				<span class="pltt-hero-limit" style="left:<?php echo esc_attr( number_format( $marker_pct, 2, '.', '' ) ); ?>%">
					<b><?php echo esc_html( $g['marker'] ); ?></b>
				</span>
			</div>
			<div class="pltt-hero-trackcap">
				<span><?php echo esc_html( $g['basis'] ); ?></span>
				<span class="pltt-hero-cap pltt-gauge-cap-<?php echo esc_attr( $g['state'] ); ?>"><?php echo esc_html( $g['delta'] ); ?></span>
			</div>
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

	<?php // Right column only when there are mini-rows; the meter stands alone otherwise. ?>
	<?php if ( $has_rows ) : ?>
		<div class="pltt-hero-right">
			<?php foreach ( $hero['minirows'] as $row ) : ?>
				<div class="pltt-hero-row">
					<span class="pltt-hero-row-lbl"><?php echo esc_html( $row['label'] ); ?></span>
					<b class="<?php echo ! empty( $row['accent'] ) ? 'pltt-hero-row-accent' : ''; ?>"><?php echo esc_html( $row['value'] ); ?></b>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

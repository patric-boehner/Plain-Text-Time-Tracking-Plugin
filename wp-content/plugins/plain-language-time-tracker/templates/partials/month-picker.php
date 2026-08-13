<?php
/**
 * Month picker — prev / labelled dropdown / next, with months grouped by year.
 *
 * The date control for screens that move one period at a time: History's month
 * browser and a retainer project's period filter. (Reports and Billing use
 * partials/date-nav.php instead — that one picks an arbitrary from/to RANGE from
 * presets, which is a different question than "which month".)
 *
 * Behaviour — open/close, keyboard, year switching — is in
 * assets/js/pltt-month-picker.js and is shared. Selection is per-screen, because
 * the two callers address a period differently:
 *   • an option with 'url'  navigates (a plain link; needs no JS at all)
 *   • an option with 'from' writes the hidden #pltt-date-from / #pltt-date-to
 *     pair and submits the enclosing form
 * A caller supplies one or the other, never both.
 *
 * Expects in scope:
 *   $mp_label       string  Text on the trigger, e.g. "March 2026" / "All time".
 *   $mp_years       array   year => list of options, NEWEST YEAR FIRST. Each option:
 *                           [ 'label' => string, 'active' => bool,
 *                             'url' => string  |  'from' => 'Y-m-d', 'to' => 'Y-m-d' ].
 *   $mp_active_year string  Which year group starts visible.
 * Optional:
 *   $mp_lead        array|null  One option pinned above the year groups (e.g. "All time"),
 *                               same shape as a year option.
 *   $mp_prev        string      href for the previous period; '' renders it disabled.
 *   $mp_next        string      href for the next period; '' renders it disabled.
 *   $mp_reset       array|null  [ 'url' => string, 'label' => string ] after the nav.
 *   $mp_aria        string      aria-label for the nav element.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mp_lead        = isset( $mp_lead ) ? $mp_lead : null;
$mp_prev        = isset( $mp_prev ) ? (string) $mp_prev : '';
$mp_next        = isset( $mp_next ) ? (string) $mp_next : '';
$mp_reset       = isset( $mp_reset ) ? $mp_reset : null;
$mp_aria        = isset( $mp_aria ) ? $mp_aria : __( 'Period navigation', 'plain-language-time-tracker' );
$mp_multi_year  = ( count( $mp_years ) > 1 );

/**
 * One option, as a link or as a form-submitting button depending on what the
 * caller supplied. Kept local so the lead option and the month options can't
 * drift apart.
 *
 * @param array $opt Option data.
 * @return void
 */
$mp_render_option = function ( $opt ) {
	$classes = 'pltt-date-nav-option';
	$current = ! empty( $opt['active'] ) ? ' aria-current="true"' : '';

	if ( isset( $opt['url'] ) ) {
		printf(
			'<a class="%s" href="%s"%s>%s</a>',
			esc_attr( $classes ),
			esc_url( $opt['url'] ),
			$current, // Static markup, not user input.
			esc_html( $opt['label'] )
		);
		return;
	}

	printf(
		'<button type="button" class="%s" data-from="%s" data-to="%s"%s>%s</button>',
		esc_attr( $classes ),
		esc_attr( $opt['from'] ),
		esc_attr( $opt['to'] ),
		$current, // Static markup, not user input.
		esc_html( $opt['label'] )
	);
};
?>
<div class="pltt-date-nav-row">
	<nav class="pltt-date-nav" aria-label="<?php echo esc_attr( $mp_aria ); ?>">

		<?php if ( '' !== $mp_prev ) : ?>
			<a href="<?php echo esc_url( $mp_prev ); ?>"
				class="pltt-date-nav-step pltt-date-nav-prev"
				aria-label="<?php esc_attr_e( 'Previous period', 'plain-language-time-tracker' ); ?>"></a>
		<?php else : ?>
			<span class="pltt-date-nav-step pltt-date-nav-prev is-disabled" aria-disabled="true"></span>
		<?php endif; ?>

		<div class="pltt-date-nav-picker">
			<button type="button" class="pltt-date-nav-label" aria-expanded="false" id="pltt-date-nav-trigger">
				<span class="pltt-date-nav-label-main"><?php echo esc_html( $mp_label ); ?></span>
			</button>

			<div class="pltt-date-nav-dropdown" hidden>

				<?php if ( $mp_lead ) : ?>
					<?php // Pinned above the years — it is not one of them. ?>
					<ul class="pltt-date-nav-options pltt-date-nav-lead">
						<li><?php $mp_render_option( $mp_lead ); ?></li>
					</ul>
					<hr class="pltt-date-nav-separator">
				<?php endif; ?>

				<?php if ( $mp_multi_year ) : ?>
					<div class="pltt-date-nav-year-switcher" data-year="<?php echo esc_attr( $mp_active_year ); ?>">
						<button type="button" class="pltt-date-nav-year-prev"
							aria-label="<?php esc_attr_e( 'Previous year', 'plain-language-time-tracker' ); ?>">&#8249;</button>
						<span class="pltt-date-nav-year-label"><?php echo esc_html( $mp_active_year ); ?></span>
						<button type="button" class="pltt-date-nav-year-next"
							aria-label="<?php esc_attr_e( 'Next year', 'plain-language-time-tracker' ); ?>">&#8250;</button>
					</div>
					<hr class="pltt-date-nav-separator">
				<?php endif; ?>

				<?php foreach ( $mp_years as $mp_year => $mp_year_options ) : ?>
					<div class="pltt-date-nav-year-months"
						data-year="<?php echo esc_attr( $mp_year ); ?>"
						<?php if ( (string) $mp_year !== (string) $mp_active_year ) : ?>hidden<?php endif; ?>>
						<ul class="pltt-date-nav-options">
							<?php foreach ( $mp_year_options as $mp_opt ) : ?>
								<li><?php $mp_render_option( $mp_opt ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>

			</div>
		</div>

		<?php if ( '' !== $mp_next ) : ?>
			<a href="<?php echo esc_url( $mp_next ); ?>"
				class="pltt-date-nav-step pltt-date-nav-next"
				aria-label="<?php esc_attr_e( 'Next period', 'plain-language-time-tracker' ); ?>"></a>
		<?php else : ?>
			<span class="pltt-date-nav-step pltt-date-nav-next is-disabled" aria-disabled="true"></span>
		<?php endif; ?>

	</nav>

	<?php // Reset goes AFTER the nav and is named for its destination. ?>
	<?php if ( $mp_reset ) : ?>
		<a href="<?php echo esc_url( $mp_reset['url'] ); ?>" class="button button-secondary">
			<?php echo esc_html( $mp_reset['label'] ); ?>
		</a>
	<?php endif; ?>
</div>

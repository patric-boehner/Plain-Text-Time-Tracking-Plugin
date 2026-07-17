<?php
/**
 * Date navigator widget — the preset dropdown + prev/next stepper + custom range.
 *
 * Behaviour is driven by assets/js/pltt-date-nav.js (reads the hidden from/to
 * inputs and submits the enclosing <form>). Must be rendered INSIDE a GET form
 * that carries the page's other params. Styling is in assets/css/admin.css.
 *
 * Expects in scope:
 *   $dn_presets    — array of [ 'label' => string, 'from' => 'Y-m-d', 'to' => 'Y-m-d' ].
 *   $dn_from       — active range start (Y-m-d).
 *   $dn_to         — active range end (Y-m-d).
 *   $dn_week_start — start_of_week option (int), for the JS stepper.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Which preset (if any) matches the active range — drives the label + highlight.
$dn_active_label = '';
foreach ( $dn_presets as $dn_p ) {
	if ( $dn_from === $dn_p['from'] && $dn_to === $dn_p['to'] ) {
		$dn_active_label = $dn_p['label'];
		break;
	}
}
$dn_range_label = pltt_format_date_range( $dn_from, $dn_to );
?>
<div class="pltt-date-nav-row">
	<div class="pltt-date-nav"
		role="group"
		aria-label="<?php esc_attr_e( 'Date range', 'plain-language-time-tracker' ); ?>"
		data-week-start="<?php echo esc_attr( $dn_week_start ); ?>">

		<input type="hidden" name="from" id="pltt-date-from" value="<?php echo esc_attr( $dn_from ); ?>">
		<input type="hidden" name="to"   id="pltt-date-to"   value="<?php echo esc_attr( $dn_to ); ?>">

		<button type="button" class="pltt-date-nav-step pltt-date-nav-prev"
			aria-label="<?php esc_attr_e( 'Previous period', 'plain-language-time-tracker' ); ?>"></button>

		<div class="pltt-date-nav-picker">
			<button type="button" class="pltt-date-nav-label"
				aria-expanded="false"
				id="pltt-date-nav-trigger">
				<span class="pltt-date-nav-label-main"><?php echo esc_html( $dn_active_label ?: $dn_range_label ); ?></span>
				<?php if ( $dn_active_label ) : ?>
					<span class="pltt-date-nav-label-sub"><?php echo esc_html( $dn_range_label ); ?></span>
				<?php endif; ?>
			</button>

			<div class="pltt-date-nav-dropdown" hidden>

				<ul class="pltt-date-nav-options">
				<?php foreach ( $dn_presets as $dn_p ) :
					$dn_sel = ( $dn_from === $dn_p['from'] && $dn_to === $dn_p['to'] );
					?>
					<li><button type="button"
						class="pltt-date-nav-option"
						data-from="<?php echo esc_attr( $dn_p['from'] ); ?>"
						data-to="<?php echo esc_attr( $dn_p['to'] ); ?>"
						<?php if ( $dn_sel ) : ?>aria-current="true"<?php endif; ?>>
						<?php echo esc_html( $dn_p['label'] ); ?>
					</button></li>
				<?php endforeach; ?>
				</ul>

				<hr class="pltt-date-nav-separator">

				<fieldset class="pltt-date-nav-custom-inputs">
					<legend><?php esc_html_e( 'Custom Range', 'plain-language-time-tracker' ); ?></legend>
					<label for="pltt-date-custom-from"><?php esc_html_e( 'From', 'plain-language-time-tracker' ); ?></label>
					<input type="date" id="pltt-date-custom-from" value="<?php echo esc_attr( $dn_from ); ?>">
					<label for="pltt-date-custom-to"><?php esc_html_e( 'To', 'plain-language-time-tracker' ); ?></label>
					<input type="date" id="pltt-date-custom-to" value="<?php echo esc_attr( $dn_to ); ?>">
					<button type="button" class="button button-primary pltt-date-nav-custom-apply">
						<?php esc_html_e( 'Apply', 'plain-language-time-tracker' ); ?>
					</button>
				</fieldset>
			</div>
		</div>

		<button type="button" class="pltt-date-nav-step pltt-date-nav-next"
			aria-label="<?php esc_attr_e( 'Next period', 'plain-language-time-tracker' ); ?>"></button>
	</div>
</div>

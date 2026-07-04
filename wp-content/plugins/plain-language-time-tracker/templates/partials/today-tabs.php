<?php
/**
 * Today · History toggle.
 *
 * Today is one destination with two sub-views: the day's journal/entries
 * (default) and the History browser (the retired Log History page). Both
 * templates include this bar just inside .wrap so the two read as one section.
 * Uses core's native .nav-tab styling.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab highlight.
$pltt_today_is_history = isset( $_GET['screen'] ) && 'history' === sanitize_key( wp_unslash( $_GET['screen'] ) );
?>
<nav class="nav-tab-wrapper pltt-today-tabs" aria-label="<?php esc_attr_e( 'Today views', 'plain-language-time-tracker' ); ?>">
	<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log' ) ); ?>"
		class="nav-tab <?php echo $pltt_today_is_history ? '' : 'nav-tab-active'; ?>">
		<?php esc_html_e( 'Today', 'plain-language-time-tracker' ); ?>
	</a>
	<a href="<?php echo esc_url( pltt_get_admin_url( 'history' ) ); ?>"
		class="nav-tab <?php echo $pltt_today_is_history ? 'nav-tab-active' : ''; ?>">
		<?php esc_html_e( 'History', 'plain-language-time-tracker' ); ?>
	</a>
</nav>
<?php
unset( $pltt_today_is_history );

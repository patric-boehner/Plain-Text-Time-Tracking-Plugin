<?php
/**
 * Today · History toggle.
 *
 * Today is one destination with two sub-views: the day's journal/entries
 * (default) and the History browser (the retired Log History page). Uses the
 * same .pltt-view-toggle button pair as the Insights (Reports) view switch, so
 * it sits in the header's right slot next to the <h1>.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab highlight.
$pltt_today_is_history = isset( $_GET['screen'] ) && 'history' === sanitize_key( wp_unslash( $_GET['screen'] ) );
?>
<div class="pltt-view-toggle">
	<a href="<?php echo esc_url( pltt_get_admin_url( 'daily-log' ) ); ?>"
		class="button <?php echo $pltt_today_is_history ? '' : 'button-primary'; ?>">
		<?php esc_html_e( 'Today', 'plain-language-time-tracker' ); ?>
	</a>
	<a href="<?php echo esc_url( pltt_get_admin_url( 'history' ) ); ?>"
		class="button <?php echo $pltt_today_is_history ? 'button-primary' : ''; ?>">
		<?php esc_html_e( 'History', 'plain-language-time-tracker' ); ?>
	</a>
</div>
<?php
unset( $pltt_today_is_history );

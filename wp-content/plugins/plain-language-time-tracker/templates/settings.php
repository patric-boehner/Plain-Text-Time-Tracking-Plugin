<?php
/**
 * Settings — the weekly balance digest.
 *
 * One section for now. The digest is the only thing in the plugin with a
 * preference attached to it; everything else is driven by the data.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$digest_settings = PLTT_Digest::get_settings();
$digest_last     = (int) get_option( PLTT_Digest::LAST_SENT_OPTION, 0 );
$digest_next     = (int) wp_next_scheduled( PLTT_Digest::HOOK );
$stamp_format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$admin_email     = (string) get_option( 'admin_email' );
$weekday_names   = isset( $GLOBALS['wp_locale'] ) ? $GLOBALS['wp_locale']->weekday : array();

if ( $digest_settings['enabled'] ) {
	$next_text = $digest_next
		? wp_date( $stamp_format, $digest_next )
		: __( 'not scheduled', 'plain-language-time-tracker' );
} else {
	$next_text = __( 'off', 'plain-language-time-tracker' );
}
$last_text = $digest_last ? wp_date( $stamp_format, $digest_last ) : __( 'never', 'plain-language-time-tracker' );
?>
<div class="wrap pltt-wrap">
	<div class="pltt-header">
		<h1><?php esc_html_e( 'Settings', 'plain-language-time-tracker' ); ?></h1>
		<?php
		pltt_render_admin_notices(
			array(
				'digest_saved' => __( 'Settings saved.', 'plain-language-time-tracker' ),
				/* translators: %s: email address */
				'digest_sent'  => sprintf( __( 'Test digest sent to %s.', 'plain-language-time-tracker' ), PLTT_Digest::recipient() ),
			),
			array(
				'digest_invalid_email' => __( 'That recipient is not a valid email address.', 'plain-language-time-tracker' ),
				'digest_send_failed'   => __( 'The test digest could not be sent. Check the site can send email.', 'plain-language-time-tracker' ),
			)
		);
		?>
	</div>

	<h2><?php esc_html_e( 'Weekly digest email', 'plain-language-time-tracker' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'One email a week listing every active retainer and fixed budget and where it stands — the same figures the notice shows after a save, on a morning you choose. Nothing is sent when no project has a ceiling.', 'plain-language-time-tracker' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="pltt_save_digest_settings">
		<?php wp_nonce_field( 'pltt_save_digest_settings' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Send the digest', 'plain-language-time-tracker' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="pltt_digest[enabled]" value="1" <?php checked( $digest_settings['enabled'] ); ?>>
						<?php esc_html_e( 'Every week', 'plain-language-time-tracker' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pltt-digest-weekday"><?php esc_html_e( 'Day', 'plain-language-time-tracker' ); ?></label></th>
				<td>
					<select id="pltt-digest-weekday" name="pltt_digest[weekday]">
						<?php for ( $d = 0; $d <= 6; $d++ ) : ?>
							<option value="<?php echo (int) $d; ?>" <?php selected( $digest_settings['weekday'], $d ); ?>>
								<?php echo esc_html( $weekday_names[ $d ] ?? (string) $d ); ?>
							</option>
						<?php endfor; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Sent around 7:00 am site time. Scheduled jobs run when the site is visited, so on a quiet day it goes out on the first visit after that.', 'plain-language-time-tracker' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pltt-digest-recipient"><?php esc_html_e( 'Send to', 'plain-language-time-tracker' ); ?></label></th>
				<td>
					<input type="email" id="pltt-digest-recipient" name="pltt_digest[recipient]" class="regular-text"
						value="<?php echo esc_attr( $digest_settings['recipient'] ); ?>"
						placeholder="<?php echo esc_attr( $admin_email ); ?>">
					<p class="description"><?php esc_html_e( 'Leave blank to use the site admin address.', 'plain-language-time-tracker' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'plain-language-time-tracker' ); ?></button>
		</p>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="pltt_send_digest_test">
		<?php wp_nonce_field( 'pltt_send_digest_test' ); ?>
		<p>
			<button type="submit" class="button"><?php esc_html_e( 'Send a test now', 'plain-language-time-tracker' ); ?></button>
		</p>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: last sent date/time or "never", 2: next scheduled date/time, "not scheduled" or "off" */
					__( 'Last sent: %1$s · Next scheduled: %2$s', 'plain-language-time-tracker' ),
					$last_text,
					$next_text
				)
			);
			?>
		</p>
	</form>
</div>

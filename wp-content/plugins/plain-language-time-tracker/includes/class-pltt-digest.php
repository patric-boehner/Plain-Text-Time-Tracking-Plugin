<?php
/**
 * Weekly balance digest — one email a week saying where every capped project
 * stands.
 *
 * Not an alert. The on-screen notice (pltt_render_consumption_notice()) only
 * appears after a save, so a week without processing is a week without seeing
 * the balances. This sends the same figures on a fixed morning whether or not
 * anything was saved. There is no "nearing" threshold on purpose: the docblock
 * on pltt_consumption_status() records why a percentage band would miss most
 * crossings. A fixed schedule also needs no de-duplication state — nothing has
 * to remember what was already sent.
 *
 * Runs on WP-Cron, which fires on page loads: on a site nobody visits it sends
 * on the first load after the scheduled time, never before it.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings, schedule, content and delivery of the weekly digest.
 */
class PLTT_Digest {

	const HOOK             = 'pltt_weekly_digest';
	const OPTION           = 'pltt_digest_settings';
	const LAST_SENT_OPTION = 'pltt_digest_last_sent';
	const SEND_HOUR        = 7;

	/**
	 * Hook the cron callback. Must run on every request, not only in wp-admin —
	 * the cron request that sends the email is not an admin request.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
	}

	/**
	 * Default settings: off, Monday, the site admin's address.
	 *
	 * @return array{ enabled: bool, weekday: int, recipient: string }
	 */
	public static function defaults() {
		return array(
			'enabled'   => false,
			'weekday'   => 1,
			'recipient' => '',
		);
	}

	/**
	 * Saved settings merged over the defaults and clamped to valid values.
	 *
	 * `recipient` stays '' when the user left it blank, so the default keeps
	 * following admin_email if that ever changes.
	 *
	 * @return array{ enabled: bool, weekday: int, recipient: string }
	 */
	public static function get_settings() {
		$saved    = get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		$settings['enabled']   = ! empty( $settings['enabled'] );
		$settings['weekday']   = min( 6, max( 0, (int) $settings['weekday'] ) );
		$settings['recipient'] = is_string( $settings['recipient'] ) ? $settings['recipient'] : '';

		return $settings;
	}

	/**
	 * Where the email goes.
	 *
	 * @return string
	 */
	public static function recipient() {
		$settings = self::get_settings();
		return '' !== $settings['recipient'] ? $settings['recipient'] : (string) get_option( 'admin_email' );
	}

	/**
	 * Sanitize and store settings from the form, then re-arm the schedule.
	 *
	 * @param array $raw Submitted fields (enabled, weekday, recipient).
	 * @return array The stored settings.
	 */
	public static function save_settings( array $raw ) {
		$settings = array(
			'enabled'   => ! empty( $raw['enabled'] ),
			'weekday'   => min( 6, max( 0, isset( $raw['weekday'] ) ? (int) $raw['weekday'] : 1 ) ),
			'recipient' => isset( $raw['recipient'] ) ? sanitize_email( $raw['recipient'] ) : '',
		);

		update_option( self::OPTION, $settings );
		self::schedule();

		return $settings;
	}

	/**
	 * (Re)create the weekly event from the current settings. A no-op while the
	 * digest is off, so it is safe to call from activation.
	 */
	public static function schedule() {
		self::unschedule();

		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}

		wp_schedule_event( self::next_run( $settings['weekday'] ), 'weekly', self::HOOK );
	}

	/**
	 * Remove the weekly event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Unix timestamp of the next $weekday at $hour o'clock, site time.
	 *
	 * Today counts when the hour is still ahead. Computed in the site's zone so
	 * "Monday at 7" means the site's Monday; WP-Cron itself works in UTC.
	 *
	 * @param int $weekday 0 (Sunday) … 6 (Saturday), the start_of_week convention.
	 * @param int $hour    Hour of the day, 0–23.
	 * @return int
	 */
	public static function next_run( $weekday, $hour = self::SEND_HOUR ) {
		$now       = new DateTimeImmutable( 'now', wp_timezone() );
		$candidate = $now->setTime( (int) $hour, 0, 0 );
		$shift     = ( (int) $weekday - (int) $now->format( 'w' ) + 7 ) % 7;

		if ( $shift > 0 ) {
			$candidate = $candidate->modify( "+{$shift} days" );
		}
		if ( $candidate <= $now ) {
			$candidate = $candidate->modify( '+7 days' );
		}

		return $candidate->getTimestamp();
	}

	/**
	 * Every active project with a ceiling and where it stands, most urgent first.
	 *
	 * Same row shape as pltt_consumption_status() where the keys overlap, so the
	 * shared comparator orders both lists the same way. Retainers report the
	 * period containing $reference_date; fixed budgets report the whole project.
	 * Each row also carries the minutes logged in the seven days ending on the
	 * reference date, so the email can say what moved since last time.
	 *
	 * @param string|null $reference_date Y-m-d; today when null.
	 * @return array List of { project, client, consumption, state, remaining_minutes,
	 *               over_minutes, used_percent, days_left, week_minutes, untouched }.
	 *               `state` is 'over' or 'under'; `days_left` is null for fixed
	 *               budgets; `untouched` is a retainer with nothing logged this period.
	 */
	public static function collect( $reference_date = null ) {
		if ( ! $reference_date ) {
			$reference_date = current_time( 'Y-m-d' );
		}

		$rows        = array();
		$client_ids  = array();
		$project_ids = array();

		foreach ( (array) PLTT_Projects::get_all( array( 'status' => 'active' ) ) as $project ) {
			// Null for hourly, internal, and retainers with no allocation.
			$consumption = pltt_project_consumption( $project, $reference_date );
			if ( null === $consumption ) {
				continue;
			}

			$ceiling   = (int) $consumption['ceiling_minutes'];
			$used      = (int) $consumption['consumed_minutes'];
			$days_left = null;
			if ( null !== $consumption['period_day'] && null !== $consumption['period_days'] ) {
				$days_left = max( 0, (int) $consumption['period_days'] - (int) $consumption['period_day'] );
			}

			$rows[] = array(
				'project'           => $project,
				'client'            => null,
				'consumption'       => $consumption,
				'state'             => ( $used >= $ceiling ) ? 'over' : 'under',
				'remaining_minutes' => max( 0, $ceiling - $used ),
				'over_minutes'      => max( 0, $used - $ceiling ),
				'used_percent'      => (int) round( ( $used / max( 1, $ceiling ) ) * 100 ),
				'days_left'         => $days_left,
				'week_minutes'      => 0,
				'untouched'         => ( null !== $days_left && 0 === $used ),
			);
			$project_ids[] = (int) $project->id;
			if ( ! empty( $project->client_id ) ) {
				$client_ids[] = (int) $project->client_id;
			}
		}

		if ( empty( $rows ) ) {
			return array();
		}

		$clients = array();
		if ( $client_ids ) {
			foreach ( (array) PLTT_Clients::get_multiple( array_unique( $client_ids ) ) as $client ) {
				if ( is_object( $client ) && isset( $client->id ) ) {
					$clients[ (int) $client->id ] = $client;
				}
			}
		}

		// The last seven days, one query for every project at once.
		$week_from = DateTimeImmutable::createFromFormat( '!Y-m-d', $reference_date, wp_timezone() );
		$week_from = $week_from ? $week_from->modify( '-6 days' )->format( 'Y-m-d' ) : $reference_date;
		$week      = PLTT_Entries::get_stats_grouped_by(
			'project_id',
			array(
				'date_from'   => $week_from,
				'date_to'     => $reference_date,
				'project_ids' => $project_ids,
			)
		);

		foreach ( $rows as &$row ) {
			$pid                 = (int) $row['project']->id;
			$cid                 = (int) ( $row['project']->client_id ?? 0 );
			$row['client']       = $clients[ $cid ] ?? null;
			$row['week_minutes'] = isset( $week[ $pid ] ) ? (int) $week[ $pid ]->total_minutes : 0;
		}
		unset( $row );

		return pltt_sort_consumption_rows( $rows );
	}

	/**
	 * Email subject.
	 *
	 * @param string $reference_date Y-m-d.
	 * @return string
	 */
	public static function build_subject( $reference_date ) {
		return sprintf(
			/* translators: %s: formatted date */
			__( 'Where your retainers and budgets stand — %s', 'plain-language-time-tracker' ),
			self::date_text( $reference_date )
		);
	}

	/**
	 * Plain-text body, in three sections: past the ceiling, running down, and
	 * retainers nobody has touched this period (folded to one line per project
	 * name, since five "1h left of 1h" rows say nothing five times).
	 *
	 * Every project line opens with the share of its ceiling used, so the eye
	 * can run down the left edge. The client leads the name because the project
	 * names repeat ("Website Care Plan Time" × 5) and the client is what tells
	 * them apart. Figures use the on-screen notice's wording — "1.5h left of 3h"
	 * while under, "4.1 of 3h, 1.1h over" once past — so the email and the
	 * screen say the same thing about the same project.
	 *
	 * @param array  $rows           From collect().
	 * @param string $reference_date Y-m-d.
	 * @return string
	 */
	public static function build_body( array $rows, $reference_date ) {
		$lines   = array();
		$lines[] = self::build_subject( $reference_date );
		$lines[] = '';

		if ( empty( $rows ) ) {
			$lines[] = __( 'No active projects have a ceiling right now.', 'plain-language-time-tracker' );
			$lines[] = '';
		}

		$s = self::sections( $rows );

		if ( $s['over'] ) {
			$lines[] = __( 'Over', 'plain-language-time-tracker' );
			foreach ( $s['over'] as $row ) {
				$lines[] = '  ' . self::line( $row );
			}
			$lines[] = '';
		}

		if ( $s['under'] ) {
			$lines[] = __( 'Running down', 'plain-language-time-tracker' );
			foreach ( $s['under'] as $row ) {
				$lines[] = '  ' . self::line( $row );
			}
			$lines[] = '';
		}

		if ( $s['untouched'] ) {
			$lines[] = __( 'Nothing logged yet this period', 'plain-language-time-tracker' );
			foreach ( self::untouched_by_name( $s['untouched'] ) as $name => $name_rows ) {
				$whos = array();
				foreach ( $name_rows as $row ) {
					if ( ! empty( $row['client']->name ) ) {
						$whos[] = (string) $row['client']->name;
					}
				}
				$lines[] = '  0% · ' . $name . ( $whos ? ': ' . implode( ', ', $whos ) : '' );
			}
			$lines[] = '';
		}

		$lines[] = __( 'Retainers show the current period; fixed budgets show the whole project.', 'plain-language-time-tracker' );
		/* translators: %s: URL of the Projects screen */
		$lines[] = sprintf( __( 'Projects: %s', 'plain-language-time-tracker' ), admin_url( 'admin.php?page=pltt-projects' ) );
		/* translators: %s: URL of the Settings screen */
		$lines[] = sprintf( __( 'Turn this email off: %s', 'plain-language-time-tracker' ), admin_url( 'admin.php?page=pltt-settings' ) );

		return implode( "\n", $lines );
	}

	/**
	 * The pieces of one project's line, shared by the text and HTML bodies.
	 *
	 * @param array $row A collect() row.
	 * @return array{ pct: string, who: string, detail: string }
	 *               pct "216%", who "Client · Project", detail "10.8 of 5h, 5.8h over, …".
	 */
	private static function line_parts( array $row ) {
		$c   = $row['consumption'];
		$who = (string) $row['project']->name;
		if ( ! empty( $row['client']->name ) ) {
			$who = $row['client']->name . ' · ' . $who;
		}

		$parts = array();
		if ( 'over' === $row['state'] ) {
			$parts[] = sprintf(
				/* translators: 1: hours used, 2: hours allowed */
				__( '%1$s of %2$sh', 'plain-language-time-tracker' ),
				pltt_consumption_hours( $c['consumed_minutes'] ),
				pltt_consumption_hours( $c['ceiling_minutes'] )
			);
			$over = pltt_consumption_hours( $row['over_minutes'] );
			// A minute or two past rounds to "0h over", which says nothing.
			if ( '0' !== $over ) {
				/* translators: %s: hours past the ceiling */
				$parts[] = sprintf( __( '%sh over', 'plain-language-time-tracker' ), $over );
			}
		} else {
			$parts[] = sprintf(
				/* translators: 1: hours remaining, 2: total hours allowed */
				__( '%1$sh left of %2$sh', 'plain-language-time-tracker' ),
				pltt_consumption_hours( $row['remaining_minutes'] ),
				pltt_consumption_hours( $c['ceiling_minutes'] )
			);
		}

		// A retainer balance means little without how much of the period is
		// left: 1.5h left with three weeks to go is comfortable; with two days,
		// it is not.
		if ( null !== $row['days_left'] ) {
			$n = (int) $row['days_left'];
			if ( 0 === $n ) {
				$parts[] = __( 'last day of the period', 'plain-language-time-tracker' );
			} else {
				/* translators: %d: days remaining in the allocation period */
				$parts[] = sprintf( _n( '%d day left in the period', '%d days left in the period', $n, 'plain-language-time-tracker' ), $n );
			}
		}

		// What moved since the last digest. Omitted when nothing did — a quiet
		// line reads as quiet without saying so.
		$week = pltt_consumption_hours( $row['week_minutes'] );
		if ( '0' !== $week ) {
			/* translators: %s: hours logged in the last seven days */
			$parts[] = sprintf( __( '%sh in the last 7 days', 'plain-language-time-tracker' ), $week );
		}

		return array(
			'pct'    => $row['used_percent'] . '%',
			'who'    => $who,
			'detail' => implode( ', ', $parts ),
		);
	}

	/**
	 * One project's text line: "{used}% · {Client} · {Project}: {figure}, {context…}".
	 *
	 * @param array $row A collect() row.
	 * @return string
	 */
	private static function line( array $row ) {
		$l = self::line_parts( $row );
		return $l['pct'] . ' · ' . $l['who'] . ': ' . $l['detail'];
	}

	/**
	 * The project page for a row.
	 *
	 * @param object $project Project row.
	 * @return string
	 */
	private static function project_url( $project ) {
		return admin_url( 'admin.php?page=pltt-projects&action=view&project_id=' . (int) $project->id );
	}

	/**
	 * Split rows into the three body sections, running-down re-sorted by share used.
	 *
	 * @param array $rows From collect().
	 * @return array{ over: array, under: array, untouched: array }
	 */
	private static function sections( array $rows ) {
		$over = $under = $untouched = array();
		foreach ( $rows as $row ) {
			if ( 'over' === $row['state'] ) {
				$over[] = $row;
			} elseif ( ! empty( $row['untouched'] ) ) {
				$untouched[] = $row;
			} else {
				$under[] = $row;
			}
		}

		// The shared comparator orders these by hours left, which suits the
		// notice. Here every line opens with the share used, so the section is
		// re-sorted by that figure — otherwise the left edge reads 97, 42, 56,
		// 55 and looks shuffled. Ties fall back to least left.
		usort(
			$under,
			static function ( $a, $b ) {
				$by_pct = $b['used_percent'] <=> $a['used_percent'];
				return $by_pct ?: ( $a['remaining_minutes'] <=> $b['remaining_minutes'] );
			}
		);

		return array(
			'over'      => $over,
			'under'     => $under,
			'untouched' => $untouched,
		);
	}

	/**
	 * Untouched retainers grouped by project name, each with its rows.
	 *
	 * @param array $untouched Rows flagged `untouched`.
	 * @return array Map of project name => rows, names in natural order,
	 *               rows in client-name order.
	 */
	private static function untouched_by_name( array $untouched ) {
		$by_name = array();
		foreach ( $untouched as $row ) {
			$by_name[ (string) $row['project']->name ][] = $row;
		}
		ksort( $by_name, SORT_NATURAL | SORT_FLAG_CASE );
		foreach ( $by_name as &$rows ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					return strnatcasecmp( (string) ( $a['client']->name ?? '' ), (string) ( $b['client']->name ?? '' ) );
				}
			);
		}
		unset( $rows );
		return $by_name;
	}

	/**
	 * HTML body: the same three sections as the text, as inline-styled tables.
	 *
	 * Inline styles and nested tables because that is what email clients
	 * render consistently; nothing here depends on a stylesheet. Colours are
	 * the admin palette's hex values (admin.css / pltt-system.css) — over is
	 * the amber used for overage everywhere else, never red: over is a fact.
	 *
	 * @param array  $rows           From collect().
	 * @param string $reference_date Y-m-d.
	 * @return string
	 */
	public static function build_html( array $rows, $reference_date ) {
		$font   = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
		$ink    = '#1d2327';
		$muted  = '#646970';
		$rule   = '#dcdcde';
		$faint  = '#f0f0f1';
		$amber  = '#9a6a10';
		$pct_td = 'padding:9px 12px 9px 0;width:44px;text-align:right;vertical-align:top;white-space:nowrap;font-weight:600;font-variant-numeric:tabular-nums;border-bottom:1px solid ' . $faint . ';';
		$row_td = 'padding:9px 0;vertical-align:top;border-bottom:1px solid ' . $faint . ';';
		$h_sec  = 'font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:' . $muted . ';padding:0 0 4px;border-bottom:1px solid ' . $rule . ';';

		$s = self::sections( $rows );

		$section = static function ( $title, array $section_rows, $pct_color ) use ( $pct_td, $row_td, $h_sec, $ink, $muted ) {
			if ( empty( $section_rows ) ) {
				return '';
			}
			$html  = '<tr><td style="padding:18px 24px 0;">';
			$html .= '<div style="' . $h_sec . '">' . esc_html( $title ) . '</div>';
			$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
			foreach ( $section_rows as $row ) {
				$l     = self::line_parts( $row );
				$html .= '<tr>';
				$html .= '<td style="' . $pct_td . 'color:' . $pct_color . ';">' . esc_html( $l['pct'] ) . '</td>';
				$html .= '<td style="' . $row_td . '">';
				$html .= '<a href="' . esc_url( self::project_url( $row['project'] ) ) . '" style="color:' . $ink . ';font-weight:600;text-decoration:none;">' . esc_html( $l['who'] ) . '</a>';
				$html .= '<div style="color:' . $muted . ';">' . esc_html( $l['detail'] ) . '</div>';
				$html .= '</td></tr>';
			}
			$html .= '</table></td></tr>';
			return $html;
		};

		$body  = '';
		$body .= $section( __( 'Over', 'plain-language-time-tracker' ), $s['over'], $amber );
		$body .= $section( __( 'Running down', 'plain-language-time-tracker' ), $s['under'], $ink );

		if ( $s['untouched'] ) {
			$body .= '<tr><td style="padding:18px 24px 0;">';
			$body .= '<div style="' . $h_sec . '">' . esc_html__( 'Nothing logged yet this period', 'plain-language-time-tracker' ) . '</div>';
			$body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
			foreach ( self::untouched_by_name( $s['untouched'] ) as $name => $name_rows ) {
				$links = array();
				foreach ( $name_rows as $row ) {
					$label   = ! empty( $row['client']->name ) ? $row['client']->name : $name;
					$links[] = '<a href="' . esc_url( self::project_url( $row['project'] ) ) . '" style="color:' . $muted . ';text-decoration:none;">' . esc_html( $label ) . '</a>';
				}
				$body .= '<tr>';
				$body .= '<td style="' . $pct_td . 'color:' . $muted . ';">0%</td>';
				$body .= '<td style="' . $row_td . '"><span style="font-weight:600;">' . esc_html( $name ) . '</span>';
				$body .= '<div style="color:' . $muted . ';">' . implode( ', ', $links ) . '</div>';
				$body .= '</td></tr>';
			}
			$body .= '</table></td></tr>';
		}

		if ( '' === $body ) {
			$body = '<tr><td style="padding:18px 24px 0;color:' . $muted . ';">' . esc_html__( 'No active projects have a ceiling right now.', 'plain-language-time-tracker' ) . '</td></tr>';
		}

		$title = __( 'Where your retainers and budgets stand', 'plain-language-time-tracker' );

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . esc_html( self::build_subject( $reference_date ) ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f6f7f7;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f7;"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid ' . $rule . ';border-radius:4px;font-family:' . $font . ';color:' . $ink . ';font-size:14px;line-height:1.5;text-align:left;">'
			. '<tr><td style="padding:22px 24px 4px;">'
			. '<div style="font-size:18px;font-weight:600;line-height:1.3;">' . esc_html( $title ) . '</div>'
			. '<div style="color:' . $muted . ';">' . esc_html( self::date_text( $reference_date ) ) . '</div>'
			. '</td></tr>'
			. $body
			. '<tr><td style="padding:26px 24px 24px;">'
			. '<div style="font-size:12px;color:' . $muted . ';">'
			. esc_html__( 'Retainers show the current period; fixed budgets show the whole project.', 'plain-language-time-tracker' ) . '<br>'
			. '<a href="' . esc_url( admin_url( 'admin.php?page=pltt-projects' ) ) . '" style="color:' . $muted . ';">' . esc_html__( 'Projects', 'plain-language-time-tracker' ) . '</a>'
			. ' &middot; '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=pltt-settings' ) ) . '" style="color:' . $muted . ';">' . esc_html__( 'Turn this email off', 'plain-language-time-tracker' ) . '</a>'
			. '</div></td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Send the digest to the configured recipient.
	 *
	 * @param bool $force Send even when no project has a ceiling (the test button).
	 * @return true|false|WP_Error True when sent, false when skipped for lack of
	 *                             rows, WP_Error when wp_mail() refused.
	 */
	public static function send( $force = false ) {
		$reference_date = current_time( 'Y-m-d' );
		$rows           = self::collect( $reference_date );

		if ( empty( $rows ) && ! $force ) {
			return false;
		}

		$text = self::build_body( $rows, $reference_date );
		$html = self::build_html( $rows, $reference_date );

		// HTML for the client that renders it, the text version as the
		// alternative part for one that doesn't (and for anyone who prefers it).
		// wp_mail() has no AltBody argument, so it is set on PHPMailer directly,
		// for this one send only.
		$set_alt = static function ( $phpmailer ) use ( $text ) {
			$phpmailer->AltBody = $text;
		};
		add_action( 'phpmailer_init', $set_alt );
		$sent = wp_mail(
			self::recipient(),
			self::build_subject( $reference_date ),
			$html,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
		remove_action( 'phpmailer_init', $set_alt );

		return $sent ? true : new WP_Error( 'pltt_digest_send_failed', 'wp_mail() returned false.' );
	}

	/**
	 * Cron callback.
	 */
	public static function run_scheduled() {
		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}
		if ( true === self::send() ) {
			update_option( self::LAST_SENT_OPTION, time(), false );
		}
	}

	/**
	 * A Y-m-d in the site's date format.
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	private static function date_text( $ymd ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
		return $dt ? wp_date( get_option( 'date_format' ), $dt->getTimestamp() ) : $ymd;
	}
}

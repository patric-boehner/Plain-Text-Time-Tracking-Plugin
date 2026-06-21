<?php
/**
 * Admin initialization and menu registration.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin menu and asset loading.
 */
class PLTT_Admin {

	/**
	 * Initialize admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register admin menu pages.
	 */
	public static function add_admin_menu() {
		// Main menu page (Daily Log).
		add_menu_page(
			__( 'Time Tracker', 'plain-language-time-tracker' ),
			__( 'Time Tracker', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-time-tracker',
			array( __CLASS__, 'render_page' ),
			'dashicons-clock',
			30
		);

		// Submenu: Daily Log (same as parent).
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Daily Log', 'plain-language-time-tracker' ),
			__( 'Daily Log', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-time-tracker',
			array( __CLASS__, 'render_page' )
		);

		// Submenu: Log History.
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Log History', 'plain-language-time-tracker' ),
			__( 'Log History', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-log-archive',
			array( __CLASS__, 'render_log_archive_page' )
		);

		// Submenu: Reports.
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Reports', 'plain-language-time-tracker' ),
			__( 'Reports', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-reports',
			array( __CLASS__, 'render_reports_page' )
		);

		// Submenu: Clients.
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Clients', 'plain-language-time-tracker' ),
			__( 'Clients', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-clients',
			array( __CLASS__, 'render_clients_page' )
		);

		// Submenu: Projects.
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Projects', 'plain-language-time-tracker' ),
			__( 'Projects', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-projects',
			array( __CLASS__, 'render_projects_page' )
		);

		// Submenu: Tags.
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Tags', 'plain-language-time-tracker' ),
			__( 'Tags', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-tags',
			array( __CLASS__, 'render_tags_page' )
		);

	}

	/**
	 * Gate a page render on the plugin capability; dies with a 403 otherwise.
	 *
	 * Shared by every render_*_page() method (OPT-DUP-D) so the capability copy
	 * lives in one place.
	 */
	private static function require_access() {
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Render the main page (Daily Log or Review based on screen param).
	 */
	public static function render_page() {
		self::require_access();

		// Check which screen to show.
		$screen = isset( $_GET['screen'] ) ? sanitize_text_field( wp_unslash( $_GET['screen'] ) ) : 'daily-log';

		switch ( $screen ) {
			case 'review':
				PLTT_Review::render();
				break;
			default:
				PLTT_Daily_Log::render();
				break;
		}
	}

	/**
	 * Render the log archive page.
	 */
	public static function render_log_archive_page() {
		self::require_access();

		PLTT_Log_Archive::render();
	}

	/**
	 * Render the reports page.
	 */
	public static function render_reports_page() {
		self::require_access();

		PLTT_Reports::render();
	}

	/**
	 * Render the clients page.
	 */
	public static function render_clients_page() {
		self::require_access();

		include PLTT_PLUGIN_DIR . 'templates/clients.php';
	}

	/**
	 * Render the projects page.
	 */
	public static function render_projects_page() {
		self::require_access();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'view' === $action ) {
			PLTT_Project_Detail::render();
			return;
		}

		include PLTT_PLUGIN_DIR . 'templates/projects.php';
	}

	/**
	 * Render the tags page.
	 */
	public static function render_tags_page() {
		self::require_access();

		include PLTT_PLUGIN_DIR . 'templates/tags.php';
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our plugin pages.
		$plugin_pages = array(
			'toplevel_page_pltt-time-tracker',
			'time-tracker_page_pltt-log-archive',
			'time-tracker_page_pltt-reports',
			'time-tracker_page_pltt-clients',
			'time-tracker_page_pltt-projects',
			'time-tracker_page_pltt-tags',
		);

		if ( ! in_array( $hook, $plugin_pages, true ) ) {
			return;
		}

		$version = defined( 'WP_DEBUG' ) && WP_DEBUG ? time() : PLTT_VERSION;

		// Shared admin styles.
		wp_enqueue_style(
			'pltt-admin',
			PLTT_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$version
		);

		// Shared JS utilities.
		wp_enqueue_script(
			'pltt-shared',
			PLTT_PLUGIN_URL . 'assets/js/shared.js',
			array(),
			$version,
			true
		);

		// Localize script data.
		wp_localize_script(
			'pltt-shared',
			'plttData',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'pltt_ajax_nonce' ),
				'autosaveDebounceMs' => PLTT_AUTOSAVE_DEBOUNCE_MS,
				'internalClientId' => pltt_get_internal_client_id(),
				'i18n'             => array(
					'saving'     => __( 'Saving...', 'plain-language-time-tracker' ),
					'saved'      => __( 'Saved', 'plain-language-time-tracker' ),
					'savedAt'    => __( 'Saved %s', 'plain-language-time-tracker' ),
					'unsaved'    => __( 'Unsaved changes', 'plain-language-time-tracker' ),
					'error'      => __( 'Error', 'plain-language-time-tracker' ),
					'confirm'    => __( 'Are you sure?', 'plain-language-time-tracker' ),
					'processing' => __( 'Processing...', 'plain-language-time-tracker' ),
				),
			)
		);

		// Page-specific assets.
		if ( 'toplevel_page_pltt-time-tracker' === $hook ) {
			$screen = isset( $_GET['screen'] ) ? sanitize_text_field( wp_unslash( $_GET['screen'] ) ) : 'daily-log';

			if ( 'review' === $screen ) {
				wp_enqueue_style(
					'pltt-tag-picker',
					PLTT_PLUGIN_URL . 'assets/css/tag-picker.css',
					array( 'pltt-admin' ),
					$version
				);
				wp_enqueue_style(
					'pltt-review',
					PLTT_PLUGIN_URL . 'assets/css/review.css',
					array( 'pltt-admin', 'pltt-tag-picker' ),
					$version
				);
				wp_enqueue_script(
					'pltt-tag-picker',
					PLTT_PLUGIN_URL . 'assets/js/tag-picker.js',
					array(),
					$version,
					true
				);
				wp_enqueue_script(
					'pltt-review',
					PLTT_PLUGIN_URL . 'assets/js/review.js',
					array( 'pltt-shared', 'pltt-tag-picker' ),
					$version,
					true
				);
			} else {
				wp_enqueue_style(
					'pltt-daily-log',
					PLTT_PLUGIN_URL . 'assets/css/daily-log.css',
					array( 'pltt-admin' ),
					$version
				);
				wp_enqueue_script(
					'pltt-daily-log',
					PLTT_PLUGIN_URL . 'assets/js/daily-log.js',
					array( 'pltt-shared' ),
					$version,
					true
				);
			}
		}

		if ( 'time-tracker_page_pltt-log-archive' === $hook ) {
			wp_enqueue_style(
				'pltt-log-archive',
				PLTT_PLUGIN_URL . 'assets/css/log-archive.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_script(
				'pltt-log-archive',
				PLTT_PLUGIN_URL . 'assets/js/log-archive.js',
				array( 'pltt-shared' ),
				$version,
				true
			);
		}

		// Alias/keyword chip manager — clients (alias seeding) and tags (keyword
		// seeding) use the same widget in their create/edit modal. Projects rely
		// on client association + most-recent-used, so they have no seeding UI.
		if ( 'time-tracker_page_pltt-clients' === $hook || 'time-tracker_page_pltt-tags' === $hook ) {
			wp_enqueue_style(
				'pltt-alias-chips',
				PLTT_PLUGIN_URL . 'assets/css/alias-chips.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_script(
				'pltt-alias-chips',
				PLTT_PLUGIN_URL . 'assets/js/alias-chips.js',
				array( 'pltt-shared' ),
				$version,
				true
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only asset routing.
		$projects_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'time-tracker_page_pltt-projects' === $hook && 'view' === $projects_action ) {
			wp_enqueue_style(
				'pltt-tooltip',
				PLTT_PLUGIN_URL . 'assets/css/pltt-tooltip.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_style(
				'pltt-chart',
				PLTT_PLUGIN_URL . 'assets/css/pltt-chart.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_style(
				'pltt-project-detail',
				PLTT_PLUGIN_URL . 'assets/css/project-detail.css',
				array( 'pltt-admin', 'pltt-tooltip', 'pltt-chart' ),
				$version
			);
			wp_enqueue_script(
				'pltt-tooltip',
				PLTT_PLUGIN_URL . 'assets/js/pltt-tooltip.js',
				array(),
				$version,
				true
			);
			wp_enqueue_script(
				'pltt-project-detail',
				PLTT_PLUGIN_URL . 'assets/js/project-detail.js',
				array( 'pltt-shared', 'pltt-tooltip' ),
				$version,
				true
			);
		}

		if ( 'time-tracker_page_pltt-reports' === $hook ) {
			wp_enqueue_style(
				'pltt-tag-picker',
				PLTT_PLUGIN_URL . 'assets/css/tag-picker.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_style(
				'pltt-tooltip',
				PLTT_PLUGIN_URL . 'assets/css/pltt-tooltip.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_style(
				'pltt-chart',
				PLTT_PLUGIN_URL . 'assets/css/pltt-chart.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_style(
				'pltt-reports',
				PLTT_PLUGIN_URL . 'assets/css/reports.css',
				array( 'pltt-admin', 'pltt-tag-picker' ),
				$version
			);
			wp_enqueue_script(
				'pltt-tag-picker',
				PLTT_PLUGIN_URL . 'assets/js/tag-picker.js',
				array(),
				$version,
				true
			);
			wp_enqueue_script(
				'pltt-tooltip',
				PLTT_PLUGIN_URL . 'assets/js/pltt-tooltip.js',
				array(),
				$version,
				true
			);
			wp_enqueue_script(
				'pltt-reports',
				PLTT_PLUGIN_URL . 'assets/js/reports.js',
				array( 'pltt-shared', 'pltt-tag-picker', 'pltt-tooltip' ),
				$version,
				true
			);
		}
	}

}

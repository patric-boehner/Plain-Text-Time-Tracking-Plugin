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
		add_action( 'admin_notices', array( __CLASS__, 'render_migration_notices' ) );
		add_action( 'admin_post_pltt_dismiss_migration_1_9_5', array( __CLASS__, 'dismiss_migration_1_9_5_notice' ) );
	}

	/**
	 * Surface one-time admin notices left behind by migrations.
	 *
	 * The 1.9.5 billable-model migration flips retainer / fixed-fee entries to
	 * non-billable and writes a review log to wp-content/uploads. The link to
	 * that log is held in pltt_migration_1_9_5_log_url until the user dismisses.
	 */
	public static function render_migration_notices() {
		if ( ! pltt_user_can_access() ) {
			return;
		}

		$log_url = get_option( 'pltt_migration_1_9_5_log_url' );
		if ( empty( $log_url ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'action', 'pltt_dismiss_migration_1_9_5', admin_url( 'admin-post.php' ) ),
			'pltt_dismiss_migration_1_9_5'
		);
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Time Tracker:', 'plain-language-time-tracker' ); ?></strong>
				<?php esc_html_e( 'Billable-model migration ran. Retainer and fixed-fee entries that were marked billable have been flipped to non-billable.', 'plain-language-time-tracker' ); ?>
				<a href="<?php echo esc_url( $log_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Download migration log', 'plain-language-time-tracker' ); ?>
				</a>
				— <?php esc_html_e( 'review invoiced entries that may need to be re-flipped manually.', 'plain-language-time-tracker' ); ?>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 8px;">
					<?php esc_html_e( 'Dismiss', 'plain-language-time-tracker' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the dismiss action for the 1.9.5 migration notice.
	 */
	public static function dismiss_migration_1_9_5_notice() {
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'plain-language-time-tracker' ) );
		}
		check_admin_referer( 'pltt_dismiss_migration_1_9_5' );
		delete_option( 'pltt_migration_1_9_5_log_url' );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=pltt-time-tracker' ) );
		exit;
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
	 * Render the main page (Daily Log or Review based on screen param).
	 */
	public static function render_page() {
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plain-language-time-tracker' ) );
		}

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
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plain-language-time-tracker' ) );
		}

		PLTT_Log_Archive::render();
	}

	/**
	 * Render the reports page.
	 */
	public static function render_reports_page() {
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plain-language-time-tracker' ) );
		}

		PLTT_Reports::render();
	}

	/**
	 * Render the clients page.
	 */
	public static function render_clients_page() {
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plain-language-time-tracker' ) );
		}

		include PLTT_PLUGIN_DIR . 'templates/clients.php';
	}

	/**
	 * Render the projects page.
	 */
	public static function render_projects_page() {
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plain-language-time-tracker' ) );
		}

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
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plain-language-time-tracker' ) );
		}

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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only asset routing.
		$projects_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'time-tracker_page_pltt-projects' === $hook && 'view' === $projects_action ) {
			wp_enqueue_style(
				'pltt-project-detail',
				PLTT_PLUGIN_URL . 'assets/css/project-detail.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_script(
				'pltt-project-detail',
				PLTT_PLUGIN_URL . 'assets/js/project-detail.js',
				array( 'pltt-shared' ),
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
				'pltt-reports',
				PLTT_PLUGIN_URL . 'assets/js/reports.js',
				array( 'pltt-shared', 'pltt-tag-picker' ),
				$version,
				true
			);
		}
	}

}

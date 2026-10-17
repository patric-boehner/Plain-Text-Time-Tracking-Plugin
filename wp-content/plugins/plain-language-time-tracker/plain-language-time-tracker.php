<?php
/**
 * Plugin Name: Plain Language Time Tracker
 * Plugin URI: https://github.com/patrickb/plain-language-time-tracker
 * Description: Capture first, categorize later. Interstitial journaling and time tracking in one plain-text log — jot timestamped notes between tasks, then process them into structured time entries. Everything you wrote around the timestamps stays as a journal.
 * Version: 1.9.89
 * Author: Patrick Boehner
 * Text Domain: plain-language-time-tracker
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: false
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'PLTT_VERSION', '1.9.89' );
define( 'PLTT_PLUGIN_FILE', __FILE__ );
define( 'PLTT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLTT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PLTT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Configurable defaults — centralized here instead of scattered as magic numbers.
define( 'PLTT_PREDICTION_WINDOW_DAYS', 30 );
define( 'PLTT_CONFIDENCE_THRESHOLD', 0.7 );
define( 'PLTT_ENTRIES_PER_PAGE', 50 );
define( 'PLTT_LOGS_PER_PAGE', 20 );
define( 'PLTT_AUTOSAVE_DEBOUNCE_MS', 1500 );
define( 'PLTT_DEFAULT_HOURLY_RATE', 100.00 );
define( 'PLTT_ALLOWED_RECURRING_PERIODS', array( '', 'weekly', 'monthly', 'quarterly', 'yearly' ) );

// Weekly balance digest — one email a week saying where every retainer and
// fixed budget stands: Monday at 7, to the site admin, unless changed here.
// Configured here, like everything else, rather than on a settings page; the
// schedule follows these on the next admin page load, so editing them is all
// it takes. Each can also be set earlier in wp-config.php to differ between
// sites.
if ( ! defined( 'PLTT_DIGEST_ENABLED' ) ) {
	define( 'PLTT_DIGEST_ENABLED', true );
}
if ( ! defined( 'PLTT_DIGEST_WEEKDAY' ) ) {
	define( 'PLTT_DIGEST_WEEKDAY', 1 ); // 0 = Sunday … 6 = Saturday.
}
if ( ! defined( 'PLTT_DIGEST_HOUR' ) ) {
	define( 'PLTT_DIGEST_HOUR', 7 ); // Site time, 24-hour clock.
}
if ( ! defined( 'PLTT_DIGEST_RECIPIENT' ) ) {
	define( 'PLTT_DIGEST_RECIPIENT', '' ); // Blank = the site admin email.
}

/**
 * Load plugin dependencies.
 */
function pltt_load_dependencies() {
	// Helpers (procedural functions).
	require_once PLTT_PLUGIN_DIR . 'includes/helpers.php';

	// Database classes.
	require_once PLTT_PLUGIN_DIR . 'includes/database/class-pltt-database.php';
	require_once PLTT_PLUGIN_DIR . 'includes/database/class-pltt-clients.php';
	require_once PLTT_PLUGIN_DIR . 'includes/database/class-pltt-projects.php';
	require_once PLTT_PLUGIN_DIR . 'includes/database/class-pltt-tags.php';
	require_once PLTT_PLUGIN_DIR . 'includes/database/class-pltt-entries.php';
	require_once PLTT_PLUGIN_DIR . 'includes/database/class-pltt-aliases.php';
	require_once PLTT_PLUGIN_DIR . 'includes/database/class-pltt-tag-aliases.php';
	require_once PLTT_PLUGIN_DIR . 'includes/database/class-pltt-billing-records.php';
	require_once PLTT_PLUGIN_DIR . 'includes/database/class-pltt-billing-record-entries.php';

	// Billing engine (read model).
	require_once PLTT_PLUGIN_DIR . 'includes/class-pltt-billing.php';

	// Weekly balance digest (WP-Cron + email; configured by the constants above).
	require_once PLTT_PLUGIN_DIR . 'includes/class-pltt-digest.php';

	// Parser.
	require_once PLTT_PLUGIN_DIR . 'includes/parser/class-pltt-time-parser.php';

	// API.
	require_once PLTT_PLUGIN_DIR . 'includes/api/class-pltt-ajax.php';
	require_once PLTT_PLUGIN_DIR . 'includes/api/class-pltt-form-handlers.php';

	// Admin.
	require_once PLTT_PLUGIN_DIR . 'includes/admin/class-pltt-admin.php';
	require_once PLTT_PLUGIN_DIR . 'includes/admin/class-pltt-daily-log.php';
	require_once PLTT_PLUGIN_DIR . 'includes/admin/class-pltt-review.php';
	require_once PLTT_PLUGIN_DIR . 'includes/admin/class-pltt-reports.php';
	require_once PLTT_PLUGIN_DIR . 'includes/admin/class-pltt-project-report.php';
	require_once PLTT_PLUGIN_DIR . 'includes/admin/class-pltt-project-detail.php';
	require_once PLTT_PLUGIN_DIR . 'includes/admin/class-pltt-billing-surface.php';
	require_once PLTT_PLUGIN_DIR . 'includes/admin/class-pltt-log-archive.php';
}

/**
 * Plugin activation.
 */
function pltt_activate() {
	pltt_load_dependencies();
	PLTT_Database::create_tables();
	add_option( 'pltt_version', PLTT_VERSION );
	add_option( 'pltt_db_version', PLTT_Database::DB_VERSION );

	// Arm the weekly digest if PLTT_DIGEST_ENABLED is on. A no-op while it's off.
	PLTT_Digest::schedule();
}
register_activation_hook( __FILE__, 'pltt_activate' );

/**
 * Plugin deactivation: drop the digest's cron event so WP-Cron never fires a
 * hook with no callback behind it.
 */
function pltt_deactivate() {
	pltt_load_dependencies();
	PLTT_Digest::unschedule();
}
register_deactivation_hook( __FILE__, 'pltt_deactivate' );

/**
 * Initialize the plugin.
 */
function pltt_init() {
	pltt_load_dependencies();

	// Check for database updates.
	PLTT_Database::maybe_upgrade();

	// The digest's cron callback. Outside the is_admin() gate on purpose: the
	// request WP-Cron runs in is not an admin request.
	PLTT_Digest::init();

	// Initialize admin.
	if ( is_admin() ) {
		PLTT_Admin::init();
		PLTT_Ajax::init();
		PLTT_Form_Handlers::init();
	}
}
add_action( 'plugins_loaded', 'pltt_init' );

/**
 * Load plugin textdomain for translations.
 */
function pltt_load_textdomain() {
	load_plugin_textdomain(
		'plain-language-time-tracker',
		false,
		dirname( PLTT_PLUGIN_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'pltt_load_textdomain' );
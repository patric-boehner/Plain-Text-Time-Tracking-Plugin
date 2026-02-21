<?php
/**
 * Plugin uninstall handler.
 *
 * This file runs when the plugin is deleted from WordPress.
 * It removes all plugin data including database tables and options.
 *
 * @package PlainLanguageTimeTracker
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the database class to use drop_tables().
require_once plugin_dir_path( __FILE__ ) . 'includes/database/class-pltt-database.php';

// Drop all plugin tables.
PLTT_Database::drop_tables();

// Delete plugin options.
delete_option( 'pltt_version' );
delete_option( 'pltt_db_version' );

// Delete plugin options not covered by drop_tables().
delete_option( 'pltt_custom_tags' ); // May already be gone from 1.8.0 migration.

// Delete any transients.
delete_transient( 'pltt_daily_log_cache' );
delete_transient( 'pltt_tags_list' );

// Clear any scheduled events (if added in future).
wp_clear_scheduled_hook( 'pltt_daily_cleanup' );

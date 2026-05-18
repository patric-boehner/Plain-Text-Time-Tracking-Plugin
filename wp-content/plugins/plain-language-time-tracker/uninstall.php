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
delete_option( 'pltt_custom_tags' ); // May already be gone from 1.8.1 migration.

// Delete any transients written by the helpers.
delete_transient( 'pltt_tags_list' );
delete_transient( 'pltt_clients_list' );
delete_transient( 'pltt_projects_list' );
delete_transient( 'pltt_aliases_list' );

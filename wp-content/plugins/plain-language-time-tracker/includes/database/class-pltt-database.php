<?php
/**
 * Database schema and migrations.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database table creation and upgrades.
 */
class PLTT_Database {

	/**
	 * Current database version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.7.0';

	/**
	 * Get the full table name with WordPress prefix.
	 *
	 * @param string $table Table name without prefix.
	 * @return string Full table name.
	 */
	public static function get_table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . 'pltt_' . $table;
	}

	/**
	 * Create all plugin tables.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Clients table.
		$table_clients = self::get_table_name( 'clients' );
		$sql_clients   = "CREATE TABLE {$table_clients} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text,
			hourly_rate decimal(10,2) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY name (name(191))
		) {$charset_collate};";
		dbDelta( $sql_clients );

		// Projects table.
		$table_projects = self::get_table_name( 'projects' );
		$sql_projects   = "CREATE TABLE {$table_projects} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL,
			name varchar(255) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			description text,
			hourly_rate decimal(10,2) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY client_id (client_id),
			KEY status (status),
			KEY name (name(191)),
			KEY client_status (client_id, status)
		) {$charset_collate};";
		dbDelta( $sql_projects );

		// Time entries table.
		$table_entries = self::get_table_name( 'time_entries' );
		$sql_entries   = "CREATE TABLE {$table_entries} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_date date NOT NULL,
			start_time time NOT NULL,
			end_time time,
			duration_minutes int unsigned,
			raw_text text NOT NULL,
			description text,
			client_id bigint(20) unsigned,
			project_id bigint(20) unsigned,
			verified tinyint(1) NOT NULL DEFAULT 0,
			billable tinyint(1) NOT NULL DEFAULT 0,
			billable_rate decimal(10,2) DEFAULT NULL COMMENT 'Hourly rate at time of verification',
			billable_amount decimal(10,2) DEFAULT NULL COMMENT 'Calculated billable amount (locked at verification)',
			billed tinyint(1) NOT NULL DEFAULT 0,
			tags varchar(500),
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY entry_date (entry_date),
			KEY client_id (client_id),
			KEY project_id (project_id),
			KEY verified (verified),
			KEY date_client (entry_date, client_id),
			KEY billable (billable),
			KEY billed (billed),
			KEY verified_billable (verified, billable)
		) {$charset_collate};";
		dbDelta( $sql_entries );

		// Aliases table (for learning system).
		$table_aliases = self::get_table_name( 'aliases' );
		$sql_aliases   = "CREATE TABLE {$table_aliases} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			alias_text varchar(100) NOT NULL,
			client_id bigint(20) unsigned,
			project_id bigint(20) unsigned,
			confidence decimal(3,2) NOT NULL DEFAULT 0.50,
			use_count int unsigned NOT NULL DEFAULT 0,
			correct_count int unsigned NOT NULL DEFAULT 0,
			last_used datetime,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY alias_text (alias_text),
			KEY client_id (client_id),
			KEY project_id (project_id),
			KEY confidence (confidence)
		) {$charset_collate};";
		dbDelta( $sql_aliases );

		// Daily logs table (raw text storage).
		$table_logs = self::get_table_name( 'daily_logs' );
		$sql_logs   = "CREATE TABLE {$table_logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_date date NOT NULL,
			content longtext,
			processed tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY log_date (log_date)
		) {$charset_collate};";
		dbDelta( $sql_logs );

		// Update database version.
		update_option( 'pltt_db_version', self::DB_VERSION );
	}

	/**
	 * Check if database needs upgrade and run create_tables.
	 */
	public static function maybe_upgrade() {
		$current_version = get_option( 'pltt_db_version', '0' );

		if ( version_compare( $current_version, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			self::migrate( $current_version );
		}
	}

	/**
	 * Run migrations for specific version upgrades.
	 *
	 * @param string $from_version Version upgrading from.
	 */
	private static function migrate( $from_version ) {
		global $wpdb;

		// 1.6.0: Drop task_type from time_entries, billing_model and fixed_fee from projects.
		if ( version_compare( $from_version, '1.6.0', '<' ) ) {
			$entries_table  = self::get_table_name( 'time_entries' );
			$projects_table = self::get_table_name( 'projects' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$entries_table} DROP COLUMN IF EXISTS task_type" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$projects_table} DROP COLUMN IF EXISTS billing_model" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$projects_table} DROP COLUMN IF EXISTS fixed_fee" );

			delete_option( 'pltt_task_types' );
		}

		// 1.7.0: Add performance indexes for billable/billed filtering and project queries.
		if ( version_compare( $from_version, '1.7.0', '<' ) ) {
			$entries_table  = self::get_table_name( 'time_entries' );
			$projects_table = self::get_table_name( 'projects' );

			// Add indexes to time_entries table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$entries_table} ADD INDEX billable (billable)" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$entries_table} ADD INDEX billed (billed)" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$entries_table} ADD INDEX verified_billable (verified, billable)" );

			// Add composite index to projects table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$projects_table} ADD INDEX client_status (client_id, status)" );
		}
	}

	/**
	 * Drop all plugin tables (used during uninstall).
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			'time_entries',
			'projects',
			'clients',
			'aliases',
			'daily_logs',
		);

		foreach ( $tables as $table ) {
			$table_name = self::get_table_name( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		}

		delete_option( 'pltt_version' );
		delete_option( 'pltt_db_version' );
		delete_option( 'pltt_task_types' ); // May already be gone from 1.6.0 migration.
	}
}

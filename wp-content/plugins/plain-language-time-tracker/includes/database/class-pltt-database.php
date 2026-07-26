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
	const DB_VERSION = '1.9.8';

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
	 * Current transaction nesting depth.
	 *
	 * @var int
	 */
	private static $tx_depth = 0;

	/**
	 * Whether any layer in the current transaction has asked to roll back.
	 *
	 * @var bool
	 */
	private static $tx_failed = false;

	/**
	 * Begin a transaction with nesting support.
	 *
	 * TRC-DB23: previously the entry layer probed `SELECT @@in_transaction` to
	 * decide whether it owned the transaction, but that variable only exists on
	 * MariaDB — on MySQL the probe errors and returns NULL, so every call thought
	 * it owned the transaction and issued a fresh START, implicitly committing any
	 * outer transaction and defeating the "no partial data" guarantee. MySQL has no
	 * true nested transactions, so we emulate them with a plugin-owned depth
	 * counter: only the outermost begin() issues a real START TRANSACTION.
	 */
	public static function begin_transaction() {
		global $wpdb;
		if ( 0 === self::$tx_depth ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'START TRANSACTION' );
			self::$tx_failed = false;
		}
		self::$tx_depth++;
	}

	/**
	 * Commit the current transaction level.
	 *
	 * Only the outermost level touches the server. If any inner level rolled back,
	 * the whole transaction is rolled back instead of committed.
	 */
	public static function commit_transaction() {
		global $wpdb;
		if ( self::$tx_depth > 0 ) {
			self::$tx_depth--;
		}
		if ( 0 === self::$tx_depth ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( self::$tx_failed ? 'ROLLBACK' : 'COMMIT' );
			self::$tx_failed = false;
		}
	}

	/**
	 * Roll back the current transaction.
	 *
	 * MySQL cannot roll back a single nested level, so an inner rollback only
	 * marks the transaction as doomed; the actual ROLLBACK fires when the
	 * outermost level closes (via commit_transaction() or this method at depth 0).
	 */
	public static function rollback_transaction() {
		global $wpdb;
		self::$tx_failed = true;
		if ( self::$tx_depth > 0 ) {
			self::$tx_depth--;
		}
		if ( 0 === self::$tx_depth ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			self::$tx_failed = false;
		}
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
			is_internal tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY name (name(191)),
			KEY is_internal (is_internal)
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
			billability_default tinyint(1) NOT NULL DEFAULT 1,
			recurring_period varchar(20) DEFAULT NULL,
			budget_hours decimal(8,2) DEFAULT NULL,
		budget_fee decimal(10,2) DEFAULT NULL,
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

		// Tags registry table.
		$table_tags = self::get_table_name( 'tags' );
		$sql_tags   = "CREATE TABLE {$table_tags} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL,
			group_name varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY name (name),
			KEY group_name (group_name)
		) {$charset_collate};";
		dbDelta( $sql_tags );

		// Entry-tag junction table.
		$table_entry_tags = self::get_table_name( 'entry_tags' );
		$sql_entry_tags   = "CREATE TABLE {$table_entry_tags} (
			entry_id bigint(20) unsigned NOT NULL,
			tag_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY (entry_id, tag_id),
			KEY tag_id (tag_id)
		) {$charset_collate};";
		dbDelta( $sql_entry_tags );

		// Tag-alias seeding table: deterministic keyword -> tag mapping that lets
		// the parser pre-fill tags. Lean by design (no confidence/learning yet);
		// use_count is just the prune signal. UNIQUE(keyword): one keyword maps
		// to one tag, repointed on re-seed.
		$table_tag_aliases = self::get_table_name( 'tag_aliases' );
		$sql_tag_aliases   = "CREATE TABLE {$table_tag_aliases} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword varchar(100) NOT NULL,
			tag_id bigint(20) unsigned NOT NULL,
			use_count int unsigned NOT NULL DEFAULT 0,
			last_used datetime,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY keyword (keyword),
			KEY tag_id (tag_id)
		) {$charset_collate};";
		dbDelta( $sql_tag_aliases );

		// Billing records: one durable per-scope summary written at commit time
		// (verify -> adjust -> commit). Entries carry no billing_record link — what
		// a record covered lives in billing_record_entries below. A fully-absorbed
		// record is just billed_amount = 0 (absorbed = calculated); there is no
		// status column. Hours stored as minutes to match the rest of the schema
		// (duration_minutes, etc.). marked_at is the date the invoice went out and
		// is settable, so a back-filled record lands in the month it was really
		// billed. How these rows are read back differs by billing type — see the
		// PLTT_Billing header.
		$table_billing = self::get_table_name( 'billing_records' );
		$sql_billing   = "CREATE TABLE {$table_billing} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			period_start date DEFAULT NULL,
			period_end date DEFAULT NULL,
			billing_type varchar(20) NOT NULL,
			rate decimal(10,2) DEFAULT NULL,
			calculated_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			billed_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			absorbed_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			billed_minutes int unsigned DEFAULT NULL,
			allocation_minutes int unsigned DEFAULT NULL,
			description text,
			marked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY project_id (project_id),
			KEY project_type (project_id, billing_type),
			KEY project_period (project_id, period_start)
		) {$charset_collate};";
		dbDelta( $sql_billing );

		// Frozen coverage snapshot: which entries a finalized billing record
		// captured. Written once at commit (hourly = the billed entries minus any
		// excluded; retainer = the period's overage entries). This is what makes
		// entry billing status immutable instead of recomputed live.
		$table_record_entries = self::get_table_name( 'billing_record_entries' );
		$sql_record_entries   = "CREATE TABLE {$table_record_entries} (
			record_id bigint(20) unsigned NOT NULL,
			entry_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (record_id, entry_id),
			KEY entry_id (entry_id)
		) {$charset_collate};";
		dbDelta( $sql_record_entries );
	}

	/**
	 * Check if database needs upgrade and run create_tables.
	 *
	 * Migrations run before create_tables() so dbDelta sees the post-migration
	 * schema. The version option is only bumped after both succeed, so a
	 * partial-failure run will retry on the next page load instead of being
	 * silently considered "current."
	 */
	public static function maybe_upgrade() {
		// One-time removal of legacy 1.9.5 migration-log artifacts (runs independently
		// of the version gate, since already-migrated installs are the ones that have
		// them). Cheap after the first run thanks to an autoloaded flag.
		self::maybe_purge_migration_1_9_5_log();

		$current_version = get_option( 'pltt_db_version', '0' );

		if ( version_compare( $current_version, self::DB_VERSION, '<' ) ) {
			// Fresh installs skip migrate() — there is nothing to migrate from.
			if ( '0' !== $current_version ) {
				if ( false === self::migrate( $current_version ) ) {
					// A sub-migration signalled failure. Leave the version where it is
					// so the next page load retries — bumping now would mark this
					// upgrade "done" and silently strand the data in a half-migrated state.
					return;
				}
			}
			self::create_tables();
			update_option( 'pltt_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Run migrations for specific version upgrades.
	 *
	 * Returns false if any sub-migration that opted into status reporting failed,
	 * true otherwise. Older sub-migrations that don't return a value implicitly
	 * succeed (they pre-date the failure-propagation contract).
	 *
	 * @param string $from_version Version upgrading from.
	 * @return bool
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

		// 1.8.1: Normalize tags from CSV column to junction tables.
		if ( version_compare( $from_version, '1.8.1', '<' ) ) {
			$entries_table    = self::get_table_name( 'time_entries' );
			$tags_table       = self::get_table_name( 'tags' );
			$entry_tags_table = self::get_table_name( 'entry_tags' );

			// Guard: only run if the tags column still exists.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$column_exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					DB_NAME,
					$entries_table,
					'tags'
				)
			);

			if ( $column_exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( 'START TRANSACTION' );

				// Collect all unique tag names from CSV column + pltt_custom_tags option.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$csv_rows = $wpdb->get_col( "SELECT DISTINCT tags FROM {$entries_table} WHERE tags != '' AND tags IS NOT NULL" );

				$all_tag_names = array();
				foreach ( $csv_rows as $csv ) {
					foreach ( explode( ',', $csv ) as $name ) {
						$name = strtolower( trim( $name ) );
						if ( '' !== $name ) {
							$all_tag_names[ $name ] = true;
						}
					}
				}

				$custom_tags = get_option( 'pltt_custom_tags', array() );
				foreach ( $custom_tags as $name ) {
					$name = strtolower( trim( $name ) );
					if ( '' !== $name ) {
						$all_tag_names[ $name ] = true;
					}
				}

				// Bulk insert all unique tag names.
				foreach ( array_keys( $all_tag_names ) as $name ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->query(
						$wpdb->prepare( "INSERT IGNORE INTO {$tags_table} (name) VALUES (%s)", $name )
					);
				}

				// Build name → id lookup map.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$tag_rows  = $wpdb->get_results( "SELECT id, name FROM {$tags_table}" );
				$tag_id_map = array();
				foreach ( $tag_rows as $row ) {
					$tag_id_map[ $row->name ] = (int) $row->id;
				}

				// Populate junction table from existing entries.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$entries = $wpdb->get_results( "SELECT id, tags FROM {$entries_table} WHERE tags != '' AND tags IS NOT NULL" );
				foreach ( $entries as $entry ) {
					foreach ( explode( ',', $entry->tags ) as $name ) {
						$name = strtolower( trim( $name ) );
						if ( '' !== $name && isset( $tag_id_map[ $name ] ) ) {
							$tag_id = $tag_id_map[ $name ];
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
							$wpdb->query(
								$wpdb->prepare(
									"INSERT IGNORE INTO {$entry_tags_table} (entry_id, tag_id) VALUES (%d, %d)",
									$entry->id,
									$tag_id
								)
							);
						}
					}
				}

				// Drop the old CSV column.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$entries_table} DROP COLUMN tags" );

				// Remove the custom tags option.
				delete_option( 'pltt_custom_tags' );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( 'COMMIT' );
			}
		}

		// 1.9.0: Add billability_default and recurring_period to projects.
		if ( version_compare( $from_version, '1.9.0', '<' ) ) {
			$projects_table = self::get_table_name( 'projects' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$projects_table} ADD COLUMN IF NOT EXISTS billability_default tinyint(1) NOT NULL DEFAULT 1" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$projects_table} ADD COLUMN IF NOT EXISTS recurring_period varchar(20) DEFAULT NULL" );
		}

		// 1.9.1: Add budget_hours to projects.
		if ( version_compare( $from_version, '1.9.1', '<' ) ) {
			$projects_table = self::get_table_name( 'projects' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$projects_table} ADD COLUMN IF NOT EXISTS budget_hours decimal(8,2) DEFAULT NULL" );
		}

		// 1.9.2: Add budget_fee to projects.
		if ( version_compare( $from_version, '1.9.2', '<' ) ) {
			$projects_table = self::get_table_name( 'projects' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$projects_table} ADD COLUMN IF NOT EXISTS budget_fee decimal(10,2) DEFAULT NULL" );
		}

		// 1.9.3: Add is_internal flag to clients so the internal client is identified
		// by a column rather than a hardcoded ID constant.
		if ( version_compare( $from_version, '1.9.3', '<' ) ) {
			$clients_table = self::get_table_name( 'clients' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$clients_table} ADD COLUMN IF NOT EXISTS is_internal tinyint(1) NOT NULL DEFAULT 0" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$clients_table} ADD INDEX IF NOT EXISTS is_internal (is_internal)" );

			// Mark the pre-existing internal client (seeded at ID 3 during initial setup).
			// After this migration, pltt_get_internal_client_id() uses the flag — not the ID.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "UPDATE {$clients_table} SET is_internal = 1 WHERE id = %d", 3 ) );
		}

		// 1.9.4: Add optional group_name to tags for grouped picker display.
		if ( version_compare( $from_version, '1.9.4', '<' ) ) {
			$tags_table = self::get_table_name( 'tags' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$tags_table} ADD COLUMN IF NOT EXISTS group_name varchar(100) DEFAULT NULL" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$tags_table} ADD INDEX IF NOT EXISTS group_name (group_name)" );
		}

		// 1.9.5: Billable model honesty — flip retainer / fixed-fee projects (and
		// their existing billable entries) to non-billable by default. The billable
		// flag now means "this entry generates invoiceable dollars from time × rate"
		// — work paid via flat fees no longer counts. See plan: lets-take-a-look-bright-grove.md.
		if ( version_compare( $from_version, '1.9.5', '<' ) ) {
			if ( false === self::migrate_to_1_9_5() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 1.9.5 migration: flip retainer / fixed-fee projects + entries to non-billable.
	 *
	 * Two distinct sets of rows are affected:
	 *   - Project defaults: every retainer / fixed-fee project with
	 *     billability_default = 1 gets flipped to 0.
	 *   - Entries: every billable=1 entry on ANY retainer / fixed-fee project
	 *     gets flipped to 0, regardless of that project's current default
	 *     (a project may have been switched to default=0 earlier, but its
	 *     legacy billable entries still need cleanup under the new model).
	 *
	 * Writes a plain-text review log to wp-content/uploads listing every
	 * flipped entry that was already invoiced (billed=1) so the user can
	 * manually re-flip true overage afterward.
	 *
	 * @return bool true on success (or no-op), false on hard failure —
	 *              caller MUST NOT bump the DB version when false is returned.
	 */
	private static function migrate_to_1_9_5() {
		global $wpdb;

		$entries_table  = self::get_table_name( 'time_entries' );
		$projects_table = self::get_table_name( 'projects' );
		$clients_table  = self::get_table_name( 'clients' );

		// All retainer / fixed-fee projects (regardless of current billability_default).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$retainer_fixed_projects = $wpdb->get_results(
			"SELECT p.id, p.name, p.recurring_period, p.budget_hours, p.budget_fee,
			        p.billability_default, c.name AS client_name
			 FROM {$projects_table} p
			 LEFT JOIN {$clients_table} c ON p.client_id = c.id
			 WHERE p.recurring_period IS NOT NULL
			    OR p.budget_hours IS NOT NULL
			    OR p.budget_fee IS NOT NULL
			 ORDER BY c.name ASC, p.name ASC"
		);

		if ( empty( $retainer_fixed_projects ) ) {
			return true; // Nothing on this install matches the new non-billable types.
		}

		$project_ids                  = array_map( static function( $p ) { return (int) $p->id; }, $retainer_fixed_projects );
		$default_flip_ids             = array_map(
			static function( $p ) { return (int) $p->id; },
			array_values( array_filter( $retainer_fixed_projects, static function( $p ) {
				return 1 === (int) $p->billability_default;
			} ) )
		);
		$placeholders_all             = implode( ',', array_fill( 0, count( $project_ids ), '%d' ) );

		// Collect entries about to be flipped — for the log.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		// Only need to know whether any billable entries exist on these projects —
		// the flip below re-selects by WHERE clause, so just probe for existence.
		$has_entries_to_flip = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$entries_table} e
			 WHERE e.billable = 1 AND e.project_id IN ({$placeholders_all})",
			$project_ids
		) );

		// True no-op: no defaults to flip AND no entries to flip. Skip the flip.
		if ( empty( $default_flip_ids ) && 0 === $has_entries_to_flip ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'START TRANSACTION' );

		// Flip project defaults (only the subset that still defaults to billable).
		$projects_updated = 0;
		if ( ! empty( $default_flip_ids ) ) {
			$placeholders_defaults = implode( ',', array_fill( 0, count( $default_flip_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$projects_updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$projects_table} SET billability_default = 0 WHERE id IN ({$placeholders_defaults})",
				$default_flip_ids
			) );
		}

		// Flip ALL existing billable entries on any retainer / fixed-fee project.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$entries_updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$entries_table} SET billable = 0 WHERE billable = 1 AND project_id IN ({$placeholders_all})",
			$project_ids
		) );

		if ( false === $projects_updated || false === $entries_updated ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'COMMIT' );

		return true;
	}

	/**
	 * One-time cleanup of the 1.9.5 migration review log (removed in 1.9.20).
	 *
	 * Earlier versions wrote a review log of the billable-model flip — first to a
	 * world-readable file in wp-content/uploads (a data-disclosure issue, SEC-M1),
	 * later to a private option. The log/notice/download feature was dropped; this
	 * sweep removes any lingering artifacts (both options + any public .txt files).
	 * Gated by an autoloaded flag so the uploads glob runs at most once per install.
	 */
	public static function maybe_purge_migration_1_9_5_log() {
		if ( get_option( 'pltt_migration_1_9_5_log_purged' ) ) {
			return;
		}
		self::purge_migration_1_9_5_log();
		update_option( 'pltt_migration_1_9_5_log_purged', 1 );
	}

	/**
	 * Delete the 1.9.5 migration log options and any legacy public log files.
	 */
	private static function purge_migration_1_9_5_log() {
		delete_option( 'pltt_migration_1_9_5_log' );
		delete_option( 'pltt_migration_1_9_5_log_url' );

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return;
		}

		$legacy_files = glob( trailingslashit( $upload_dir['basedir'] ) . 'pltt-migration-1.9.5-*.txt' );
		if ( empty( $legacy_files ) ) {
			return;
		}

		foreach ( $legacy_files as $legacy_file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $legacy_file );
		}
	}

	/**
	 * Drop all plugin tables (used during uninstall).
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			'entry_tags',
			'tags',
			'tag_aliases',
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
		delete_option( 'pltt_task_types' );          // May already be gone from 1.6.0 migration.
		delete_option( 'pltt_custom_tags' );         // May already be gone from 1.8.1 migration.
		self::purge_migration_1_9_5_log();           // Options + any legacy public log files.
		delete_option( 'pltt_migration_1_9_5_log_purged' );
	}
}

# Trace Audit — DATABASE Layer (2026-06-15)

Scope: `includes/database/` — `PLTT_Database`, `PLTT_Entries`, `PLTT_Clients`, `PLTT_Projects`, `PLTT_Tags`, `PLTT_Aliases`.
DB version under audit: **1.9.5** (confirmed `const DB_VERSION = '1.9.5'`).
Environment: LocalWP uses **MySQL 8.x** (`mysql-8.0.x` / `mysql-8.4.0` lightning-services present — no MariaDB).

## Schema reference (create_tables, current 1.9.5)

| table key | columns |
|---|---|
| `clients` | id, name, description, hourly_rate, **is_internal**, created_at, updated_at |
| `projects` | id, client_id, name, status, description, hourly_rate, **billability_default**, **recurring_period**, **budget_hours**, **budget_fee**, created_at, updated_at |
| `time_entries` | id, entry_date, start_time, end_time, duration_minutes, raw_text, description, client_id, project_id, verified, billable, billable_rate, billable_amount, billed, created_at, updated_at |
| `aliases` | id, alias_text, client_id, project_id, confidence, use_count, correct_count, last_used, created_at, updated_at |
| `daily_logs` | id, log_date, content, processed, created_at, updated_at |
| `tags` | id, name, **group_name**, created_at |
| `entry_tags` | entry_id, tag_id |

Dropped columns (`task_type`, `billing_model`, `fixed_fee`) — **none reappear** in any SQL. Good.

---

## Method-by-method

### PLTT_Database (class-pltt-database.php)

| ID | Method (line) | Signature | Return | SQL correctness | Verdict |
|---|---|---|---|---|---|
| TRC-DB1 | `get_table_name` (31) | `($table)` | string | prefix + `pltt_` + key | OK |
| TRC-DB2 | `create_tables` (39) | `()` | void | All 7 tables; columns match schema table above. `decimal`/`int`/`tinyint`/`date`/`time` types correct. | OK |
| TRC-DB3 | `maybe_upgrade` (183) | `()` | void | Migrations run before create_tables; version bumped only after both succeed; failure returns early without bump. Correct gating. | OK |
| TRC-DB4 | `migrate` (211, private) | `($from_version)` | bool | 1.6.0 drops dropped cols; 1.7.0 indexes; 1.8.1 tags normalization (prepared, guarded by information_schema check); 1.9.0–1.9.4 ADD COLUMN IF NOT EXISTS for billability_default/recurring_period/budget_hours/budget_fee/is_internal/group_name — **every migrated column is present in create_tables()**. | OK (see note DB-N1) |
| TRC-DB5 | `migrate_to_1_9_5` (421, private) | `()` | bool | Placeholder count == arg count for `IN (…)` lists; uses `%d` per id. Transaction + rollback on false. References only existing cols. | OK |
| TRC-DB6 | `write_migration_1_9_5_log` (518, private) | `($projects, $flip_ids, $entries)` | string (url) | No SQL. | OK |
| TRC-DB7 | `drop_tables` (626) | `()` | void | All via `get_table_name`; valid keys. | OK |

Note **DB-N1**: 1.9.x migrations add columns with `NOT NULL DEFAULT` or `DEFAULT NULL` — none change `NOT NULL → NULL`, so no explicit `ALTER … MODIFY` is required (the dbDelta limitation does not bite here). Migrations use raw `ALTER TABLE … ADD COLUMN IF NOT EXISTS`, which is correct MySQL-8 syntax and idempotent.

### PLTT_Entries (class-pltt-entries.php)

| ID | Method (line) | Signature | Return | SQL correctness | Verdict |
|---|---|---|---|---|---|
| TRC-DB8 | `get` (24) | `($id)` | object\|null | `SELECT *`, `%d`. | OK |
| TRC-DB9 | `get_all` (40) | `($args=array())` | object[] | orderby allowlisted; placeholders built from `$prepare` array, count matches; `build_filter_clauses` shared. | OK |
| TRC-DB10 | `get_by_date` (141) | `($date)` | object[] | delegates get_all. | OK |
| TRC-DB11 | `create` (157) | `($data)` | int\|false | Nullable client_id/project_id omitted from insert (correct — avoids %d→0). billable inherited from project default. **Transaction nesting via `@@in_transaction` — see TRC-DB23.** | ISSUE (TRC-DB23) |
| TRC-DB12 | `update` (246) | `($id,$data)` | bool | allowed_fields map; nullable client_id/project_id routed to `pltt_set_nullable_fields`; `%f` for billable_rate/amount. billable/billed/verified forced 0/1. **Transaction nesting — see TRC-DB23.** | ISSUE (TRC-DB23) |
| TRC-DB13 | `delete` (350) | `($id)` | bool | deletes tags first, then row, `%d`. | OK |
| TRC-DB14 | `delete_by_date` (373) | `($date)` | int\|false | DELETE JOIN on entry_tags + delete by date, `%s`. | OK |
| TRC-DB15 | `get_stats` (406) | `($args=array())` | object | Aggregates over existing cols; internal-client exclude via flag (SEC-L1) with name fallback. `billable_amount` falls back to live rate calc using p.hourly_rate/c.hourly_rate (exist). Placeholders from `$prepare`. | OK |
| TRC-DB16 | `get_stats_grouped_by` (485) | `($group_by,$args)` | array<int,object> | group_by allowlisted (`client_id`/`project_id`) — interpolated only after allowlist check (safe). ID-restriction placeholders count-matched. | OK |
| TRC-DB17 | `get_summary_by_project` (562) | `($date_from,$date_to,$args)` | object[] | GROUP BY matches selected non-aggregate cols; budget_hours/budget_fee/recurring_period/billability_default all exist. | OK |
| TRC-DB18 | `get_unbilled_outside_range_summary` (626) | `($date_from,$date_to,$args)` | object\|null | Fixed criteria; excludes archived + fixed-fee; placeholders count-matched; unsets billable/billed before shared filters. | OK |
| TRC-DB19 | `get_top_projects_for_period` (686) | `($date_from,$date_to,$args,$limit=2)` | object[] | LIMIT via `%d` appended to prepare; internal-client exclude via `%d`. | OK |
| TRC-DB20 | `get_chart_daily_totals` (745) | `($date_from,$date_to,$args)` | object[] | internal_clause interpolates `pltt_get_internal_client_id()` (int, safe) or name fallback. | OK |
| TRC-DB21 | `build_filter_clauses` (797, private) | `($args,$col_prefix,$entry_ref)` | array{where,prepare} | client/project/tag/billable/billed; `without_project`/`without_tag` sentinels; tag subqueries use junction tables; placeholder per condition. | OK |

### PLTT_Clients (class-pltt-clients.php)

| ID | Method (line) | Signature | Return | SQL correctness | Verdict |
|---|---|---|---|---|---|
| TRC-DB24 | `get` (24) | `($id)` | object\|null | `%d`. | OK |
| TRC-DB25 | `get_multiple` (40) | `($ids)` | id=>object map | absint+filter; placeholders count-matched. **Returns map (id-keyed)** — consistent with Projects::get_multiple. | OK |
| TRC-DB26 | `get_all` (68) | `($args)` | object[] | esc_like search; orderby allowlist; LIMIT prepared. | OK |
| TRC-DB27 | `create` (110) | `($data)` | int\|false\|WP_Error | hourly_rate `%f`, omitted when blank (uses default NULL); validates rate. **Note: signature doc says "(name, description)" but also accepts hourly_rate.** | OK (doc note DB-N2) |
| TRC-DB28 | `update` (158) | `($id,$data)` | bool\|WP_Error | hourly_rate NULL routed via `pltt_set_nullable_fields`; validates rate. | OK |
| TRC-DB29 | `delete` (228) | `($id)` | bool\|WP_Error | guards on project + entry counts; clears alias client_id refs to NULL via direct `UPDATE … = NULL` (raw query, not %d — correct). | OK |

Note **DB-N2**: `PLTT_Clients::create` does **not** handle `is_internal` — that column is only ever set by the 1.9.3 migration. Acceptable (no UI to create internal clients), just noting create() can never set it.

### PLTT_Projects (class-pltt-projects.php)

| ID | Method (line) | Signature | Return | SQL correctness | Verdict |
|---|---|---|---|---|---|
| TRC-DB30 | `get` (24) | `($id)` | object\|null | `%d`. | OK |
| TRC-DB31 | `get_multiple` (40) | `($ids)` | id=>object map | matches Clients::get_multiple shape. | OK |
| TRC-DB32 | `get_all` (68) | `($args)` | object[] | where/prepare count-matched; orderby allowlist; archived-last ordering. | OK |
| TRC-DB33 | `get_by_client` (138) | `($client_id,$active_only=true)` | object[] | delegates get_all. | OK |
| TRC-DB34 | `create` (158) | `($data)` | int\|false\|WP_Error | recurring_period validated against `array_filter(PLTT_ALLOWED_RECURRING_PERIODS)`; budget_hours/fee `%f` ≥0; nullable omitted when blank. No dropped cols. | OK |
| TRC-DB35 | `update` (240) | `($id,$data)` | bool\|WP_Error | status allowlist; recurring_period/budget_hours/budget_fee/hourly_rate NULL routed via `pltt_set_nullable_fields`; recurring_period re-validated. | OK |
| TRC-DB36 | `get_by_client_recent_first` (374) | `($client_id,$include_project_ids=array())` | object[] | placeholders for include-ids count-matched; prepare_args merged in correct order (client_id first, then ids — matches SQL order). | OK |
| TRC-DB37 | `get_for_clients` (414) | `($client_ids,$extra_ids_by_client=array())` | client_id=>project[] map | client placeholders + optional extra placeholders; prepare_args order matches (clients then extras). | OK |
| TRC-DB38 | `delete` (478) | `($id)` | bool\|WP_Error | guards on entry count; clears alias project_id refs to NULL. | OK |

### PLTT_Tags (class-pltt-tags.php)

| ID | Method (line) | Signature | Return | SQL correctness | Verdict |
|---|---|---|---|---|---|
| TRC-DB39 | `get_by_name` (24) | `($name)` | object\|null | lowercased, `%s`. | OK |
| TRC-DB40 | `get_all` (40) | `()` | object[] | no args, static SQL. | OK |
| TRC-DB41 | `get_all_with_counts` (53) | `()` | object[] (+usage_count) | LEFT JOIN + GROUP BY t.id. | OK |
| TRC-DB42 | `normalize_group_name` (74, private) | `($group_name)` | string\|null | clamps 100 chars. | OK |
| TRC-DB43 | `create` (92) | `($name,$group_name=null)` | int\|false | group_name omitted when null (uses default NULL); `%s`. | OK |
| TRC-DB44 | `rename` (131) | `($id,$new_name,$group_name=false)` | bool | tri-state group param; NULL clear deferred to raw `UPDATE … = NULL`. | OK |
| TRC-DB45 | `set_group` (186) | `($id,$group_name)` | bool | NULL via raw query, else wpdb->update `%s`. | OK |
| TRC-DB46 | `bulk_set_group` (219, **public**) | `(array $tag_ids,$group_name)` | int | placeholders count-matched; param order (group_name then ids) matches SQL. | OK |
| TRC-DB47 | `get_all_groups` (264) | `()` | string[] | DISTINCT non-empty. | OK |
| TRC-DB48 | `get_name_to_group_map` (283) | `()` | name=>group map | OK. | OK |
| TRC-DB49 | `delete` (307) | `($id)` | bool | junction rows first, then tag, `%d`. | OK |
| TRC-DB50 | `get_for_entry` (333) | `($entry_id)` | string[] | join, `%d`. | OK |
| TRC-DB51 | `get_for_entries` (356) | `($entry_ids)` | entry_id=>name[] map | placeholders count-matched. **Map omits entries with no tags** — callers (helpers.php:1255, reports, project-report, review) use `$map[$id] ?? array()` style isset checks; consistent. | OK |
| TRC-DB52 | `sync_entry_tags` (397) | `($entry_id,$tag_names)` | bool | delete junction → INSERT IGNORE tags → name→id map → INSERT IGNORE junction; returns false on any failure (per contract, callers ROLLBACK). | OK |
| TRC-DB53 | `delete_for_entry` (482) | `($entry_id)` | void | `%d`. | OK |

### PLTT_Aliases (class-pltt-aliases.php)

| ID | Method (line) | Signature | Return | SQL correctness | Verdict |
|---|---|---|---|---|---|
| TRC-DB54 | `get` (44) | `($id)` | object\|null | `%d`. | OK |
| TRC-DB55 | `get_by_text` (60) | `($alias_text)` | object\|null | `LOWER(alias_text)=LOWER(%s)`. | OK |
| TRC-DB56 | `get_all` (79) | `($args)` | object[] | client_id `%d`, min_confidence `%f`; orderby allowlist. | OK |
| TRC-DB57 | `create` (131) | `($data)` | int\|false | nullable client_id/project_id omitted; `%f` confidence; flushes alias cache. | OK |
| TRC-DB58 | `record_usage` (183) | `($id,$was_correct=true)` | void | atomic UPDATE (SEC-M9); placeholders: %d,%d,%s,%d == 4 args (delta,delta,time,id). confidence recompute uses correct_count/use_count (exist). | OK |
| TRC-DB59 | `find_in_text` (219, private) | `($text)` | object[] | no SQL (cache). | OK |
| TRC-DB60 | `get_best_client_match` (248) | `($text)` | object\|null | no SQL. | OK |
| TRC-DB61 | `extract_potential` (272) | `($text,$known_tags=null)` | string[] | no SQL beyond optional PLTT_Tags::get_all(). | OK |
| TRC-DB62–64 | `_extract_acronyms`/`_extract_capitalized_words`/`_filter_stopwords_and_tags` (private) | — | string[] | no SQL. | OK |

---

## FLAGGED

### TRC-DB23 — `@@in_transaction` is not a MySQL system variable; nested-transaction guard is non-functional on MySQL (the host DB)
Files: `class-pltt-entries.php:205` (`create`) and `:308` (`update`).

```php
$own_transaction = ! (bool) $wpdb->get_var( 'SELECT @@in_transaction' );
```

`@@in_transaction` exists in **MariaDB** but **not in MySQL 8.x**, which is what LocalWP runs (confirmed: only `mysql-8.0.x`/`mysql-8.4.0` lightning-services installed; no MariaDB). On MySQL, `SELECT @@in_transaction` raises `ERROR 1193 (Unknown system variable)`; `wpdb->get_var()` swallows the error and returns `NULL`, so `! (bool) NULL === true` — **`$own_transaction` is always `true`.**

Consequence: `PLTT_Entries::create()` / `update()` **always** issue their own `START TRANSACTION`. When a caller has already opened an outer transaction and then calls these methods, the inner `START TRANSACTION` **implicitly commits the outer transaction** (MySQL has no nested transactions), defeating the intended atomicity. Two real callers do exactly this:

- `class-pltt-ajax.php:149` (`save_entries`): outer `START TRANSACTION` → loop of `PLTT_Entries::create()`. Each `create()` opens+commits its own TX, so the outer `ROLLBACK` at line 184 on a mid-loop failure can no longer undo entries already committed by earlier iterations. The "no partial data" guarantee in the comment does not hold on MySQL.
- `class-pltt-ajax.php:244` (`update_entry_field`, billable branch): outer `START TRANSACTION` → `PLTT_Entries::update()`. The inner START commits the outer; the read-compute-write atomicity intended by SEC-M6/TRC-7 is broken.

Severity: medium. The intended nesting-safe design silently degrades to autocommit-per-call on the production DB engine. The comments and the SEC-M6/SEC-M9/TRC-7 hardening assume the guard works.

Fix options: (a) track transaction depth in a static/`$wpdb` flag the plugin controls instead of asking the server; (b) on MySQL use `SELECT @@autocommit` is not equivalent — better to maintain an explicit `private static $tx_depth` counter incremented by whoever opens a transaction; (c) standardize on `wpdb`-level savepoints. Any of these removes the dependency on a non-portable server variable.

---

## Summary counts
- Methods audited: **64** (incl. private helpers).
- OK: **63**
- Flagged: **1** (TRC-DB23 — affects 2 methods + 2 callers).
- No dropped-column reappearance; all table access via `get_table_name` with valid keys; all `prepare()` placeholder/arg counts matched; all NULL writes routed through `pltt_set_nullable_fields` or raw `= NULL` queries (no NULL-via-`%d`); migration columns all present in `create_tables()`.

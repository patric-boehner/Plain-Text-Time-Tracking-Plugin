# Call Traceability — Business Logic Layer → Database / Helpers

**Date:** 2026-06-15
**Layer audited:** `includes/admin/` (PLTT_Admin, Daily_Log, Log_Archive, Review, Reports, Project_Detail, Project_Report), `includes/parser/` (PLTT_Time_Parser), `includes/helpers.php`
**Target layer:** `includes/database/` (PLTT_Entries, PLTT_Clients, PLTT_Projects, PLTT_Tags, PLTT_Aliases, PLTT_Database) + `pltt_*` helpers

Each row traces a call FROM the business logic layer INTO the DB/helper layer. Callee signatures were opened and compared against the call sites. File paths are relative to the plugin root.

Legend for VERDICT: **OK** = signature + data contract + return handling all correct. **MISMATCH** = a flagged issue. **NOTE** = correct call, but an adjacent doc/contract observation worth recording.

---

## PLTT_Daily_Log

Daily_Log holds its own `$wpdb` queries (it IS effectively a thin DB class for the `daily_logs` table); the only outward calls are to `PLTT_Database::get_table_name()`.

| ID | Caller (file:line) | Callee (file:line) | Args | Expected signature | Return handling | Verdict |
|----|--------------------|--------------------|------|--------------------|-----------------|---------|
| TRC-BIZ1 | class-pltt-daily-log.php:36,53,97,117,145,192,224,240,259 | PLTT_Database::get_table_name( $name ) | single string table key ('daily_logs','time_entries') | `get_table_name(string)` | string used in interpolated SQL | OK |

Note: `save_log()` (line 51) has a 3rd param `$preserve_processed = false` not reflected in its docblock (`@param` lists only date + content). Cosmetic; all call sites pass valid args.

---

## PLTT_Log_Archive

| ID | Caller (file:line) | Callee (file:line) | Args | Expected signature | Return handling | Verdict |
|----|--------------------|--------------------|------|--------------------|-----------------|---------|
| TRC-BIZ2 | class-pltt-log-archive.php:61 | PLTT_Daily_Log::count_all( $args ) (class-pltt-daily-log.php:190) | `array('date_from'=>str,'date_to'=>str)` | `count_all( $args = array() )` | `(int)` assigned to `$total_logs`; used in ceil() | OK |
| TRC-BIZ3 | class-pltt-log-archive.php:64 | PLTT_Daily_Log::get_all( $args ) (class-pltt-daily-log.php:143) | merged array w/ date_from, date_to, limit, offset | `get_all( $args = array() )` | array → template loop | OK |
| TRC-BIZ4 | class-pltt-log-archive.php:75 | PLTT_Daily_Log::get_logged_months() (class-pltt-daily-log.php:222) | none | `get_logged_months()` | array of 'YYYY-MM' strings, foreach'd | OK |

`get_all`/`count_all` honor `date_from`+`date_to` (elseif branch) — Log_Archive only ever passes those, never `month`. Contract matches.

---

## PLTT_Review

### render() and helpers

| ID | Caller (file:line) | Callee (file:line) | Args | Expected signature | Return handling | Verdict |
|----|--------------------|--------------------|------|--------------------|-----------------|---------|
| TRC-BIZ5 | class-pltt-review.php:28,93,131 | PLTT_Clients::get_all() / ::get( $id ) (class-pltt-clients.php:68,24) | none / int id | `get_all($args=array())`, `get($id)` | array / object\|null; null-checked at 134 | OK |
| TRC-BIZ6 | class-pltt-review.php:46 | PLTT_Projects::get_for_clients( $client_ids, $extra_ids_by_client ) (class-pltt-projects.php:414) | `array_unique($unique_client_ids)` (int[]), `$extra_project_ids_by_client` (cid=>pid[] map) | `get_for_clients( $client_ids, $extra_ids_by_client = array() )` | client_id=>project[] map → template | OK |
| TRC-BIZ7 | class-pltt-review.php:52 | PLTT_Daily_Log::get_log( $date ) (class-pltt-daily-log.php:34) | string date | `get_log($date)` | object\|null → template | OK |
| TRC-BIZ8 | class-pltt-review.php:55 | PLTT_Tags::get_all() (class-pltt-tags.php:40) | none | `get_all()` | array → array_column('name') | OK |
| TRC-BIZ9 | class-pltt-review.php:75 | PLTT_Tags::get_for_entries( $entry_ids ) (class-pltt-tags.php:356) | `array((int)$entry->id)` (int[]) | `get_for_entries($entry_ids)` | entry_id=>name[] map; indexed by `(int)$entry->id` (line 76) | OK |
| TRC-BIZ10 | class-pltt-review.php:98 | PLTT_Projects::get_for_clients(...) (class-pltt-projects.php:414) | `array((int)$entry->client_id)`, `$extra` map | as TRC-BIZ6 | map → template | OK |
| TRC-BIZ11 | class-pltt-review.php:131,132 | PLTT_Clients::get / PLTT_Projects::get | int id | `get($id)` | object\|null, truthy-checked | OK |

### get_entries_for_date / format_entries_for_review

| ID | Caller (file:line) | Callee (file:line) | Args | Expected signature | Return handling | Verdict |
|----|--------------------|--------------------|------|--------------------|-----------------|---------|
| TRC-BIZ12 | class-pltt-review.php:177 | PLTT_Entries::get_all( $args ) (class-pltt-entries.php:40) | `array('date'=>str,'orderby'=>'start_time','order'=>'ASC')` | `get_all($args=array())` | array of objects; empty-checked at 185 | OK |
| TRC-BIZ13 | class-pltt-review.php:225 | PLTT_Tags::get_for_entries( $entry_ids ) (class-pltt-tags.php:356) | int[] | `get_for_entries($entry_ids)` | map; keyed `(int)$entry->id` at 274 | OK |
| TRC-BIZ14 | class-pltt-review.php:228 | PLTT_Projects::get_multiple( $ids ) (class-pltt-projects.php:40) | `array_unique($project_ids)` (int[]) | `get_multiple($ids)` → id=>object map | passed as `$projects_cache` to resolve helper | OK |
| TRC-BIZ15 | class-pltt-review.php:229 | PLTT_Clients::get_multiple( $ids ) (class-pltt-clients.php:40) | `array_unique($client_ids)` (int[]) | `get_multiple($ids)` → id=>object map | passed as `$clients_cache` | OK |
| TRC-BIZ16 | class-pltt-review.php:252 | pltt_resolve_billable_rate( $client_id, $project_id, $clients_cache, $projects_cache ) (helpers.php:1131) | `(int)$client_id, (int)$project_id, $clients_cache, $projects_cache` — correct order (client first) | `pltt_resolve_billable_rate( $client_id, $project_id, $clients_cache=array(), $projects_cache=array() )` | float → amount calc | OK — uses canonical helper, NOT a hand-rolled cascade. The MEMORY note about a stale cascade "at lines 139-161" is **resolved**; line 250-252 explicitly routes through the helper (comment cites OPT-DUP5/TRC-3). |
| TRC-BIZ17 | class-pltt-review.php:283 | pltt_compute_entry_warnings( $formatted ) (helpers.php:286) | array of formatted entries (each has 'id','start_time', etc.) | `pltt_compute_entry_warnings( array $entries )` | id=>warnings[] map; indexed `(int)$entry_ref['id']` | OK |

### save_entries — the write path (most contract-sensitive)

| ID | Caller (file:line) | Callee (file:line) | Args | Expected signature | Return handling | Verdict |
|----|--------------------|--------------------|------|--------------------|-----------------|---------|
| TRC-BIZ18 | class-pltt-review.php:320 | PLTT_Clients::get_multiple( $client_ids ) | absint-filtered int[] | id=>object map | cache for rate resolve | OK |
| TRC-BIZ19 | class-pltt-review.php:321 | PLTT_Projects::get_multiple( $project_ids ) | absint-filtered int[] | id=>object map | cache for rate resolve | OK |
| TRC-BIZ20 | class-pltt-review.php:324 | PLTT_Tags::get_all() | none | array | array_column('name') → `$all_tag_names` passed to alias learning | OK |
| TRC-BIZ21 | class-pltt-review.php:335 | PLTT_Entries::get( $entry_id ) (class-pltt-entries.php:24) | `absint` id | `get($id)` → object\|null | null-checked at 340 (SEC-H2 date guard) | OK |
| TRC-BIZ22 | class-pltt-review.php:409 → 468 | PLTT_Review::resolve_billable_rate( $data, $clients_cache, $projects_cache ) → pltt_resolve_billable_rate (helpers.php:1131) | `$data` (client_id/project_id may be **null**), caches | wrapper guards `! empty()` → coerces null to 0; passes `(int)$client_id,(int)$project_id` in correct order | float assigned to `billable_rate` | OK — null client_id/project_id safely become 0; helper casts. |
| TRC-BIZ23 | class-pltt-review.php:424 | PLTT_Entries::update( $entry_id, $data ) (class-pltt-entries.php:246) | int id, `$data` array (description, client_id\|null, project_id\|null, tags, billable 0/1, verified 1, optional date/time/duration, billable_rate %f, billable_amount %f) | `update($id, $data)` → **bool** (transaction-wrapped: update + nullable + tag sync) | `if ( false !== $result )` (line 426) — correct bool handling; increments saved/error | OK — billable forced to 0/1 at 366 (allowlist satisfied). client_id/project_id passed as null → update() routes them to `$null_fields` → `pltt_set_nullable_fields()` (no `%d` NULL write). |

**NULL-handling deep-check (TRC-BIZ23):** `$data['client_id']`/`['project_id']` are set to `null` when empty (lines 363-364). `PLTT_Entries::update()` treats both as nullable (line 271), and on empty pushes them to `$null_fields` (line 280) → `pltt_set_nullable_fields()` (line 329). No code path writes NULL through a `%d` format. **Correct.**

### learn_client_alias — alias write path

| ID | Caller (file:line) | Callee (file:line) | Args | Expected signature | Return handling | Verdict |
|----|--------------------|--------------------|------|--------------------|-----------------|---------|
| TRC-BIZ24 | class-pltt-review.php:431 | PLTT_Review::learn_client_alias( $original, $data, $all_tag_names ) | object, array, string[] | `learn_client_alias( $original, $saved, $known_tags = null )` | void | OK |
| TRC-BIZ25 | class-pltt-review.php:495 | PLTT_Aliases::get_best_client_match( $text ) (class-pltt-aliases.php:248) | string | `get_best_client_match($text)` → object\|null | null-checked at 497 | OK |
| TRC-BIZ26 | class-pltt-review.php:500 | PLTT_Aliases::record_usage( $alias_match->id, $was_correct ) (class-pltt-aliases.php:183) | int id, bool | `record_usage($id, $was_correct=true)` | void (atomic UPDATE) | OK |
| TRC-BIZ27 | class-pltt-review.php:506 | PLTT_Aliases::extract_potential( $text, $known_tags ) (class-pltt-aliases.php:272) | string, `$known_tags` (string[]\|null) | `extract_potential($text, $known_tags=null)` | string[] foreach'd | OK — OPT-L7 pre-loaded tag array passed to avoid N+1. |
| TRC-BIZ28 | class-pltt-review.php:509 | PLTT_Aliases::get_by_text( $potential ) (class-pltt-aliases.php:60) | string | `get_by_text($alias_text)` → object\|null | `if ( ! $existing )` | OK |
| TRC-BIZ29 | class-pltt-review.php:511 | PLTT_Aliases::create( $data ) (class-pltt-aliases.php:131) | `array('alias_text'=>str,'client_id'=>int)` | `create($data)` → int\|false | return ignored (fire-and-forget alias creation) | OK — non-critical learning write; ignoring the int\|false is acceptable. |

---

## PLTT_Reports

| ID | Caller (file:line) | Callee (file:line) | Args | Expected signature | Return handling | Verdict |
|----|--------------------|--------------------|------|--------------------|-----------------|---------|
| TRC-BIZ30 | class-pltt-reports.php:65,99 | PLTT_Clients::get / PLTT_Projects::get | int id | `get($id)` → object\|null | truthy-checked (67,100) | OK |
| TRC-BIZ31 | class-pltt-reports.php:72 | PLTT_Projects::get( (int)$project_id ) | int | object\|null | checked + `client_id` compared (73) | OK |
| TRC-BIZ32 | class-pltt-reports.php:79 | PLTT_Projects::get_by_client( $client_id, true ) (class-pltt-projects.php:138) | int client_id, bool active_only | `get_by_client($client_id, $active_only=true)` | array → `$context_projects` | OK |
| TRC-BIZ33 | class-pltt-reports.php:101 | pltt_get_billing_type( $alloc_project ) (helpers.php:1401) | project object | `pltt_get_billing_type($project)` → 'hourly'\|'recurring'\|'fixed'\|'none' | in_array check against ['recurring','fixed'] | OK |
| TRC-BIZ34 | class-pltt-reports.php:106 | pltt_compute_overage_threshold( $alloc_project, $filter_args ) (helpers.php:1605) | project object, filter array | `pltt_compute_overage_threshold($project, $filter_args)` → array with 'state' key | reads `$context_overage['state']` (107) — key always present | OK |
| TRC-BIZ35 | class-pltt-reports.php:123 | PLTT_Entries::get_unbilled_outside_range_summary( $date_from, $date_to, $filter_args ) (class-pltt-entries.php:626) | str, str, filter array | `(...$args=array())` → object\|null | null assignable; template null-checks | OK — billable/billed stripped inside callee (criteria fixed). |
| TRC-BIZ36 | class-pltt-reports.php:127,157 | PLTT_Entries::get_stats( $filter_args ) / ($prev_filter_args) (class-pltt-entries.php:406) | filter array | `get_stats($args=array())` → object | `$stats ? (int)$stats->total_count : 0` — null-guarded | OK |
| TRC-BIZ37 | class-pltt-reports.php:139 | pltt_get_previous_period( $date_from, $date_to ) (helpers.php:463) | str, str | `pltt_get_previous_period($date_from,$date_to)` → `array('from'=>,'to'=>)` | reads ['to'],['from'] | OK |
| TRC-BIZ38 | class-pltt-reports.php:171 | PLTT_Entries::get_top_projects_for_period( $date_from, $date_to, $filter_args, 2 ) (class-pltt-entries.php:686) | str, str, array, int limit | `get_top_projects_for_period($date_from,$date_to,$args=array(),$limit=2)` | array → template; gated on `$total_entries>0` | OK |
| TRC-BIZ39 | class-pltt-reports.php:191 | PLTT_Entries::get_summary_by_project( $date_from, $date_to, $filter_args ) (class-pltt-entries.php:562) | str, str, array | `get_summary_by_project($date_from,$date_to,$args=array())` | array of project rows | OK |
| TRC-BIZ40 | class-pltt-reports.php:195 | pltt_build_period_chart_data( $date_from, $date_to, $filter_args ) (helpers.php:656) | str, str, array | `pltt_build_period_chart_data($date_from,$date_to,$filter_args=array())` → array(buckets,bucket_size,max_minutes,avg_minutes,today_key) | all 5 keys read (196-200) | OK |
| TRC-BIZ41 | class-pltt-reports.php:233,240 | PLTT_Entries::get_stats_grouped_by( 'project_id', $args ) (class-pltt-entries.php:485) | 'project_id', `array('project_ids'=>int[], date_from?, date_to?)` | `get_stats_grouped_by($group_by, $args=array())` → id=>object map | `$alloc_stats += (...)` merges maps — keys are project_ids, no collision risk | OK — `project_ids` arg name matches callee's `$args[$group_by.'s']` lookup (line 510). |
| TRC-BIZ42 | class-pltt-reports.php:251 | PLTT_Entries::get_all( merged filter+orderby+limit+offset ) | array | `get_all($args=array())` | array of objects | OK |
| TRC-BIZ43 | class-pltt-reports.php:266 | PLTT_Tags::get_for_entries( $entry_ids ) | int[] (`(int)$e->id`) | map | attached as CSV; keyed `(int)$entry->id` | OK |

---

## PLTT_Project_Detail

| ID | Caller (file:line) | Callee (file:line) | Args | Expected signature | Return handling | Verdict |
|----|--------------------|--------------------|------|--------------------|-----------------|---------|
| TRC-BIZ44 | class-pltt-project-detail.php:36 | PLTT_Projects::get( $project_id ) | absint | object\|null | null → render_not_found (38) | OK |
| TRC-BIZ45 | class-pltt-project-detail.php:43,67 | PLTT_Entries::get_stats( array('project_id'=>...) ) | filter array (project_id, optional date_from/to) | object | `$stats->first_entry_date ?? ''` null-coalesced | OK |
| TRC-BIZ46 | class-pltt-project-detail.php:44 | pltt_get_billing_type( $project ) | object | string | used in window resolve + subhead | OK |
| TRC-BIZ47 | class-pltt-project-detail.php:54 | pltt_resolve_project_chart_window( $billing_type, $recurring_period, $first, $last, $req_scope, $req_anchor ) (helpers.php:856) | 6 positional: string, string, string, string, string, string | `pltt_resolve_project_chart_window($billing_type,$recurring_period,$first_date,$last_date,$req_scope='',$req_anchor='')` | array window; reads ['scope'],['from'],['to'],['period_label'] | OK — positional order matches exactly; `$stats->first_entry_date ?? ''` guards null. |
| TRC-BIZ48 | class-pltt-project-detail.php:71 | PLTT_Project_Report::build( $project_id, $project, null, $stats, $window ) (class-pltt-project-report.php:55) | int, object, null(client), object stats, array window | `build($project_id,$project,$client=null,$stats=null,$window=null)` | array → template | OK — 3rd arg `null` for unused `$client` is intentional (param reserved). |

---

## PLTT_Project_Report

| ID | Caller (file:line) | Callee (file:line) | Args | Expected signature | Return handling | Verdict |
|----|--------------------|--------------------|------|--------------------|-----------------|---------|
| TRC-BIZ49 | class-pltt-project-report.php:57 | PLTT_Entries::get_stats( array('project_id'=>...) ) | filter array | object | only reached when `$stats===null` (caller passes it) | OK |
| TRC-BIZ50 | class-pltt-project-report.php:62 | PLTT_Entries::get_all( array('project_id'=>,'orderby'=>'entry_date','order'=>'ASC') ) | array | array of objects | foreach for entry_ids + groupings | OK |
| TRC-BIZ51 | class-pltt-project-report.php:74 | PLTT_Tags::get_for_entries( $entry_ids ) | int[] | map | indexed `(int)$entry->id` in build_one_grouping (535) | OK |
| TRC-BIZ52 | class-pltt-project-report.php:75 | PLTT_Tags::get_name_to_group_map() (class-pltt-tags.php:283) | none | tag_name=>group_name map | read by name in build_one_grouping | OK |
| TRC-BIZ53 | class-pltt-project-report.php:77 | pltt_resolve_billable_rate( (int)$project->client_id, (int)$project_id ) (helpers.php:1131) | int client_id, int project_id, **no caches** | `(...,$clients_cache=array(),$projects_cache=array())` | float `$rate` | OK — omitting caches means the helper falls back to DB (`PLTT_Projects::get`/`PLTT_Clients::get`), which is the documented default-empty-array behavior. Single project view → not an N+1. |
| TRC-BIZ54 | class-pltt-project-report.php:93 | PLTT_Entries::get_stats( project_id + window from/to ) | filter array | object | `$card_stats` → cards | OK |
| TRC-BIZ55 | class-pltt-project-report.php:117 | pltt_build_period_chart_data( $chart_from, $chart_to, array('project_id'=>(int)$project_id) ) (helpers.php:656) | str, str, filter array | `(...$filter_args=array())` | array\|null (gated on dates) | OK |
| TRC-BIZ56 | class-pltt-project-report.php:162,820 | pltt_get_billing_type( $project ) | object | string | switch / `'fixed' !==` compare | OK |
| TRC-BIZ57 | class-pltt-project-report.php:486 | PLTT_Tags::get_all_groups() (class-pltt-tags.php:264) | none | string[] | candidate_keys for groupings | OK |

---

## PLTT_Time_Parser → Database / Helpers

The parser is pure transformation; it touches the DB only through `PLTT_Aliases` for predictions and `pltt_*` text helpers. It does **not** call `PLTT_Entries::create()` directly — entry creation happens in `PLTT_Form_Handlers`/`PLTT_Ajax` (API layer) consuming the parser's array output.

| ID | Caller (file:line) | Callee (file:line) | Args | Expected signature | Return handling | Verdict |
|----|--------------------|--------------------|------|--------------------|-----------------|---------|
| TRC-BIZ58 | class-pltt-time-parser.php:86,104 | pltt_extract_tags( $matches[3] / $description ) (helpers.php:220) | string | `pltt_extract_tags($text)` → string[] | `implode(',', ...)` | OK |
| TRC-BIZ59 | class-pltt-time-parser.php:187 | pltt_remove_tags( $description ) (helpers.php:238) | string | `pltt_remove_tags($text)` → string | trimmed/normalized | OK |
| TRC-BIZ60 | class-pltt-time-parser.php:212-213,226-227 | pltt_time_to_minutes( $start/$end/$next ) (helpers.php:81) | string time | `pltt_time_to_minutes($time)` → int\|false | `false !== $x` checks before subtraction (215,229) | OK — false return correctly guarded; would otherwise corrupt duration. |
| TRC-BIZ61 | class-pltt-time-parser.php:274 | PLTT_Aliases::get_best_client_match( $text ) (class-pltt-aliases.php:248) | string | object\|null | `if ($alias_match && ! empty($alias_match->client_id))` (276) — reads `->client_id`, `->confidence` | OK — object property access guarded. |

**Parser output → create() contract (cross-layer):** `parse_log()` emits entries with keys `start_time`, `end_time` (may be `null`), `raw_text`, `description`, `tags` (CSV), `duration_minutes` (may be `null` for last open entry), `entry_date`, `predicted_client_id`. `PLTT_Entries::create()` (class-pltt-entries.php:157) reads these via `?? ''` / `absint(... ?? 0)` defaults, so a `null` `end_time`/`duration_minutes` degrades to `''`/`0` — no NULL-via-`%d` write. The parser's `predicted_client_id` key is NOT consumed by `create()` (which expects `client_id`); the API layer maps it. Within this layer's scope: **OK**.

---

## Findings Summary

### Genuine flags

- **TRC-BIZ-DOC1 (NOTE / doc bug, not a runtime break)** — `pltt_resolve_billable_rate()` docblock at **helpers.php:1118-1120** states: *"If a cache is provided but the ID is not found in it, the DB is NOT queried as a fallback."* The actual code at **helpers.php:1137 & 1145** DOES fall back to `PLTT_Projects::get()` / `PLTT_Clients::get()` on a cache miss (`isset($cache[$id]) ? ... : PLTT_X::get($id)`). MEMORY.md's description ("on cache MISS it falls back to DB regardless") matches the **code**, not the docblock. All call sites in this layer either pass complete caches (Review) or pass none (Project_Report), so no caller is misled in practice — but the inline doc is wrong and should be corrected to avoid a future caller assuming a partial cache suppresses DB hits. **Severity: low (documentation only).**

### Verified-correct contracts (no flag)

- `PLTT_Tags::sync_entry_tags()` bool-on-failure contract is respected at its callers inside `PLTT_Entries::create()/update()` (ROLLBACK on false) — those are DB-layer internal, but the business-layer write path (TRC-BIZ23) correctly treats `update()`'s bool return.
- `PLTT_Entries::create()` self-opening transaction and `update()` single-transaction wrapping are honored; Review's save loop calls `update()` once per row and reads its bool.
- The previously-noted hand-rolled rate cascade in `format_entries_for_review` is **resolved** — it now delegates to `pltt_resolve_billable_rate()` (TRC-BIZ16).
- All bulk loaders (`get_multiple`, `get_for_entries`, `get_for_clients`, `get_stats_grouped_by`) return id-keyed maps and every caller keys them by the matching id — no mis-keying found.
- `pltt_validate_hourly_rate()` (true\|WP_Error) is not called from this layer; it lives in the DB create/update methods, which `is_wp_error()`-check it correctly (out of scope but spot-confirmed).
- No NULL written through a `%d` format anywhere in the traced edges; nullable fields consistently routed through `pltt_set_nullable_fields()`.

**Edges checked: 61. OK: 60. NOTE/flagged: 1 (doc-only).**

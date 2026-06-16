# API/Handler Layer Call-Traceability Audit — 2026-06-15

Layer: `includes/api/` — `PLTT_Ajax` (wp_ajax_*) and `PLTT_Form_Handlers` (admin_post_*).

Files:
- `includes/api/class-pltt-ajax.php`
- `includes/api/class-pltt-form-handlers.php`

REST routes: **none** (`grep register_rest_route` → 0 hits).

## Registration / method existence

### PLTT_Ajax::init() (class-pltt-ajax.php:21-43)

| Hook | Method | Method exists |
|---|---|---|
| wp_ajax_pltt_save_daily_log | save_daily_log | :67 OK |
| wp_ajax_pltt_update_daily_log | update_daily_log | :92 OK |
| wp_ajax_pltt_process_log | process_log | :117 OK |
| wp_ajax_pltt_delete_daily_log | delete_daily_log | :635 OK |
| wp_ajax_pltt_delete_entry | delete_entry | :296 OK |
| wp_ajax_pltt_update_entry_field | update_entry_field | :203 OK |
| wp_ajax_pltt_save_entry | save_entry | :515 OK |
| wp_ajax_pltt_create_client | create_client | :320 OK |
| wp_ajax_pltt_get_projects | get_projects | :367 OK |
| wp_ajax_pltt_create_project | create_project | :388 OK |
| wp_ajax_pltt_create_tag | create_tag | :474 OK |

All 11 AJAX hooks map to existing methods. All are `wp_ajax_` only (no `wp_ajax_nopriv_` — correct, admin-only). Every handler opens with `if ( ! self::verify_request() ) { return; }` (nonce + `pltt_user_can_access()`). OK.

### PLTT_Form_Handlers::init() (class-pltt-form-handlers.php:23-41)

| Hook | Method | Method exists |
|---|---|---|
| admin_post_pltt_update_client | handle_update_client | :72 OK |
| admin_post_pltt_delete_client | handle_delete_client | :122 OK |
| admin_post_pltt_update_project | handle_update_project | :149 OK |
| admin_post_pltt_delete_project | handle_delete_project | :244 OK |
| admin_post_pltt_save_entries | handle_save_entries | :426 OK |
| admin_post_pltt_create_tag | handle_create_tag | :271 OK |
| admin_post_pltt_rename_tag | handle_rename_tag | :314 OK |
| admin_post_pltt_delete_tag | handle_delete_tag | :363 OK |
| admin_post_pltt_bulk_assign_group | handle_bulk_assign_group | :391 OK |

All 9 form hooks map to existing methods. Each starts with `pltt_user_can_access()` gate + `verify_nonce(action)`. OK.

**Total handlers: 20 (11 AJAX + 9 form).**

---

## Inputs per handler (key → sanitizer → type → req/opt)

### save_daily_log (:67)
- `date` → `pltt_sanitize_date(wp_unslash)` → string — required (empty → error)
- `content` → `sanitize_textarea_field(wp_unslash)` → string — optional

### update_daily_log (:92)
- `date` → `pltt_sanitize_date` → string — required
- `content` → `sanitize_textarea_field` → string — optional

### process_log (:117)
- `date` → `pltt_sanitize_date_strict` → string — required
- `content` → `sanitize_textarea_field` → string — optional

### update_entry_field (:203)
- `entry_id` → `absint` → int — required (empty → error)
- `field` → `sanitize_key` → string, allowlist {billable,billed,tags} — required
- `value` → `sanitize_text_field` → string — required-by-use

### delete_entry (:296)
- `entry_id` → `absint` → int — required

### create_client (:320)
- `name` → `sanitize_text_field` → string — required
- `description` → `sanitize_textarea_field` → string — optional
- `hourly_rate` → `wp_unslash` then `floatval` → float — optional (validated)

### get_projects (:367)
- `client_id` → `absint` → int — required-by-use (0 allowed, returns empty)
- `current_project_id` → `absint` → int — optional

### create_project (:388)
- `client_id` → `absint` → int — required
- `name` → `sanitize_text_field` → string — required
- `description` → `sanitize_textarea_field` → string — optional
- `hourly_rate` → `floatval` → float — optional (validated)
- `recurring_period` → `sanitize_text_field` + allowlist → string — optional
- `budget_hours` → `floatval`, `>=0` → float — optional
- `budget_fee` → `floatval`, `>=0` → float — optional
- `non_billable` → `'1' ===` → derives `billability_default` int — optional

### create_tag (:474)
- `tag_name` → `sanitize_text_field` + lower/trim + len<=100 → string — required

### save_entry (:515)
- `entry_id` → `absint` → int — required (>0)
- `entry_date` → `pltt_sanitize_date` → string — required
- `start_time` → `sanitize_text_field` + regex → string — required
- `end_time` → `sanitize_text_field` + regex → string — optional
- `duration_minutes` → `absint` → int — recomputed server-side when end_time present
- `description` → `sanitize_textarea_field` → string — optional
- `client_id` → `absint` (0 if blank) → int — optional
- `project_id` → `absint` (0 if blank) → int — optional
- `tags` → `sanitize_text_field` + per-tag len<=100 → string CSV — optional
- `billable` → `'1' ===` → int 0/1 — optional

### delete_daily_log (:635)
- `log_date` → `pltt_sanitize_date_strict` → string — required

### handle_update_client (:72)
- `client_id` → `absint` → int — required
- `name` → `sanitize_text_field` → string — **not emptiness-checked at handler** (see TRC-API1)
- `description` → `sanitize_textarea_field` → string — optional
- `hourly_rate` → `''` passthrough or `floatval`+validate → float|'' — optional

### handle_delete_client (:122)
- `client_id` → `absint` → int — required

### handle_update_project (:149)
- `project_id` → `absint` → int — required
- `name`, `status` (allowlist), `description`, `hourly_rate`, `recurring_period` (allowlist), `budget_hours`, `budget_fee`, `non_billable`, `client_id` — all optional / presence-gated

### handle_delete_project (:244)
- `project_id` → `absint` → int — required

### handle_save_entries (:426)
- `date` → `pltt_sanitize_date_strict` → string — required
- `entries` → `wp_unslash` + `json_decode`/array → array — required, capped at 200
- `return_to` → `esc_url_raw` + `wp_validate_redirect` → string — optional

### handle_create_tag (:271)
- `tag_name` → `sanitize_text_field`+lower/trim+len<=100 → string — required
- `group_name` → `sanitize_text_field` → string — optional

### handle_rename_tag (:314)
- `tag_id` → `absint` → int — required
- `tag_name` → `sanitize_text_field`+lower/trim+len<=100 → string — required
- `group_name` → `array_key_exists` ? `sanitize_text_field` : `false` → string|false — optional

### handle_delete_tag (:363)
- `tag_id` → `absint` → int — required

### handle_bulk_assign_group (:391)
- `tag_ids` → `is_array` ? `array_map(absint)` + `array_filter` → int[] — required
- `group_name` → `sanitize_text_field` → string — optional

---

## Downstream edge table (handler → target, args, signature, verdict)

| ID | Handler (file:line) | Downstream target (file:line) | Args passed | Expected signature | Verdict |
|---|---|---|---|---|---|
| TRC-API1 | handle_update_client :110 | PLTT_Clients::update (clients:158) | `$client_id`(int), `$update_data`(array; may carry `hourly_rate=''`) | `update($id, $data)` → `bool\|WP_Error` | **MISMATCH (return contract).** `update()` can return `WP_Error` (missing_name). Handler does `if ( $result )` — WP_Error is truthy → false "client_updated" success on a failed update. Handler also never emptiness-checks `name`, so missing_name is reachable. Sibling handlers (delete_client/update_project/delete_project) all do `is_wp_error()`; this one doesn't. |
| TRC-API2 | create_client :349 | PLTT_Clients::create (clients:110) | `$client_data`(array) | `create($data)` → `int\|false\|WP_Error` | **MISMATCH (return contract, latent).** `create()` returns `WP_Error` on missing_name. Handler does `if ( $client_id )` — WP_Error truthy → calls `PLTT_Clients::get( $client_id )` with a WP_Error object and returns success. Handler pre-validates name (:329) and rate (:341), so unreachable in practice, but the success branch doesn't guard `is_wp_error()`. |
| TRC-API3 | create_project :456 | PLTT_Projects::create (projects:158) | `$project_data`(array) | `create($data)` → `int\|false\|WP_Error` | **MISMATCH (return contract, latent).** Returns `WP_Error` (missing_client/missing_name/invalid rate). Handler `if ( $project_id )` treats WP_Error as success → `PLTT_Projects::get(WP_Error)`. Pre-validated at boundary (:403,:408,:414,:428) so unreachable, but no `is_wp_error()` guard. |
| TRC-API4 | save_daily_log :80 | PLTT_Daily_Log::save_log (daily-log:51) | `$date`(string), `$content`(string) | `save_log($date,$content,$preserve_processed=false)` → bool | OK |
| TRC-API5 | update_daily_log :105 | PLTT_Daily_Log::save_log (daily-log:51) | `$date`, `$content`, `true` | `save_log($date,$content,$preserve_processed)` | OK (3rd arg bool) |
| TRC-API6 | process_log :133 | PLTT_Daily_Log::save_log (daily-log:51) | `$date`, `$content` | OK | OK |
| TRC-API7 | process_log :136 | PLTT_Time_Parser::parse_log (parser:25) | `$content`(string), `$date`(string) | `parse_log($text,$date)` → array | OK |
| TRC-API8 | process_log :144 | PLTT_Time_Parser::validate (parser:291) | `$entries`(array) | `validate($entries)` → array | OK |
| TRC-API9 | process_log :151 | PLTT_Entries::delete_by_date (entries:373) | `$date`(string) | `delete_by_date($date)` → int\|false | OK (return not used) |
| TRC-API10 | process_log :157 | PLTT_Entries::create (entries:157) | assoc array (entry_date,start_time,end_time,duration_minutes,raw_text,description,client_id,tags) | `create($data)` → int\|false | OK — checks `false === $result` (:170). |
| TRC-API11 | process_log :182 | PLTT_Daily_Log::mark_processed (daily-log:115) | `$date`(string) | `mark_processed($date)` → bool | OK |
| TRC-API12 | update_entry_field :225 | PLTT_Tags::sync_entry_tags (tags:397) | `$entry_id`(int), `$tag_names`(array from explode) | `sync_entry_tags($entry_id,$tag_names)` → bool (accepts array OR csv) | OK — checks `false === $result` (:226). |
| TRC-API13 | update_entry_field :250 | PLTT_Entries::get (entries:24) | `$entry_id`(int) | `get($id)` → object\|null | OK — null-guarded (:251). |
| TRC-API14 | update_entry_field :252 | pltt_resolve_billable_rate (helpers:1131) | `(int)$entry->client_id`, `(int)$entry->project_id` | `($client_id,$project_id,$cc=[],$pc=[])` → float (self-casts to int) | OK |
| TRC-API15 | update_entry_field :262 / :283 | PLTT_Entries::update (entries:246) | `$entry_id`(int), `$update_data`/`['billed'=>int]` | `update($id,$data)` → bool | OK — `if ( ! $result )` (:265,:284). |
| TRC-API16 | delete_entry :308 | PLTT_Entries::delete (entries:350) | `$entry_id`(int) | `delete($id)` → bool | OK |
| TRC-API17 | create_client :341 | pltt_validate_hourly_rate (helpers:1105) | `$rate_float`(float) | `($rate)` → true\|WP_Error | OK — `is_wp_error()` guarded (:342). |
| TRC-API18 | create_client :352 | PLTT_Clients::get (clients:24) | `$client_id`(int) | `get($id)` → object\|null | OK (reached only on truthy id; see TRC-API2 caveat). |
| TRC-API19 | get_projects :376 | PLTT_Projects::get_by_client_recent_first (projects:374) | `$client_id`(int), `$include_ids`(int[]) | `get_by_client_recent_first($client_id,$include_project_ids=[])` → array | OK |
| TRC-API20 | create_project :428 | pltt_validate_hourly_rate (helpers:1105) | `$rate_float`(float) | OK | OK — guarded. |
| TRC-API21 | create_project :459 | PLTT_Projects::get (projects:24) | `$project_id`(int) | `get($id)` → object\|null | OK (see TRC-API3 caveat). |
| TRC-API22 | create_tag :495 | PLTT_Tags::get_by_name (tags:24) | `$tag_name`(string) | `get_by_name($name)` → object\|null | OK |
| TRC-API23 | create_tag :500 | PLTT_Tags::create (tags:92) | `$tag_name`(string) | `create($name,$group_name=null)` → int\|false | OK — `if ( $result )`; create returns int\|false (no WP_Error). |
| TRC-API24 | save_entry :567/:568 | pltt_time_to_minutes (helpers:81) | `$start_time`/`$end_time`(string) | `($time)` → int\|false | OK — `false ===` guarded (:569). |
| TRC-API25 | save_entry :597 | pltt_resolve_billable_rate (helpers:1131) | `$client_id`(int,0 ok), `$project_id`(int,0 ok) | float | OK |
| TRC-API26 | save_entry :605/:618 | PLTT_Entries::get (entries:24) | `$entry_id`(int) | object\|null | OK — null-guarded (:606). |
| TRC-API27 | save_entry :611 | PLTT_Entries::update (entries:246) | `$entry_id`(int), `$data`(assoc, client_id/project_id may be null) | `update($id,$data)` → bool | OK — `if ( ! $result )` (:612). |
| TRC-API28 | save_entry :620 | PLTT_Review::render_entry_row (review:71) | `$saved_entry`(object) | `render_entry_row($entry)` → void (echoes) | OK — wrapped in ob_start/ob_get_clean. |
| TRC-API29 | delete_daily_log :649 | PLTT_Entries::delete_by_date (entries:373) | `$log_date`(string) | int\|false | OK — `is_numeric()` guarded (:658). |
| TRC-API30 | delete_daily_log :652 | PLTT_Daily_Log::delete_log (daily-log:95) | `$log_date`(string) | `delete_log($date)` → bool | OK |
| TRC-API31 | handle_update_client :102 | pltt_validate_hourly_rate (helpers:1105) | `$rate_float`(float) | true\|WP_Error | OK — guarded. |
| TRC-API32 | handle_delete_client :134 | PLTT_Clients::delete (clients:228) | `$client_id`(int) | `delete($id)` → bool\|WP_Error | OK — `is_wp_error()` guarded (:136). |
| TRC-API33 | handle_update_project :187 | pltt_validate_hourly_rate (helpers:1105) | `$rate_float`(float) | true\|WP_Error | OK — guarded. |
| TRC-API34 | handle_update_project :229 | PLTT_Projects::update (projects:240) | `$project_id`(int), `$data`(assoc; hourly_rate/budget_* may be `''`) | `update($id,$data)` → bool\|WP_Error | OK — `is_wp_error()` guarded (:231). |
| TRC-API35 | handle_delete_project :256 | PLTT_Projects::delete (projects:478) | `$project_id`(int) | `delete($id)` → bool\|WP_Error | OK — `is_wp_error()` guarded (:258). |
| TRC-API36 | handle_save_entries :459 | PLTT_Review::save_entries (review:306) | `$date`(string), `$entries`(array) | `save_entries($date,$entries)` → array{success,...} | OK — reads `$result['success']`; save_entries returns array with `success` key (review:438). |
| TRC-API37 | handle_create_tag :296 | PLTT_Tags::get_by_name (tags:24) | `$tag_name`(string) | object\|null | OK |
| TRC-API38 | handle_create_tag :301 | PLTT_Tags::create (tags:92) | `$tag_name`(string), `$group_name`(string) | `create($name,$group_name=null)` → int\|false | OK |
| TRC-API39 | handle_rename_tag :344 | PLTT_Tags::get_by_name (tags:24) | `$new_tag`(string) | object\|null | OK — `(int)$existing->id` compared. |
| TRC-API40 | handle_rename_tag :350 | PLTT_Tags::rename (tags:131) | `$tag_id`(int), `$new_tag`(string), `$group_arg`(string\|false) | `rename($id,$new_name,$group_name=false)` → bool | OK — `false` sentinel for "leave unchanged" passed correctly. |
| TRC-API41 | handle_delete_tag :378 | PLTT_Tags::delete (tags:307) | `$tag_id`(int) | `delete($id)` → bool | OK |
| TRC-API42 | handle_bulk_assign_group :412 | PLTT_Tags::bulk_set_group (tags:219) | `$tag_ids`(int[]), `$group_name`(string) | `bulk_set_group(array $tag_ids,$group_name)` → bool | OK — `false === $result` guarded (:414). Type-hint `array` satisfied (array_filter always returns array). |

---

## Findings summary

- **Handlers audited:** 20 (11 AJAX, 9 form). All registered + methods exist.
- **Downstream edges checked:** 42.
- **OK:** 39. **Flagged:** 3 (all return-contract / WP_Error handling).

### FLAGGED

- **TRC-API1 — handle_update_client treats `WP_Error` from `Clients::update` as success.** `if ( $result )` (form-handlers:110-116) — a `WP_Error` is truthy, so a failed update (e.g. empty `name` → `missing_name` WP_Error at clients:123/171) redirects with the `client_updated` *success* message. Handler also lacks an emptiness check on `name`, so this path is **reachable**. Inconsistent with delete_client / update_project / delete_project, which all `is_wp_error()`. **Fix:** add `if ( is_wp_error( $result ) ) { redirect_back([pltt_error => $result->get_error_code()]); }` before the truthy check.

- **TRC-API2 — create_client doesn't guard `WP_Error` from `Clients::create`.** `if ( $client_id )` (ajax:351) treats WP_Error as success and passes it into `PLTT_Clients::get()`. Latent only (name + rate pre-validated at :329/:341), but should `is_wp_error()`-guard for robustness.

- **TRC-API3 — create_project doesn't guard `WP_Error` from `Projects::create`.** `if ( $project_id )` (ajax:456) — same shape as TRC-API2; WP_Error (missing_client/missing_name/invalid rate at projects:174/178/186) would flow into `PLTT_Projects::get()`. Latent (boundary pre-validates name/client/period/rate), but no `is_wp_error()` guard on the success branch.

### Notes (verified OK, no action)
- `sync_entry_tags` accepts both `array` and CSV `string` (tags:403-405) — both call sites (ajax:225 array, entries:229 csv) are safe.
- `pltt_resolve_billable_rate` self-casts args to int (helpers:1132-1133) — callers passing `(int)` or absint results (ajax:252,:597) are fine; `0` is handled as "skip".
- All date-mutating/deleting handlers (process_log, delete_daily_log, handle_save_entries) use `pltt_sanitize_date_strict`; read/save-only handlers use `pltt_sanitize_date`. Consistent with SEC-M3.
- `handle_save_entries` correctly reads `$result['success']`; `save_entries` always returns an array with that key (review:438).

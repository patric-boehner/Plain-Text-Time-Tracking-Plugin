# UI Layer Call-Traceability Audit — 2026-06-15

Scope: `templates/` (page templates + partials), `assets/js/` (all JS), inline `<script>`/render-method HTML in `includes/admin/`.

Method: every interactive element that triggers plugin code was enumerated, then each `action` string was matched against a registered handler in `includes/api/class-pltt-ajax.php` (`add_action('wp_ajax_*')`), `includes/api/class-pltt-form-handlers.php` (`add_action('admin_post_*')`), and `includes/admin/class-pltt-admin.php`. Field names sent were diffed against `$_POST` reads in each handler.

## Registered handlers (endpoint inventory)

### AJAX (`wp_ajax_*`, PLTT_Ajax) — nonce: `check_ajax_referer('pltt_ajax_nonce','nonce')` via `verify_request()`
`pltt_save_daily_log`, `pltt_update_daily_log`, `pltt_process_log`, `pltt_delete_daily_log`, `pltt_delete_entry`, `pltt_update_entry_field`, `pltt_save_entry`, `pltt_create_client`, `pltt_get_projects`, `pltt_create_project`, `pltt_create_tag`

### admin_post (`admin_post_*`, PLTT_Form_Handlers) — nonce: `wp_verify_nonce($_POST['_wpnonce'], <action-specific>)`
`pltt_update_client` (nonce `pltt_update_client`), `pltt_delete_client` (`pltt_delete_client`), `pltt_update_project` (`pltt_update_project`), `pltt_delete_project` (`pltt_delete_project`), `pltt_save_entries` (`pltt_save_entries`), `pltt_create_tag` (`pltt_manage_tag`), `pltt_rename_tag` (`pltt_manage_tag`), `pltt_delete_tag` (`pltt_manage_tag`), `pltt_bulk_assign_group` (`pltt_bulk_tag_group`)

### admin_post (PLTT_Admin)
`pltt_dismiss_migration_1_9_5` (nonce `pltt_dismiss_migration_1_9_5` via `check_admin_referer`)

### Client `PLTT.ajax` nonce contract
`assets/js/shared.js:19` appends `nonce: plttData.nonce`. `plttData.nonce = wp_create_nonce('pltt_ajax_nonce')` (`class-pltt-admin.php:284`). Matches handler check. ✅ for all AJAX rows.

---

## TRACE TABLE

| # | UI element (file:line) | Trigger | Target action | Fields sent (name → source) | Nonce | VERDICT |
|---|---|---|---|---|---|---|
| 1 | `daily-log.js:107` autoSave (textarea input/timestamp) | AJAX | `pltt_save_daily_log` **or** `pltt_update_daily_log` (chosen by presence of `#pltt-update-notes-btn`) | `date`→pageDate, `content`→textarea | `nonce` | **OK** — both registered; handlers read `date`,`content` |
| 2 | `daily-log.js:237` Save button `#pltt-save-btn` | AJAX | `pltt_save_daily_log` | `date`,`content` | `nonce` | **OK** |
| 3 | `daily-log.js:265` Update Notes `#pltt-update-notes-btn` | AJAX | `pltt_update_daily_log` | `date`,`content` | `nonce` | **OK** |
| 4 | `daily-log.js:198` Process button `#pltt-process-btn` | AJAX | `pltt_process_log` | `date`→dateInput.value, `content` | `nonce` | **OK** — handler reads `date`(strict),`content`; returns `redirect` |
| 5 | `daily-log.js:283` beforeunload `navigator.sendBeacon` | AJAX (beacon) | `pltt_save_daily_log`/`pltt_update_daily_log` | `action`,`nonce`,`date`→pageDate,`content` | `nonce` | **OK** |
| 6 | `daily-log.php` date nav (prev/next anchors, `#pltt-date-nav-trigger`, `#pltt-log-date` change) | link / nav | none (page reload w/ `?date=`) | — | n/a | **OK** — navigation only, no handler needed |
| 7 | `review.js:324` & `:1271` loadProjects (client `<select>` change) | AJAX | `pltt_get_projects` | `client_id`, `current_project_id`(opt) | `nonce` | **OK** — handler reads both |
| 8 | `review.js:498` & `:1308` Save Client modal `#pltt-save-client` | AJAX | `pltt_create_client` | `name`,`hourly_rate` (`:498`); + `description` not sent here | `nonce` | **OK** — handler reads `name`,`description`(opt),`hourly_rate`(opt); missing `description` is optional |
| 9 | `review.js:559` & `:1356` Save Project modal `#pltt-save-project` | AJAX | `pltt_create_project` | `name`,`client_id`,`hourly_rate` | `nonce` | **OK** — handler reads those + optional recurring/budget/non_billable (all optional) |
| 10 | `review.js:611` & `:1396` Save Tag modal `#pltt-save-tag` | AJAX | `pltt_create_tag` | `tag_name` | `nonce` | **OK** — handler reads `tag_name` |
| 11 | `review.js:658` & `:1102` Delete entry `.pltt-delete-entry` | AJAX | `pltt_delete_entry` | `entry_id` | `nonce` | **OK** |
| 12 | `review.js:989` per-row Save `.pltt-form-save` (edit-existing) | AJAX | `pltt_save_entry` | `entry_id`→`data-form-for-entry-id`, `entry_date`,`start_time`,`end_time`,`duration_minutes`,`description`,`client_id`,`project_id`,`tags`,`billable` | `nonce` | **OK** — every field read by handler; handler returns `row_html` |
| 13 | `review-post-parse.php:21` `#pltt-review-form` Save All `#pltt-save-all` | form POST (JS `form.submit()` after serializing) | `admin_post_pltt_save_entries` | `action`,`date`,`entries`(JSON),`return_to`(opt),`_wpnonce` | `_wpnonce` = `pltt_save_entries` | **OK** — handler verifies `pltt_save_entries`, reads `date`,`entries`,`return_to` |
| 14 | `clients.php:135` `#pltt-client-form` submit — **create path** (`#pltt-edit-client-id` empty) | AJAX (`preventDefault`) | `pltt_create_client` | `name`,`description`,`hourly_rate` | `nonce` | **OK** — AJAX path; handler reads all 3 |
| 15 | `clients.php:135` `#pltt-client-form` submit — **update path** (id set) | form POST | `admin_post_pltt_update_client` (action swapped by JS `:216`) | `action`,`client_id`,`name`,`description`,`hourly_rate`,`_wpnonce` | `_wpnonce` = `pltt_update_client` | **OK** — form nonce field is `pltt_update_client`; handler verifies same |
| 16 | `clients.php:166` `#pltt-delete-client-form` (JS submit) | form POST | `admin_post_pltt_delete_client` | `action`,`client_id`,`_wpnonce` | `_wpnonce` = `pltt_delete_client` | **OK** |
| 17 | `clients.php:136` default form `action=pltt_create_client` if JS fails / no-JS | form POST (fallback) | `admin_post_pltt_create_client` | `action`,`name`,... ,`_wpnonce`(=`pltt_update_client`) | `_wpnonce` = `pltt_update_client` | **FLAG TRC-UI1** — no `admin_post_pltt_create_client` handler registered; create relies entirely on JS interception. No-JS submit hits admin-post.php with an unregistered action → silent `0`/dead. Nonce field is also `pltt_update_client`, not a `pltt_create_client` action. |
| 18 | `projects.php:227` `#pltt-project-form` submit — **create path** (id empty) | AJAX (`preventDefault`) | `pltt_create_project` | `client_id`,`name`,`hourly_rate`,`budget_hours`,`budget_fee`,`recurring_period`,`non_billable` | `nonce` | **OK** — handler reads all |
| 19 | `projects.php:227` `#pltt-project-form` submit — **update path** (id set) | form POST | `admin_post_pltt_update_project` (action swapped `:559`) | `action`,`project_id`,`client_id`,`name`,`status`,`hourly_rate`,`recurring_period`,`budget_hours`,`budget_fee`,`non_billable`,`_wpnonce` | `_wpnonce` = `pltt_update_project` | **OK** |
| 20 | `projects.php:227` default form `action=pltt_create_project` if JS fails / no-JS | form POST (fallback) | `admin_post_pltt_create_project` | `action`, project fields, `_wpnonce`(=`pltt_update_project`) | `_wpnonce` = `pltt_update_project` | **FLAG TRC-UI2** — no `admin_post_pltt_create_project` handler registered; same no-JS dead-fallback pattern as TRC-UI1. |
| 21 | `projects.php:335` `#pltt-archive-project-form` (JS submit, archive/restore) | form POST | `admin_post_pltt_update_project` | `action`,`project_id`,`status`,`_wpnonce` | `_wpnonce` = `pltt_update_project` | **OK** — handler gates `client_id`/`name`/billability on presence so status-only submit is safe |
| 22 | `projects.php:343` `#pltt-delete-project-form` (JS submit) | form POST | `admin_post_pltt_delete_project` | `action`,`project_id`,`_wpnonce` | `_wpnonce` = `pltt_delete_project` | **OK** |
| 23 | `projects.php:123` "Group by" toolbar `<select onchange=submit>` | form GET | none (page reload `?group=`) | `page`,`group` | n/a | **OK** — read by template, not a handler |
| 24 | `projects.php:605` create-project AJAX (inline, same as #18) | AJAX | `pltt_create_project` | client_id,name,hourly_rate,budget_hours,budget_fee,recurring_period,non_billable | `nonce` | **OK** |
| 25 | `tags.php:120` `#pltt-tag-form` submit — create | form POST | `admin_post_pltt_create_tag` | `action`,`tag_name`,`tag_id`(empty),`group_name`,`_wpnonce` | `_wpnonce` = `pltt_manage_tag` | **OK** — handler verifies `pltt_manage_tag`, reads `tag_name`,`group_name` |
| 26 | `tags.php:120` `#pltt-tag-form` submit — rename (action swapped `:243`) | form POST | `admin_post_pltt_rename_tag` | `action`,`tag_id`,`tag_name`,`group_name`,`_wpnonce` | `_wpnonce` = `pltt_manage_tag` | **OK** — handler reads `tag_id`,`tag_name`,`group_name` |
| 27 | `tags.php:110` `#pltt-delete-tag-form` (JS submit) | form POST | `admin_post_pltt_delete_tag` | `action`,`tag_id`,`_wpnonce` | `_wpnonce` = `pltt_manage_tag` | **OK** |
| 28 | `reports.js:547` inline Billable toggle `.pltt-billable-symbol.pltt-inline-toggle` | AJAX | `pltt_update_entry_field` | `entry_id`,`field='billable'`,`value` (0/1) | `nonce` | **OK** — handler allowlists field, returns `billable_amount` |
| 29 | `reports.js:641` cascade clear invoiced when billable→off | AJAX | `pltt_update_entry_field` | `entry_id`,`field='billed'`,`value='0'` | `nonce` | **OK** |
| 30 | `reports.js:672` inline Invoiced toggle `.pltt-invoiced-toggle` | AJAX | `pltt_update_entry_field` | `entry_id`,`field='billed'`,`value` | `nonce` | **OK** |
| 31 | `reports.js:698` inline tag picker onClose (report rows) | AJAX | `pltt_update_entry_field` | `entry_id`,`field='tags'`,`value`(csv) | `nonce` | **OK** — handler `field=tags` branch syncs entry tags |
| 32 | `reports.php:155` filters form (date nav, client/project selects, negate toggles) | form GET | none (page reload w/ query args) | `page`,`view`,`from`,`to`,`client_id`,`project_id`,`client_negate`,`project_negate`,… | n/a | **OK** — pure GET filter, no action handler |
| 33 | `reports.js:430` dismiss unbilled notice `.pltt-unbilled-notice-dismiss` | JS only (sessionStorage) | none | — | n/a | **OK** — no server call by design |
| 34 | `log-archive.js:212` Delete log `.pltt-delete-log` | AJAX | `pltt_delete_daily_log` | `log_date`→`data-log-date` | `nonce` | **OK** — handler reads `log_date`(strict) |
| 35 | `log-archive.php:32` month-nav GET form + `log-archive.js` month/year dropdown | form GET | none (page reload) | `page`,`from`,`to` | n/a | **OK** |
| 36 | `class-pltt-admin.php:58` migration-notice "Dismiss" link | link (GET to admin-post.php via `wp_nonce_url`) | `admin_post_pltt_dismiss_migration_1_9_5` | `action`,`_wpnonce` | `_wpnonce` (`check_admin_referer`) | **OK** — handler registered, nonce action matches |
| 37 | `project-detail.js:41` group-by toggle `.pltt-groupby-btn` | JS only (toggles hidden) | none | — | n/a | **OK** — client-side view switch only |
| 38 | `entry-form-row.php:168` Billable toggle button `.pltt-form-billable-btn` | JS only (flips hidden checkbox; saved via #12) | none directly | — | n/a | **OK** — state captured in `pltt_save_entry` payload |
| 39 | review/tag-picker "Add new tag…" link (`tag-picker.js:232`) | JS only (opens modal → #10) | none directly | — | n/a | **OK** |

---

## Unreachable registered handler (no UI trigger)

| Handler | Registered at | UI references | VERDICT |
|---|---|---|---|
| `admin_post_pltt_bulk_assign_group` (`handle_bulk_assign_group`, nonce `pltt_bulk_tag_group`) | `class-pltt-form-handlers.php:39` | **none** — grep across `templates/`, `assets/js/`, `includes/admin/` finds no form action `pltt_bulk_assign_group`, no nonce `pltt_bulk_tag_group`, no `tag_ids[]` field | **FLAG TRC-UI3** — handler fully implemented + wired to `PLTT_Tags::bulk_set_group()` but no interactive surface invokes it. Dead/orphan endpoint (or planned-but-unshipped UI from the tag-taxonomy work). Not a security hole (nonce-gated), but unreferenced code. |

---

## FINDINGS

### TRC-UI1 — `clients.php` create form has no admin_post fallback handler
- UI: `templates/clients.php:135-138` — `<form action=admin-post.php>` defaults `name="action" value="pltt_create_client"`, nonce field is `pltt_update_client`.
- JS (`clients.php:235`) intercepts submit on the **create** path and routes to `PLTT.ajax('pltt_create_client', …)` with `preventDefault()`. Update path swaps action to `pltt_update_client` and POSTs normally.
- Endpoint: **no `add_action('admin_post_pltt_create_client')`** exists (`class-pltt-form-handlers.php`). `pltt_create_client` is AJAX-only.
- Impact: if JS is disabled/fails to load, the native form POSTs `action=pltt_create_client` to admin-post.php → WP fires no handler → blank/`0` response, client silently not created. Also the create form ships a `pltt_update_client` nonce, mismatched to the action it nominally declares.
- Severity: low (JS effectively always present in wp-admin), but it is a latent dead-action + nonce/action mismatch.

### TRC-UI2 — `projects.php` create form has no admin_post fallback handler
- UI: `templates/projects.php:227-230` — same pattern: form default `action=pltt_create_project`, nonce `pltt_update_project`; create path is AJAX-intercepted (`projects.php:592/605`).
- Endpoint: **no `add_action('admin_post_pltt_create_project')`**.
- Impact/severity: identical to TRC-UI1.

### TRC-UI3 — registered `pltt_bulk_assign_group` handler is unreachable from the UI
- Endpoint: `class-pltt-form-handlers.php:39` + `handle_bulk_assign_group()` (reads `tag_ids[]`, `group_name`; nonce `pltt_bulk_tag_group`).
- UI: no template/JS emits a form/link with `action=pltt_bulk_assign_group`, no `tag_ids` field, no `pltt_bulk_tag_group` nonce. Confirmed by repo-wide grep.
- Impact: orphaned endpoint — code exists but no interactive element reaches it. Likely a planned bulk tag-group action whose UI was never shipped (matches the in-progress "Tag taxonomy cleanup" note). Recommend either wiring the UI or removing the handler.

### No other defects found
- All 11 AJAX actions used by JS map 1:1 to registered `wp_ajax_*` handlers; nonce sent (`nonce`) matches `check_ajax_referer('pltt_ajax_nonce','nonce')`.
- All admin_post forms carry the correct action-specific `_wpnonce` matching their handler's `verify_nonce()` call (delete-client→`pltt_delete_client`, delete-project→`pltt_delete_project`, all tag ops→`pltt_manage_tag`, save-entries→`pltt_save_entries`, update-project/client→matching).
- Field sets sent match `$_POST` reads in every handler (e.g. `pltt_save_entry` reads all 10 fields review.js sends; `pltt_update_entry_field` reads `entry_id`/`field`/`value`; inline-edit toggle markup in `helpers.php pltt_render_entry_table()` emits `data-entry-id`/`data-value`/`data-minutes` exactly as reports.js consumes).
- The archive/restore form (`#pltt-archive-project-form`) reuses `pltt_update_project` and is safe because the handler gates `name`/`client_id`/`billability_default` writes on field presence.
- `pltt-tooltip.js` and `tag-picker.js` make no server calls (presentational); GET filter forms (reports, log-archive, projects group-by) trigger page reloads, not action handlers — correctly carry no nonce.

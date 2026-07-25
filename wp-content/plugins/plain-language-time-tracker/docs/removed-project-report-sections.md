# Removed: "Where the time went" + "Activity over time" (Project detail Report tab)

**Removed:** 2026-07-18 · plugin 1.9.35 · **last commit that still contains them: `1fb2a9c`**
**Reason:** On the project page these two sections felt like too much and weren't earning their space (Patrick's call). The type-aware hero, stat cards, the period lens, the **volume "Hours by …" chart**, and Billing history all stayed. Everything below is preserved so both sections can be rebuilt as they were.

To see the exact prior code: `git show 1fb2a9c:templates/partials/project-detail-report.php` and `git show 1fb2a9c:includes/admin/class-pltt-project-report.php` (plus `assets/css/project-detail.css`, `assets/js/project-detail.js`).

---

## What the two sections were

Both lived in `templates/partials/project-detail-report.php`, below the volume chart and above Billing history. They **shared a single "Group by" segmented toggle** (`.pltt-groupby`) — clicking a group switched the visible bars group *and* the visible timeline lane-set in lockstep (that sync was the whole reason they sat together).

### 1. "Where the time went" — horizontal bars
Per-tag-group breakdown of hours. One group of bars per tag-group dimension (e.g. *Phase*, *Activity*, *Ungrouped*), only the default dimension visible, the rest `hidden` and revealed by the toggle.

- **Windowed** to the active period lens (recurring projects); full-lifetime otherwise.
- Each bar = one tag bucket: label + dot (cycled color `.pltt-bar-color-0..7`), a fill bar scaled to the group max, and a meta line `· N% over Nd · worked Nd`. Untagged bucket rendered hatched/gray and sorted last.
- Hover tooltip (`data-pltt-tip`) added Time / Share / Active span / Worked-days.
- Phase-like groups ordered chronologically by first logged day; all others by hours desc.

### 2. "Activity over time" — CSS swimlane timeline
One lane per tag bucket across a shared calendar axis (project first → last entry). **Always full lifetime**, never windowed. Each lane drew solid pills for active stretches, with a dashed connector wherever ≥ `GAP_THRESHOLD_DAYS` (7) days passed with nothing logged.

- Month axis with gridlines; Jan labels carried the year.
- **Fixed-budget projects that went over budget** also got an amber budget-crossing line + over-zone wash + "▾ over budget" axis marker, positioned at the date cumulative hours first reached the budget. Grouping-independent (summed across all entries in date order), so it held still as lanes regrouped. Hourly/internal never had it; retainers use the per-period overage threshold on the Reports entry stream instead.
- Hover tooltips on segments (Span / Time / Worked), on idle gaps (Span / Idle days), and on the budget line (Crossed / Over-by).

---

## Data layer that fed them (removed from `PLTT_Project_Report`)

`PLTT_Project_Report::build()` returned these keys **only** for the two sections (nothing else consumed them):

| Key | Fed | Built by |
|-----|-----|----------|
| `groupings` | bars (windowed slice) | `build_groupings()` on windowed entries |
| `timeline_groupings` | swimlane + toggle dimension set (lifetime) | `build_groupings()` on all entries |
| `default_group` | which dimension shows first | `pick_default_group()` (prefers a phase-like group) |
| `axis` | timeline calendar axis | `build_axis($stats)` |
| `budget_line` | crossing line/wash/marker | `build_budget_line()` (fixed-budget, over-budget only) |

To produce them, `build()` also did work that is now **gone** (this is the runtime cost the removal reclaims — it ran on every project page load):
- `PLTT_Entries::get_all()` for the project's `id/entry_date/duration_minutes` (oldest-first)
- `PLTT_Tags::get_for_entries()`, `PLTT_Tags::get_name_to_group_map()`, `PLTT_Tags::get_all_groups()`
- Windowed-entry filtering + a **second** `build_groupings()` call for the windowed bars

Private methods removed with them (all self-contained, referenced only by the above):
`pick_default_group`, `build_groupings`, `build_one_grouping`, `grouping_description`,
`accumulate`, `finalize_bucket`, `compute_segments`, `build_axis`, `axis_pct` (was `public`),
`build_budget_line`, `looks_like_phase`, `untagged_label`
— plus constants `UNGROUPED`, `UNTAGGED`, `GAP_THRESHOLD_DAYS`.

**What stayed in `build()`:** `has_entries`, `hero`, `cards`, `chart`, `window` — i.e. the hero + stat cards + volume chart. The windowed **stats** query (`$card_stats`) stayed because the cards need it; only the windowed **entries/groupings** work was dropped.

### Bucket shape (for reference when rebuilding)
`build_one_grouping()` produced buckets shaped:
```
key, label, is_untagged, minutes, pct (of grouping total),
first_date, last_date, span_days, worked_days,
segments: [ { start, end, minutes, worked_days }, … ]   // gap-split, for the timeline
```
Grouping shape: `key, label, description, buckets[], total_minutes, max_minutes, has_tagged, is_phase`.
Multi-tag entries attributed their full duration to **each** matching tag, so a group's bucket percentages can exceed 100%.

---

## Front-end pieces removed

- **Template:** the two `<div class="pltt-card pltt-where-card">` / `pltt-timeline-card` blocks and the shared `.pltt-groupby` toggle in `.pltt-where-header`. Kept `.pltt-where-header` / `.pltt-where-title` (Billing history still uses them).
- **JS:** `assets/js/project-detail.js` (its only job was `initGroupBy()` — toggling `.pltt-bars-group` / `.pltt-timeline-group` visibility). File deleted; its `wp_enqueue_script('pltt-project-detail', …)` removed from `class-pltt-admin.php`. The tooltip script/style stay enqueued — the volume chart still uses them.
- **CSS (`assets/css/project-detail.css`):** removed the "Where the time went" block (`.pltt-where-card`, `.pltt-groupby*`, `.pltt-bar*`, `.pltt-bars-empty`, `.pltt-bar-color-0..7`) and the entire "Activity over time — swimlane timeline" block (`.pltt-timeline*`, `.pltt-tl-*`, over-budget line/zone/label vars). Kept `.pltt-where-header` / `.pltt-where-title`.

## To rebuild
1. `git show 1fb2a9c:includes/admin/class-pltt-project-report.php` → restore the removed private methods + constants, and re-add the `groupings` / `timeline_groupings` / `default_group` / `axis` / `budget_line` computation to `build()`.
2. `git show 1fb2a9c:templates/partials/project-detail-report.php` → paste the two card blocks + the `.pltt-groupby` toggle back below the volume chart.
3. Restore `assets/js/project-detail.js` + its enqueue, and the CSS blocks above.
4. Everything is `$report`-key driven — no schema/DB changes were involved, so no migration is needed.

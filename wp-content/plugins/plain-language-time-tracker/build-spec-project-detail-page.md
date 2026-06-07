# Build Spec — Project Detail Page & Postmortem Report

**Plugin:** Plain Language Time Tracker (`pltt_`)
**Visual reference:** `project-postmortem-mockup.html` (the interactive mockup we iterated on)
**Conventions:** follow `development-notes.md` and the Technical Reference in `PROJECT.md`. This spec describes *what* to build and *which decisions are settled*; it assumes the existing data model, helpers, and coding standards.

---

## 1. What we're building

A **project detail page** that replaces the project's current home (a settings-only modal) with a full admin screen. The page has two tabs:

- **Report** (default) — a lifetime, retrospective view of the project: where the time went, when it happened, and how it landed against budget. This is the "postmortem."
- **Settings** — the existing project settings fields, moved out of the modal onto the page.

### The organizing principle (don't lose this)

Scope is the dividing line between this page and the existing Reports screen:

- **Reports = this project, *this period*.** Period-first. Keep period-scoped single-project analysis (monthly overage, weekly hours) there. Unchanged by this work.
- **Project page = this project, *all time*.** Lifetime-first, retrospective. The Report tab is resolutely lifetime-scoped.

**The Report tab has no date filter.** Setting a date range is exactly what Reports is for; keeping this page lifetime-only is what keeps the two surfaces distinct. (See Open Decisions if this needs revisiting.)

---

## 2. Routing & entry points

- The **project name in the Projects list table** becomes a link to this page. (Names are already styled as links.)
- The **project names in the Reports summary table** link here too.
- Reach the page via the existing admin routing pattern, e.g. `admin.php?page=…&action=view&project_id=N`. Use the project's real ID; capability-check and bail cleanly on a missing/invalid project.
- Tab state lives in the query string (`&tab=report|settings`, default `report`) so it's linkable and survives the Settings-save reload.

---

## 3. Page header (above the tabs)

- **H1 = project name.**
- **Type badge + status badge** next to the title. Type badge uses existing styling (Fixed Budget / Hourly / Monthly / Internal). **Status badge only shows when non-default:** Archived → "Archived" badge + muted card treatment; Active → *no badge* (absence is the signal).
- **Subhead line:** project type · calendar span (first entry date – last entry date) · phase count or entry count.
- Admin notices (e.g. after a Settings save) render after the H1, inside the header wrapper — see the Admin Notice Placement section of `development-notes.md`.

---

## 4. Report tab

All figures are **read-only aggregations** computed from the project's time entries. No new data is stored.

### 4a. Summary stat cards

Build the **Fixed Budget lineup** for v1 (the mockup's four cards):

1. **Total hours** — sum of entry durations. Sub-line shows over/under budget (e.g. "+11h 45m over budget") when a budget exists.
2. **Effective rate (EHR)** — total fee ÷ total hours. Sub-line "vs $X target." Most meaningful on archived/fixed-budget projects (retrospective), consistent with the existing EHR rule.
3. **Budget** — hours used of budgeted, with % (budgeted hours = total fee ÷ resolved rate via `pltt_resolve_billable_rate()`).
4. **Fixed fee** — the project's total fee.

**Descriptive, not evaluative.** Show the EHR/percentage numbers; no "profitable / not" labels. Use the amber "attention" color for over-budget sub-lines, consistent with the rest of the tool.

> Per-project-type card lineups (hourly / retainer / internal) are **deferred** — see Open Decisions and the billable-changes doc. v1 ships the fixed-budget lineup.

### 4b. "Where the time went" — grouped bars

Horizontal bars, one per group.

- **Group-by control** with four options = the project's tag groups: **Project Phase (default) · Tasks · Flag · Ungrouped.** Switching re-groups the bars (and the timeline in 4c) live. The control simply mirrors whatever tag groups exist; it has no opinion about overlap.
- **Grouping logic:** group the project's entries by the entry's tag *within the selected group*. Order: Phase = build order (chronological by phase definition); Tasks / Ungrouped = by hours descending; Flag = defined order.
- **Untagged-in-group bucket:** entries with no tag in the active group fall into a muted, hatched bucket sorted last — "Unphased" (Phase), "No flag" (Flag), "No task tag" (Tasks). This is how a project logged before phase tags existed stays honest: its time shows up as Unphased rather than silently dropping. If no such entries exist, the bucket doesn't appear. (For old projects, the user just uses the Tasks grouping instead — it never depended on phases.)
- **Bar meta line (single line, hours stated once):**
  `{hours} · {pct}% over {spanDays}d · worked {workedDays}d`
  where spanDays = first→last entry in that group inclusive, workedDays = distinct days with an entry in that group.
- **Empty / heterogeneous states:** Ungrouped is empty if the project has no ungrouped tags — show a quiet "No ungrouped tags on this project" message rather than an empty chart.

### 4c. "When it happened" — swimlane timeline

One horizontal track per group, sharing a single calendar x-axis spanning the project's first→last entry, with month gridlines.

Each track shows:

- **Open-span band** (faint, group color) from the group's first to last entry — the phase "being open."
- **Worked-day ticks** (solid, group color) at each day with logged time; **tick height ∝ hours that day.** Solid-on-faint = worked vs. idle at a glance. A track that's mostly hollow over a long span = a stall.
- **Gap markers** for idle runs ≥ 7 days between consecutive worked days within a group — a dashed marker labelled "Nd idle." (Blank space is ambiguous; a labelled gap is interrogable.)
- **Phase-gate markers (Phase grouping only):** a marker at each phase's end (derive from the phase's last entry date), labelled with the gate name (Sitemap approved, Design approved, etc.). Gates are a phase concept — hide them for Tasks / Flag / Ungrouped groupings.
- **Hover detail** on ticks (phase · task, date, hours), gaps (date range, days idle), and gates (gate name, date).
- **Legend:** worked (height = hours) · phase open/idle · gap (7+ days) · phase gate.

The bars (4b) and timeline (4c) read together: the bar says *which* group ate the time; the timeline says *why* — concentrated effort (estimate/scope question) vs. a long idle stretch before a gate (sign-off/process question).

> The mockup's prose "reading" note under the timeline was scaffolding to explain the design — **omit it** from the build, or reduce to a one-line hint.

---

## 5. Settings tab

The current edit-modal fields, relocated onto the page as a form section. **Reuse the existing project save logic and validation** — this is the same data, just a different surface. Do not build a second save path.

Fields (from the existing modal):

- **Client** (select)
- **Project Name** (text)
- **Billing Type** (select: Fixed Budget / Hourly / Monthly (Retainer) / Internal)
- **Type-specific sub-panel**, shown conditionally on billing type (reuse existing logic): e.g. Fixed Budget → Track Budget By (Project Fee / Budgeted Hours) + Total Fee. Retainer → its allocation/fee fields. Hourly / Internal → none.
- **Hourly Rate (optional)** with helper text ("Used to calculate the implied effective rate. Leave blank to use the client rate.")
- **Non-billable project** checkbox + helper text.
- **Actions:** Archive · Save (Cancel optional on a full page).

Security & UX: nonce on save, sanitize inputs, escape output, capability check. On save, redirect back to the Settings tab and show the success notice after the H1; clean the notice query param from the URL after display (per `development-notes.md`).

---

## 6. Data & computation notes

- All aggregation is read-only over the project's entries. Reuse bulk loaders (`get_multiple`, `PLTT_Projects::get_for_clients`) and transient caching; no per-entry queries in loops.
- Rates via `pltt_resolve_billable_rate()`. EHR = fee ÷ total hours. Budgeted hours = fee ÷ resolved rate.
- Tick heights need **per-day hours within a group**; gap detection needs the sorted set of **distinct worked days per group**; gate markers need the **last entry date per phase**.
- **Multi-tag edge case:** entries typically carry one tag per group. If an entry has more than one tag in the active group (rare), attribute its hours to each matching tag — group percentages may then sum above 100%, which is the accepted behavior from the prior tag-visualization decisions. **Do not** build combination-grouping machinery.

---

## 7. Conventions (must-hits from development-notes.md)

- **Procedural PHP**, `pltt_`-prefixed; domain logic as existing static `PLTT_*::method()` calls. No new OO architecture.
- **Separate CSS and JS files**, enqueued **only on this screen**, in the footer, cacheable. No inline blocks. Vanilla JS only — no jQuery, no frameworks.
- **CSS custom properties** for all colors/spacing — no hardcoded values. Reuse the tool's existing palette and the semantic colors (amber = over/attention, etc.).
- **Accessibility (WCAG AA min):** tabs as a proper ARIA `tablist`/`tab`/`tabpanel` with keyboard support; visible focus states; semantic HTML; screen-reader text where visual-only context exists; sufficient contrast.
- WordPress-native screen registration, hooks, escaping, sanitization throughout.

---

## 8. Suggested build order

1. **Page scaffold + routing + tabs + Settings tab.** Link project names to it; move the modal fields onto the Settings tab (reusing the save path). Lowest risk, immediately useful — the project gets a real home.
2. **Report: stat cards + "Where the time went" bars** with the four-group switch and the untagged bucket.
3. **Report: "When it happened" timeline.** Largest new component; worth its own pass after the rest is in use.

---

## 9. Explicitly out of scope (v1)

- **No date filter on the Report tab.** Lifetime only; period analysis stays in Reports.
- **No per-project-type card lineups.** Build the fixed-budget set; hourly/retainer/internal lineups are deferred (billable-changes doc).
- **No revisions overlay** on the timeline (later, if it earns it).
- **No tag-cleanup tooling.** Overlap is cleaned manually per project; the view just surfaces it honestly.
- **No tag-group restructuring** (e.g. splitting Tasks into client-work vs. business-work, or consolidating communication tags). Accept tags as they are.
- **Keep the existing quick-edit modal** from the list for fast edits (see Open Decisions).

---

## 10. Open decisions (confirm before/while building)

- **Keep or retire the quick-edit modal?** Recommendation: keep it as a fast shortcut from the list (rate tweak, archive — no page load); the Settings tab and the modal share the same fields and save path, so it's a shortcut, not a second system. Alternative: drop the modal, add a WP-style inline Quick Edit later.
- **Date scope on the Report tab.** Recommendation: none (lifetime only). Flagged here so it's a conscious choice, not a drift.
- **Non-build project types on the Report tab.** Hourly/retainer/internal projects will show mostly-empty or Unphased phase bars; they probably want to default to the Tasks grouping and a different card lineup. Deferred — noted so it's expected, not a surprise.

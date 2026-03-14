# Plugin Health Review Prompt

Copy and paste the following into a new Claude Code conversation to run a full plugin review.

---

Please run a comprehensive health review of this plugin. Launch the following 4 agents **in parallel** (all in a single message), then aggregate their findings into a final prioritized report.

Before launching agents, read these two files so you have the full evaluation criteria:
- `design-philosophy.md`
- `development-notes.md`

---

**Agent 1 — Design Philosophy Audit**

Read `design-philosophy.md` carefully. Then audit the entire plugin against it:

- Inventory every user-facing feature in the plugin (templates, admin screens, AJAX endpoints, form handlers)
- Cross-reference each feature against the YES/NO/MAYBE lists in design-philosophy.md
- Flag anything that matches the "Skip It" criteria (solves hypothetical problem, rarely used, complexity not justified by current pain)
- Assess whether the "capture first, categorize later" core workflow is preserved and central throughout the codebase
- Check if any feature appears to have been built for future/hypothetical needs vs. actual current pain
- Note the MAYBE list items (retainer tracking, project estimates) — have they been built? Should they have been?
- Summarize: how well does the plugin stay true to its stated purpose of making time tracking painless and fast?

Files to read: `design-philosophy.md`, `templates/`, `includes/admin/`, `includes/api/`

---

**Agent 2 — Technical Standards Audit**

Read `development-notes.md` carefully. Then audit the plugin's code against every standard in that document:

- **PHP style**: Is the code procedural or OOP? (Classes are used — is this acceptable given the plugin's history, or a clear violation of the stated preference?)
- **JavaScript**: Pure vanilla JS only? Any jQuery, frameworks, or libraries? Check all files in `assets/js/`
- **WordPress hooks**: Is all code properly hooked? Anything executed directly at file load?
- **Security**: Check for unescaped output, unsanitized input, missing nonces, missing capability checks — sample all templates and AJAX handlers
- **CSS variables**: Are colors and spacing defined as CSS custom properties or hardcoded? Check `assets/css/admin.css` for variable definitions, then check if they're used consistently
- **Asset loading**: Are scripts loaded in footer? Conditional loading per page? Any inline scripts/styles that should be enqueued?
- **Admin notices**: Do all pages follow the grid layout pattern (notices inside header wrapper, near H1)?
- **Function size**: Flag any functions longer than ~30 lines that appear to be doing more than one thing

Files to read: `development-notes.md`, `assets/js/`, `assets/css/`, `includes/`, `templates/`

---

**Agent 3 — Code Complexity & Maintainability Audit**

Audit the raw code quality of the plugin with fresh eyes:

- Find the 5 longest or most complex functions/methods — list them with file:line and a one-line description of the problem
- Identify any functions clearly doing more than one thing that could be split
- Find duplicated logic — specifically: tag picker CSS is known to be duplicated between `review.css` and `reports.css`; find any other duplication in PHP or JS
- Look for hardcoded values that should be constants (magic numbers, magic strings, hardcoded IDs — note `PLTT_INTERNAL_CLIENT_ID = 3` specifically)
- Assess comment quality: are comments explaining WHY or just WHAT? Flag the worst offenders
- Look for dead code, unused variables, commented-out code blocks
- Check for any N+1 query patterns — places where bulk helpers (`get_multiple`, `get_for_clients`) should be used but aren't
- Check `helpers.php` — are the shared helpers actually being used consistently, or are there places in the codebase doing the same thing inline?

Files to read: All PHP files in `includes/`, `assets/js/`, `assets/css/`

---

**Agent 4 — Feature Inventory & Usage Audit**

Create a complete inventory of every feature in the plugin and assess each one:

- List every user-facing screen/page with what it does
- List every AJAX endpoint and what it handles
- List every form action and what it handles
- For each feature, rate it: Core (used daily/weekly), Supporting (used monthly), Peripheral (rarely needed), or Unknown
- Flag any features that seem over-engineered relative to the problem they solve
- Check `plain-language-time-tracker.php` for all constants — are they reasonable defaults or signs of premature configurability?
- Check if project budget/retainer tracking features have been built (these were on the MAYBE list) — if so, are they being used or are they dormant complexity?
- Identify anything that looks like it was added "because it would be nice" vs. "because it solved a real problem today"

Files to read: `plain-language-time-tracker.php`, `templates/`, `includes/admin/`, `includes/api/class-pltt-ajax.php`, `includes/api/class-pltt-form-handlers.php`, `design-philosophy.md`

---

## After All 4 Agents Complete

Compile their findings into a single structured report using this format:

```
## Plugin Health Report

### Overall Assessment
[1-2 paragraph honest assessment of the plugin's health]

### What's Working Well
[Bullet list]

### Standards Violations
[Ordered by severity: Critical / High / Medium / Low]
[Each item: file:line, what the violation is, why it matters]

### Scope Creep Concerns
[Features or complexity that may not belong]

### Code Quality Issues
[Ordered by impact on maintainability]
[Each item: file:line, description]

### Prioritized Recommendations
1. [Critical] ...
2. [High] ...
3. [Medium] ...
4. [Low] ...

### Feature Inventory Summary
- Core features (daily/weekly use): X
- Supporting features (monthly use): X
- Peripheral / rarely used: X
- Possible scope creep: X
```

Be direct and honest. The point of this review is to catch problems before they compound — not to validate decisions already made.

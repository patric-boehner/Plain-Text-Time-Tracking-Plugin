# Plain Language Time Tracker

A WordPress plugin for tracking time from a plain-text daily log. You write timestamped notes as you work, and at the end of the day the plugin turns them into time entries and suggests a client, project, and tags for each one.

I built it for my own freelance work, and it only does what I need. It runs entirely in the WordPress admin.

## The daily log

Each day has one text box. A line that starts with `@` and a time becomes a time entry. Anything else stays in the log as a note.

```
@9:15am - Kickoff meeting with ABC Corp about website redesign
@10:30am - Started wireframes for homepage
  Nav is the hard part. They want mega-menu behavior on three top-level
  items, and nobody has said what happens on mobile.
@12:00pm - Lunch
@1:00pm - Continued wireframes - product pages
@2:50pm - Client call - approved mockups
@4:20pm - done
```

Each entry runs until the next timestamp, so the kickoff meeting above is 1 hour 15 minutes. The indented lines have no timestamp, so they never become an entry.

Timestamp formats:

```
@2:50pm - Description              12-hour
@14:50 - Description               24-hour
@2:50pm - 4:20pm - Description     explicit start and end
@4:20pm - done                     ends the previous entry without starting a new one
```

A line whose description is `done`, `end`, `finished`, `stop`, `break`, `lunch`, `pause`, or `eod` (or is empty) ends the previous entry and creates nothing itself.

Pressing `@` inserts the current time. The log saves as you type.

## Processing a day

**Process Time Entries** parses the log and opens a review screen with one row per entry. Each row is pre-filled where the plugin has something to go on:

- **Client.** Acronyms (`ABC`) and capitalized words in the description are matched against names it has seen before. When you confirm or correct a row, it remembers which client and project that word pointed to.
- **Project.** Taken from the matched word if it has one, otherwise the project you used most recently for that client in the last 30 days. Archived projects are never suggested.
- **Tags.** A tag is suggested when its name, or one of the keywords you gave it, appears in the description.

You correct what is wrong and save. After the save, a notice says how much is left on any retainer or budget that day's entries counted toward.

Processing never changes the log. The full text of every day is kept, and **History** lists the days by month.

## Screens

| Screen | What it is for |
|---|---|
| **Today** | The daily log, and the entries recorded from it. A day that has been processed opens read-only until you choose Edit. |
| **History** | Every day's log, by month. |
| **Overview** | Hours and billable amounts for a date range, filtered by client, project, or tag, with an hours-by-day chart. Entries can be edited here. |
| **Billing** | What is ready to bill across all clients, and what has been billed. |
| **Clients**, **Projects** | Rates and billing setup. Each project has its own page with its hours, budget, and billing history. |
| **Tags** | Tag names, groups, and the keywords that suggest them. |

## Projects and billing

A project is billed one of four ways:

- **Hourly.** Every entry is billable at the project's rate.
- **Monthly retainer.** A set number of hours is included each period. Only time over that is billed.
- **Fixed budget.** A fee or a number of hours for the whole project. Time is tracked against the budget and is not billed by the hour.
- **Internal.** Never billed.

The rate for an entry comes from the project, then the client, then a default of $100/hr. It is stored on the entry when you save it, so changing a rate later does not change past entries.

Each entry is also marked **Project** or **Internal**. Internal marks time you spent on a client's project on your own account, such as fixing your own mistake. Whether an entry is billable follows from this and the project's type, and is not set by hand.

Billing is a record of what you invoiced. The plugin does not create or send invoices. When a project has something to bill, you review the entries and the total, enter the amount you actually invoiced, and commit. The plugin stores which entries that record covered, so they cannot be billed twice. If you invoiced less than the calculated amount, the difference is kept on the record as absorbed. A record cannot be edited. To change one, delete it and the entries return to unbilled.

A retainer period can be billed from its last day.

## Weekly email

Once a week the plugin emails where every retainer and fixed budget stands. It is sent on Monday at 7am to the site admin address by default.

## Configuration

There is no settings page. Defaults are constants at the top of `plain-language-time-tracker.php`. The digest constants can also be defined in `wp-config.php`.

| Constant | Default | Meaning |
|---|---|---|
| `PLTT_DEFAULT_HOURLY_RATE` | `100.00` | Rate used when neither the project nor the client has one |
| `PLTT_PREDICTION_WINDOW_DAYS` | `30` | How far back to look for a client's most recent project |
| `PLTT_DIGEST_ENABLED` | `true` | Send the weekly email |
| `PLTT_DIGEST_WEEKDAY` | `1` | Day it is sent (0 = Sunday, 6 = Saturday) |
| `PLTT_DIGEST_HOUR` | `7` | Hour it is sent, in site time |
| `PLTT_DIGEST_RECIPIENT` | `''` | Address it goes to. Blank means the site admin email |

## Requirements and install

- WordPress 6.0 or later
- PHP 7.4 or later

This repository is a LocalWP site with only this plugin tracked, so the plugin lives at `wp-content/plugins/plain-language-time-tracker/`. Copy that folder into your site's `wp-content/plugins/` directory and activate it. **Time Tracker** appears in the admin menu. There is no build step.

All screens require the `manage_options` capability.

Deleting the plugin from the Plugins screen removes its tables and all of its data. Deactivating it removes nothing.

## Code

PHP with static classes, plain JavaScript and CSS. No Composer, no bundler, and no tests.

```
plain-language-time-tracker.php   Constants, loading, activation
includes/
  database/    Schema and migrations, one class per table
  admin/       Menu, asset loading, one class per screen
  api/         AJAX handlers and form handlers
  parser/      Log parsing and predictions
  helpers.php  Shared functions
templates/     Screen templates and partials
assets/        CSS and JS
```

Tables, all prefixed `{wp_prefix}pltt_`:

| Table | Holds |
|---|---|
| `daily_logs` | The text of each day's log |
| `time_entries` | Entries, each with its rate and amount as saved |
| `clients`, `projects` | Rates, billing type, retainer period, budget |
| `aliases` | Words that pointed to a client or project |
| `tags`, `entry_tags`, `tag_aliases` | Tags, their entries, and their keywords |
| `billing_records`, `billing_record_entries` | Each bill recorded, and the entries it covered |

## License

GPL v2 or later. https://www.gnu.org/licenses/gpl-2.0.html

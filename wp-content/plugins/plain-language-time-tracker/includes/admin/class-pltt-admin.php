<?php
/**
 * Admin initialization and menu registration.
 *
 * @package PlainLanguageTimeTracker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin menu and asset loading.
 */
class PLTT_Admin {

	/**
	 * Initialize admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_finalized_review' ) );
	}

	/**
	 * Route finalized-day "review" visits to the Daily Log (Today) inline editor.
	 *
	 * Editing committed entries happens in place on the Today screen now; the
	 * review screen is only for the post-parse commit. So when a review link
	 * lands on a date whose entries are all verified (or that has no entries),
	 * send the user to Today instead, forwarding any return_to so the
	 * Reports → Edit → Back loop is preserved. A day with an unverified draft
	 * is mid-commit and stays on the review screen.
	 *
	 * Runs on admin_init (before any output) so wp_safe_redirect is safe.
	 */
	public static function maybe_redirect_finalized_review() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET routing.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$screen = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : '';
		if ( 'pltt-time-tracker' !== $page || 'review' !== $screen ) {
			return;
		}

		$date    = isset( $_GET['date'] ) ? pltt_sanitize_date( wp_unslash( $_GET['date'] ) ) : pltt_get_current_date();
		$entries = PLTT_Entries::get_by_date( $date );

		// Any unverified draft → genuine post-parse commit; leave it on review.
		foreach ( $entries as $entry ) {
			if ( empty( $entry->verified ) ) {
				return;
			}
		}

		// All verified (or empty): edit on Today instead.
		$args = array( 'date' => $date );
		if ( ! empty( $_GET['return_to'] ) ) {
			// Read it the same way review.php does, validate against open-redirects,
			// then rawurlencode for forwarding: add_query_arg does NOT encode values,
			// so a bare "&" in the URL would otherwise split the query.
			$return_to = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['return_to'] ) ), '' );
			if ( $return_to ) {
				$args['return_to'] = rawurlencode( $return_to );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Land on the entries section — Today leads with the journal capture box,
		// but a review/edit link means the user wants the entries.
		wp_safe_redirect( pltt_get_admin_url( 'daily-log', $args ) . '#pltt-entries' );
		exit;
	}

	/**
	 * Register admin menu pages.
	 */
	public static function add_admin_menu() {
		// Top-level group. Keep the menu title "Time Tracker": WordPress derives the
		// submenu hook suffixes (time-tracker_page_*) from it, and enqueue_assets()
		// keys its allowlist off those hooks — renaming it would silently break asset
		// loading.
		add_menu_page(
			__( 'Time Tracker', 'plain-language-time-tracker' ),
			__( 'Time Tracker', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-time-tracker',
			array( __CLASS__, 'render_page' ),
			'dashicons-clock',
			30
		);

		/*
		 * Redesign nav (phase 1): relabel the three primary destinations — Today,
		 * Insights, Billing — and keep Clients, Projects and Tags as their own menu
		 * items. Every page slug is unchanged, so existing links, bookmarks and the
		 * enqueue allowlist keep working; only three labels change.
		 */

		// Today — capture + process + review (same slug as the parent).
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Today', 'plain-language-time-tracker' ),
			__( 'Today', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-time-tracker',
			array( __CLASS__, 'render_page' )
		);

		// History — the log archive / month browser, its own destination again
		// (slug kept as pltt-log-archive). It sits right after Today because it's
		// the "look back at past days" companion to the day view.
		add_submenu_page(
			'pltt-time-tracker',
			__( 'History', 'plain-language-time-tracker' ),
			__( 'History', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-log-archive',
			array( __CLASS__, 'render_history_page' )
		);

		// Overview — reporting (slug kept as pltt-reports).
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Overview', 'plain-language-time-tracker' ),
			__( 'Overview', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-reports',
			array( __CLASS__, 'render_reports_page' )
		);

		// Billing — cross-project queue + invoiced ledger (slug kept as pltt-invoicing).
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Billing', 'plain-language-time-tracker' ),
			__( 'Billing', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-invoicing',
			array( __CLASS__, 'render_invoicing_page' )
		);

		// Clients.
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Clients', 'plain-language-time-tracker' ),
			__( 'Clients', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-clients',
			array( __CLASS__, 'render_clients_page' )
		);

		// Projects.
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Projects', 'plain-language-time-tracker' ),
			__( 'Projects', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-projects',
			array( __CLASS__, 'render_projects_page' )
		);

		// Tags.
		add_submenu_page(
			'pltt-time-tracker',
			__( 'Tags', 'plain-language-time-tracker' ),
			__( 'Tags', 'plain-language-time-tracker' ),
			'manage_options',
			'pltt-tags',
			array( __CLASS__, 'render_tags_page' )
		);
	}

	/**
	 * Gate a page render on the plugin capability; dies with a 403 otherwise.
	 *
	 * Shared by every render_*_page() method (OPT-DUP-D) so the capability copy
	 * lives in one place.
	 */
	private static function require_access() {
		if ( ! pltt_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plain-language-time-tracker' ) );
		}
	}

	/**
	 * Render the main page (Daily Log or Review based on screen param).
	 */
	public static function render_page() {
		self::require_access();

		// Check which screen to show.
		$screen = isset( $_GET['screen'] ) ? sanitize_text_field( wp_unslash( $_GET['screen'] ) ) : 'daily-log';

		switch ( $screen ) {
			case 'review':
				PLTT_Review::render();
				break;
			default:
				PLTT_Daily_Log::render();
				break;
		}
	}

	/**
	 * Render the History page — the log archive / month browser.
	 *
	 * Its own menu destination (?page=pltt-log-archive) again, no longer a
	 * sub-view of Today.
	 */
	public static function render_history_page() {
		self::require_access();

		PLTT_Log_Archive::render();
	}

	/**
	 * Render the reports page.
	 */
	public static function render_reports_page() {
		self::require_access();

		PLTT_Reports::render();
	}

	/**
	 * Render the invoicing page — two views under one Billing workspace:
	 *   - ready:   outstanding work grouped by client (the "Review & bill" cards)
	 *              + this-month activity cards.
	 *   - history: the filtered, paginated ledger of committed records + cards.
	 */
	public static function render_invoicing_page() {
		self::require_access();

		$view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'ready';
		if ( ! in_array( $view, array( 'ready', 'history' ), true ) ) {
			$view = 'ready';
		}

		// Defaults so the template can reference either view's vars safely.
		$queue       = null;
		$mtd         = null;
		$log         = null;
		$all_clients = array();
		$date_from   = '';
		$date_to     = '';
		$h_client_id = 0;
		$h_type      = '';

		if ( 'history' === $view ) {
			// Default to This Month; the date-nav narrows/steps from there.
			$date_from   = isset( $_GET['from'] ) ? pltt_sanitize_date( wp_unslash( $_GET['from'] ) ) : current_time( 'Y-m-01' );
			$date_to     = isset( $_GET['to'] ) ? pltt_sanitize_date( wp_unslash( $_GET['to'] ) ) : pltt_get_current_date();
			$h_client_id = isset( $_GET['client_id'] ) ? absint( $_GET['client_id'] ) : 0;
			$h_type      = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
			if ( ! in_array( $h_type, PLTT_Billing_Records::TYPES, true ) ) {
				$h_type = '';
			}
			$h_paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

			$log = PLTT_Billing::get_invoiced_log(
				array(
					'date_from'    => $date_from,
					'date_to'      => $date_to,
					'client_id'    => $h_client_id,
					'billing_type' => $h_type,
					'paged'        => $h_paged,
				)
			);
			$all_clients = PLTT_Clients::get_all();
		} else {
			$queue = PLTT_Billing::get_invoicing_queue();
			// This-month billed/absorbed activity for the summary cards.
			$mtd = PLTT_Billing::get_billed_totals( current_time( 'Y-m-01' ), pltt_get_current_date() );
		}

		include PLTT_PLUGIN_DIR . 'templates/reports-invoicing.php';
	}

	/**
	 * Render the clients page.
	 */
	public static function render_clients_page() {
		self::require_access();

		include PLTT_PLUGIN_DIR . 'templates/clients.php';
	}

	/**
	 * Render the projects page.
	 */
	public static function render_projects_page() {
		self::require_access();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'view' === $action ) {
			PLTT_Project_Detail::render();
			return;
		}
		if ( 'bill' === $action ) {
			// The one billing surface (verify -> adjust -> commit), reached from the
			// project page's "Review & bill". Lives under pltt-projects (like the
			// project detail view) to share the capability gate and enqueue hook.
			PLTT_Billing_Surface::render();
			return;
		}

		include PLTT_PLUGIN_DIR . 'templates/projects.php';
	}

	/**
	 * Render the tags page.
	 */
	public static function render_tags_page() {
		self::require_access();

		include PLTT_PLUGIN_DIR . 'templates/tags.php';
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our plugin pages.
		$plugin_pages = array(
			'toplevel_page_pltt-time-tracker',
			'time-tracker_page_pltt-log-archive',
			'time-tracker_page_pltt-reports',
			'time-tracker_page_pltt-invoicing',
			'time-tracker_page_pltt-clients',
			'time-tracker_page_pltt-projects',
			'time-tracker_page_pltt-tags',
		);

		if ( ! in_array( $hook, $plugin_pages, true ) ) {
			return;
		}

		$version = defined( 'WP_DEBUG' ) && WP_DEBUG ? time() : PLTT_VERSION;

		// Design language — tokens, type, and the header/number-bar primitives.
		// Loaded first so every per-screen sheet can build against it.
		wp_enqueue_style(
			'pltt-system',
			PLTT_PLUGIN_URL . 'assets/css/pltt-system.css',
			array(),
			$version
		);

		// Shared admin styles.
		wp_enqueue_style(
			'pltt-admin',
			PLTT_PLUGIN_URL . 'assets/css/admin.css',
			array( 'pltt-system' ),
			$version
		);

		// PlttTooltip — global, not per-screen. It started as chart-only, but
		// pltt_render_info_note() puts an ⓘ wherever a sentence is worth hiding,
		// and every screen has an empty state that can carry one. Loading it in
		// one place is also what stops the class of bug this pattern just hit:
		// markup rendering with its stylesheet absent. ~4KB combined.
		wp_enqueue_style(
			'pltt-tooltip',
			PLTT_PLUGIN_URL . 'assets/css/pltt-tooltip.css',
			array( 'pltt-admin' ),
			$version
		);
		wp_enqueue_script(
			'pltt-tooltip',
			PLTT_PLUGIN_URL . 'assets/js/pltt-tooltip.js',
			array(),
			$version,
			true
		);

		// Shared JS utilities.
		wp_enqueue_script(
			'pltt-shared',
			PLTT_PLUGIN_URL . 'assets/js/shared.js',
			array(),
			$version,
			true
		);

		// Localize script data.
		wp_localize_script(
			'pltt-shared',
			'plttData',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'pltt_ajax_nonce' ),
				'autosaveDebounceMs' => PLTT_AUTOSAVE_DEBOUNCE_MS,
				'internalClientId' => pltt_get_internal_client_id(),
				'i18n'             => array(
					'saving'     => __( 'Saving...', 'plain-language-time-tracker' ),
					'saved'      => __( 'Saved', 'plain-language-time-tracker' ),
					'savedAt'    => __( 'Saved %s', 'plain-language-time-tracker' ),
					'unsaved'    => __( 'Unsaved changes', 'plain-language-time-tracker' ),
					'error'      => __( 'Error', 'plain-language-time-tracker' ),
					'confirm'    => __( 'Are you sure?', 'plain-language-time-tracker' ),
					'processing' => __( 'Processing...', 'plain-language-time-tracker' ),
					// Status-column badges rebuilt client-side when the billable
					// toggle flips. Must match pltt_render_entry_table()'s markup in
					// includes/helpers.php — the two states a toggleable row can reach.
					'statusUnbilled'          => __( 'Unbilled', 'plain-language-time-tracker' ),
					'statusUnbilledTitle'     => __( 'Chargeable — not on a bill record yet', 'plain-language-time-tracker' ),
					'statusNotCharged'        => __( 'Not charged', 'plain-language-time-tracker' ),
					'statusNotChargedTitle'   => __( 'Billable was switched off for this entry', 'plain-language-time-tracker' ),
				),
				// Strings for the shared project billing-type picker
				// (assets/js/project-type-picker.js), used by the Projects modal
				// and the entry-editor "Add project" quick-add.
				'projectType'      => array(
					'rate'        => array(
						'hourly'    => __( 'Leave blank to use client rate.', 'plain-language-time-tracker' ),
						'fixed'     => __( 'Used to calculate implied effective rate. Leave blank to use client rate.', 'plain-language-time-tracker' ),
						'recurring' => __( 'Used for overage billing. Leave blank to use client rate.', 'plain-language-time-tracker' ),
						'none'      => __( 'Not applicable for internal projects.', 'plain-language-time-tracker' ),
					),
					'nonbillable' => array(
						'hourly'    => __( 'Entries default to billable at this rate. Check the box to default new entries to non-billable instead.', 'plain-language-time-tracker' ),
						'fixed'     => __( 'Entries default to non-billable — you decide when and what to bill against the budget.', 'plain-language-time-tracker' ),
						'recurring' => __( 'Time within the allocation is non-billable, covered by the retainer. Hours over the plan are billed as overage.', 'plain-language-time-tracker' ),
						'none'      => __( 'Internal work is never billed — it still shows in the Overview so you can see where your own time goes.', 'plain-language-time-tracker' ),
					),
					'fixedTitle'     => __( 'FIXED BUDGET SETTINGS', 'plain-language-time-tracker' ),
					'hourBudget'     => __( 'Hour Budget', 'plain-language-time-tracker' ) . ' ',
					'fixedDesc'      => __( 'Entries default to non-billable — you decide when and what to bill against the budget.', 'plain-language-time-tracker' ),
					'recurringTitle' => __( 'RECURRING SETTINGS', 'plain-language-time-tracker' ),
					'hourAllocation' => __( 'Hour Allocation', 'plain-language-time-tracker' ) . ' ',
					'recurringDesc'  => __( 'Hours included per period. Time within the allocation is covered by the retainer; hours over the plan are billed as overage when you invoice.', 'plain-language-time-tracker' ),
					'budgetRequired' => __( 'Enter the total fee for this fixed-budget project.', 'plain-language-time-tracker' ),
				),
			)
		);

		// Page-specific assets.

		// Shared project billing-type picker: the Projects-page modal and the
		// entry-editor "Add project" quick-add (Today / review) both drive
		// project-billing-fields.php with it. Strings come from plttData (pltt-shared).
		if ( 'time-tracker_page_pltt-projects' === $hook || 'toplevel_page_pltt-time-tracker' === $hook ) {
			wp_enqueue_script(
				'pltt-project-type-picker',
				PLTT_PLUGIN_URL . 'assets/js/project-type-picker.js',
				array( 'pltt-shared' ),
				$version,
				true
			);
		}

		// Which Projects screen: the list, or a project's detail view. Resolved here
		// because several blocks below (and the shared month picker) branch on it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only asset routing.
		$projects_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		// Shared month picker (templates/partials/month-picker.php): History's month
		// browser and a retainer project's period filter render the same control, so
		// its open/close + year-switcher behaviour is enqueued for both.
		if ( 'time-tracker_page_pltt-log-archive' === $hook
			|| ( 'time-tracker_page_pltt-projects' === $hook && 'view' === $projects_action ) ) {
			wp_enqueue_script(
				'pltt-month-picker',
				PLTT_PLUGIN_URL . 'assets/js/pltt-month-picker.js',
				array(),
				$version,
				true
			);
		}

		// History — the log archive / month browser, its own page again.
		if ( 'time-tracker_page_pltt-log-archive' === $hook ) {
			wp_enqueue_style(
				'pltt-log-archive',
				PLTT_PLUGIN_URL . 'assets/css/log-archive.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_script(
				'pltt-log-archive',
				PLTT_PLUGIN_URL . 'assets/js/log-archive.js',
				array( 'pltt-shared' ),
				$version,
				true
			);
		}

		if ( 'toplevel_page_pltt-time-tracker' === $hook ) {
			$screen = isset( $_GET['screen'] ) ? sanitize_text_field( wp_unslash( $_GET['screen'] ) ) : 'daily-log';

			// Inline entry-editor bundle — shared by the review screen and the
			// Daily Log (Today) inline editor. review.js IIFE 2 binds it wherever
			// the editable list is present.
			wp_enqueue_style(
				'pltt-tag-picker',
				PLTT_PLUGIN_URL . 'assets/css/tag-picker.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_style(
				'pltt-review',
				PLTT_PLUGIN_URL . 'assets/css/review.css',
				array( 'pltt-admin', 'pltt-tag-picker' ),
				$version
			);
			wp_enqueue_script(
				'pltt-tag-picker',
				PLTT_PLUGIN_URL . 'assets/js/tag-picker.js',
				array(),
				$version,
				true
			);
			wp_enqueue_script(
				'pltt-review',
				PLTT_PLUGIN_URL . 'assets/js/review.js',
				array( 'pltt-shared', 'pltt-tag-picker' ),
				$version,
				true
			);

			if ( 'review' !== $screen ) {
				// Daily Log also loads the capture UI (textarea autosave / process).
				wp_enqueue_style(
					'pltt-daily-log',
					PLTT_PLUGIN_URL . 'assets/css/daily-log.css',
					array( 'pltt-admin' ),
					$version
				);
				wp_enqueue_script(
					'pltt-daily-log',
					PLTT_PLUGIN_URL . 'assets/js/daily-log.js',
					array( 'pltt-shared' ),
					$version,
					true
				);
			}
		}

		// Alias/keyword chip manager — clients (alias seeding) and tags (keyword
		// seeding) use the same widget in their create/edit modal. Projects rely
		// on client association + most-recent-used, so they have no seeding UI.
		if ( 'time-tracker_page_pltt-clients' === $hook || 'time-tracker_page_pltt-tags' === $hook ) {
			wp_enqueue_style(
				'pltt-alias-chips',
				PLTT_PLUGIN_URL . 'assets/css/alias-chips.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_script(
				'pltt-alias-chips',
				PLTT_PLUGIN_URL . 'assets/js/alias-chips.js',
				array( 'pltt-shared' ),
				$version,
				true
			);
		}

		if ( 'time-tracker_page_pltt-projects' === $hook && 'view' === $projects_action ) {
			// pltt-tooltip (CSS + JS) is enqueued globally above — it powers the
			// volume chart's hover tooltips here, and the ⓘ notes everywhere.
			wp_enqueue_style(
				'pltt-chart',
				PLTT_PLUGIN_URL . 'assets/css/pltt-chart.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_style(
				'pltt-project-detail',
				PLTT_PLUGIN_URL . 'assets/css/project-detail.css',
				array( 'pltt-admin', 'pltt-tooltip', 'pltt-chart' ),
				$version
			);
		}

		// Billing styles: the surface (action=bill), the project page's
		// ready-to-invoice prompt + billing history (action=view), the
		// cross-project Invoicing queue, and the Reports single-project card's
		// Review & Invoice section all use them.
		$needs_billing_css = ( 'time-tracker_page_pltt-invoicing' === $hook )
			|| ( 'time-tracker_page_pltt-reports' === $hook )
			|| ( 'time-tracker_page_pltt-projects' === $hook
				&& ( 'bill' === $projects_action || 'view' === $projects_action ) );
		if ( $needs_billing_css ) {
			wp_enqueue_style(
				'pltt-billing',
				PLTT_PLUGIN_URL . 'assets/css/billing.css',
				array( 'pltt-admin' ),
				$version
			);
		}

		// Commit-in-a-modal for the Billing (Invoicing) queue. Reports/Insights is
		// Billing page queue, and Reports/Insights where the "Line items" copy modal
		// (billing-copy-dialog.php) is now a peer to Record bill. invoicing.js drives
		// the copy modal (open / source-swap / copy); it self-guards on the presence
		// of a .pltt-billing-form or [data-lineitems-dialog], so it no-ops otherwise
		// and doesn't clash with billing-select.js's commit flow.
		// Also the Project Detail report, whose billing-history table opens the same
		// "View record" dialog (invoicing.js handles open / source-swap / copy).
		if ( 'time-tracker_page_pltt-invoicing' === $hook
			|| 'time-tracker_page_pltt-reports' === $hook
			|| ( 'time-tracker_page_pltt-projects' === $hook && 'view' === $projects_action ) ) {
			wp_enqueue_script(
				'pltt-invoicing',
				PLTT_PLUGIN_URL . 'assets/js/invoicing.js',
				array( 'pltt-shared' ),
				$version,
				true
			);
		}

		// Shared date-navigator widget: the Overview filter bar and the Billing
		// history filter bar both render templates/partials/date-nav.php.
		if ( in_array( $hook, array( 'time-tracker_page_pltt-invoicing', 'time-tracker_page_pltt-reports' ), true ) ) {
			wp_enqueue_script(
				'pltt-date-nav',
				PLTT_PLUGIN_URL . 'assets/js/pltt-date-nav.js',
				array(),
				$version,
				true
			);
		}

		if ( 'time-tracker_page_pltt-reports' === $hook ) {
			wp_enqueue_style(
				'pltt-tag-picker',
				PLTT_PLUGIN_URL . 'assets/css/tag-picker.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_style(
				'pltt-chart',
				PLTT_PLUGIN_URL . 'assets/css/pltt-chart.css',
				array( 'pltt-admin' ),
				$version
			);
			wp_enqueue_style(
				'pltt-reports',
				PLTT_PLUGIN_URL . 'assets/css/reports.css',
				array( 'pltt-admin', 'pltt-tag-picker' ),
				$version
			);
			wp_enqueue_script(
				'pltt-tag-picker',
				PLTT_PLUGIN_URL . 'assets/js/tag-picker.js',
				array(),
				$version,
				true
			);
			// pltt-tooltip is enqueued globally; reports.js still deps on it.
			wp_enqueue_script(
				'pltt-reports',
				PLTT_PLUGIN_URL . 'assets/js/reports.js',
				array( 'pltt-shared', 'pltt-tag-picker', 'pltt-tooltip', 'pltt-date-nav' ),
				$version,
				true
			);
			// Billing from the detailed view: the "Include in bill" select row +
			// Record-bill modal on a single hourly project. Self-guards on its DOM.
			wp_enqueue_script(
				'pltt-billing-select',
				PLTT_PLUGIN_URL . 'assets/js/billing-select.js',
				array( 'pltt-shared' ),
				$version,
				true
			);
		}
	}

}

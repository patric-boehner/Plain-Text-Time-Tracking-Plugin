<?php
/**
 * Project billing fields — the "How is it billed?" type picker, its
 * type-specific settings (recurring period / budget), the hourly-rate group and
 * the non-billable toggle.
 *
 * Shared by the full Projects-page modal (templates/projects.php) and the inline
 * entry-editor "Add project" quick-add (templates/partials/entry-editor-modals.php)
 * so the type picker is defined once. The behaviour (show/hide per type, dynamic
 * descriptions) is driven by assets/js/project-type-picker.js, which reads the
 * same element IDs on either page.
 *
 * @package PlainLanguageTimeTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Compact mode (the entry-editor "Add project" quick-add): the type cards, plus
// the one number Fixed budget can't go without. The billing type sets the new
// entry's billable default, which is what matters mid-capture; the rest of the
// setting numbers are project setup, so they stay on the Projects page. Retainer
// auto-defaults to monthly with its allocation added later.
$pltt_compact = ! empty( $pltt_billing_compact );
?>
<p>
	<label id="pltt-project-billing-type-label"><?php esc_html_e( 'How is it billed?', 'plain-language-time-tracker' ); ?></label>
	<span class="pltt-typepick" role="radiogroup" aria-labelledby="pltt-project-billing-type-label">
		<button type="button" class="pltt-typecard" data-type="hourly" role="radio" aria-checked="false">
			<span class="pltt-typecard-t"><span class="pltt-typecard-dot" aria-hidden="true"></span><?php esc_html_e( 'Hourly', 'plain-language-time-tracker' ); ?></span>
			<span class="pltt-typecard-d"><?php esc_html_e( 'Bill for time at an hourly rate. Every entry is billable by default.', 'plain-language-time-tracker' ); ?></span>
		</button>
		<button type="button" class="pltt-typecard" data-type="recurring" role="radio" aria-checked="false">
			<span class="pltt-typecard-t"><span class="pltt-typecard-dot" aria-hidden="true"></span><?php esc_html_e( 'Monthly retainer', 'plain-language-time-tracker' ); ?></span>
			<span class="pltt-typecard-d"><?php esc_html_e( 'A set allocation of hours each period. Time is covered; only overage is billable.', 'plain-language-time-tracker' ); ?></span>
		</button>
		<button type="button" class="pltt-typecard" data-type="fixed" role="radio" aria-checked="false">
			<span class="pltt-typecard-t"><span class="pltt-typecard-dot" aria-hidden="true"></span><?php esc_html_e( 'Fixed budget', 'plain-language-time-tracker' ); ?></span>
			<span class="pltt-typecard-d"><?php esc_html_e( 'A total project budget. Track burn; bill on your own schedule, non-billable by default.', 'plain-language-time-tracker' ); ?></span>
		</button>
		<button type="button" class="pltt-typecard" data-type="none" role="radio" aria-checked="false">
			<span class="pltt-typecard-t"><span class="pltt-typecard-dot" aria-hidden="true"></span><?php esc_html_e( 'Internal', 'plain-language-time-tracker' ); ?></span>
			<span class="pltt-typecard-d"><?php esc_html_e( 'Your own work. Tracked for insight, never billed.', 'plain-language-time-tracker' ); ?></span>
		</button>
	</span>
	<?php // Value holder read/written by the modal JS; type itself isn't submitted — it's derived server-side from the fields below. ?>
	<input type="hidden" id="pltt-project-billing-type" value="hourly">
	<?php if ( $pltt_compact ) : ?>
		<small class="description"><?php esc_html_e( 'Set the retainer allocation and the rate later, in Projects.', 'plain-language-time-tracker' ); ?></small>
	<?php endif; ?>
</p>

<?php if ( $pltt_compact ) : ?>
	<?php
	// A project counts as Fixed budget only once it has a budget number — the type
	// is derived from the budget, never stored on its own (pltt_get_billing_type()).
	// Without a number here, picking Fixed would save an internal project instead,
	// so the quick-add asks for the total fee and nothing else. Hour-based budgets
	// are a Projects-page switch.
	?>
	<div id="pltt-project-budget-compact" class="pltt-billing-settings pltt-hidden">
		<label for="pltt-project-budget-fee"><?php esc_html_e( 'Total Fee ($)', 'plain-language-time-tracker' ); ?></label>
		<div class="pltt-input-adornment-wrap">
			<span class="pltt-adornment pltt-adornment-prefix">$</span>
			<input type="text" inputmode="decimal" id="pltt-project-budget-fee" name="budget_fee" class="widefat pltt-currency-input" placeholder="0.00">
		</div>
		<p class="description"><?php esc_html_e( 'Budget by hours instead? Create it here, then switch it in Projects.', 'plain-language-time-tracker' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( $pltt_compact ) { return; } // Quick-add stops here: no allocation, rate, or non-billable toggle. ?>

<!-- Type-specific settings box: shown for Fixed Fee and Recurring only -->
<div id="pltt-project-billing-settings" class="pltt-billing-settings pltt-hidden">
	<p class="pltt-billing-settings-label" id="pltt-billing-settings-title"></p>
	<div id="pltt-billing-settings-fields">
		<div id="pltt-project-recurring-group" class="pltt-hidden">
			<label for="pltt-project-recurring-period"><?php esc_html_e( 'Recurring Period', 'plain-language-time-tracker' ); ?></label>
			<select id="pltt-project-recurring-period" name="recurring_period" class="widefat">
				<option value=""><?php esc_html_e( '— None', 'plain-language-time-tracker' ); ?></option>
				<option value="monthly"><?php esc_html_e( 'Monthly', 'plain-language-time-tracker' ); ?></option>
			</select>
		</div>
		<div id="pltt-project-budget-mode-group" class="pltt-hidden">
			<label for="pltt-project-budget-mode"><?php esc_html_e( 'Track Budget By', 'plain-language-time-tracker' ); ?></label>
			<select id="pltt-project-budget-mode" class="widefat">
				<option value="hours"><?php esc_html_e( 'Hours', 'plain-language-time-tracker' ); ?></option>
				<option value="fee"><?php esc_html_e( 'Project Fee', 'plain-language-time-tracker' ); ?></option>
			</select>
		</div>
		<div id="pltt-project-budget-value-group">
			<div id="pltt-budget-hours-wrap">
				<label id="pltt-project-budget-label" for="pltt-project-budget-hours"><?php esc_html_e( 'Hour Budget', 'plain-language-time-tracker' ); ?> <span class="pltt-optional"><?php esc_html_e( '(optional)', 'plain-language-time-tracker' ); ?></span></label>
				<div class="pltt-input-adornment-wrap">
				<input type="number" id="pltt-project-budget-hours" name="budget_hours" step="0.5" min="0" class="widefat" placeholder="0">
				<span class="pltt-adornment pltt-adornment-suffix">hr</span>
			</div>
			</div>
			<div id="pltt-budget-fee-wrap" class="pltt-hidden">
				<label for="pltt-project-budget-fee"><?php esc_html_e( 'Total Fee ($)', 'plain-language-time-tracker' ); ?> <span class="pltt-optional"><?php esc_html_e( '(optional)', 'plain-language-time-tracker' ); ?></span></label>
				<div class="pltt-input-adornment-wrap">
				<span class="pltt-adornment pltt-adornment-prefix">$</span>
				<input type="text" inputmode="decimal" id="pltt-project-budget-fee" name="budget_fee" class="widefat pltt-currency-input" placeholder="0.00">
			</div>
			</div>
		</div>
	</div>
	<p class="description" id="pltt-billing-settings-desc"></p>
</div>

<hr class="pltt-form-separator">

<p id="pltt-project-rate-group">
	<label for="pltt-project-rate"><?php esc_html_e( 'Hourly Rate', 'plain-language-time-tracker' ); ?> <span class="pltt-optional"><?php esc_html_e( '(optional)', 'plain-language-time-tracker' ); ?></span></label>
	<div class="pltt-input-adornment-wrap">
	<span class="pltt-adornment pltt-adornment-prefix">$</span>
	<input type="text" inputmode="decimal" id="pltt-project-rate" name="hourly_rate" class="widefat pltt-currency-input" placeholder="0.00">
</div>
	<small class="description" id="pltt-rate-description"><?php esc_html_e( 'Leave blank to use client rate.', 'plain-language-time-tracker' ); ?></small>
</p>
<p id="pltt-project-nonbillable-group">
	<label>
		<input type="checkbox" id="pltt-project-non-billable" name="non_billable" value="1">
		<?php esc_html_e( 'Non-billable project', 'plain-language-time-tracker' ); ?>
	</label>
	<small class="description" id="pltt-nonbillable-description"><?php esc_html_e( 'Entries default to non-billable. Can be overridden per entry.', 'plain-language-time-tracker' ); ?></small>
</p>

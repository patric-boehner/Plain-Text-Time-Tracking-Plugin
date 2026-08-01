/**
 * Project billing-type picker — shared behaviour for the "How is it billed?"
 * card group + its type-specific settings.
 *
 * Drives the markup in templates/partials/project-billing-fields.php on BOTH the
 * full Projects-page modal and the inline entry-editor "Add project" quick-add,
 * so the show/hide-per-type logic lives in one place. Operates on the modal's
 * fixed element IDs (only one project modal exists per page). Translated strings
 * come from plttData.projectType (localized in PHP).
 *
 * Public API (window.PlttProjectType):
 *   init()                    Wire card clicks + budget-mode change (idempotent).
 *   select(type, setDefaults) Select a type card + apply its field UI.
 *   reset()                   Clear the fields and select Hourly (quick-add open).
 *   getValues()               Read the fields for a pltt_create_project AJAX call.
 */
( function () {
	'use strict';

	function el( id ) {
		return document.getElementById( id );
	}

	// Localized copy (falls back to English so a missing localize can't crash the UI).
	var S = ( window.plttData && plttData.projectType ) || {};
	var RATE = S.rate || {
		hourly:    'Leave blank to use client rate.',
		fixed:     'Used to calculate implied effective rate. Leave blank to use client rate.',
		recurring: 'Used for overage billing. Leave blank to use client rate.',
		none:      'Not applicable for internal projects.'
	};
	var NONBILL = S.nonbillable || {
		hourly:    'Entries default to billable at this rate. Check the box to default new entries to non-billable instead.',
		fixed:     'Entries default to non-billable — you decide when and what to bill against the budget.',
		recurring: 'Time within the allocation is non-billable, covered by the retainer. Hours over the plan are billed as overage.',
		none:      'Internal work is never billed — it still shows in the Overview so you can see where your own time goes.'
	};
	var TXT = {
		fixedTitle:     S.fixedTitle || 'FIXED BUDGET SETTINGS',
		hourBudget:     S.hourBudget || 'Hour Budget ',
		fixedDesc:      S.fixedDesc || 'Entries default to non-billable — you decide when and what to bill against the budget.',
		recurringTitle: S.recurringTitle || 'RECURRING SETTINGS',
		hourAllocation: S.hourAllocation || 'Hour Allocation ',
		recurringDesc:  S.recurringDesc || 'Hours included per period. Time within the allocation is covered by the retainer; hours over the plan are billed as overage when you invoice.'
	};

	function applyBudgetModeUI( mode ) {
		var hoursWrap  = el( 'pltt-budget-hours-wrap' );
		var feeWrap    = el( 'pltt-budget-fee-wrap' );
		var hoursInput = el( 'pltt-project-budget-hours' );
		var feeInput   = el( 'pltt-project-budget-fee' );
		if ( ! hoursWrap || ! feeWrap ) {
			return;
		}
		if ( mode === 'fee' ) {
			hoursWrap.classList.add( 'pltt-hidden' );
			feeWrap.classList.remove( 'pltt-hidden' );
			hoursInput.disabled = true;
			feeInput.disabled   = false;
		} else {
			hoursWrap.classList.remove( 'pltt-hidden' );
			feeWrap.classList.add( 'pltt-hidden' );
			hoursInput.disabled = false;
			feeInput.disabled   = true;
		}
	}

	function applyBillingTypeUI( type, setDefaults ) {
		var rateField        = el( 'pltt-project-rate' );
		var rateGroup        = el( 'pltt-project-rate-group' );
		var settingsBox      = el( 'pltt-project-billing-settings' );
		var settingsTitle    = el( 'pltt-billing-settings-title' );
		var settingsFields   = el( 'pltt-billing-settings-fields' );
		var settingsDesc     = el( 'pltt-billing-settings-desc' );
		var recurringGroup   = el( 'pltt-project-recurring-group' );
		var recurringSelect  = el( 'pltt-project-recurring-period' );
		var budgetModeGroup  = el( 'pltt-project-budget-mode-group' );
		var budgetLabel      = el( 'pltt-project-budget-label' );
		var nonBillableGroup = el( 'pltt-project-nonbillable-group' );
		var nonBillable      = el( 'pltt-project-non-billable' );
		var hoursInput       = el( 'pltt-project-budget-hours' );
		var feeInput         = el( 'pltt-project-budget-fee' );

		// Compact quick-add (entry editor) renders only the type cards — no
		// settings box, rate, or non-billable toggle. Once the card selection is
		// recorded there's nothing else to toggle; getValues() derives the
		// type-driving fields from the chosen type.
		if ( ! settingsBox ) {
			return;
		}

		// Reset disabled/greyed states first.
		rateField.disabled = false;
		rateGroup.classList.remove( 'pltt-field-disabled' );
		nonBillableGroup.classList.remove( 'pltt-field-disabled' );
		// Default the per-entry billable setting visible; retainer/fixed-fee hide it
		// below (their billing is computed at the period level, not per entry).
		nonBillableGroup.classList.remove( 'pltt-hidden' );

		// Update dynamic descriptions.
		el( 'pltt-rate-description' ).textContent = RATE[ type ];
		el( 'pltt-nonbillable-description' ).textContent = NONBILL[ type ];

		if ( type === 'hourly' ) {
			settingsBox.classList.add( 'pltt-hidden' );
			hoursInput.disabled      = true;
			feeInput.disabled        = true;
			recurringSelect.disabled = true;
			hoursInput.required      = false;
			feeInput.required        = false;
			if ( setDefaults ) { nonBillable.checked = false; }

		} else if ( type === 'fixed' ) {
			settingsBox.classList.remove( 'pltt-hidden' );
			settingsTitle.textContent = TXT.fixedTitle;
			settingsFields.classList.add( 'pltt-grid' );
			recurringGroup.classList.add( 'pltt-hidden' );
			budgetModeGroup.classList.remove( 'pltt-hidden' );
			budgetLabel.firstChild.textContent = TXT.hourBudget;
			settingsDesc.textContent = TXT.fixedDesc;
			recurringSelect.disabled = true;
			hoursInput.required      = true;
			feeInput.required        = true;
			var hoursOptional = budgetLabel.querySelector( '.pltt-optional' );
			if ( hoursOptional ) { hoursOptional.classList.add( 'pltt-hidden' ); }
			var feeLabel = el( 'pltt-budget-fee-wrap' ).querySelector( 'label' );
			var feeOptional = feeLabel ? feeLabel.querySelector( '.pltt-optional' ) : null;
			if ( feeOptional ) { feeOptional.classList.add( 'pltt-hidden' ); }
			// Infer mode from current values so switching away and back preserves the user's entry.
			var budgetMode = ( feeInput.value !== '' ) ? 'fee' : 'hours';
			el( 'pltt-project-budget-mode' ).value = budgetMode;
			applyBudgetModeUI( budgetMode );
			// Fixed-fee dollars come from the flat fee, not time × rate — force entries
			// non-billable and hide the per-entry billable setting (it does nothing here).
			// Forced unconditionally, not just on setDefaults: the control is hidden, so
			// an edit-modal open that seeded it from a stale billability_default = 1
			// would silently re-submit that 1 on every save, and new entries would
			// inherit the flag. Same reasoning as the 'none' branch below.
			nonBillable.checked = true;
			nonBillableGroup.classList.add( 'pltt-hidden' );

		} else if ( type === 'recurring' ) {
			settingsBox.classList.remove( 'pltt-hidden' );
			settingsTitle.textContent = TXT.recurringTitle;
			settingsFields.classList.add( 'pltt-grid' );
			recurringGroup.classList.remove( 'pltt-hidden' );
			budgetModeGroup.classList.add( 'pltt-hidden' );
			el( 'pltt-project-budget-mode' ).value = 'hours';
			budgetLabel.firstChild.textContent = TXT.hourAllocation;
			settingsDesc.textContent = TXT.recurringDesc;
			recurringSelect.disabled = false;
			feeInput.disabled        = true;
			hoursInput.disabled      = false;
			hoursInput.required      = false;
			feeInput.required        = false;
			var hoursOptional2 = budgetLabel.querySelector( '.pltt-optional' );
			if ( hoursOptional2 ) { hoursOptional2.classList.remove( 'pltt-hidden' ); }
			if ( setDefaults && recurringSelect.value === '' ) { recurringSelect.value = 'monthly'; }
			// Within-allocation time is covered by the flat fee and overage is billed at
			// the period level — the per-entry billable setting does nothing here. Force
			// it off (unconditionally, see the 'fixed' branch above for why) and hide it.
			nonBillable.checked = true;
			nonBillableGroup.classList.add( 'pltt-hidden' );

		} else if ( type === 'none' ) {
			settingsBox.classList.add( 'pltt-hidden' );
			rateField.disabled = true;
			rateGroup.classList.add( 'pltt-field-disabled' );
			nonBillable.checked = true;
			nonBillableGroup.classList.add( 'pltt-field-disabled' ); // visual lock only; not HTML disabled so it still submits
			hoursInput.disabled      = true;
			feeInput.disabled        = true;
			recurringSelect.disabled = true;
			hoursInput.required      = false;
			feeInput.required        = false;
		}
	}

	// Billing-type card picker. The hidden #pltt-project-billing-type input stays
	// the source of truth (the rest of the modal JS reads/writes its .value);
	// selecting a card updates that value, the cards' selected state, and the
	// field UI in one place.
	function selectBillingType( type, setDefaults ) {
		var hidden = el( 'pltt-project-billing-type' );
		if ( ! hidden ) {
			return;
		}
		hidden.value = type;
		document.querySelectorAll( '.pltt-typecard' ).forEach( function ( card ) {
			var on = card.dataset.type === type;
			card.classList.toggle( 'is-selected', on );
			card.setAttribute( 'aria-checked', on ? 'true' : 'false' );
		} );
		applyBillingTypeUI( type, setDefaults );
	}

	// Wire card clicks + budget-mode change. Idempotent: guards on a dataset flag
	// so calling init() from more than one script (Projects page, entry editor)
	// binds the handlers only once.
	function init() {
		var group = document.querySelector( '.pltt-typepick' );
		if ( ! group || group.dataset.plttWired === '1' ) {
			return;
		}
		group.dataset.plttWired = '1';

		document.querySelectorAll( '.pltt-typecard' ).forEach( function ( card ) {
			card.addEventListener( 'click', function () {
				selectBillingType( this.dataset.type, true );
			} );
		} );

		var budgetModeSelect = el( 'pltt-project-budget-mode' );
		if ( budgetModeSelect ) {
			budgetModeSelect.addEventListener( 'change', function () {
				applyBudgetModeUI( this.value );
			} );
		}
	}

	// Clear the project fields and select Hourly — used when opening the quick-add.
	function reset() {
		[ 'pltt-project-name', 'pltt-project-rate', 'pltt-project-budget-hours', 'pltt-project-budget-fee' ].forEach( function ( id ) {
			var node = el( id );
			if ( node ) { node.value = ''; }
		} );
		var recur = el( 'pltt-project-recurring-period' );
		if ( recur ) { recur.value = ''; }
		selectBillingType( 'hourly', true );
	}

	// Read the fields a pltt_create_project call needs.
	function getValues() {
		function val( id ) {
			var node = el( id );
			return node ? node.value : '';
		}
		var typeEl = el( 'pltt-project-billing-type' );
		var type = typeEl ? typeEl.value : 'hourly';
		var recurEl = el( 'pltt-project-recurring-period' );
		var nb = el( 'pltt-project-non-billable' );
		return {
			name: val( 'pltt-project-name' ).trim(),
			hourly_rate: val( 'pltt-project-rate' ),
			budget_hours: val( 'pltt-project-budget-hours' ),
			budget_fee: val( 'pltt-project-budget-fee' ),
			// When the settings inputs aren't rendered (compact quick-add), derive
			// the type-driving values from the chosen card: retainer → monthly,
			// internal → non-billable.
			recurring_period: recurEl ? recurEl.value : ( type === 'recurring' ? 'monthly' : '' ),
			non_billable: nb ? ( nb.checked ? '1' : '0' ) : ( type === 'none' ? '1' : '0' )
		};
	}

	window.PlttProjectType = {
		init: init,
		select: selectBillingType,
		reset: reset,
		getValues: getValues
	};

	// Self-wire the card group as soon as the DOM is ready, so neither the
	// Projects-page inline script nor review.js has to call init() (and so it
	// works regardless of script load order).
	if ( document.readyState !== 'loading' ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
}() );

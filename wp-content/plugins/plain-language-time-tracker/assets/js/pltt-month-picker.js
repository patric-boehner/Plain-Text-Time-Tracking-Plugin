/**
 * Month picker widget — shared behaviour for templates/partials/month-picker.php.
 *
 * Open/close, keyboard navigation, and the year switcher. Used by History's month
 * browser and a retainer project's period filter, which is why this lives here
 * rather than in either screen's own script.
 *
 * Selection is deliberately NOT uniform, because the two screens address a period
 * differently:
 *   • options rendered as <a href> navigate on their own — no handler needed
 *   • options rendered as <button data-from data-to> write the hidden
 *     #pltt-date-from / #pltt-date-to pair and submit the enclosing form
 * Only the second needs code, and it is guarded so a link-only picker (or a page
 * with no hidden inputs) is fine.
 *
 * Not to be confused with pltt-date-nav.js, which drives the preset + custom-range
 * navigator on Reports and Billing. Same .pltt-date-nav CSS, different question:
 * that one picks an arbitrary range, this one picks one month.
 */
( function () {
	'use strict';

	var widget = document.querySelector( '.pltt-date-nav' );
	if ( ! widget ) {
		return;
	}

	var trigger  = document.getElementById( 'pltt-date-nav-trigger' );
	var dropdown = widget.querySelector( '.pltt-date-nav-dropdown' );
	if ( ! trigger || ! dropdown ) {
		return;
	}

	// A month picker always renders year groups. Without them this is the OTHER
	// widget (pltt-date-nav.js's preset navigator) and we must not double-bind it.
	if ( ! dropdown.querySelector( '.pltt-date-nav-year-months' ) ) {
		return;
	}

	var switcher = widget.querySelector( '.pltt-date-nav-year-switcher' );

	// ── Dropdown open / close ────────────────────────────────────────────

	function getOptions() {
		// Only the options you can actually see: the lead option (e.g. "All time")
		// plus the visible year's months. Hidden years must not swallow arrow keys.
		var lead    = Array.from( dropdown.querySelectorAll( '.pltt-date-nav-lead .pltt-date-nav-option' ) );
		var visible = dropdown.querySelector( '.pltt-date-nav-year-months:not([hidden])' );
		var months  = visible ? Array.from( visible.querySelectorAll( '.pltt-date-nav-option' ) ) : [];
		return lead.concat( months );
	}

	function openDropdown() {
		dropdown.hidden = false;
		trigger.setAttribute( 'aria-expanded', 'true' );
		var selected = dropdown.querySelector( '.pltt-date-nav-option[aria-current="true"]' )
			|| getOptions()[ 0 ];
		if ( selected ) {
			selected.focus();
		}
	}

	function closeDropdown() {
		dropdown.hidden = true;
		trigger.setAttribute( 'aria-expanded', 'false' );
	}

	trigger.addEventListener( 'click', function () {
		if ( dropdown.hidden ) {
			openDropdown();
		} else {
			closeDropdown();
		}
	} );

	trigger.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			openDropdown();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && ! dropdown.hidden ) {
			closeDropdown();
			trigger.focus();
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( ! widget.contains( e.target ) && ! dropdown.hidden ) {
			closeDropdown();
		}
	} );

	// ── Option keyboard navigation ───────────────────────────────────────

	dropdown.addEventListener( 'keydown', function ( e ) {
		var options = getOptions();
		var idx     = options.indexOf( document.activeElement );

		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			if ( idx < options.length - 1 ) {
				options[ idx + 1 ].focus();
			}
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			if ( idx > 0 ) {
				options[ idx - 1 ].focus();
			}
		} else if ( e.key === 'Home' ) {
			e.preventDefault();
			if ( options[ 0 ] ) {
				options[ 0 ].focus();
			}
		} else if ( e.key === 'End' ) {
			e.preventDefault();
			if ( options[ options.length - 1 ] ) {
				options[ options.length - 1 ].focus();
			}
		} else if ( e.key === 'Tab' ) {
			// Let Tab move naturally; close if focus leaves the widget entirely.
			setTimeout( function () {
				if ( ! widget.contains( document.activeElement ) ) {
					closeDropdown();
				}
			}, 0 );
		}
	} );

	// ── Form-submitting options (History) ────────────────────────────────
	// Link options need nothing here — the browser follows the href.

	var fromInput = document.getElementById( 'pltt-date-from' );
	var toInput   = document.getElementById( 'pltt-date-to' );

	if ( fromInput && toInput && fromInput.form ) {
		dropdown.querySelectorAll( '.pltt-date-nav-option[data-from]' ).forEach( function ( opt ) {
			opt.addEventListener( 'click', function () {
				fromInput.value = this.dataset.from;
				toInput.value   = this.dataset.to;
				closeDropdown();
				fromInput.form.submit();
			} );
		} );
	}

	// ── Year switcher ────────────────────────────────────────────────────

	if ( switcher ) {
		var yearGroups  = Array.from( dropdown.querySelectorAll( '.pltt-date-nav-year-months' ) );
		var yearLabel   = switcher.querySelector( '.pltt-date-nav-year-label' );
		var prevYearBtn = switcher.querySelector( '.pltt-date-nav-year-prev' );
		var nextYearBtn = switcher.querySelector( '.pltt-date-nav-year-next' );

		var getActiveGroup = function () {
			return dropdown.querySelector( '.pltt-date-nav-year-months:not([hidden])' );
		};

		var showYear = function ( targetGroup ) {
			yearGroups.forEach( function ( g ) { g.hidden = true; } );
			targetGroup.hidden    = false;
			yearLabel.textContent = targetGroup.dataset.year;
			switcher.dataset.year = targetGroup.dataset.year;

			// yearGroups are ordered newest-first, matching the PHP output.
			var idx = yearGroups.indexOf( targetGroup );
			prevYearBtn.disabled = ( idx >= yearGroups.length - 1 );
			nextYearBtn.disabled = ( idx <= 0 );
		};

		prevYearBtn.addEventListener( 'click', function () {
			var idx = yearGroups.indexOf( getActiveGroup() );
			if ( idx < yearGroups.length - 1 ) {
				showYear( yearGroups[ idx + 1 ] );
				var first = getOptions()[ 0 ];
				if ( first ) { first.focus(); }
			}
		} );

		nextYearBtn.addEventListener( 'click', function () {
			var idx = yearGroups.indexOf( getActiveGroup() );
			if ( idx > 0 ) {
				showYear( yearGroups[ idx - 1 ] );
				var first = getOptions()[ 0 ];
				if ( first ) { first.focus(); }
			}
		} );

		// Set the initial arrow state.
		showYear( getActiveGroup() );
	}
}() );

/**
 * Shared Date Navigator widget.
 *
 * Handles preset selection, custom range input, and prev/next navigation for the
 * `.pltt-date-nav` component. Self-contained: it only reads its own markup and the
 * hidden <input id="pltt-date-from"> / <input id="pltt-date-to"> pair, then submits
 * the enclosing form — so any page that renders the widget inside a form (Overview,
 * Billing history) gets the behaviour with no page-specific glue.
 *
 * Rendered markup: templates/partials/date-nav.php.
 */
( function() {
	'use strict';

	var widget = document.querySelector( '.pltt-date-nav' );
	if ( ! widget ) {
		return;
	}

	var fromInput   = document.getElementById( 'pltt-date-from' );
	var toInput     = document.getElementById( 'pltt-date-to' );
	var trigger     = document.getElementById( 'pltt-date-nav-trigger' );
	var dropdown    = widget.querySelector( '.pltt-date-nav-dropdown' );
	var prevBtn     = widget.querySelector( '.pltt-date-nav-prev' );
	var nextBtn     = widget.querySelector( '.pltt-date-nav-next' );
	var customInputs = widget.querySelector( '.pltt-date-nav-custom-inputs' );
	var customFrom  = document.getElementById( 'pltt-date-custom-from' );
	var customTo    = document.getElementById( 'pltt-date-custom-to' );
	var applyBtn    = widget.querySelector( '.pltt-date-nav-custom-apply' );
	var weekStart   = parseInt( widget.dataset.weekStart || '0', 10 );

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Parse a YYYY-MM-DD string into a local Date (no timezone shift).
	 */
	function parseDate( str ) {
		var parts = str.split( '-' );
		return new Date( parseInt( parts[0], 10 ), parseInt( parts[1], 10 ) - 1, parseInt( parts[2], 10 ) );
	}

	/**
	 * Format a Date as YYYY-MM-DD.
	 */
	function fmtDate( d ) {
		var y  = d.getFullYear();
		var m  = String( d.getMonth() + 1 ).padStart( 2, '0' );
		var dy = String( d.getDate() ).padStart( 2, '0' );
		return y + '-' + m + '-' + dy;
	}

	/**
	 * Detect the step unit for navigation based on the current range.
	 * Returns { unit: 'month'|'week'|'year'|'days', days: N }
	 *
	 * Preset ranges like "This Week"/"This Month"/"This Year" are PARTIAL —
	 * they run from the period start through today, not to the period end.
	 * We still treat those as week/month/year so stepping snaps to the full
	 * adjacent period rather than sliding an odd-length window. Order matters:
	 * week is checked before month so a week beginning on the 1st of a month
	 * isn't misread as a month.
	 */
	function detectStep( from, to ) {
		var f = parseDate( from );
		var t = parseDate( to );
		var diffMs   = t - f;
		var totalDays = Math.round( diffMs / 86400000 ) + 1;

		// Week: starts on the configured week-start day and spans no more than
		// 7 days (full "Last Week" = 7 days, partial "This Week" = fewer).
		if ( f.getDay() === weekStart && totalDays <= 7 ) {
			return { unit: 'week' };
		}

		// Year: starts Jan 1 and extends beyond January (full year, or partial
		// "This Year" = Jan 1 → today). A range confined to January is a month.
		if ( f.getMonth() === 0 && f.getDate() === 1 &&
			( t.getFullYear() > f.getFullYear() || t.getMonth() > 0 ) ) {
			return { unit: 'year' };
		}

		// Month: starts on the 1st and stays within that same month (full month,
		// or partial "This Month" = 1st → today).
		if ( f.getDate() === 1 &&
			t.getFullYear() === f.getFullYear() && t.getMonth() === f.getMonth() ) {
			return { unit: 'month' };
		}

		return { unit: 'days', days: totalDays };
	}

	/**
	 * Shift a date range by one step in the given direction (+1 or -1).
	 * Returns { from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' }.
	 */
	function shiftRange( from, to, direction ) {
		var step = detectStep( from, to );
		var f    = parseDate( from );
		var t    = parseDate( to );

		if ( step.unit === 'month' ) {
			// Shift by 1 month; result is always a full month.
			var newMonth = f.getMonth() + direction;
			var newYear  = f.getFullYear();
			if ( newMonth < 0 ) { newMonth = 11; newYear--; }
			if ( newMonth > 11 ) { newMonth = 0; newYear++; }
			var newFrom = new Date( newYear, newMonth, 1 );
			var newTo   = new Date( newYear, newMonth + 1, 0 ); // last day of new month
			return { from: fmtDate( newFrom ), to: fmtDate( newTo ) };
		}

		if ( step.unit === 'week' ) {
			// `from` is guaranteed to sit on the week-start day, so shifting it
			// by whole weeks and adding 6 days always yields a full week — even
			// when the current range was a partial "This Week".
			var offset  = direction * 7;
			var wFrom   = new Date( f.getFullYear(), f.getMonth(), f.getDate() + offset );
			var wTo     = new Date( wFrom.getFullYear(), wFrom.getMonth(), wFrom.getDate() + 6 );
			return { from: fmtDate( wFrom ), to: fmtDate( wTo ) };
		}

		if ( step.unit === 'year' ) {
			var yFrom = new Date( f.getFullYear() + direction, 0, 1 );
			var yTo   = new Date( f.getFullYear() + direction, 11, 31 );
			return { from: fmtDate( yFrom ), to: fmtDate( yTo ) };
		}

		// Arbitrary range: shift by full range duration.
		var dOffset = direction * step.days;
		var dFrom   = new Date( f.getFullYear(), f.getMonth(), f.getDate() + dOffset );
		var dTo     = new Date( t.getFullYear(), t.getMonth(), t.getDate() + dOffset );
		return { from: fmtDate( dFrom ), to: fmtDate( dTo ) };
	}

	/**
	 * Apply a new date range: update hidden inputs, label, and submit.
	 */
	function applyRange( from, to ) {
		fromInput.value = from;
		toInput.value   = to;
		closeDropdown();
		fromInput.form.submit();
	}

	// ── Dropdown open / close ────────────────────────────────────────────

	function openDropdown() {
		dropdown.hidden = false;
		trigger.setAttribute( 'aria-expanded', 'true' );
		// Focus the selected option or the first option.
		var selected = dropdown.querySelector( '.pltt-date-nav-option[aria-current="true"]' )
			|| dropdown.querySelector( '.pltt-date-nav-option' );
		if ( selected ) {
			selected.focus();
		}
	}

	function closeDropdown() {
		dropdown.hidden = true;
		trigger.setAttribute( 'aria-expanded', 'false' );
	}

	trigger.addEventListener( 'click', function() {
		if ( dropdown.hidden ) {
			openDropdown();
		} else {
			closeDropdown();
		}
	} );

	trigger.addEventListener( 'keydown', function( e ) {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			openDropdown();
		}
	} );

	// Close on Escape or outside click.
	document.addEventListener( 'keydown', function( e ) {
		if ( e.key === 'Escape' && ! dropdown.hidden ) {
			closeDropdown();
			trigger.focus();
		}
	} );

	document.addEventListener( 'click', function( e ) {
		if ( ! widget.contains( e.target ) && ! dropdown.hidden ) {
			closeDropdown();
		}
	} );

	// ── Option keyboard navigation ───────────────────────────────────────

	dropdown.addEventListener( 'keydown', function( e ) {
		var options = Array.from( dropdown.querySelectorAll( '.pltt-date-nav-option' ) );
		var focused = document.activeElement;
		var idx     = options.indexOf( focused );

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
			if ( options[0] ) {
				options[0].focus();
			}
		} else if ( e.key === 'End' ) {
			e.preventDefault();
			if ( options[ options.length - 1 ] ) {
				options[ options.length - 1 ].focus();
			}
		} else if ( e.key === 'Tab' ) {
			// Allow Tab to move naturally; close dropdown if focus leaves widget.
			setTimeout( function() {
				if ( ! widget.contains( document.activeElement ) ) {
					closeDropdown();
				}
			}, 0 );
		}
	} );

	// ── Preset option click ──────────────────────────────────────────────

	dropdown.querySelectorAll( '.pltt-date-nav-option[data-from]' ).forEach( function( opt ) {
		opt.addEventListener( 'click', function() {
			applyRange( this.dataset.from, this.dataset.to );
		} );
	} );

	// Custom date validation.
	if ( customFrom && customTo ) {
		customFrom.addEventListener( 'change', function() {
			if ( customTo.value && this.value > customTo.value ) {
				customTo.value = this.value;
			}
		} );
		customTo.addEventListener( 'change', function() {
			if ( customFrom.value && this.value < customFrom.value ) {
				customFrom.value = this.value;
			}
		} );
	}

	// Apply button.
	if ( applyBtn ) {
		applyBtn.addEventListener( 'click', function() {
			if ( customFrom && customTo && customFrom.value && customTo.value ) {
				applyRange( customFrom.value, customTo.value );
			}
		} );
	}
} )();

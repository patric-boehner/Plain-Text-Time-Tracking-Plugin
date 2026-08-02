/**
 * Finalize-screen consumption indicator.
 *
 * Shows how much of a project's ceiling is already used, right where entries get
 * assigned to projects — so the month-end figure is familiar by the time it gets
 * invoiced instead of being met once, under time pressure.
 *
 * Retainer:   "2.1 of 3h, 5 days in"
 * Fixed fee:  "5.4 of 5h"          (cumulative, no period fragment)
 * Everything else (hourly, internal, a retainer with no allocation): nothing.
 *
 * DISPLAY ONLY. Nothing here writes, submits, or changes an entry.
 *
 * ── Where the numbers come from ──────────────────────────────────────────────
 *
 * Each project <option> carries the server's figures:
 *
 *   data-ceiling-minutes    the allocation; 0 means "no ceiling, render nothing"
 *   data-consumed-minutes   minutes already in the database for the period
 *   data-period-day/-days   retainer only — how far into the period we are
 *
 * They are emitted by pltt_consumption_data_attrs() in PHP and rebuilt by all
 * four option builders in review.js. Miss one builder and the figure silently
 * vanishes once a project list is refreshed — exactly how the billable-flag bug
 * hid. Change PHP and all four together.
 *
 * ── Why the baseline gets adjusted ───────────────────────────────────────────
 *
 * data-consumed-minutes is a straight SUM from the database, and the entries on
 * the finalize screen are ALREADY IN IT — they get created at parse time, not at
 * "Save All". Counting the visible rows on top of that would double them.
 *
 * So each row publishes what it currently contributes to that SUM (its stored
 * project and stored duration), and seed() subtracts those ONCE at load. From
 * then on the figure is:
 *
 *     consumed − whatTheseRowsAlreadyContributed + whatTheyContributeNow
 *
 * Sealing the subtraction at load is what makes deletion work: removing a row
 * drops its live contribution, and the database row it deleted is already netted
 * out of the baseline. Nothing has to be recomputed.
 */
window.PlttConsumption = ( function() {
	'use strict';

	// projectId -> { ceiling, consumed, periodDay, periodDays }
	var known = {};

	// projectId -> minutes of on-screen rows already inside `consumed`. Sealed
	// after the first seed(); see the header note on deletion.
	var seeded = {};
	var isSeeded = false;

	/**
	 * Hours to one decimal, trailing ".0" trimmed: 180 -> "3", 126 -> "2.1".
	 *
	 * Deliberately coarser than PLTT.formatHours() (2dp) — this is a glanceable
	 * statement mid-flow, not an accounting figure.
	 *
	 * @param {number} minutes Duration in minutes.
	 * @return {string} Hours.
	 */
	function hours( minutes ) {
		var h = Math.round( ( Number( minutes ) || 0 ) / 6 ) / 10;
		return String( h );
	}

	/**
	 * Read every project option's figures into the lookup.
	 *
	 * Idempotent and additive — safe to call again after any option builder runs.
	 *
	 * @param {ParentNode} root Element (or document) to scan.
	 */
	function harvest( root ) {
		if ( ! root || ! root.querySelectorAll ) {
			return;
		}
		root.querySelectorAll( 'option[data-ceiling-minutes]' ).forEach( function( opt ) {
			var id = parseInt( opt.value, 10 );
			if ( ! id ) {
				return;
			}
			known[ id ] = {
				ceiling: parseInt( opt.dataset.ceilingMinutes, 10 ) || 0,
				consumed: parseInt( opt.dataset.consumedMinutes, 10 ) || 0,
				periodDay: parseInt( opt.dataset.periodDay, 10 ) || 0,
				periodDays: parseInt( opt.dataset.periodDays, 10 ) || 0
			};
		} );
	}

	/**
	 * Net the rows already on screen out of the baseline. First call wins.
	 *
	 * @param {Array} contributions [{ projectId, minutes }] as stored in the database.
	 */
	function seed( contributions ) {
		if ( isSeeded ) {
			return;
		}
		isSeeded = true;
		contributions.forEach( function( c ) {
			var id = parseInt( c.projectId, 10 );
			if ( ! id ) {
				return;
			}
			seeded[ id ] = ( seeded[ id ] || 0 ) + ( parseInt( c.minutes, 10 ) || 0 );
		} );
	}

	/**
	 * Build the line for a project given what this screen currently adds to it.
	 *
	 * @param {number} projectId   Selected project.
	 * @param {number} liveMinutes Minutes currently assigned to it on screen.
	 * @return {?Object} { text, over } or null when there is no ceiling.
	 */
	function describe( projectId, liveMinutes ) {
		var id = parseInt( projectId, 10 );
		var p = id ? known[ id ] : null;

		// No ceiling to be near — hourly, internal, or a retainer whose empty
		// allocation is deliberate. Render nothing at all, never "0 of 0".
		if ( ! p || p.ceiling <= 0 ) {
			return null;
		}

		var used = p.consumed - ( seeded[ id ] || 0 ) + ( liveMinutes || 0 );
		if ( used < 0 ) {
			used = 0;
		}

		var text = hours( used ) + ' of ' + hours( p.ceiling ) + 'h';

		// Retainers reset each period, so "how far in" is what makes the number
		// mean something. Fixed budgets are cumulative — no period fragment.
		if ( p.periodDay > 0 ) {
			text += ', ' + p.periodDay + ( 1 === p.periodDay ? ' day in' : ' days in' );
		}

		return { text: text, over: used > p.ceiling };
	}

	/**
	 * Write a line into a container (or empty it when there is nothing to say).
	 *
	 * @param {HTMLElement} el          Target .pltt-consumption element.
	 * @param {number}      projectId   Selected project.
	 * @param {number}      liveMinutes Minutes currently assigned to it on screen.
	 */
	function paint( el, projectId, liveMinutes ) {
		if ( ! el ) {
			return;
		}
		var d = describe( projectId, liveMinutes );
		if ( ! d ) {
			el.textContent = '';
			el.classList.remove( 'is-over' );
			el.hidden = true;
			return;
		}
		el.textContent = d.text;
		el.classList.toggle( 'is-over', d.over );
		el.hidden = false;
	}

	return {
		harvest: harvest,
		seed: seed,
		describe: describe,
		paint: paint
	};
}() );

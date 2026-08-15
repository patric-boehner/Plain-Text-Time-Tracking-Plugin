/**
 * Log Archive Screen JavaScript.
 *
 * Handles daily log deletion. The month navigation dropdown is shared —
 * see assets/js/pltt-month-picker.js.
 */

/* global plttData, PLTT */

( function() {
	'use strict';

	// The month navigator moved to assets/js/pltt-month-picker.js — the same
	// picker now serves this screen and the retainer project period filter.

	/**
	 * How bad is deleting this one? A log that produced entries takes them with
	 * it, so the confirmation says how many rather than asking in the abstract.
	 *
	 * @param {HTMLElement} row The log's <tr>.
	 * @return {string} Confirmation text.
	 */
	function deleteConfirmMessage( row ) {
		const entryCount = parseInt( row.dataset.entryCount, 10 ) || 0;
		if ( entryCount > 0 ) {
			return 'Delete this log and ' + entryCount + ' associated time ' +
				( entryCount === 1 ? 'entry' : 'entries' ) + '? This cannot be undone.';
		}
		return 'Delete this log? This cannot be undone.';
	}

	/**
	 * Drop a log and its entries, then take its row out of the table.
	 *
	 * @param {HTMLElement} row The log's <tr>.
	 */
	function deleteLog( row ) {
		const logDate = row.dataset.logDate;

		row.classList.add( 'pltt-deleting' );

		PLTT.ajax( 'pltt_delete_daily_log', {
			log_date: logDate
		}, function( response ) {
			if ( response.success ) {
				row.remove();

				// Update the total log count in summary card.
				const countEl = document.querySelector( '.pltt-card-value' );
				if ( countEl ) {
					const current = parseInt( countEl.textContent, 10 ) || 0;
					countEl.textContent = Math.max( 0, current - 1 );
				}

				// Update the pagination "X logs" text.
				const displayNum = document.querySelector( '.displaying-num' );
				if ( displayNum ) {
					const current = parseInt( displayNum.textContent, 10 ) || 0;
					const newCount = Math.max( 0, current - 1 );
					displayNum.textContent = newCount + ( newCount === 1 ? ' log' : ' logs' );
				}

				// If table body is now empty, reload the page to show empty state.
				const tbody = document.querySelector( '.widefat tbody' );
				if ( tbody && tbody.children.length === 0 ) {
					window.location.reload();
				}
			} else {
				row.classList.remove( 'pltt-deleting' );
				alert( response.data || plttData.i18n.error );
			}
		} );
	}

	// Row menu (spec §6).
	if ( typeof PlttRowMenu !== 'undefined' ) {
		PlttRowMenu.register( 'daily-log', function( trigger ) {
			const row = trigger.closest( 'tr' );
			const items = [];

			if ( trigger.dataset.viewUrl ) {
				items.push( { label: 'Open log', href: trigger.dataset.viewUrl } );
				items.push( { kind: 'separator' } );
			}

			items.push( {
				label: 'Delete log',
				kind: 'destructive',
				confirm: deleteConfirmMessage( row ),
				onSelect: function() {
					deleteLog( row );
				},
			} );

			return items;
		} );
	}

} )();

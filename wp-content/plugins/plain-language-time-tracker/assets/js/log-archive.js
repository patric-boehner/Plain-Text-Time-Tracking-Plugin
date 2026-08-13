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
	 * Delete log button handler (event delegation).
	 */
	document.addEventListener( 'click', function( e ) {
		const deleteLink = e.target.closest( '.pltt-delete-log' );
		if ( ! deleteLink ) {
			return;
		}

		e.preventDefault();

		const row = deleteLink.closest( 'tr' );
		if ( ! row ) {
			return;
		}

		const logDate = row.dataset.logDate;
		const entryCount = parseInt( row.dataset.entryCount, 10 ) || 0;

		// Build confirmation message.
		let message = plttData.i18n.confirm;
		if ( entryCount > 0 ) {
			message = 'Delete this log and ' + entryCount + ' associated time ' + ( entryCount === 1 ? 'entry' : 'entries' ) + '? This cannot be undone.';
		} else {
			message = 'Delete this log? This cannot be undone.';
		}

		if ( ! confirm( message ) ) {
			return;
		}

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
	} );

} )();

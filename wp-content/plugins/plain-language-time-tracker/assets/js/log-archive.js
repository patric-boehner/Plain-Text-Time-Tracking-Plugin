/**
 * Log Archive Screen JavaScript.
 *
 * Handles month navigation dropdown and daily log deletion.
 */

/* global plttData, PLTT */

( function() {
	'use strict';

	/**
	 * Month navigator widget.
	 *
	 * Handles the .pltt-date-nav dropdown: open/close, keyboard navigation,
	 * month option selection, and year switcher (when data spans multiple years).
	 */
	( function initMonthNav() {
		var widget   = document.querySelector( '.pltt-date-nav' );
		if ( ! widget ) {
			return;
		}

		var fromInput = document.getElementById( 'pltt-date-from' );
		var toInput   = document.getElementById( 'pltt-date-to' );
		var trigger   = document.getElementById( 'pltt-date-nav-trigger' );
		var dropdown  = widget.querySelector( '.pltt-date-nav-dropdown' );
		var switcher  = widget.querySelector( '.pltt-date-nav-year-switcher' );

		// ── Dropdown open / close ────────────────────────────────────────────

		function getOptions() {
			// Only options in the currently visible year group.
			var visibleGroup = dropdown.querySelector( '.pltt-date-nav-year-months:not([hidden])' );
			var scope        = visibleGroup || dropdown;
			return Array.from( scope.querySelectorAll( '.pltt-date-nav-option' ) );
		}

		function openDropdown() {
			dropdown.hidden = false;
			trigger.setAttribute( 'aria-expanded', 'true' );
			var selected = dropdown.querySelector( '.pltt-date-nav-option[aria-selected="true"]' )
				|| ( getOptions()[0] || null );
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
			var options = getOptions();
			var focused = document.activeElement;
			var idx     = options.indexOf( focused );

			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				( options[ idx + 1 ] || options[0] ).focus();
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				( options[ idx - 1 ] || options[ options.length - 1 ] ).focus();
			} else if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				if ( focused && options.includes( focused ) ) {
					focused.click();
				}
			} else if ( e.key === 'Tab' ) {
				setTimeout( function() {
					if ( ! widget.contains( document.activeElement ) ) {
						closeDropdown();
					}
				}, 0 );
			}
		} );

		// ── Month option click ───────────────────────────────────────────────

		dropdown.querySelectorAll( '.pltt-date-nav-option[data-from]' ).forEach( function( opt ) {
			opt.addEventListener( 'click', function() {
				fromInput.value = this.dataset.from;
				toInput.value   = this.dataset.to;
				closeDropdown();
				fromInput.form.submit();
			} );
		} );

		// ── Year switcher ────────────────────────────────────────────────────

		if ( switcher ) {
			var yearGroups = Array.from( dropdown.querySelectorAll( '.pltt-date-nav-year-months' ) );
			var yearLabel  = switcher.querySelector( '.pltt-date-nav-year-label' );
			var prevYearBtn = switcher.querySelector( '.pltt-date-nav-year-prev' );
			var nextYearBtn = switcher.querySelector( '.pltt-date-nav-year-next' );

			function getActiveGroup() {
				return dropdown.querySelector( '.pltt-date-nav-year-months:not([hidden])' );
			}

			function showYear( targetGroup ) {
				yearGroups.forEach( function( g ) { g.hidden = true; } );
				targetGroup.hidden = false;
				yearLabel.textContent = targetGroup.dataset.year;
				switcher.dataset.year = targetGroup.dataset.year;

				// Update year arrow visibility.
				var idx = yearGroups.indexOf( targetGroup );
				// yearGroups are ordered newest-first (matching PHP output).
				prevYearBtn.disabled = ( idx >= yearGroups.length - 1 );
				nextYearBtn.disabled = ( idx <= 0 );
			}

			prevYearBtn.addEventListener( 'click', function() {
				var current = getActiveGroup();
				var idx     = yearGroups.indexOf( current );
				if ( idx < yearGroups.length - 1 ) {
					showYear( yearGroups[ idx + 1 ] );
				}
			} );

			nextYearBtn.addEventListener( 'click', function() {
				var current = getActiveGroup();
				var idx     = yearGroups.indexOf( current );
				if ( idx > 0 ) {
					showYear( yearGroups[ idx - 1 ] );
				}
			} );

			// Set initial arrow state.
			showYear( getActiveGroup() );
		}
	} )();

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

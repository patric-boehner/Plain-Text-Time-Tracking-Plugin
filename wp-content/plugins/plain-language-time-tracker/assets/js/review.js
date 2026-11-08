/**
 * Review & Verify Screen JavaScript
 */

/* global PLTT, plttData, PlttTagPicker, plttAllTags, plttTagGroups */

( function() {
	'use strict';

	const form = document.getElementById( 'pltt-review-form' );
	const dateInput = document.getElementById( 'pltt-entry-date' );
	const saveAllBtn = document.getElementById( 'pltt-save-all' );
	const saveStatus = document.getElementById( 'pltt-save-status' );

	if ( ! form || ! dateInput ) {
		return;
	}

	// Initialize tag picker dropdowns.
	var tagSuggestions = ( typeof plttAllTags !== 'undefined' ) ? plttAllTags : [];
	var tagGroups = ( typeof plttTagGroups !== 'undefined' ) ? plttTagGroups : {};
	var allTagPickers = [];

	// Shared "Add New Tag" modal wiring (shared.js) — also used by the edit
	// modal and by Overview.
	var openTagModal = PLTT.wireTagModal(
		function() {
			return allTagPickers;
		},
		function( tag ) {
			// Keep tagSuggestions in sync for any future pickers.
			if ( tagSuggestions.indexOf( tag ) === -1 ) {
				tagSuggestions.push( tag );
			}
		}
	);

	document.querySelectorAll( '.pltt-tag-input-wrap' ).forEach( function( container ) {
		var picker = new PlttTagPicker(
			container,
			tagSuggestions,
			openTagModal,
			null,
			tagGroups
		);
		allTagPickers.push( picker );
	} );

	/**
	 * Set a Type pill to one of its two states: word, class and data-value.
	 *
	 * @param {HTMLElement} pill       The .pltt-type-pill button.
	 * @param {boolean}     isInternal True for "Internal", false for "Project".
	 */
	function syncTypePill( pill, isInternal ) {
		if ( ! pill ) {
			return;
		}
		var i18n = ( typeof plttData !== 'undefined' && plttData.i18n ) || {};
		pill.classList.toggle( 'pltt-badge-type-internal', isInternal );
		pill.classList.toggle( 'pltt-badge-type-project', ! isInternal );
		pill.dataset.value = isInternal ? 'internal' : 'project';
		pill.textContent = isInternal ? ( i18n.typeInternal || 'Internal' ) : ( i18n.typeProject || 'Project' );
	}

	// The Type pill is the control: clicking it flips the row's hidden box
	// between Project and Internal. Billable is no longer a control here — the
	// server derives it from the type and the project.
	document.querySelectorAll( '.pltt-type-toggle .pltt-type-pill' ).forEach( function( pill ) {
		pill.addEventListener( 'click', function() {
			var wrap = this.closest( '.pltt-type-toggle' );
			var box = wrap && wrap.querySelector( '.pltt-time-type' );
			if ( ! box ) {
				return;
			}
			box.checked = ! box.checked;
			syncTypePill( this, box.checked );
		} );
	} );

	// Track currently active row for modals.
	let activeRow = null;

	/**
	 * Calculate duration in minutes from start and end time strings.
	 *
	 * @param {string} startTime HH:MM format.
	 * @param {string} endTime   HH:MM format.
	 * @return {number|null} Duration in minutes, or null if inputs are missing.
	 */
	function calculateDuration( startTime, endTime ) {
		if ( ! startTime || ! endTime ) {
			return null;
		}

		var startParts = startTime.split( ':' ).map( Number );
		var endParts = endTime.split( ':' ).map( Number );
		var minutes = ( endParts[0] * 60 + endParts[1] ) - ( startParts[0] * 60 + startParts[1] );

		// Handle overnight (end before start).
		if ( minutes < 0 ) {
			minutes += 24 * 60;
		}

		return minutes;
	}

	/**
	 * Update duration display and hidden input when times change.
	 *
	 * @param {HTMLElement} row The entry row.
	 */
	function recalcDuration( row ) {
		var startInput = row.querySelector( '.pltt-start-time' );
		var endInput = row.querySelector( '.pltt-end-time' );
		var durationInput = row.querySelector( '.pltt-duration-minutes' );
		var durationDisplay = row.querySelector( '.pltt-duration-display' );

		if ( ! startInput || ! durationInput || ! durationDisplay ) {
			return;
		}

		var minutes = calculateDuration( startInput.value, endInput ? endInput.value : '' );

		if ( minutes !== null ) {
			durationInput.value = minutes;
			durationDisplay.textContent = PLTT.formatDuration( minutes );
		}

		updateSummary();
	}

	/**
	 * OPT-L3: Use event delegation for time input change handlers instead of
	 * adding one listener per row. Single listener handles all current and future rows.
	 */
	var reviewForm = document.getElementById( 'pltt-review-form' );
	if ( reviewForm ) {
		reviewForm.addEventListener( 'change', function( e ) {
			if ( e.target.classList.contains( 'pltt-start-time' ) || e.target.classList.contains( 'pltt-end-time' ) ) {
				var row = e.target.closest( '.pltt-entry-row' );
				if ( row ) {
					recalcDuration( row );
				}
			}
		} );
	}

	/**
	 * Format a time string for display (e.g. "14:30" → "2:30pm").
	 *
	 * @param {string} timeStr HH:MM or HH:MM:SS format.
	 * @return {string} Formatted time string.
	 */
	function formatTimeForDisplay( timeStr ) {
		if ( ! timeStr ) {
			return '';
		}
		var parts = timeStr.split( ':' );
		var hours = parseInt( parts[0], 10 );
		var minutes = parts[1];
		var period = hours >= 12 ? 'pm' : 'am';

		hours = hours % 12;
		if ( hours === 0 ) {
			hours = 12;
		}

		return hours + ':' + minutes + period;
	}

	/**
	 * Format a date string for display (e.g. "2026-02-07" → "Feb 7, 2026").
	 *
	 * @param {string} dateStr YYYY-MM-DD format.
	 * @return {string} Formatted date string.
	 */
	function formatDateForDisplay( dateStr ) {
		if ( ! dateStr ) {
			return '';
		}
		var d = new Date( dateStr + 'T00:00:00' );
		return d.toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', year: 'numeric' } );
	}

	/**
	 * Open time editing for a cell: hide display, show edit, store originals.
	 *
	 * @param {HTMLElement} cell The td.pltt-time-cell element.
	 */
	function openTimeEdit( cell ) {
		var displayDiv = cell.querySelector( '.pltt-time-display' );
		var editDiv = cell.querySelector( '.pltt-time-edit' );
		var dateInput = cell.querySelector( '.pltt-entry-date-input' );
		var startInput = cell.querySelector( '.pltt-start-time' );
		var endInput = cell.querySelector( '.pltt-end-time' );

		// Store original values for cancel revert.
		cell._origDate = dateInput ? dateInput.value : '';
		cell._origStart = startInput ? startInput.value : '';
		cell._origEnd = endInput ? endInput.value : '';

		displayDiv.style.display = 'none';
		editDiv.style.display = 'block';

		if ( dateInput ) {
			dateInput.focus();
		}
	}

	/**
	 * Apply changes: update display text from inputs, hide edit, show display.
	 *
	 * @param {HTMLElement} cell The td.pltt-time-cell element.
	 */
	function applyTimeEdit( cell ) {
		var displayDiv = cell.querySelector( '.pltt-time-display' );
		var editDiv = cell.querySelector( '.pltt-time-edit' );
		var dateInput = cell.querySelector( '.pltt-entry-date-input' );
		var startInput = cell.querySelector( '.pltt-start-time' );
		var endInput = cell.querySelector( '.pltt-end-time' );
		var dateText = cell.querySelector( '.pltt-date-text' );
		var timeText = cell.querySelector( '.pltt-time-text' );

		if ( dateText && dateInput ) {
			dateText.textContent = formatDateForDisplay( dateInput.value );
		}

		if ( timeText && startInput ) {
			var display = formatTimeForDisplay( startInput.value );
			if ( endInput && endInput.value ) {
				display += ' \u2013 ' + formatTimeForDisplay( endInput.value );
			}
			timeText.textContent = display;
		}

		editDiv.style.display = 'none';
		displayDiv.style.display = '';
	}

	/**
	 * Cancel editing: restore original values, hide edit, show display.
	 *
	 * @param {HTMLElement} cell The td.pltt-time-cell element.
	 */
	function cancelTimeEdit( cell ) {
		var displayDiv = cell.querySelector( '.pltt-time-display' );
		var editDiv = cell.querySelector( '.pltt-time-edit' );
		var dateInput = cell.querySelector( '.pltt-entry-date-input' );
		var startInput = cell.querySelector( '.pltt-start-time' );
		var endInput = cell.querySelector( '.pltt-end-time' );

		// Restore original values.
		if ( dateInput ) {
			dateInput.value = cell._origDate || '';
		}
		if ( startInput ) {
			startInput.value = cell._origStart || '';
		}
		if ( endInput ) {
			endInput.value = cell._origEnd || '';
		}

		// Recalc duration back to original.
		var row = cell.closest( '.pltt-entry-row' );
		if ( row ) {
			recalcDuration( row );
		}

		editDiv.style.display = 'none';
		displayDiv.style.display = '';
	}

	/**
	 * Edit link: open time editing.
	 */
	document.querySelectorAll( '.pltt-edit-time' ).forEach( function( link ) {
		link.addEventListener( 'click', function( e ) {
			e.preventDefault();
			var cell = this.closest( '.pltt-time-cell' );
			openTimeEdit( cell );
		} );
	} );

	/**
	 * Update button: apply changes and close editor.
	 */
	document.querySelectorAll( '.pltt-save-time' ).forEach( function( btn ) {
		btn.addEventListener( 'click', function() {
			var cell = this.closest( '.pltt-time-cell' );
			applyTimeEdit( cell );
		} );
	} );

	/**
	 * Cancel button: revert and close editor.
	 */
	document.querySelectorAll( '.pltt-cancel-time' ).forEach( function( btn ) {
		btn.addEventListener( 'click', function() {
			var cell = this.closest( '.pltt-time-cell' );
			cancelTimeEdit( cell );
		} );
	} );

	/**
	 * Escape key: cancel editing (same as Cancel button).
	 */
	document.addEventListener( 'keydown', function( e ) {
		if ( e.key === 'Escape' ) {
			document.querySelectorAll( '.pltt-time-cell' ).forEach( function( cell ) {
				if ( cell.querySelector( '.pltt-time-display' ).style.display === 'none' ) {
					cancelTimeEdit( cell );
				}
			} );
		}
	} );

	/**
	 * Load projects for a client via AJAX.
	 *
	 * Used when the user changes the client dropdown.
	 * Initial project options are rendered server-side.
	 *
	 * @param {HTMLSelectElement} clientSelect    Client dropdown.
	 * @param {HTMLSelectElement} projectSelect   Project dropdown.
	 * @param {string}           currentProjectId Optional project ID to preserve (may be archived).
	 */
	function loadProjects( clientSelect, projectSelect, currentProjectId ) {
		const clientId = clientSelect.value;

		if ( ! clientId || clientId === 'new' ) {
			projectSelect.innerHTML = '<option value="">Select project...</option>' +
				'<option value="new">+ Add new project...</option>';
			return;
		}

		const ajaxData = { client_id: clientId };
		if ( currentProjectId ) {
			ajaxData.current_project_id = currentProjectId;
		}

		PLTT.ajax( 'pltt_get_projects', ajaxData, function( response ) {
			if ( response.success ) {
				let html = '<option value="">Select project...</option>';

				response.data.projects.forEach( function( project ) {
					var isArchived = ( project.status === 'archived' );
					// Only include archived projects if they match the entry's current project.
					if ( isArchived && String( project.id ) !== String( currentProjectId ) ) {
						return;
					}
					var label = isArchived
						? PLTT.escapeHtml( project.name ) + ' (Archived)'
						: PLTT.escapeHtml( project.name );
					var billDefault = parseInt( project.billability_default, 10 ) === 1 ? '1' : '0';
					var billFlag = parseInt( project.billable_flag_applies, 10 ) === 0 ? '0' : '1';
					var dataAttr = ' data-billability-default="' + billDefault + '"' +
						' data-billable-flag="' + billFlag + '"' +
						( isArchived ? ' data-archived="1"' : '' );
					// SEC-M13: defense-in-depth — coerce id to int before interpolating.
					html += '<option value="' + parseInt( project.id, 10 ) + '"' + dataAttr + '>' +
						label + '</option>';
				} );

				html += '<option value="new">+ Add new project...</option>';
				projectSelect.innerHTML = html;

				// Re-select the current project if it was in the list.
				if ( currentProjectId ) {
					projectSelect.value = currentProjectId;
				}

				// Auto-select most recent project if currentProjectId didn't match any option.
				var matched = currentProjectId && projectSelect.value === String( currentProjectId );
				if ( ! matched ) {
					for ( var i = 0; i < projectSelect.options.length; i++ ) {
						var opt = projectSelect.options[ i ];
						if ( opt.value && opt.value !== 'new' && ! opt.dataset.archived ) {
							projectSelect.value = opt.value;
							break;
						}
					}
					if ( projectSelect.value && projectSelect.value !== 'new' ) {
						projectSelect.dispatchEvent( new Event( 'change' ) );
					}
				}

			}
		} );
	}

	/**
	 * Initialize client dropdown handlers.
	 */
	document.querySelectorAll( '.pltt-client-select' ).forEach( function( select ) {
		const row = select.closest( '.pltt-entry-row' );
		const projectSelect = row.querySelector( '.pltt-project-select' );

		// Reload projects when client changes.
		select.addEventListener( 'change', function() {
			if ( this.value === 'new' ) {
				activeRow = row;
				PLTT.showModal( 'pltt-client-modal' );
				this.value = '';
				return;
			}

			// On the internal client every entry is internal — nothing to decide,
			// so the Internal box steps aside (the server forces the type anyway).
			applyTypeVisibility( row );

			var originalProjectId = row.dataset.originalProjectId || '';
			loadProjects( this, projectSelect, originalProjectId );
		} );
	} );

	/**
	 * Initialize project dropdown handlers.
	 */
	document.querySelectorAll( '.pltt-project-select' ).forEach( function( select ) {
		select.addEventListener( 'change', function() {
			if ( this.value === 'new' ) {
				const row = this.closest( '.pltt-entry-row' );
				const clientSelect = row.querySelector( '.pltt-client-select' );

				if ( ! clientSelect.value || clientSelect.value === 'new' ) {
					alert( 'Please select a client first.' );
					this.value = '';
					return;
				}

				activeRow = row;
				document.getElementById( 'pltt-new-project-client-id' ).value = clientSelect.value;
				if ( window.PlttProjectType ) { PlttProjectType.reset(); } PLTT.showModal( 'pltt-project-modal' );
				this.value = '';
			}
			// Nothing else to settle on a project change: the time type is a fact
			// about the entry, not about the project, and billable derives from
			// both on the server.
		} );
	} );

	/**
	 * Show or hide a row's Internal box by its client.
	 *
	 * On the internal client every entry is internal by definition, so the box
	 * is hidden and cleared (the server resolves the type from the client
	 * regardless — this only keeps the row from showing a choice that isn't
	 * one). Any other client gets the box back, unchanged.
	 *
	 * @param {HTMLElement} row The .pltt-entry-row element.
	 */
	function applyTypeVisibility( row ) {
		var clientSelect = row.querySelector( '.pltt-client-select' );
		var toggle = row.querySelector( '.pltt-type-toggle' );
		if ( ! clientSelect || ! toggle ) {
			return;
		}
		var opt = clientSelect.options[ clientSelect.selectedIndex ];
		var isInternalClient = !! ( opt && opt.dataset.isInternal === '1' );
		toggle.classList.toggle( 'pltt-hidden', isInternalClient );
		var dash = row.querySelector( '.pltt-type-empty' );
		if ( dash ) {
			dash.classList.toggle( 'pltt-hidden', ! isInternalClient );
		}
		if ( isInternalClient ) {
			var box = toggle.querySelector( '.pltt-time-type' );
			if ( box && box.checked ) {
				box.checked = false;
				syncTypePill( toggle.querySelector( '.pltt-type-pill' ), false );
			}
		}
	}

	/**
	 * On page load: settle the Internal box for rows already on the page (the
	 * change handler only fires on user interaction, not on initial render).
	 */
	document.querySelectorAll( '.pltt-entry-row' ).forEach( function( row ) {
		applyTypeVisibility( row );
	} );

	/**
	 * Handle new client creation.
	 */
	const saveClientBtn = document.getElementById( 'pltt-save-client' );
	if ( saveClientBtn ) {
		saveClientBtn.addEventListener( 'click', function() {
			const nameInput = document.getElementById( 'pltt-new-client-name' );
			const rateInput = document.getElementById( 'pltt-new-client-rate' );
			const name = nameInput.value.trim();
			const rate = PLTT.parseCurrencyValue( rateInput.value );

			if ( ! name ) {
				alert( 'Please enter a client name.' );
				nameInput.focus();
				return;
			}

			this.disabled = true;

			PLTT.ajax( 'pltt_create_client', {
				name: name,
				hourly_rate: rate
			}, function( response ) {
				saveClientBtn.disabled = false;

				if ( response.success && response.data.client ) {
					const client = response.data.client;

					// Add to all client dropdowns.
					document.querySelectorAll( '.pltt-client-select' ).forEach( function( select ) {
						const option = document.createElement( 'option' );
						option.value = client.id;
						option.textContent = client.name;

						// Insert before the "Add new" option.
						const addNewOption = select.querySelector( 'option[value="new"]' );
						select.insertBefore( option, addNewOption );
					} );

					// Select in active row.
					if ( activeRow ) {
						const clientSelect = activeRow.querySelector( '.pltt-client-select' );
						clientSelect.value = client.id;

						// Trigger project load.
						const projectSelect = activeRow.querySelector( '.pltt-project-select' );
						loadProjects( clientSelect, projectSelect );
					}

					PLTT.hideModal( 'pltt-client-modal' );
					nameInput.value = '';
					rateInput.value = '';
				} else {
					alert( response.data || 'Error creating client.' );
				}
			} );
		} );
	}

	/**
	 * Handle new project creation.
	 */
	const saveProjectBtn = document.getElementById( 'pltt-save-project' );
	if ( saveProjectBtn ) {
		saveProjectBtn.addEventListener( 'click', function() {
			const clientIdInput = document.getElementById( 'pltt-new-project-client-id' );
			const nameInput = document.getElementById( 'pltt-project-name' );
			const vals = window.PlttProjectType ? PlttProjectType.getValues() : { name: nameInput ? nameInput.value.trim() : '', hourly_rate: '', budget_hours: '', budget_fee: '', recurring_period: '', non_billable: '0' };
			const name = vals.name;
			const clientId = clientIdInput.value;
			const rate = PLTT.parseCurrencyValue( vals.hourly_rate );

			if ( ! name ) {
				alert( 'Please enter a project name.' );
				nameInput.focus();
				return;
			}

			const typeError = window.PlttProjectType ? PlttProjectType.validate() : '';
			if ( typeError ) {
				alert( typeError );
				const feeInput = document.getElementById( 'pltt-project-budget-fee' );
				if ( feeInput ) { feeInput.focus(); }
				return;
			}

			this.disabled = true;

			PLTT.ajax( 'pltt_create_project', {
				name: name,
				client_id: clientId,
				hourly_rate: rate,
				budget_hours: vals.budget_hours,
				budget_fee: PLTT.parseCurrencyValue( vals.budget_fee ),
				recurring_period: vals.recurring_period,
				non_billable: vals.non_billable
			}, function( response ) {
				saveProjectBtn.disabled = false;

				if ( response.success && response.data.project ) {
					const project = response.data.project;

					// Select in active row.
					if ( activeRow ) {
						const projectSelect = activeRow.querySelector( '.pltt-project-select' );

						const option = document.createElement( 'option' );
						option.value = project.id;
						option.textContent = project.name;
						option.dataset.billabilityDefault = parseInt( project.billability_default, 10 ) === 1 ? '1' : '0';
						option.dataset.billableFlag = parseInt( project.billable_flag_applies, 10 ) === 0 ? '0' : '1';
						option.selected = true;

						const addNewOption = projectSelect.querySelector( 'option[value="new"]' );
						projectSelect.insertBefore( option, addNewOption );
						projectSelect.dispatchEvent( new Event( 'change' ) );
					}

					PLTT.hideModal( 'pltt-project-modal' );
					if ( window.PlttProjectType ) { PlttProjectType.reset(); }
				} else {
					alert( response.data || 'Error creating project.' );
				}
			} );
		} );
	}

	// New tag creation is wired by PLTT.wireTagModal() at the top of this IIFE.

	/**
	 * Handle delete entry buttons via event delegation.
	 */
	document.addEventListener( 'click', function( e ) {
		const btn = e.target.closest( '.pltt-delete-entry' );
		if ( ! btn ) {
			return;
		}

		e.preventDefault();
		if ( ! confirm( plttData.i18n.confirm ) ) {
			return;
		}

		const row = btn.closest( '.pltt-entry-row' );
		const entryId = row.dataset.entryId;

		row.classList.add( 'deleting' );

		if ( entryId && entryId !== '0' ) {
			// Delete from database.
			PLTT.ajax( 'pltt_delete_entry', { entry_id: entryId }, function( response ) {
				if ( response.success ) {
					row.remove();
					updateSummary();
				} else {
					row.classList.remove( 'deleting' );
					alert( response.data || 'Error deleting entry.' );
				}
			} );
		} else {
			// Just remove from DOM (not saved yet).
			row.remove();
			updateSummary();
		}
	} );

	/**
	 * Update summary cards after changes.
	 */
	function updateSummary() {
		const rows = document.querySelectorAll( '.pltt-entry-row' );
		let totalMinutes = 0;

		rows.forEach( function( row ) {
			const durationInput = row.querySelector( '.pltt-duration-minutes' );
			if ( durationInput ) {
				totalMinutes += parseInt( durationInput.value, 10 ) || 0;
			}
		} );

		// Update cards if they exist.
		const entryCountEl = document.querySelector( '[data-card="entry-count"]' );
		if ( entryCountEl ) {
			entryCountEl.textContent = rows.length;
		}

		const hoursEl = document.querySelector( '[data-card="hours"]' );
		if ( hoursEl ) {
			hoursEl.textContent = PLTT.formatDuration( totalMinutes );
			// Keep the decimal-hours hover hint in step with the figure.
			hoursEl.setAttribute( 'data-tip-rows', JSON.stringify( [ [ '', '= ' + PLTT.formatHours( totalMinutes ) + ' h' ] ] ) );
		}
	}

	/**
	 * Handle form submission (Save All).
	 */
	form.addEventListener( 'submit', function( e ) {
		e.preventDefault();

		const rows = document.querySelectorAll( '.pltt-entry-row' );
		const entries = [];

		rows.forEach( function( row, index ) {
			const typeBox = row.querySelector( '.pltt-time-type' );
			const entry = {
				id: row.dataset.entryId || 0,
				entry_date: row.querySelector( '.pltt-entry-date-input' ).value,
				start_time: row.querySelector( '.pltt-start-time' ).value,
				end_time: row.querySelector( '.pltt-end-time' ).value,
				duration_minutes: row.querySelector( '.pltt-duration-minutes' ).value,
				description: row.querySelector( '.pltt-description' ).value,
				client_id: row.querySelector( '.pltt-client-select' ).value,
				project_id: row.querySelector( '.pltt-project-select' ).value,
				tags: row.querySelector( '.pltt-tags' ).value,
				time_type: ( typeBox && typeBox.checked ) ? 'internal' : 'project'
			};

			entries.push( entry );
		} );

		if ( entries.length === 0 ) {
			alert( 'No entries to save.' );
			return;
		}

		// Serialize entries into hidden field.
		const entriesDataInput = document.getElementById( 'pltt-entries-data' );
		entriesDataInput.value = JSON.stringify( entries );

		// Update button text to show saving state.
		saveAllBtn.disabled = true;
		saveAllBtn.textContent = plttData.i18n.saving;

		// Submit the form.
		form.submit();
	} );
} )();


/**
 * Recorded-entries list: the row kebab, and repainting a row after the shared
 * edit modal saves it.
 *
 * Editing itself lives in assets/js/pltt-entry-editor.js — one modal, opened
 * from any row. What used to be here was a form per row, revealed in place:
 * expand/collapse, a value snapshot for Cancel, and an auto-save that fired
 * when you clicked Edit on a different row. One form needs none of that.
 *
 * No-ops in post-parse mode (when #pltt-review-form is present), which keeps
 * its own inline table.
 */
( function() {
	'use strict';

	if ( document.getElementById( 'pltt-review-form' ) ) {
		return; // Post-parse mode handles its own UI.
	}

	const tbody = document.getElementById( 'pltt-entries-tbody' );
	if ( ! tbody || typeof PlttRowMenu === 'undefined' ) {
		return;
	}

	const i18n = ( typeof plttData !== 'undefined' && plttData.i18n ) || {};

	/**
	 * Row menu (spec §6). Same items as the Overview's, minus "Go to [date]
	 * log" — this screen is that log.
	 */
	PlttRowMenu.register( 'entry', function( trigger ) {
		const d = trigger.dataset;
		const entryId = d.entryId;
		const items = [];

		// Spec §6 also lists "Change tags…", but it opened the same modal on the
		// same entry — the tag field is right there in it.
		if ( typeof PlttEntryEditor !== 'undefined' ) {
			// The builder runs as the menu opens, which is the moment worth
			// spending on fetching the entry — see PlttEntryEditor.prefetch.
			PlttEntryEditor.prefetch( entryId );
			items.push( {
				label: 'Edit entry…',
				kind: 'primary',
				onSelect: function() {
					PlttEntryEditor.open( entryId );
				},
			} );
		}

		// The entry's time type — offered on every client but the internal one,
		// where every entry is internal and there is nothing to decide.
		if ( d.typeApplies === '1' ) {
			const toInternal = d.timeType !== 'internal';
			items.push( {
				label: toInternal
					? ( i18n.typeMarkInternal || 'Mark as internal' )
					: ( i18n.typeMarkProject || 'Mark as project work' ),
				onSelect: function( t ) {
					const row = t.closest( 'tr' );
					const entry = row ? row.dataset.entryId : 0;
					PLTT.ajax(
						'pltt_update_entry_field',
						{ entry_id: entry, field: 'time_type', value: toInternal ? 'internal' : 'project' },
						function( response ) {
							if ( ! response.success ) {
								window.alert( response.data || i18n.error );
								return;
							}
							// The day's billable hours and amount move with it.
							window.location.reload();
						}
					);
				},
			} );
		}

		if ( items.length ) {
			items.push( { kind: 'separator' } );
		}

		items.push( {
			label: 'Delete entry',
			kind: 'destructive',
			confirm: i18n.confirm || 'Are you sure?',
			onSelect: function() {
				PLTT.ajax( 'pltt_delete_entry', { entry_id: entryId }, function( response ) {
					if ( ! response.success ) {
						window.alert( response.data || i18n.error );
						return;
					}
					// Every summary card above the table counts this entry.
					window.location.reload();
				} );
			},
		} );

		return items;
	} );

	/**
	 * Swap in the saved row.
	 *
	 * This screen takes the server-rendered markup rather than the JSON payload:
	 * its row is a different shape from the Overview's, and re-rendering it in
	 * PHP is what keeps it identical to a fresh page load.
	 *
	 * A change that reaches past the row — the date, client, project, billing or
	 * length — comes back flagged, because the day header and every summary card
	 * above the table count it too.
	 */
	document.addEventListener( 'pltt:entry-saved', function( e ) {
		if ( e.detail.requiresReload ) {
			window.location.reload();
			return;
		}

		const row = tbody.querySelector( 'tr[data-entry-id="' + e.detail.entryId + '"]' );
		if ( ! row || ! e.detail.rowHtml ) {
			return;
		}

		const wrapper = document.createElement( 'tbody' );
		wrapper.innerHTML = e.detail.rowHtml.trim();
		if ( wrapper.firstElementChild ) {
			row.replaceWith( wrapper.firstElementChild );
		}
	} );
} )();

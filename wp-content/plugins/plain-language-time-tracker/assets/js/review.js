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
	var activeTagPicker = null;

	document.querySelectorAll( '.pltt-tag-input-wrap' ).forEach( function( container ) {
		var picker = new PlttTagPicker(
			container,
			tagSuggestions,
			function( p ) {
				activeTagPicker = p;
				PLTT.showModal( 'pltt-tag-modal' );
			},
			null,
			tagGroups
		);
		allTagPickers.push( picker );
	} );

	// Billable $ symbol click handler — toggles the hidden checkbox.
	document.querySelectorAll( '.pltt-billable-symbol' ).forEach( function( symbol ) {
		symbol.addEventListener( 'click', function() {
			var cell = this.closest( 'td' );
			var checkbox = cell.querySelector( '.pltt-billable' );
			if ( checkbox ) {
				// A click here is a deliberate choice — flag it so a later project
				// change won't re-apply the project default over the user's pick.
				checkbox.dataset.userSet = '1';
				checkbox.checked = ! checkbox.checked;
				checkbox.dispatchEvent( new Event( 'change' ) );
			}
		} );
	} );

	// Billable checkbox change — update $ symbol.
	document.querySelectorAll( '.pltt-billable' ).forEach( function( checkbox ) {
		// A direct click on the checkbox is also a deliberate choice. 'click'
		// fires only on real interaction, never on a programmatic dispatch.
		checkbox.addEventListener( 'click', function() {
			this.dataset.userSet = '1';
		} );
		checkbox.addEventListener( 'change', function() {
			var cell = this.closest( 'td' );
			var symbol = cell.querySelector( '.pltt-billable-symbol' );
			if ( symbol ) {
				symbol.classList.toggle( 'is-billable', this.checked );
				symbol.classList.toggle( 'not-billable', ! this.checked );
			}
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

				var ppRow = projectSelect.closest( '.pltt-entry-row' );
				if ( ppRow ) {
					applyBillableVisibility( ppRow );
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

			// If the internal client is selected, mark the entry as non-billable.
			var selectedClientOpt = this.options[ this.selectedIndex ];
			if ( selectedClientOpt && selectedClientOpt.dataset.isInternal === '1' ) {
				setBillableVisual( row, false );
			}

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
			} else {
				var pRowVis = this.closest( '.pltt-entry-row' );
				if ( pRowVis ) {
					applyBillableVisibility( pRowVis );
				}

				// Apply the project's billability default to the billable checkbox,
				// but never clobber a manual choice: if the user has toggled billable
				// on this row, leave it alone (spec: billable defaults once).
				var selectedOpt = this.options[ this.selectedIndex ];
				if ( selectedOpt && selectedOpt.value ) {
					var billDefault = selectedOpt.dataset.billabilityDefault;
					if ( billDefault !== undefined ) {
						var entryRow = this.closest( '.pltt-entry-row' );
						var checkbox = entryRow && entryRow.querySelector( '.pltt-billable' );
						if ( checkbox && checkbox.dataset.userSet !== '1' ) {
							var shouldBeBillable = billDefault === '1';
							if ( checkbox.checked !== shouldBeBillable ) {
								checkbox.checked = shouldBeBillable;
								checkbox.dispatchEvent( new Event( 'change' ) );
							}
						}
					}
				}
			}
		} );
	} );

	/**
	 * Helper: update billable checkbox and $ symbol visually without side-effects.
	 * Does NOT dispatch 'change' (which would trigger AJAX to clear billed status).
	 *
	 * @param {HTMLElement} row         The .pltt-entry-row element.
	 * @param {boolean}     isBillable  True to mark billable, false for non-billable.
	 */
	function setBillableVisual( row, isBillable ) {
		var checkbox = row.querySelector( '.pltt-billable' );
		if ( ! checkbox || checkbox.checked === isBillable ) {
			return;
		}
		checkbox.checked = isBillable;
		var cell = checkbox.closest( 'td' );
		var symbol = cell && cell.querySelector( '.pltt-billable-symbol' );
		if ( symbol ) {
			symbol.classList.toggle( 'is-billable', isBillable );
			symbol.classList.toggle( 'not-billable', ! isBillable );
		}
	}

	/**
	 * Show/hide a row's billable control based on the selected project's type.
	 * Retainer/fixed-fee projects (data-billable-flag="0") bill at the period
	 * level, so the per-entry flag is hidden. The checkbox stays in the DOM and
	 * still submits its (defaulted non-billable) value.
	 *
	 * @param {HTMLElement} row The .pltt-entry-row element.
	 */
	function applyBillableVisibility( row ) {
		var projectSelect = row.querySelector( '.pltt-project-select' );
		var cell = row.querySelector( '.pltt-billable-indicator' );
		if ( ! projectSelect || ! cell ) {
			return;
		}
		var opt = projectSelect.options[ projectSelect.selectedIndex ];
		var hide = !! ( opt && opt.dataset.billableFlag === '0' );
		cell.querySelectorAll( '.pltt-billable-symbol, .pltt-billable' ).forEach( function( el ) {
			el.classList.toggle( 'pltt-hidden', hide );
		} );
	}

	/**
	 * On page load: apply internal-client non-billable rule for rows already on the page.
	 * Handles entries that already have the internal client assigned (change handler only
	 * fires on user interaction, not on initial render).
	 */
	document.querySelectorAll( '.pltt-entry-row' ).forEach( function( row ) {
		var clientSelect  = row.querySelector( '.pltt-client-select' );
		var projectSelect = row.querySelector( '.pltt-project-select' );
		if ( ! clientSelect || ! projectSelect ) {
			return;
		}

		var selectedClientOpt = clientSelect.options[ clientSelect.selectedIndex ];
		var hasProject = projectSelect.value && projectSelect.value !== 'new';

		if ( selectedClientOpt && selectedClientOpt.dataset.isInternal === '1' && ! hasProject ) {
			setBillableVisual( row, false );
		}

		applyBillableVisibility( row );
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

	/**
	 * Handle new tag creation.
	 */
	const saveTagBtn = document.getElementById( 'pltt-save-tag' );
	if ( saveTagBtn ) {
		saveTagBtn.addEventListener( 'click', function() {
			const nameInput = document.getElementById( 'pltt-new-tag-name' );
			const name = nameInput.value.trim();

			if ( ! name ) {
				alert( 'Please enter a tag name.' );
				nameInput.focus();
				return;
			}

			this.disabled = true;

			PLTT.ajax( 'pltt_create_tag', { tag_name: name }, function( response ) {
				saveTagBtn.disabled = false;

				if ( response.success && response.data.tag ) {
					const tag = response.data.tag;

					// Add tag to all pickers' available lists; auto-select only in the triggering picker.
					allTagPickers.forEach( function( picker ) {
						picker.addTagOption( tag, picker === activeTagPicker );
					} );

					// Also keep tagSuggestions in sync for any future pickers.
					if ( tagSuggestions.indexOf( tag ) === -1 ) {
						tagSuggestions.push( tag );
					}

					PLTT.hideModal( 'pltt-tag-modal' );
					nameInput.value = '';
					activeTagPicker = null;
				} else {
					alert( response.data || 'Error creating tag.' );
				}
			} );
		} );
	}

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
			hoursEl.textContent = PLTT.formatHours( totalMinutes );
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
			const billableCheckbox = row.querySelector( '.pltt-billable' );
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
				billable: billableCheckbox ? ( billableCheckbox.checked ? 1 : 0 ) : 0
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
 * Editing-existing screen state: compact rows with hover Edit/Delete,
 * expandable unified form (add + edit), per-row save via AJAX.
 *
 * This IIFE no-ops in post-parse mode (when #pltt-review-form is present).
 */
( function() {
	'use strict';

	if ( document.getElementById( 'pltt-review-form' ) ) {
		return; // Post-parse mode handles its own UI.
	}

	const tbody = document.getElementById( 'pltt-entries-tbody' );
	if ( ! tbody ) {
		return;
	}

	const tagSuggestions = ( typeof plttAllTags !== 'undefined' ) ? plttAllTags : [];
	const tagGroups = ( typeof plttTagGroups !== 'undefined' ) ? plttTagGroups : {};

	// One tag picker instance per form row, keyed by row element.
	const tagPickers = new WeakMap();
	// Track which form row currently owns the "create new tag" modal flow.
	let activeTagPicker = null;
	// Track which form row currently owns the "create new client/project" modal flow.
	let activeFormRow = null;
	// Track which form row is currently open (only one at a time).
	let openFormRow = null;

	/**
	 * Look up the compact display row that owns a given form row.
	 *
	 * @param {HTMLElement} formRow .pltt-entry-form-row tr.
	 * @return {HTMLElement|null} The preceding compact row.
	 */
	function getDisplayRow( formRow ) {
		return formRow.previousElementSibling;
	}

	/**
	 * Initialize a tag picker on a form row if one doesn't already exist.
	 *
	 * @param {HTMLElement} formRow .pltt-entry-form-row element.
	 */
	function ensureTagPicker( formRow ) {
		if ( tagPickers.has( formRow ) ) {
			return;
		}
		const container = formRow.querySelector( '.pltt-tag-input-wrap' );
		if ( ! container ) {
			return;
		}
		const picker = new PlttTagPicker(
			container,
			tagSuggestions,
			function( p ) {
				activeTagPicker = p;
				PLTT.showModal( 'pltt-tag-modal' );
			},
			null,
			tagGroups
		);
		tagPickers.set( formRow, picker );
	}

	/**
	 * Snapshot the form's current field values for cancel/revert.
	 *
	 * @param {HTMLElement} formRow .pltt-entry-form-row element.
	 * @return {Object} Snapshot of field values.
	 */
	function snapshotForm( formRow ) {
		return {
			description: formRow.querySelector( '.pltt-form-description' ).value,
			date: formRow.querySelector( '.pltt-form-date' ).value,
			start: formRow.querySelector( '.pltt-form-start' ).value,
			end: formRow.querySelector( '.pltt-form-end' ).value,
			duration: formRow.querySelector( '.pltt-form-duration' ).value,
			billable: formRow.querySelector( '.pltt-form-billable' ).checked,
			client: formRow.querySelector( '.pltt-form-client' ).value,
			project: formRow.querySelector( '.pltt-form-project' ).value,
			tags: formRow.querySelector( '.pltt-form-tags' ).value
		};
	}

	/**
	 * Restore form fields from a snapshot.
	 *
	 * @param {HTMLElement} formRow  .pltt-entry-form-row element.
	 * @param {Object}      snapshot Previously captured field values.
	 */
	function restoreForm( formRow, snapshot ) {
		formRow.querySelector( '.pltt-form-description' ).value = snapshot.description;
		formRow.querySelector( '.pltt-form-date' ).value = snapshot.date;
		formRow.querySelector( '.pltt-form-start' ).value = snapshot.start;
		formRow.querySelector( '.pltt-form-end' ).value = snapshot.end;
		formRow.querySelector( '.pltt-form-duration' ).value = snapshot.duration;
		formRow.querySelector( '.pltt-form-billable' ).checked = snapshot.billable;
		syncBillableButton( formRow );
		formRow.querySelector( '.pltt-form-client' ).value = snapshot.client;
		formRow.querySelector( '.pltt-form-project' ).value = snapshot.project;
		const tagsInput = formRow.querySelector( '.pltt-form-tags' );
		tagsInput.value = snapshot.tags;
		// Force the tag picker to re-read its hidden input.
		tagsInput.dispatchEvent( new Event( 'change' ) );
	}

	/**
	 * Show a form row and capture its pre-edit snapshot.
	 */
	function expandForm( formRow ) {
		formRow.classList.remove( 'pltt-hidden' );
		formRow._snapshot = snapshotForm( formRow );
		formRow._durationManual = false;
		ensureTagPicker( formRow );

		const displayRow = getDisplayRow( formRow );
		if ( displayRow ) {
			displayRow.classList.add( 'pltt-entry-being-edited' );
		}

		openFormRow = formRow;
		clearFormError( formRow );

		const desc = formRow.querySelector( '.pltt-form-description' );
		if ( desc ) {
			desc.focus();
		}
	}

	/**
	 * Hide a form row without changing its values.
	 */
	function collapseForm( formRow ) {
		formRow.classList.add( 'pltt-hidden' );

		const displayRow = getDisplayRow( formRow );
		if ( displayRow ) {
			displayRow.classList.remove( 'pltt-entry-being-edited' );
		}

		if ( openFormRow === formRow ) {
			openFormRow = null;
		}
	}

	/**
	 * Display a validation/error message in a form row.
	 *
	 * @param {HTMLElement} formRow .pltt-entry-form-row element.
	 * @param {string}      message Error text to display.
	 */
	function showFormError( formRow, message ) {
		const target = formRow.querySelector( '.pltt-entry-form-error' );
		if ( target ) {
			target.textContent = message;
			target.classList.add( 'is-visible' );
		}
	}

	function clearFormError( formRow ) {
		const target = formRow.querySelector( '.pltt-entry-form-error' );
		if ( target ) {
			target.textContent = '';
			target.classList.remove( 'is-visible' );
		}
	}

	/**
	 * Calculate duration in minutes from start/end time strings (HH:MM).
	 *
	 * @param {string} startTime HH:MM.
	 * @param {string} endTime   HH:MM.
	 * @return {number|null} Duration in minutes, or null if either input is missing.
	 */
	function calculateDuration( startTime, endTime ) {
		if ( ! startTime || ! endTime ) {
			return null;
		}
		const startParts = startTime.split( ':' ).map( Number );
		const endParts = endTime.split( ':' ).map( Number );
		let minutes = ( endParts[0] * 60 + endParts[1] ) - ( startParts[0] * 60 + startParts[1] );
		if ( minutes < 0 ) {
			minutes += 24 * 60; // Overnight span.
		}
		return minutes;
	}

	/**
	 * Build the request payload from a form row.
	 *
	 * @param {HTMLElement} formRow .pltt-entry-form-row element.
	 * @return {Object} Payload for pltt_save_entry.
	 */
	function collectPayload( formRow ) {
		const payload = {
			entry_id: formRow.dataset.formForEntryId || 0,
			entry_date: formRow.querySelector( '.pltt-form-date' ).value,
			start_time: formRow.querySelector( '.pltt-form-start' ).value,
			end_time: formRow.querySelector( '.pltt-form-end' ).value,
			duration_minutes: formRow.querySelector( '.pltt-form-duration' ).value || 0,
			description: formRow.querySelector( '.pltt-form-description' ).value,
			client_id: formRow.querySelector( '.pltt-form-client' ).value,
			project_id: formRow.querySelector( '.pltt-form-project' ).value,
			tags: formRow.querySelector( '.pltt-form-tags' ).value,
			billable: formRow.querySelector( '.pltt-form-billable' ).checked ? 1 : 0
		};
		// Don't send 'new' as a real selection — strip to empty.
		if ( payload.client_id === 'new' ) {
			payload.client_id = '';
		}
		if ( payload.project_id === 'new' ) {
			payload.project_id = '';
		}
		return payload;
	}

	/**
	 * Save the form row via AJAX. Resolves to true on success, false on failure.
	 *
	 * @param {HTMLElement} formRow .pltt-entry-form-row element.
	 * @return {Promise<boolean>}
	 */
	function saveForm( formRow ) {
		return new Promise( function( resolve ) {
			clearFormError( formRow );

			const saveBtn = formRow.querySelector( '.pltt-form-save' );
			const status = formRow.querySelector( '.pltt-form-status' );
			const payload = collectPayload( formRow );

			if ( ! payload.start_time ) {
				showFormError( formRow, 'A start time is required.' );
				resolve( false );
				return;
			}

			saveBtn.disabled = true;
			if ( status ) {
				status.textContent = plttData.i18n.saving || 'Saving…';
			}

			PLTT.ajax( 'pltt_save_entry', payload, function( response ) {
				saveBtn.disabled = false;
				if ( status ) {
					status.textContent = '';
				}

				if ( response.success ) {
					applySaveResponse( formRow, response.data );
					resolve( true );
				} else {
					const message = ( response.data && response.data.message ) || response.data || 'Failed to save entry.';
					showFormError( formRow, message );
					resolve( false );
				}
			} );
		} );
	}

	/**
	 * Replace the saved row's HTML with the server-rendered markup.
	 *
	 * @param {HTMLElement} formRow .pltt-entry-form-row element that was saved.
	 * @param {Object}      data    Response payload (row_html, entry_id).
	 */
	function applySaveResponse( formRow, data ) {
		const wrapper = document.createElement( 'tbody' );
		wrapper.innerHTML = data.row_html.trim();
		const newRows = Array.from( wrapper.children );

		// Replace the existing display row + form row in place.
		const displayRow = formRow.previousElementSibling;
		if ( displayRow ) {
			displayRow.replaceWith( newRows[0] );
		}
		formRow.replaceWith( newRows[1] );
		openFormRow = null;

		updateSummary();
	}

	/**
	 * Update summary cards after a save/delete.
	 */
	function updateSummary() {
		const rows = document.querySelectorAll( '.pltt-entry-compact' );
		const entryCountEl = document.querySelector( '[data-card="entry-count"]' );
		if ( entryCountEl ) {
			entryCountEl.textContent = rows.length;
		}
		// Total Hours card is not recalculated client-side; relies on full reload for now.
	}

	/**
	 * Auto-save the currently open form before opening a different one.
	 * If the open form fails to save, the new row does NOT expand.
	 *
	 * @param {HTMLElement|null} nextFormRow Form row about to be opened, or null.
	 * @return {Promise<boolean>} True if it's safe to open the next row.
	 */
	async function commitOpenForm( nextFormRow ) {
		if ( ! openFormRow || openFormRow === nextFormRow ) {
			return true;
		}
		const ok = await saveForm( openFormRow );
		return ok;
	}

	/**
	 * Resolve the form row an event originated in.
	 */
	function closestForm( target ) {
		return target.closest( '.pltt-entry-form-row' );
	}

	/**
	 * Delegate Edit, Delete, Cancel, Save clicks to the tbody.
	 */
	tbody.addEventListener( 'click', async function( e ) {
		// Edit link in compact row.
		const editLink = e.target.closest( '.pltt-edit-entry' );
		if ( editLink ) {
			e.preventDefault();
			const displayRow = editLink.closest( '.pltt-entry-compact' );
			const formRow = displayRow ? displayRow.nextElementSibling : null;
			if ( ! formRow || ! formRow.classList.contains( 'pltt-entry-form-row' ) ) {
				return;
			}

			if ( openFormRow === formRow ) {
				return; // Already open.
			}

			const safe = await commitOpenForm( formRow );
			if ( ! safe ) {
				return;
			}
			expandForm( formRow );
			return;
		}

		// Delete link in compact row.
		const deleteLink = e.target.closest( '.pltt-delete-entry' );
		if ( deleteLink ) {
			e.preventDefault();
			if ( ! confirm( plttData.i18n.confirm ) ) {
				return;
			}
			const displayRow = deleteLink.closest( '.pltt-entry-compact' );
			const entryId = displayRow ? displayRow.dataset.entryId : 0;
			const formRow = displayRow ? displayRow.nextElementSibling : null;
			if ( ! entryId ) {
				return;
			}
			PLTT.ajax( 'pltt_delete_entry', { entry_id: entryId }, function( response ) {
				if ( response.success ) {
					if ( openFormRow === formRow ) {
						openFormRow = null;
					}
					displayRow.remove();
					if ( formRow && formRow.classList.contains( 'pltt-entry-form-row' ) ) {
						formRow.remove();
					}
					updateSummary();
				} else {
					alert( response.data || 'Error deleting entry.' );
				}
			} );
			return;
		}

		// Save button inside form.
		const saveBtn = e.target.closest( '.pltt-form-save' );
		if ( saveBtn ) {
			e.preventDefault();
			const formRow = closestForm( saveBtn );
			if ( formRow ) {
				saveForm( formRow );
			}
			return;
		}

		// Cancel button inside form.
		const cancelBtn = e.target.closest( '.pltt-form-cancel' );
		if ( cancelBtn ) {
			e.preventDefault();
			const formRow = closestForm( cancelBtn );
			if ( formRow ) {
				if ( formRow._snapshot ) {
					restoreForm( formRow, formRow._snapshot );
				}
				collapseForm( formRow );
			}
			return;
		}

		// Billable $ toggle button inside form.
		const billableBtn = e.target.closest( '.pltt-form-billable-btn' );
		if ( billableBtn ) {
			e.preventDefault();
			const formRow = closestForm( billableBtn );
			if ( ! formRow ) {
				return;
			}
			const checkbox = formRow.querySelector( '.pltt-form-billable' );
			checkbox.checked = ! checkbox.checked;
			syncBillableButton( formRow );
		}
	} );

	/**
	 * Sync the billable button's visual state to its hidden checkbox.
	 * Call after any programmatic change to the checkbox (e.g. project change).
	 *
	 * @param {HTMLElement} formRow .pltt-entry-form-row element.
	 */
	function syncBillableButton( formRow ) {
		const checkbox = formRow.querySelector( '.pltt-form-billable' );
		const button = formRow.querySelector( '.pltt-form-billable-btn' );
		if ( ! checkbox || ! button ) {
			return;
		}
		const checked = !! checkbox.checked;
		button.classList.toggle( 'is-billable', checked );
		button.classList.toggle( 'not-billable', ! checked );
		button.setAttribute( 'aria-pressed', checked ? 'true' : 'false' );
	}

	/**
	 * Auto-calc duration from start/end. Editing the duration manually breaks
	 * the auto-calc relationship for the rest of the form's editing session.
	 */
	tbody.addEventListener( 'input', function( e ) {
		const formRow = closestForm( e.target );
		if ( ! formRow ) {
			return;
		}

		if ( e.target.classList.contains( 'pltt-form-start' ) || e.target.classList.contains( 'pltt-form-end' ) ) {
			if ( formRow._durationManual ) {
				return;
			}
			const start = formRow.querySelector( '.pltt-form-start' ).value;
			const end = formRow.querySelector( '.pltt-form-end' ).value;
			const minutes = calculateDuration( start, end );
			if ( minutes !== null ) {
				formRow.querySelector( '.pltt-form-duration' ).value = minutes;
			}
		} else if ( e.target.classList.contains( 'pltt-form-duration' ) ) {
			formRow._durationManual = true;
		}
	} );

	/**
	 * Client change → load projects (AJAX). Project change → apply billability default.
	 */
	tbody.addEventListener( 'change', function( e ) {
		const formRow = closestForm( e.target );
		if ( ! formRow ) {
			return;
		}

		if ( e.target.classList.contains( 'pltt-form-client' ) ) {
			if ( e.target.value === 'new' ) {
				activeFormRow = formRow;
				PLTT.showModal( 'pltt-client-modal' );
				e.target.value = '';
				return;
			}

			// Internal client → force non-billable.
			const opt = e.target.options[ e.target.selectedIndex ];
			if ( opt && opt.dataset.isInternal === '1' ) {
				formRow.querySelector( '.pltt-form-billable' ).checked = false;
				syncBillableButton( formRow );
			}

			const projectSelect = formRow.querySelector( '.pltt-form-project' );
			loadProjects( e.target, projectSelect );
			return;
		}

		if ( e.target.classList.contains( 'pltt-form-project' ) ) {
			if ( e.target.value === 'new' ) {
				const clientSelect = formRow.querySelector( '.pltt-form-client' );
				if ( ! clientSelect.value || clientSelect.value === 'new' ) {
					alert( 'Please select a client first.' );
					e.target.value = '';
					return;
				}
				activeFormRow = formRow;
				document.getElementById( 'pltt-new-project-client-id' ).value = clientSelect.value;
				if ( window.PlttProjectType ) { PlttProjectType.reset(); } PLTT.showModal( 'pltt-project-modal' );
				e.target.value = '';
				return;
			}

			// Apply billability default from the selected project.
			const opt = e.target.options[ e.target.selectedIndex ];
			if ( opt && opt.value ) {
				const billDefault = opt.dataset.billabilityDefault;
				if ( billDefault !== undefined ) {
					formRow.querySelector( '.pltt-form-billable' ).checked = billDefault === '1';
					syncBillableButton( formRow );
				}
			}
			applyFormBillableVisibility( formRow );
		}
	} );

	/**
	 * Load projects for a client into the form's project dropdown (AJAX).
	 *
	 * @param {HTMLSelectElement} clientSelect  Client dropdown.
	 * @param {HTMLSelectElement} projectSelect Project dropdown.
	 */
	function loadProjects( clientSelect, projectSelect ) {
		const clientId = clientSelect.value;
		if ( ! clientId || clientId === 'new' ) {
			projectSelect.innerHTML = '<option value="">Select project...</option>' +
				'<option value="new">+ Add new project...</option>';
			return;
		}

		PLTT.ajax( 'pltt_get_projects', { client_id: clientId }, function( response ) {
			if ( ! response.success ) {
				return;
			}
			let html = '<option value="">Select project...</option>';
			response.data.projects.forEach( function( project ) {
				const isArchived = ( project.status === 'archived' );
				if ( isArchived ) {
					return; // Don't pollute the picker with archived projects.
				}
				const billDefault = parseInt( project.billability_default, 10 ) === 1 ? '1' : '0';
				const billFlag = parseInt( project.billable_flag_applies, 10 ) === 0 ? '0' : '1';
				html += '<option value="' + parseInt( project.id, 10 ) + '" data-billability-default="' + billDefault + '" data-billable-flag="' + billFlag + '">' +
					PLTT.escapeHtml( project.name ) + '</option>';
			} );
			html += '<option value="new">+ Add new project...</option>';
			projectSelect.innerHTML = html;
			applyFormBillableVisibility( projectSelect.closest( '.pltt-entry-form' ) );
		} );
	}

	/**
	 * Show/hide a form row's billable field based on the selected project's type.
	 * Retainer/fixed-fee projects (data-billable-flag="0") bill at the period
	 * level, so the per-entry control is hidden. The hidden checkbox still submits.
	 *
	 * @param {HTMLElement} formRow The .pltt-entry-form element.
	 */
	function applyFormBillableVisibility( formRow ) {
		if ( ! formRow ) {
			return;
		}
		const sel = formRow.querySelector( '.pltt-form-project' );
		const field = formRow.querySelector( '.pltt-field-billable' );
		if ( ! sel || ! field ) {
			return;
		}
		const opt = sel.options[ sel.selectedIndex ];
		field.classList.toggle( 'pltt-hidden', !! ( opt && opt.dataset.billableFlag === '0' ) );
	}

	/**
	 * Modal: Create Client (used from the form's client dropdown).
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
			PLTT.ajax( 'pltt_create_client', { name: name, hourly_rate: rate }, function( response ) {
				saveClientBtn.disabled = false;
				if ( response.success && response.data.client ) {
					const client = response.data.client;
					// Add to every client dropdown on the page.
					document.querySelectorAll( '.pltt-form-client' ).forEach( function( select ) {
						const option = document.createElement( 'option' );
						option.value = client.id;
						option.textContent = client.name;
						const addNewOption = select.querySelector( 'option[value="new"]' );
						select.insertBefore( option, addNewOption );
					} );
					if ( activeFormRow ) {
						const clientSelect = activeFormRow.querySelector( '.pltt-form-client' );
						clientSelect.value = client.id;
						const projectSelect = activeFormRow.querySelector( '.pltt-form-project' );
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
	 * Modal: Create Project.
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

			this.disabled = true;
			PLTT.ajax( 'pltt_create_project', { name: name, client_id: clientId, hourly_rate: rate, budget_hours: vals.budget_hours, budget_fee: PLTT.parseCurrencyValue( vals.budget_fee ), recurring_period: vals.recurring_period, non_billable: vals.non_billable }, function( response ) {
				saveProjectBtn.disabled = false;
				if ( response.success && response.data.project ) {
					const project = response.data.project;
					if ( activeFormRow ) {
						const projectSelect = activeFormRow.querySelector( '.pltt-form-project' );
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

	/**
	 * Modal: Create Tag.
	 */
	const saveTagBtn = document.getElementById( 'pltt-save-tag' );
	if ( saveTagBtn ) {
		saveTagBtn.addEventListener( 'click', function() {
			const nameInput = document.getElementById( 'pltt-new-tag-name' );
			const name = nameInput.value.trim();
			if ( ! name ) {
				alert( 'Please enter a tag name.' );
				nameInput.focus();
				return;
			}

			this.disabled = true;
			PLTT.ajax( 'pltt_create_tag', { tag_name: name }, function( response ) {
				saveTagBtn.disabled = false;
				if ( response.success && response.data.tag ) {
					const tag = response.data.tag;
					// Add to all pickers; auto-select only in the triggering picker.
					tbody.querySelectorAll( '.pltt-entry-form-row' ).forEach( function( formRow ) {
						const picker = tagPickers.get( formRow );
						if ( picker ) {
							picker.addTagOption( tag, picker === activeTagPicker );
						}
					} );
					if ( tagSuggestions.indexOf( tag ) === -1 ) {
						tagSuggestions.push( tag );
					}
					PLTT.hideModal( 'pltt-tag-modal' );
					nameInput.value = '';
					activeTagPicker = null;
				} else {
					alert( response.data || 'Error creating tag.' );
				}
			} );
		} );
	}

	/**
	 * Escape key cancels the open form.
	 */
	document.addEventListener( 'keydown', function( e ) {
		if ( e.key === 'Escape' && openFormRow ) {
			if ( openFormRow._snapshot ) {
				restoreForm( openFormRow, openFormRow._snapshot );
			}
			collapseForm( openFormRow );
		}
	} );

} )();

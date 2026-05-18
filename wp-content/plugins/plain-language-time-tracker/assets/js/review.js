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
				checkbox.checked = ! checkbox.checked;
				checkbox.dispatchEvent( new Event( 'change' ) );
			}
		} );
	} );

	// Billable checkbox change — update $ symbol.
	document.querySelectorAll( '.pltt-billable' ).forEach( function( checkbox ) {
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
					var dataAttr = ' data-billability-default="' + billDefault + '"' +
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
				PLTT.showModal( 'pltt-project-modal' );
				this.value = '';
			} else {
				// Apply project's billability default to the billable checkbox.
				var selectedOpt = this.options[ this.selectedIndex ];
				if ( selectedOpt && selectedOpt.value ) {
					var billDefault = selectedOpt.dataset.billabilityDefault;
					if ( billDefault !== undefined ) {
						var entryRow = this.closest( '.pltt-entry-row' );
						var checkbox = entryRow && entryRow.querySelector( '.pltt-billable' );
						if ( checkbox ) {
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
			const nameInput = document.getElementById( 'pltt-new-project-name' );
			const clientIdInput = document.getElementById( 'pltt-new-project-client-id' );
			const rateInput = document.getElementById( 'pltt-new-project-rate' );
			const name = nameInput.value.trim();
			const clientId = clientIdInput.value;
			const rate = PLTT.parseCurrencyValue( rateInput.value );

			if ( ! name ) {
				alert( 'Please enter a project name.' );
				nameInput.focus();
				return;
			}

			this.disabled = true;

			PLTT.ajax( 'pltt_create_project', {
				name: name,
				client_id: clientId,
				hourly_rate: rate
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
						option.selected = true;

						const addNewOption = projectSelect.querySelector( 'option[value="new"]' );
						projectSelect.insertBefore( option, addNewOption );
						projectSelect.dispatchEvent( new Event( 'change' ) );
					}

					PLTT.hideModal( 'pltt-project-modal' );
					nameInput.value = '';
					rateInput.value = '';
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

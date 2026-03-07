/**
 * Reports Screen JavaScript.
 *
 * Handles date validation, date preset selection, client-project cascade,
 * and negate filter toggles.
 */

( function() {
	'use strict';

	const dateStart = document.getElementById( 'pltt-date-start' );
	const dateEnd = document.getElementById( 'pltt-date-end' );

	if ( ! dateStart || ! dateEnd ) {
		return;
	}

	/**
	 * Date validation - ensure start date is not after end date.
	 */
	dateStart.addEventListener( 'change', function() {
		if ( dateEnd.value && this.value > dateEnd.value ) {
			dateEnd.value = this.value;
		}
	} );

	dateEnd.addEventListener( 'change', function() {
		if ( dateStart.value && this.value < dateStart.value ) {
			dateStart.value = this.value;
		}
	} );

	/**
	 * Date preset selection - populate date fields and submit.
	 */
	const presetSelect = document.getElementById( 'pltt-date-preset' );
	if ( presetSelect ) {
		presetSelect.addEventListener( 'change', function() {
			if ( ! this.value ) {
				return;
			}
			var parts = this.value.split( '|' );
			dateStart.value = parts[0];
			dateEnd.value = parts[1];
			this.form.submit();
		} );
	}

	/**
	 * Client -> Project cascade filter.
	 *
	 * When the client dropdown changes, rebuild the project dropdown
	 * to show only that client's projects (or all projects if "All Clients").
	 */
	const clientSelect = document.getElementById( 'pltt-filter-client' );
	const projectSelect = document.getElementById( 'pltt-filter-project' );

	if ( clientSelect && projectSelect && typeof plttProjectsByClient !== 'undefined' ) {
		clientSelect.addEventListener( 'change', function() {
			const clientId = this.value;

			// Remember current project selection (will be cleared if not valid).
			const currentProject = projectSelect.value;

			// Clear current project options.
			projectSelect.innerHTML = '';

			// Add default "All Projects" option.
			const defaultOpt = document.createElement( 'option' );
			defaultOpt.value = '';
			defaultOpt.textContent = 'All Projects';
			projectSelect.appendChild( defaultOpt );

			var foundCurrent = false;

			if ( clientId === '' ) {
				// No client selected: show all projects grouped by client.
				Object.keys( plttProjectsByClient ).forEach( function( cid ) {
					var group = document.createElement( 'optgroup' );
					group.label = ( typeof plttClientNames !== 'undefined' && plttClientNames[ cid ] )
						? plttClientNames[ cid ]
						: 'Client ' + cid;
					plttProjectsByClient[ cid ].forEach( function( proj ) {
						var opt = document.createElement( 'option' );
						opt.value = proj.id;
						opt.textContent = proj.name;
						if ( String( proj.id ) === currentProject ) {
							foundCurrent = true;
						}
						group.appendChild( opt );
					} );
					projectSelect.appendChild( group );
				} );
			} else {
				// Single client selected: flat list.
				var projects = plttProjectsByClient[ clientId ] || [];
				projects.forEach( function( proj ) {
					var opt = document.createElement( 'option' );
					opt.value = proj.id;
					opt.textContent = proj.name;
					if ( String( proj.id ) === currentProject ) {
						foundCurrent = true;
					}
					projectSelect.appendChild( opt );
				} );
			}

			// Restore previous selection if still valid, otherwise reset.
			projectSelect.value = foundCurrent ? currentProject : '';
		} );
	}

	/**
	 * Negate filter toggles.
	 *
	 * Each toggle button flips its associated hidden input between 0 and 1,
	 * switching the filter between "is" (include) and "not" (exclude).
	 */
	document.querySelectorAll( '.pltt-negate-toggle' ).forEach( function( btn ) {
		btn.addEventListener( 'click', function() {
			var targetName = this.getAttribute( 'data-target' );
			var hiddenInput = this.closest( 'form' ).querySelector(
				'input[name="' + targetName + '"]'
			);
			if ( ! hiddenInput ) {
				return;
			}

			var isNegate = hiddenInput.value === '1';
			hiddenInput.value = isNegate ? '0' : '1';
			this.textContent = isNegate ? 'is' : 'not';
			this.classList.toggle( 'pltt-negate-active', ! isNegate );
		} );
	} );

	/**
	 * Show/hide negate toggles based on select value.
	 *
	 * When a filter select is set to "All" (empty value), the negate toggle
	 * is hidden because negating "all" is meaningless.
	 */
	document.querySelectorAll( '.pltt-filter-input-wrap select' ).forEach( function( sel ) {
		sel.addEventListener( 'change', function() {
			var wrap = this.closest( '.pltt-filter-input-wrap' );
			if ( ! wrap ) {
				return;
			}

			var toggle = wrap.querySelector( '.pltt-negate-toggle' );
			var hidden = wrap.querySelector( 'input[type="hidden"]' );
			if ( ! toggle || ! hidden ) {
				return;
			}

			if ( this.value === '' ) {
				toggle.style.display = 'none';
				hidden.value = '0';
				toggle.textContent = 'is';
				toggle.classList.remove( 'pltt-negate-active' );
			} else {
				toggle.style.display = '';
			}
		} );
	} );

	/**
	 * Inline field editing — Billable, Invoiced, Tags.
	 *
	 * Saves each change immediately via AJAX. No page reload needed.
	 */
	( function() {
		if ( typeof PlttTagPicker === 'undefined' || typeof plttAllTags === 'undefined' ) {
			return;
		}

		/**
		 * Send a field update and handle the response.
		 *
		 * @param {HTMLElement} btn     The toggle button.
		 * @param {string}      field   Field name.
		 * @param {string}      value   New value.
		 * @param {Function}    onSuccess Called on AJAX success.
		 * @param {Function}    onError   Called on AJAX error (revert).
		 */
		function saveField( btn, field, value, onSuccess, onError ) {
			var entryId = btn.dataset.entryId;
			btn.classList.add( 'pltt-saving' );
			btn.disabled = true;

			PLTT.ajax(
				'pltt_update_entry_field',
				{ entry_id: entryId, field: field, value: value },
				function( response ) {
					btn.classList.remove( 'pltt-saving' );
					btn.disabled = false;
					if ( response.success ) {
						onSuccess( response.data );
					} else {
						onError();
					}
				}
			);
		}

		/**
		 * Billable toggle — click handler via delegation.
		 */
		document.addEventListener( 'click', function( e ) {
			var btn = e.target.closest( '.pltt-billable-symbol.pltt-inline-toggle' );
			if ( ! btn ) {
				return;
			}

			var currentValue = btn.dataset.value === '1';
			var newValue     = currentValue ? '0' : '1';
			var isBillable   = ! currentValue;
			var row          = btn.closest( 'tr' );

			// Optimistic update.
			btn.classList.toggle( 'is-billable', isBillable );
			btn.classList.toggle( 'not-billable', ! isBillable );
			btn.dataset.value = newValue;
			var label = isBillable ? 'Billable \u2014 click to toggle' : 'Not billable \u2014 click to toggle';
			btn.setAttribute( 'aria-label', label );
			btn.setAttribute( 'title', label );

			// Show/hide the Inv. toggle based on billable state.
			// If turning off billable and entry is currently invoiced, clear it too.
			if ( row ) {
				var invoicedBtn = row.querySelector( '.pltt-invoiced-toggle' );
				if ( invoicedBtn ) {
					if ( isBillable ) {
						invoicedBtn.style.visibility = '';
					} else {
						invoicedBtn.style.visibility = 'hidden';
						if ( invoicedBtn.dataset.value === '1' ) {
							// Clear invoiced state optimistically.
							invoicedBtn.classList.remove( 'is-invoiced' );
							invoicedBtn.classList.add( 'not-invoiced' );
							invoicedBtn.dataset.value = '0';
							invoicedBtn.textContent = '\u25cb';
							row.classList.remove( 'pltt-billed' );
							// Persist the cleared invoiced state.
							PLTT.ajax( 'pltt_update_entry_field', {
								entry_id: invoicedBtn.dataset.entryId,
								field: 'billed',
								value: '0'
							}, function() {} );
						}
					}
				}
			}

			saveField(
				btn,
				'billable',
				newValue,
				function( data ) {
					// Update the Amount cell with the server-calculated value.
					var amountCell = row && row.querySelector( '.pltt-amount-col' );
					if ( amountCell ) {
						var amount = data && parseFloat( data.billable_amount );
						if ( amount > 0 ) {
							amountCell.textContent = '$' + amount.toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
						} else {
							amountCell.innerHTML = '<span class="pltt-empty">—</span>';
						}
					}
				},
				function() {
					// Revert.
					btn.classList.toggle( 'is-billable', currentValue );
					btn.classList.toggle( 'not-billable', ! currentValue );
					btn.dataset.value = currentValue ? '1' : '0';
					var revertLabel = currentValue ? 'Billable \u2014 click to toggle' : 'Not billable \u2014 click to toggle';
					btn.setAttribute( 'aria-label', revertLabel );
					btn.setAttribute( 'title', revertLabel );
					// Revert Inv. toggle visibility too.
					if ( row ) {
						var invoicedBtn = row.querySelector( '.pltt-invoiced-toggle' );
						if ( invoicedBtn ) {
							invoicedBtn.style.visibility = currentValue ? '' : 'hidden';
						}
					}
				}
			);
		} );

		/**
		 * Invoiced toggle — click handler via delegation.
		 */
		document.addEventListener( 'click', function( e ) {
			var btn = e.target.closest( '.pltt-invoiced-toggle' );
			if ( ! btn ) {
				return;
			}

			var currentValue = btn.dataset.value === '1';
			var newValue     = currentValue ? '0' : '1';
			var isInvoiced   = ! currentValue;
			var row          = btn.closest( 'tr' );

			// Optimistic update.
			btn.classList.toggle( 'is-invoiced', isInvoiced );
			btn.classList.toggle( 'not-invoiced', ! isInvoiced );
			btn.dataset.value = newValue;
			btn.textContent = isInvoiced ? '\u2713' : '\u25cb';
			var label = isInvoiced ? 'Invoiced \u2014 click to toggle' : 'Not invoiced \u2014 click to toggle';
			btn.setAttribute( 'aria-label', label );
			btn.setAttribute( 'title', label );
			if ( row ) {
				row.classList.toggle( 'pltt-billed', isInvoiced );
			}

			saveField(
				btn,
				'billed',
				newValue,
				function() { /* already updated optimistically */ },
				function() {
					// Revert.
					btn.classList.toggle( 'is-invoiced', currentValue );
					btn.classList.toggle( 'not-invoiced', ! currentValue );
					btn.dataset.value = currentValue ? '1' : '0';
					btn.textContent = currentValue ? '\u2713' : '\u25cb';
					var revertLabel = currentValue ? 'Invoiced \u2014 click to toggle' : 'Not invoiced \u2014 click to toggle';
					btn.setAttribute( 'aria-label', revertLabel );
					btn.setAttribute( 'title', revertLabel );
					if ( row ) {
						row.classList.toggle( 'pltt-billed', currentValue );
					}
				}
			);
		} );

		/**
		 * Initialize inline tag pickers on all .pltt-tag-input-wrap elements in the report table.
		 */
		document.querySelectorAll( '#pltt-report-content .pltt-tag-input-wrap' ).forEach( function( wrap ) {
			var entryId = wrap.dataset.entryId;

			new PlttTagPicker(
				wrap,
				( typeof plttAllTags !== 'undefined' ) ? plttAllTags : [],
				null,
				function( selectedTags, csvValue ) {
					// onClose: save tags via AJAX.
					PLTT.ajax(
						'pltt_update_entry_field',
						{ entry_id: entryId, field: 'tags', value: csvValue },
						function( response ) {
							if ( ! response.success ) {
								// Silent failure — tags are still shown correctly in the picker.
								// A future improvement could show an inline error.
							}
						}
					);
				}
			);
		} );
	} )();
} )();

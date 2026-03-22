/**
 * Reports Screen JavaScript.
 *
 * Handles date validation, date preset selection, client-project cascade,
 * and negate filter toggles.
 */

( function() {
	'use strict';

	/**
	 * Date Navigator widget.
	 *
	 * Handles preset selection, custom range input, and prev/next navigation.
	 * The widget uses hidden <input name="from"> and <input name="to"> so the
	 * form submits the same way as before — no other form code changes needed.
	 */
	( function initDateNav() {
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
		 */
		function detectStep( from, to ) {
			var f = parseDate( from );
			var t = parseDate( to );
			var diffMs   = t - f;
			var totalDays = Math.round( diffMs / 86400000 ) + 1;

			// Full or partial month: starts on 1st of a month.
			if ( f.getDate() === 1 ) {
				return { unit: 'month' };
			}

			// Full week: exactly 7 days starting on the configured week-start day.
			if ( totalDays === 7 && f.getDay() === weekStart ) {
				return { unit: 'week' };
			}

			// Full year: Jan 1 → Dec 31.
			if ( f.getMonth() === 0 && f.getDate() === 1 && t.getMonth() === 11 && t.getDate() === 31 ) {
				return { unit: 'year' };
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
				var offset = direction * 7;
				var wFrom  = new Date( f.getFullYear(), f.getMonth(), f.getDate() + offset );
				var wTo    = new Date( t.getFullYear(), t.getMonth(), t.getDate() + offset );
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
			var selected = dropdown.querySelector( '.pltt-date-nav-option[aria-selected="true"]' )
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
				var next = options[ idx + 1 ] || options[0];
				next.focus();
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				var prev = options[ idx - 1 ] || options[ options.length - 1 ];
				prev.focus();
			} else if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				if ( focused && options.includes( focused ) ) {
					focused.click();
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

		// ── Prev / Next navigation ───────────────────────────────────────────

		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function() {
				var range = shiftRange( fromInput.value, toInput.value, -1 );
				applyRange( range.from, range.to );
			} );
		}

		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function() {
				var range = shiftRange( fromInput.value, toInput.value, 1 );
				applyRange( range.from, range.to );
			} );
		}
	} )();

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

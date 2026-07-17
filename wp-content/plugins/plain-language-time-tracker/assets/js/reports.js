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
						opt.textContent = proj.archived ? proj.name + ' (Archived)' : proj.name;
						if ( proj.archived ) {
							opt.className = 'pltt-project-archived';
						}
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
					opt.textContent = proj.archived ? proj.name + ' (Archived)' : proj.name;
					if ( proj.archived ) {
						opt.className = 'pltt-project-archived';
					}
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
		/**
		 * Parse a rendered currency cell ("$1,234.56" or "—") back to a number.
		 *
		 * @param {string} text Cell text content.
		 * @return {number} Parsed amount, or 0 if not a number.
		 */
		function parseAmount( text ) {
			if ( ! text ) { return 0; }
			var n = parseFloat( String( text ).replace( /[^0-9.\-]/g, '' ) );
			return isFinite( n ) ? n : 0;
		}

		/**
		 * Apply a billable delta to the summary stat cards live (Billable Hours +
		 * its vs-last-period sub-line, Billable Amount + its vs-last-period sub-line).
		 * Mirrors the PHP math in templates/reports.php so a refresh produces the
		 * same numbers. No-op if the cards aren't on the page.
		 *
		 * SYNC (OPT-PERF-E / OPT-DUP-F): the pct-change + arrow logic below mirrors
		 * pltt_pct_change_indicator() in includes/helpers.php — the ±5% neutral band
		 * and the →/↑/↓ glyphs must stay identical to the PHP helper.
		 *
		 * @param {number} minDelta Signed change in billable minutes.
		 * @param {number} amtDelta Signed change in billable dollars.
		 */
		function updateBillableCards( minDelta, amtDelta ) {
			if ( typeof plttReportStats === 'undefined' ) { return; }

			plttReportStats.billableMinutes = Math.max( 0, plttReportStats.billableMinutes + minDelta );
			plttReportStats.billableAmount  = Math.max( 0, Math.round( ( plttReportStats.billableAmount + amtDelta ) * 100 ) / 100 );

			var hrsEl = document.getElementById( 'pltt-stat-billable-hours' );
			if ( hrsEl ) {
				hrsEl.textContent = PLTT.formatDuration( plttReportStats.billableMinutes );
				// Keep the decimal-hours hover hint (data-tip-rows) in sync with the figure.
				if ( hrsEl.hasAttribute( 'data-tip-rows' ) ) {
					hrsEl.setAttribute(
						'data-tip-rows',
						JSON.stringify( [ [ '', '= ' + PLTT.formatHours( plttReportStats.billableMinutes ) + ' h' ] ] )
					);
				}
			}

			var hrsChangeEl = document.getElementById( 'pltt-stat-hours-change' );
			if ( hrsChangeEl ) {
				var prevMins = plttReportStats.prevMinutes;
				var currMins = plttReportStats.billableMinutes;
				var hrsPct   = prevMins > 0 ? ( currMins - prevMins ) / prevMins * 100 : 100;
				var hrsCls, hrsIcon;
				if ( Math.abs( hrsPct ) < 5 ) {
					hrsCls = 'status-neutral'; hrsIcon = '→';
				} else if ( hrsPct > 0 ) {
					hrsCls = 'status-increase'; hrsIcon = '↑';
				} else {
					hrsCls = 'status-decrease'; hrsIcon = '↓';
				}
				hrsChangeEl.className   = hrsCls;
				hrsChangeEl.textContent = hrsIcon + ' ' + Math.round( Math.abs( hrsPct ) ) + '%';
			}

			var amtEl = document.getElementById( 'pltt-stat-billable-amount' );
			if ( amtEl ) {
				amtEl.textContent = '$' + plttReportStats.billableAmount.toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
			}

			var card = document.getElementById( 'pltt-stat-amount-card' );
			if ( card ) {
				card.classList.toggle( 'pltt-hidden', ! ( plttReportStats.billableAmount > 0 ) );
			}

			var changeEl = document.getElementById( 'pltt-stat-amount-change' );
			if ( changeEl ) {
				var prev = plttReportStats.prevAmount;
				var curr = plttReportStats.billableAmount;
				var pct  = prev > 0 ? ( curr - prev ) / prev * 100 : 100;
				var cls, icon;
				if ( Math.abs( pct ) < 5 ) {
					cls = 'status-neutral'; icon = '→';
				} else if ( pct > 0 ) {
					cls = 'status-increase'; icon = '↑';
				} else {
					cls = 'status-decrease'; icon = '↓';
				}
				changeEl.className   = cls;
				changeEl.textContent = icon + ' ' + Math.round( Math.abs( pct ) ) + '%';
			}
		}

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
		 * OPT-DUP19 / Group H: shared inline-toggle binding.
		 *
		 * @param {Object} opts
		 *   selector  - CSS selector for the toggle button (matched via .closest).
		 *   field     - server field name ('billable' or 'billed').
		 *   classes   - { on, off } classes to toggle on the button.
		 *   labels    - { on, off } accessible label strings.
		 *   apply     - fn( btn, row, isOn ) - extra UI changes when state flips on/off.
		 *   onSuccess - fn( btn, row, data ) - optional, called after server confirms.
		 */
		function bindInlineToggle( opts ) {
			document.addEventListener( 'click', function( e ) {
				var btn = e.target.closest( opts.selector );
				if ( ! btn ) {
					return;
				}

				var currentOn = btn.dataset.value === '1';
				var newValue  = currentOn ? '0' : '1';
				var isOn      = ! currentOn;
				var row       = btn.closest( 'tr' );

				function setVisual( on ) {
					btn.classList.toggle( opts.classes.on,  on );
					btn.classList.toggle( opts.classes.off, ! on );
					btn.dataset.value = on ? '1' : '0';
					var label = on ? opts.labels.on : opts.labels.off;
					btn.setAttribute( 'aria-label', label );
					btn.setAttribute( 'title', label );
					if ( typeof opts.apply === 'function' ) {
						opts.apply( btn, row, on );
					}
				}

				// Optimistic update.
				setVisual( isOn );

				saveField(
					btn,
					opts.field,
					newValue,
					function( data ) {
						if ( typeof opts.onSuccess === 'function' ) {
							opts.onSuccess( btn, row, data );
						}
					},
					function() {
						setVisual( currentOn );
					}
				);
			} );
		}

		// Billable toggle: also shows/hides the Inv. toggle and clears invoiced
		// state when turning billable off on a previously-invoiced row.
		bindInlineToggle( {
			selector: '.pltt-billable-symbol.pltt-inline-toggle',
			field:    'billable',
			classes:  { on: 'is-billable', off: 'not-billable' },
			labels:   {
				on:  'Billable — click to toggle',
				off: 'Not billable — click to toggle'
			},
			apply: function( btn, row, isBillable ) {
				if ( ! row ) { return; }
				var invoicedBtn = row.querySelector( '.pltt-invoiced-toggle' );
				if ( ! invoicedBtn ) { return; }
				if ( isBillable ) {
					invoicedBtn.style.visibility = '';
				} else {
					invoicedBtn.style.visibility = 'hidden';
					if ( invoicedBtn.dataset.value === '1' ) {
						// Cascade: clear invoiced state when billable goes off.
						invoicedBtn.classList.remove( 'is-invoiced' );
						invoicedBtn.classList.add( 'not-invoiced' );
						invoicedBtn.dataset.value = '0';
						invoicedBtn.textContent   = '○';
						row.classList.remove( 'pltt-billed' );
						PLTT.ajax( 'pltt_update_entry_field', {
							entry_id: invoicedBtn.dataset.entryId,
							field:    'billed',
							value:    '0'
						}, function() {} );
					}
				}
			},
			onSuccess: function( btn, row, data ) {
				// Update the Amount cell with the server-calculated value, then push
				// the deltas to the summary stat cards so they update without a refresh.
				var amountCell = row && row.querySelector( '.pltt-amount-col' );
				var newAmount  = data ? parseFloat( data.billable_amount ) || 0 : 0;
				var oldAmount  = amountCell ? parseAmount( amountCell.textContent ) : 0;

				if ( amountCell ) {
					if ( newAmount > 0 ) {
						amountCell.textContent = '$' + newAmount.toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
					} else {
						amountCell.innerHTML = '<span class="pltt-empty">—</span>';
					}
				}

				// btn.dataset.value already reflects the new (post-toggle) state.
				var isBillable = btn.dataset.value === '1';
				var minutes    = parseInt( btn.dataset.minutes, 10 ) || 0;
				updateBillableCards( isBillable ? minutes : -minutes, newAmount - oldAmount );

				// Let the billing select row react: a newly-billable row becomes a
				// checkable box; a now-non-billable one becomes a muted "—".
				document.dispatchEvent( new CustomEvent( 'pltt:entry-billable-changed', {
					detail: {
						entryId:  row ? row.getAttribute( 'data-entry-id' ) : '',
						billable: isBillable,
						amount:   newAmount
					}
				} ) );
			}
		} );

		// Invoiced toggle: simpler - flips the checkmark and the row tint.
		bindInlineToggle( {
			selector: '.pltt-invoiced-toggle',
			field:    'billed',
			classes:  { on: 'is-invoiced', off: 'not-invoiced' },
			labels:   {
				on:  'Invoiced — click to toggle',
				off: 'Not invoiced — click to toggle'
			},
			apply: function( btn, row, isInvoiced ) {
				btn.textContent = isInvoiced ? '✓' : '○';
				if ( row ) {
					row.classList.toggle( 'pltt-billed', isInvoiced );
				}
			}
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
				},
				( typeof plttTagGroups !== 'undefined' ) ? plttTagGroups : {}
			);
		} );
	} )();
} )();

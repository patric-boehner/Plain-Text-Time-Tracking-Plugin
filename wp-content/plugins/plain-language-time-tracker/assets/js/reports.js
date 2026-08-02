/**
 * Reports Screen JavaScript.
 *
 * Handles the client-project cascade and negate filter toggles. The date range
 * picker is the shared widget in assets/js/pltt-date-nav.js (OPT-DUP18).
 */

( function() {
	'use strict';

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
		 *   field     - server field name ('billable' or 'tags').
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

		/**
		 * Repaint a row's Status badge to match the billable flag.
		 *
		 * A row only gets a clickable toggle when the per-entry billable flag
		 * applies AND no committed bill record covers it, so the badge can only
		 * be "Unbilled" or "Not charged" here. The "Billed" badge and the dash
		 * are unreachable from this control — the guard below keeps a "Billed"
		 * row untouched if the toggle ever renders on one.
		 *
		 * Markup mirrors pltt_render_entry_table() in includes/helpers.php.
		 *
		 * @param {HTMLElement} row The entry's <tr>.
		 * @param {boolean}     on  True when the entry is now billable.
		 */
		function setStatusBadge( row, on ) {
			var cell = row && row.querySelector( '.pltt-status-col' );
			if ( ! cell || cell.querySelector( '.pltt-badge-billed' ) ) {
				return;
			}

			var i18n  = ( typeof plttData !== 'undefined' && plttData.i18n ) || {};
			var badge = cell.querySelector( '.pltt-badge' );
			if ( ! badge ) {
				badge = document.createElement( 'span' );
				cell.textContent = '';
				cell.appendChild( badge );
			}

			badge.className = 'pltt-badge ' + ( on ? 'pltt-badge-success' : 'pltt-badge-notcharged' );
			badge.textContent = on
				? ( i18n.statusUnbilled || 'Unbilled' )
				: ( i18n.statusNotCharged || 'Not charged' );
			badge.title = on
				? ( i18n.statusUnbilledTitle || 'Chargeable — not on a bill record yet' )
				: ( i18n.statusNotChargedTitle || 'Billable was switched off for this entry' );
		}

		/**
		 * Apply a billable-dollar delta to the day-group header this row sits in.
		 *
		 * The header sums exactly the entries the Amount column shows a figure
		 * for, so leaving it alone would let the day total and the rows under it
		 * disagree until the next page load.
		 *
		 * @param {HTMLElement} row      The entry's <tr>.
		 * @param {number}      amtDelta Signed change in billable dollars.
		 */
		function updateDayTotal( row, amtDelta ) {
			var group = row && row.closest( '.pltt-date-group' );
			var meta  = group && group.querySelector( '.pltt-date-group-meta' );
			if ( ! meta || ! amtDelta ) {
				return;
			}

			var total = ( parseFloat( meta.dataset.dayAmount ) || 0 ) + amtDelta;
			total = Math.max( 0, Math.round( total * 100 ) / 100 );
			meta.dataset.dayAmount = total.toFixed( 2 );

			var valueEl = meta.querySelector( '.pltt-day-amount-value' );
			if ( valueEl ) {
				valueEl.textContent = '$' + total.toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
			}

			var wrapEl = meta.querySelector( '.pltt-day-amount' );
			if ( wrapEl ) {
				wrapEl.classList.toggle( 'pltt-hidden', ! ( total > 0 ) );
			}
		}

		// Billable toggle. Billed state is not a per-entry field any more — it
		// comes from bill-record coverage — so there is nothing to cascade into.
		bindInlineToggle( {
			selector: '.pltt-billable-symbol.pltt-inline-toggle',
			field:    'billable',
			classes:  { on: 'is-billable', off: 'not-billable' },
			labels:   {
				on:  'Billable — click to toggle',
				off: 'Not billable — click to toggle'
			},
			// Runs on the optimistic flip and again on rollback, so a failed save
			// puts the badge back where it was.
			apply: function( btn, row, on ) {
				setStatusBadge( row, on );
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
				updateDayTotal( row, newAmount - oldAmount );

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

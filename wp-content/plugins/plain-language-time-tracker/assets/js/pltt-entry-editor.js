/**
 * PlttEntryEditor — the shared "edit entry" modal.
 *
 * One editing surface for a recorded entry, opened from a row's kebab on the
 * Overview entries table and on Today. Replaces the per-entry expanding form
 * that used to be rendered hidden beneath every row.
 *
 *   PlttEntryEditor.open( 123 );
 *
 * On a successful save it dispatches `pltt:entry-saved` on document, with
 * detail { entryId, entry, requiresReload, rowHtml }. Each screen listens and
 * repaints its own row — the two screens draw entry rows differently (the
 * Overview's is 8 columns from pltt_render_entry_table(), Today's is 4-or-5
 * from entries-editor.php), so the server sends both a JSON payload and
 * pre-rendered markup and the listener takes whichever suits it.
 *
 * `requiresReload` says the change moved the entry rather than just relabelled
 * it — a different date, client, project or billing state. See save_entry() in
 * class-pltt-ajax.php for why that can't be repainted in place.
 *
 * Most of the behaviour here is lifted from the inline editor in review.js:
 * duration auto-calc, the client→project cascade, the retainer/fixed-fee
 * billable rule, and the two quick-add modals. What's gone is everything that
 * existed only because there was a form per row — the snapshot/restore pair,
 * expand/collapse, and the auto-save-when-you-click-another-row dance. One
 * form, repopulated on open, needs none of it.
 *
 * Requires: shared.js (PLTT), tag-picker.js (PlttTagPicker),
 * templates/partials/entry-edit-modal.php, templates/partials/entry-editor-modals.php.
 *
 * Vanilla JS.
 */
( function() {
	'use strict';

	var MODAL_ID = 'pltt-entry-edit-modal';
	// How long a prefetched entry stays usable. Long enough to cover reading a
	// menu and clicking; short enough that a menu left open never fills the form
	// with stale values.
	var PREFETCH_TTL = 30000;

	// The most recent prefetch: { id, entry, at }. One entry, consumed on open.
	var pending = null;

	var modal = null;
	var form = null;
	var els = {};
	var tagPicker = null;
	var currentEntryId = 0;
	// Set once the user types in Duration: start/end stop driving it for the
	// rest of this open.
	var durationManual = false;

	function qs( selector ) {
		return form ? form.querySelector( selector ) : null;
	}

	// ── Errors and status ────────────────────────────────────────────────

	function showError( message ) {
		if ( els.error ) {
			els.error.textContent = message;
			els.error.classList.add( 'is-visible' );
		}
	}

	function clearError() {
		if ( els.error ) {
			els.error.textContent = '';
			els.error.classList.remove( 'is-visible' );
		}
	}

	function setStatus( text ) {
		if ( els.status ) {
			els.status.textContent = text || '';
		}
	}

	// ── Billable ─────────────────────────────────────────────────────────

	/**
	 * Match the $ button to its hidden checkbox.
	 */
	function syncBillableButton() {
		var checked = !! ( els.billable && els.billable.checked );
		if ( ! els.billableBtn ) {
			return;
		}
		els.billableBtn.classList.toggle( 'is-billable', checked );
		els.billableBtn.classList.toggle( 'not-billable', ! checked );
		els.billableBtn.setAttribute( 'aria-pressed', checked ? 'true' : 'false' );
	}

	/**
	 * Retainer and fixed-fee projects bill at the period level, so the
	 * per-entry control doesn't apply. Hide it AND force it off — hiding alone
	 * leaves a checked box that still posts.
	 */
	function applyBillableVisibility() {
		var opt = els.project ? els.project.options[ els.project.selectedIndex ] : null;
		var hide = !! ( opt && opt.dataset.billableFlag === '0' );

		if ( hide && els.billable ) {
			els.billable.checked = false;
			syncBillableButton();
		}
		if ( els.billableField ) {
			els.billableField.classList.toggle( 'pltt-hidden', hide );
		}
	}

	// ── Projects ─────────────────────────────────────────────────────────

	/**
	 * Fill the project select for the selected client.
	 *
	 * Archived projects are left out — they'd clutter the picker — except the
	 * one this entry already points at, which the server includes when told
	 * about it. Dropping it silently would reassign the entry on the next save.
	 *
	 * @param {number|string} selectProjectId Project to select once loaded.
	 */
	function loadProjects( selectProjectId ) {
		var clientId = els.client ? els.client.value : '';
		var keepId = parseInt( selectProjectId, 10 ) || 0;

		if ( ! clientId || clientId === 'new' ) {
			els.project.innerHTML = '<option value="">Select project...</option>' +
				'<option value="new">+ Add new project...</option>';
			applyBillableVisibility();
			return;
		}

		PLTT.ajax(
			'pltt_get_projects',
			{ client_id: clientId, current_project_id: keepId },
			function( response ) {
				if ( ! response.success ) {
					applyBillableVisibility();
					return;
				}

				var html = '<option value="">Select project...</option>';
				response.data.projects.forEach( function( project ) {
					var id = parseInt( project.id, 10 );
					if ( project.status === 'archived' && id !== keepId ) {
						return;
					}
					var name = project.status === 'archived' ? project.name + ' (Archived)' : project.name;
					var billDefault = parseInt( project.billability_default, 10 ) === 1 ? '1' : '0';
					var billFlag = parseInt( project.billable_flag_applies, 10 ) === 0 ? '0' : '1';
					html += '<option value="' + id + '"' +
						' data-billability-default="' + billDefault + '"' +
						' data-billable-flag="' + billFlag + '"' +
						( project.status === 'archived' ? ' data-archived="1"' : '' ) +
						'>' + PLTT.escapeHtml( name ) + '</option>';
				} );
				html += '<option value="new">+ Add new project...</option>';

				els.project.innerHTML = html;
				if ( keepId ) {
					els.project.value = String( keepId );
				}
				els.project.dataset.currentProjectId = keepId ? String( keepId ) : '';
				applyBillableVisibility();
			}
		);
	}

	// ── Tags ─────────────────────────────────────────────────────────────

	function ensureTagPicker() {
		if ( tagPicker || typeof PlttTagPicker === 'undefined' ) {
			return;
		}
		var wrap = qs( '.pltt-tag-input-wrap' );
		if ( ! wrap ) {
			return;
		}

		var suggestions = ( typeof plttAllTags !== 'undefined' ) ? plttAllTags : [];
		var groups = ( typeof plttTagGroups !== 'undefined' ) ? plttTagGroups : {};

		// The same "+ Add new tag…" path the other pickers use. Only one picker
		// lives in this modal, so the getPickers callback is a one-item list.
		var openTagModal = PLTT.wireTagModal(
			function() {
				return tagPicker ? [ tagPicker ] : [];
			},
			function( tag ) {
				if ( suggestions.indexOf( tag ) === -1 ) {
					suggestions.push( tag );
				}
			}
		);

		tagPicker = new PlttTagPicker( wrap, suggestions, openTagModal, null, groups );
	}

	// ── Open / close ─────────────────────────────────────────────────────

	/**
	 * Write an entry's values into the form.
	 *
	 * @param {Object} entry Payload from pltt_get_entry.
	 */
	function populate( entry ) {
		currentEntryId = entry.id;
		form.dataset.entryId = entry.id;
		durationManual = false;

		els.description.value = entry.description || '';
		els.date.value = entry.entry_date || '';
		els.start.value = entry.start_time || '';
		els.end.value = entry.end_time || '';
		els.duration.value = entry.duration_minutes || '';
		els.client.value = entry.client_id ? String( entry.client_id ) : '';
		els.billable.checked = !! entry.billable;
		syncBillableButton();

		// Settle the Billable field here, from the payload, rather than leaving it
		// to applyBillableVisibility() after the projects request comes back —
		// that lands once the modal is already on screen, so the field visibly
		// appeared or vanished a moment after opening. The server has already
		// worked out whether the per-entry flag applies to this entry's project.
		els.billableField.classList.toggle( 'pltt-hidden', ! entry.billable_applies );

		ensureTagPicker();
		if ( tagPicker ) {
			tagPicker.setTags( entry.tags || [] );
		}

		loadProjects( entry.project_id );
	}

	/**
	 * Fill the form and put it on screen.
	 *
	 * @param {Object} entry Payload from pltt_get_entry.
	 */
	function show( entry ) {
		populate( entry );
		PLTT.showModal( MODAL_ID );

		// showModal focuses the first focusable, which is the injected ×.
		// Description is where the cursor belongs.
		els.description.focus();
		els.description.select();
	}

	/**
	 * Fetch an entry ahead of being asked for it.
	 *
	 * Called when a row menu opens, because "Edit entry…" is the first item in
	 * it and usually the reason it was opened. The work isn't the query — the
	 * payload takes about a millisecond — it's that admin-ajax boots all of
	 * WordPress per request, so a fetch started on the click leaves a visible
	 * pause before the modal appears. Started when the menu opens, the round
	 * trip runs during the time it takes to read the menu and click.
	 *
	 * @param {number} entryId Entry the menu belongs to.
	 */
	function prefetch( entryId ) {
		if ( ! modal ) {
			return;
		}
		pending = null;
		PLTT.ajax( 'pltt_get_entry', { entry_id: entryId }, function( response ) {
			if ( response.success ) {
				pending = { id: String( entryId ), entry: response.data.entry, at: Date.now() };
			}
		} );
	}

	/**
	 * Open the modal on an entry.
	 *
	 * @param {number} entryId Entry to edit.
	 */
	function open( entryId ) {
		if ( ! modal ) {
			return;
		}

		clearError();
		setStatus( '' );

		// Use the prefetch if it's for this entry and recent. Consumed rather
		// than cached: it's a few hundred milliseconds old at most, which is the
		// only reason it's safe to open on it. Anything staler gets re-read, so
		// a menu left open across a coffee break can't fill the form with what
		// the entry used to say.
		if ( pending && pending.id === String( entryId ) && Date.now() - pending.at < PREFETCH_TTL ) {
			const entry = pending.entry;
			pending = null;
			show( entry );
			return;
		}
		pending = null;

		PLTT.ajax( 'pltt_get_entry', { entry_id: entryId }, function( response ) {
			if ( ! response.success ) {
				window.alert( response.data || plttData.i18n.error );
				return;
			}
			show( response.data.entry );
		} );
	}

	function close() {
		PLTT.hideModal( MODAL_ID );
		currentEntryId = 0;
	}

	// ── Save ─────────────────────────────────────────────────────────────

	function collectPayload() {
		var payload = {
			entry_id: currentEntryId,
			entry_date: els.date.value,
			start_time: els.start.value,
			end_time: els.end.value,
			duration_minutes: els.duration.value || 0,
			description: els.description.value,
			client_id: els.client.value,
			project_id: els.project.value,
			tags: els.tags.value,
			billable: els.billable.checked ? 1 : 0,
			// Only the Day view uses the server-rendered row that comes back, and
			// its Billable column drops out on an all-retainer day. Telling the
			// server which shape to write keeps the replacement row the same width
			// as the table it lands in.
			show_billable_col: document.querySelector( '.pltt-entries-table .pltt-col-billable' ) ? 1 : 0,
		};
		// "new" is a menu item, not a selection — it opens a modal.
		if ( payload.client_id === 'new' ) {
			payload.client_id = '';
		}
		if ( payload.project_id === 'new' ) {
			payload.project_id = '';
		}
		return payload;
	}

	function save() {
		clearError();

		var payload = collectPayload();
		if ( ! payload.start_time ) {
			showError( 'A start time is required.' );
			els.start.focus();
			return;
		}

		els.save.disabled = true;
		setStatus( plttData.i18n.saving || 'Saving…' );

		PLTT.ajax( 'pltt_save_entry', payload, function( response ) {
			els.save.disabled = false;
			setStatus( '' );

			if ( ! response.success ) {
				showError( ( response.data && response.data.message ) || response.data || 'Failed to save entry.' );
				return;
			}

			var entryId = currentEntryId;
			close();

			document.dispatchEvent( new CustomEvent( 'pltt:entry-saved', {
				detail: {
					entryId: entryId,
					entry: response.data.entry,
					// True when the date, client, project or billing changed —
					// i.e. when the row may no longer belong where it's sitting.
					requiresReload: !! response.data.requires_reload,
					rowHtml: response.data.row_html,
				},
			} ) );
		} );
	}

	// ── Field behaviour ──────────────────────────────────────────────────

	/**
	 * Minutes between two HH:MM times, wrapping past midnight.
	 *
	 * @param {string} startTime HH:MM.
	 * @param {string} endTime   HH:MM.
	 * @return {number|null} Minutes, or null when either is blank.
	 */
	function calculateDuration( startTime, endTime ) {
		if ( ! startTime || ! endTime ) {
			return null;
		}
		var s = startTime.split( ':' ).map( Number );
		var e = endTime.split( ':' ).map( Number );
		var minutes = ( e[0] * 60 + e[1] ) - ( s[0] * 60 + s[1] );
		if ( minutes < 0 ) {
			minutes += 24 * 60;
		}
		return minutes;
	}

	function bindForm() {
		form.addEventListener( 'input', function( e ) {
			if ( e.target === els.start || e.target === els.end ) {
				if ( durationManual ) {
					return;
				}
				var minutes = calculateDuration( els.start.value, els.end.value );
				if ( minutes !== null ) {
					els.duration.value = minutes;
				}
			} else if ( e.target === els.duration ) {
				durationManual = true;
			}
		} );

		form.addEventListener( 'change', function( e ) {
			if ( e.target === els.client ) {
				if ( els.client.value === 'new' ) {
					els.client.value = '';
					PLTT.showModal( 'pltt-client-modal' );
					return;
				}
				// Internal work is never chargeable.
				var opt = els.client.options[ els.client.selectedIndex ];
				if ( opt && opt.dataset.isInternal === '1' ) {
					els.billable.checked = false;
					syncBillableButton();
				}
				loadProjects( 0 );
				return;
			}

			if ( e.target === els.project ) {
				if ( els.project.value === 'new' ) {
					els.project.value = '';
					if ( ! els.client.value || els.client.value === 'new' ) {
						window.alert( 'Please select a client first.' );
						return;
					}
					document.getElementById( 'pltt-new-project-client-id' ).value = els.client.value;
					if ( window.PlttProjectType ) {
						PlttProjectType.reset();
					}
					PLTT.showModal( 'pltt-project-modal' );
					return;
				}

				// A project's type carries a default answer to "is this billable".
				var popt = els.project.options[ els.project.selectedIndex ];
				if ( popt && popt.value && popt.dataset.billabilityDefault !== undefined ) {
					els.billable.checked = popt.dataset.billabilityDefault === '1';
					syncBillableButton();
				}
				applyBillableVisibility();
			}
		} );

		form.addEventListener( 'click', function( e ) {
			if ( e.target.closest( '.pltt-form-billable-btn' ) ) {
				e.preventDefault();
				els.billable.checked = ! els.billable.checked;
				syncBillableButton();
			}
		} );

		els.save.addEventListener( 'click', function( e ) {
			e.preventDefault();
			save();
		} );
	}

	// ── Quick-add modals ─────────────────────────────────────────────────
	//
	// The client and project selects both offer "+ Add new …". These wire the
	// save buttons in entry-editor-modals.php and feed the result back into
	// this form.

	function bindQuickAdd() {
		var saveClientBtn = document.getElementById( 'pltt-save-client' );
		if ( saveClientBtn ) {
			saveClientBtn.addEventListener( 'click', function() {
				var nameInput = document.getElementById( 'pltt-new-client-name' );
				var rateInput = document.getElementById( 'pltt-new-client-rate' );
				var name = nameInput.value.trim();

				if ( ! name ) {
					window.alert( 'Please enter a client name.' );
					nameInput.focus();
					return;
				}

				saveClientBtn.disabled = true;
				PLTT.ajax(
					'pltt_create_client',
					{ name: name, hourly_rate: PLTT.parseCurrencyValue( rateInput.value ) },
					function( response ) {
						saveClientBtn.disabled = false;
						if ( ! response.success || ! response.data.client ) {
							window.alert( response.data || 'Error creating client.' );
							return;
						}

						var client = response.data.client;
						document.querySelectorAll( '.pltt-form-client' ).forEach( function( select ) {
							var option = document.createElement( 'option' );
							option.value = client.id;
							option.textContent = client.name;
							select.insertBefore( option, select.querySelector( 'option[value="new"]' ) );
						} );

						els.client.value = client.id;
						loadProjects( 0 );

						PLTT.hideModal( 'pltt-client-modal' );
						nameInput.value = '';
						rateInput.value = '';
					}
				);
			} );
		}

		var saveProjectBtn = document.getElementById( 'pltt-save-project' );
		if ( saveProjectBtn ) {
			saveProjectBtn.addEventListener( 'click', function() {
				var clientIdInput = document.getElementById( 'pltt-new-project-client-id' );
				var nameInput = document.getElementById( 'pltt-project-name' );
				var vals = window.PlttProjectType
					? PlttProjectType.getValues()
					: { name: nameInput ? nameInput.value.trim() : '', hourly_rate: '', budget_hours: '', budget_fee: '', recurring_period: '', non_billable: '0' };

				if ( ! vals.name ) {
					window.alert( 'Please enter a project name.' );
					if ( nameInput ) {
						nameInput.focus();
					}
					return;
				}

				var typeError = window.PlttProjectType ? PlttProjectType.validate() : '';
				if ( typeError ) {
					window.alert( typeError );
					var feeInput = document.getElementById( 'pltt-project-budget-fee' );
					if ( feeInput ) {
						feeInput.focus();
					}
					return;
				}

				saveProjectBtn.disabled = true;
				PLTT.ajax(
					'pltt_create_project',
					{
						name: vals.name,
						client_id: clientIdInput.value,
						hourly_rate: PLTT.parseCurrencyValue( vals.hourly_rate ),
						budget_hours: vals.budget_hours,
						budget_fee: PLTT.parseCurrencyValue( vals.budget_fee ),
						recurring_period: vals.recurring_period,
						non_billable: vals.non_billable,
					},
					function( response ) {
						saveProjectBtn.disabled = false;
						if ( ! response.success || ! response.data.project ) {
							window.alert( response.data || 'Error creating project.' );
							return;
						}

						var project = response.data.project;
						var option = document.createElement( 'option' );
						option.value = project.id;
						option.textContent = project.name;
						option.dataset.billabilityDefault = parseInt( project.billability_default, 10 ) === 1 ? '1' : '0';
						option.dataset.billableFlag = parseInt( project.billable_flag_applies, 10 ) === 0 ? '0' : '1';
						option.selected = true;
						els.project.insertBefore( option, els.project.querySelector( 'option[value="new"]' ) );
						els.project.dispatchEvent( new Event( 'change' ) );

						PLTT.hideModal( 'pltt-project-modal' );
						if ( window.PlttProjectType ) {
							PlttProjectType.reset();
						}
					}
				);
			} );
		}
	}

	// ── Boot ─────────────────────────────────────────────────────────────

	function init() {
		modal = document.getElementById( MODAL_ID );
		if ( ! modal ) {
			return;
		}
		form = modal.querySelector( '.pltt-entry-form' );
		if ( ! form ) {
			return;
		}

		els = {
			description: qs( '.pltt-form-description' ),
			date: qs( '.pltt-form-date' ),
			start: qs( '.pltt-form-start' ),
			end: qs( '.pltt-form-end' ),
			duration: qs( '.pltt-form-duration' ),
			client: qs( '.pltt-form-client' ),
			project: qs( '.pltt-form-project' ),
			tags: qs( '.pltt-form-tags' ),
			billable: qs( '.pltt-form-billable' ),
			billableBtn: qs( '.pltt-form-billable-btn' ),
			billableField: qs( '.pltt-field-billable' ),
			error: qs( '.pltt-entry-form-error' ),
			// Status and Save live in the modal's action band, outside .pltt-entry-form.
			status: modal.querySelector( '.pltt-form-status' ),
			save: modal.querySelector( '.pltt-form-save' ),
		};

		bindForm();
		bindQuickAdd();
	}

	if ( document.readyState !== 'loading' ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}

	window.PlttEntryEditor = {
		open: open,
		close: close,
		prefetch: prefetch,
	};
} )();

/**
 * Daily Log Screen JavaScript
 */

/* global PLTT, plttData */

( function() {
	'use strict';

	const textarea      = document.getElementById( 'pltt-log-textarea' );
	const saveIndicator = document.getElementById( 'pltt-save-indicator' );
	const dateInput     = document.getElementById( 'pltt-log-date' );
	const processBtn    = document.getElementById( 'pltt-process-btn' );

	if ( ! textarea || ! dateInput ) {
		return;
	}

	// Capture the date this page was loaded for. Must not be read from dateInput.value
	// later because the date picker's change event mutates dateInput.value to the new
	// destination date before beforeunload fires, causing saves to go to the wrong date.
	const pageDate = dateInput.value;

	// Track save state.
	let isDirty        = false;
	let navigatingAway = false;

	/**
	 * Mark the log as saved — persistent timestamp, clears dirty flag.
	 */
	function markSaved() {
		saveIndicator.textContent = plttData.i18n.savedAt.replace( '%s', PLTT.getCurrentTime() );
		saveIndicator.className   = 'pltt-save-indicator saved';
		isDirty = false;
	}

	/**
	 * Mark the log as having unsaved changes.
	 * No-op if already dirty so repeated keystrokes don't thrash the DOM.
	 */
	function markDirty() {
		if ( isDirty ) {
			return;
		}
		isDirty = true;
		saveIndicator.textContent = plttData.i18n.unsaved;
		saveIndicator.className   = 'pltt-save-indicator unsaved';
	}

	/**
	 * Insert timestamp at cursor position.
	 */
	function insertTimestamp() {
		const time   = PLTT.getCurrentTime();
		const pos    = textarea.selectionStart;
		const before = textarea.value.substring( 0, pos );
		const after  = textarea.value.substring( pos );

		// Add newline if not at start and previous char isn't newline.
		let prefix = '';
		if ( pos > 0 && before.charAt( before.length - 1 ) !== '\n' ) {
			prefix = '\n';
		}

		const insert = prefix + '@' + time + ' - ';
		textarea.value = before + insert + after;

		// Move cursor to end of inserted text.
		const newPos = pos + insert.length;
		textarea.selectionStart = textarea.selectionEnd = newPos;
		textarea.focus();

		// Trigger auto-save.
		markDirty();
		autoSave();
	}

	/**
	 * Handle keydown for @ shortcut.
	 */
	textarea.addEventListener( 'keydown', function( e ) {
		// Check for @ key (Shift+2 on US keyboard, or direct @ on others).
		if ( e.key === '@' ) {
			e.preventDefault();
			insertTimestamp();
		}
	} );

	/**
	 * Auto-save with debounce.
	 */
	const autoSave = PLTT.debounce( function() {
		if ( ! textarea.value.trim() ) {
			return; // Don't overwrite real content with an empty save.
		}
		if ( navigatingAway ) {
			return; // Navigation has started; beforeunload handles the final save.
		}

		saveIndicator.textContent = plttData.i18n.saving;
		saveIndicator.className   = 'pltt-save-indicator saving';

		// Detect if log is processed (check if update notes button exists).
		const isProcessed = document.getElementById( 'pltt-update-notes-btn' ) !== null;
		const action      = isProcessed ? 'pltt_update_daily_log' : 'pltt_save_daily_log';

		PLTT.ajax( action, {
			date:    pageDate,
			content: textarea.value,
		}, function( response ) {
			if ( response.success ) {
				markSaved();
			} else {
				saveIndicator.textContent = plttData.i18n.error;
				saveIndicator.className   = 'pltt-save-indicator error';
			}
		} );
	}, plttData.autosaveDebounceMs || 1500 );

	// Mark dirty and auto-save on input.
	textarea.addEventListener( 'input', function( e ) {
		// Fallback for the @ shortcut. The keydown handler below is the fast
		// path, but it can be missed: if the footer script is still loading
		// when the user types the first @, or on mobile/IME keyboards where
		// e.key isn't reliably '@'. In those cases the literal @ lands here —
		// strip it and insert a timestamp instead, matching keydown behavior.
		if ( e.inputType === 'insertText' && e.data === '@' ) {
			const pos = textarea.selectionStart; // Caret sits just after the @.
			textarea.value =
				textarea.value.substring( 0, pos - 1 ) +
				textarea.value.substring( pos );
			textarea.selectionStart = textarea.selectionEnd = pos - 1;
			insertTimestamp(); // Handles its own markDirty()/autoSave().
			return;
		}

		markDirty();
		autoSave();
	} );

	/**
	 * Open native date picker when the label button is clicked.
	 */
	const dateNavTrigger = document.getElementById( 'pltt-date-nav-trigger' );
	if ( dateNavTrigger ) {
		dateNavTrigger.addEventListener( 'click', function() {
			try {
				dateInput.showPicker();
			} catch ( e ) {
				dateInput.click();
			}
		} );
	}

	/**
	 * Handle date change.
	 */
	dateInput.addEventListener( 'change', function() {
		// Flag navigation so any pending debounced auto-save does not fire.
		navigatingAway = true;

		// Navigate to the new date.
		const url = new URL( window.location.href );
		url.searchParams.set( 'date', this.value );
		window.location.href = url.toString();
	} );

	/**
	 * Handle process button.
	 */
	if ( processBtn ) {
		processBtn.addEventListener( 'click', function() {
			const content = textarea.value.trim();

			if ( ! content ) {
				alert( 'Please add some time entries first.' );
				return;
			}

			// Check for at least one timestamp.
			if ( ! content.includes( '@' ) ) {
				alert( 'No timestamps found. Use @ to insert timestamps.' );
				return;
			}

			// Check if log is already processed (button has secondary class).
			if ( processBtn.classList.contains( 'button-secondary' ) ) {
				const confirmMsg = 'This re-reads the log and refreshes your unsaved draft entries.\n\n' +
					'Finalized entries are kept as-is — only new timestamps become new drafts to review.\n\nContinue?';
				if ( ! confirm( confirmMsg ) ) {
					return;
				}
			}

			processBtn.disabled    = true;
			processBtn.textContent = plttData.i18n.processing;

			PLTT.ajax( 'pltt_process_log', {
				date:    dateInput.value,
				content: content,
			}, function( response ) {
				if ( response.success && response.data.redirect ) {
					try {
						const redirectUrl = new URL( response.data.redirect, window.location.origin );
						if ( redirectUrl.origin === window.location.origin ) {
							window.location.href = redirectUrl.href;
						}
					} catch ( e ) {
						// Malformed URL — ignore.
					}
				} else {
					alert( response.data || 'Error processing entries.' );
					processBtn.disabled    = false;
					processBtn.textContent = 'Process Time Entries →';
				}
			} );
		} );
	}

	/**
	 * Handle save button (unprocessed logs only).
	 */
	const saveBtn = document.getElementById( 'pltt-save-btn' );
	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', function() {
			if ( ! textarea.value.trim() ) {
				return;
			}

			saveBtn.disabled    = true;
			const originalText  = saveBtn.textContent;
			saveBtn.textContent = plttData.i18n.saving;

			saveIndicator.textContent = plttData.i18n.saving;
			saveIndicator.className   = 'pltt-save-indicator saving';

			PLTT.ajax( 'pltt_save_daily_log', {
				date:    pageDate,
				content: textarea.value,
			}, function( response ) {
				if ( response.success ) {
					markSaved();
				} else {
					saveIndicator.textContent = plttData.i18n.error;
					saveIndicator.className   = 'pltt-save-indicator error';
				}
				saveBtn.disabled    = false;
				saveBtn.textContent = originalText;
			} );
		} );
	}

	/**
	 * Handle update notes button (preserves processed state).
	 */
	const updateNotesBtn = document.getElementById( 'pltt-update-notes-btn' );
	if ( updateNotesBtn ) {
		updateNotesBtn.addEventListener( 'click', function() {
			const content = textarea.value.trim();

			updateNotesBtn.disabled    = true;
			const originalText         = updateNotesBtn.textContent;
			updateNotesBtn.textContent = plttData.i18n.saving;

			PLTT.ajax( 'pltt_update_daily_log', {
				date:    pageDate,
				content: content,
			}, function( response ) {
				if ( response.success ) {
					markSaved();
				} else {
					alert( response.data || 'Error updating notes.' );
				}
				updateNotesBtn.disabled    = false;
				updateNotesBtn.textContent = originalText;
			} );
		} );
	}

	/**
	 * Save before leaving page.
	 */
	window.addEventListener( 'beforeunload', function() {
		if ( textarea.value.trim() ) {
			// Use pageDate (not dateInput.value) — the date picker's change event mutates
			// dateInput.value to the destination date before this handler fires.
			const isProcessed = document.getElementById( 'pltt-update-notes-btn' ) !== null;
			const action      = isProcessed ? 'pltt_update_daily_log' : 'pltt_save_daily_log';
			const formData    = new FormData();
			formData.append( 'action', action );
			formData.append( 'nonce', plttData.nonce );
			formData.append( 'date', pageDate );
			formData.append( 'content', textarea.value );

			// Use sendBeacon for reliable save on page unload.
			navigator.sendBeacon( plttData.ajaxUrl, formData );
		}
	} );

	// Focus textarea on load.
	textarea.focus();
} )();

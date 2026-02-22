/**
 * Daily Log Screen JavaScript
 */

/* global PLTT, plttData */

( function() {
	'use strict';

	const textarea = document.getElementById( 'pltt-log-textarea' );
	const saveIndicator = document.getElementById( 'pltt-save-indicator' );
	const dateInput = document.getElementById( 'pltt-log-date' );
	const processBtn = document.getElementById( 'pltt-process-btn' );

	if ( ! textarea || ! dateInput ) {
		return;
	}

	/**
	 * Insert timestamp at cursor position.
	 */
	function insertTimestamp() {
		const time = PLTT.getCurrentTime();
		const pos = textarea.selectionStart;
		const before = textarea.value.substring( 0, pos );
		const after = textarea.value.substring( pos );

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
		saveIndicator.textContent = plttData.i18n.saving;
		saveIndicator.className = 'pltt-save-indicator saving';

		// Detect if log is processed (check if update notes button exists).
		const isProcessed = document.getElementById( 'pltt-update-notes-btn' ) !== null;
		const action = isProcessed ? 'pltt_update_daily_log' : 'pltt_save_daily_log';

		PLTT.ajax( action, {
			date: dateInput.value,
			content: textarea.value
		}, function( response ) {
			if ( response.success ) {
				saveIndicator.textContent = plttData.i18n.saved;
				saveIndicator.className = 'pltt-save-indicator saved';
			} else {
				saveIndicator.textContent = plttData.i18n.error;
				saveIndicator.className = 'pltt-save-indicator error';
			}

			// Clear indicator after 2 seconds.
			setTimeout( function() {
				saveIndicator.textContent = '';
				saveIndicator.className = 'pltt-save-indicator';
			}, 2000 );
		} );
	}, plttData.autosaveDebounceMs || 1500 );

	// Auto-save on input.
	textarea.addEventListener( 'input', autoSave );

	/**
	 * Handle date change.
	 */
	dateInput.addEventListener( 'change', function() {
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
				const confirmMsg = 'This will delete all existing entries and recreate them from the parsed log text.\n\n' +
					'To edit individual entries, use the Review screen instead.\n\nContinue?';
				if ( ! confirm( confirmMsg ) ) {
					return;
				}
			}

			processBtn.disabled = true;
			processBtn.textContent = plttData.i18n.processing;

			PLTT.ajax( 'pltt_process_log', {
				date: dateInput.value,
				content: content
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
					processBtn.disabled = false;
					processBtn.textContent = 'Process Time Entries →';
				}
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

			updateNotesBtn.disabled = true;
			const originalText = updateNotesBtn.textContent;
			updateNotesBtn.textContent = plttData.i18n.saving;

			PLTT.ajax( 'pltt_update_daily_log', {
				date: dateInput.value,
				content: content
			}, function( response ) {
				if ( response.success ) {
					saveIndicator.textContent = plttData.i18n.saved;
					saveIndicator.className = 'pltt-save-indicator saved';

					setTimeout( function() {
						saveIndicator.textContent = '';
						saveIndicator.className = 'pltt-save-indicator';
					}, 2000 );
				} else {
					alert( response.data || 'Error updating notes.' );
				}

				updateNotesBtn.disabled = false;
				updateNotesBtn.textContent = originalText;
			} );
		} );
	}

	/**
	 * Save before leaving page.
	 */
	window.addEventListener( 'beforeunload', function() {
		if ( textarea.value.trim() ) {
			// Synchronous save attempt.
			const formData = new FormData();
			formData.append( 'action', 'pltt_save_daily_log' );
			formData.append( 'nonce', plttData.nonce );
			formData.append( 'date', dateInput.value );
			formData.append( 'content', textarea.value );

			// Use sendBeacon for reliable save on page unload.
			navigator.sendBeacon( plttData.ajaxUrl, formData );
		}
	} );

	// Focus textarea on load.
	textarea.focus();
} )();

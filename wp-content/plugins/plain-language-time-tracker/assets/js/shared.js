/**
 * Plain Language Time Tracker - Shared Utilities
 * Vanilla JavaScript - no dependencies
 */

/* global plttData */

const PLTT = {
	/**
	 * Make an AJAX request.
	 *
	 * @param {string}   action   AJAX action name.
	 * @param {Object}   data     Data to send.
	 * @param {Function} callback Callback function.
	 */
	ajax: function( action, data, callback ) {
		const formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', plttData.nonce );

		for ( const key in data ) {
			if ( data.hasOwnProperty( key ) ) {
				formData.append( key, data[ key ] );
			}
		}

		fetch( plttData.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		} )
			.then( response => response.json() )
			.then( callback )
			.catch( () => {
				callback( { success: false, data: plttData.i18n.error } );
			} );
	},

	/**
	 * Debounce a function.
	 *
	 * @param {Function} func Function to debounce.
	 * @param {number}   wait Milliseconds to wait.
	 * @return {Function} Debounced function.
	 */
	debounce: function( func, wait ) {
		let timeout;
		return function( ...args ) {
			clearTimeout( timeout );
			timeout = setTimeout( () => func.apply( this, args ), wait );
		};
	},

	/**
	 * Format minutes as duration string.
	 *
	 * SYNC: Output format and edge-case handling must match pltt_format_duration()
	 * in includes/helpers.php. Update both if the format changes.
	 *
	 * @param {number} minutes Total minutes.
	 * @return {string} Formatted duration.
	 */
	formatDuration: function( minutes ) {
		const n = Number( minutes );
		if ( ! Number.isFinite( n ) || n <= 0 ) {
			return '0m';
		}
		const total = Math.round( n );
		const hours = Math.floor( total / 60 );
		const mins  = total % 60;

		if ( hours > 0 && mins > 0 ) {
			return hours + 'h ' + mins + 'm';
		} else if ( hours > 0 ) {
			return hours + 'h';
		}
		return mins + 'm';
	},

	/**
	 * Format minutes as decimal hours.
	 *
	 * SYNC: Matches pltt_format_hours() in includes/helpers.php, which uses
	 * number_format(_, 2) — includes a thousands separator for values ≥1000.
	 *
	 * @param {number} minutes Total minutes.
	 * @return {string} Formatted hours.
	 */
	formatHours: function( minutes ) {
		const n = Number( minutes );
		if ( ! Number.isFinite( n ) || n < 0 ) {
			return '0.00';
		}
		return ( n / 60 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	},

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} text Text to escape.
	 * @return {string} Escaped text.
	 */
	escapeHtml: function( text ) {
		const div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	},

	/**
	 * Show a modal.
	 *
	 * @param {string} modalId Modal element ID.
	 */
	showModal: function( modalId ) {
		const modal = document.getElementById( modalId );
		if ( modal ) {
			// Remember the element that had focus, as the element. This used to
			// store its id, which meant focus only ever came back to triggers
			// that happened to have one — a per-row kebab doesn't, and neither
			// does most of what opens a modal.
			modal._plttPreviousFocus = document.activeElement;

			// Stack order, for the Escape handler below: modals can open over
			// each other, and Escape should only close the one on top.
			PLTT._modalOpenSeq = ( PLTT._modalOpenSeq || 0 ) + 1;
			modal._plttOpenSeq = PLTT._modalOpenSeq;

			modal.classList.remove( 'pltt-hidden' );

			// Get all focusable elements in the modal
			const focusableElements = modal.querySelectorAll(
				'a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
			);
			const firstElement = focusableElements[0];
			const lastElement = focusableElements[focusableElements.length - 1];

			// Focus first element
			if ( firstElement ) {
				firstElement.focus();
			}

			// OPT-L4: Cache focusable elements at open time; re-use in keydown handler instead
			// of re-querying on every Tab keypress. Refresh only when modal content mutates.
			let trapFirst = firstElement;
			let trapLast  = lastElement;

			const focusTrapSelector = 'a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

			const refreshFocusTrap = function() {
				const els = modal.querySelectorAll( focusTrapSelector );
				trapFirst = els[0];
				trapLast  = els[els.length - 1];
			};

			if ( !modal.dataset.focusTrapBound ) {
				modal.dataset.focusTrapBound = 'true';
				modal.addEventListener( 'keydown', function( e ) {
					// Only trap focus if modal is visible
					if ( modal.classList.contains( 'pltt-hidden' ) ) {
						return;
					}

					if ( e.key === 'Tab' ) {
						if ( e.shiftKey ) {
							// Shift+Tab: moving backwards
							if ( document.activeElement === trapFirst ) {
								e.preventDefault();
								if ( trapLast ) { trapLast.focus(); }
							}
						} else {
							// Tab: moving forwards
							if ( document.activeElement === trapLast ) {
								e.preventDefault();
								if ( trapFirst ) { trapFirst.focus(); }
							}
						}
					}
				} );
			} else {
				// Modal already had its trap bound on a previous open — refresh the cached list.
				refreshFocusTrap();
			}
		}
	},

	/**
	 * Hide a modal.
	 *
	 * @param {string} modalId Modal element ID.
	 */
	hideModal: function( modalId ) {
		const modal = document.getElementById( modalId );
		if ( modal ) {
			modal.classList.add( 'pltt-hidden' );

			// Return focus to whatever opened it, as long as that element is
			// still on the page — a saved row can be replaced out from under us.
			const previousElement = modal._plttPreviousFocus;
			if ( previousElement && previousElement.isConnected && previousElement.focus ) {
				previousElement.focus();
			}
			modal._plttPreviousFocus = null;

			// Clear inputs.
			modal.querySelectorAll( 'input[type="text"]' ).forEach( input => {
				input.value = '';
			} );
		}
	},

	/**
	 * Strip commas from a currency string and return a clean numeric string.
	 *
	 * @param {string} str Raw input value (e.g. "1,500.00").
	 * @return {string} Clean value (e.g. "1500.00"), or empty string.
	 */
	parseCurrencyValue: function( str ) {
		return str.replace( /,/g, '' ).trim();
	},

	/**
	 * Initialize currency inputs (.pltt-currency-input).
	 *
	 * - Filters out characters that aren't digits, '.', or ','.
	 * - Strips commas on form submit so POST values are clean floats.
	 *
	 * @param {Element|Document} context Root element to search within.
	 */
	initCurrencyInputs: function( context ) {
		context.querySelectorAll( '.pltt-currency-input' ).forEach( function( input ) {
			// Block non-numeric characters (allow digits, dot, comma, control keys).
			input.addEventListener( 'keydown', function( e ) {
				if (
					e.ctrlKey || e.metaKey || e.altKey ||
					[ 'Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'Home', 'End' ].includes( e.key )
				) {
					return;
				}
				if ( ! /^[\d.,]$/.test( e.key ) ) {
					e.preventDefault();
				}
			} );

			// Strip commas before the parent form is submitted (traditional POST).
			const form = input.closest( 'form' );
			if ( form && ! form.dataset.plttCurrencyBound ) {
				form.dataset.plttCurrencyBound = 'true';
				form.addEventListener( 'submit', function() {
					form.querySelectorAll( '.pltt-currency-input' ).forEach( function( field ) {
						field.value = PLTT.parseCurrencyValue( field.value );
					} );
				} );
			}
		} );
	},

	/**
	 * Get current time formatted for display.
	 *
	 * @return {string} Formatted time (e.g., "9:15am").
	 */
	getCurrentTime: function() {
		const now = new Date();
		let hours = now.getHours();
		const minutes = now.getMinutes().toString().padStart( 2, '0' );
		const period = hours >= 12 ? 'pm' : 'am';

		hours = hours % 12;
		if ( hours === 0 ) {
			hours = 12;
		}

		return hours + ':' + minutes + period;
	},

	/**
	 * Strip one-time notice params from the address bar after the page renders, so
	 * a reload doesn't re-show the admin notice. No-op when none are present.
	 * (OPT-DUP2: replaces the inline copies in clients/projects/tags/daily-log and
	 * project-detail.js.)
	 */
	cleanNoticeParams: function() {
		const search = window.location.search;
		if ( search.indexOf( 'pltt_message' ) === -1 && search.indexOf( 'pltt_error' ) === -1 ) {
			return;
		}
		const url = new URL( window.location.href );
		url.searchParams.delete( 'pltt_message' );
		url.searchParams.delete( 'pltt_error' );
		url.searchParams.delete( 'pltt_error_message' );
		window.history.replaceState( {}, '', url.toString() );
	},

	/**
	 * Wire the shared "Add New Tag" modal (templates/partials/tag-modal.php) to a
	 * screen's tag pickers, and return the opener to hand PlttTagPicker as its
	 * onAddNew callback.
	 *
	 * Every screen enumerates its pickers differently — an array on the review
	 * and Overview tables, a per-form-row WeakMap on the inline editor — so the
	 * caller supplies getPickers rather than registering each one here. The new
	 * tag is offered to all of them and auto-selected only in the one that
	 * opened the modal.
	 *
	 * No-ops when the modal isn't on the page, so a screen can call this
	 * unconditionally.
	 *
	 * Safe to call more than once per page, and the Overview does: the entries
	 * table has a picker per row, and the edit modal has one of its own. Each
	 * call adds a registration; the Create button is only ever bound once, or a
	 * single click would create the tag as many times as there are callers.
	 *
	 * @param {Function} getPickers Returns the current array of PlttTagPicker instances.
	 * @param {Function} onCreated  Optional; receives the new tag name after it saves.
	 * @return {Function} Opener to pass as the picker's onAddNew callback.
	 */
	wireTagModal: function( getPickers, onCreated ) {
		PLTT._tagModal = PLTT._tagModal || { registrations: [], activePicker: null, bound: false };
		const state = PLTT._tagModal;
		state.registrations.push( { getPickers: getPickers, onCreated: onCreated } );

		const groupSelect = document.getElementById( 'pltt-new-tag-group-select' );
		const groupNew = document.getElementById( 'pltt-new-tag-group-new' );

		/**
		 * The group as typed: the select's value, or the free-text box when
		 * "+ New group…" is chosen. Empty string means ungrouped.
		 *
		 * @return {string} Group name.
		 */
		const readGroup = function() {
			if ( ! groupSelect ) {
				return '';
			}
			if ( '__new__' === groupSelect.value ) {
				return groupNew ? groupNew.value.trim() : '';
			}
			return groupSelect.value;
		};

		const resetGroup = function() {
			if ( groupSelect ) {
				groupSelect.value = '';
			}
			if ( groupNew ) {
				groupNew.value = '';
				groupNew.classList.add( 'pltt-hidden' );
			}
		};

		const open = function( picker ) {
			state.activePicker = picker;
			resetGroup();
			PLTT.showModal( 'pltt-tag-modal' );
		};

		const saveBtn = document.getElementById( 'pltt-save-tag' );
		if ( ! saveBtn || state.bound ) {
			return open;
		}
		state.bound = true;

		if ( groupSelect && groupNew ) {
			groupSelect.addEventListener( 'change', function() {
				const isNew = '__new__' === groupSelect.value;
				groupNew.classList.toggle( 'pltt-hidden', ! isNew );
				if ( isNew ) {
					groupNew.focus();
				}
			} );
		}

		saveBtn.addEventListener( 'click', function() {
			const nameInput = document.getElementById( 'pltt-new-tag-name' );
			const name = nameInput.value.trim();

			if ( ! name ) {
				alert( 'Please enter a tag name.' );
				nameInput.focus();
				return;
			}

			// Picking "+ New group…" and typing nothing means ungrouped, not a
			// group named "" — readGroup() already collapses both to ''.
			const group = readGroup();

			saveBtn.disabled = true;

			PLTT.ajax( 'pltt_create_tag', { tag_name: name, group_name: group }, function( response ) {
				saveBtn.disabled = false;

				if ( ! response.success || ! response.data.tag ) {
					alert( response.data || 'Error creating tag.' );
					return;
				}

				const tag = response.data.tag;
				// Trust the server's group, not the form's: it normalizes and
				// truncates, so this is what the tag will report on reload.
				const savedGroup = response.data.group || '';

				// Offer the tag to every picker on the page, and select it only
				// in the one that opened the modal.
				state.registrations.forEach( function( reg ) {
					reg.getPickers().forEach( function( picker ) {
						picker.addTagOption( tag, picker === state.activePicker, savedGroup );
					} );
					if ( reg.onCreated ) {
						reg.onCreated( tag, savedGroup );
					}
				} );

				PLTT.hideModal( 'pltt-tag-modal' );
				nameInput.value = '';
				resetGroup();
				state.activePicker = null;
			} );
		} );

		return open;
	}
};

// Initialize modal close buttons.
document.addEventListener( 'DOMContentLoaded', function() {
	// Drop one-time notice params from the URL (OPT-DUP2).
	PLTT.cleanNoticeParams();

	// Initialize currency inputs.
	PLTT.initCurrencyInputs( document );

	// Modal chrome: inject a top-right "×" close into every modal so they share
	// the header (title + close) / footer (buttons) pattern. Runs before the
	// .pltt-modal-close binding below so the injected button gets wired up too.
	document.querySelectorAll( '.pltt-modal-content' ).forEach( function( content ) {
		if ( content.querySelector( '.pltt-modal-x' ) ) {
			return;
		}
		const x = document.createElement( 'button' );
		x.type      = 'button';
		x.className = 'pltt-modal-x pltt-modal-close';
		x.setAttribute( 'aria-label', ( window.plttData && plttData.i18n && plttData.i18n.close ) || 'Close' );
		x.innerHTML = '&times;';
		content.insertBefore( x, content.firstChild );
	} );

	// Close modals on button click.
	document.querySelectorAll( '.pltt-modal-close' ).forEach( function( btn ) {
		btn.addEventListener( 'click', function() {
			const modal = this.closest( '.pltt-modal' );
			if ( modal && modal.id ) {
				PLTT.hideModal( modal.id );
			}
		} );
	} );

	// Close modals on backdrop click.
	document.querySelectorAll( '.pltt-modal' ).forEach( function( modal ) {
		modal.addEventListener( 'click', function( e ) {
			if ( e.target === this && this.id ) {
				PLTT.hideModal( this.id );
			}
		} );
	} );

	// Close the top modal on Escape — the top one only.
	//
	// Modals stack now: the edit-entry modal's Client and Project fields both
	// open a quick-add over it. Closing every open modal would mean Escaping out
	// of "Add client" also threw away the entry edit underneath, which is not
	// what Escape is for. The last one opened is the one on top, so the last
	// match in document order isn't reliable — track opens instead.
	document.addEventListener( 'keydown', function( e ) {
		if ( e.key !== 'Escape' ) {
			return;
		}
		const open = Array.from( document.querySelectorAll( '.pltt-modal' ) )
			.filter( function( modal ) {
				return modal.id && ! modal.classList.contains( 'pltt-hidden' );
			} );
		if ( ! open.length ) {
			return;
		}
		// Highest _plttOpenSeq wins; showModal stamps it.
		open.sort( function( a, b ) {
			return ( a._plttOpenSeq || 0 ) - ( b._plttOpenSeq || 0 );
		} );
		PLTT.hideModal( open[ open.length - 1 ].id );
	} );
} );

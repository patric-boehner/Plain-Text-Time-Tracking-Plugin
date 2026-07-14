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
			// Store the element that had focus before modal opened
			modal.dataset.previousFocus = document.activeElement ? document.activeElement.id || '' : '';

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

			// Return focus to element that had focus before modal opened
			if ( modal.dataset.previousFocus ) {
				const previousElement = document.getElementById( modal.dataset.previousFocus );
				if ( previousElement ) {
					previousElement.focus();
				}
			}

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

	// Close modals on Escape key.
	document.addEventListener( 'keydown', function( e ) {
		if ( e.key === 'Escape' ) {
			document.querySelectorAll( '.pltt-modal' ).forEach( function( modal ) {
				if ( modal.id && !modal.classList.contains( 'pltt-hidden' ) ) {
					PLTT.hideModal( modal.id );
				}
			} );
		}
	} );
} );

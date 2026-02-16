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
			.catch( error => {
				console.error( 'PLTT Error:', error );
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
	 * @param {number} minutes Total minutes.
	 * @return {string} Formatted duration.
	 */
	formatDuration: function( minutes ) {
		const hours = Math.floor( minutes / 60 );
		const mins = minutes % 60;

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
	 * @param {number} minutes Total minutes.
	 * @return {string} Formatted hours.
	 */
	formatHours: function( minutes ) {
		return ( minutes / 60 ).toFixed( 2 );
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

			// Set up focus trap
			if ( !modal.dataset.focusTrapBound ) {
				modal.dataset.focusTrapBound = 'true';
				modal.addEventListener( 'keydown', function( e ) {
					// Only trap focus if modal is visible
					if ( modal.classList.contains( 'pltt-hidden' ) ) {
						return;
					}

					if ( e.key === 'Tab' ) {
						// Get current focusable elements (in case they've changed)
						const currentFocusable = modal.querySelectorAll(
							'a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
						);
						const currentFirst = currentFocusable[0];
						const currentLast = currentFocusable[currentFocusable.length - 1];

						if ( e.shiftKey ) {
							// Shift+Tab: moving backwards
							if ( document.activeElement === currentFirst ) {
								e.preventDefault();
								currentLast.focus();
							}
						} else {
							// Tab: moving forwards
							if ( document.activeElement === currentLast ) {
								e.preventDefault();
								currentFirst.focus();
							}
						}
					}
				} );
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
	}
};

// Initialize modal close buttons.
document.addEventListener( 'DOMContentLoaded', function() {
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

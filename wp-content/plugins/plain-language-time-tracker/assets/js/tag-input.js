/**
 * Reusable tag pill input component.
 *
 * Renders tag chips with inline text input and autocomplete dropdown.
 * Keeps a hidden <input class="pltt-tags"> synced as a comma-separated string.
 */

/* global */

var PlttTagInput = ( function() {
	'use strict';

	/**
	 * @param {HTMLElement} container  Wrapper div.pltt-tag-input-wrap
	 * @param {string[]}    suggestions Array of all known tags for autocomplete.
	 */
	function PlttTagInput( container, suggestions ) {
		this.container   = container;
		this.suggestions = ( suggestions || [] ).map( function( t ) { return t.toLowerCase(); } );
		this.hiddenInput = container.querySelector( '.pltt-tags' );
		this.tags        = [];

		this._buildDOM();
		this._loadInitial();
		this._bindEvents();
	}

	/**
	 * Build the pill area, inline text input, and suggestion dropdown.
	 */
	PlttTagInput.prototype._buildDOM = function() {
		this.pillArea = document.createElement( 'div' );
		this.pillArea.className = 'pltt-tag-pill-area';

		this.textInput = document.createElement( 'input' );
		this.textInput.type = 'text';
		this.textInput.className = 'pltt-tag-text-input';
		this.textInput.placeholder = '#tags';
		this.textInput.setAttribute( 'autocomplete', 'off' );

		this.pillArea.appendChild( this.textInput );
		this.container.appendChild( this.pillArea );

		this.dropdown = document.createElement( 'div' );
		this.dropdown.className = 'pltt-tag-suggestions';
		this.dropdown.style.display = 'none';
		this.container.appendChild( this.dropdown );

		this.highlightIndex = -1;
	};

	/**
	 * Read initial value from hidden input and render pills.
	 */
	PlttTagInput.prototype._loadInitial = function() {
		var raw = ( this.hiddenInput.value || '' ).trim();
		if ( ! raw ) {
			return;
		}
		var self = this;
		raw.split( ',' ).forEach( function( t ) {
			t = self._normalize( t );
			if ( t && self.tags.indexOf( t ) === -1 ) {
				self.tags.push( t );
				self._renderPill( t );
			}
		} );
	};

	/**
	 * Strip # prefix, trim, lowercase.
	 */
	PlttTagInput.prototype._normalize = function( text ) {
		return text.replace( /^#/, '' ).trim().toLowerCase();
	};

	/**
	 * Sync the hidden input with current tags array.
	 */
	PlttTagInput.prototype._sync = function() {
		this.hiddenInput.value = this.tags.join( ',' );
	};

	/**
	 * Add a tag (if not duplicate/empty).
	 */
	PlttTagInput.prototype.addTag = function( raw ) {
		var tag = this._normalize( raw );
		if ( ! tag || this.tags.indexOf( tag ) !== -1 ) {
			return;
		}
		this.tags.push( tag );
		this._renderPill( tag );
		this._sync();
		this.textInput.value = '';
		this._hideDropdown();
	};

	/**
	 * Remove a tag by value.
	 */
	PlttTagInput.prototype.removeTag = function( tag ) {
		var idx = this.tags.indexOf( tag );
		if ( idx === -1 ) {
			return;
		}
		this.tags.splice( idx, 1 );
		var pill = this.pillArea.querySelector( '.pltt-tag-pill[data-tag="' + CSS.escape( tag ) + '"]' );
		if ( pill ) {
			pill.remove();
		}
		this._sync();
	};

	/**
	 * Render a single pill element before the text input.
	 */
	PlttTagInput.prototype._renderPill = function( tag ) {
		var self = this;
		var pill = document.createElement( 'span' );
		pill.className = 'pltt-tag-pill';
		pill.setAttribute( 'data-tag', tag );
		pill.textContent = tag;

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'pltt-tag-pill-remove';
		btn.innerHTML = '&times;';
		btn.addEventListener( 'click', function( e ) {
			e.stopPropagation();
			self.removeTag( tag );
		} );

		pill.appendChild( btn );
		this.pillArea.insertBefore( pill, this.textInput );
	};

	/**
	 * Bind keyboard and mouse events.
	 */
	PlttTagInput.prototype._bindEvents = function() {
		var self = this;

		// Click anywhere in wrapper focuses the text input.
		this.container.addEventListener( 'click', function() {
			self.textInput.focus();
		} );

		// Typing filters the dropdown.
		this.textInput.addEventListener( 'input', function() {
			self._updateDropdown();
		} );

		// Keyboard: Enter/comma/Tab to confirm, Backspace to remove last pill, arrows to navigate.
		this.textInput.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Enter' || e.key === ',' || ( e.key === 'Tab' && self.textInput.value.trim() ) ) {
				e.preventDefault();
				if ( self.highlightIndex >= 0 ) {
					var items = self.dropdown.querySelectorAll( '.pltt-tag-suggestion' );
					if ( items[ self.highlightIndex ] ) {
						self.addTag( items[ self.highlightIndex ].textContent );
					}
				} else {
					self.addTag( self.textInput.value );
				}
				return;
			}

			if ( e.key === 'Backspace' && ! self.textInput.value ) {
				if ( self.tags.length > 0 ) {
					self.removeTag( self.tags[ self.tags.length - 1 ] );
				}
				return;
			}

			if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
				e.preventDefault();
				var items = self.dropdown.querySelectorAll( '.pltt-tag-suggestion' );
				if ( ! items.length ) {
					return;
				}
				if ( e.key === 'ArrowDown' ) {
					self.highlightIndex = Math.min( self.highlightIndex + 1, items.length - 1 );
				} else {
					self.highlightIndex = Math.max( self.highlightIndex - 1, -1 );
				}
				self._highlightItem( items );
				return;
			}

			if ( e.key === 'Escape' ) {
				self._hideDropdown();
			}
		} );

		// On blur, hide dropdown after a short delay (to allow suggestion clicks).
		this.textInput.addEventListener( 'blur', function() {
			setTimeout( function() {
				self._hideDropdown();
			}, 200 );
		} );

		// On focus, show dropdown if there's text.
		this.textInput.addEventListener( 'focus', function() {
			if ( self.textInput.value.trim() ) {
				self._updateDropdown();
			}
		} );
	};

	/**
	 * Filter suggestions and render dropdown.
	 */
	PlttTagInput.prototype._updateDropdown = function() {
		var query = this._normalize( this.textInput.value );
		if ( ! query ) {
			this._hideDropdown();
			return;
		}

		var self    = this;
		var matches = this.suggestions.filter( function( s ) {
			return s.indexOf( query ) === 0 && self.tags.indexOf( s ) === -1;
		} );

		if ( ! matches.length ) {
			this._hideDropdown();
			return;
		}

		this.dropdown.innerHTML = '';
		this.highlightIndex = -1;

		matches.forEach( function( tag ) {
			var item = document.createElement( 'div' );
			item.className = 'pltt-tag-suggestion';
			item.textContent = tag;
			item.addEventListener( 'mousedown', function( e ) {
				e.preventDefault(); // Prevent blur.
				self.addTag( tag );
				self.textInput.focus();
			} );
			self.dropdown.appendChild( item );
		} );

		this.dropdown.style.display = 'block';
	};

	/**
	 * Highlight a suggestion item by index.
	 */
	PlttTagInput.prototype._highlightItem = function( items ) {
		items.forEach( function( el, i ) {
			el.classList.toggle( 'pltt-tag-suggestion-active', i === this.highlightIndex );
		}.bind( this ) );
	};

	/**
	 * Hide the suggestion dropdown.
	 */
	PlttTagInput.prototype._hideDropdown = function() {
		this.dropdown.style.display = 'none';
		this.highlightIndex = -1;
	};

	return PlttTagInput;
} )();

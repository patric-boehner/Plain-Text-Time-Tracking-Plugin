/**
 * Client Picker — Custom single-select dropdown for the Reports client filter.
 *
 * Replaces the native <select> with a searchable picker. Flat list (no sections).
 * Shares CSS (.pltt-picker-*) and a shared open-instance registry
 * (window.__plttOpenPickers) with PlttProjectPicker so the two pickers close
 * each other when one opens.
 *
 * @package PlainLanguageTimeTracker
 */

/* exported PlttClientPicker */

var PlttClientPicker = ( function() {
	'use strict';

	if ( ! window.__plttOpenPickers ) { window.__plttOpenPickers = []; }
	var _instances = window.__plttOpenPickers;

	/**
	 * @param {Object} opts
	 * @param {HTMLElement} opts.container The .pltt-picker element (contains hidden input).
	 * @param {Array}       opts.clients   Array of {id, name} objects.
	 * @param {Function}    [opts.onSelect] Called with (value, label) after each selection.
	 */
	function PlttClientPicker( opts ) {
		this.container = opts.container;
		this.clients = opts.clients || [];
		this.onSelect = opts.onSelect || null;

		this.hiddenInput = this.container.querySelector( 'input[type="hidden"]' );

		this.labels = {
			all:              this.container.getAttribute( 'data-all-label' ) || 'All Clients',
			searchPlaceholder: this.container.getAttribute( 'data-search-placeholder' ) || 'Search clients…'
		};

		_instances.push( this );

		this._buildDOM();
		this._setLabelFromValue();
		this._bindEvents();
	}

	PlttClientPicker.prototype._buildDOM = function() {
		this.trigger = document.createElement( 'button' );
		this.trigger.type = 'button';
		this.trigger.className = 'pltt-picker-trigger';
		this.trigger.setAttribute( 'aria-haspopup', 'listbox' );
		this.trigger.setAttribute( 'aria-expanded', 'false' );

		this.triggerLabel = document.createElement( 'span' );
		this.triggerLabel.className = 'pltt-picker-trigger-label';
		this.triggerLabel.textContent = this.container.getAttribute( 'data-initial-label' ) || this.labels.all;

		var caret = document.createElement( 'span' );
		caret.className = 'pltt-picker-caret';
		caret.setAttribute( 'aria-hidden', 'true' );
		caret.textContent = '▾';

		this.trigger.appendChild( this.triggerLabel );
		this.trigger.appendChild( caret );

		this.dropdown = document.createElement( 'div' );
		this.dropdown.className = 'pltt-picker-dropdown';
		this.dropdown.hidden = true;

		this.searchInput = document.createElement( 'input' );
		this.searchInput.type = 'text';
		this.searchInput.className = 'pltt-picker-search';
		this.searchInput.placeholder = this.labels.searchPlaceholder;

		this.optionsList = document.createElement( 'div' );
		this.optionsList.className = 'pltt-picker-options';

		this.dropdown.appendChild( this.searchInput );
		this.dropdown.appendChild( this.optionsList );

		this.container.appendChild( this.trigger );
		this.container.appendChild( this.dropdown );
	};

	PlttClientPicker.prototype._setLabelFromValue = function() {
		var value = this.hiddenInput.value;
		this.triggerLabel.textContent = this._labelForValue( value );
	};

	PlttClientPicker.prototype._labelForValue = function( value ) {
		if ( value === '' || value === '0' ) {
			return this.labels.all;
		}
		var client = this._findClientById( value );
		return client ? client.name : this.labels.all;
	};

	PlttClientPicker.prototype._findClientById = function( id ) {
		var target = String( id );
		for ( var i = 0; i < this.clients.length; i++ ) {
			if ( String( this.clients[ i ].id ) === target ) {
				return this.clients[ i ];
			}
		}
		return null;
	};

	PlttClientPicker.prototype._renderOptions = function() {
		var self = this;
		this.optionsList.innerHTML = '';

		this._appendOption( this.optionsList, '', this.labels.all, 'pltt-picker-option--meta' );

		for ( var i = 0; i < this.clients.length; i++ ) {
			this._appendOption( this.optionsList, String( this.clients[ i ].id ), this.clients[ i ].name, '' );
		}

		this.optionsList.querySelectorAll( '.pltt-picker-option' ).forEach( function( el ) {
			if ( el.getAttribute( 'data-value' ) === String( self.hiddenInput.value ) ) {
				el.classList.add( 'pltt-picker-option--selected' );
			}
		} );
	};

	PlttClientPicker.prototype._appendOption = function( parent, value, label, extraClass ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'pltt-picker-option' + ( extraClass ? ' ' + extraClass : '' );
		btn.setAttribute( 'data-value', value );
		btn.setAttribute( 'data-label', label );
		btn.textContent = label;

		var self = this;
		btn.addEventListener( 'click', function( e ) {
			e.stopPropagation();
			self._selectValue( value, label );
		} );

		parent.appendChild( btn );
	};

	PlttClientPicker.prototype._selectValue = function( value, label ) {
		this.hiddenInput.value = value;
		this.triggerLabel.textContent = label;
		this._closeDropdown();
		if ( this.onSelect ) {
			this.onSelect( value, label );
		}
	};

	PlttClientPicker.prototype.getValue = function() {
		return this.hiddenInput.value;
	};

	PlttClientPicker.prototype._bindEvents = function() {
		var self = this;

		this.trigger.addEventListener( 'click', function( e ) {
			e.stopPropagation();
			self._toggleDropdown();
		} );

		this.searchInput.addEventListener( 'input', function() {
			self._applySearch();
		} );

		this.searchInput.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				self._moveFocus( 1, true );
			} else if ( e.key === 'Escape' ) {
				e.preventDefault();
				self._closeDropdown();
				self.trigger.focus();
			}
		} );

		this.dropdown.addEventListener( 'keydown', function( e ) {
			if ( ! self._focusedOption ) { return; }
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				self._moveFocus( 1 );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				self._moveFocus( -1 );
			} else if ( e.key === 'Enter' ) {
				if ( document.activeElement === self._focusedOption ) {
					e.preventDefault();
					self._focusedOption.click();
				}
			} else if ( e.key === 'Escape' ) {
				e.preventDefault();
				self._closeDropdown();
				self.trigger.focus();
			}
		} );

		document.addEventListener( 'click', function( e ) {
			if ( ! self.container.contains( e.target ) ) {
				self._closeDropdown();
			}
		} );

		this.dropdown.addEventListener( 'click', function( e ) {
			e.stopPropagation();
		} );
	};

	PlttClientPicker.prototype._toggleDropdown = function() {
		if ( this.dropdown.hidden ) {
			this._openDropdown();
		} else {
			this._closeDropdown();
		}
	};

	PlttClientPicker.prototype._openDropdown = function() {
		var self = this;
		_instances.forEach( function( inst ) {
			if ( inst !== self && inst.dropdown && ! inst.dropdown.hidden ) {
				inst._closeDropdown();
			}
		} );

		this._renderOptions();
		this.searchInput.value = '';
		this.dropdown.hidden = false;
		this.trigger.setAttribute( 'aria-expanded', 'true' );
		this.searchInput.focus();
		this._resetFocus();
	};

	PlttClientPicker.prototype._closeDropdown = function() {
		this.dropdown.hidden = true;
		this.trigger.setAttribute( 'aria-expanded', 'false' );
		this._focusedOption = null;
	};

	PlttClientPicker.prototype._applySearch = function() {
		var query = ( this.searchInput.value || '' ).trim().toLowerCase();
		this.optionsList.querySelectorAll( '.pltt-picker-option' ).forEach( function( el ) {
			var label = ( el.getAttribute( 'data-label' ) || '' ).toLowerCase();
			el.hidden = !! query && label.indexOf( query ) === -1;
		} );
		this._resetFocus();
	};

	PlttClientPicker.prototype._visibleOptions = function() {
		return Array.prototype.filter.call(
			this.optionsList.querySelectorAll( '.pltt-picker-option' ),
			function( el ) { return ! el.hidden; }
		);
	};

	PlttClientPicker.prototype._resetFocus = function() {
		if ( this._focusedOption ) {
			this._focusedOption.classList.remove( 'pltt-picker-option--focus' );
		}
		this._focusedOption = null;
	};

	PlttClientPicker.prototype._moveFocus = function( delta, fromSearch ) {
		var visible = this._visibleOptions();
		if ( visible.length === 0 ) { return; }

		var idx = -1;
		if ( this._focusedOption ) {
			idx = visible.indexOf( this._focusedOption );
		}
		if ( fromSearch ) {
			idx = -1;
		}

		var next = idx + delta;
		if ( next < 0 ) { next = 0; }
		if ( next >= visible.length ) { next = visible.length - 1; }

		if ( this._focusedOption ) {
			this._focusedOption.classList.remove( 'pltt-picker-option--focus' );
		}
		this._focusedOption = visible[ next ];
		this._focusedOption.classList.add( 'pltt-picker-option--focus' );
		this._focusedOption.focus();
	};

	/**
	 * Programmatically trigger an onSelect event with the current hidden input value.
	 * Useful for syncing dependent pickers without dispatching a real change event.
	 */
	PlttClientPicker.prototype.notify = function() {
		var value = this.hiddenInput.value;
		var label = this._labelForValue( value );
		if ( this.onSelect ) {
			this.onSelect( value, label );
		}
	};

	return PlttClientPicker;
} )();

/**
 * Project Picker — Custom single-select dropdown for the Reports project filter.
 *
 * Replaces the native <select> with a searchable picker that splits projects
 * into collapsible Active and Archived sections, with per-client sub-headings
 * when no single client is selected.
 *
 * Shares CSS (.pltt-picker-*) and a shared open-instance registry
 * (window.__plttOpenPickers) with PlttClientPicker so the two pickers close
 * each other when one opens.
 *
 * @package PlainLanguageTimeTracker
 */

/* exported PlttProjectPicker */

var PlttProjectPicker = ( function() {
	'use strict';

	if ( ! window.__plttOpenPickers ) { window.__plttOpenPickers = []; }
	var _instances = window.__plttOpenPickers;

	/**
	 * @param {Object} opts
	 * @param {HTMLElement} opts.container        The .pltt-picker element (contains hidden input).
	 * @param {Object}      opts.projectsByClient Map of clientId => [{id, name, status}].
	 * @param {Object}      opts.clientNames      Map of clientId => "Client Name".
	 * @param {Function}    [opts.onSelect]       Called with (value, label) after each selection.
	 */
	function PlttProjectPicker( opts ) {
		this.container = opts.container;
		this.projectsByClient = opts.projectsByClient || {};
		this.clientNames = opts.clientNames || {};
		this.onSelect = opts.onSelect || null;

		this.hiddenInput = this.container.querySelector( 'input[type="hidden"]' );
		this.clientId = '';

		// Labels from data-* attributes.
		this.labels = {
			all:              this.container.getAttribute( 'data-all-label' ) || 'All Projects',
			withoutProject:   this.container.getAttribute( 'data-without-project-label' ) || '— Without Projects —',
			active:           this.container.getAttribute( 'data-active-label' ) || 'Active',
			archived:         this.container.getAttribute( 'data-archived-label' ) || 'Archived',
			searchPlaceholder: this.container.getAttribute( 'data-search-placeholder' ) || 'Search projects…'
		};

		// Section open/closed state — preserved across renders and search clears.
		this.sectionsOpen = { active: true, archived: false };

		_instances.push( this );

		this._buildDOM();
		this._setLabelFromValue();
		this._bindEvents();
	}

	PlttProjectPicker.prototype._buildDOM = function() {
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

	PlttProjectPicker.prototype._setLabelFromValue = function() {
		var value = this.hiddenInput.value;
		this.triggerLabel.textContent = this._labelForValue( value );
	};

	PlttProjectPicker.prototype._labelForValue = function( value ) {
		if ( value === '' || value === '0' ) {
			return this.labels.all;
		}
		if ( value === 'without_project' ) {
			return this.labels.withoutProject;
		}
		var project = this._findProjectById( value );
		return project ? project.name : this.labels.all;
	};

	PlttProjectPicker.prototype._findProjectById = function( id ) {
		var target = String( id );
		var clients = this.projectsByClient;
		for ( var cid in clients ) {
			if ( ! Object.prototype.hasOwnProperty.call( clients, cid ) ) { continue; }
			var list = clients[ cid ];
			for ( var i = 0; i < list.length; i++ ) {
				if ( String( list[ i ].id ) === target ) {
					return list[ i ];
				}
			}
		}
		return null;
	};

	PlttProjectPicker.prototype._buildScopedGroups = function() {
		var groups = { active: {}, archived: {} };
		var clients = this.projectsByClient;
		var keys;

		if ( this.clientId ) {
			keys = [ String( this.clientId ) ];
		} else {
			keys = Object.keys( clients );
		}

		for ( var k = 0; k < keys.length; k++ ) {
			var cid = keys[ k ];
			var list = clients[ cid ] || [];
			for ( var i = 0; i < list.length; i++ ) {
				var proj = list[ i ];
				var bucket = ( proj.status === 'archived' ) ? 'archived' : 'active';
				if ( ! groups[ bucket ][ cid ] ) {
					groups[ bucket ][ cid ] = [];
				}
				groups[ bucket ][ cid ].push( proj );
			}
		}
		return groups;
	};

	PlttProjectPicker.prototype._countInGroup = function( clientMap ) {
		var n = 0;
		for ( var cid in clientMap ) {
			if ( Object.prototype.hasOwnProperty.call( clientMap, cid ) ) {
				n += clientMap[ cid ].length;
			}
		}
		return n;
	};

	PlttProjectPicker.prototype._renderOptions = function() {
		var self = this;
		this.optionsList.innerHTML = '';

		// Fixed top options.
		this._appendOption( this.optionsList, '', this.labels.all, 'pltt-picker-option--meta' );
		this._appendOption( this.optionsList, 'without_project', this.labels.withoutProject, 'pltt-picker-option--meta' );

		var groups = this._buildScopedGroups();
		var activeCount = this._countInGroup( groups.active );
		var archivedCount = this._countInGroup( groups.archived );

		// Auto-open Archived if currently selected project is archived.
		var current = this._findProjectById( this.hiddenInput.value );
		if ( current && current.status === 'archived' ) {
			this.sectionsOpen.archived = true;
		}

		if ( archivedCount > 0 ) {
			this._renderSection( 'archived', this.labels.archived, groups.archived, archivedCount );
		}
		if ( activeCount > 0 ) {
			this._renderSection( 'active', this.labels.active, groups.active, activeCount );
		}

		// Highlight current selection.
		this.optionsList.querySelectorAll( '.pltt-picker-option' ).forEach( function( el ) {
			if ( el.getAttribute( 'data-value' ) === String( self.hiddenInput.value ) ) {
				el.classList.add( 'pltt-picker-option--selected' );
			}
		} );
	};

	PlttProjectPicker.prototype._renderSection = function( key, label, clientMap, count ) {
		var section = document.createElement( 'div' );
		section.className = 'pltt-picker-section';
		section.setAttribute( 'data-section', key );

		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'pltt-section-toggle';
		toggle.setAttribute( 'aria-expanded', this.sectionsOpen[ key ] ? 'true' : 'false' );

		var chevron = document.createElement( 'span' );
		chevron.className = 'pltt-section-chevron';
		chevron.setAttribute( 'aria-hidden', 'true' );
		chevron.textContent = '▾';

		var text = document.createElement( 'span' );
		text.className = 'pltt-section-label';
		text.textContent = label;

		var countEl = document.createElement( 'span' );
		countEl.className = 'pltt-section-count';
		countEl.textContent = '(' + count + ')';

		toggle.appendChild( chevron );
		toggle.appendChild( text );
		toggle.appendChild( countEl );

		var body = document.createElement( 'div' );
		body.className = 'pltt-section-body';
		body.hidden = ! this.sectionsOpen[ key ];

		// Render contents. When clientId set, clientMap has a single key — flat list.
		// Otherwise per-client sub-headings.
		var clientIds = Object.keys( clientMap );
		var showSubHeadings = ! this.clientId && clientIds.length > 0;

		if ( showSubHeadings ) {
			clientIds.sort( function( a, b ) {
				var na = ( this.clientNames[ a ] || '' ).toLowerCase();
				var nb = ( this.clientNames[ b ] || '' ).toLowerCase();
				return na.localeCompare( nb );
			}.bind( this ) );

			for ( var i = 0; i < clientIds.length; i++ ) {
				var cid = clientIds[ i ];
				var sub = document.createElement( 'div' );
				sub.className = 'pltt-picker-subheading';
				sub.textContent = this.clientNames[ cid ] || ( 'Client ' + cid );
				body.appendChild( sub );

				var projs = clientMap[ cid ];
				for ( var j = 0; j < projs.length; j++ ) {
					this._appendOption( body, String( projs[ j ].id ), projs[ j ].name, 'pltt-picker-option--nested' );
				}
			}
		} else {
			for ( var c = 0; c < clientIds.length; c++ ) {
				var pl = clientMap[ clientIds[ c ] ];
				for ( var p = 0; p < pl.length; p++ ) {
					this._appendOption( body, String( pl[ p ].id ), pl[ p ].name, '' );
				}
			}
		}

		section.appendChild( toggle );
		section.appendChild( body );
		this.optionsList.appendChild( section );

		var self = this;
		toggle.addEventListener( 'click', function( e ) {
			e.stopPropagation();
			self._toggleSection( key );
		} );
	};

	PlttProjectPicker.prototype._appendOption = function( parent, value, label, extraClass ) {
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

	PlttProjectPicker.prototype._toggleSection = function( key ) {
		this.sectionsOpen[ key ] = ! this.sectionsOpen[ key ];
		var section = this.optionsList.querySelector( '[data-section="' + key + '"]' );
		if ( ! section ) { return; }
		var toggle = section.querySelector( '.pltt-section-toggle' );
		var body = section.querySelector( '.pltt-section-body' );
		toggle.setAttribute( 'aria-expanded', this.sectionsOpen[ key ] ? 'true' : 'false' );
		body.hidden = ! this.sectionsOpen[ key ];
		this._resetFocus();
	};

	PlttProjectPicker.prototype._selectValue = function( value, label ) {
		this.hiddenInput.value = value;
		this.triggerLabel.textContent = label;
		this._closeDropdown();
		if ( this.onSelect ) {
			this.onSelect( value, label );
		}
	};

	PlttProjectPicker.prototype.setClientId = function( clientId ) {
		this.clientId = clientId || '';
		this._selectValue( '', this.labels.all );
		this.sectionsOpen = { active: true, archived: false };
	};

	PlttProjectPicker.prototype.getValue = function() {
		return this.hiddenInput.value;
	};

	PlttProjectPicker.prototype._bindEvents = function() {
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

	PlttProjectPicker.prototype._toggleDropdown = function() {
		if ( this.dropdown.hidden ) {
			this._openDropdown();
		} else {
			this._closeDropdown();
		}
	};

	PlttProjectPicker.prototype._openDropdown = function() {
		var self = this;
		_instances.forEach( function( inst ) {
			if ( inst !== self && inst.dropdown && ! inst.dropdown.hidden ) {
				inst._closeDropdown();
			}
		} );

		var current = this._findProjectById( this.hiddenInput.value );
		if ( current && current.status === 'archived' ) {
			this.sectionsOpen.archived = true;
		}

		this._renderOptions();
		this.searchInput.value = '';
		this.dropdown.hidden = false;
		this.trigger.setAttribute( 'aria-expanded', 'true' );
		this.searchInput.focus();
		this._resetFocus();
	};

	PlttProjectPicker.prototype._closeDropdown = function() {
		this.dropdown.hidden = true;
		this.trigger.setAttribute( 'aria-expanded', 'false' );
		this._focusedOption = null;
	};

	PlttProjectPicker.prototype._applySearch = function() {
		var query = ( this.searchInput.value || '' ).trim().toLowerCase();

		var sections = this.optionsList.querySelectorAll( '.pltt-picker-section' );

		sections.forEach( function( section ) {
			var key = section.getAttribute( 'data-section' );
			var body = section.querySelector( '.pltt-section-body' );
			var anyMatch = false;
			var lastSubheading = null;
			var subheadingHasMatch = false;

			Array.prototype.forEach.call( body.children, function( child ) {
				if ( child.classList.contains( 'pltt-picker-subheading' ) ) {
					if ( lastSubheading && ! subheadingHasMatch ) {
						lastSubheading.hidden = true;
					}
					lastSubheading = child;
					subheadingHasMatch = false;
					child.hidden = false;
				} else if ( child.classList.contains( 'pltt-picker-option' ) ) {
					var label = ( child.getAttribute( 'data-label' ) || '' ).toLowerCase();
					var match = ! query || label.indexOf( query ) !== -1;
					child.hidden = ! match;
					if ( match ) {
						anyMatch = true;
						subheadingHasMatch = true;
					}
				}
			} );

			if ( lastSubheading && ! subheadingHasMatch ) {
				lastSubheading.hidden = true;
			}

			if ( query && anyMatch ) {
				body.hidden = false;
				section.querySelector( '.pltt-section-toggle' ).setAttribute( 'aria-expanded', 'true' );
			} else if ( ! query ) {
				body.hidden = ! this.sectionsOpen[ key ];
				section.querySelector( '.pltt-section-toggle' ).setAttribute( 'aria-expanded', this.sectionsOpen[ key ] ? 'true' : 'false' );
			}

			section.hidden = !! query && ! anyMatch;
		}.bind( this ) );

		var metas = this.optionsList.querySelectorAll( '.pltt-picker-option--meta' );
		metas.forEach( function( el ) {
			var label = ( el.getAttribute( 'data-label' ) || '' ).toLowerCase();
			el.hidden = !! query && label.indexOf( query ) === -1;
		} );

		this._resetFocus();
	};

	PlttProjectPicker.prototype._visibleOptions = function() {
		return Array.prototype.filter.call(
			this.optionsList.querySelectorAll( '.pltt-picker-option' ),
			function( el ) {
				if ( el.hidden ) { return false; }
				var body = el.closest( '.pltt-section-body' );
				if ( body && body.hidden ) { return false; }
				return true;
			}
		);
	};

	PlttProjectPicker.prototype._resetFocus = function() {
		if ( this._focusedOption ) {
			this._focusedOption.classList.remove( 'pltt-picker-option--focus' );
		}
		this._focusedOption = null;
	};

	PlttProjectPicker.prototype._moveFocus = function( delta, fromSearch ) {
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

	return PlttProjectPicker;
} )();

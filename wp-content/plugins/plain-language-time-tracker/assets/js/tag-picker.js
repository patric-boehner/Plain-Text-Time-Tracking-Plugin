/**
 * Tag Picker — Checkbox dropdown for selecting tags.
 *
 * Replaces freeform text input with a dropdown of checkboxes.
 * Selected tags display as pills. Search field filters the list.
 *
 * @package PlainLanguageTimeTracker
 */

/* exported PlttTagPicker */

var PlttTagPicker = ( function() {
	'use strict';

	// Registry of all instances — used to close other open pickers when one opens.
	var _instances = [];

	/**
	 * Create a tag picker instance.
	 *
	 * @param {HTMLElement}   container The .pltt-tag-input-wrap element.
	 * @param {Array}         allTags   Array of all known tag strings.
	 * @param {Function|null} onAddNew  Optional callback when "Add new tag…" is clicked; receives picker instance.
	 * @param {Function|null} onClose   Optional callback when the dropdown closes; receives (selectedTags, csvValue).
	 * @param {Object|null}   tagGroups Optional map of tag name => group name; when provided, the
	 *                                  checkbox list is rendered with labeled group sections.
	 */
	function PlttTagPicker( container, allTags, onAddNew, onClose, tagGroups ) {
		this.container = container;
		this.hiddenInput = container.querySelector( '.pltt-tags' );
		this.allTags = allTags || [];
		this.selectedTags = [];
		this.onAddNew = onAddNew || null;
		this.onClose = onClose || null;
		this.tagGroups = tagGroups || {};

		// Opt-in predicted state (set by the finalize screen via data-predicted).
		// Pre-filled tags are parser guesses: shown dashed ("records if left")
		// until the user edits this row, then they solidify. Other usages
		// (Reports, edit-existing) don't set the flag, so this is inert there.
		this.markPredicted = container.dataset.predicted === '1';
		this.predictedTags = [];

		_instances.push( this );

		this._buildDOM();
		this._loadInitial();
		this._bindEvents();
	}

	/**
	 * Build the pill display, trigger button, and dropdown panel.
	 */
	PlttTagPicker.prototype._buildDOM = function() {
		// Pills display area.
		this.pillsArea = document.createElement( 'div' );
		this.pillsArea.className = 'pltt-tag-pills';

		// Trigger button.
		this.triggerBtn = document.createElement( 'button' );
		this.triggerBtn.type = 'button';
		this.triggerBtn.className = 'button button-secondary';
		this.triggerBtn.textContent = 'Add Tags';
		this.triggerBtn.title = 'Select tags';

		// Dropdown panel.
		this.dropdown = document.createElement( 'div' );
		this.dropdown.className = 'pltt-tag-picker-dropdown';
		this.dropdown.style.display = 'none';

		// Search input inside dropdown.
		this.searchInput = document.createElement( 'input' );
		this.searchInput.type = 'text';
		this.searchInput.className = 'pltt-tag-search';
		this.searchInput.placeholder = 'Search tags\u2026';

		// Checkbox list.
		this.checkboxList = document.createElement( 'div' );
		this.checkboxList.className = 'pltt-tag-checkbox-list';

		this.dropdown.appendChild( this.searchInput );
		this.dropdown.appendChild( this.checkboxList );

		this.container.appendChild( this.pillsArea );
		this.container.appendChild( this.triggerBtn );
		this.container.appendChild( this.dropdown );

		this._renderCheckboxes();
	};

	/**
	 * Load initial selected tags from the hidden input value.
	 */
	PlttTagPicker.prototype._loadInitial = function() {
		var raw = ( this.hiddenInput.value || '' ).trim();
		if ( ! raw ) {
			this._renderPills();
			return;
		}

		var self = this;
		raw.split( ',' ).forEach( function( tag ) {
			tag = self._normalize( tag );
			if ( tag && self.selectedTags.indexOf( tag ) === -1 ) {
				self.selectedTags.push( tag );
			}
		} );

		// Whatever was pre-filled here is the prediction set.
		if ( this.markPredicted ) {
			this.predictedTags = this.selectedTags.slice();
		}

		this._renderPills();
		this._updateCheckboxStates();
	};

	/**
	 * Normalize a tag string.
	 *
	 * @param {string} text Raw tag text.
	 * @return {string} Normalized tag.
	 */
	PlttTagPicker.prototype._normalize = function( text ) {
		return text.replace( /^#/, '' ).trim().toLowerCase();
	};

	/**
	 * Sync the hidden input with current selected tags.
	 */
	PlttTagPicker.prototype._sync = function() {
		this.hiddenInput.value = this.selectedTags.join( ',' );
	};

	/**
	 * Append a single tag checkbox row to a container.
	 *
	 * @param {HTMLElement} target  Container to append into.
	 * @param {string}      tag     Tag name.
	 * @param {string}      section Header this row sits under, so search
	 *                              filtering can hide a header once every row
	 *                              beneath it is filtered out. Omit for rows
	 *                              that have no header (the ungrouped tail).
	 */
	PlttTagPicker.prototype._renderCheckboxItem = function( target, tag, section ) {
		var self = this;
		var label = document.createElement( 'label' );
		label.className = 'pltt-tag-checkbox-item';
		if ( section ) {
			label.dataset.section = section;
		}

		var checkbox = document.createElement( 'input' );
		checkbox.type = 'checkbox';
		checkbox.value = tag;
		checkbox.checked = self.selectedTags.indexOf( tag ) !== -1;

		checkbox.addEventListener( 'change', function() {
			self._onCheckboxChange( tag, this.checked );
		} );

		var text = document.createTextNode( ' ' + tag.charAt( 0 ).toUpperCase() + tag.slice( 1 ) );

		label.appendChild( checkbox );
		label.appendChild( text );
		target.appendChild( label );
	};

	/**
	 * Render the checkbox list. Tags already on the entry come first under a
	 * "Selected" header, so unticking one never means scrolling to find it.
	 * They are moved there, not copied — no tag renders twice. The rest follow,
	 * in labeled sections per group when tagGroups is non-empty.
	 *
	 * Only called when the dropdown opens, so ticking a box mid-session doesn't
	 * shuffle the list out from under the pointer.
	 */
	PlttTagPicker.prototype._renderCheckboxes = function() {
		var self = this;
		this.checkboxList.innerHTML = '';

		if ( this.selectedTags.length > 0 ) {
			var selectedHeader = document.createElement( 'div' );
			selectedHeader.className = 'pltt-tag-group-header';
			selectedHeader.textContent = 'Selected';
			selectedHeader.dataset.section = '__selected';
			this.checkboxList.appendChild( selectedHeader );

			// Selection order, matching the pills outside the dropdown.
			this.selectedTags.forEach( function( tag ) {
				self._renderCheckboxItem( self.checkboxList, tag, '__selected' );
			} );
		}

		var remaining = this.allTags.filter( function( tag ) {
			return self.selectedTags.indexOf( tag ) === -1;
		} );

		if ( remaining.length === 0 ) {
			if ( this.selectedTags.length === 0 ) {
				var empty = document.createElement( 'div' );
				empty.className = 'pltt-tag-empty';
				empty.textContent = 'No tags available';
				this.checkboxList.appendChild( empty );
			}
		} else {
			var hasGroups = this.tagGroups && Object.keys( this.tagGroups ).length > 0;

			if ( hasGroups ) {
				// Group tags by their group_name, with ungrouped collected separately.
				var byGroup = {};
				var ungrouped = [];
				remaining.forEach( function( tag ) {
					var g = self.tagGroups[ tag ];
					if ( g ) {
						if ( ! byGroup[ g ] ) {
							byGroup[ g ] = [];
						}
						byGroup[ g ].push( tag );
					} else {
						ungrouped.push( tag );
					}
				} );

				var groupNames = Object.keys( byGroup ).sort( function( a, b ) {
					return a.localeCompare( b );
				} );

				groupNames.forEach( function( g ) {
					var header = document.createElement( 'div' );
					header.className = 'pltt-tag-group-header';
					header.textContent = g;
					header.dataset.section = g;
					self.checkboxList.appendChild( header );

					byGroup[ g ].sort( function( a, b ) {
						return a.localeCompare( b );
					} );
					byGroup[ g ].forEach( function( tag ) {
						self._renderCheckboxItem( self.checkboxList, tag, g );
					} );
				} );

				if ( ungrouped.length > 0 ) {
					ungrouped.sort( function( a, b ) {
						return a.localeCompare( b );
					} );
					ungrouped.forEach( function( tag ) {
						self._renderCheckboxItem( self.checkboxList, tag );
					} );
				}
			} else {
				// No groups: the "Selected" header above now does the lifting the
				// old selected-first sort used to, so this is plain alphabetical.
				remaining.sort( function( a, b ) {
					return a.localeCompare( b );
				} );

				remaining.forEach( function( tag ) {
					self._renderCheckboxItem( self.checkboxList, tag );
				} );
			}
		}

		// "Add new tag…" link — only shown when a callback is provided.
		if ( self.onAddNew ) {
			var addLink = document.createElement( 'div' );
			addLink.className = 'pltt-tag-add-new';
			var a = document.createElement( 'a' );
			a.href = '#add-tag';
			a.textContent = '+ Add new tag\u2026';
			a.addEventListener( 'click', function( e ) {
				e.preventDefault();
				e.stopPropagation();
				self.onAddNew( self );
			} );
			addLink.appendChild( a );
			self.checkboxList.appendChild( addLink );
		}
	};

	/**
	 * Add a newly created tag to this picker's available list and optionally select it.
	 *
	 * @param {string}  tagName    The normalized tag name to add.
	 * @param {boolean} autoSelect Whether to auto-select the tag after adding.
	 * @param {string}  groupName  Optional group, so the tag files itself under
	 *                             the right header instead of the unlabeled tail.
	 */
	PlttTagPicker.prototype.addTagOption = function( tagName, autoSelect, groupName ) {
		if ( this.allTags.indexOf( tagName ) === -1 ) {
			this.allTags.push( tagName );
		}
		if ( groupName ) {
			this.tagGroups[ tagName ] = groupName;
		}
		if ( autoSelect && this.selectedTags.indexOf( tagName ) === -1 ) {
			this.selectedTags.push( tagName );
			// Adding a tag by hand confirms the row — drop the predicted cue.
			this.predictedTags = [];
			this._renderPills();
			this._sync();
		}
		// Re-render if dropdown is open.
		if ( this.dropdown.style.display !== 'none' ) {
			this._renderCheckboxes();
			this._filterCheckboxes();
		}
	};

	/**
	 * Replace the current selection.
	 *
	 * The edit modal is one element shared by every row on the page, so its
	 * picker gets re-pointed at a different entry's tags each time it opens.
	 *
	 * Nothing watches the hidden input, so writing to it directly leaves the
	 * pills and checkboxes showing the previous entry's tags — this is the way
	 * in from outside.
	 *
	 * @param {string|Array} tags Comma-separated names or an array. Empty clears.
	 */
	PlttTagPicker.prototype.setTags = function( tags ) {
		var self = this;
		var list = Array.isArray( tags ) ? tags : String( tags || '' ).split( ',' );

		this.selectedTags = [];
		list.forEach( function( tag ) {
			tag = self._normalize( tag );
			if ( tag && self.selectedTags.indexOf( tag ) === -1 ) {
				self.selectedTags.push( tag );
			}
		} );

		this._sync();
		this._renderPills();
		this._updateCheckboxStates();
	};

	/**
	 * Update checkbox checked states to match selectedTags.
	 */
	PlttTagPicker.prototype._updateCheckboxStates = function() {
		var self = this;
		this.checkboxList.querySelectorAll( 'input[type="checkbox"]' ).forEach( function( cb ) {
			cb.checked = self.selectedTags.indexOf( cb.value ) !== -1;
		} );
	};

	/**
	 * Handle a checkbox being toggled.
	 *
	 * @param {string}  tag     The tag name.
	 * @param {boolean} checked Whether it was checked.
	 */
	PlttTagPicker.prototype._onCheckboxChange = function( tag, checked ) {
		var index = this.selectedTags.indexOf( tag );

		if ( checked && index === -1 ) {
			this.selectedTags.push( tag );
		} else if ( ! checked && index !== -1 ) {
			this.selectedTags.splice( index, 1 );
		}

		// Editing this row's tags confirms them — drop the dashed predicted cue.
		this.predictedTags = [];

		this._renderPills();
		this._sync();
	};

	/**
	 * Render pills for selected tags. Pills act as the trigger to open the picker.
	 */
	PlttTagPicker.prototype._renderPills = function() {
		var self = this;
		this.pillsArea.innerHTML = '';

		// Toggle trigger button visibility: hide when pills exist.
		this.triggerBtn.style.display = this.selectedTags.length > 0 ? 'none' : '';

		if ( this.selectedTags.length === 0 ) {
			return;
		}

		this.selectedTags.forEach( function( tag ) {
			var pill = document.createElement( 'span' );
			pill.className = 'pltt-badge pltt-badge-tag pltt-tag-pill-trigger';
			if ( self.predictedTags.indexOf( tag ) !== -1 ) {
				pill.className += ' is-predicted';
				pill.title = 'Predicted tag — click to confirm or change';
			} else {
				pill.title = 'Click to edit tags';
			}
			pill.textContent = tag.charAt( 0 ).toUpperCase() + tag.slice( 1 );
			pill.addEventListener( 'click', function( e ) {
				e.stopPropagation();
				self._toggleDropdown();
			} );
			self.pillsArea.appendChild( pill );
		} );
	};

	/**
	 * Bind all event listeners.
	 */
	PlttTagPicker.prototype._bindEvents = function() {
		var self = this;

		// Toggle dropdown on trigger click.
		this.triggerBtn.addEventListener( 'click', function( e ) {
			e.stopPropagation();
			self._toggleDropdown();
		} );

		// Filter checkboxes as user types.
		this.searchInput.addEventListener( 'input', function() {
			self._filterCheckboxes();
		} );

		// Close dropdown when clicking outside.
		document.addEventListener( 'click', function( e ) {
			if ( ! self.container.contains( e.target ) ) {
				self._closeDropdown();
			}
		} );

		// Prevent dropdown close when clicking inside it.
		this.dropdown.addEventListener( 'click', function( e ) {
			e.stopPropagation();
		} );
	};

	/**
	 * Toggle dropdown open/closed.
	 */
	PlttTagPicker.prototype._toggleDropdown = function() {
		if ( this.dropdown.style.display === 'none' ) {
			this._openDropdown();
		} else {
			this._closeDropdown();
		}
	};

	/**
	 * Open the dropdown.
	 */
	PlttTagPicker.prototype._openDropdown = function() {
		// Close any other open picker first.
		var self = this;
		_instances.forEach( function( instance ) {
			if ( instance !== self && instance.dropdown.style.display !== 'none' ) {
				instance._closeDropdown();
			}
		} );

		this._renderCheckboxes();
		this.dropdown.style.display = 'block';
		this.searchInput.value = '';
		this._filterCheckboxes();
		this.searchInput.focus();
	};

	/**
	 * Close the dropdown.
	 */
	PlttTagPicker.prototype._closeDropdown = function() {
		this.dropdown.style.display = 'none';
		if ( this.onClose ) {
			this.onClose( this.selectedTags.slice(), this.hiddenInput.value );
		}
	};

	/**
	 * Filter the checkbox list by search query.
	 */
	PlttTagPicker.prototype._filterCheckboxes = function() {
		var query = this._normalize( this.searchInput.value );

		this.checkboxList.querySelectorAll( '.pltt-tag-checkbox-item' ).forEach( function( item ) {
			var checkbox = item.querySelector( 'input' );
			var tag = checkbox.value;

			if ( ! query || tag.indexOf( query ) !== -1 ) {
				item.style.display = '';
			} else {
				item.style.display = 'none';
			}
		} );

		// Hide any header whose rows are all filtered out. Matched by section
		// rather than by walking siblings: the last header on the list has no
		// following header to stop the walk, so it would count the ungrouped
		// tail as its own.
		var items = this.checkboxList.querySelectorAll( '.pltt-tag-checkbox-item' );
		this.checkboxList.querySelectorAll( '.pltt-tag-group-header' ).forEach( function( header ) {
			var visible = Array.prototype.some.call( items, function( item ) {
				return item.dataset.section === header.dataset.section && item.style.display !== 'none';
			} );
			header.style.display = visible ? '' : 'none';
		} );
	};

	return PlttTagPicker;
} )();

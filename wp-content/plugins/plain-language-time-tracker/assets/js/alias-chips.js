/**
 * Alias chip manager.
 *
 * A lightweight free-text chip field for seeding/pruning aliases on the client
 * and project settings forms. The modal is shared and JS-populated, so this
 * exposes an instance API (create -> { clear, setExisting, getAdditions })
 * rather than auto-initializing.
 *
 * Native form submit picks up hidden inputs:
 *   - aliases_add[]    one per newly typed chip
 *   - aliases_remove[] one per pruned existing alias (by id)
 * The AJAX create path reads getAdditions() and sends them as aliases_json.
 *
 * @package PlainLanguageTimeTracker
 */
(function () {
	'use strict';

	function lower( s ) {
		return String( s ).trim().toLowerCase();
	}

	function create( container ) {
		if ( ! container ) {
			return null;
		}

		var list   = container.querySelector( '.pltt-alias-chip-list' );
		var input  = container.querySelector( '.pltt-alias-input' );
		var hidden = container.querySelector( '.pltt-alias-hidden' );
		var removeLabel = container.getAttribute( 'data-remove-label' ) || 'Remove';

		function currentTexts() {
			return Array.prototype.map.call(
				list.querySelectorAll( '.pltt-alias-chip' ),
				function ( c ) { return lower( c.getAttribute( 'data-text' ) ); }
			);
		}

		function removeChip( chip ) {
			var id = chip.getAttribute( 'data-id' );
			if ( id ) {
				// Existing alias: record a pruning request for the native submit.
				var rm = document.createElement( 'input' );
				rm.type  = 'hidden';
				rm.name  = 'aliases_remove[]';
				rm.value = id;
				hidden.appendChild( rm );
			} else if ( chip._hidden ) {
				chip._hidden.remove();
			}
			chip.remove();
		}

		function makeChip( text, opts ) {
			opts = opts || {};
			var chip = document.createElement( 'span' );
			chip.className = 'pltt-alias-chip ' + ( opts.existing ? 'is-existing' : 'is-new' );
			chip.setAttribute( 'data-text', text );
			if ( opts.id ) {
				chip.setAttribute( 'data-id', opts.id );
			}

			var label = document.createElement( 'span' );
			label.className   = 'pltt-alias-chip-text';
			label.textContent = text;
			chip.appendChild( label );

			if ( opts.use !== undefined && opts.use !== null && opts.use !== '' ) {
				var use = document.createElement( 'span' );
				use.className   = 'pltt-alias-chip-use';
				use.textContent = '·' + opts.use;
				use.title       = opts.use + ' uses';
				chip.appendChild( use );
			}

			var btn = document.createElement( 'button' );
			btn.type      = 'button';
			btn.className = 'pltt-alias-chip-remove';
			btn.setAttribute( 'aria-label', removeLabel );
			btn.textContent = '×';
			btn.addEventListener( 'click', function () { removeChip( chip ); } );
			chip.appendChild( btn );

			if ( ! opts.existing ) {
				var h = document.createElement( 'input' );
				h.type  = 'hidden';
				h.name  = 'aliases_add[]';
				h.value = text;
				chip._hidden = h;
				hidden.appendChild( h );
			}

			return chip;
		}

		function addText( raw ) {
			var text = String( raw ).trim();
			if ( ! text ) {
				return;
			}
			if ( currentTexts().indexOf( lower( text ) ) !== -1 ) {
				return; // de-dupe within the field.
			}
			list.appendChild( makeChip( text, { existing: false } ) );
		}

		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ',' === e.key ) {
				e.preventDefault();
				addText( input.value );
				input.value = '';
			} else if ( 'Backspace' === e.key && '' === input.value ) {
				var chips = list.querySelectorAll( '.pltt-alias-chip' );
				if ( chips.length ) {
					removeChip( chips[ chips.length - 1 ] );
				}
			}
		} );

		// Commit a half-typed alias if focus leaves the field.
		input.addEventListener( 'blur', function () {
			if ( input.value.trim() ) {
				addText( input.value );
				input.value = '';
			}
		} );

		return {
			clear: function () {
				list.innerHTML   = '';
				hidden.innerHTML = '';
				input.value      = '';
			},
			setExisting: function ( items ) {
				this.clear();
				( items || [] ).forEach( function ( it ) {
					list.appendChild( makeChip( it.text, { existing: true, id: it.id, use: it.use } ) );
				} );
			},
			getAdditions: function () {
				return Array.prototype.map.call(
					list.querySelectorAll( '.pltt-alias-chip.is-new' ),
					function ( c ) { return c.getAttribute( 'data-text' ); }
				);
			}
		};
	}

	window.PlttAliasChips = { create: create };
})();

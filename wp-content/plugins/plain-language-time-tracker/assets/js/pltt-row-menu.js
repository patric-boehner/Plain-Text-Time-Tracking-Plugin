/**
 * PlttRowMenu — the always-visible row action menu (kebab).
 *
 * Spec §6: every row carries one menu in its last column, holding edit,
 * navigation and destructive actions. Replaces WordPress's hover-revealed
 * .row-actions.
 *
 * A screen renders triggers with pltt_render_row_menu_trigger() (helpers.php)
 * and registers one builder per row type:
 *
 *   PlttRowMenu.register( 'entry', function ( trigger ) {
 *       return [
 *           { label: 'Edit entry…',  kind: 'primary', onSelect: … },
 *           { label: 'Go to log',    href: '…' },
 *           { kind: 'separator' },
 *           { label: 'Delete entry', kind: 'destructive', confirm: '…', onSelect: … },
 *       ];
 *   } );
 *
 * The builder reads whatever it needs off the trigger's data- attributes and
 * returns a fresh array each time, so a row whose state changed since render
 * (billable toggled, entry billed) gets the right items without re-rendering
 * the row.
 *
 * ── Two implementation notes ────────────────────────────────────────────
 *
 * 1. One panel for the whole page, not one per row. The Overview renders
 *    hundreds of entry rows.
 *
 * 2. popover="manual", not "auto". The panel is a popover for one reason: the
 *    top layer. These tables are `overflow-x: auto` below 782px with
 *    `table-layout: fixed` cells, so an absolutely-positioned menu in the last
 *    column gets clipped — which rules out the .pltt-date-nav-dropdown
 *    approach. "auto" would also hand us light dismiss and Escape free, but its
 *    light dismiss races the trigger's own click: the UA closes the panel on
 *    pointerdown, then the click handler reopens it, and clicking the same
 *    kebab twice never closes. "manual" plus the ~20 lines of dismiss below
 *    (the same shape as pltt-date-nav.js) is the predictable trade.
 *
 * Item labels are written with textContent, never innerHTML.
 *
 * Vanilla JS, no dependencies.
 */
(function () {
	'use strict';

	var EDGE = 4; // Min viewport gap when clamping.
	var GAP = 4; // Distance between trigger and panel.

	var menuEl = null;
	var activeTrigger = null;
	var builders = {};

	function ensureMenu() {
		if ( menuEl ) {
			return menuEl;
		}
		menuEl = document.createElement( 'div' );
		menuEl.className = 'pltt-row-menu';
		menuEl.id = 'pltt-row-menu';
		menuEl.setAttribute( 'popover', 'manual' );
		document.body.appendChild( menuEl );
		return menuEl;
	}

	function isOpen() {
		return !! ( menuEl && menuEl.matches( ':popover-open' ) );
	}

	// ── Building ─────────────────────────────────────────────────────────

	/**
	 * Render one item into the panel.
	 *
	 * An item with href becomes an <a> (it navigates); everything else becomes
	 * a <button> (it acts). Both are styled by .pltt-row-menu-item.
	 *
	 * @param {Object} item Item spec from the registered builder.
	 */
	function appendItem( item ) {
		if ( 'separator' === item.kind ) {
			var hr = document.createElement( 'hr' );
			hr.className = 'pltt-row-menu-separator';
			menuEl.appendChild( hr );
			return;
		}

		var el;
		if ( item.href ) {
			el = document.createElement( 'a' );
			el.href = item.href;
		} else {
			el = document.createElement( 'button' );
			el.type = 'button';
		}

		el.className = 'pltt-row-menu-item';
		if ( 'primary' === item.kind ) {
			el.classList.add( 'is-primary' );
		} else if ( 'destructive' === item.kind ) {
			el.classList.add( 'is-destructive' );
		}
		el.textContent = item.label;

		el.addEventListener( 'click', function ( e ) {
			// Destructive items always confirm (spec §6). A non-destructive item
			// can opt in by supplying its own confirm text.
			if ( item.confirm && ! window.confirm( item.confirm ) ) {
				e.preventDefault();
				return;
			}
			if ( item.onSelect ) {
				e.preventDefault();
			}

			// Close before acting: the action may open a modal, and the panel
			// must be out of the top layer before the modal enters it. Skip the
			// focus restore for the same reason — the action decides where focus
			// belongs next.
			var trigger = activeTrigger;
			close( ! item.onSelect );

			if ( item.onSelect ) {
				item.onSelect( trigger );
			}
		} );

		menuEl.appendChild( el );
	}

	function buildItems( trigger ) {
		menuEl.textContent = '';

		var builder = builders[ trigger.getAttribute( 'data-menu' ) ];
		if ( ! builder ) {
			return false;
		}

		var items = builder( trigger ) || [];
		items.forEach( appendItem );

		return !! menuEl.firstChild;
	}

	// ── Placement ────────────────────────────────────────────────────────

	/**
	 * Place the panel against its trigger, flipping above when the viewport
	 * has no room below. Mirrors PlttTooltip.position(), minus the arrow —
	 * fixed coordinates, since the top layer is positioned to the viewport.
	 *
	 * @param {Element} trigger The kebab button.
	 */
	function position( trigger ) {
		var rect = trigger.getBoundingClientRect();
		var mw = menuEl.offsetWidth;
		var mh = menuEl.offsetHeight;
		var vw = document.documentElement.clientWidth;
		var vh = document.documentElement.clientHeight;

		// Prefer below; flip above when it would run off the bottom and there
		// is more room up top.
		var top = rect.bottom + GAP;
		if ( top + mh > vh - EDGE && rect.top - mh - GAP > EDGE ) {
			top = rect.top - mh - GAP;
		}
		top = Math.max( EDGE, Math.min( top, vh - mh - EDGE ) );

		// Right-align to the trigger: the kebab is the last column, so the panel
		// opens inward rather than off the edge of the table.
		var left = rect.right - mw;
		left = Math.max( EDGE, Math.min( left, vw - mw - EDGE ) );

		menuEl.style.top = Math.round( top ) + 'px';
		menuEl.style.left = Math.round( left ) + 'px';
	}

	// ── Open / close ─────────────────────────────────────────────────────

	function open( trigger ) {
		ensureMenu();

		if ( ! buildItems( trigger ) ) {
			return;
		}

		activeTrigger = trigger;
		menuEl.showPopover();
		trigger.setAttribute( 'aria-expanded', 'true' );
		position( trigger );

		var first = menuEl.querySelector( '.pltt-row-menu-item' );
		if ( first ) {
			first.focus();
		}
	}

	/**
	 * @param {boolean} restoreFocus Return focus to the trigger. Skipped when
	 *                               the action taking over decides focus itself.
	 */
	function close( restoreFocus ) {
		if ( ! isOpen() ) {
			activeTrigger = null;
			return;
		}

		var trigger = activeTrigger;
		menuEl.hidePopover();
		activeTrigger = null;

		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', 'false' );
			if ( restoreFocus ) {
				trigger.focus();
			}
		}
	}

	// ── Wiring ───────────────────────────────────────────────────────────

	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest ? e.target.closest( '.pltt-row-menu-trigger' ) : null;

		if ( trigger ) {
			e.preventDefault();
			var wasActive = ( trigger === activeTrigger && isOpen() );
			close( false );
			if ( ! wasActive ) {
				open( trigger );
			}
			return;
		}

		// Outside click. Items handle their own clicks and close first, so
		// anything reaching here that isn't in the panel dismisses it.
		if ( isOpen() && ! menuEl.contains( e.target ) ) {
			close( false );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ! isOpen() ) {
			return;
		}

		if ( 'Escape' === e.key ) {
			e.preventDefault();
			close( true );
			return;
		}

		if ( ! menuEl.contains( document.activeElement ) ) {
			return;
		}

		var items = Array.prototype.slice.call( menuEl.querySelectorAll( '.pltt-row-menu-item' ) );
		var idx = items.indexOf( document.activeElement );

		if ( 'ArrowDown' === e.key ) {
			e.preventDefault();
			items[ ( idx + 1 ) % items.length ].focus();
		} else if ( 'ArrowUp' === e.key ) {
			e.preventDefault();
			items[ ( idx - 1 + items.length ) % items.length ].focus();
		} else if ( 'Home' === e.key ) {
			e.preventDefault();
			items[0].focus();
		} else if ( 'End' === e.key ) {
			e.preventDefault();
			items[ items.length - 1 ].focus();
		} else if ( 'Tab' === e.key ) {
			// Tab moves on normally; the panel closes once focus has left it.
			setTimeout( function () {
				if ( isOpen() && ! menuEl.contains( document.activeElement ) ) {
					close( false );
				}
			}, 0 );
		}
	} );

	// The panel is positioned once, against where the trigger was. Rather than
	// track the trigger through a scroll, close — the row it belongs to is
	// moving out from under it anyway. Capture catches scrolling containers,
	// not just the window.
	document.addEventListener( 'scroll', function () {
		close( false );
	}, true );

	window.addEventListener( 'resize', function () {
		close( false );
	} );

	window.PlttRowMenu = {
		/**
		 * Register the item builder for a row type.
		 *
		 * @param {string}   type    Matches the trigger's data-menu attribute.
		 * @param {Function} builder Receives the trigger, returns an item array.
		 */
		register: function ( type, builder ) {
			builders[ type ] = builder;
		},
		close: function () {
			close( false );
		},
		isOpen: isOpen,
	};
})();

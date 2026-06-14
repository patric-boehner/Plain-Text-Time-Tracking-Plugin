/**
 * PlttTooltip — formatted hover/focus tooltips for charts.
 *
 * A single floating card is reused for every trigger. Triggers opt in with a
 * `data-pltt-tip` attribute and describe their content with:
 *
 *   data-tip-title  Heading text (optional).
 *   data-tip-color  Dot color: a CSS color, "none" to suppress the dot, or
 *                   omit to inherit the trigger's `--pltt-bar-color`.
 *   data-tip-rows   JSON array of [label, value] pairs (an optional third
 *                   element is a CSS color for a dot beside the label, used to
 *                   match a chart legend). A "" label renders a full-width line
 *                   instead of a label/value row.
 *
 * Content is built with textContent only (never innerHTML), so the JSON values
 * are rendered as plain text and cannot inject markup.
 *
 * Vanilla JS, no dependencies.
 */
(function () {
	'use strict';

	var SHOW_DELAY = 120; // ms to wait before showing, matching "interest".
	var EDGE = 4; // Min viewport gap when clamping.
	var GAP = 8; // Distance between trigger and tooltip.

	var tipEl = null;
	var showTimer = null;
	var activeTrigger = null;

	function ensureTip() {
		if ( tipEl ) {
			return tipEl;
		}
		tipEl = document.createElement( 'div' );
		tipEl.className = 'pltt-tip';
		tipEl.setAttribute( 'role', 'tooltip' );
		tipEl.hidden = true;
		document.body.appendChild( tipEl );
		return tipEl;
	}

	function resolveColor( trigger ) {
		var attr = trigger.getAttribute( 'data-tip-color' );
		if ( 'none' === attr ) {
			return '';
		}
		if ( attr ) {
			return attr;
		}
		// Custom properties inherit, so a child trigger picks up its track color.
		var inherited = getComputedStyle( trigger ).getPropertyValue( '--pltt-bar-color' );
		return inherited ? inherited.trim() : '';
	}

	function buildContent( trigger ) {
		var tip = ensureTip();
		tip.textContent = '';

		var title = trigger.getAttribute( 'data-tip-title' ) || '';
		if ( title ) {
			var head = document.createElement( 'div' );
			head.className = 'pltt-tip-head';

			var color = resolveColor( trigger );
			if ( color ) {
				var dot = document.createElement( 'span' );
				dot.className = 'pltt-tip-dot';
				dot.style.setProperty( '--pltt-tip-dot', color );
				head.appendChild( dot );
			}

			var label = document.createElement( 'span' );
			label.textContent = title;
			head.appendChild( label );
			tip.appendChild( head );
		}

		var rowsRaw = trigger.getAttribute( 'data-tip-rows' );
		if ( ! rowsRaw ) {
			return;
		}

		var rows;
		try {
			rows = JSON.parse( rowsRaw );
		} catch ( e ) {
			return;
		}
		if ( ! rows || ! rows.length ) {
			return;
		}

		var dl = document.createElement( 'dl' );
		dl.className = 'pltt-tip-rows';
		rows.forEach( function ( row ) {
			var rowLabel = row[ 0 ] || '';
			var rowValue = row.length > 1 ? row[ 1 ] : '';
			if ( '' === rowLabel ) {
				var line = document.createElement( 'div' );
				line.className = 'pltt-tip-line';
				line.textContent = rowValue;
				dl.appendChild( line );
				return;
			}
			var dt = document.createElement( 'dt' );
			var rowColor = row.length > 2 ? row[ 2 ] : '';
			if ( rowColor ) {
				var rowDot = document.createElement( 'span' );
				rowDot.className = 'pltt-tip-dot';
				rowDot.style.setProperty( '--pltt-tip-dot', rowColor );
				dt.appendChild( rowDot );
			}
			dt.appendChild( document.createTextNode( rowLabel ) );
			var dd = document.createElement( 'dd' );
			dd.textContent = rowValue;
			dl.appendChild( dt );
			dl.appendChild( dd );
		} );
		tip.appendChild( dl );
	}

	function position( trigger ) {
		var tip = tipEl;
		var rect = trigger.getBoundingClientRect();

		// Measure off-screen-but-rendered (hidden=false, opacity handled by class).
		tip.hidden = false;
		var tw = tip.offsetWidth;
		var th = tip.offsetHeight;

		var sx = window.scrollX;
		var sy = window.scrollY;
		var vw = document.documentElement.clientWidth;

		var triggerCenter = rect.left + rect.width / 2;

		// Prefer above; flip below when there isn't room.
		var place = 'top';
		var top = rect.top + sy - th - GAP;
		if ( rect.top - th - GAP < 0 ) {
			place = 'bottom';
			top = rect.bottom + sy + GAP;
		}

		var left = triggerCenter + sx - tw / 2;
		var minLeft = sx + EDGE;
		var maxLeft = sx + vw - tw - EDGE;
		if ( left < minLeft ) {
			left = minLeft;
		}
		if ( left > maxLeft ) {
			left = maxLeft;
		}

		// Point the arrow at the trigger center even after horizontal clamping.
		var arrow = triggerCenter + sx - left;
		arrow = Math.max( 12, Math.min( tw - 12, arrow ) );

		tip.style.left = Math.round( left ) + 'px';
		tip.style.top = Math.round( top ) + 'px';
		tip.style.setProperty( '--pltt-tip-arrow', Math.round( arrow ) + 'px' );
		tip.setAttribute( 'data-place', place );
	}

	function show( trigger ) {
		buildContent( trigger );
		position( trigger );
		tipEl.classList.add( 'is-visible' );
		activeTrigger = trigger;
	}

	function hide() {
		clearTimeout( showTimer );
		showTimer = null;
		if ( tipEl ) {
			tipEl.classList.remove( 'is-visible' );
			tipEl.hidden = true;
		}
		activeTrigger = null;
	}

	function scheduleShow( trigger ) {
		if ( trigger === activeTrigger ) {
			return;
		}
		clearTimeout( showTimer );
		showTimer = setTimeout( function () {
			show( trigger );
		}, SHOW_DELAY );
	}

	function onPointerOver( e ) {
		var trigger = e.target.closest( '[data-pltt-tip]' );
		if ( ! trigger ) {
			return;
		}
		scheduleShow( trigger );
	}

	function onPointerOut( e ) {
		var trigger = e.target.closest( '[data-pltt-tip]' );
		if ( ! trigger ) {
			return;
		}
		// Moving within the same trigger (onto a child) shouldn't dismiss.
		if ( e.relatedTarget && trigger.contains( e.relatedTarget ) ) {
			return;
		}
		hide();
	}

	function onFocusIn( e ) {
		var trigger = e.target.closest ? e.target.closest( '[data-pltt-tip]' ) : null;
		if ( trigger ) {
			show( trigger );
		}
	}

	function onKeyDown( e ) {
		if ( 'Escape' === e.key && activeTrigger ) {
			hide();
		}
	}

	function init() {
		document.addEventListener( 'pointerover', onPointerOver );
		document.addEventListener( 'pointerout', onPointerOut );
		document.addEventListener( 'focusin', onFocusIn );
		document.addEventListener( 'focusout', hide );
		document.addEventListener( 'keydown', onKeyDown );
		// Position is static once shown, so dismiss on scroll/resize.
		window.addEventListener( 'scroll', hide, true );
		window.addEventListener( 'resize', hide );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.PlttTooltip = { hide: hide };
})();

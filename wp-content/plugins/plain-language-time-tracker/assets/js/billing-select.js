/**
 * Billing from the Insights detailed view (hourly projects).
 *
 * The entry table renders an "Include in bill" select row (.pltt-bill-select).
 * This tallies the current selection into a docked bar, opens the Record-bill
 * modal on demand, and commits the checked entries as included_entry_ids via
 * pltt_commit_billing. Selection lives on the entries; the modal carries the
 * amount, the invoice date and the copyable line items (invoicing.js drives the
 * .pltt-billcopy-* controls inside it). Committing lands on the new record.
 *
 * Depends on PLTT.ajax / plttData from shared.js.
 */
( function () {
	'use strict';

	const bar = document.querySelector( '.pltt-billsel-bar' );
	const dialog = document.getElementById( 'pltt-billsel-dialog' );
	const form = dialog ? dialog.querySelector( '.pltt-billsel-form' ) : null;
	if ( ! bar || ! dialog || ! form ) {
		return;
	}

	// Recurring "confirm the number" mode: the bar is always visible, there's no
	// per-entry selection, and the commit bills the period overage as a whole.
	const isConfirm = bar.hasAttribute( 'data-confirm' );

	// What the work came to, which the "you would absorb" readout measures the
	// typed amount against. Fixed for a retainer (it bills its period whole);
	// retallied from the ticked rows on every change for hourly.
	let calcTotal = parseFloat( form.dataset.calculated ) || 0;

	function formatCurrency( n ) {
		return '$' + Number( n ).toLocaleString( 'en-US', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		} );
	}

	function selected() {
		return Array.prototype.slice.call( document.querySelectorAll( '.pltt-bill-select:checked' ) );
	}

	function selectionTotal( boxes ) {
		let total = 0;
		boxes.forEach( function ( c ) { total += parseFloat( c.dataset.amount ) || 0; } );
		return Math.round( total * 100 ) / 100;
	}

	function selectionMinutes( boxes ) {
		let mins = 0;
		boxes.forEach( function ( c ) { mins += parseInt( c.dataset.minutes, 10 ) || 0; } );
		return mins;
	}

	// Reflect the current selection into the bar (count + total, visibility).
	function refreshBar() {
		if ( isConfirm ) {
			bar.hidden = false;
			return;
		}
		const boxes = selected();
		const total = selectionTotal( boxes );
		// The bar stays put once billing mode is on. It used to hide itself on an
		// empty selection, back when it held nothing but the tally — but it now
		// states the span being billed and carries the widen link and the way out,
		// and those have to survive unticking every row. The submit button is what
		// reflects an empty selection; the bar is not.
		bar.hidden = false;
		bar.querySelectorAll( '.pltt-billsel-count' ).forEach( function ( el ) { el.textContent = String( boxes.length ); } );
		bar.querySelectorAll( '.pltt-billsel-total' ).forEach( function ( el ) { el.textContent = formatCurrency( total ); } );
		const openBtn = bar.querySelector( '[data-open-billsel]' );
		if ( openBtn ) {
			openBtn.disabled = boxes.length === 0;
		}
	}

	// Sync the modal to the current selection when it opens.
	function syncDialog() {
		if ( isConfirm ) {
			refreshSubmitLabel();
			return;
		}
		const boxes = selected();
		const total = selectionTotal( boxes );
		calcTotal = total;
		dialog.querySelectorAll( '.pltt-billsel-count' ).forEach( function ( el ) { el.textContent = String( boxes.length ); } );
		dialog.querySelectorAll( '.pltt-billsel-calc' ).forEach( function ( el ) { el.textContent = formatCurrency( total ); } );
		// Duration of what's ticked, beside the count it belongs to. Formatted by
		// the shared helper so it matches pltt_format_duration() everywhere else.
		const dur = dialog.querySelector( '.pltt-billsel-duration' );
		if ( dur ) {
			dur.textContent = PLTT.formatDuration( selectionMinutes( boxes ) );
		}
		// No ceiling — trim down to absorb, or raise for a rounded-up invoice.
		const amount = form.querySelector( '.pltt-billsel-amount' );
		if ( amount ) {
			amount.value = total.toFixed( 2 );
		}
		// The invoice text is built server-side from the whole scope, so it keeps
		// describing rows you unticked. Say so when the two diverge rather than let
		// the modal show a live count beside prose that contradicts it.
		const stale = dialog.querySelector( '.pltt-billsel-lineitems-stale' );
		if ( stale ) {
			const all = document.querySelectorAll( '.pltt-bill-select' ).length;
			stale.hidden = boxes.length >= all;
		}
		refreshSubmitLabel();
	}

	/**
	 * The submit button's amount, plus the gap between what the work came to and
	 * what you're charging. Lower is absorption (you eat the difference); higher is
	 * a rounded-up invoice. Equal says nothing — there's no gap to name.
	 */
	function refreshSubmitLabel() {
		const amount = form.querySelector( '.pltt-billsel-amount' );
		const label = form.querySelector( '.pltt-billsel-submit-amt' );
		if ( ! amount ) {
			return;
		}
		// A plain text field now (matching project setup's adorned currency inputs),
		// so strip anything that isn't part of the number before reading it — a
		// pasted "$1,234.56" would otherwise parse as 1.
		const n = parseFloat( String( amount.value ).replace( /[^0-9.-]/g, '' ) );
		const billed = Number.isFinite( n ) ? n : 0;
		if ( label ) {
			label.textContent = formatCurrency( billed );
		}

		const note = form.querySelector( '.pltt-billsel-gap-note' );
		if ( ! note ) {
			return;
		}
		const gap = Math.round( ( calcTotal - billed ) * 100 ) / 100;
		const i18n = plttData.i18n || {};
		if ( gap > 0 ) {
			note.textContent = '· ' + ( i18n.youWouldAbsorb || 'you would absorb' ) + ' ' + formatCurrency( gap );
		} else if ( gap < 0 ) {
			note.textContent = '· ' + ( i18n.roundedUpBy || 'rounded up by' ) + ' ' + formatCurrency( -gap );
		} else {
			note.textContent = '';
		}
	}

	// --- selection changes ---
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.closest( '.pltt-bill-select' ) ) {
			refreshBar();
		}
	} );

	// --- open the modal ---
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '[data-open-billsel]' ) ) {
			return;
		}
		if ( ! isConfirm && ! selected().length ) {
			return;
		}
		syncDialog();
		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		}
	} );

	// --- close ("Cancel") ---
	document.addEventListener( 'click', function ( e ) {
		const closer = e.target.closest( '[data-close]' );
		if ( closer && closer.closest( 'dialog' ) === dialog ) {
			dialog.close();
		}
	} );

	// --- leaving billing mode (bar Cancel / Escape) ---
	// The bar's Cancel is a plain link to this view minus bill=1, so it needs no
	// JS of its own. Escape follows the same href. Committing lands here too:
	// same URL, so the just-billed entries come back as covered.
	const exitLink = bar.querySelector( '[data-exit-bill]' );

	function exitBillingUrl() {
		if ( exitLink ) {
			return exitLink.href;
		}
		const url = new URL( window.location.href );
		url.searchParams.delete( 'bill' );
		return url.toString();
	}

	// "Mid-edit" means text is being entered, not merely that an <input> has focus.
	// Ticking an "Include in bill" checkbox leaves focus on it, and that must not
	// swallow Escape — it's the most common thing to be focused when you decide to
	// back out. So: textareas and text-ish inputs block, toggles and buttons don't.
	const NON_TEXT_INPUT = /^(checkbox|radio|button|submit|reset|file|range|color)$/i;

	function isTypingTarget( el ) {
		if ( ! el ) {
			return false;
		}
		if ( el.isContentEditable || 'TEXTAREA' === el.tagName ) {
			return true;
		}
		return 'INPUT' === el.tagName && ! NON_TEXT_INPUT.test( el.type );
	}

	// Anything layered over the page owns Escape first: the Record-bill and
	// Line-items dialogs, the shared .pltt-modal editors, the date-range
	// dropdown, a chart tooltip. Those close on Escape via their own handlers
	// and don't mark the event handled, so check for them by sight. First
	// Escape dismisses the layer, a second one leaves billing mode.
	const OVERLAY_SELECTOR = 'dialog[open], .pltt-modal:not(.pltt-hidden), .pltt-date-nav-dropdown:not([hidden]), .pltt-tip:not([hidden])';

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key || e.defaultPrevented ) {
			return;
		}
		if ( document.querySelector( OVERLAY_SELECTOR ) ) {
			return;
		}
		// Mid-edit in a field (inline entry editing, the amount box): leave it alone.
		if ( isTypingTarget( e.target ) ) {
			return;
		}
		window.location.href = exitBillingUrl();
	} );

	// --- light-dismiss fallback (no `closedby` support) ---
	if ( ! ( 'closedBy' in HTMLDialogElement.prototype ) ) {
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target !== dialog ) {
				return;
			}
			const rect = dialog.getBoundingClientRect();
			const inside = rect.top <= e.clientY && e.clientY <= rect.top + rect.height
				&& rect.left <= e.clientX && e.clientX <= rect.left + rect.width;
			if ( ! inside ) {
				dialog.close();
			}
		} );
	}

	// --- live submit label as the amount is edited ---
	form.addEventListener( 'input', function ( e ) {
		if ( e.target.closest( '.pltt-billsel-amount' ) ) {
			refreshSubmitLabel();
		}
	} );

	// --- commit ---
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		const errorEl = form.querySelector( '.pltt-billing-error' );
		if ( errorEl ) {
			errorEl.hidden = true;
			errorEl.textContent = '';
		}

		const included = isConfirm ? '' : selected().map( function ( c ) { return c.dataset.entryId; } ).join( ',' );
		if ( ! isConfirm && ! included ) {
			return;
		}

		const buttons = form.querySelectorAll( 'button[type="submit"]' );
		buttons.forEach( function ( b ) { b.disabled = true; } );

		// Invoice date: blank falls back to today server-side.
		const markedAt = form.querySelector( 'input[name="marked_at"]' );

		// Normalise the amount before posting. The field is plain text, so a typed
		// or pasted "$1,234.56" would reach PHP intact and cast to 1.00 — a bill
		// silently short by three orders of magnitude.
		const rawAmount = parseFloat( String( form.querySelector( 'input[name="billed_amount"]' ).value ).replace( /[^0-9.-]/g, '' ) );

		PLTT.ajax(
			'pltt_commit_billing',
			{
				project_id: form.querySelector( 'input[name="project_id"]' ).value,
				billing_type: form.querySelector( 'input[name="billing_type"]' ).value,
				period: form.querySelector( 'input[name="period"]' ).value,
				date_from: form.querySelector( 'input[name="date_from"]' ).value,
				date_to: form.querySelector( 'input[name="date_to"]' ).value,
				billed_amount: ( Number.isFinite( rawAmount ) ? rawAmount : 0 ).toFixed( 2 ),
				marked_at: markedAt ? markedAt.value : '',
				included_entry_ids: included,
				description: form.querySelector( '[name="description"]' ).value,
			},
			function ( response ) {
				if ( response && response.success ) {
					dialog.close();
					// Land on the record just written, whose bar reopens the invoice
					// line items read-only. The line items are inside this modal now,
					// so closing it without that hop would take the invoice text off
					// screen with no way back short of hunting the billing history.
					// Falls back to simply leaving billing mode — the bill flag drops,
					// the select column goes away and the just-billed entries show as
					// covered — if the server couldn't name the record.
					const viewUrl = response.data && response.data.view_url;
					window.location.href = viewUrl ? viewUrl : exitBillingUrl();
					return;
				}
				buttons.forEach( function ( b ) { b.disabled = false; } );
				if ( errorEl ) {
					const msg = response && response.data && response.data.message
						? response.data.message
						: ( response && typeof response.data === 'string' ? response.data : plttData.i18n.error );
					errorEl.textContent = msg;
					errorEl.hidden = false;
				}
			}
		);
	} );

	// Keep the select cell in sync when an entry's billable flag is toggled inline
	// (reports.js dispatches this). Newly billable → a checked box joins the
	// selection; now non-billable → a muted "—".
	document.addEventListener( 'pltt:entry-billable-changed', function ( e ) {
		const d = e.detail || {};
		if ( ! d.entryId ) {
			return;
		}
		const row = document.querySelector( 'tr[data-entry-id="' + d.entryId + '"]' );
		const cell = row && row.querySelector( '.pltt-bill-select-col' );
		if ( ! cell ) {
			return;
		}
		if ( d.billable ) {
			const amt = ( Number( d.amount ) || 0 ).toFixed( 2 );
			const mins = parseInt( d.minutes, 10 ) || 0;
			cell.innerHTML = '<input type="checkbox" class="pltt-bill-select" checked'
				+ ' data-entry-id="' + d.entryId + '" data-amount="' + amt + '"'
				+ ' data-minutes="' + mins + '"'
				+ ' aria-label="' + ( ( plttData.i18n && plttData.i18n.includeInBill ) || 'Include this entry in the bill' ) + '">';
		} else {
			const na = ( plttData.i18n && plttData.i18n.nonBillable ) || 'Non-billable';
			cell.innerHTML = '<span class="pltt-bill-select-na" title="' + na + '" aria-label="' + na + '">—</span>';
		}
		refreshBar();
	} );

	// Pre-checked entries mean there's a selection on load.
	refreshBar();
}() );

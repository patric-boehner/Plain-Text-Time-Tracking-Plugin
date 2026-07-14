/**
 * Billing from the Insights detailed view (hourly projects).
 *
 * The entry table renders an "Include in bill" select row (.pltt-bill-select).
 * This tallies the current selection into a docked bar, opens the Record-bill
 * modal on demand, and commits the checked entries as included_entry_ids via
 * pltt_commit_billing. Selection lives on the entries; the modal is commit-only.
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

	// Reflect the current selection into the bar (count + total, visibility).
	function refreshBar() {
		const boxes = selected();
		const total = selectionTotal( boxes );
		bar.hidden = boxes.length === 0;
		bar.querySelectorAll( '.pltt-billsel-count' ).forEach( function ( el ) { el.textContent = String( boxes.length ); } );
		bar.querySelectorAll( '.pltt-billsel-total' ).forEach( function ( el ) { el.textContent = formatCurrency( total ); } );
	}

	// Sync the modal to the current selection when it opens.
	function syncDialog() {
		const boxes = selected();
		const total = selectionTotal( boxes );
		dialog.querySelectorAll( '.pltt-billsel-count' ).forEach( function ( el ) { el.textContent = String( boxes.length ); } );
		dialog.querySelectorAll( '.pltt-billsel-calc' ).forEach( function ( el ) { el.textContent = formatCurrency( total ); } );
		const amount = form.querySelector( '.pltt-billsel-amount' );
		if ( amount ) {
			amount.max = total.toFixed( 2 );
			amount.value = total.toFixed( 2 );
		}
		refreshSubmitLabel();
	}

	function refreshSubmitLabel() {
		const amount = form.querySelector( '.pltt-billsel-amount' );
		const label = form.querySelector( '.pltt-billsel-submit-amt' );
		if ( amount && label ) {
			const n = parseFloat( amount.value );
			label.textContent = formatCurrency( Number.isFinite( n ) ? n : 0 );
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
		if ( ! selected().length ) {
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

		const included = selected().map( function ( c ) { return c.dataset.entryId; } ).join( ',' );
		if ( ! included ) {
			return;
		}

		const buttons = form.querySelectorAll( 'button[type="submit"]' );
		buttons.forEach( function ( b ) { b.disabled = true; } );

		PLTT.ajax(
			'pltt_commit_billing',
			{
				project_id: form.querySelector( 'input[name="project_id"]' ).value,
				billing_type: form.querySelector( 'input[name="billing_type"]' ).value,
				period: form.querySelector( 'input[name="period"]' ).value,
				date_from: form.querySelector( 'input[name="date_from"]' ).value,
				date_to: form.querySelector( 'input[name="date_to"]' ).value,
				billed_amount: form.querySelector( 'input[name="billed_amount"]' ).value,
				included_entry_ids: included,
				description: form.querySelector( '[name="description"]' ).value,
			},
			function ( response ) {
				if ( response && response.success ) {
					dialog.close();
					// Exit billing mode: drop the bill flag so the select column goes
					// away and the just-billed entries show as covered in the normal view.
					const url = new URL( window.location.href );
					url.searchParams.delete( 'bill' );
					window.location.href = url.toString();
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
			cell.innerHTML = '<input type="checkbox" class="pltt-bill-select" checked'
				+ ' data-entry-id="' + d.entryId + '" data-amount="' + amt + '"'
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

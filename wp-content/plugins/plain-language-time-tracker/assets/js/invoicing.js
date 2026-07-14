/**
 * Billing queue — inline entry selection + two-modal commit.
 *
 * Each scope row expands to show its entries. Hourly scopes get checkboxes:
 * ticking/unticking recomputes the row's "Selected (N) $X" line live and syncs
 * the Record-bill modal's amount. Two buttons sit under the selection:
 *   - "Line items…"  → a copy-only dialog (default / structured + AI prompt).
 *   - "Record bill →" → the commit modal (amount + description), which posts
 *     pltt_commit_billing with the row's unchecked entries as excluded_entry_ids.
 * On success the settled scope is removed and totals re-tally in place.
 * Depends on PLTT.ajax / plttData from shared.js.
 */
( function () {
	'use strict';

	// Activate where there's something to drive: a commit form (the detailed-view
	// scope panel) or a copy dialog (the Billing ledger's "View line items"). The
	// handlers are all delegated and target-checked, so binding is otherwise inert.
	if ( ! document.querySelector( '.pltt-billing-form' ) && ! document.querySelector( '[data-lineitems-dialog]' ) ) {
		return;
	}

	function formatCurrency( n ) {
		return '$' + Number( n ).toLocaleString( 'en-US', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		} );
	}

	/**
	 * Re-tally client + grand totals from the rows still in the DOM. Row
	 * data-amount stays the full outstanding scope total (selection doesn't change
	 * what's outstanding, only what goes on this invoice), so the header reflects
	 * everything still billable. Reloads to the empty state if the queue clears.
	 */
	function recompute() {
		if ( ! document.querySelectorAll( '.pltt-invoicing-item' ).length ) {
			window.location.reload();
			return;
		}

		let grand = 0;

		document.querySelectorAll( '.pltt-invoicing-client' ).forEach( function ( card ) {
			const clientItems = card.querySelectorAll( '.pltt-invoicing-item' );
			if ( ! clientItems.length ) {
				card.remove();
				return;
			}
			let clientTotal = 0;
			clientItems.forEach( function ( item ) {
				clientTotal += parseFloat( item.dataset.amount ) || 0;
			} );
			grand += clientTotal;
			const totalEl = card.querySelector( '.pltt-invoicing-client-total' );
			if ( totalEl ) {
				totalEl.textContent = formatCurrency( clientTotal );
			}
		} );

		const grandEl = document.querySelector( '.pltt-inv-grand' );
		if ( grandEl ) {
			grandEl.textContent = formatCurrency( grand );
		}
		const itemsEl = document.querySelector( '.pltt-inv-items' );
		if ( itemsEl ) {
			itemsEl.textContent = String( document.querySelectorAll( '.pltt-invoicing-item' ).length );
		}
		const clientsEl = document.querySelector( '.pltt-inv-clients' );
		if ( clientsEl ) {
			clientsEl.textContent = String( document.querySelectorAll( '.pltt-invoicing-client' ).length );
		}
	}

	/**
	 * Remove a settled scope: its row plus both of its dialogs.
	 *
	 * @param {string} uid Scope uid.
	 */
	function removeScope( uid ) {
		const item = document.querySelector( '.pltt-invoicing-item[data-scope="' + uid + '"]' );
		if ( item ) {
			item.remove();
		}
		[ 'pltt-bill-' + uid, 'pltt-billcopy-' + uid ].forEach( function ( id ) {
			const d = document.getElementById( id );
			if ( d ) {
				d.remove();
			}
		} );
	}

	/**
	 * Recompute a row's selection from its entry checkboxes, updating the panel's
	 * "Selected (N) $X" line and syncing the Record-bill modal's amount + label.
	 * No-ops for retainer scopes (no per-entry checkboxes).
	 *
	 * @param {Element} item The .pltt-invoicing-item row.
	 */
	function recomputeRowSelection( item ) {
		const checks = item.querySelectorAll( '.pltt-bill-entry' );
		if ( ! checks.length ) {
			return;
		}
		let total = 0;
		let count = 0;
		checks.forEach( function ( c ) {
			if ( c.checked ) {
				total += parseFloat( c.dataset.amount ) || 0;
				count += 1;
			}
		} );
		total = Math.round( total * 100 ) / 100;

		const cntEl = item.querySelector( '.pltt-inv-sel-count' );
		if ( cntEl ) {
			cntEl.textContent = String( count );
		}
		const totEl = item.querySelector( '.pltt-inv-sel-total' );
		if ( totEl ) {
			totEl.textContent = formatCurrency( total );
		}

		// Keep "Select all" in sync with the row.
		const allBox = item.querySelector( '.pltt-inv-selectall-box' );
		if ( allBox ) {
			allBox.checked = Array.prototype.every.call( checks, function ( c ) { return c.checked; } );
		}

		// Sync the Record-bill modal: the amount ceiling + value follow the
		// selected total (trim further inside the modal to absorb).
		const dialog = document.getElementById( 'pltt-bill-' + item.dataset.scope );
		if ( dialog ) {
			const input = dialog.querySelector( '.pltt-billing-amount-input' );
			if ( input ) {
				input.max = total.toFixed( 2 );
				input.value = total.toFixed( 2 );
			}
			const amountEl = dialog.querySelector( '.pltt-bill-amount' );
			if ( amountEl ) {
				amountEl.textContent = formatCurrency( total );
			}
		}
	}

	// --- Expand / collapse a row (the whole row is the toggle) ---
	document.addEventListener( 'click', function ( e ) {
		const toggle = e.target.closest( '.pltt-invoicing-toggle' );
		if ( ! toggle ) {
			return;
		}
		const controls = document.getElementById( toggle.getAttribute( 'aria-controls' ) );
		const open = toggle.getAttribute( 'aria-expanded' ) === 'true';
		toggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
		if ( controls ) {
			controls.hidden = open;
		}
	} );

	// --- Open the Record-bill modal (sync its amount from the row first) ---
	document.addEventListener( 'click', function ( e ) {
		const opener = e.target.closest( '[data-bill-dialog]' );
		if ( ! opener ) {
			return;
		}
		const item = opener.closest( '.pltt-invoicing-item' );
		if ( item ) {
			recomputeRowSelection( item );
		}
		const dialog = document.getElementById( opener.getAttribute( 'data-bill-dialog' ) );
		if ( dialog && typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		}
	} );

	// --- Open the Line-items (copy) modal ---
	document.addEventListener( 'click', function ( e ) {
		const opener = e.target.closest( '[data-lineitems-dialog]' );
		if ( ! opener ) {
			return;
		}
		const dialog = document.getElementById( opener.getAttribute( 'data-lineitems-dialog' ) );
		if ( dialog && typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		}
	} );

	// --- Close ("Cancel" / "Done") ---
	document.addEventListener( 'click', function ( e ) {
		const closer = e.target.closest( '[data-close]' );
		if ( ! closer ) {
			return;
		}
		const dialog = closer.closest( 'dialog' );
		if ( dialog ) {
			dialog.close();
		}
	} );

	// --- Light-dismiss fallback (Safari / no `closedby` support) ---
	if ( ! ( 'closedBy' in HTMLDialogElement.prototype ) ) {
		document.addEventListener( 'click', function ( e ) {
			const dialog = e.target;
			if ( dialog.tagName !== 'DIALOG'
				|| ! ( dialog.classList.contains( 'pltt-bill-dialog' ) || dialog.classList.contains( 'pltt-billcopy-dialog' ) ) ) {
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

	// --- Inline entry selection: recompute the row as boxes toggle ---
	document.addEventListener( 'change', function ( e ) {
		const check = e.target.closest( '.pltt-bill-entry' );
		if ( ! check ) {
			return;
		}
		const item = check.closest( '.pltt-invoicing-item' );
		if ( item ) {
			recomputeRowSelection( item );
		}
	} );

	// --- "Select all" toggles every entry in the row ---
	document.addEventListener( 'change', function ( e ) {
		const all = e.target.closest( '.pltt-inv-selectall-box' );
		if ( ! all ) {
			return;
		}
		const item = all.closest( '.pltt-invoicing-item' );
		if ( ! item ) {
			return;
		}
		item.querySelectorAll( '.pltt-bill-entry' ).forEach( function ( c ) { c.checked = all.checked; } );
		recomputeRowSelection( item );
	} );

	// --- Line-items dialog: swap the copyable text to the chosen source ---
	document.addEventListener( 'change', function ( e ) {
		const select = e.target.closest( '.pltt-billcopy-mode' );
		if ( ! select ) {
			return;
		}
		const dialog = select.closest( 'dialog' );
		const textarea = dialog && dialog.querySelector( '.pltt-billcopy-text' );
		const option = select.options[ select.selectedIndex ];
		if ( textarea && option ) {
			textarea.value = option.dataset.text || '';
		}
	} );

	// --- Line-items dialog: copy to clipboard ---
	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.pltt-billcopy-copy' );
		if ( ! btn ) {
			return;
		}
		const dialog = btn.closest( 'dialog' );
		const textarea = dialog && dialog.querySelector( '.pltt-billcopy-text' );
		if ( ! textarea ) {
			return;
		}
		const label = btn.querySelector( '.pltt-billcopy-copy-label' ) || btn;
		const done = function () {
			const original = label.textContent;
			label.textContent = ( plttData.i18n && plttData.i18n.copied ) || 'Copied';
			setTimeout( function () { label.textContent = original; }, 1200 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( textarea.value ).then( done, function () { textarea.select(); } );
		} else {
			textarea.select();
			try { document.execCommand( 'copy' ); done(); } catch ( err ) { /* no-op */ }
		}
	} );

	// --- Live "Record bill $X" label as the modal amount is edited ---
	document.addEventListener( 'input', function ( e ) {
		const input = e.target.closest( '.pltt-billing-amount-input' );
		if ( ! input ) {
			return;
		}
		const dialog = input.closest( 'dialog' );
		const amountEl = dialog && dialog.querySelector( '.pltt-bill-amount' );
		if ( amountEl ) {
			const n = parseFloat( input.value );
			amountEl.textContent = formatCurrency( Number.isFinite( n ) ? n : 0 );
		}
	} );

	// --- Commit (Record bill) ---
	document.addEventListener( 'submit', function ( e ) {
		const form = e.target.closest( '.pltt-billing-form' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();

		const dialog = form.closest( 'dialog' );
		const uid = form.dataset.scope;
		const errorEl = form.querySelector( '.pltt-billing-error' );

		if ( errorEl ) {
			errorEl.hidden = true;
			errorEl.textContent = '';
		}

		const buttons = form.querySelectorAll( 'button[type="submit"]' );
		buttons.forEach( function ( b ) { b.disabled = true; } );

		// Excluded entries come from the row's unchecked boxes (selection is inline now).
		const row = document.querySelector( '.pltt-invoicing-item[data-scope="' + uid + '"]' );
		const excluded = row
			? Array.prototype.slice.call( row.querySelectorAll( '.pltt-bill-entry' ) )
				.filter( function ( c ) { return ! c.checked; } )
				.map( function ( c ) { return c.dataset.entryId; } )
				.join( ',' )
			: '';

		PLTT.ajax(
			'pltt_commit_billing',
			{
				project_id: form.querySelector( 'input[name="project_id"]' ).value,
				billing_type: form.querySelector( 'input[name="billing_type"]' ).value,
				period: form.querySelector( 'input[name="period"]' ).value,
				date_from: form.querySelector( 'input[name="date_from"]' ).value,
				date_to: form.querySelector( 'input[name="date_to"]' ).value,
				billed_amount: form.querySelector( 'input[name="billed_amount"]' ).value,
				excluded_entry_ids: excluded,
				description: form.querySelector( 'textarea[name="description"]' ).value,
			},
			function ( response ) {
				if ( response && response.success ) {
					if ( dialog ) {
						dialog.close();
					}
					removeScope( uid );
					recompute();
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
}() );

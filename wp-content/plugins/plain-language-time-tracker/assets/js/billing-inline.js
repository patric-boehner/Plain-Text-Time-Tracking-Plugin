/**
 * Inline billing (Reports single-project detailed view).
 *
 * "Review & bill" reveals the settings panel in place — no navigation, no screen
 * change. The entry rows already on the page gain checkboxes (hourly); the panel
 * shows the live Computed / Invoice amount / Absorbed figures and a Copy line
 * items dialog. Finalize commits via pltt_commit_billing and refreshes the same
 * filtered view, where the billed rows now read as invoiced. Depends on
 * PLTT.ajax / plttData from shared.js.
 */
( function () {
	'use strict';

	const wrap = document.querySelector( '.pltt-wrap' );
	const content = document.getElementById( 'pltt-report-content' );
	// Bind whenever the toggle is present — the panel may be absent when nothing
	// billable is in the current view (all outstanding sits outside the range).
	if ( ! wrap || ! content || ! document.querySelector( '[data-billing-toggle]' ) ) {
		return;
	}

	/**
	 * Format a number as currency, matching pltt_format_currency() ($1,234.56).
	 *
	 * @param {number} n Amount.
	 * @return {string}
	 */
	function formatCurrency( n ) {
		return '$' + Number( n ).toLocaleString( 'en-US', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		} );
	}

	/**
	 * The selection checkboxes a panel bills from. Hourly panels drive off the
	 * boxes on the visible entry rows; retainer panels have none.
	 *
	 * @param {HTMLElement} panel .pltt-billing-panel
	 * @return {HTMLInputElement[]}
	 */
	function boxesFor( panel ) {
		if ( panel.dataset.selectable !== '1' ) {
			return [];
		}
		return Array.prototype.slice.call( content.querySelectorAll( '.pltt-bill-select' ) );
	}

	/**
	 * A panel's computed total: sum of checked entries (hourly), or the fixed
	 * scope figure (retainer).
	 *
	 * @param {HTMLElement} panel .pltt-billing-panel
	 * @return {number}
	 */
	function computedFor( panel ) {
		const boxes = boxesFor( panel );
		if ( ! boxes.length ) {
			return parseFloat( panel.dataset.computed ) || 0;
		}
		let total = 0;
		boxes.forEach( function ( b ) {
			if ( b.checked ) {
				total += parseFloat( b.dataset.amount ) || 0;
			}
		} );
		return Math.round( total * 100 ) / 100;
	}

	/**
	 * Recompute Absorbed = Computed − Invoice amount.
	 *
	 * @param {HTMLElement} panel .pltt-billing-panel
	 */
	function syncAbsorbed( panel ) {
		const computed = computedFor( panel );
		const input = panel.querySelector( '.pltt-bill-amount-input' );
		const absorbedEl = panel.querySelector( '.pltt-bill-absorbed' );
		let amount = input ? parseFloat( input.value ) : computed;
		if ( ! Number.isFinite( amount ) || amount < 0 ) {
			amount = 0;
		}
		if ( amount > computed ) {
			amount = computed;
		}
		if ( absorbedEl ) {
			absorbedEl.textContent = formatCurrency( Math.round( ( computed - amount ) * 100 ) / 100 );
		}
	}

	/**
	 * Recompute everything driven by the selection: Computed, the "N of M" count,
	 * and the amount ceiling/value (reset to the new total), then Absorbed.
	 *
	 * @param {HTMLElement} panel .pltt-billing-panel
	 */
	function syncPanel( panel ) {
		const computed = computedFor( panel );
		const computedEl = panel.querySelector( '.pltt-bill-computed' );
		if ( computedEl ) {
			computedEl.textContent = formatCurrency( computed );
		}

		const boxes = boxesFor( panel );
		const countEl = panel.querySelector( '.pltt-bill-computed-count' );
		if ( countEl && boxes.length ) {
			let checked = 0;
			boxes.forEach( function ( b ) { if ( b.checked ) { checked++; } } );
			countEl.textContent = checked + ' of ' + boxes.length;
		}

		const input = panel.querySelector( '.pltt-bill-amount-input' );
		if ( input ) {
			input.max = computed.toFixed( 2 );
			input.value = computed.toFixed( 2 );
		}
		syncAbsorbed( panel );
	}

	/** Show a transient nudge when there's nothing billable in the current view. */
	function showNoBillable() {
		let note = content.querySelector( '.pltt-billing-empty-note' );
		if ( ! note ) {
			note = document.createElement( 'div' );
			note.className = 'pltt-billing-empty-note';
			note.textContent = ( plttData.i18n && plttData.i18n.noBillableInView )
				|| 'No billable entries in the current view. Widen your date range to bill outstanding time.';
			content.insertBefore( note, content.firstChild );
		}
		note.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	/** Reveal the billing panel(s) + checkboxes in place. */
	function enterBilling() {
		const panels = document.querySelectorAll( '.pltt-billing-panel' );
		if ( ! panels.length ) {
			showNoBillable();
			return;
		}
		wrap.classList.add( 'pltt-billing-active' );
		panels.forEach( function ( panel ) {
			panel.hidden = false;
			syncPanel( panel );
		} );
		panels[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	/** Hide the panel(s) and leave billing mode — nothing committed. */
	function exitBilling() {
		wrap.classList.remove( 'pltt-billing-active' );
		document.querySelectorAll( '.pltt-billing-panel' ).forEach( function ( panel ) {
			panel.hidden = true;
		} );
		const note = content.querySelector( '.pltt-billing-empty-note' );
		if ( note ) {
			note.remove();
		}
	}

	// --- Toggle: "Review & bill" (context card) enters; "Cancel" exits ---
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-billing-toggle]' ) ) {
			if ( wrap.classList.contains( 'pltt-billing-active' ) ) {
				exitBilling();
			} else {
				enterBilling();
			}
			return;
		}
		if ( e.target.closest( '.pltt-bill-cancel' ) ) {
			exitBilling();
		}
	} );

	// --- Check all / Uncheck all ---
	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.pltt-bill-check-all, .pltt-bill-uncheck-all' );
		if ( ! btn ) {
			return;
		}
		const panel = btn.closest( '.pltt-billing-panel' );
		const check = btn.classList.contains( 'pltt-bill-check-all' );
		boxesFor( panel ).forEach( function ( b ) { b.checked = check; } );
		syncPanel( panel );
	} );

	// --- A single entry toggles ---
	document.addEventListener( 'change', function ( e ) {
		if ( ! e.target.closest( '.pltt-bill-select' ) ) {
			return;
		}
		document.querySelectorAll( '.pltt-billing-panel' ).forEach( function ( panel ) {
			if ( panel.dataset.selectable === '1' ) {
				syncPanel( panel );
			}
		} );
	} );

	// --- Absorption: lower the amount below computed ---
	document.addEventListener( 'input', function ( e ) {
		const input = e.target.closest( '.pltt-bill-amount-input' );
		if ( ! input ) {
			return;
		}
		const panel = input.closest( '.pltt-billing-panel' );
		if ( panel ) {
			syncAbsorbed( panel );
		}
	} );

	// --- Copy line items dialog: open ---
	document.addEventListener( 'click', function ( e ) {
		const opener = e.target.closest( '.pltt-bill-copy-open' );
		if ( ! opener ) {
			return;
		}
		const dialog = document.getElementById( opener.getAttribute( 'data-copy-dialog' ) );
		if ( dialog && typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		}
	} );

	// --- Copy dialog: close ---
	document.addEventListener( 'click', function ( e ) {
		const closer = e.target.closest( '.pltt-billcopy-dialog [data-close]' );
		if ( ! closer ) {
			return;
		}
		const dialog = closer.closest( 'dialog' );
		if ( dialog ) {
			dialog.close();
		}
	} );

	// --- Copy dialog: source dropdown swaps the textarea ---
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

	// --- Copy dialog: copy to clipboard ---
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
			navigator.clipboard.writeText( textarea.value ).then( done, function () {
				textarea.select();
			} );
		} else {
			textarea.select();
			try { document.execCommand( 'copy' ); done(); } catch ( err ) { /* no-op */ }
		}
	} );

	// --- Finalize record (commit) ---
	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.pltt-bill-finalize' );
		if ( ! btn ) {
			return;
		}
		const panel = btn.closest( '.pltt-billing-panel' );
		if ( ! panel ) {
			return;
		}

		const errorEl = panel.querySelector( '.pltt-bill-error' );
		if ( errorEl ) {
			errorEl.hidden = true;
			errorEl.textContent = '';
		}

		const amountInput = panel.querySelector( '.pltt-bill-amount-input' );
		const descInput = panel.querySelector( '.pltt-bill-desc-input' );
		const included = boxesFor( panel )
			.filter( function ( b ) { return b.checked; } )
			.map( function ( b ) { return b.dataset.entryId; } )
			.join( ',' );

		if ( panel.dataset.selectable === '1' && ! included ) {
			if ( errorEl ) {
				errorEl.textContent = ( plttData.i18n && plttData.i18n.selectEntry ) || 'Select at least one entry to bill.';
				errorEl.hidden = false;
			}
			return;
		}

		btn.disabled = true;

		PLTT.ajax(
			'pltt_commit_billing',
			{
				project_id: panel.dataset.projectId,
				billing_type: panel.dataset.billingType,
				period: panel.dataset.period || '',
				billed_amount: amountInput ? amountInput.value : ( panel.dataset.computed || '0' ),
				included_entry_ids: included,
				description: descInput ? descInput.value : '',
			},
			function ( response ) {
				if ( response && response.success ) {
					// Refresh the same filtered view; the billed rows now read as
					// invoiced — confirmation without leaving the screen.
					window.location.reload();
					return;
				}
				btn.disabled = false;
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

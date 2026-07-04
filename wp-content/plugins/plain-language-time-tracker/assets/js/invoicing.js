/**
 * Invoicing page — inline entry expansion + commit-in-a-modal.
 *
 * Each scope row can expand to show its period's entries; Review & bill opens a
 * native <dialog> with the invoice options and commits via AJAX
 * (pltt_commit_billing), removing the settled row and updating totals in place —
 * no page navigation. Depends on PLTT.ajax / plttData from shared.js.
 */
( function () {
	'use strict';

	// Run wherever the billing modal is present: the Invoicing queue and the
	// Reports single-project card both render .pltt-billing-form dialogs.
	if ( ! document.querySelector( '.pltt-billing-form' ) ) {
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
	 * Recompute every total from the rows still in the DOM (source of truth =
	 * each row's data-amount). Removes emptied client cards; reloads to show the
	 * proper empty state if the whole queue clears.
	 */
	function recompute() {
		const items = document.querySelectorAll( '.pltt-invoicing-item' );
		if ( ! items.length ) {
			window.location.reload();
			return;
		}

		let grand = 0;

		document.querySelectorAll( '.pltt-invoicing-client' ).forEach( function ( card ) {
			let clientTotal = 0;
			const clientItems = card.querySelectorAll( '.pltt-invoicing-item' );

			if ( ! clientItems.length ) {
				card.remove();
				return;
			}

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
	 * Remove a settled scope (its item — toggle + panel — and its dialog).
	 *
	 * @param {string} uid Scope uid.
	 */
	function removeScope( uid ) {
		const item = document.querySelector( '.pltt-invoicing-item[data-scope="' + uid + '"]' );
		if ( item ) {
			item.remove();
		}
		const dialog = document.getElementById( 'pltt-bill-' + uid );
		if ( dialog ) {
			dialog.remove();
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

	// --- Open the billing dialog ---
	document.addEventListener( 'click', function ( e ) {
		const opener = e.target.closest( '[data-bill-dialog]' );
		if ( ! opener ) {
			return;
		}
		const dialog = document.getElementById( opener.getAttribute( 'data-bill-dialog' ) );
		if ( dialog && typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		}
	} );

	// --- Close ("Leave open") ---
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
			if ( dialog.tagName !== 'DIALOG' || ! dialog.classList.contains( 'pltt-bill-dialog' ) ) {
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

	// --- Description source dropdown: swap the textarea to the chosen text ---
	document.addEventListener( 'change', function ( e ) {
		const select = e.target.closest( '.pltt-billing-desc-mode' );
		if ( ! select ) {
			return;
		}
		const form = select.closest( '.pltt-billing-form' );
		const textarea = form && form.querySelector( 'textarea[name="description"]' );
		const option = select.options[ select.selectedIndex ];
		if ( textarea && option ) {
			textarea.value = option.dataset.text || '';
		}
	} );

	// --- Copy the description (e.g. the AI prompt) to the clipboard ---
	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.pltt-billing-copy' );
		if ( ! btn ) {
			return;
		}
		const form = btn.closest( '.pltt-billing-form' );
		const textarea = form && form.querySelector( 'textarea[name="description"]' );
		if ( ! textarea ) {
			return;
		}
		const label = btn.querySelector( '.pltt-copy-label' ) || btn;
		const done = function () {
			const original = label.textContent;
			label.textContent = plttData.i18n.copied || 'Copied';
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

	// --- Live "Bill $X" label as the amount changes ---
	document.addEventListener( 'input', function ( e ) {
		const input = e.target.closest( '.pltt-billing-amount-input' );
		if ( ! input ) {
			return;
		}
		const form = input.closest( '.pltt-billing-form' );
		const amountEl = form && form.querySelector( '.pltt-bill-amount' );
		if ( amountEl ) {
			const n = parseFloat( input.value );
			amountEl.textContent = formatCurrency( Number.isFinite( n ) ? n : 0 );
		}
	} );

	// --- Manifest exclusion: recompute the includable total as entries toggle ---
	function recomputeManifest( form ) {
		const checks = form.querySelectorAll( '.pltt-bill-entry' );
		if ( ! checks.length ) {
			return;
		}
		let total = 0;
		checks.forEach( function ( c ) {
			if ( c.checked ) {
				total += parseFloat( c.dataset.amount ) || 0;
			}
		} );
		total = Math.round( total * 100 ) / 100;
		const input = form.querySelector( '.pltt-billing-amount-input' );
		if ( input ) {
			// Excluding an entry lowers the ceiling and resets the amount to the new
			// includable total; trim further afterwards for absorption.
			input.max = total.toFixed( 2 );
			input.value = total.toFixed( 2 );
		}
		const amountEl = form.querySelector( '.pltt-bill-amount' );
		if ( amountEl ) {
			amountEl.textContent = formatCurrency( total );
		}
	}

	document.addEventListener( 'change', function ( e ) {
		const check = e.target.closest( '.pltt-bill-entry' );
		if ( ! check ) {
			return;
		}
		const form = check.closest( '.pltt-billing-form' );
		if ( form ) {
			recomputeManifest( form );
		}
	} );

	// --- Commit (Bill) ---
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

		// Disable the action buttons while in flight.
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
				excluded_entry_ids: Array.prototype.slice.call( form.querySelectorAll( '.pltt-bill-entry' ) )
					.filter( function ( c ) { return ! c.checked; } )
					.map( function ( c ) { return c.dataset.entryId; } )
					.join( ',' ),
				description: form.querySelector( 'textarea[name="description"]' ).value,
			},
			function ( response ) {
				if ( response && response.success ) {
					if ( dialog ) {
						dialog.close();
					}
					// Queue: drop the settled scope and re-tally in place. Elsewhere
					// (the Reports single-project card) there's no queue to re-tally —
					// reload so the card reflects the new history + outstanding.
					if ( document.querySelector( '.pltt-invoicing-client' ) ) {
						removeScope( uid );
						recompute();
					} else {
						window.location.reload();
					}
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

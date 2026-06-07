/**
 * Project detail page (report only) — notice cleanup + "Where the time went"
 * group-by switching. Settings are edited via the modal on the Projects list.
 *
 * Vanilla JS, no dependencies.
 */
(function () {
	'use strict';

	/* --------------------------------------------------------------------- */
	/* Notice query-param cleanup                                            */
	/* --------------------------------------------------------------------- */
	function cleanNoticeParams() {
		if (
			window.location.search.indexOf('pltt_message') === -1 &&
			window.location.search.indexOf('pltt_error') === -1
		) {
			return;
		}
		var url = new URL(window.location.href);
		url.searchParams.delete('pltt_message');
		url.searchParams.delete('pltt_error');
		url.searchParams.delete('pltt_error_message');
		window.history.replaceState({}, '', url.toString());
	}

	/* --------------------------------------------------------------------- */
	/* "Where the time went" / timeline group-by switch                      */
	/* --------------------------------------------------------------------- */
	function initGroupBy() {
		var control = document.querySelector('.pltt-groupby');
		if (!control) {
			return;
		}
		var buttons = Array.prototype.slice.call(control.querySelectorAll('.pltt-groupby-btn'));
		// The same control switches both the bars and the timeline tracks.
		var groups = Array.prototype.slice.call(
			document.querySelectorAll('.pltt-bars-group, .pltt-timeline-group')
		);

		control.addEventListener('click', function (e) {
			var btn = e.target.closest('.pltt-groupby-btn');
			if (!btn) {
				return;
			}
			var target = btn.getAttribute('data-group-target');
			buttons.forEach(function (b) {
				var on = b === btn;
				b.classList.toggle('button-primary', on);
				b.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
			groups.forEach(function (g) {
				if (g.getAttribute('data-group') === target) {
					g.removeAttribute('hidden');
				} else {
					g.setAttribute('hidden', '');
				}
			});
		});
	}

	/* --------------------------------------------------------------------- */
	function init() {
		cleanNoticeParams();
		initGroupBy();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

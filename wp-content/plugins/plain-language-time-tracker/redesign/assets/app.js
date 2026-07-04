/* Reimagined PLTT concept — tiny interaction layer for the static demos.
   Nothing here talks to the plugin; it only makes the wireframes feel alive:
   live-parse on Today, prediction-chip confirm/cycle, segmented toggles. */

(function () {
  'use strict';

  /* ---- Inject the faux WP-admin chrome so every page shares one shell ---- */
  var NAV = [
    { key: 'today',    ico: '☀️', label: 'Today',    href: 'today.html' },
    { key: 'insights', ico: '📊', label: 'Insights', href: 'insights.html' },
    { key: 'billing',  ico: '🧾', label: 'Billing',  href: 'billing.html', tag: '3' },
    { key: 'setup',    ico: '⚙️', label: 'Setup',    href: 'setup.html',
      sub: [
        { label: 'Clients',  href: 'setup.html' },
        { label: 'Projects', href: 'setup.html#projects' },
        { label: 'Tags',     href: 'setup.html#tags' }
      ] }
  ];

  var page = document.body.getAttribute('data-page');
  if (page && !document.querySelector('.wpshell')) {
    var main = document.querySelector('main.wpmain');
    var navHtml = NAV.map(function (n) {
      var cur = n.key === page ? ' class="current"' : '';
      var tag = n.tag ? '<span class="tag">' + n.tag + '</span>' : '';
      var sub = '';
      if (n.sub && n.key === page) {
        sub = '<ul class="sub">' + n.sub.map(function (s, i) {
          return '<li><a class="' + (i === 0 ? 'on' : '') + '" href="' + s.href + '">' + s.label + '</a></li>';
        }).join('') + '</ul>';
      }
      return '<li' + cur + '><a href="' + n.href + '"><span class="ico">' + n.ico + '</span>' + n.label + tag + '</a>' + sub + '</li>';
    }).join('');

    var shell = document.createElement('div');
    shell.className = 'wpshell';
    shell.innerHTML =
      '<div class="wpbrand-mini"></div>' +
      '<div class="wpbar"><span>Patrick’s Studio</span><span class="spacer"></span>' +
        '<a href="index.html">↩ Concept overview</a><span>Howdy, Patrick ▾</span></div>' +
      '<nav class="wpsidebar"><div class="brand"><span class="dot">◷</span> Time Tracker</div>' +
        '<ul class="wpnav">' + navHtml + '</ul></nav>';
    document.body.insertBefore(shell, main);
    shell.appendChild(main); // move main into the grid area
  }

  /* ---- Prediction chips: click to confirm a guess, click caret to cycle ---- */
  document.addEventListener('click', function (e) {
    var chip = e.target.closest('.pchip');
    if (chip && !chip.classList.contains('confirmed') && !e.target.closest('.x')) {
      chip.classList.add('confirmed');
      var spark = chip.querySelector('.spark');
      if (spark) spark.textContent = '✓';
      var row = chip.closest('.tl-entry');
      if (row) row.classList.remove('needs');
      refreshFinalizeState();
    }
    // segmented controls that are pure demo toggles
    var seg = e.target.closest('[data-toggle] a, [data-toggle] button');
    if (seg) {
      e.preventDefault();
      seg.parentElement.querySelectorAll('a,button').forEach(function (b) { b.classList.remove('on'); });
      seg.classList.add('on');
    }
  });

  /* ---- Billing queue: expand a project row in place, live totals ---- */
  document.addEventListener('click', function (e) {
    var tog = e.target.closest('[data-bill-toggle]');
    if (tog) {
      // don't toggle when clicking the primary/secondary button label itself is fine — still toggles
      var item = tog.closest('.bill-item');
      item.classList.toggle('open');
      var btn = tog.querySelector('.bill-cta button');
      if (btn && item.classList.contains('open') && /Review/.test(btn.textContent)) {
        btn.textContent = 'Collapse ▲';
        btn.classList.remove('btn-primary'); btn.classList.add('btn-ghost');
      }
    }
  });

  function money(n) { return '$' + n.toLocaleString('en-US'); }

  /* ---- Dialogs: Record-the-bill (Modal 1) + Invoice line-items (Modal 2) ---- */
  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-open-dialog]');
    if (opener) {
      e.preventDefault();
      var dlg = document.getElementById('dlg-' + opener.getAttribute('data-open-dialog'));
      if (!dlg) return;
      // carry the expanded row's live total into the record dialog
      var item = opener.closest('.bill-item');
      if (item) {
        var amt = (item.querySelector('[data-rowamt]') || item.querySelector('.bill-amt')).textContent;
        var name = item.querySelector('.bill-title').textContent;
        dlg.querySelectorAll('[data-fill-amt]').forEach(function (el) {
          if (el.tagName === 'INPUT') el.value = amt.replace('$', '') + (/\./.test(amt) ? '' : '.00'); else el.textContent = amt;
        });
        var nm = dlg.querySelector('[data-fill-name]'); if (nm) nm.textContent = name;
      }
      if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
    }
    var closer = e.target.closest('[data-close-dialog]');
    if (closer) { e.preventDefault(); var d = closer.closest('dialog'); if (d) d.close(); }
  });

  document.addEventListener('change', function (e) {
    var panel = e.target.closest('.bill-panel');
    if (!panel) return;

    // "select all" toggles every entry checkbox in the panel
    if (e.target.matches('[data-all]')) {
      panel.querySelectorAll('.bill-chk input').forEach(function (c) { c.checked = e.target.checked; });
    }
    recomputeBill(panel);
  });

  function recomputeBill(panel) {
    var boxes = panel.querySelectorAll('.bill-chk input[data-amt]');
    if (!boxes.length) return;
    var total = 0, count = 0;
    boxes.forEach(function (c) {
      var row = c.closest('tr');
      if (c.checked) { total += parseFloat(c.getAttribute('data-amt')) || 0; count++; row.classList.remove('off'); }
      else { row.classList.add('off'); }
    });

    var item = panel.closest('.bill-item');
    // update the collapsed row summary
    item.querySelectorAll('[data-count]').forEach(function (el) { el.textContent = count; });
    var rowAmt = item.querySelector('[data-rowamt]'); if (rowAmt) rowAmt.textContent = money(total);
    // update the panel footer figures
    var sel = panel.querySelector('[data-selected]'); if (sel) sel.textContent = money(total);
    var inv = panel.querySelector('[data-invoice]'); if (inv) inv.value = '$' + total.toLocaleString('en-US', { minimumFractionDigits: 2 });
    var invTot = panel.querySelector('[data-invoicetotal]'); if (invTot) invTot.textContent = money(total);
    var abs = panel.querySelector('[data-absorbed]'); if (abs) abs.textContent = '$0';
  }

  function refreshFinalizeState() {
    var banner = document.getElementById('finalize-state');
    if (!banner) return;
    var needs = document.querySelectorAll('.tl-entry.needs').length;
    if (needs === 0) {
      banner.className = 'notice ok';
      banner.innerHTML = '<span class="ico">✓</span><div class="grow"><b>All set for today.</b> Every block is assigned — nothing else to confirm.</div>';
      if (document.body.classList.contains('is-processed')) setDayState('settled');
    } else {
      banner.className = 'notice warn';
      banner.innerHTML = '<span class="ico">●</span><div class="grow"><b>' + needs + ' block' + (needs > 1 ? 's' : '') + ' need' + (needs > 1 ? '' : 's') + ' a project.</b> Tap a suggested chip to confirm, or assign one.</div>';
    }
  }

  /* ---- Today: Process the day evolves the SAME page in place (no live parse) ---- */
  function setDayState(step) {
    var ind = document.getElementById('state-indicator');
    if (!ind) return;
    var order = ['capture', 'processed', 'settled'];
    var idx = order.indexOf(step);
    ind.querySelectorAll('[data-s]').forEach(function (el) {
      var i = order.indexOf(el.getAttribute('data-s'));
      el.classList.remove('now', 'done');
      if (i < idx) el.classList.add('done');
      else if (i === idx) el.classList.add('now');
    });
  }

  function processDay(scroll) {
    var prompt = document.getElementById('process-prompt');
    var view = document.getElementById('processed-view');
    if (!view) return;
    if (prompt) prompt.style.display = 'none';
    view.style.display = '';
    document.body.classList.add('is-processed');
    var reproc = document.getElementById('reprocess-btn');
    if (reproc) reproc.style.display = '';
    setDayState('processed');
    refreshFinalizeState();
    if (scroll) view.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  var processBtn = document.getElementById('process-day');
  if (processBtn) processBtn.addEventListener('click', function (e) { e.preventDefault(); processDay(true); });

  var reprocessBtn = document.getElementById('reprocess-btn');
  if (reprocessBtn) reprocessBtn.addEventListener('click', function () { refreshFinalizeState(); });

  // Opening an already-processed day (e.g. from History) lands directly in State B.
  if (document.getElementById('processed-view') && location.hash === '#processed') processDay(false);

  /* ---- Live parse demo on Today: textarea -> timeline preview count ---- */
  var ta = document.getElementById('capture-ta');
  if (ta) {
    var counter = document.getElementById('parse-count');
    function countBlocks() {
      var lines = ta.value.split(/\n/).map(function (l) { return l.trim(); })
        .filter(function (l) { return l.indexOf('@') === 0 && !/\b(done|lunch|break|eod|stop|pause)\b/i.test(l.replace(/^@\S+\s*-?\s*/, '')); });
      if (counter) counter.textContent = lines.length;
    }
    ta.addEventListener('input', countBlocks);
    countBlocks();
  }

  refreshFinalizeState();
})();

/* Menakan HR Portal front-end helpers. No inline scripts (CSP script-src 'self'). */
(function () {
  'use strict';

  // Mobile sidebar toggle
  document.querySelectorAll('[data-toggle-sidebar]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('sidebar').classList.toggle('open');
    });
  });

  // "Go back" links on error pages
  document.querySelectorAll('[data-back]').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); history.back(); });
  });

  // Confirmation prompts: <form data-confirm="Are you sure?">
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
    });
  });

  // Auto-submit filter selects: <select data-autosubmit>
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  // Select-all checkbox: <input type="checkbox" data-check-all="name[]">
  document.querySelectorAll('[data-check-all]').forEach(function (box) {
    box.addEventListener('change', function () {
      document.querySelectorAll('input[type=checkbox][name="' + box.getAttribute('data-check-all') + '"]').forEach(function (c) {
        if (!c.disabled) { c.checked = box.checked; }
      });
    });
  });

  // Show/hide a block when a checkbox is ticked: <input data-reveal="#id">
  document.querySelectorAll('[data-reveal]').forEach(function (box) {
    var target = document.querySelector(box.getAttribute('data-reveal'));
    var sync = function () { if (target) { target.hidden = !box.checked; } };
    box.addEventListener('change', sync); sync();
  });

  // Hide a block while a checkbox is ticked: <input data-reveal-inverse="#id">
  document.querySelectorAll('[data-reveal-inverse]').forEach(function (box) {
    var target = document.querySelector(box.getAttribute('data-reveal-inverse'));
    var sync = function () { if (target) { target.hidden = box.checked; } };
    box.addEventListener('change', sync); sync();
  });

  // Repeatable rows (work experience / education): add from <template>, remove, 'currently works here'
  var renumber = function (list) {
    list.querySelectorAll('[data-row-number]').forEach(function (s, i) { s.textContent = i + 1; });
  };
  var wireRow = function (row) {
    var cur = row.querySelector('[data-current]');
    var to = row.querySelector('[data-to-date]');
    if (cur && to) {
      var sync = function () { to.disabled = cur.checked; if (cur.checked) { to.value = ''; } };
      cur.addEventListener('change', sync); sync();
    }
    var rm = row.querySelector('[data-remove-row]');
    if (rm) {
      rm.addEventListener('click', function () {
        var list = row.parentNode;
        row.remove();
        renumber(list);
      });
    }
  };
  document.querySelectorAll('[data-history-row]').forEach(wireRow);
  document.querySelectorAll('[data-add-row]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var kind = btn.getAttribute('data-add-row');
      var list = document.querySelector('[data-history-list="' + kind + '"]');
      var tpl = document.getElementById('tpl-' + kind);
      if (!list || !tpl) { return; }
      var index = parseInt(list.getAttribute('data-next-index') || '0', 10);
      list.setAttribute('data-next-index', String(index + 1));
      var wrap = document.createElement('div');
      wrap.innerHTML = tpl.innerHTML.replace(/__INDEX__/g, String(index));
      var row = wrap.firstElementChild;
      list.appendChild(row);
      wireRow(row);
      renumber(list);
      var first = row.querySelector('input, select');
      if (first) { first.focus(); }
    });
  });

  // News: show the picker for the chosen audience (employees / departments / teams).
  document.querySelectorAll('[data-audience]').forEach(function (r) {
    r.addEventListener('change', function () {
      document.querySelectorAll('[data-audience-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-audience-panel') !== r.value; });
    });
  });
  // Show the panel for the chosen option: <input type="radio" data-switch="group" value="x"> + <div data-switch-panel="group:x">
  document.querySelectorAll('[data-switch]').forEach(function (r) {
    r.addEventListener('change', function () {
      var g = r.getAttribute('data-switch');
      document.querySelectorAll('[data-switch-panel^="' + g + ':"]').forEach(function (p) { p.hidden = p.getAttribute('data-switch-panel') !== g + ':' + r.value; });
    });
  });
  // New email: preview the signature of the chosen sender (<select data-signature-from> options carry data-signature).
  var sigPreview = document.getElementById('signature-preview');
  if (sigPreview) {
    var showSignature = function () {
      var text = '';
      document.querySelectorAll('select[data-signature-from]').forEach(function (s) {
        var panel = s.closest('[data-switch-panel]');
        if (!panel || !panel.hidden) {
          var o = s.options[s.selectedIndex];
          text = o ? (o.getAttribute('data-signature') || '') : '';
        }
      });
      sigPreview.textContent = text || sigPreview.getAttribute('data-empty');
    };
    document.querySelectorAll('select[data-signature-from], [data-switch="sender"]').forEach(function (el) { el.addEventListener('change', showSignature); });
    showSignature();
  }
  // Confirm before a specific submit button: <button data-confirm-click="Sure?">
  document.querySelectorAll('[data-confirm-click]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      if (!window.confirm(b.getAttribute('data-confirm-click'))) { e.preventDefault(); }
    });
  });

  // View-only areas (the employee's ID card): no right-click save or dragging the photo out.
  document.querySelectorAll('[data-no-save]').forEach(function (el) {
    ['contextmenu', 'dragstart', 'copy'].forEach(function (ev) { el.addEventListener(ev, function (e) { e.preventDefault(); }); });
  });

  // Print buttons: <button data-print>
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });

  // Organisation chart PDF: generate in the background with a loading state, then download,
  // and report success or the server's error message. Without JavaScript the link downloads directly.
  document.querySelectorAll('[data-pdf-export]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      if (!window.fetch || !window.URL || !URL.createObjectURL) { return; }
      e.preventDefault();
      var status = document.querySelector(link.getAttribute('data-pdf-status'));
      var buttons = document.querySelectorAll('[data-pdf-export]');
      var show = function (type, html) {
        if (status) { status.innerHTML = '<div class="alert alert-' + type + ' d-flex align-items-center gap-2 mb-0" role="alert">' + html + '</div>'; }
      };
      if (link.getAttribute('aria-busy') === 'true') { return; }
      buttons.forEach(function (b) { b.setAttribute('aria-busy', 'true'); b.classList.add('disabled'); });
      show('info', '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Generating the PDF from the latest employee records\u2026');
      fetch(link.href, { credentials: 'same-origin', headers: { 'Accept': 'application/pdf, application/json' } })
        .then(function (res) {
          var type = res.headers.get('Content-Type') || '';
          if (res.ok && type.indexOf('application/pdf') === 0) {
            var pages = res.headers.get('X-Pdf-Pages');
            var format = res.headers.get('X-Pdf-Format');
            var name = ((res.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/) || [])[1] || 'Organizational-Structure.pdf';
            return res.blob().then(function (blob) {
              var url = URL.createObjectURL(blob);
              var a = document.createElement('a');
              a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
              setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
              show('success', '<i class="bi bi-check-circle" aria-hidden="true"></i> PDF downloaded: ' + name + (pages ? ' (' + pages + ' page' + (pages === '1' ? '' : 's') + (format ? ', ' + format + ' landscape' : '') + ')' : '') + '.');
            });
          }
          if (res.status === 401 || res.redirected) { throw new Error('Your session has ended. Please sign in again.'); }
          return res.json().catch(function () { return {}; }).then(function (body) {
            throw new Error(body.error || 'The PDF could not be created (error ' + res.status + '). Please try again.');
          });
        })
        .catch(function (err) {
          var msg = err && err.message && err.message !== 'Failed to fetch' ? err.message : 'The PDF could not be created. Check your connection and try again.';
          var div = document.createElement('div'); div.textContent = msg;
          show('danger', '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' + div.innerHTML);
        })
        .then(function () {
          buttons.forEach(function (b) { b.removeAttribute('aria-busy'); b.classList.remove('disabled'); });
        });
    });
  });

  // Organisation chart: on a narrow screen, start with the top of the hierarchy in view.
  document.querySelectorAll('.org-scroll').forEach(function (box) {
    var top = box.querySelector('.org-tree > li > .org-node');
    if (top && box.scrollWidth > box.clientWidth) {
      box.scrollLeft = top.offsetLeft + top.offsetWidth / 2 - box.clientWidth / 2;
    }
  });

  // Organisation chart: expand / collapse branches
  document.querySelectorAll('[data-org-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var li = btn.closest('li');
      var collapsed = li.classList.toggle('collapsed');
      btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
  });
  document.querySelectorAll('[data-org-all]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var collapse = btn.getAttribute('data-org-all') === 'collapse';
      document.querySelectorAll('[data-org-tree] li').forEach(function (li) {
        var t = li.querySelector(':scope > .org-node [data-org-toggle]');
        if (!t) { return; }
        // Keep the top level open when collapsing so the structure stays readable.
        var top = li.parentNode.hasAttribute('data-org-tree');
        var shut = collapse && !top;
        li.classList.toggle('collapsed', shut);
        t.setAttribute('aria-expanded', shut ? 'false' : 'true');
      });
    });
  });

  // Shift type picks default times: <select data-shift-type> options carry data-start/end/break
  document.querySelectorAll('[data-shift-type]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var o = sel.options[sel.selectedIndex];
      if (!o || !o.dataset.start) { return; }
      var f = sel.form;
      f.querySelector('[name=start_time]').value = o.dataset.start;
      f.querySelector('[name=end_time]').value = o.dataset.end;
      f.querySelector('[name=break_minutes]').value = o.dataset.break;
    });
  });

  // Job titles filtered by department on the employee form
  var dept = document.querySelector('[data-dept-select]');
  var job = document.querySelector('[data-job-select]');
  if (dept && job) {
    var filter = function () {
      Array.prototype.forEach.call(job.options, function (o) {
        if (!o.value) { return; }
        var d = o.getAttribute('data-dept');
        o.hidden = !!(dept.value && d && d !== dept.value);
      });
    };
    dept.addEventListener('change', filter); filter();
  }

  // Charts: <canvas data-chart='{"type":"bar",...}'>
  if (window.Chart) {
    var css = getComputedStyle(document.documentElement);
    Chart.defaults.font.family = css.getPropertyValue('--f-body') || 'sans-serif';
    Chart.defaults.color = '#5b6b7f';
    document.querySelectorAll('canvas[data-chart]').forEach(function (c) {
      try { new Chart(c, JSON.parse(c.getAttribute('data-chart'))); } catch (err) { /* ignore bad chart config */ }
    });
  }
})();

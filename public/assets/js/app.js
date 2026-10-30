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

  // File drop area: <label data-dropzone> wrapping an <input type=file>; shows the chosen file's name
  document.querySelectorAll('[data-dropzone]').forEach(function (zone) {
    var input = zone.querySelector('input[type=file]');
    var name = zone.querySelector('[data-dropzone-name]');
    var sync = function () {
      var f = input.files && input.files[0];
      zone.classList.toggle('has-file', !!f);
      if (f && name) { name.textContent = f.name; }
    };
    input.addEventListener('change', sync);
    ['dragenter', 'dragover'].forEach(function (t) {
      zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      zone.addEventListener(t, function () { zone.classList.remove('is-over'); });
    });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; sync(); }
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
  // Rich message box: <textarea data-rich-editor> becomes an editable area that keeps what is pasted from Word, Excel,
  // Outlook or a web page (formatting, tables, charts, pictures). The textarea still carries the value; the server cleans it.
  document.querySelectorAll('textarea[data-rich-editor]').forEach(function (ta) {
    var wrap = document.createElement('div');
    wrap.className = 'rich-editor' + (ta.classList.contains('is-invalid') ? ' is-invalid' : '');
    var bar = document.createElement('div');
    bar.className = 'rich-toolbar';
    var ed = document.createElement('div');
    ed.className = 'rich-area news-rich';
    ed.contentEditable = 'true';
    ed.id = ta.id + '_editor';
    ed.setAttribute('role', 'textbox');
    ed.setAttribute('aria-multiline', 'true');
    ed.setAttribute('data-placeholder', ta.getAttribute('placeholder') || '');
    ed.style.minHeight = (parseInt(ta.getAttribute('rows'), 10) || 6) * 1.6 + 'em';
    var label = ta.id && document.querySelector('label[for="' + ta.id + '"]');
    if (label) { label.setAttribute('for', ed.id); ed.setAttribute('aria-labelledby', label.id || (label.id = ta.id + '_label')); }

    var esc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
    var v = ta.value.trim();
    ed.innerHTML = /^<[a-z]/i.test(v) ? v : esc(v).replace(/\n/g, '<br>');
    var sync = function () { ta.value = (ed.textContent.trim() || ed.querySelector('img, table')) ? ed.innerHTML : ''; };
    ed.addEventListener('input', sync);
    if (ta.form) { ta.form.addEventListener('submit', sync); }

    var insertHtml = function (html) { ed.focus(); document.execCommand('insertHTML', false, html); sync(); };
    // A picture as a data: URL, scaled down to at most 1600px wide; the server stores it as a file.
    var readImage = function (file) {
      return new Promise(function (resolve) {
        var reader = new FileReader();
        reader.onload = function () {
          var img = new Image();
          img.onload = function () {
            if (img.naturalWidth <= 1600 && reader.result.length < 3000000) { resolve(reader.result); return; }
            var scale = Math.min(1, 1600 / img.naturalWidth), c = document.createElement('canvas');
            c.width = Math.round(img.naturalWidth * scale);
            c.height = Math.round(img.naturalHeight * scale);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            var png = c.toDataURL('image/png');
            resolve(png.length < 3000000 ? png : c.toDataURL('image/jpeg', 0.85));
          };
          img.onerror = function () { resolve(null); };
          img.src = reader.result;
        };
        reader.onerror = function () { resolve(null); };
        reader.readAsDataURL(file);
      });
    };
    var insertImages = function (files) {
      return Promise.all(files.map(readImage)).then(function (urls) {
        insertHtml(urls.filter(Boolean).map(function (u) { return '<img src="' + u + '" alt="">'; }).join('<br>'));
      });
    };
    // Word and Excel put their styles in a <style> block; copy them onto each element so they survive the paste.
    var inlineStyles = function (doc) {
      doc.querySelectorAll('[style]').forEach(function (el) { el.setAttribute('data-own-style', el.style.cssText); });
      doc.querySelectorAll('style').forEach(function (s) {
        var sheet;
        try { sheet = new CSSStyleSheet(); sheet.replaceSync(s.textContent.replace(/<!--|-->/g, '')); } catch (err) { return; }
        Array.prototype.forEach.call(sheet.cssRules, function (rule) {
          if (!rule.selectorText) { return; }
          var els;
          try { els = doc.body.querySelectorAll(rule.selectorText); } catch (err) { return; }
          els.forEach(function (el) {
            for (var i = 0; i < rule.style.length; i++) {
              var p = rule.style[i];
              if (!el.getAttribute('data-own-style') || !new RegExp('(^|;)\\s*' + p + '\\s*:').test(el.getAttribute('data-own-style'))) {
                el.style.setProperty(p, rule.style.getPropertyValue(p));
              }
            }
          });
        });
      });
      doc.querySelectorAll('[data-own-style]').forEach(function (el) { el.removeAttribute('data-own-style'); });
    };

    ed.addEventListener('paste', function (e) {
      var cd = e.clipboardData;
      if (!cd) { return; }
      var files = Array.prototype.filter.call(cd.files || [], function (f) { return f.type.indexOf('image/') === 0; });
      var html = cd.getData('text/html');
      var doc = html ? new DOMParser().parseFromString(html, 'text/html') : null;
      // A screenshot, or a chart copied from Excel (its HTML only points at a local file): paste the picture itself.
      if (files.length && (!doc || !doc.body.textContent.trim())) {
        e.preventDefault();
        insertImages(files);
        return;
      }
      if (!doc) { return; }
      e.preventDefault();
      inlineStyles(doc);
      // Pictures that point at the copier's own disk cannot be shown; use the clipboard's picture files in their place.
      var local = Array.prototype.slice.call(doc.body.querySelectorAll('img')).filter(function (img) { return !/^(https?:|data:)/i.test(img.getAttribute('src') || ''); });
      Promise.all(files.slice(0, local.length).map(readImage)).then(function (urls) {
        local.forEach(function (img, i) { if (urls[i]) { img.setAttribute('src', urls[i]); } else { img.remove(); } });
        doc.body.querySelectorAll('script, style, meta, link').forEach(function (el) { el.remove(); });
        insertHtml(doc.body.innerHTML);
      });
    });

    [['bold', 'type-bold', 'Bold'], ['italic', 'type-italic', 'Italic'], ['underline', 'type-underline', 'Underline'],
      ['insertUnorderedList', 'list-ul', 'Bulleted list'], ['insertOrderedList', 'list-ol', 'Numbered list'], ['removeFormat', 'eraser', 'Clear formatting'], ['image', 'image', 'Insert picture']]
      .forEach(function (t) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm btn-light';
        b.title = t[2];
        b.setAttribute('aria-label', t[2]);
        b.innerHTML = '<i class="bi bi-' + t[1] + '"></i>';
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep the text selection
        b.addEventListener('click', function () {
          if (t[0] !== 'image') { ed.focus(); document.execCommand(t[0]); sync(); return; }
          var input = document.createElement('input');
          input.type = 'file';
          input.accept = 'image/png,image/jpeg,image/gif,image/webp';
          input.multiple = true;
          input.addEventListener('change', function () { insertImages(Array.prototype.slice.call(input.files)); });
          input.click();
        });
        bar.appendChild(b);
      });

    wrap.appendChild(bar);
    wrap.appendChild(ed);
    ta.removeAttribute('required');
    ta.hidden = true;
    ta.parentNode.insertBefore(wrap, ta.nextSibling);
  });
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

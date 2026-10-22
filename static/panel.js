'use strict';
/*
 * Feed Settings Panel
 *
 * Right-click a feed in the sidebar (contributed to Right-Click Actions' menu)
 * or in the article list / header (own listener) and the feed's enabled fields
 * open in a panel over the page.
 *
 * Degrades to nothing: with the extension disabled this file is never
 * requested; with it enabled but no feed row under the pointer, no handler runs.
 */

(function () {
  var KEY = 'Right Click Actions - Feed Settings Panel';
  var panel = null;
  var overlay = null;
  var currentId = null;
  var payload = null;
  var scrollLocked = false;
  var prevBodyOverflow = '';

  /*
   * How each field renders. The server decides WHICH keys are enabled; this
   * table decides how each one looks. Category options come from the server
   * (they are dynamic), everything else is static here.
   */
  var FIELDS = {
    name: { label: 'Title', type: 'text' },
    url: { label: 'Feed URL', type: 'url' },
    website: { label: 'Website URL', type: 'url' },
    description: { label: 'Description', type: 'textarea' },
    category: { label: 'Category', type: 'select', source: 'categories' },
    priority: {
      label: 'Priority', type: 'select', options: [
        { value: 20, label: 'Important' },
        { value: 10, label: 'Main stream' },
        { value: 0, label: 'In its category' },
        { value: -5, label: 'Feed only' },
        { value: -10, label: 'Do not show' }
      ]
    },
    ttl: {
      label: 'Refresh interval', type: 'select', options: [
        { value: 0, label: 'Use default' },
        { value: 300, label: '5 minutes' },
        { value: 900, label: '15 minutes' },
        { value: 1800, label: '30 minutes' },
        { value: 3600, label: '1 hour' },
        { value: 21600, label: '6 hours' },
        { value: 43200, label: '12 hours' },
        { value: 86400, label: '1 day' }
      ]
    },
    mute: { label: 'Mute', type: 'checkbox', hint: 'Stops this feed from being refreshed at all.' },
    show_unread_count: {
      label: 'Show unread count', type: 'select', hint: 'Whether this feed\'s number appears in the sidebar.', options: [
        { value: '', label: 'Use default' }, { value: '0', label: 'No' }, { value: '1', label: 'Yes' }
      ]
    },
    display_enclosures: {
      label: 'Show enclosure links', type: 'select', hint: 'Whether audio and video attachments are listed.', options: [
        { value: '', label: 'Use default' }, { value: '0', label: 'No' }, { value: '1', label: 'Yes' }
      ]
    },
    mark_updated_article_unread: {
      label: 'Updated articles become unread', type: 'select', hint: 'Re-marks an article unread when its content changes upstream.', options: [
        { value: '', label: 'Use default' }, { value: '0', label: 'No' }, { value: '1', label: 'Yes' }
      ]
    },
    defaultSortOrder: {
      label: 'Sort order', type: 'select', hint: 'Default article order for this feed.', options: [
        { value: '', label: 'Use default' },
        { value: 'date_desc', label: 'Newest first' },
        { value: 'date_asc', label: 'Oldest first' },
        { value: 'id_desc', label: 'Newest added first' },
        { value: 'title_asc', label: 'Title A to Z' },
        { value: 'title_desc', label: 'Title Z to A' }
      ]
    },
    archive_keep_max: {
      label: 'Keep at most N articles', type: 'number', placeholder: 'Empty = keep all',
      hint: 'Older articles beyond this count are deleted on refresh.'
    },
    archive_keep_period: {
      label: 'Keep articles for', type: 'select', hint: 'Articles older than this are deleted on refresh.', options: [
        { value: '', label: 'Keep all' },
        { value: 'P1W', label: '1 week' },
        { value: 'P1M', label: '1 month' },
        { value: 'P3M', label: '3 months' },
        { value: 'P6M', label: '6 months' },
        { value: 'P1Y', label: '1 year' }
      ]
    },
    http_user: { label: 'HTTP username', type: 'text', placeholder: 'Only if the feed needs a login' },
    http_pass: { label: 'HTTP password', type: 'password', placeholder: 'Leave blank to keep stored' },
    unread_count_readonly: { label: '', type: 'info' },
    action_refresh_feed: { label: 'Update feed now', type: 'action', op: 'refresh', icon: '\u21BB' },
    action_reload_articles: { label: 'Reload articles', type: 'action', op: 'reload', icon: '\u21BA' },
    action_delete_articles: { label: 'Delete all articles', type: 'action', op: 'delete', icon: '\u2716', danger: true },
    action_export: { label: 'Export articles', type: 'action', op: 'export', icon: '\u2913', link: true },
    action_remove_feed: { label: 'Remove feed', type: 'action', op: 'remove', icon: '\u2716', danger: true }
  };

  // ---------------------------------------------------------------- helpers

  function whenContext(fn) {
    var HC = window.context && window.context.extensions && window.context.extensions[KEY];
    if (HC) { fn(HC); return; }
    var done = false;
    function fire(H) {
      if (done) return;
      done = true;
      fn(H);
    }
    document.addEventListener('freshrss:globalContextLoaded', function () {
      var H = window.context && window.context.extensions && window.context.extensions[KEY];
      if (H) fire(H);
    }, { once: true });
    var tries = 0;
    var t = setInterval(function () {
      var H = window.context && window.context.extensions && window.context.extensions[KEY];
      if (H) { clearInterval(t); fire(H); }
      else if (++tries > 40) { clearInterval(t); }
    }, 50);
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function notify(msg, isError) {
    /* an inline banner in the panel is the primary signal while it is open;
       the toast is only for when the panel has closed over the message. */
    var n = el('div', 'fsp-toast', msg);
    if (isError) n.classList.add('fsp-toast-error');
    document.body.appendChild(n);
    setTimeout(function () { n.remove(); }, 3500);
  }

  /*
   * One visible place for "something is happening" and "it happened": the
   * status strip above the buttons. Before this, pressing an action gave no
   * feedback until the page reloaded.
   */
  function setStatus(msg, kind) {
    if (!panel) return;
    var status = panel.querySelector('.fsp-status');
    if (!status) return;
    status.textContent = msg || '';
    status.classList.remove('fsp-status-busy', 'fsp-status-ok', 'fsp-status-error');
    if (kind) status.classList.add('fsp-status-' + kind);
  }

  function setBusy(busy, btn) {
    if (!panel) return;
    panel.classList.toggle('fsp-busy', !!busy);
    [].forEach.call(panel.querySelectorAll('.fsp-btn, .fsp-action'), function (b) {
      if (busy) {
        if (b.dataset.label === undefined) b.dataset.label = b.textContent;
        b.disabled = true;
      } else {
        b.disabled = false;
        if (b.dataset.label !== undefined && b.classList.contains('fsp-btn')) b.textContent = b.dataset.label;
      }
    });
    if (busy && btn) btn.textContent = 'Working\u2026';
  }

  function actionUrl() {
    return (payload && payload.actionUrl) || './?c=fsp&a=action';
  }
  function exportUrl() {
    return (payload && payload.exportUrl) || './?c=fsp&a=export';
  }

  // The feed whose page we are on, if any. FreshRSS's stream URLs name it as
  // get=f_<id>.
  function viewingFeedId() {
    var m = String(window.location.href).match(/[?&]get=f_(\d+)/);
    return m ? m[1] : null;
  }
  // Where to go when the feed being viewed no longer exists. NOT a reload:
  // reloading the URL that names the removed feed is exactly what makes
  // FreshRSS answer with its "page not found" screen. The stream root is
  // still a valid page afterwards.
  function streamRootUrl() {
    return window.location.pathname || './';
  }
  function isViewingCurrent() {
    return currentId != null && String(viewingFeedId()) === String(currentId);
  }

  // Deleting articles and removing a feed are not undone by pressing Cancel.
  function confirmAction(op, name) {
    var active = op === 'remove' && isViewingCurrent();
    var text = op === 'remove'
      ? 'Remove "' + name + '" and all of its articles?'
        + (active ? '\n\nThis is the feed you are reading right now — removing it takes you'
          + ' back to your feeds.' : '')
      : op === 'delete'
        ? 'Delete all stored articles of "' + name + '"? The feed stays subscribed.'
        : 'Delete "' + name + '\'s stored articles and fetch them again from the source?';
    return window.confirm(text);
  }

  function runAction(op, name, btn) {
    if ((op === 'remove' || op === 'delete' || op === 'reload') && !confirmAction(op, name)) return;
    if (op === 'export') {
      window.open(exportUrl() + '&id=' + encodeURIComponent(currentId), '_blank');
      setStatus('Export started.', 'ok');
      return;
    }

    var token = (payload && payload.csrf) || csrfFromPage();
    if (!token) {
      setStatus('Could not read the security token \u2014 reload the page and try again.', 'error');
      return;
    }

    var fd = new FormData();
    fd.append('_csrf', token);
    fd.append('id', currentId);
    fd.append('op', op);

    var started = Date.now();
    setBusy(true, btn);
    setStatus('Working\u2026');

    function done(msg, isError) {
      // Keep the spinner visible for a beat so a fast call does not flash.
      var wait = Math.max(0, 220 - (Date.now() - started));
      setTimeout(function () {
        setBusy(false);
        setStatus(msg, isError ? 'error' : 'ok');
        notify(msg, isError);
      }, wait);
    }

    fetch(actionUrl(), {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.j || !res.ok || res.j.error) {
          done((res.j && res.j.error) || 'That action failed.', true);
          return;
        }
        var msg = res.j.message || 'Done.';
        var wasViewing = !!res.j.removed && isViewingCurrent();
        if (wasViewing) { msg += ' You were viewing it, so you are back at your feeds.'; }
        done(msg, false);
        if (res.j.removed) {
          // Reload either way would be wrong for the feed you are ON: its URL
          // no longer resolves, so go to the stream root instead.
          setTimeout(function () {
            if (wasViewing) { window.location.href = streamRootUrl(); }
            else { window.location.reload(); }
          }, 900);
        }
      })
      .catch(function () { done('Could not reach the server.', true); });
  }

  // Every FreshRSS page carries the token in a form; used when there is no
  // extension payload (the extension's own settings page).
  function csrfFromPage() {
    var el = document.querySelector('input[name="_csrf"]');
    return el ? el.value : '';
  }

  function feedIdFromHref(href) {
    if (!href) return null;
    var m = href.match(/[?&]get=f_(\d+)/) || href.match(/[?&]id=(\d+)/) || href.match(/f_(\d+)/);
    return m ? m[1] : null;
  }

  // The page behind the panel must not scroll while it is open.
  function lockScroll(on) {
    if (on === scrollLocked) return;
    scrollLocked = on;
    if (on) {
      prevBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = prevBodyOverflow || '';
    }
  }

  // ---------------------------------------------------------------- panel

  function ensurePanel() {
    if (panel) return;

    overlay = el('div', 'fsp-overlay');
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closePanel();
    });

    panel = el('div', 'fsp-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', 'Feed settings');
    panel.innerHTML = '' +
      '<div class="fsp-head">' +
        '<span class="fsp-title"></span>' +
        '<button type="button" class="fsp-close" aria-label="Close">&times;</button>' +
      '</div>' +
      '<div class="fsp-body"></div>' +
      '<div class="fsp-foot"></div>';

    panel.querySelector('.fsp-close').addEventListener('click', closePanel);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && panel && overlay.classList.contains('fsp-open')) closePanel();
    });
  }

  function closePanel() {
    if (!overlay) return;
    overlay.classList.remove('fsp-open');
    lockScroll(false);
    currentId = null;
  }

  function openPanel(id) {
    if (!id) return;
    ensurePanel();
    currentId = String(id);
    overlay.classList.add('fsp-open');
    lockScroll(true);

    var title = panel.querySelector('.fsp-title');
    var body = panel.querySelector('.fsp-body');
    var foot = panel.querySelector('.fsp-foot');
    title.textContent = 'Loading…';
    body.innerHTML = '<div class="fsp-loading">Loading feed settings…</div>';
    foot.innerHTML = '';

    var base = (payload && payload.panelUrl) || './?c=fsp&a=panel';
    var url = base + (base.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(id);

    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (String(currentId) !== String(id)) return;
        if (!res.ok || !res.j || res.j.error) {
          body.innerHTML = '';
          body.appendChild(el('div', 'fsp-error', (res.j && res.j.error) || 'Could not load this feed.'));
          title.textContent = 'Feed settings';
          return;
        }
        render(res.j);
      })
      .catch(function () {
        body.innerHTML = '';
        body.appendChild(el('div', 'fsp-error', 'Could not reach the server.'));
      });
  }

  function render(data) {
    var title = panel.querySelector('.fsp-title');
    var body = panel.querySelector('.fsp-body');
    var foot = panel.querySelector('.fsp-foot');

    title.textContent = data.values.name || 'Feed settings';
    body.innerHTML = '';
    foot.innerHTML = '';

    var fields = (data.fields || []).filter(function (f) { return !!FIELDS[f]; });
    var actionKeys = (data.actions || data.fields || []).filter(function (f) {
      return FIELDS[f] && FIELDS[f].type === 'action';
    });
    var editFields = fields.filter(function (f) { return FIELDS[f].type !== 'action'; });

    if (!editFields.length && !actionKeys.length) {
      body.appendChild(el('div', 'fsp-empty',
        'No fields are switched on. Choose them on the Feed Settings Panel extension page.'));
      return;
    }

    var form = el('div', 'fsp-form');
    var inputs = {};

    function addField(key) {
      var def = FIELDS[key];
      if (!def || def.type === 'action') return;
      var value = data.values[key];
      var wrap = el('div', 'fsp-field');
      var input;

      if (def.type === 'info') {
        wrap.appendChild(el('div', 'fsp-info', String(value === undefined ? '' : value)));
        form.appendChild(wrap);
        return;
      }

      if (def.type === 'checkbox') {
        var rowWrap = el('label', 'fsp-check');
        input = el('input');
        input.type = 'checkbox';
        input.checked = String(value) === '1';
        rowWrap.appendChild(input);
        rowWrap.appendChild(el('span', null, def.label));
        wrap.appendChild(rowWrap);
        if (def.hint) wrap.appendChild(el('div', 'fsp-help', def.hint));
        inputs[key] = input;
        form.appendChild(wrap);
        return;
      }

      wrap.appendChild(el('label', 'fsp-label', def.label));

      if (def.type === 'textarea') {
        input = el('textarea', 'fsp-input');
        input.rows = 2;
        input.value = value || '';
      } else if (def.type === 'select') {
        input = el('select', 'fsp-input');
        var opts = def.source === 'categories'
          ? (data.categories || []).map(function (c) { return { value: c.id, label: c.name }; })
          : def.options;
        (opts || []).forEach(function (o) {
          var opt = el('option', null, o.label);
          opt.value = o.value;
          if (String(o.value) === String(value)) opt.selected = true;
          input.appendChild(opt);
        });
      } else {
        input = el('input', 'fsp-input');
        input.type = def.type;
        input.value = value === null || value === undefined ? '' : value;
        if (def.placeholder) input.placeholder = def.placeholder;
      }
      if (def.hint) wrap.appendChild(el('div', 'fsp-help', def.hint));
      inputs[key] = input;
      wrap.appendChild(input);
      form.appendChild(wrap);
    }

    fields.forEach(addField);
    body.appendChild(form);

    // Actions row: the operations this feed can have, above Save/Cancel.
    if (actionKeys.length) {
      var actBox = el('div', 'fsp-actionbox');
      actBox.appendChild(el('div', 'fsp-actionbox-title', 'Actions'));
      var actRow = el('div', 'fsp-actionrow');
      actionKeys.forEach(function (key) {
        var def = FIELDS[key];
        var b = el('button', 'fsp-action' + (def.danger ? ' fsp-action-danger' : ''), (def.icon ? def.icon + '  ' : '') + def.label);
        b.type = 'button';
        b.title = def.label;
        b.addEventListener('click', function () {
          runAction(def.op, data.values.name || 'this feed', b);
        });
        actRow.appendChild(b);
      });
      actBox.appendChild(actRow);
      body.appendChild(actBox);
    }

    var actions = el('div', 'fsp-actions');
    var save = el('button', 'fsp-btn fsp-btn-primary', 'Save');
    save.type = 'button';
    var cancel = el('button', 'fsp-btn', 'Cancel');
    cancel.type = 'button';
    var open = el('a', 'fsp-btn fsp-btn-quiet', 'Open full page');
    open.href = './?c=subscription&a=feed&id=' + encodeURIComponent(data.id);
    open.target = '_blank';
    open.rel = 'noreferrer';

    var status = el('span', 'fsp-status', (data.unread || 0) + ' unread · ' + (data.total || 0) + ' articles');
    foot.appendChild(status);
    actions.appendChild(save);
    actions.appendChild(cancel);
    actions.appendChild(open);
    foot.appendChild(actions);

    cancel.addEventListener('click', closePanel);

    save.addEventListener('click', function () {
      doSave(data.id, editFields, inputs, save);
    });

    var first = form.querySelector('.fsp-input');
    if (first) setTimeout(function () { first.focus(); first.select && first.select(); }, 30);
  }

  function doSave(id, fields, inputs, saveBtn) {
    var fd = new FormData();
    fd.append('id', id);
    var token = (payload && payload.csrf) || csrfFromPage();
    if (!token) {
      setStatus('Could not read the security token \u2014 reload the page and try again.', 'error');
      return;
    }
    fd.append('_csrf', token);
    fields.forEach(function (f) {
      var input = inputs[f];
      if (!input) return;
      if (input.type === 'checkbox') {
        fd.append(f, input.checked ? '1' : '0');
      } else {
        fd.append(f, input.value);
      }
    });

    saveBtn.disabled = true;
    setStatus('Saving\u2026', 'busy');

    var base = (payload && payload.saveUrl) || './?c=fsp&a=save';

    fetch(base, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        saveBtn.disabled = false;
        if (!res.j || !res.ok || res.j.error) {
          setStatus((res.j && res.j.error) || 'Save failed.', 'error');
          return;
        }
        setStatus(res.j.message || 'Saved.', 'ok');
        if (res.j.values) updateSidebarName(res.j.values.name);
      })
      .catch(function () {
        saveBtn.disabled = false;
        setStatus('Could not reach the server.', 'error');
      });
  }

  // A renamed feed should read its new name in the sidebar without a reload.
  function updateSidebarName(name) {
    if (!currentId || !name) return;
    var row = document.querySelector('.tree a[href*="get=f_' + currentId + '"]');
    if (!row) return;
    var label = row.querySelector('.item-title') || row;
    var count = row.querySelector('.item-element');
    if (label) label.textContent = name;
    if (count) row.appendChild(count);
  }

  // ------------------------------------------------- right-click: article list

  function findFeedIdInList(target) {
    var flux = target.closest && target.closest('.flux');
    if (!flux) return null;
    var link = flux.querySelector('.flux_header a[href*="get=f_"]')
      || flux.querySelector('a[href*="get=f_"]');
    if (link) {
      var id = feedIdFromHref(link.getAttribute('href'));
      if (id) return id;
    }
    if (flux.dataset && flux.dataset.feed) return flux.dataset.feed;
    return null;
  }

  function initListListener() {
    document.addEventListener('contextmenu', function (e) {
      var id = findFeedIdInList(e.target);
      if (!id) return;
      e.preventDefault();
      e.stopPropagation();
      openPanel(id);
    }, true);
  }

  // ------------------------------------------- right-click: Right-Click Actions

  /*
   * Contribute one item to Right-Click Actions' sidebar-feed menu. That host
   * builds its menu on `contextmenu` at BUBBLE phase and rebuilds it on every
   * open, so our capture-phase listener runs first and the append lands in a
   * setTimeout(0) after the menu exists. If the host is absent there is no
   * sidebar right-click menu at all, so nothing is missing and we add none.
   */
  function initHostMenuHook() {
    document.addEventListener('contextmenu', function (e) {
      if (!e.target.closest || !e.target.closest('.tree')) return;
      var row = e.target.closest('a[href*="get=f_"]');
      if (!row) return;
      var id = feedIdFromHref(row.getAttribute('href'));
      if (!id) return;

      setTimeout(function () {
        var menu = document.getElementById('frss-ctx-menu');
        if (!menu) return;
        if (menu.querySelector('[data-action="fsp_open"]')) return;

        var item = el('div', 'frss-ctx-item');
        item.dataset.action = 'fsp_open';
        var icon = el('span', 'frss-ctx-icon', '\u2699');
        var label = el('span', 'frss-ctx-label', 'Feed settings (panel)');
        item.appendChild(icon);
        item.appendChild(label);

        item.addEventListener('mousedown', function (ev) { ev.stopPropagation(); });
        item.addEventListener('click', function (ev) {
          ev.stopPropagation();
          ev.preventDefault();
          menu.style.display = 'none';
          openPanel(id);
        });

        // Put it directly under the host's own "Feed settings" item so the two
        // read as the same job, different window.
        var existing = menu.querySelector('[data-action="open_settings"]');
        if (existing && existing.parentNode) {
          existing.parentNode.insertBefore(item, existing.nextSibling);
        } else {
          menu.appendChild(item);
        }
      }, 0);
    }, true);
  }

  // ---------------------------------------------------------------- boot

  function boot(config) {
    payload = config || {};
    if (window.FeedSettingsPanel) return;
    window.FeedSettingsPanel = { open: openPanel, close: closePanel };

    initListListener();
    initHostMenuHook();
  }

  /*
   * On an ordinary page the payload arrives with the page; on the extension's
   * own settings page there is no payload, but the button there still needs to
   * open the panel. So bind immediately with defaults, then upgrade if the
   * payload turns up. The defaults are the same routes the payload carries.
   */
  boot(null);
  whenContext(boot);
})();

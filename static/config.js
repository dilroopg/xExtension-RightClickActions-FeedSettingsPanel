'use strict';
/*
 * Feed Settings Panel — extension page.
 *
 * Two jobs:
 *   1. keep the live preview in step with the toggles, so switching a field on
 *      shows exactly what it adds before it is saved;
 *   2. show a running count next to the Save button.
 *
 * The preview is built from the same field definitions the panel uses, so the
 * two cannot drift. Everything here is progressive enhancement: with this file
 * absent the toggles still post, the page just has no preview.
 */

(function () {
  // Sample values, keyed by field. Only used to draw the preview.
  var SAMPLE = {
    name: 'Example Feed',
    url: 'https://example.com/feed.xml',
    website: 'https://example.com/',
    description: 'A short description of this feed.',
    category: 'Newsletters',
    priority: '10',
    ttl: '3600',
    mute: false,
    show_unread_count: '1',
    display_enclosures: '0',
    defaultSortOrder: 'date_desc',
    mark_updated_article_unread: '',
    archive_keep_max: '',
    archive_keep_period: 'P3M',
    http_user: 'user',
    http_pass: '••••••',
    unread_count_readonly: '12 unread / 240 total'
  };

  // Option lists for selects, keyed by field.
  var OPTIONS = {
    priority: [
      'Important', 'Main stream', 'In its category', 'Feed only', 'Do not show'
    ],
    ttl: [
      'Use default', '5 minutes', '15 minutes', '30 minutes', '1 hour', '6 hours', '12 hours', '1 day'
    ],
    show_unread_count: ['Use default', 'No', 'Yes'],
    display_enclosures: ['Use default', 'No', 'Yes'],
    mark_updated_article_unread: ['Use default', 'No', 'Yes'],
    defaultSortOrder: ['Use default', 'Newest first', 'Oldest first', 'Newest added first', 'Title A to Z', 'Title Z to A'],
    archive_keep_period: ['Keep all', '1 week', '1 month', '3 months', '6 months', '1 year']
  };

  var SELECT_DEFAULTS = {
    priority: 1,
    ttl: 0,
    show_unread_count: 0,
    display_enclosures: 0,
    mark_updated_article_unread: 0,
    defaultSortOrder: 0,
    archive_keep_period: 0,
    category: 0
  };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  // Build one preview row for a field key. Returns null for unknown keys.
  function previewRow(key, type) {
    var wrap = el('div', 'fsp-field');

    if (type === 'info') {
      wrap.appendChild(el('div', 'fsp-info', SAMPLE[key] || ''));
      return wrap;
    }

    wrap.appendChild(el('span', 'fsp-label', labelFor(key)));

    if (type === 'textarea') {
      var ta = el('textarea', 'fsp-input');
      ta.rows = 2;
      ta.value = SAMPLE[key] || '';
      ta.disabled = true;
      wrap.appendChild(ta);
      return wrap;
    }

    if (type === 'select' || type === 'select-bool') {
      var sel = el('select', 'fsp-input');
      var opts = OPTIONS[key] || [];
      for (var i = 0; i < opts.length; i++) {
        var o = el('option', null, opts[i]);
        if (SAMPLE[key] === String(i)) o.selected = true;
        sel.appendChild(o);
      }
      if (key === 'category') {
        var c = el('option', null, 'Newsletters');
        c.selected = true;
        sel.appendChild(c);
      }
      if (key === 'priority' && SAMPLE[key] === '10') sel.selectedIndex = 1;
      if (!opts.length && key !== 'category') {
        var d = el('option', null, SAMPLE[key] || '');
        sel.appendChild(d);
      }
      sel.disabled = true;
      wrap.appendChild(sel);
      return wrap;
    }

    if (type === 'checkbox') {
      var row = el('label', 'fsp-check');
      var cb = el('input');
      cb.type = 'checkbox';
      cb.disabled = true;
      row.appendChild(cb);
      row.appendChild(el('span', null, labelFor(key)));
      wrap.innerHTML = '';
      wrap.appendChild(row);
      return wrap;
    }

    var input = el('input', 'fsp-input');
    input.type = (type === 'password') ? 'password' : (type === 'number' ? 'number' : 'text');
    input.value = SAMPLE[key] || '';
    input.disabled = true;
    wrap.appendChild(input);
    return wrap;
  }

  function labelFor(key) {
    var map = {
      name: 'Title', url: 'Feed URL', website: 'Website URL', description: 'Description',
      category: 'Category', priority: 'Priority', ttl: 'Refresh interval', mute: 'Mute',
      show_unread_count: 'Show unread count', display_enclosures: 'Show enclosure links',
      defaultSortOrder: 'Sort order', mark_updated_article_unread: 'Updated articles become unread',
      archive_keep_max: 'Keep at most N articles', archive_keep_period: 'Keep articles for',
      http_user: 'HTTP username', http_pass: 'HTTP password'
    };
    return map[key] || key;
  }

  function init() {
    var boxes = [].slice.call(document.querySelectorAll('.fsp-switch input[type="checkbox"]'));
    var hint = document.getElementById('fsp-hint');
    var form = document.getElementById('fsp-preview-form');
    if (!boxes.length) return;

    function render() {
      var on = [];
      for (var i = 0; i < boxes.length; i++) {
        if (boxes[i].checked) on.push(boxes[i]);
      }

      if (hint) {
        hint.textContent = on.length === 0
          ? 'No fields selected — the panel will show nothing.'
          : on.length + (on.length === 1 ? ' field' : ' fields') + ' will show in the panel.';
      }

      if (!form) return;
      form.innerHTML = '';
      if (!on.length) {
        form.appendChild(el('div', 'fsp-empty', 'No fields — switch some on to preview them.'));
        return;
      }
      for (var j = 0; j < on.length; j++) {
        var row = previewRow(on[j].dataset.key, on[j].dataset.type);
        if (row) form.appendChild(row);
      }
    }

    for (var i = 0; i < boxes.length; i++) {
      boxes[i].addEventListener('change', render);
    }

    // "toggle all" per group: on if any are off, off if all are on.
    [].slice.call(document.querySelectorAll('.fsp-group-all')).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var group = btn.dataset.group;
        var groupBoxes = boxes.filter(function (b) { return b.dataset.group === group; });
        var anyOff = groupBoxes.some(function (b) { return !b.checked; });
        groupBoxes.forEach(function (b) { b.checked = anyOff; });
        render();
      });
    });

    // Open the real panel straight from this page.
    var quickSelect = document.getElementById('fsp-quick-feed');
    var quickOpen = document.getElementById('fsp-quick-open');
    if (quickSelect && quickOpen) {
      quickOpen.addEventListener('click', function () {
        var id = quickSelect.value;
        if (!id) { quickSelect.focus(); return; }
        if (window.FeedSettingsPanel && window.FeedSettingsPanel.open) {
          window.FeedSettingsPanel.open(id);
        } else {
          window.location.href = './?c=subscription&a=feed&id=' + encodeURIComponent(id);
        }
      });
    }

    render();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

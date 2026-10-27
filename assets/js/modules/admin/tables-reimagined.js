/* =============================================================
 * tables-reimagined.js — Reimagined view of wp-list-table (Direction B).
 *
 * Built client-side from the DOM already rendered by WordPress: the native
 * table stays in the DOM (single source of truth, hidden via CSS) and all
 * its row-actions — both native and third-party plugins (post_row_actions,
 * etc.) — are read automatically. Clicking a chip triggers the original
 * native link (nonces & WP handlers preserved).
 *
 * Config injected by TablesReimagined.php: window.doubleyoupiTables.
 * =============================================================*/
(function () {
  'use strict';

  var CFG = window.doubleyoupiTables;
  if (!CFG || !CFG.screen) { return; }
  var table = document.querySelector('.wp-list-table');
  if (!table) { return; }

  var SCREEN = CFG.screen;
  var KIND   = SCREEN.kind;                 // 'post' | 'user' | 'term' | 'generic' | 'orders' | 'plugins' (screen type)
  var I18N   = CFG.i18n || { statuses: {}, labels: {} };
  var L      = I18N.labels || {};
  var ST     = I18N.statuses || {};
  var HIER   = CFG.hierarchy || null;       // { id: {parent, depth} } | null
  var ORDERABLE = !!SCREEN.orderable;       // post type supports page-attributes

  // Classification column (post lists). WordPress names it `column-categories`
  // on posts, `column-tags`, and `column-taxonomy-<slug>` for every taxonomy a
  // post type registers with `show_admin_column`. Hard-coding "categories"
  // showed an EMPTY column on any custom post type with its own taxonomies
  // (audit du banc FSE, 14/09/2026: `dy_case` has Secteurs + Technologies).
  // We resolve the real column once, keep its native heading, and drop the
  // column entirely when the screen classifies nothing.
  var CLASSCOL = (function () {
    if (KIND !== 'post') return null;
    var th = table.querySelector('thead th.column-categories')
          || table.querySelector('thead th.column-tags')
          || table.querySelector('thead th[class*="column-taxonomy-"]');
    if (!th) return null;
    var cls = '';
    th.className.split(/\s+/).forEach(function (c) { if (!cls && c.indexOf('column-') === 0) cls = c; });
    return cls ? { cls: cls, th: th } : null;
  })();

  // Meta columns of a post list, read from the native <thead> (lot 19,
  // 14/09/2026). The template used to hard-code author / classification /
  // date: every column an extension adds (SKU, price, stock on WooCommerce
  // products, the "Client" column of the bench) was silently dropped. Now
  // every native column except the checkbox, the title and thumbnails gets
  // a cell, with a curated treatment for the ones we know and a generic
  // cell (native HTML, ellipsis) for the others. WordPress' own Screen
  // Options stay the source of truth for what is hidden (`th.hidden`), and
  // the gear writes back to the same user option.
  var META_KIND = {
    author: 'author', date: 'date', comments: 'comments',
    categories: 'tax', tags: 'tax',
    // WooCommerce names its taxonomy columns after the taxonomy itself.
    product_cat: 'tax', product_tag: 'tax', product_brand: 'tax',
    sku: 'sku', price: 'price', is_in_stock: 'stock'
  };
  // A column whose cells link to `edit.php?…&<id>=<term>` filters the list
  // by a taxonomy: that is how WordPress renders every taxonomy column.
  function looksLikeTaxonomy(id) {
    var tr = table.querySelector('tbody > tr:not(.no-items)');
    var td = tr && tr.querySelector('.column-' + id);
    if (!td) return false;
    var a = td.querySelector('a[href*="edit.php"]');
    return !!(a && (a.getAttribute('href') || '').indexOf(id + '=') !== -1);
  }
  var META_TRACK = { author: '150px', date: '160px', comments: '64px', tax: '128px', sku: '110px', price: '120px', stock: '116px', generic: 'minmax(96px, .6fr)' };
  // Room each column needs before the title starts to suffer (px).
  var META_MIN = { author: 120, date: 150, comments: 64, tax: 112, sku: 96, price: 104, stock: 112, generic: 110 };
  // Priority: 1 always, 2 kept while there is room, 3 the first to go. The
  // first taxonomy column is the primary classification and rises to 2.
  var META_PRIO = { date: 1, author: 2, price: 2, stock: 2, sku: 3, tax: 3, comments: 3, generic: 3 };
  // `dy-order-handle` is our own drag column (PostOrdering), not data.
  var META_SKIP = { cb: 1, title: 1, name: 1, username: 1, thumb: 1, 'dy-order-handle': 1 };
  var META_COLS = (function () {
    if (KIND !== 'post') return [];
    var out = [];
    table.querySelectorAll('thead tr:first-child th, thead tr:first-child td').forEach(function (th) {
      var id = th.id || '';
      if (!id) { th.className.split(/\s+/).forEach(function (c) { if (!id && c.indexOf('column-') === 0) id = c.slice(7); }); }
      if (!id || META_SKIP[id] || th.classList.contains('check-column') || th.classList.contains('column-primary')) return;
      var kind = META_KIND[id] || (id.indexOf('taxonomy-') === 0 || looksLikeTaxonomy(id) ? 'tax' : 'generic');
      var prio = META_PRIO[kind] || 3;
      if (kind === 'tax' && !out.some(function (c) { return c.kind === 'tax'; })) prio = 2;
      out.push({ id: id, cls: 'column-' + id, th: th, kind: kind, prio: prio, min: META_MIN[kind] || 110, track: META_TRACK[kind] || META_TRACK.generic, hidden: th.classList.contains('hidden') });
    });
    return out;
  })();
  function metaLegacyClass(kind) {
    return { author: 'dy-trow__author', tax: 'dy-trow__cat', date: 'dy-trow__date', comments: 'dy-trow__count' }[kind] || 'dy-trow__gcell';
  }
  // Grid of the post template = fixed leading tracks + one track per meta
  // column currently shown. Recomputed whenever a column is hidden or the
  // viewport crosses 1200px (an inline custom property beats the media
  // queries, so the CSS cannot do it alone).
  var MQ_MD = window.matchMedia ? window.matchMedia('(max-width: 1199px)') : null;
  // Columns the user (or Screen Options) keeps, then a width budget: the
  // title needs 240px; when the remaining columns do not fit, the lowest
  // priority ones step aside, rightmost first (they stay one gear click
  // away). WooCommerce products declare eleven columns: showing them all
  // crushed the title to three letters.
  function metaVisible(col, listEl) {
    return !(listEl && listEl.__hiddenCols && listEl.__hiddenCols[col.id]);
  }
  function applyMetaGrid(listEl) {
    if (KIND !== 'post' || !listEl) return;
    var kept = META_COLS.filter(function (c) { return metaVisible(c, listEl); });
    var width = listEl.clientWidth || (document.documentElement.clientWidth - 300) || 1100;
    var budget = function (cols) {
      var fixed = 26 + 26 + 240 + 36 + (cols.length + 2) * 12;   // leading tracks, title, padding, gaps
      return cols.reduce(function (n, c) { return n + c.min; }, fixed);
    };
    var shown = kept.slice();
    [3, 2].forEach(function (p) {
      for (var i = shown.length - 1; i >= 0 && budget(shown) > width; i--) {
        if (shown[i].prio === p) shown.splice(i, 1);
      }
    });
    var on = {};
    shown.forEach(function (c) { on[c.id] = true; });
    var tracks = ['26px', '26px', 'minmax(0, 1.5fr)'];
    META_COLS.forEach(function (c) {
      listEl.querySelectorAll('[data-col="' + c.id + '"]').forEach(function (cell) { cell.hidden = !on[c.id]; });
      if (on[c.id]) tracks.push(c.track);
    });
    listEl.style.setProperty('--dya-grid', tracks.join(' '));
  }

  // Column sorting for HIERARCHICAL post types (Pages, CPT): we do not delegate
  // to the server (WP would flatten the hierarchy and break pagination
  // by sub-trees). We reorder the model client-side, RECURSIVELY:
  // each sibling group (at every level) is sorted by key, while the
  // parent→child nesting stays intact. Persists across filter changes
  // (re-applied on every rebuild). { key:null } = default order.
  var treeSort = { key: null, dir: 'asc' };

  // ---------------------------------------------------------------------
  // Icons (inline SVG, stroke = currentColor).
  // ---------------------------------------------------------------------
  var ICON = {
    edit:    '<path d="M17 3l4 4-12 12H5v-4Z"/>',
    inline:  '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
    view:    '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    trash:   '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/>',
    untrash: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8m0-4v4h4"/>',
    duplicate: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
    bricks:  '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
    index:   '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    plugin:  '<path d="M10 3h4a1 1 0 0 1 1 1v1.5a2 2 0 1 0 4 0V5h.5a1 1 0 0 1 1 1v3.5a2 2 0 1 0 0 4V17a1 1 0 0 1-1 1h-3.5a2 2 0 1 0-4 0H4a1 1 0 0 1-1-1v-3.5a2 2 0 1 0 0-4V6a1 1 0 0 1 1-1h5Z"/>',
    gear:    '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8 2 2 0 1 1-2.8 2.8 1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5 2 2 0 1 1-4 0 1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3 2 2 0 1 1-2.8-2.8 1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1 2 2 0 1 1 0-4 1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8 2 2 0 1 1 2.8-2.8 1.7 1.7 0 0 0 1.8.3 1.7 1.7 0 0 0 1-1.5 2 2 0 1 1 4 0 1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3 2 2 0 1 1 2.8 2.8 1.7 1.7 0 0 0-.3 1.8 1.7 1.7 0 0 0 1.5 1 2 2 0 1 1 0 4 1.7 1.7 0 0 0-1.5 1z" stroke-linejoin="round"/>',
    list:    '<path d="M4 6h16M4 12h16M4 18h16"/>',
    layout:  '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/>',
    grip:    '<circle cx="1.5" cy="2" r="1"/><circle cx="4.5" cy="2" r="1"/><circle cx="1.5" cy="7" r="1"/><circle cx="4.5" cy="7" r="1"/><circle cx="1.5" cy="12" r="1"/><circle cx="4.5" cy="12" r="1"/>',
    chevUp:  '<path d="M6 15l6-6 6 6"/>',
    chevDown:'<path d="M6 9l6 6 6-6"/>',
    sort:    '<path d="M8 9l4-4 4 4"/><path d="M8 15l4 4 4-4"/>',
    check:   '<path d="M20 6 9 17l-5-5"/>',
    close:   '<path d="M18 6 6 18M6 6l12 12"/>',
    dots:    '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
    home:    '<path d="M3 11l9-8 9 8v10a2 2 0 0 1-2 2h-4v-7H10v7H6a2 2 0 0 1-2-2V11Z"/>',
    search:  '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    x:       '<path d="M6 6l12 12M18 6L6 18"/>',
    // Plugins list (plugins.php).
    power:   '<path d="M12 3v8"/><path d="M6.3 6.3a8 8 0 1 0 11.4 0"/>',
    update:  '<path d="M12 19V6"/><path d="m6 12 6-6 6 6"/>',
    info:    '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>'
  };
  function svg(paths, vb) {
    return '<svg viewBox="' + (vb || '0 0 24 24') + '" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">' + paths + '</svg>';
  }

  // Maps an action key/label to a known icon, falling back to 'plugin'.
  function iconForAction(key, label) {
    var k = (key || '').toLowerCase();
    var t = (label || '').toLowerCase();
    function has(s) { return k.indexOf(s) !== -1 || t.indexOf(s) !== -1; }
    if (has('editinline') || k === 'inline' || has('quick')) return ICON.inline;
    if (k === 'edit' || (has('edit') && !has('bricks'))) return ICON.edit;
    if (has('untrash') || has('restore')) return ICON.untrash;
    if (has('trash') || has('delete') || has('remove') || has('spam')) return ICON.trash;
    if (has('view') || has('preview') || has('voir') || has('aperçu') || has('aperc')) return ICON.view;
    if (has('clone') || has('duplicate') || has('dupli')) return ICON.duplicate;
    if (has('bricks')) return ICON.bricks;
    if (has('index') || has('rank') || has('seo')) return ICON.index;
    return ICON.plugin;
  }
  // "Primary" action (Edit)? — strict match to avoid catching
  // 'edit_with_bricks', 'editinline', etc.
  function isPrimary(key, label) {
    var k = (key || '').toLowerCase(), t = (label || '').toLowerCase();
    return k === 'edit' || t === 'modifier' || t === 'edit' || t === 'éditer' || t === 'modifier l’élément';
  }
  function isDanger(key, label) {
    var k = (key || '').toLowerCase(), t = (label || '').toLowerCase();
    return k.indexOf('trash') !== -1 || k.indexOf('delete') !== -1 || k.indexOf('spam') !== -1
        || t.indexOf('corbeille') !== -1 || t.indexOf('trash') !== -1 || t.indexOf('supprim') !== -1;
  }
  function isQuickEdit(key, label) {
    var k = (key || '').toLowerCase(), t = (label || '').toLowerCase();
    return k.indexOf('inline') !== -1 || t.indexOf('rapide') !== -1 || t.indexOf('quick') !== -1;
  }
  // Core action (not a plugin) → no source badge.
  function isCore(key) {
    return ['edit', 'inline', 'view', 'trash', 'untrash', 'delete'].indexOf((key || '').toLowerCase()) !== -1;
  }

  // ---------------------------------------------------------------------
  // 1. Model extraction from the native table.
  // ---------------------------------------------------------------------
  function el(html) { var d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstChild; }
  function txt(node) { return node ? node.textContent.replace(/\s+/g, ' ').trim() : ''; }
  // Accessible name of a row checkbox: WordPress's own hidden label
  // ("Select Hello world!"), else the row title. Our clones had none
  // (accessibility audit, 21/10/2026).
  function checkName(nativeCb, title) {
    var lab = nativeCb && nativeCb.id ? document.querySelector('label[for="' + nativeCb.id + '"]') : null;
    return txt(lab) || title || '';
  }
  function nameCheck(cb, nativeCb, title) {
    var n = checkName(nativeCb, title);
    if (n) cb.setAttribute('aria-label', n);
    return cb;
  }

  function rowId(tr) {
    if (tr.id) {
      var m = tr.id.match(/-(\d+)$/);
      if (m) return parseInt(m[1], 10);
    }
    var cb = tr.querySelector('.check-column input[type=checkbox], td.column-cb input[type=checkbox]');
    if (cb && cb.value) { var v = parseInt(cb.value, 10); if (v) return v; }
    return 0;
  }
  function primaryCell(tr) {
    var cells = tr.children, i;
    for (i = 0; i < cells.length; i++) { if (cells[i].querySelector(':scope > .row-actions, :scope .row-actions')) return cells[i]; }
    for (i = 0; i < cells.length; i++) { if (cells[i].querySelector('.row-title')) return cells[i]; }
    for (i = 0; i < cells.length; i++) {
      var c = cells[i];
      if (c.classList.contains('check-column') || c.classList.contains('column-cb')) continue;
      return c;
    }
    return null;
  }
  function titleLink(cell) {
    if (!cell) return null;
    return cell.querySelector('a.row-title') || cell.querySelector('strong > a') || cell.querySelector('a');
  }
  function statusOf(tr) {
    var m = (tr.className || '').match(/status-([a-z_-]+)/);
    return m ? m[1] : '';
  }
  // Normalizes a label for deduplication: lowercase, no qualifier in
  // parentheses ("Duplicate (WordPress)" == "Duplicate"), whitespace collapsed.
  function normLabel(s) {
    return (s || '').toLowerCase().replace(/\([^)]*\)/g, '').replace(/\s+/g, ' ').trim();
  }
  function actionsOf(cell) {
    var out = [], seen = {};
    if (!cell) return out;
    // Quick Edit is rendered as <button class="editinline"> (recent WP) or as
    // <a> (older WP) → we read both.
    var links = cell.querySelectorAll('.row-actions a, .row-actions button');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      var span = a.closest('span');
      var key = span ? (span.className || '').trim().split(/\s+/)[0] : '';
      var label = txt(a);
      if (!label) continue;
      var norm = normLabel(label);
      if (seen[norm]) continue;           // two actions with the same intent → keep one
      seen[norm] = true;
      out.push({ key: key, label: label, node: a, core: isCore(key) });
    }
    return out;
  }
  // Texte d'une cellule, débarrassé de ce que WordPress réserve aux lecteurs
  // d'écran. Une page sans auteur rend « — » suivi d'un libellé masqué : la
  // cellule se lisait « —(aucun auteur ou autrice) » dans la vue repensée
  // (audit du banc FSE, 14/09/2026). Vaut aussi pour date, rôle et compteurs.
  function colText(tr, colClass) {
    var c = tr.querySelector('.' + colClass);
    if (!c) return '';
    if (!c.querySelector('.screen-reader-text, .hidden')) return txt(c);
    var clone = c.cloneNode(true);
    clone.querySelectorAll('.screen-reader-text, .hidden').forEach(function (e) { e.remove(); });
    return txt(clone);
  }
  // .column-date = "Published<br>2026/05/26 at 14:22" → <br> does not produce a
  // line break in textContent; we split on <br> and keep the
  // segment that contains a date (digits).
  function dateText(tr) {
    var c = tr.querySelector('.column-date');
    if (!c) return '';
    var parts = c.innerHTML.split(/<br\s*\/?>/i).map(function (h) {
      var d = document.createElement('div'); d.innerHTML = h; return txt(d);
    }).filter(Boolean);
    var withDigit = parts.filter(function (p) { return /\d/.test(p); });
    return withDigit[0] || parts[parts.length - 1] || '';
  }

  function buildModel() {
    var trs = table.querySelectorAll('tbody > tr');
    var rows = [];
    for (var i = 0; i < trs.length; i++) {
      var tr = trs[i];
      // Skip service rows (no-items, inline-edit, hidden).
      if (tr.classList.contains('no-items') || tr.classList.contains('inline-edit-row') || tr.id === 'inline-edit' || tr.id === 'bulk-edit') continue;
      var id = rowId(tr);
      if (id <= 0) continue;   // service rows / templates
      var cell = primaryCell(tr);
      var link = titleLink(cell);
      var depth = (HIER && HIER[id]) ? HIER[id].depth : 0;
      var parent = (HIER && HIER[id]) ? HIER[id].parent : 0;
      rows.push({
        tr: tr,
        id: id,
        title: link ? txt(link) : txt(cell),
        editHref: link ? link.getAttribute('href') : '',
        status: statusOf(tr),
        author: colText(tr, 'column-author'),
        cat: CLASSCOL ? colText(tr, CLASSCOL.cls) : '',
        cells: (function () {
          var m = {};
          META_COLS.forEach(function (c) {
            var td = tr.querySelector('.' + c.cls);
            var text = c.kind === 'date' ? dateText(tr) : colText(tr, c.cls);
            if (c.kind === 'comments' && td) {
              // WordPress prints "—" + a hidden pending count: keep the
              // approved count only, or the dash.
              var ap = td.querySelector('.comment-count-approved');
              text = ap ? txt(ap) : '\u2014';
            }
            if (c.kind === 'tax' && text === '\u2014') text = '';
            m[c.id] = { text: text, html: td ? td.innerHTML : '' };
          });
          return m;
        })(),
        date: dateText(tr),
        role: colText(tr, 'column-role'),
        count: colText(tr, 'column-posts') || colText(tr, 'column-comments'),
        checkbox: tr.querySelector('.check-column input, td.column-cb input'),
        actions: actionsOf(cell),
        depth: depth,
        parent: parent,
        isHome: false
      });
    }
    return rows;
  }

  // ---------------------------------------------------------------------
  // 2. Tree structure: compute rails (tee/ell/v/first) per row.
  // ---------------------------------------------------------------------
  function computeTree(rows) {
    var n = rows.length, i, L2;
    // isLastChild: no following sibling at the same depth before a shallower depth.
    var isLast = new Array(n);
    for (i = 0; i < n; i++) {
      var d = rows[i].depth, last = true;
      for (var j = i + 1; j < n; j++) {
        if (rows[j].depth < d) break;
        if (rows[j].depth === d) { last = false; break; }
      }
      isLast[i] = last;
    }
    var lastIdxAtDepth = {};
    for (i = 0; i < n; i++) {
      var dep = rows[i].depth;
      var rails = [];
      if (dep > 0) {
        // ancestor columns 0..dep-2 (depths 1..dep-1)
        for (L2 = 1; L2 <= dep - 1; L2++) {
          var anc = lastIdxAtDepth[L2];
          rails.push((anc != null && !isLast[anc]) ? 'v' : 'blank');
        }
        // node column (dep-1)
        var conn = isLast[i] ? 'ell' : 'tee';
        var first = (i > 0 && rows[i - 1].depth === dep - 1);
        rails.push(first ? (conn = conn + ' first') : conn);
      }
      rows[i]._rails = rails;
      lastIdxAtDepth[dep] = i;
    }
  }

  // ---------------------------------------------------------------------
  // 2bis. Recursive tree sort (hierarchical post types, client-side).
  // ---------------------------------------------------------------------
  // Maps a native column id to the model field.
  function sortField(key) {
    if (key === 'comments' || key === 'posts') return 'count';
    if (key === 'name') return 'title';
    return key; // title | date | author | count…
  }
  // Sortable date value extracted from the native label ("Published 2026/05/26 at
  // 14:22" → 202605261422). WP renders admin dates as Y/m/d → digit order is
  // monotonic; fallback to Date.parse, then 0.
  function dateVal(s) {
    s = s || '';
    var m = s.match(/(\d{4})\D+(\d{1,2})\D+(\d{1,2})(?:\D+(\d{1,2})\D+(\d{1,2}))?/);
    if (m) return ((((+m[1]) * 100 + +m[2]) * 100 + +m[3]) * 100 + (+m[4] || 0)) * 100 + (+m[5] || 0);
    var t = Date.parse(s);
    return isNaN(t) ? 0 : t;
  }
  function cmpKey(a, b, key) {
    if (key === 'date') return dateVal(a.date) - dateVal(b.date);
    var f = sortField(key), av = a[f], bv = b[f];
    if (a.cells && a.cells[key]) { av = a.cells[key].text; bv = (b.cells && b.cells[key]) ? b.cells[key].text : ''; }
    var an = parseFloat(av), bn = parseFloat(bv);
    if (!isNaN(an) && !isNaN(bn) && /^\s*[\d.,\s]+$/.test(String(av)) && /^\s*[\d.,\s]+$/.test(String(bv))) return an - bn;
    av = String(av == null ? '' : av).toLowerCase();
    bv = String(bv == null ? '' : bv).toLowerCase();
    return av < bv ? -1 : (av > bv ? 1 : 0);
  }
  // Reorders `rows` (WP pre-order) by sorting EACH sibling group by `key`/`dir`,
  // nesting preserved. Stable sort (ties keep their WP order).
  function sortTree(rows, key, dir) {
    var present = {};
    rows.forEach(function (r) { present[r.id] = true; });
    var kids = {}; // parentId (0 = root) -> [rows], in WP order
    rows.forEach(function (r) {
      var p = (r.parent && present[r.parent]) ? r.parent : 0;
      (kids[p] = kids[p] || []).push(r);
    });
    var sign = dir === 'desc' ? -1 : 1;
    Object.keys(kids).forEach(function (p) {
      var arr = kids[p];
      arr.forEach(function (r, i) { r.__si = i; });
      arr.sort(function (a, b) {
        var c = cmpKey(a, b, key);
        return c === 0 ? (a.__si - b.__si) : sign * c;   // stable, never reversed on ties
      });
      arr.forEach(function (r) { delete r.__si; });
    });
    var out = [];
    (function walk(list) { (list || []).forEach(function (r) { out.push(r); walk(kids[r.id]); }); })(kids[0]);
    if (out.length !== rows.length) { // safety net: cycles/orphans → append the rest in WP order
      var seen = {}; out.forEach(function (r) { seen[r.id] = true; });
      rows.forEach(function (r) { if (!seen[r.id]) out.push(r); });
    }
    return out;
  }

  // ---------------------------------------------------------------------
  // 3. Rendering the reimagined markup.
  // ---------------------------------------------------------------------
  // Short label shown in the chip: we cut before a separator
  // (":", "—", "|", "·"); the full label stays in the tooltip,
  // and CSS truncates with an ellipsis beyond a max width.
  function shortLabel(s) {
    var t = (s || '').split(/\s+[:–—·|]\s+/)[0].trim();
    return t || s;
  }
  function chip(act) {
    // An action may pin its own semantics (plugins list: update = primary,
    // activate = primary when no update, explicit icons); otherwise the
    // key/label heuristics decide.
    var primary = act.primary != null ? !!act.primary : isPrimary(act.key, act.label);
    var danger  = act.danger  != null ? !!act.danger  : isDanger(act.key, act.label);
    var cls = 'dy-tchip' + (primary ? ' dy-tchip--primary' : '') + (danger ? ' dy-tchip--danger' : '');
    var src = act.core ? '' : '<span class="dy-tchip__src"></span>';
    var short = shortLabel(act.label);
    var b = el('<button type="button" class="' + cls + '" data-key="' + escAttr(act.key || '') + '" data-tip="' + escAttr(act.tip || act.label) + '">' +
      src + svg(act.icon || iconForAction(act.key, act.label)) + '<span class="dy-tchip__label">' + escHtml(short) + '</span></button>');
    b.__action = act;
    return b;
  }
  function escHtml(s) { return (s || '').replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  function escAttr(s) { return escHtml(s).replace(/'/g, '&#39;'); }

  function gripCell(idx, total) {
    var g = el('<div class="dy-trow__grip"></div>');
    var up = el('<button type="button" class="dy-trow__grip-chev" data-dir="up" data-tip="' + escAttr(L.moveUp || 'Move up') + '">' + svg(ICON.chevUp) + '</button>');
    // A mouse-only drag grip, not a button: the chevrons are the keyboard
    // and screen-reader way to move a row, and now have the 24px targets
    // (audit of 21/10/2026).
    var bar = el('<span class="dy-trow__grip-bar" aria-hidden="true" data-tip="' + escAttr(L.reorder || 'Reorder') + '">' + svg(ICON.grip, '0 0 6 14') + '</span>');
    var dn = el('<button type="button" class="dy-trow__grip-chev" data-dir="down" data-tip="' + escAttr(L.moveDown || 'Move down') + '">' + svg(ICON.chevDown) + '</button>');
    g.appendChild(up); g.appendChild(bar); g.appendChild(dn);
    return g;
  }

  function renderRow(r, idx, total, isTree) {
    var row = el('<div class="dy-trow"></div>');
    row.setAttribute('data-id', r.id);
    if (isTree) row.setAttribute('data-depth', r.depth);
    row.__model = r;

    if (ORDERABLE && isTree && !treeSort.key) row.appendChild(gripCell(idx, total));

    // checkbox
    var ck = el('<div class="dy-trow__check"></div>');
    var cb = nameCheck(el('<input type="checkbox">'), r.checkbox, r.title);
    cb.value = r.id; if (r.checkbox && r.checkbox.checked) cb.checked = true;
    cb.addEventListener('change', function () { if (r.checkbox) { r.checkbox.checked = cb.checked; } row.classList.toggle('is-selected', cb.checked); refreshBulk(); });
    ck.appendChild(cb); row.appendChild(ck);

    // status (posts only)
    if (KIND === 'post') {
      var stKey = r.status || 'publish';
      var stLabel = ST[stKey] || stKey;
      var st = el('<div class="dy-trow__status"><span class="dy-tstatus dy-tstatus--' + escAttr(stKey) + '" data-tip="' + escAttr(stLabel) + '"><span class="dy-tstatus__dot"></span></span></div>');
      row.appendChild(st);
    }

    // title / page (tree)
    if (isTree) {
      var page = el('<div class="dy-trow__page"></div>');
      var tree = el('<span class="dy-ttree"></span>');
      (r._rails || []).forEach(function (rk) {
        var cls = 'dy-ttree__rail';
        if (rk === 'v') cls += ' dy-ttree__rail--v';
        else if (rk.indexOf('tee') === 0) cls += ' dy-ttree__rail--tee' + (rk.indexOf('first') !== -1 ? ' dy-ttree__rail--first' : '');
        else if (rk.indexOf('ell') === 0) cls += ' dy-ttree__rail--ell' + (rk.indexOf('first') !== -1 ? ' dy-ttree__rail--first' : '');
        tree.appendChild(el('<span class="' + cls + '"></span>'));
      });
      page.appendChild(tree);
      var hasChildren = HIER && Object.keys(HIER).some(function (k) { return HIER[k].parent === r.id; });
      var ex = el('<button type="button" class="dy-trow__expand" data-state="' + (hasChildren ? 'expanded' : 'leaf') + '"' +
        (hasChildren ? ' data-tip="' + escAttr(L.collapse || 'Collapse') + '"' : '') + '>' + svg(ICON.chevDown) + '</button>');
      page.appendChild(ex);
      var pt = el('<span class="dy-trow__page-text">' + escHtml(r.title) + '</span>');
      page.appendChild(pt);
      row.appendChild(page);
    } else {
      var ti = el('<div class="dy-trow__title"><span class="dy-trow__title-text">' + escHtml(r.title) + '</span></div>');
      row.appendChild(ti);
    }

    // meta columns according to kind
    if (KIND === 'post') {
      META_COLS.forEach(function (c) {
        var v = (r.cells && r.cells[c.id]) || { text: '', html: '' };
        var cell = el('<div class="dy-trow__cell dy-trow__cell--' + escAttr(c.kind) + ' ' + metaLegacyClass(c.kind) + '" data-col="' + escAttr(c.id) + '"></div>');
        if (c.kind === 'generic' || c.kind === 'price' || c.kind === 'stock') {
          // Native HTML kept (price markup, stock badge, plugin cells).
          var val = el('<span class="dy-cell-value"></span>');
          val.innerHTML = v.html;
          val.querySelectorAll('.screen-reader-text, .hidden, .row-actions').forEach(function (e) { e.remove(); });
          if (v.text.length > 18) cell.setAttribute('title', v.text);
          cell.appendChild(val);
        } else {
          cell.textContent = v.text;
          if (c.kind === 'tax' && v.text.length > 18) cell.setAttribute('title', v.text);
        }
        row.appendChild(cell);
      });
    } else if (KIND === 'user') {
      row.appendChild(el('<div class="dy-trow__author dy-trow__role">' + escHtml(r.role) + '</div>'));
    } else if (KIND === 'term') {
      row.appendChild(el('<div class="dy-trow__count">' + escHtml(r.count) + '</div>'));
    }

    // action drawer
    var drawer = el('<div class="dy-trow__drawer"></div>');
    var core = [], ext = [];
    r.actions.forEach(function (a) { (a.core ? core : ext).push(a); });
    core.forEach(function (a) { drawer.appendChild(chip(a)); });
    if (core.length && ext.length) drawer.appendChild(el('<span class="dy-tchip__sep"></span>'));
    ext.forEach(function (a) { drawer.appendChild(chip(a)); });
    row.appendChild(drawer);

    // click navigation (title / row) → edit + view transition
    var titleEl = row.querySelector('.dy-trow__title-text, .dy-trow__page-text');
    if (titleEl && r.editHref) {
      titleEl.style.cursor = 'pointer';
      titleEl.addEventListener('click', function (e) { e.stopPropagation(); navigateEdit(r, titleEl); });
    }
    return row;
  }

  // ---------------------------------------------------------------------
  // 4. Actions: proxy to the original native link.
  // ---------------------------------------------------------------------
  function runAction(act, row) {
    if (!act || !act.node) return;
    if (isQuickEdit(act.key, act.label)) {
      // Quick Edit INTEGRATED into the reimagined view (posts): we reuse the
      // native inlineEditPost engine (save + nonce + all fields →
      // zero data loss) but the UI is styled by us. Falls back to
      // the native editor if the engine is unavailable (or for non-post types).
      if (KIND === 'post' && row && window.inlineEditPost &&
          typeof window.inlineEditPost.edit === 'function') {
        openQuickEdit(act, row);
      } else if (KIND === 'term' && row && window.inlineEditTax &&
          typeof window.inlineEditTax.edit === 'function') {
        openQuickEditTerm(act, row);
      } else {
        setMode('native', function () { try { act.node.click(); } catch (e) { location.href = act.node.href; } });
      }
      return;
    }
    try { act.node.click(); } catch (e) { if (act.node.href) location.href = act.node.href; }
  }

  // ---------------------------------------------------------------------
  // 4bis. Inline Quick Edit — integrated into the reimagined row.
  // ---------------------------------------------------------------------
  // The row stays in place and "pushes" a panel below it; the title
  // becomes an editable field in situ. We delegate persistence to the
  // native `inlineEditPost` engine: it builds a pre-filled #edit-{id} form
  // (hidden in the native table) with ALL fields + the nonce. We expose
  // a curated subset (title, slug, status, date), synced back to that
  // form before the native save → unexposed fields keep their original
  // value (zero data loss).
  var qeId = null, qeState = null;
  // One-shot: suppresses the row entrance animation on the next rebuild
  // (Quick Edit close → no flash of the entire list re-entering).
  var suppressRowAnimOnce = false;
  function qeFieldOf(form, name) { return form.querySelector('[name="' + name + '"]'); }
  function qeSet(form, name, value) { var f = qeFieldOf(form, name); if (f != null) f.value = value; }
  function pad2(s) { s = String(s == null ? '' : s); return s.length < 2 ? ('0' + s) : s; }

  // Native label of a field (already localized by WP); provided fallback.
  function nativeLabel(f, fallback) {
    if (!f) return fallback;
    var lab = f.closest('label');
    if (lab) {
      var t = lab.querySelector('.title');
      if (t && t.textContent.trim()) return t.textContent.trim();
      var txt = lab.textContent.replace(/\s+/g, ' ').trim();
      if (txt) return txt;
    }
    var leg = f.closest('fieldset') && f.closest('fieldset').querySelector('legend .title, legend');
    return (leg && leg.textContent.trim()) || fallback;
  }

  function openQuickEdit(act, row) {
    var id = row.__model ? row.__model.id : 0;
    if (!id) return;
    if (qeId != null && qeId !== id) closeQuickEdit();
    if (qeId === id) { closeQuickEdit(); return; } // re-click = toggle closed

    try { window.inlineEditPost.edit(id); } catch (e) {}
    var form = document.getElementById('edit-' + id);
    if (!form) { setMode('native', function () { try { act.node.click(); } catch (e) {} }); return; }
    qeId = id;
    row.classList.add('is-qe-open');

    // We read the native #edit-{id} form (already built + pre-filled by
    // inlineEditPost) and mirror EACH field into styled controls.
    // `commits` = UI→native functions run on save; `relocated` = complex
    // native nodes (category tree) moved here and put back before the
    // native save (correct serialization, zero data loss).
    var commits = [], relocated = [];

    // (1) Editable title in place (mirror).
    var titleEl = row.querySelector('.dy-trow__page-text, .dy-trow__title-text');
    var fTitle = qeFieldOf(form, 'post_title');
    var titleInput = el('<input type="text" class="dy-qe__title" aria-label="' + escAttr(L.title || 'Title') + '">');
    titleInput.value = fTitle ? fTitle.value : (row.__model.title || '');
    if (titleEl) { titleEl.style.display = 'none'; titleEl.parentNode.insertBefore(titleInput, titleEl.nextSibling); }
    if (fTitle) commits.push(function () { fTitle.value = titleInput.value; });

    // (2) Panel.
    var panel = el('<div class="dy-qe" data-for="' + id + '"></div>');
    var inner = el('<div class="dy-qe__inner"></div>');
    var grid = el('<div class="dy-qe__grid"></div>');
    var checks = el('<div class="dy-qe__checks"></div>');

    function field(labelTxt, control, mod) {
      var lab = el('<label class="dy-qe__field' + (mod ? ' dy-qe__field--' + mod : '') + '"></label>');
      lab.appendChild(el('<span class="dy-qe__lab">' + escHtml(labelTxt) + '</span>'));
      lab.appendChild(control); return lab;
    }
    // Text/number
    function mText(name, fallback, opts) {
      var f = qeFieldOf(form, name); if (!f) return null;
      var inp = el('<input type="' + ((opts && opts.type) || 'text') + '" class="dy-qe__input" spellcheck="false">');
      inp.value = f.value;
      grid.appendChild(field(nativeLabel(f, fallback), inp, opts && opts.mod));
      commits.push(function () { f.value = inp.value; });
      return inp;
    }
    // Select (options cloned, already localized)
    function mSelect(name, fallback) {
      var f = qeFieldOf(form, name); if (!f || !f.options) return null;
      var sel = el('<select class="dy-qe__input dy-qe__select"></select>');
      [].forEach.call(f.options, function (o) {
        var op = el('<option></option>'); op.value = o.value;
        op.textContent = o.textContent.replace(/ /g, ' ').trim() || o.value;
        if (o.disabled) op.disabled = true; sel.appendChild(op);
      });
      sel.value = f.value;
      grid.appendChild(field(nativeLabel(f, fallback), sel));
      commits.push(function () { f.value = sel.value; });
      return sel;
    }
    // Checkbox (bottom group)
    function mCheck(name, fallback) {
      var f = qeFieldOf(form, name);
      if (!f || (f.type !== 'checkbox')) return null;
      var lab = el('<label class="dy-qe__check"></label>');
      var cb = el('<input type="checkbox" class="dy-qe__cbox">'); cb.checked = f.checked;
      lab.appendChild(cb); lab.appendChild(el('<span>' + escHtml(nativeLabel(f, fallback)) + '</span>'));
      checks.appendChild(lab);
      commits.push(function () { f.checked = cb.checked; });
      return cb;
    }

    // — Main fields — (same as the native Quick Edit, in this order)
    var slugInput = mText('post_name', L.slug || 'Slug');
    mSelect('post_author', L.author || 'Author');     // Author
    mSelect('post_parent', L.parent || 'Parent');     // Parent (hierarchical)
    var statusSel = mSelect('_status', L.status || 'Status');

    // Date + time (HTML5 fields bound to aa/mm/jj/hh/mn)
    var dateInput = null, timeInput = null;
    var aa = qeFieldOf(form, 'aa'), mm = qeFieldOf(form, 'mm'), jj = qeFieldOf(form, 'jj');
    if (aa && mm && jj) {
      var dwrap = el('<div class="dy-qe__datetime"></div>');
      dateInput = el('<input type="date" class="dy-qe__input">');
      dateInput.value = aa.value + '-' + pad2(mm.value) + '-' + pad2(jj.value);
      dwrap.appendChild(dateInput);
      var hh = qeFieldOf(form, 'hh'), mn = qeFieldOf(form, 'mn');
      if (hh && mn) {
        timeInput = el('<input type="time" class="dy-qe__input dy-qe__time">');
        timeInput.value = pad2(hh.value || '0') + ':' + pad2(mn.value || '0');
        dwrap.appendChild(timeInput);
      }
      grid.appendChild(field(L.date || 'Date', dwrap, 'date'));
      commits.push(function () {
        if (!dateInput.value) return;
        var p = dateInput.value.split('-');
        qeSet(form, 'aa', p[0]); qeSet(form, 'mm', p[1]); qeSet(form, 'jj', p[2]);
        if (timeInput && timeInput.value) { var t = timeInput.value.split(':'); qeSet(form, 'hh', t[0]); qeSet(form, 'mn', t[1]); }
      });
    }

    mText('menu_order', L.order || 'Order', { type: 'number', mod: 'sm' });   // Order
    mSelect('page_template', L.template || 'Template');                       // Template
    mText('post_password', L.password || 'Password', { mod: 'sm' });          // Password

    // Tags (posts): native textarea → mirror input.
    var fTags = form.querySelector('textarea[name^="tax_input"], textarea.tax_input_post_tag, textarea[name="tags_input"]');
    if (fTags) {
      var tg = el('<input type="text" class="dy-qe__input" spellcheck="false">'); tg.value = fTags.value;
      grid.appendChild(field(nativeLabel(fTags, L.tags || 'Tags'), tg, 'wide'));
      commits.push(function () { fTags.value = tg.value; });
    }

    // Categories (posts): we RELOCATE the native tree (then put it back before
    // save) — no re-implementation of the checklist, checked state preserved.
    var catBox = form.querySelector('.inline-edit-categories .cat-checklist, ul.cat-checklist');
    if (catBox) {
      relocated.push({ node: catBox, home: catBox.parentNode, next: catBox.nextSibling });
      var catLabelEl = form.querySelector('.inline-edit-categories-label, .inline-edit-categories .title');
      var catField = el('<div class="dy-qe__field dy-qe__field--wide"></div>');
      catField.appendChild(el('<span class="dy-qe__lab">' + escHtml((catLabelEl && catLabelEl.textContent.trim()) || L.categories || 'Categories') + '</span>'));
      var catWrap = el('<div class="dy-qe__cats"></div>'); catWrap.appendChild(catBox);
      catField.appendChild(catWrap); grid.appendChild(catField);
    }

    // — Checkboxes (bottom, full width) —
    mCheck('keep_private', L.private || 'Private');
    mCheck('comment_status', L.comments || 'Allow comments');
    mCheck('ping_status', L.pings || 'Allow pings');
    mCheck('sticky', L.sticky || 'Sticky');
    if (checks.children.length) grid.appendChild(checks);

    inner.appendChild(grid);
    var actions = el('<div class="dy-qe__actions"></div>');
    var cancelBtn = el('<button type="button" class="dy-qe__btn dy-qe__btn--ghost">' + escHtml(L.cancel || 'Cancel') + '</button>');
    var saveBtn = el('<button type="button" class="dy-qe__btn dy-qe__btn--save">' + svg(ICON.check) + '<span>' + escHtml(L.save || 'Save') + '</span></button>');
    actions.appendChild(cancelBtn); actions.appendChild(saveBtn);
    inner.appendChild(actions);
    panel.appendChild(inner);
    row.parentNode.insertBefore(panel, row.nextSibling);

    function restoreRelocated() {
      relocated.forEach(function (r) { if (r.home && !r.home.contains(r.node)) r.home.insertBefore(r.node, r.next); });
      relocated.length = 0;
    }
    qeState = { id: id, restore: restoreRelocated, panel: panel, row: row, engine: window.inlineEditPost };

    function save() {
      saveBtn.classList.add('is-busy'); saveBtn.disabled = true;
      commits.forEach(function (fn) { try { fn(); } catch (e) {} });
      restoreRelocated();                 // category tree re-injected into #edit-{id}
      watchSave(id);
      try { window.inlineEditPost.save(id); } catch (e) { closeQuickEdit({ saved: false }); }
    }
    saveBtn.addEventListener('click', save);
    cancelBtn.addEventListener('click', function () { closeQuickEdit(); });
    [titleInput, slugInput].forEach(function (inp) {
      if (!inp) return;
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); save(); }
        else if (e.key === 'Escape') { e.preventDefault(); closeQuickEdit(); }
      });
    });

    // Animated open + focus title.
    requestAnimationFrame(function () { panel.classList.add('is-open'); });
    titleInput.focus(); try { titleInput.select(); } catch (e) {}
  }

  // ---------------------------------------------------------------------
  // 4ter. TERM Quick Edit (edit-tags) — integrated into the reimagined view.
  // ---------------------------------------------------------------------
  // Same principle as openQuickEdit but wired to the native
  // `inlineEditTax` engine (Name + Slug fields). The view stays in reimagined
  // mode: we read the native #edit-{id} form (built by inlineEditTax), style it,
  // and delegate persistence to inlineEditTax.save (native nonce + AJAX).
  function openQuickEditTerm(act, row) {
    var id = row.__model ? row.__model.id : 0;
    if (!id) return;
    if (qeId != null && qeId !== id) closeQuickEdit();
    if (qeId === id) { closeQuickEdit(); return; } // re-click = toggle closed

    try { window.inlineEditTax.edit(id); } catch (e) {}
    var form = document.getElementById('edit-' + id);
    if (!form) { setMode('native', function () { try { act.node.click(); } catch (e) {} }); return; }
    qeId = id;
    row.classList.add('is-qe-open');

    var commits = [];
    var fName = qeFieldOf(form, 'name');
    var fSlug = qeFieldOf(form, 'slug');

    // Name editable in place (mirror), like the post title.
    var nameInput = null;
    var nameEl = row.querySelector('.dy-trow__page-text, .dy-trow__title-text, .dy-trow__name-text');
    if (nameEl && fName) {
      nameInput = el('<input type="text" class="dy-qe__title" aria-label="' + escAttr(nativeLabel(fName, L.title || 'Name')) + '">');
      nameInput.value = fName.value;
      nameEl.style.display = 'none';
      nameEl.parentNode.insertBefore(nameInput, nameEl.nextSibling);
      commits.push(function () { fName.value = nameInput.value; });
    }

    var panel = el('<div class="dy-qe" data-for="' + id + '"></div>');
    var inner = el('<div class="dy-qe__inner"></div>');
    var grid = el('<div class="dy-qe__grid"></div>');
    function field(labelTxt, control, mod) {
      var lab = el('<label class="dy-qe__field' + (mod ? ' dy-qe__field--' + mod : '') + '"></label>');
      lab.appendChild(el('<span class="dy-qe__lab">' + escHtml(labelTxt) + '</span>'));
      lab.appendChild(control); return lab;
    }
    // No in-place mirror → Name goes in the panel.
    if (!nameInput && fName) {
      var ni = el('<input type="text" class="dy-qe__input" spellcheck="false">'); ni.value = fName.value;
      grid.appendChild(field(nativeLabel(fName, L.title || 'Name'), ni, 'wide'));
      commits.push(function () { fName.value = ni.value; });
      nameInput = ni;
    }
    // Slug field.
    var slugInput = null;
    if (fSlug) {
      slugInput = el('<input type="text" class="dy-qe__input" spellcheck="false">'); slugInput.value = fSlug.value;
      grid.appendChild(field(nativeLabel(fSlug, L.slug || 'Slug'), slugInput, 'wide'));
      commits.push(function () { fSlug.value = slugInput.value; });
    }

    inner.appendChild(grid);
    var actions = el('<div class="dy-qe__actions"></div>');
    var cancelBtn = el('<button type="button" class="dy-qe__btn dy-qe__btn--ghost">' + escHtml(L.cancel || 'Cancel') + '</button>');
    var saveBtn = el('<button type="button" class="dy-qe__btn dy-qe__btn--save">' + svg(ICON.check) + '<span>' + escHtml(L.save || 'Save') + '</span></button>');
    actions.appendChild(cancelBtn); actions.appendChild(saveBtn);
    inner.appendChild(actions);
    panel.appendChild(inner);
    row.parentNode.insertBefore(panel, row.nextSibling);

    qeState = { id: id, restore: null, panel: panel, row: row, engine: window.inlineEditTax };

    function save() {
      saveBtn.classList.add('is-busy'); saveBtn.disabled = true;
      commits.forEach(function (fn) { try { fn(); } catch (e) {} });
      watchSave(id, 'tag-' + id);
      try { window.inlineEditTax.save(id); } catch (e) { closeQuickEdit({ saved: false }); }
    }
    saveBtn.addEventListener('click', save);
    cancelBtn.addEventListener('click', function () { closeQuickEdit(); });
    [nameInput, slugInput].forEach(function (inp) {
      if (!inp) return;
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); save(); }
        else if (e.key === 'Escape') { e.preventDefault(); closeQuickEdit(); }
      });
    });

    requestAnimationFrame(function () { panel.classList.add('is-open'); });
    if (nameInput) { nameInput.focus(); try { nameInput.select(); } catch (e) {} }
  }

  // Observes the native table: a successful save means the content row reappears
  // in place of #edit-{id} → we rebuild the reimagined view (row updated).
  function watchSave(id, contentId) {
    contentId = contentId || ('post-' + id); // term: 'tag-{id}'
    var tb = table.querySelector('tbody');
    if (!tb) { setTimeout(function () { closeQuickEdit({ saved: true }); }, 500); return; }
    var done = false;
    function finish(ok) { if (done) return; done = true; try { obs.disconnect(); } catch (e) {} closeQuickEdit({ saved: ok }); }
    var obs = new MutationObserver(function () {
      // success: the content row reappears and #edit-{id} disappears.
      if (document.getElementById(contentId) && !document.getElementById('edit-' + id)) finish(true);
    });
    obs.observe(tb, { childList: true, subtree: true });
    // safety net: if nothing happens after 8s (slow network / validation error),
    // revert (restore the original row) to avoid leaving the view broken.
    setTimeout(function () { finish(false); }, 8000);
  }

  function closeQuickEdit(opts) {
    opts = opts || {};
    if (qeId == null) return;
    qeId = null;
    var st = qeState; qeState = null;
    // Put the relocated native nodes (category tree) back into #edit-{id}
    // BEFORE the rebuild — otherwise rebuild() (which removes the view and
    // thus the panel) would destroy the native checklist and lose checked state.
    if (st && st.restore) { try { st.restore(); } catch (e) {} }

    var finalize = function () {
      if (!opts.saved) { try { var eng = (st && st.engine) || window.inlineEditPost; if (eng && eng.revert) eng.revert(); } catch (e) {} }
      suppressRowAnimOnce = true;   // no re-animation of the entire list after closing
      rebuild();
    };

    // Smooth close: collapse the panel (1fr→0fr, reverse of opening)
    // and restore the row to its normal state, THEN finalize (revert/rebuild).
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var panel = st && st.panel;
    if (panel && panel.classList.contains('is-open') && !reduce) {
      panel.classList.remove('is-open');
      panel.classList.add('is-closing');
      if (st.row) st.row.classList.remove('is-qe-open');
      var done = false;
      var fin = function () { if (done) return; done = true; finalize(); };
      panel.addEventListener('transitionend', function (e) { if (e.propertyName === 'grid-template-rows') fin(); });
      setTimeout(fin, 320); // safety net if transitionend does not fire
    } else {
      finalize();
    }
  }

  function navigateEdit(r, titleEl) {
    if (!r.editHref) return;
    // View Transition (Chrome): the morph links the clicked title to the title of
    // the edit screen (already named dy-vt-page-title in _view-transitions).
    // We transfer the name: remove it from the list <h1> (otherwise an invalid
    // duplicate) and assign it to the clicked title.
    try {
      if (document.startViewTransition) {
        var h1 = document.querySelector('.wrap > h1, h1.wp-heading-inline');
        if (h1) h1.style.viewTransitionName = 'none';
        titleEl.style.viewTransitionName = 'dy-vt-page-title';
      }
    } catch (e) {}
    location.href = r.editHref;
  }

  // ---------------------------------------------------------------------
  // 5. REST helpers.
  // ---------------------------------------------------------------------
  function post(url, body, cb) {
    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.rest.nonce },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (j) { if (cb) cb(j); })
      .catch(function () { if (cb) cb(null); });
  }

  // Refreshed when the selection changes (defined in build()).
  var refreshBulk = function () {};

  var currentMode = CFG.mode || 'reimagined';
  // Data rows of the native table (service rows left out, as in buildModel).
  function nativeRowCount() {
    var n = 0;
    table.querySelectorAll('tbody > tr').forEach(function (tr) {
      if (!tr.classList.contains('no-items') && !tr.classList.contains('inline-edit-row') && rowId(tr) > 0) n++;
    });
    return n;
  }
  // Native list for this page only: nothing is saved, the user's choice
  // stays "redesigned" for the next page.
  function fallbackToNative(reason) {
    if (!document.body.classList.contains('dy-tables-reimagined')) return;
    currentMode = 'native';
    document.body.classList.remove('dy-tables-reimagined');
    document.body.classList.add('dy-tables-native');
    var btn = document.querySelector('.dy-treimg__toggle');
    if (btn) {
      var p = toggleParts(true);
      var t = btn.querySelector('.dy-treimg__toggle-txt'), i = btn.querySelector('.dy-treimg__toggle-ico');
      if (t) t.textContent = p.txt;
      if (i) i.innerHTML = p.ico;
      btn.setAttribute('data-tip', p.tip); btn.setAttribute('title', p.tip);
    }
    setTimeout(function () { try { guardNativeColumns(); } catch (e) {} }, 0);
    if (window.console) console.warn('[Double Youpï] Native list shown on this page: ' + reason + '.');
  }
  function setMode(mode, after) {
    currentMode = mode;
    document.body.classList.toggle('dy-tables-reimagined', mode === 'reimagined');
    document.body.classList.toggle('dy-tables-native', mode === 'native');
    post(CFG.rest.mode, { signature: CFG.signature, mode: mode }, null);
    // When switching to native, the table becomes visible again: (re)calculate
    // the column guard (scroll rail + indicator) which needs to measure.
    if (mode === 'native') { setTimeout(function () { try { guardNativeColumns(); } catch (e) {} }, 0); }
    if (after) setTimeout(after, 30);
  }

  // =====================================================================
  //  Animated toggle button (native ↔ reimagined) — UNIQUE, shared by all
  //  screens (content + generic/Loco). A single persistent button that
  //  changes state IN PLACE, with: fluid width, accent (border+text) in
  //  reimagined mode, 360° border trace, and "wipe" text swap
  //  (cover 0→100% from the left, swap, retract 100→0% to the right).
  // =====================================================================
  // The button displays the CURRENT mode (not the target): in reimagined →
  // "Redesigned view" (with accent, see CSS), in native → "Native view"
  // (neutral). The action (switch to the other mode) is carried by the
  // tooltip. Label + color + mode are thus always consistent.
  function toggleParts(isNative) {
    return {
      ico: svg(isNative ? ICON.list : ICON.layout),
      txt: isNative ? (L.nativeView || 'Native view') : (L.redesignedView || 'Redesigned view'),
      tip: isNative ? (L.switchToReimagined || '') : (L.switchToNative || '')
    };
  }

  function flipToggle(btn) {
    if (btn.__flipping) return;
    btn.__flipping = true;
    var txt = btn.querySelector('.dy-treimg__toggle-txt');
    var ico = btn.querySelector('.dy-treimg__toggle-ico');
    var targetMode = document.body.classList.contains('dy-tables-native') ? 'reimagined' : 'native';
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduce) {
      setMode(targetMode);
      var p0 = toggleParts(targetMode === 'native');
      txt.textContent = p0.txt; ico.innerHTML = p0.ico; btn.setAttribute('data-tip', p0.tip); btn.setAttribute('title', p0.tip);
      btn.__flipping = false;
      return;
    }

    // Lock the starting width so it can be animated.
    var startW = btn.getBoundingClientRect().width;
    btn.style.width = startW + 'px';
    btn.classList.add('is-flipping');   // 360° border trace
    btn.classList.add('is-covering');   // wipe: 0 → 100% from the left

    var COVER = 230, REVEAL = 250;
    setTimeout(function () {
      // Under the cover: switch the mode (accent via body class) and
      // replace text + icon, then animate the width to its new value.
      setMode(targetMode);
      var p = toggleParts(targetMode === 'native');
      txt.textContent = p.txt; ico.innerHTML = p.ico; btn.setAttribute('data-tip', p.tip); btn.setAttribute('title', p.tip);

      btn.style.width = 'auto';
      var endW = btn.getBoundingClientRect().width;
      btn.style.width = startW + 'px';
      void btn.offsetWidth;            // reflow → anchors the start point
      btn.style.width = endW + 'px';   // CSS width transition

      // Reveal: the wipe retracts from 100 → 0% toward the RIGHT.
      btn.classList.remove('is-covering');
      btn.classList.add('is-revealing');
      setTimeout(function () {
        btn.classList.remove('is-revealing', 'is-flipping');
        btn.style.width = '';          // back to auto
        btn.__flipping = false;
      }, REVEAL);
    }, COVER);
  }

  // Creates the toggle bar (always visible, fixed position before the view) and
  // its animated button. Inserted just before `view` → same position in both
  // modes. Used by build() (content) AND buildGeneric() (Loco…).
  function buildSwitchbar(view) {
    var bar = el('<div class="dy-treimg-switchbar"></div>');
    var p = toggleParts(currentMode === 'native');
    var btn = el('<button type="button" class="dy-treimg__toggle" data-tip="' + escAttr(p.tip) + '" title="' + escAttr(p.tip) + '">' +
      '<span class="dy-treimg__toggle-ring" aria-hidden="true"></span>' +
      '<span class="dy-treimg__toggle-ico">' + p.ico + '</span>' +
      '<span class="dy-treimg__toggle-txt">' + escHtml(p.txt) + '</span>' +
      '<span class="dy-treimg__toggle-wipe" aria-hidden="true"></span>' +
      '</button>');
    btn.addEventListener('click', function () { flipToggle(btn); });
    bar.appendChild(btn);
    // Position: ABOVE the native filters, so the button occupies the same
    // location in both modes (native filters are hidden in reimagined mode →
    // otherwise the button would "drop" below them in native mode). Anchor:
    // the status tabs `.subsubsub`, then the list form; falls back to
    // "just before the view" for screens without these markers (terms, Loco).
    var wrap = table && table.closest('.wrap');
    var anchor = null;
    if (wrap) {
      anchor = wrap.querySelector(':scope > .subsubsub');
      if (!anchor) {
        var f = table.closest('form');
        if (f && f.parentNode === wrap) {
          anchor = f;                       // standard lists (posts/pages): form is a direct child of .wrap
        } else if (f) {
          // Taxonomies: the form (#posts-filter) lives in .col-wrap, not in
          // .wrap → anchor ABOVE the filters/search, inside the
          // form (first of .subsubsub / .search-box / .tablenav.top).
          anchor = f.querySelector(':scope > .subsubsub, :scope > .search-box, :scope > .tablenav.top') || f.firstElementChild;
        }
      }
    }
    if (anchor && anchor.parentNode) { anchor.parentNode.insertBefore(bar, anchor); }
    else { view.parentNode.insertBefore(bar, view); }
    return bar;
  }

  // ---------------------------------------------------------------------
  // 6. Reorder (chevrons + simple drag, no re-parenting in v1).
  // ---------------------------------------------------------------------
  function depthOf(row) { return parseInt(row.getAttribute('data-depth') || '0', 10); }
  function parentOf(row) { return row.__model ? (row.__model.parent || 0) : 0; }
  // Block = the row + its contiguous descendants (greater depth).
  function blockOf(row) {
    var block = [row], d = depthOf(row), n = row.nextElementSibling;
    while (n && n.classList && n.classList.contains('dy-trow') && depthOf(n) > d) { block.push(n); n = n.nextElementSibling; }
    return block;
  }
  // First rows of siblings (same depth AND same parent), in DOM order.
  function siblingFirsts(list, depth, parent) {
    var out = [];
    list.querySelectorAll('.dy-trow').forEach(function (r) { if (depthOf(r) === depth && parentOf(r) === parent) out.push(r); });
    return out;
  }
  // Persists the order of the affected siblings (menu_order), parent unchanged.
  function persistSiblings(list, depth, parent) {
    var sibs = siblingFirsts(list, depth, parent);
    var items = [];
    sibs.forEach(function (r, i) {
      var id = parseInt(r.getAttribute('data-id'), 10);
      if (id) items.push({ id: id, order: i + 1, parent: parent });
    });
    if (!items.length) return;
    post(CFG.rest.reorder, { post_type: SCREEN.postType, items: items }, function (j) {
      if (!j || !j.success) { window.alert(L.reorderFailed || 'Reorder failed. Please refresh.'); }
    });
  }

  // Arrow reordering: moves the block one step among its siblings.
  function moveRow(row, dir) {
    var list = row.closest('.dy-treimg__list'); if (!list) return;
    var depth = depthOf(row), parent = parentOf(row);
    var sibs = siblingFirsts(list, depth, parent);
    var idx = sibs.indexOf(row), target = dir === 'up' ? idx - 1 : idx + 1;
    if (target < 0 || target >= sibs.length) return;
    var block = blockOf(row), ref;
    if (dir === 'up') { ref = sibs[target]; }
    else { var nb = blockOf(sibs[target]); ref = nb[nb.length - 1].nextElementSibling; }
    block.forEach(function (r) { list.insertBefore(r, ref); });
    persistSiblings(list, depth, parent);
  }

  // ── Pointer drag & drop: floating ghost + dimmed origin + placeholder ──
  function startDrag(row, ev) {
    ev.preventDefault();
    var list = row.closest('.dy-treimg__list'); if (!list) return;
    var depth = depthOf(row), parent = parentOf(row);
    var block = blockOf(row);
    var moved = false;

    // floating badge (title) that follows the cursor
    var titleTxt = (row.querySelector('.dy-trow__page-text, .dy-trow__title-text') || {}).textContent || '';
    var ghost = el('<div class="dy-tdrag">' + svg(ICON.grip, '0 0 6 14') + '<span></span></div>');
    ghost.querySelector('span').textContent = titleTxt;
    document.body.appendChild(ghost);
    document.body.classList.add('dy-dragging');

    // dotted accent placeholder (block height) inserted at the current position
    var h = 0; block.forEach(function (r) { h += r.offsetHeight; });
    var ph = el('<div class="dy-trow__drop"></div>');
    ph.style.height = h + 'px';
    list.insertBefore(ph, block[0]);
    block.forEach(function (r) { r.classList.add('dy-trow--ghosted'); });

    function moveGhost(e) { ghost.style.left = (e.clientX + 14) + 'px'; ghost.style.top = (e.clientY + 6) + 'px'; }
    function onMove(e) {
      moveGhost(e);
      moved = true;
      var sibs = siblingFirsts(list, depth, parent).filter(function (r) { return block.indexOf(r) === -1; });
      var placed = false;
      for (var i = 0; i < sibs.length; i++) {
        var b = sibs[i].getBoundingClientRect();
        if (e.clientY < b.top + b.height / 2) { list.insertBefore(ph, sibs[i]); placed = true; break; }
      }
      if (!placed && sibs.length) {
        var lastBlock = blockOf(sibs[sibs.length - 1]);
        list.insertBefore(ph, lastBlock[lastBlock.length - 1].nextElementSibling);
      }
    }
    function onUp() {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
      document.body.classList.remove('dy-dragging');
      if (ghost.parentNode) ghost.remove();
      block.forEach(function (r) { list.insertBefore(r, ph); r.classList.remove('dy-trow--ghosted'); });
      if (ph.parentNode) ph.remove();
      if (moved) persistSiblings(list, depth, parent);
    }
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  }

  // ---------------------------------------------------------------------
  // 7. Drawer: dynamic L/R anchoring (Range measurement of the title text).
  // ---------------------------------------------------------------------
  var ENTER_LEFT = 0.42, ENTER_RIGHT = 0.52;
  function titleTextRight(row) {
    var span = row.querySelector('.dy-trow__page-text, .dy-trow__title-text');
    if (!span) return null;
    var tr;
    try { var rg = document.createRange(); rg.selectNodeContents(span); tr = rg.getBoundingClientRect().right; }
    catch (e) { tr = span.getBoundingClientRect().right; }
    var cellEl = row.querySelector('.dy-trow__page') || row.querySelector('.dy-trow__title') || row.querySelector('.dy-trow__plugin') || span;
    // Plugins: the name cell also carries the version chip and the
    // dependency pill → anchor the drawer after the widest of them.
    if (cellEl.classList && cellEl.classList.contains('dy-trow__plugin')) {
      [].forEach.call(cellEl.children, function (ch) { var r = ch.getBoundingClientRect(); if (r.width && r.right > tr) tr = r.right; });
    }
    return Math.min(tr, cellEl.getBoundingClientRect().right - 6);
  }
  function placeDrawer(row, side) {
    var rect = row.getBoundingClientRect();
    var drawer = row.querySelector('.dy-trow__drawer');
    if (!drawer) return;
    var dW = drawer.scrollWidth;
    // Rows with interactive controls on their right edge (plugins: the
    // auto-update switch + expand chevron) reserve that width so the drawer
    // never covers them (CSS: --dya-drawer-reserve on the row).
    var reserve = 0;
    try { reserve = parseFloat(getComputedStyle(row).getPropertyValue('--dya-drawer-reserve')) || 0; } catch (e) {}
    var maxX = Math.max(100, rect.width - dW - 14 - reserve);
    var x;
    if (side === 'left') {
      var trr = titleTextRight(row);
      x = (trr != null ? trr - rect.left + 12 : rect.width * 0.4);
      x = Math.min(x, maxX); x = Math.max(x, 100);
    } else { x = maxX; }
    row.style.setProperty('--drawer-x', x + 'px');
  }
  function updateSide(row, clientX, animate) {
    var rect = row.getBoundingClientRect();
    var ratio = (clientX - rect.left) / rect.width;
    var side = row.dataset.drawerSide || (ratio < 0.5 ? 'left' : 'right');
    if (ratio < ENTER_LEFT) side = 'left'; else if (ratio > ENTER_RIGHT) side = 'right';
    if (side === row.dataset.drawerSide && animate !== false) return;
    row.dataset.drawerSide = side;
    if (!animate) { row.querySelector('.dy-trow__drawer').style.transition = 'none'; placeDrawer(row, side); void row.offsetWidth; row.querySelector('.dy-trow__drawer').style.transition = ''; }
    else placeDrawer(row, side);
  }

  // lit tree path up to the parent (non-first children)
  function litPath(row, on) {
    var d = parseInt(row.getAttribute('data-depth') || '0', 10);
    if (!d) return;
    var col = d - 1, cur = row.previousElementSibling;
    while (cur && cur.classList && cur.classList.contains('dy-trow')) {
      var dp = parseInt(cur.getAttribute('data-depth') || '0', 10);
      if (dp < d) break;
      var rail = cur.querySelectorAll('.dy-ttree__rail')[col];
      if (rail) rail.classList.toggle('is-lit-v', on);
      cur = cur.previousElementSibling;
    }
  }

  // ---------------------------------------------------------------------
  // 8. Gear icon (optional columns + items/page).
  // ---------------------------------------------------------------------
  var OPT_COLS = {
    post: META_COLS.map(function (c) {
      var lab = headLabel(c.th, c.id);
      return { id: c.id, key: c.id, label: lab, meta: c };
    }),
    user: [{ id: 'role', key: 'role', label: I18N.colRole }],
    term: [{ id: 'posts', key: 'count', label: I18N.colCount }],
    // Orders: optional hideable columns (labels read from the native thead).
    orders: [
      { id: 'order_status',    key: 'order-status', label: L.status || 'Status' },
      { id: 'order_date',      key: 'order-date',   label: L.date || 'Date' },
      { id: 'billing_address', key: 'order-fact',   label: '' },
      { id: 'origin',          key: 'order-origin', label: '' },
      { id: 'order_total',     key: 'order-total',  label: '' }
    ],
    // Plugins: description + auto-updates (labels read from the native thead).
    plugins: [
      { id: 'description',  key: 'desc', label: '' },
      { id: 'auto-updates', key: 'auto', label: '' }
    ]
  };
  function buildGear(listEl) {
    var wrap = el('<div class="dy-table-options"></div>');
    var trigger = el('<button type="button" class="dy-table-options-trigger" aria-expanded="false" title="' + escAttr(L.screenOptions || 'Screen options') + '">' + svg(ICON.gear) + '</button>');
    var panel = el('<div class="dy-table-options-panel" role="dialog" hidden></div>');
    // Columns that appeared since the last visit (an extension was
    // installed): a dot on the gear until it is opened once; the notice
    // in the notices centre says which extension added them.
    if (CFG.newColumns && CFG.newColumns.length) {
      var names = CFG.newColumns.map(function (c) { return c.label || c.id; }).join(', ');
      trigger.classList.add('is-new');
      trigger.setAttribute('title', (L.newColumns || 'New columns: %s').replace('%s', names));
      trigger.addEventListener('click', function () {
        trigger.classList.remove('is-new');
        trigger.setAttribute('title', L.screenOptions || 'Screen options');
      }, { once: true });
    }

    // Columns (by kind), labels sourced from the native <thead>.
    var cols = (OPT_COLS[KIND] || []).filter(function (c) { return !!nativeTh(c.id); });
    if (cols.length) {
      panel.appendChild(el('<h3>' + escHtml(L.columns || 'Columns') + '</h3>'));
      var ul = el('<ul></ul>');
      cols.forEach(function (c) {
        var th = nativeTh(c.id);
        var label = c.label || thLabel(th, c.id);
        // Screen Options are the source of truth: a column WordPress hides
        // starts unchecked here (it used to start checked whatever the state).
        var startHidden = !!(c.meta && c.meta.hidden);
        var li = el('<li><label><input type="checkbox"' + (startHidden ? '' : ' checked') + '> ' + escHtml(label) + '</label></li>');
        if (c.meta) { listEl.__hiddenCols = listEl.__hiddenCols || {}; if (startHidden) listEl.__hiddenCols[c.id] = true; }
        li.querySelector('input').addEventListener('change', function (e) {
          var hide = !e.target.checked;
          if (c.meta) {
            listEl.__hiddenCols = listEl.__hiddenCols || {};
            if (hide) listEl.__hiddenCols[c.id] = true; else delete listEl.__hiddenCols[c.id];
            applyMetaGrid(listEl);
          } else {
            listEl.querySelectorAll('.dy-trow__' + c.key).forEach(function (cell) { cell.style.display = hide ? 'none' : ''; });
            var hc = listEl.querySelector('.dy-treimg__col--' + c.id);
            if (hc) hc.style.display = hide ? 'none' : '';
          }
          persistColumns(listEl);
        });
        li.querySelector('input').__colId = c.id;
        ul.appendChild(li);
      });
      panel.appendChild(ul);
    }

    // Pagination section — only for screens that expose a per-page
    // option (posts / users / terms). Orders, generic and plugins lists
    // have none: the control would be dead, so it is not shown.
    if (SCREEN.perPageOption) {
      panel.appendChild(el('<h3>' + escHtml(L.pagination || 'Pagination') + '</h3>'));
      var perRow = el('<div class="dy-table-options-panel-row"><label>' + escHtml(L.itemsPerPage || 'Items per page') + '</label><input type="number" class="dy-pp" min="1" max="999" step="5"></div>');
      var ppInput = perRow.querySelector('input');
      ppInput.value = CFG.perPage || CFG.defaultPerPage || 50;   // configurable limit (not a count)
      ppInput.addEventListener('change', function () {
        var n = parseInt(ppInput.value, 10) || CFG.defaultPerPage;
        post(CFG.rest.options, { signature: CFG.signature, perPage: n, perPageOption: SCREEN.perPageOption }, function () { location.reload(); });
      });
      panel.appendChild(perRow);
    }

    wrap.appendChild(trigger); wrap.appendChild(panel);
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = trigger.getAttribute('aria-expanded') === 'true';
      closeAllPops();
      trigger.setAttribute('aria-expanded', open ? 'false' : 'true');
      panel.hidden = open;
    });
    // close on outside click: handled globally by closeAllPops().
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    return wrap;
  }
  function persistColumns(listEl) {
    var hidden = [];
    listEl.__gear && listEl.__gear.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
      if (cb.__colId && !cb.checked) hidden.push(cb.__colId);
    });
    post(CFG.rest.options, { signature: CFG.signature, hiddenColumns: hidden, hiddenColumnsMeta: SCREEN.hiddenColumnsMeta }, null);
  }

  // ---------------------------------------------------------------------
  // 8bis. Column header (labels + sort reusing native links).
  // ---------------------------------------------------------------------
  // Spec by type: ordered sequence of cells. type 'pad' = column with no
  // label (check/status/menu); type 'col' = label (+ sort if available).
  function headSpec(isTree) {
    if (KIND === 'user') {
      return [{ pad: 1 }, { id: 'username' }, { id: 'role' }];
    }
    if (KIND === 'term') {
      return [{ pad: 1 }, { id: 'name' }, { id: 'posts' }];
    }
    // post: the meta columns come from the native <thead> (META_COLS).
    var spec = [{ pad: 1 }, { pad: 1 }, { id: 'title' }];
    META_COLS.forEach(function (c) { spec.push({ id: c.id, meta: c }); });
    return spec;
  }
  function nativeTh(id) {
    if ('categories' === id && CLASSCOL) return CLASSCOL.th;
    for (var i = 0; i < META_COLS.length; i++) { if (META_COLS[i].id === id) return META_COLS[i].th; }
    return table.querySelector('thead th.column-' + id) || table.querySelector('thead th#' + id);
  }
  // "Clean" column label: we strip the screen-reader text that WP
  // adds inside the sort link ("Sort descending"…) to keep only
  // "Date", "Title", etc.
  function thLabel(th, fallback) {
    if (!th) return fallback;
    var c = th.cloneNode(true);
    c.querySelectorAll('.screen-reader-text').forEach(function (e) { e.remove(); });
    var t = c.textContent.replace(/\s+/g, ' ').trim();
    return t || fallback;
  }
  // Label of a meta column heading. Icon-only headings keep nothing once the
  // screen-reader text is stripped: their name for screen readers comes next
  // (Yoast's "SEO score", compatibility pass of 19/10; the sort link's own
  // hint, "Sort ascending.", is a direct child of the link and is skipped),
  // then the bubble's title, then the column id.
  function headLabel(th, id) {
    var t = thLabel(th, '');
    if (t) return t;
    var sr = [].filter.call(th.querySelectorAll('.screen-reader-text'), function (e) { return e.parentElement.tagName !== 'A'; })[0];
    t = sr ? sr.textContent.replace(/\s+/g, ' ').trim() : '';
    if (t) return t;
    var bub = th.querySelector('[title]');
    return (bub && bub.getAttribute('title')) || (id === 'comments' ? (L.comments || 'Comments') : id);
  }
  // 3-state sort cycle: '' (default) → 'desc' → 'asc' → ''.
  function nextDir(cur) { return cur === '' ? 'desc' : (cur === 'desc' ? 'asc' : ''); }
  function cycleSort(clientSort, key, th, sortA) {
    if (clientSort) {
      var cur = (treeSort.key === key) ? treeSort.dir : '';
      var nx = nextDir(cur);
      if (nx === '') { treeSort.key = null; treeSort.dir = 'asc'; }
      else { treeSort.key = key; treeSort.dir = nx; }
      resortTree();
      return;
    }
    // Flat types: server sort. We build the URL ourselves (the native href
    // only toggles asc/desc — no way to return to the default order).
    var cur2 = th.classList.contains('sorted') ? (th.classList.contains('asc') ? 'asc' : 'desc') : '';
    var nx2 = nextDir(cur2);
    var ob = key;
    try { ob = new URL(sortA.href, location.href).searchParams.get('orderby') || key; } catch (e) {}
    var u = new URL(location.href, location.origin);
    if (nx2 === '') { u.searchParams.delete('orderby'); u.searchParams.delete('order'); }
    else { u.searchParams.set('orderby', ob); u.searchParams.set('order', nx2); }
    u.searchParams.delete('paged');
    ajaxGo(u.toString());
  }
  function buildHead(isTree, list) {
    var head = el('<div class="dy-treimg__head"></div>');
    var first = true;
    headSpec(isTree).forEach(function (c) {
      if (c.pad) {
        if (first) {
          // 1st column = "select all" checkbox
          var cell = el('<span class="dy-treimg__checkall"></span>');
          var all = el('<input type="checkbox" aria-label="' + escAttr(L.selectAll || 'Select all') + '">');
          all.addEventListener('change', function () {
            list.querySelectorAll('.dy-trow').forEach(function (row) {
              var cb = row.querySelector('.dy-trow__check input');
              if (cb) { cb.checked = all.checked; cb.dispatchEvent(new Event('change')); }
            });
          });
          cell.appendChild(all); head.appendChild(cell); first = false;
        } else { head.appendChild(el('<span></span>')); }
        return;
      }
      first = false;
      var th = nativeTh(c.id);
      var label = th ? headLabel(th, c.id) : c.id;
      var sortA = th ? th.querySelector('a') : null;
      var sortable = th && th.classList.contains('sortable') && sortA;
      var cell = el('<span class="dy-treimg__col dy-treimg__col--' + c.id + '"' + (c.meta ? ' data-col="' + escAttr(c.id) + '"' : '') + '></span>');
      if (c.meta && label) cell.setAttribute('title', label);
      if (sortable) {
        // Hierarchy → recursive client-side sort (tree preserved, no server
        // round-trip so no reload risk, compatible with any active filter).
        // Flat types → native server sort (sorts across all pages).
        // 3-state cycle on both: default → descending → ascending →
        // default. The label stays "Date"; the icon reflects state (neutral
        // at rest, ↓/↑ arrow when active).
        var key = c.id;
        var clientSort = isTree;
        var act, dir;
        if (clientSort) { act = treeSort.key === key; dir = act ? treeSort.dir : ''; }
        else { act = th.classList.contains('sorted'); dir = th.classList.contains('asc') ? 'asc' : (th.classList.contains('desc') ? 'desc' : ''); }
        var arrow = !act ? ICON.sort : (dir === 'asc' ? ICON.chevUp : ICON.chevDown);
        var tip = act ? (dir === 'asc' ? (L.sortAsc || 'Sorted ascending') : (L.sortDesc || 'Sorted descending')) : (L.sortBy || 'Sort');
        var s = el('<span class="dy-treimg__sort' + (act ? ' is-active' : '') + '" data-dir="' + (act ? dir : '') + '" data-tip="' + escAttr(tip) + '"><span class="dy-treimg__sort-label">' + escHtml(label) + '</span> ' + svg(arrow) + '</span>');
        s.addEventListener('click', (function (cs, k, theTh, a) {
          return function () { cycleSort(cs, k, theTh, a); };
        })(clientSort, key, th, sortA));
        cell.appendChild(s);
      } else {
        cell.textContent = label;
      }
      head.appendChild(cell);
    });
    return head;
  }

  // ---------------------------------------------------------------------
  // 8ter. Filters (Direction A) — read from native WP controls,
  //       re-styled, URL-driven (reuses the WP engine).
  // ---------------------------------------------------------------------
  function navTo(href) { ajaxGo(href); }
  // Applies/removes a query parameter (and resets pagination) → AJAX.
  function applyParam(name, value) {
    var u = new URL(location.href, location.origin);
    if (value === '' || value === '0' || value == null) u.searchParams.delete(name);
    else u.searchParams.set(name, value);
    u.searchParams.delete('paged');
    ajaxGo(u.toString());
  }
  function resetUrl() {
    var u = new URL(location.href, location.origin), keep = new URLSearchParams();
    ['post_type', 'taxonomy'].forEach(function (k) { if (u.searchParams.get(k)) keep.set(k, u.searchParams.get(k)); });
    return u.pathname + (keep.toString() ? '?' + keep.toString() : '');
  }

  // ── AJAX filtering + View Transition (no hard reload) ─────────────────
  // The latest click wins: a click during a request cancels it (it used to
  // be ignored, so the pill showed one view and the list another; a tester
  // saw "All" over the drafts' count, 20/10/2026).
  var ajaxCtl = null;
  function absUrl(href) { return new URL(href, location.href).toString(); }
  function ajaxGo(href, push) {
    if (!href) return;
    var url = absUrl(href);
    // Browser without fetch/DOMParser: hard navigation.
    if (!window.fetch || !window.DOMParser) { location.href = url; return; }
    if (ajaxCtl) { try { ajaxCtl.abort(); } catch (e) {} }
    var ctl = window.AbortController ? new AbortController() : {};
    ajaxCtl = ctl;
    var view = document.querySelector('.dy-treimg');
    if (view) view.classList.add('is-loading');
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: ctl.signal })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) {
        if (ctl !== ajaxCtl) return;   // overtaken by a later click
        ajaxCtl = null;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        // A page cut short (a PHP error halfway through the table) has no
        // footer: let the browser show it as it is rather than apply half
        // of it.
        if (!doc.querySelector('.wp-list-table tbody') || !doc.getElementById('wpfooter')) { location.href = url; return; }
        applyDomFrom(doc);
        // pushState BEFORE the rebuild: build() re-reads the URL (filters) and
        // the fresh DOM — the history must already reflect the new state.
        if (push !== false) { try { history.pushState({ dy: 1 }, '', url); } catch (e) {} }
        var run = function () { rebuild(); };
        if (document.startViewTransition) {
          // .dy-tf-animating activates the view-transition-name on the active status
          // → the accent badge SLIDES from the old tab to the new one.
          document.body.classList.add('dy-tf-animating');
          var vt = document.startViewTransition(run);
          if (vt.ready) vt.ready.catch(function () {});
          vt.finished.catch(function () {}).finally(function () { document.body.classList.remove('dy-tf-animating'); });
        } else { run(); }
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        if (ctl === ajaxCtl) ajaxCtl = null;
        location.href = url;
      });
  }
  // Re-sort of a hierarchical tree (client): instant rebuild, wrapped in a
  // View Transition for smooth reordering when available.
  function resortTree() {
    if (document.startViewTransition) {
      var vt = document.startViewTransition(function () { rebuild(); });
      // A rapid successive click interrupts the ongoing transition (rejects
      // both .ready AND .finished with AbortError) → we absorb both to avoid
      // polluting the console.
      if (vt) { if (vt.ready) vt.ready.catch(function () {}); if (vt.finished) vt.finished.catch(function () {}); }
    } else { rebuild(); }
  }
  // Copies the filtered state from the remote document into the local native DOM.
  function applyDomFrom(doc) {
    var liveSs = document.querySelector('.subsubsub'), newSs = doc.querySelector('.subsubsub');
    if (newSs && liveSs) liveSs.replaceWith(newSs); else if (newSs && !liveSs) { /* nothing */ }
    var form = document.querySelector('#posts-filter');
    if (form) {
      doc.querySelectorAll('#posts-filter .actions select, .tablenav.top .actions select').forEach(function (ns) {
        var name = ns.getAttribute('name'); if (!name) return;
        var live = form.querySelector('select[name="' + name + '"]');
        if (live) live.value = ns.value;
      });
      var liveS = form.querySelector('#post-search-input, input[name="s"]');
      var newS = doc.querySelector('#post-search-input, input[name="s"]');
      if (liveS) liveS.value = newS ? newS.value : '';
    }
    // thead: carries the native sort state (sorted/asc/desc classes + link
    // hrefs). Essential so the SERVER sort indicator (flat types) and the
    // asc/desc toggle remain correct after an AJAX update.
    var liveTh = table.querySelector('thead'), newTh = doc.querySelector('.wp-list-table thead');
    if (liveTh && newTh) liveTh.replaceWith(newTh);
    var liveTb = table.querySelector('tbody');
    var newTb = doc.querySelector('.wp-list-table tbody');
    if (liveTb && newTb) liveTb.replaceWith(newTb);
    ['.tablenav.bottom .tablenav-pages', '.tablenav.top .tablenav-pages'].forEach(function (sel) {
      var l = document.querySelector(sel), n = doc.querySelector(sel);
      if (l && n) l.replaceWith(n);
    });
    // plugins.php: the bulk form remembers the current view (hidden inputs)
    // to come back to it after a bulk action → keep them in sync.
    ['plugin_status', 'paged', 's'].forEach(function (nm) {
      var l = document.querySelector('#bulk-action-form input[type="hidden"][name="' + nm + '"]');
      var n = doc.querySelector('#bulk-action-form input[type="hidden"][name="' + nm + '"]');
      if (l && n) l.value = n.value;
    });
  }
  // Rebuilds the reimagined view from the current native DOM.
  function rebuild() {
    var old = document.querySelector('.dy-treimg'); if (old) old.remove();
    var nb = document.querySelector('.dy-treimg-nativebar'); if (nb) nb.remove();
    var sb = document.querySelector('.dy-treimg-switchbar'); if (sb) sb.remove();
    build();
    guardNativeColumns();
  }
  window.addEventListener('popstate', function () { ajaxGo(location.href, false); });

  function readStatusViews() {
    var ul = document.querySelector('.subsubsub');
    if (!ul) return null;
    var items = [];
    ul.querySelectorAll('li > a').forEach(function (a) {
      var clone = a.cloneNode(true);
      var cEl = clone.querySelector('.count'); var count = '';
      if (cEl) { count = cEl.textContent.replace(/[() \s]/g, ''); cEl.remove(); }
      items.push({
        label: clone.textContent.replace(/\s+/g, ' ').trim(),
        href: a.getAttribute('href'),
        count: count,
        current: a.classList.contains('current') || a.getAttribute('aria-current') === 'page'
      });
    });
    return items.length ? items : null;
  }
  function readFilterSelects() {
    if (KIND !== 'post') return [];   // filter dropdowns = post screens
    var form = document.querySelector('#posts-filter') || table.closest('form');
    if (!form) return [];
    var out = [];
    form.querySelectorAll('.tablenav.top .actions:not(.bulkactions) select').forEach(function (s) {
      var name = s.getAttribute('name') || '';
      if (/^action/.test(name) || name === 'new_role' || !name) return;
      var def = s.options[0];
      var opts = [];
      for (var i = 0; i < s.options.length; i++) {
        var o = s.options[i];
        opts.push({ value: o.value, text: o.text.replace(/\s+/g, ' ').trim(), selected: o.selected });
      }
      var val = s.value;
      var isSet = !!val && val !== '0' && val !== (def ? def.value : '');
      out.push({
        name: name, opts: opts,
        placeholder: def ? def.text.replace(/\s+/g, ' ').trim() : name,
        currentText: s.options[s.selectedIndex] ? s.options[s.selectedIndex].text.replace(/\s+/g, ' ').trim() : '',
        isSet: isSet
      });
    });
    return out;
  }
  function nativeSearchInput() {
    return document.querySelector('.search-box input[type="search"], .search-box input[name="s"], #post-search-input, #user-search-input, #tag-search-input, #media-search-input');
  }

  function buildFilterChip(f) {
    var chip = el('<button type="button" class="dy-tfchip' + (f.isSet ? ' is-set' : '') + '"></button>');
    var txt = el('<span class="dy-tfchip__txt"></span>');
    if (f.isSet) { var v = el('<span class="v"></span>'); v.textContent = f.currentText; txt.appendChild(v); }
    else { txt.textContent = f.placeholder; }
    chip.appendChild(txt);
    chip.appendChild(el('<svg class="caret" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>'));

    var pop = el('<div class="dy-tfchip__pop" hidden></div>');
    var searchable = f.opts.length > 10;
    var optEls = [];
    if (searchable) {
      var si = el('<input class="dy-tfchip__search" type="search" placeholder="' + escAttr(L.search || 'Search…') + '">');
      si.addEventListener('click', function (e) { e.stopPropagation(); });
      si.addEventListener('input', function () {
        var q = si.value.toLowerCase();
        optEls.forEach(function (o) { o.hidden = q && o.textContent.toLowerCase().indexOf(q) === -1; });
      });
      pop.appendChild(si);
    }
    f.opts.forEach(function (o) {
      var opt = el('<div class="dy-tfchip__opt' + (o.selected ? ' is-current' : '') + '"></div>');
      opt.textContent = o.text;
      opt.addEventListener('click', function (e) { e.stopPropagation(); applyParam(f.name, o.value); });
      optEls.push(opt); pop.appendChild(opt);
    });
    chip.appendChild(pop);
    chip.addEventListener('click', function (e) {
      e.stopPropagation();
      var wasHidden = pop.hidden;
      closeAllPops();
      pop.hidden = !wasHidden;
    });
    return chip;
  }
  function closeAllPops() {
    document.querySelectorAll('.dy-tfchip__pop').forEach(function (p) { p.hidden = true; });
    // Options gear: now in the switchbar (outside `.dy-treimg`) → we target
    // ALL panels so that outside clicks close them properly.
    document.querySelectorAll('.dy-table-options-panel').forEach(function (p) { p.hidden = true; });
    document.querySelectorAll('.dy-table-options-trigger[aria-expanded="true"]').forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
  }
  document.addEventListener('click', closeAllPops);

  function buildSearchBox() {
    var input = nativeSearchInput();
    if (!input) return null;
    var box = el('<div class="dy-tfsearch">' + svg(ICON.search) + '<input type="search"></div>');
    var ours = box.querySelector('input');
    ours.value = input.value || '';
    ours.placeholder = input.getAttribute('placeholder') || (L.search || 'Search…');
    ours.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); applyParam('s', ours.value.trim()); }
    });
    return box;
  }

  function buildActive(views, selects) {
    var pills = [];
    if (views) {
      var cur = views.filter(function (v) { return v.current; })[0];
      var first = views[0];
      if (cur && cur !== first) pills.push({ label: cur.label, remove: function () { navTo(first.href); } });
    }
    selects.forEach(function (f) {
      if (f.isSet) pills.push({ label: f.currentText, remove: function () { applyParam(f.name, ''); } });
    });
    var sb = nativeSearchInput();
    if (sb && sb.value) pills.push({ label: '« ' + sb.value + ' »', remove: function () { applyParam('s', ''); } });
    if (!pills.length) return null;

    var row = el('<div class="dy-tfactive"></div>');
    row.appendChild(el('<span>' + escHtml((L.filters || 'Filters')) + ' :</span>'));
    pills.forEach(function (p) {
      var pill = el('<span class="dy-tfactive__pill"><b></b><span class="x">' + svg(ICON.x) + '</span></span>');
      pill.querySelector('b').textContent = p.label;
      pill.querySelector('.x').addEventListener('click', p.remove);
      row.appendChild(pill);
    });
    var reset = el('<span class="dy-tfactive__reset">' + escHtml(L.resetAll || 'Reset all') + '</span>');
    reset.addEventListener('click', function () { navTo(resetUrl()); });
    row.appendChild(reset);
    return row;
  }

  // Builds the filter bar + (optional) the active filters row.
  // The right group contains search + native toggle + gear icon.
  function buildFilters(list) {
    var bar = el('<div class="dy-tfilters"></div>');
    var views = readStatusViews();
    if (views) {
      var seg = el('<div class="dy-tfseg"></div>');
      views.forEach(function (v) {
        var b = el('<button type="button"' + (v.current ? ' class="is-active"' : '') + '></button>');
        b.appendChild(document.createTextNode(v.label + ' '));
        if (v.count) { var n = el('<span class="n"></span>'); n.textContent = v.count; b.appendChild(n); }
        b.addEventListener('click', function () { navTo(v.href); });
        seg.appendChild(b);
      });
      bar.appendChild(seg);
    }
    var selects = readFilterSelects();
    if (selects.length) {
      var chips = el('<div class="dy-tfilters__chips"></div>');
      selects.forEach(function (f) { chips.appendChild(buildFilterChip(f)); });
      bar.appendChild(chips);
    }
    // Right group: search only (the native toggle covers everything from the
    // topbar, the gear lives just above the table).
    var sb = buildSearchBox();
    if (sb) {
      var right = el('<div class="dy-tfilters__right"></div>');
      right.appendChild(sb);
      bar.appendChild(right);
    }
    return { bar: bar, active: buildActive(views, selects) };
  }

  // Bulk action bar: re-styles the native <select name=action>.
  // On "Apply", we sync the native checkboxes (per row), set the chosen
  // action, and submit the native form.
  function buildBulk() {
    var form = document.querySelector('#posts-filter') || table.closest('form');
    if (!form) return null;
    var sel = form.querySelector('select[name="action"]');
    if (!sel || sel.options.length <= 1) return null;
    var applyBtn = form.querySelector('#doaction, .tablenav.top input.action[type="submit"]');
    var applyText = applyBtn && applyBtn.value ? applyBtn.value : (L.apply || 'Apply');

    var bar = el('<div class="dy-tfbulk" hidden></div>');
    var count = el('<span class="dy-tfbulk__count"></span>');
    var ourSel = document.createElement('select'); ourSel.className = 'dy-tfbulk__select';
    for (var i = 0; i < sel.options.length; i++) {
      var o = sel.options[i], oo = document.createElement('option');
      oo.value = o.value; oo.text = o.text; ourSel.appendChild(oo);
    }
    var apply = el('<button type="button" class="dy-tfbulk__apply"></button>');
    apply.textContent = applyText;
    apply.addEventListener('click', function () {
      if (!ourSel.value || ourSel.value === '-1') return;
      sel.value = ourSel.value;
      // plugins.php: wp.updates listens to the CLICK on the native "Apply"
      // button (AJAX bulk update / delete, credentials modal, confirm).
      // A form.submit() would bypass it → click the button instead.
      if (KIND === 'plugins' && applyBtn) { applyBtn.click(); return; }
      form.submit();
    });
    bar.appendChild(count); bar.appendChild(ourSel); bar.appendChild(apply);
    bar.__count = count;
    return bar;
  }

  // ---------------------------------------------------------------------
  // 9. Assemblage + montage.
  // ---------------------------------------------------------------------
  // =====================================================================
  //  GENERIC mode (kind='generic') — third-party wp-list-table tables that do
  //  not follow WP column conventions (no .column-*, no check-column),
  //  e.g. Loco Translate. We read labels from <thead> and map cells BY INDEX.
  //  COMPLETELY ISOLATED path: none of the post/user/term assumptions
  //  (tree, status, bulk, quick-edit) are touched. The native/reimagined
  //  toggle and action chips are reused.
  // =====================================================================
  function genericColumns() {
    var ths = table.querySelectorAll('thead tr:first-child th, thead tr:first-child td');
    var cols = [];
    for (var i = 0; i < ths.length; i++) {
      var th = ths[i];
      if (th.classList.contains('check-column')) { cols.push({ label: '', check: true }); continue; }
      // `thLabel` retire le texte lecteur d'écran que WordPress glisse dans le
      // lien de tri, sans quoi l'intitulé se lisait « Titre Tri croissant ».
      cols.push({ label: thLabel(th, ''), check: false });
    }
    return cols;
  }

  // Normalizes a row's cells into LOGICAL cells aligned with the
  // <thead>. Some third-party tables split a column into multiple <td>
  // (Loco: "Progress" = bar + %) → more <td> than <th>. We merge
  // any cell containing a `.progress` bar with the next cell.
  function genericCells(tr) {
    var raw = [], k = tr.children, j;
    for (j = 0; j < k.length; j++) raw.push(k[j]);
    var cells = [];
    for (var i = 0; i < raw.length; i++) {
      var c = raw[i];
      var html = c.innerHTML;
      var hasActions = !!(c.querySelector && c.querySelector('.row-actions'));
      if (c.querySelector && c.querySelector('.progress') && raw[i + 1]) {
        // "Progress" column split by Loco into 2 <td> (bar + %). The
        // `.progress` bar ALREADY carries its own `.l` label ("100%") → we
        // absorb (skip) the following % cell to realign columns,
        // without displaying a duplicate % next to the pill.
        i++;
      }
      cells.push({ el: c, html: html, hasActions: hasActions, check: c.classList.contains('check-column') });
    }
    return cells;
  }

  function firstDataRow() {
    var trs = table.querySelectorAll('tbody > tr');
    for (var i = 0; i < trs.length; i++) {
      if (!trs[i].classList.contains('no-items') && !trs[i].classList.contains('inline-edit-row')) return trs[i];
    }
    return null;
  }

  // Index of the primary column (logical cell carrying the .row-actions).
  function genericPrimaryIdx() {
    var tr = firstDataRow();
    if (!tr) return 0;
    var cells = genericCells(tr);
    for (var c = 0; c < cells.length; c++) { if (cells[c].hasActions) return c; }
    return 0;
  }

  function buildModelGeneric(cols, primaryIdx) {
    var trs = table.querySelectorAll('tbody > tr');
    var rows = [], n = 0;
    for (var i = 0; i < trs.length; i++) {
      var tr = trs[i];
      if (tr.classList.contains('no-items') || tr.classList.contains('inline-edit-row')) continue;
      var cells = genericCells(tr);
      var primary = cells[primaryIdx] ? cells[primaryIdx].el : null;
      var link = primary ? primary.querySelector('a[href]') : null;
      // Title = CONTENT of the primary cell WITHOUT the row-actions. We keep
      // the HTML (and thus any flags/icons, e.g. Loco's `.lang`)
      // — not just the plain text.
      var titleText = '', titleHtml = '';
      if (primary) {
        var clone = primary.cloneNode(true);
        clone.querySelectorAll('.row-actions, .hidden, .locked-info, button').forEach(function (e) { e.remove(); });
        titleText = txt(clone);
        titleHtml = clone.innerHTML;
      }
      // Meta cells = all logical cells except the primary and check columns.
      var meta = [];
      for (var c2 = 0; c2 < cells.length; c2++) {
        if (c2 === primaryIdx || cells[c2].check) continue;
        meta.push({ label: (cols[c2] ? cols[c2].label : ''), html: cells[c2].html });
      }
      n++;
      rows.push({
        tr: tr,
        id: n,
        title: titleText || (link ? txt(link) : ''),
        titleHtml: titleHtml,
        editHref: link ? link.getAttribute('href') : '',
        meta: meta,
        actions: actionsOf(primary || tr),
        status: '', depth: 0, parent: 0,
        // Certaines listes génériques portent une case de sélection (les
        // listes d'extensions posées sur un type de contenu). On la reprend
        // pour que la barre d'actions groupées reste vivante.
        checkbox: tr.querySelector('.check-column input[type=checkbox], td.column-cb input[type=checkbox]'),
        isHome: false
      });
    }
    return { rows: rows, primaryIdx: primaryIdx };
  }

  function buildHeadGeneric(cols, primaryLabel, primaryIdx, withCheck, listEl) {
    var head = el('<div class="dy-treimg__head"></div>');
    if (withCheck) {
      var cell = el('<span class="dy-treimg__checkall"></span>');
      var all = el('<input type="checkbox" aria-label="' + escAttr(L.selectAll || 'Select all') + '">');
      all.addEventListener('change', function () {
        listEl.querySelectorAll('.dy-trow__check input').forEach(function (cb) {
          cb.checked = all.checked; cb.dispatchEvent(new Event('change'));
        });
      });
      cell.appendChild(all); head.appendChild(cell);
    }
    head.appendChild(el('<span class="dy-treimg__col dy-treimg__col--title">' + escHtml(primaryLabel || (L.title || 'Title')) + '</span>'));
    cols.forEach(function (col, i) {
      if (col.check || i === primaryIdx) return;   // primary = title column, no duplicate
      head.appendChild(el('<span class="dy-treimg__col">' + escHtml(col.label) + '</span>'));
    });
    return head;
  }

  function renderRowGeneric(r) {
    var row = el('<div class="dy-trow dy-trow--generic"></div>');
    row.setAttribute('data-id', r.id);
    row.__model = r;
    if (r.checkbox) {
      var ck = el('<div class="dy-trow__check"></div>');
      var cb = nameCheck(el('<input type="checkbox">'), r.checkbox, r.title);
      cb.checked = !!r.checkbox.checked;
      cb.addEventListener('change', function () {
        r.checkbox.checked = cb.checked;
        row.classList.toggle('is-selected', cb.checked);
        refreshBulk();
      });
      ck.appendChild(cb); row.appendChild(ck);
    }
    // Title: we inject the original HTML (`.lang` flag + language name +
    // native link), not just plain text. The internal `.row-title` link
    // handles navigation natively → no additional JS handler.
    var ti = el('<div class="dy-trow__title"></div>');
    var titleSpan = el('<span class="dy-trow__title-text"></span>');
    titleSpan.innerHTML = r.titleHtml || escHtml(r.title);
    ti.appendChild(titleSpan);
    row.appendChild(ti);
    // Meta cells (Loco column values: progress, strings, date…).
    r.meta.forEach(function (m) {
      var cell = el('<div class="dy-trow__gcell"></div>');
      // Le contenu passe par un enfant : `text-overflow` ne s'applique pas au
      // texte anonyme d'un conteneur flex, et une description longue se
      // faisait couper net, sans points de suspension.
      var val = el('<span class="dy-cell-value"></span>');
      val.innerHTML = m.html;             // native HTML (progress bars included)
      var plain = txt(val);
      if (plain.length > 18) cell.setAttribute('title', plain);
      cell.appendChild(val);
      row.appendChild(cell);
    });
    // Action drawer (deduplicated chips, hover overlay — reused).
    var drawer = el('<div class="dy-trow__drawer"></div>');
    var core = [], ext = [];
    r.actions.forEach(function (a) { (a.core ? core : ext).push(a); });
    core.forEach(function (a) { drawer.appendChild(chip(a)); });
    if (core.length && ext.length) drawer.appendChild(el('<span class="dy-tchip__sep"></span>'));
    ext.forEach(function (a) { drawer.appendChild(chip(a)); });
    row.appendChild(drawer);
    // Title click → edit. If the title HTML already contains its own link
    // (Loco's `.row-title`), we let it handle navigation natively;
    // otherwise we make the label clickable toward editHref.
    var titleEl = row.querySelector('.dy-trow__title-text');
    if (titleEl && !titleEl.querySelector('a[href]') && r.editHref) {
      titleEl.style.cursor = 'pointer';
      titleEl.addEventListener('click', function (e) { e.stopPropagation(); navigateEdit(r, titleEl); });
    }
    return row;
  }

  function buildGeneric() {
    var cols = genericColumns();
    var primaryIdx = genericPrimaryIdx();
    var model = buildModelGeneric(cols, primaryIdx);
    var rows = model.rows;
    if (!rows.length) { return; }   // no usable rows → leave the native view

    // Label of the primary column (for the title column header).
    var primaryLabel = (cols[primaryIdx] ? cols[primaryIdx].label : '') || (rows[0] ? rows[0].primaryLabel : '');
    // Number of meta columns = those displayed on the first row.
    var metaCount = rows[0] ? rows[0].meta.length : 0;

    var view = el('<div class="dy-treimg dy-treimg--generic"></div>');
    if (suppressRowAnimOnce) { view.classList.add('dy-treimg--no-anim'); suppressRowAnimOnce = false; }

    // List: grid = title (1.4fr) + 1fr per meta column.
    var list = el('<div class="dy-treimg__list"></div>');
    var withCheck = rows.some(function (r) { return !!r.checkbox; });
    var grid = (withCheck ? '26px ' : '') + 'minmax(0, 1.4fr)';
    for (var g = 0; g < metaCount; g++) grid += ' minmax(0, 1fr)';
    list.style.setProperty('--dya-grid', grid);
    list.appendChild(buildHeadGeneric(cols, primaryLabel, primaryIdx, withCheck, list));
    rows.forEach(function (r) { list.appendChild(renderRowGeneric(r)); });
    view.appendChild(list);

    var anchor = table.closest('.dy-table-wrapper') || table;
    anchor.parentNode.insertBefore(view, anchor);

    // Single animated toggle bar (always visible, before the view).
    buildSwitchbar(view);

    wireRows(view, list, false);
    return view;
  }

  // ---------------------------------------------------------------------
  //  DEDICATED "orders" path for WooCommerce (admin.php?page=wc-orders).
  //  Commerce-specific reimagined UX (dedicated columns & usage): order # + customer
  //  in the primary cell, colored status pill, total prominent, date;
  //  "Preview" (WC modal) + "Edit" actions as chips on hover. Reuses
  //  the toggle (buildSwitchbar), the status filter bar (buildFilters), and
  //  row wiring (wireRows).
  // ---------------------------------------------------------------------
  function orderStatusInfo(tr) {
    var m = tr.querySelector('.column-order_status mark.order-status, .column-order_status .order-status, .column-order_status mark');
    var sc = tr.querySelector('.column-order_status');
    if (!m) return { key: 'none', label: sc ? txt(sc).trim() : '' };
    var mm = (m.className || '').match(/status-([\w-]+)/);
    return { key: mm ? mm[1] : 'none', label: txt(m).trim() };
  }
  function orderActions(numCell, tr) {
    var acts = [];
    var prev = numCell ? numCell.querySelector('a.order-preview') : null;
    var view = numCell ? numCell.querySelector('a.order-view') : null;
    if (prev) acts.push({ key: 'view', label: (L.preview || 'Preview'), node: prev, core: true });
    if (view) acts.push({ key: 'edit', label: (L.edit || 'Edit'), node: view, core: true });
    actionsOf(numCell || tr).forEach(function (a) { acts.push(a); });
    return acts;
  }
  function buildOrders() {
    var trs = table.querySelectorAll('tbody > tr');
    var rows = [], n = 0;
    for (var i = 0; i < trs.length; i++) {
      var tr = trs[i];
      if (tr.classList.contains('no-items') || tr.classList.contains('inline-edit-row')) continue;
      var numCell = tr.querySelector('.column-order_number');
      var view = numCell ? numCell.querySelector('a.order-view') : null;
      // The `.order-view > strong` link contains "#2099 John Smith"
      // (order # + customer name). We cleanly separate the number from the name.
      var strong = view ? (view.querySelector('strong') || view) : null;
      var raw = strong ? txt(strong).trim() : (numCell ? txt(numCell).trim() : '');
      var nm = raw.match(/#?\s*([0-9]+)/);
      var number = nm ? ('#' + nm[1]) : (raw.split(/\s+/)[0] || ('#' + (n + 1)));
      var customer = nm ? raw.replace(nm[0], '').trim() : '';
      // Billing: city (last segment) + payment method (.description).
      var billCell = tr.querySelector('.column-billing_address, .column-order_billing');
      var location = '', payment = '';
      if (billCell) {
        var bclone = billCell.cloneNode(true);
        var desc = bclone.querySelector('.description');
        if (desc) { payment = txt(desc).replace(/^\s*via\s*/i, '').trim(); desc.remove(); } // strip "via " prefix
        var full = txt(bclone).trim();
        if (full && full !== '–' && full !== '-' && full !== '—') {
          var segs = full.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
          location = segs.length ? segs[segs.length - 1] : '';
        }
      }
      var dateCell = tr.querySelector('.column-order_date');
      var totalCell = tr.querySelector('.column-order_total');
      var originCell = tr.querySelector('.column-origin');
      var origin = originCell ? txt(originCell).trim() : '';
      n++;
      var r = {
        tr: tr, id: n, number: number, customer: customer,
        location: location, payment: payment, origin: origin,
        editHref: view ? view.getAttribute('href') : '',
        status: orderStatusInfo(tr),
        dateHtml: dateCell ? dateCell.innerHTML : '',
        totalHtml: totalCell ? totalCell.innerHTML : '',
        checkbox: tr.querySelector('.check-column input[type="checkbox"]')
      };
      r.actions = orderActions(numCell, tr);
      rows.push(r);
    }
    if (!rows.length) { return; }

    var view2 = el('<div class="dy-treimg dy-treimg--orders"></div>');
    if (suppressRowAnimOnce) { view2.classList.add('dy-treimg--no-anim'); suppressRowAnimOnce = false; }
    var list = el('<div class="dy-treimg__list"></div>');
    // Column order: Order (customer+#) · Status · Date · Billing · Origin · Total.
    // All left-aligned except Total (right). Order column narrowed.
    // IMPORTANT: each row is an INDEPENDENT grid → only use `fr`/px tracks
    // (never `auto`, which sizes to the content of EACH row and misaligns
    // columns from one row to the next).
    list.style.setProperty('--dya-grid', '26px minmax(0, 1.3fr) minmax(120px, 0.85fr) minmax(0, 0.75fr) minmax(0, 1.1fr) minmax(0, 0.7fr) minmax(0, 0.7fr)');
    // Headers (labels read from native <thead> when possible, otherwise fallback).
    function nl(col, fb) { var th = table.querySelector('thead th.column-' + col); return th ? thLabel(th, fb) : fb; }
    // The `--<id>` classes let the options gear (buildGear) target
    // each header column to hide/show it.
    var head = el('<div class="dy-treimg__head">' +
      '<span class="dy-treimg__col dy-treimg__col--title">' + escHtml(nl('order_number', 'Order')) + '</span>' +
      '<span class="dy-treimg__col dy-treimg__col--order_status">' + escHtml(nl('order_status', 'Status')) + '</span>' +
      '<span class="dy-treimg__col dy-treimg__col--order_date">' + escHtml(nl('order_date', 'Date')) + '</span>' +
      '<span class="dy-treimg__col dy-treimg__col--billing_address">' + escHtml(nl('billing_address', 'Billing')) + '</span>' +
      '<span class="dy-treimg__col dy-treimg__col--origin">' + escHtml(nl('origin', 'Origin')) + '</span>' +
      '<span class="dy-treimg__col dy-treimg__col--total dy-treimg__col--order_total">' + escHtml(nl('order_total', 'Total')) + '</span>' +
      '</div>');
    // "Select all" checkbox (same component as other reimagined screens).
    var checkAllCell = el('<span class="dy-treimg__checkall"></span>');
    var checkAll = el('<input type="checkbox" aria-label="' + escAttr(L.selectAll || 'Select all') + '">');
    checkAll.addEventListener('change', function () {
      list.querySelectorAll('.dy-trow').forEach(function (row) {
        var cb = row.querySelector('.dy-trow__check input');
        if (cb) { cb.checked = checkAll.checked; cb.dispatchEvent(new Event('change')); }
      });
    });
    checkAllCell.appendChild(checkAll);
    head.insertBefore(checkAllCell, head.firstChild);
    list.appendChild(head);
    rows.forEach(function (r) { list.appendChild(renderRowOrder(r)); });

    // Status filters (All / Processing / Completed…) + bulk actions —
    // same components as other screens (buildFilters returns {bar, active}).
    var filters = buildFilters();
    var bulk = buildBulk();
    refreshBulk = function () {
      var n = list.querySelectorAll('.dy-trow__check input:checked').length;
      var total = list.querySelectorAll('.dy-trow__check input').length;
      // Sync the "select all" checkbox (checked / indeterminate / unchecked).
      checkAll.checked = total > 0 && n === total;
      checkAll.indeterminate = n > 0 && n < total;
      if (!bulk) return;
      bulk.hidden = n === 0;
      if (bulk.__count) { bulk.__count.innerHTML = '<b>' + n + '</b> ' + escHtml(L.selected || 'selected'); }
    };
    if (filters && filters.bar) view2.appendChild(filters.bar);
    if (filters && filters.active) view2.appendChild(filters.active);
    if (bulk) view2.appendChild(bulk);
    view2.appendChild(list);

    // Options gear (hideable columns + pagination) — like the other
    // reimagined screens. Housed in the switchbar, to the left of the toggle button.
    var gear = buildGear(list);
    list.__gear = gear.querySelector('.dy-table-options-panel');

    var anchor = table.closest('.dy-table-wrapper') || table;
    anchor.parentNode.insertBefore(view2, anchor);
    var bar = buildSwitchbar(view2);
    if (gear) { gear.classList.add('dy-table-options--insb'); bar.insertBefore(gear, bar.firstChild); }
    wireRows(view2, list, false);
    return view2;
  }
  function renderRowOrder(r) {
    var row = el('<div class="dy-trow dy-trow--order"></div>');
    row.setAttribute('data-id', r.id);
    row.__model = r;
    // Checkbox: we CLONE (fresh synced checkbox) instead of MOVING the native
    // checkbox — otherwise the native table, once switched back to native view,
    // would have no checkboxes (they would have migrated into the reimagined
    // view). Same approach as the generic renderRow.
    var checkWrap = el('<div class="dy-trow__check"></div>');
    var cb = nameCheck(el('<input type="checkbox">'), r.checkbox, r.title || r.number || '');
    if (r.checkbox && r.checkbox.checked) cb.checked = true;
    cb.addEventListener('change', function () {
      if (r.checkbox) { r.checkbox.checked = cb.checked; }
      row.classList.toggle('is-selected', cb.checked);
      refreshBulk();
    });
    checkWrap.appendChild(cb);
    row.appendChild(checkWrap);
    // Order: CUSTOMER NAME as primary (ink, semi-bold) + order # as secondary.
    var main = el('<div class="dy-trow__order-main"></div>');
    var custTxt = r.customer || (L.guest || 'Guest customer');
    var custEl = el('<span class="dy-trow__order-cust dy-trow__title-text' + (r.customer ? '' : ' is-guest') + '">' + escHtml(custTxt) + '</span>');
    main.appendChild(custEl);
    main.appendChild(el('<span class="dy-trow__order-num">' + escHtml(r.number) + '</span>'));
    row.appendChild(main);
    // Status (before Date).
    var stWrap = el('<div class="dy-trow__order-status"></div>');
    stWrap.appendChild(el('<span class="dy-torder-status dy-torder-status--' + escAttr(r.status.key) + '">' +
      '<span class="dy-torder-status__dot"></span><span class="dy-torder-status__lbl">' + escHtml(r.status.label) + '</span></span>'));
    row.appendChild(stWrap);
    // Date column.
    var d = el('<div class="dy-trow__order-date"></div>'); d.innerHTML = r.dateHtml; row.appendChild(d);
    // Billing: city + payment method.
    var fact = el('<div class="dy-trow__order-fact"></div>');
    if (r.location) fact.appendChild(el('<span class="dy-trow__order-loc">' + escHtml(r.location) + '</span>'));
    if (r.payment) fact.appendChild(el('<span class="dy-trow__order-pay">' + escHtml(r.payment) + '</span>'));
    if (!r.location && !r.payment) fact.appendChild(el('<span class="dy-trow__order-loc dy-trow__order-loc--empty">—</span>'));
    row.appendChild(fact);
    // Origin column.
    row.appendChild(el('<div class="dy-trow__order-origin">' + escHtml(r.origin || '—') + '</div>'));
    // Total (right-aligned).
    var t = el('<div class="dy-trow__order-total"></div>'); t.innerHTML = r.totalHtml; row.appendChild(t);
    var drawer = el('<div class="dy-trow__drawer"></div>');
    var core = [], ext = [];
    r.actions.forEach(function (a) { (a.core ? core : ext).push(a); });
    core.forEach(function (a) { drawer.appendChild(chip(a)); });
    if (core.length && ext.length) drawer.appendChild(el('<span class="dy-tchip__sep"></span>'));
    ext.forEach(function (a) { drawer.appendChild(chip(a)); });
    row.appendChild(drawer);
    if (r.editHref) {
      custEl.style.cursor = 'pointer';
      custEl.addEventListener('click', function (e) { e.stopPropagation(); navigateEdit({ editHref: r.editHref }, custEl); });
    }
    return row;
  }

  // ---------------------------------------------------------------------
  //  DEDICATED "plugins" path (plugins.php).
  //  One plugin = one row. The native update row (`tr.plugin-update-tr`)
  //  folds into a version chip on the row, active plugins are grouped
  //  first, the description sits on one line and expands with the author
  //  and the plugin's meta links. Every action stays NATIVE: chips click
  //  the original links (activate / deactivate / delete / settings /
  //  details), the update chip clicks the core `.update-link` (wp.updates
  //  keeps its AJAX flow, credentials modal and nonces), the auto-update
  //  switch clicks the core toggle, bulk actions go through the core
  //  "Apply" button (AJAX updates and deletes, regular submit for
  //  activation). Feedback is read back from the native rows with
  //  MutationObservers — no dependency on jQuery events.
  // ---------------------------------------------------------------------
  var PL_MAIN_VIEWS = ['all', 'active', 'inactive', 'upgrade'];   // the rest folds into "More"
  var pluginObservers = [];
  function pluginObserve(target, cb, opts) {
    if (!window.MutationObserver || !target) return;
    var mo = new MutationObserver(cb);
    mo.observe(target, opts);
    pluginObservers.push(mo);
  }
  function pluginVersionOf(text) {
    var m = (text || '').match(/(\d+(?:\.\d+)+[\w.\-]*)/);
    return m ? m[1] : '';
  }
  // The core update row (or a plugin's own notice row) follows the plugin
  // row: any sibling until the next real plugin row (which has a title cell).
  function pluginUpdateRow(tr) {
    var nx = tr.nextElementSibling;
    while (nx && !nx.querySelector(':scope > .plugin-title')) {
      if (nx.querySelector('.update-message, .update-link')) return nx;
      nx = nx.nextElementSibling;
    }
    return null;
  }
  function readPluginViews() {
    var ul = document.querySelector('.subsubsub');
    if (!ul) return null;
    var items = [];
    ul.querySelectorAll('li').forEach(function (li) {
      var a = li.querySelector('a');
      if (!a) return;
      var clone = a.cloneNode(true);
      var cEl = clone.querySelector('.count'); var count = '';
      if (cEl) { count = cEl.textContent.replace(/[()\s]/g, ''); cEl.remove(); }
      items.push({
        key: (li.className || '').trim().split(/\s+/)[0] || '',
        label: txt(clone),
        href: a.getAttribute('href'),
        count: count,
        current: a.classList.contains('current') || a.getAttribute('aria-current') === 'page'
      });
    });
    return items.length ? items : null;
  }
  function pluginModel() {
    var trs = table.querySelectorAll('tbody > tr[data-plugin]');
    var rows = [], n = 0;
    for (var i = 0; i < trs.length; i++) {
      var tr = trs[i];
      var titleCell = tr.querySelector(':scope > .plugin-title');
      if (!titleCell || tr.classList.contains('plugin-update-tr')) continue;
      var descCell = tr.querySelector('.column-description');
      var metaEl   = descCell ? descCell.querySelector('.plugin-version-author-uri') : null;
      var reqEl    = descCell ? descCell.querySelector('.requires') : null;
      var upTr     = pluginUpdateRow(tr);
      var upLink   = upTr ? upTr.querySelector('a.update-link') : null;
      var upLog    = upTr ? upTr.querySelector('a.open-plugin-details-modal, a.thickbox') : null;
      var upMsgEl  = upTr ? upTr.querySelector('.update-message') : null;
      var autoA    = tr.querySelector('.column-auto-updates .toggle-auto-update');
      // Meta line: "Version 6.5.1 | By <a>Author</a> | <a>View details</a> | plugin links…"
      var version = '', versionLabel = '', author = '', authorHtml = '', detailsA = null, extras = [];
      if (metaEl) {
        detailsA = metaEl.querySelector('a.open-plugin-details-modal, a.thickbox');
        metaEl.innerHTML.split('|').forEach(function (h) {
          var d = document.createElement('div'); d.innerHTML = h;
          var t = txt(d);
          if (!t) return;
          if (d.querySelector('a.open-plugin-details-modal, a.thickbox')) return;   // → "Details" chip
          if (!version && !d.querySelector('a') && /\d/.test(t)) { version = pluginVersionOf(t) || t; versionLabel = t; return; }
          if (!author) { author = t; authorHtml = d.innerHTML.trim(); return; }
          extras.push(d.innerHTML.trim());
        });
      }
      // Native row actions (activate / deactivate / delete + plugin links).
      var natives = actionsOf(titleCell);
      var acts = [], toggles = [], del = [], settings = [], other = [];
      if (upLink) {
        acts.push({ key: 'update', label: L.update || 'Update', tip: upLink.getAttribute('aria-label') || txt(upLink), node: upLink, core: true, primary: true, icon: ICON.update });
      }
      natives.forEach(function (a) {
        var k = (a.key || '').toLowerCase();
        if (k === 'activate' || k === 'deactivate') { a.icon = ICON.power; a.core = true; a.primary = (k === 'activate' && !upLink); a.danger = false; toggles.push(a); }
        else if (k === 'delete') { a.icon = ICON.trash; a.core = true; a.danger = true; del.push(a); }
        else if (k === 'settings' || /settings|r[ée]glages|einstellungen|ajustes|impostazioni|configura/i.test(a.label)) { a.icon = ICON.gear; a.core = false; a.danger = false; settings.push(a); }
        else { a.danger = false; other.push(a); }
      });
      var details = detailsA ? [{ key: 'details', label: txt(detailsA), tip: detailsA.getAttribute('aria-label') || txt(detailsA), node: detailsA, core: true, icon: ICON.info }] : [];
      acts = acts.concat(toggles, details, del, settings, other);
      n++;
      rows.push({
        tr: tr, id: n,
        plugin: tr.getAttribute('data-plugin') || '', slug: tr.getAttribute('data-slug') || '',
        title: txt(titleCell.querySelector('strong') || titleCell),
        active: tr.classList.contains('active'),
        version: version, versionLabel: versionLabel,
        newVersion: upLog ? pluginVersionOf(txt(upLog)) : '',
        author: author, authorHtml: authorHtml, extras: extras,
        desc: descCell ? txt(descCell.querySelector('.plugin-description')) : '',
        requires: reqEl ? txt(reqEl.querySelector('p') || reqEl) : '',
        blocked: reqEl ? txt(reqEl.querySelector('.notice')) : '',
        upTr: upTr, upLink: upLink, upLog: upLog, upMsgEl: upMsgEl, metaEl: metaEl,
        upNote: (upMsgEl && !upLink) ? txt(upMsgEl) : '',
        autoA: autoA,
        checkbox: tr.querySelector('.check-column input[type="checkbox"]'),
        actions: acts
      });
    }
    return rows;
  }
  function pluginGroupHeader(label, count) {
    var g = el('<div class="dy-tgroup"><span class="dy-tgroup__lbl"></span><span class="dy-tgroup__n"></span></div>');
    g.querySelector('.dy-tgroup__lbl').textContent = label;
    g.querySelector('.dy-tgroup__n').textContent = count;
    return g;
  }
  // Recounts each group from its visible rows (search, deletions) and hides empty groups.
  function refreshPluginGroups(list) {
    list.querySelectorAll('.dy-tgroup').forEach(function (g) {
      var n = 0, cur = g.nextElementSibling;
      while (cur && !cur.classList.contains('dy-tgroup')) {
        if (cur.classList.contains('dy-trow') && !cur.hidden) n++;
        cur = cur.nextElementSibling;
      }
      g.querySelector('.dy-tgroup__n').textContent = n;
      g.hidden = n === 0;
    });
  }
  function togglePluginRow(row, force) {
    var open = force != null ? !!force : !row.classList.contains('is-open');
    row.classList.toggle('is-open', open);
    var ex = row.querySelector('.dy-trow__expand');
    if (ex) {
      ex.setAttribute('data-state', open ? 'open' : 'closed');
      ex.setAttribute('aria-expanded', open ? 'true' : 'false');
      ex.setAttribute('data-tip', open ? (L.collapse || 'Collapse') : (L.expand || 'Expand'));
    }
  }
  function pluginVersionChip(r, row) {
    var chipEl;
    if (r.upLink) {
      var lbl = (r.version && r.newVersion) ? (r.version + ' → ' + r.newVersion) : (r.newVersion || (L.updateAvailable || 'Update available'));
      var tip = r.upLog ? (r.upLog.getAttribute('aria-label') || txt(r.upLog)) : (L.updateAvailable || 'Update available');
      chipEl = el('<button type="button" class="dy-tver dy-tver--up" data-tip="' + escAttr(tip) + '"><span class="dy-tver__txt">' + escHtml(lbl) + '</span></button>');
      // The chip is informative: it opens the release notes (native thickbox link).
      chipEl.addEventListener('click', function (e) { e.stopPropagation(); if (r.upLog) { try { r.upLog.click(); } catch (err) {} } });
    } else if (r.version) {
      chipEl = el('<span class="dy-tver' + (r.upNote ? ' dy-tver--warn' : '') + '" data-tip="' + escAttr(r.upNote || r.versionLabel || r.version) + '"><span class="dy-tver__txt">' + escHtml(r.version) + '</span></span>');
    } else {
      return null;
    }
    row.__ver = chipEl;
    return chipEl;
  }
  function renderRowPlugin(r, labels) {
    var row = el('<div class="dy-trow dy-trow--plugin' + (r.active ? ' is-active' : ' is-inactive') + (r.blocked ? ' is-blocked' : '') + (r.upLink ? ' has-update' : '') + '"></div>');
    row.setAttribute('data-id', r.id);
    row.setAttribute('data-dyplugin', r.plugin);
    row.__model = r;

    // Checkbox: CLONED and synced with the native one (the native table keeps
    // its own for the "Native view" and for the core bulk handlers).
    var ck = el('<div class="dy-trow__check"></div>');
    var cb = el('<input type="checkbox">');
    cb.setAttribute('aria-label', r.title);
    if (!r.checkbox || r.checkbox.disabled) cb.disabled = true;
    if (r.checkbox && r.checkbox.checked) cb.checked = true;
    cb.addEventListener('change', function () {
      if (r.checkbox) r.checkbox.checked = cb.checked;
      row.classList.toggle('is-selected', cb.checked);
      refreshBulk();
    });
    ck.appendChild(cb); row.appendChild(ck);

    // State before the name: full dot = active, ring = inactive, dashed ring = blocked by a dependency.
    var stKey = r.blocked ? 'blocked' : (r.active ? 'active' : 'inactive');
    var stTip = r.blocked ? r.blocked : (r.active ? labels.active : labels.inactive);
    row.appendChild(el('<div class="dy-trow__status"><span class="dy-tstatus dy-tstatus--' + stKey + '" data-tip="' + escAttr(stTip) + '"><span class="dy-tstatus__dot"></span></span></div>'));

    // Name on the first line; the state line below it: "Update available"
    // (muted) + version chip, or just the version, + the dependency pill.
    var name = el('<div class="dy-trow__plugin"></div>');
    name.appendChild(el('<span class="dy-trow__title-text">' + escHtml(r.title) + '</span>'));
    var sub = el('<span class="dy-trow__plugin-sub"></span>');
    // Inactive, said in words too: the ring alone was too quiet (translator, 24/10/2026).
    if (!r.active && !r.blocked) sub.appendChild(el('<span class="dy-tinactive">' + escHtml(L.inactiveOne || 'Inactive') + '</span>'));
    if (r.upLink) {
      var upd = el('<span class="dy-tupd">' + escHtml(L.updateAvailable || 'Update available') + '</span>');
      sub.appendChild(upd);
      row.__upd = upd;
    }
    var ver = pluginVersionChip(r, row);
    if (ver) sub.appendChild(ver);
    if (r.requires) sub.appendChild(el('<span class="dy-treq" data-tip="' + escAttr(r.blocked || r.requires) + '">' + escHtml(r.requires) + '</span>'));
    if (sub.childNodes.length) name.appendChild(sub);
    row.appendChild(name);

    // Description on one line; the expanded state reveals the meta line
    // (version, author, plugin links — native markup, already escaped by WP)
    // and any notice (dependency, incompatible update, failed update).
    var dsc = el('<div class="dy-trow__desc"></div>');
    dsc.appendChild(el('<div class="dy-trow__desc-text">' + escHtml(r.desc) + '</div>'));
    var meta = el('<div class="dy-trow__desc-meta"></div>');
    var parts = [];
    if (r.versionLabel) parts.push(escHtml(r.versionLabel));
    if (r.authorHtml) parts.push(r.authorHtml);
    r.extras.forEach(function (h) { parts.push(h); });
    if (parts.length) meta.appendChild(el('<span class="dy-trow__desc-line">' + parts.join('<span class="dy-trow__desc-sep">·</span>') + '</span>'));
    if (r.blocked) meta.appendChild(el('<span class="dy-trow__note dy-trow__note--warn">' + escHtml(r.blocked) + '</span>'));
    if (r.upNote) meta.appendChild(el('<span class="dy-trow__note dy-trow__note--warn">' + escHtml(r.upNote) + '</span>'));
    var err = el('<span class="dy-trow__note dy-trow__note--error" hidden></span>');
    meta.appendChild(err);
    row.__err = err;
    dsc.appendChild(meta);
    row.appendChild(dsc);

    // Auto-updates: a switch that delegates to the core toggle link.
    var auto = el('<div class="dy-trow__auto"></div>');
    if (r.autoA) {
      var on = r.autoA.getAttribute('data-wp-action') === 'disable';
      var sw = el('<button type="button" class="dy-tswitch" role="switch" aria-checked="' + (on ? 'true' : 'false') + '" data-tip="' + escAttr(txt(r.autoA.querySelector('.label')) || (L.autoUpdates || 'Auto-updates')) + '"><span class="dy-tswitch__knob"></span></button>');
      sw.addEventListener('click', function (e) {
        e.stopPropagation();
        if (sw.classList.contains('is-busy')) return;
        sw.classList.add('is-busy');
        try { r.autoA.click(); } catch (err2) { sw.classList.remove('is-busy'); }
      });
      auto.appendChild(sw);
      row.__auto = sw;
    }
    row.appendChild(auto);

    // Expand / collapse (chevron + click on the row outside its controls).
    var ex = el('<button type="button" class="dy-trow__expand" data-state="closed" aria-expanded="false" data-tip="' + escAttr(L.expand || 'Expand') + '">' + svg(ICON.chevDown) + '</button>');
    ex.addEventListener('click', function (e) { e.stopPropagation(); togglePluginRow(row); });
    row.appendChild(ex);
    row.addEventListener('click', function (e) {
      if (e.target.closest('button, a, input, label, .dy-trow__drawer, .dy-tver')) return;
      togglePluginRow(row);
    });

    // Action drawer: core actions, then plugin-provided ones after a separator.
    var drawer = el('<div class="dy-trow__drawer"></div>');
    var core = [], ext = [];
    r.actions.forEach(function (a) { (a.core ? core : ext).push(a); });
    core.forEach(function (a) { drawer.appendChild(chip(a)); });
    if (core.length && ext.length) drawer.appendChild(el('<span class="dy-tchip__sep"></span>'));
    ext.forEach(function (a) { drawer.appendChild(chip(a)); });
    if (!r.actions.length) drawer.classList.add('dy-trow__drawer--empty');
    row.appendChild(drawer);

    watchPluginRow(r, row);
    return row;
  }
  // Reads the update feedback back from the native update row: wp.updates
  // toggles `updating-message` / `updated-message` / `notice-error` on
  // `.update-message` and rewrites the version in the meta line.
  function syncPluginUpdate(r, row) {
    var cls = r.upMsgEl.className || '';
    var ver = row.__ver, txtEl = ver ? ver.querySelector('.dy-tver__txt') : null;
    var chipUp = row.querySelector('.dy-tchip[data-key="update"]');
    function state(text) { if (row.__upd) row.__upd.textContent = text; }
    if (/updating-message/.test(cls)) {
      row.classList.add('is-updating'); row.classList.remove('is-update-error');
      state(L.updating || 'Updating…');
      if (ver) ver.classList.add('dy-tver--busy');
      if (chipUp) chipUp.disabled = true;
      return;
    }
    if (/updated-message/.test(cls)) {
      row.classList.remove('is-updating', 'is-update-error', 'has-update'); row.classList.add('is-updated');
      var nv = r.metaEl ? pluginVersionOf(txt(r.metaEl)) : '';
      state(L.updated || 'Updated');
      if (ver) {
        ver.classList.remove('dy-tver--busy', 'dy-tver--up'); ver.classList.add('dy-tver--done');
        txtEl.textContent = nv || r.newVersion || r.version;
        ver.setAttribute('data-tip', L.updated || 'Updated');
      }
      if (chipUp) chipUp.remove();
      if (row.__err) row.__err.hidden = true;
      r.upLink = null;
      if (typeof row.__onUpdated === 'function') row.__onUpdated();
      return;
    }
    if (/notice-error/.test(cls)) {
      row.classList.remove('is-updating'); row.classList.add('is-update-error');
      state(L.updateFailed || 'Update failed');
      if (ver) ver.classList.remove('dy-tver--busy');
      if (chipUp) chipUp.disabled = false;
      if (row.__err) { row.__err.textContent = txt(r.upMsgEl); row.__err.hidden = false; }
      togglePluginRow(row, true);
    }
  }
  function watchPluginRow(r, row) {
    if (r.upMsgEl) {
      pluginObserve(r.upMsgEl, function () { syncPluginUpdate(r, row); }, { attributes: true, attributeFilter: ['class'] });
    }
    if (r.autoA && row.__auto) {
      pluginObserve(r.autoA, function () {
        var sw = row.__auto;
        sw.setAttribute('aria-checked', r.autoA.getAttribute('data-wp-action') === 'disable' ? 'true' : 'false');
        var lbl = txt(r.autoA.querySelector('.label'));
        if (lbl) sw.setAttribute('data-tip', lbl);
        if (!r.autoA.hasAttribute('data-doing-ajax')) sw.classList.remove('is-busy');
      }, { attributes: true, attributeFilter: ['data-wp-action', 'data-doing-ajax'], childList: true, subtree: true, characterData: true });
    }
  }
  function buildPlugins() {
    pluginObservers.forEach(function (mo) { try { mo.disconnect(); } catch (e) {} });
    pluginObservers = [];
    var rows = pluginModel();
    if (!rows.length) { return; }
    var views = readPluginViews();
    var curView = (views || []).filter(function (v) { return v.current; })[0];
    var curKey = curView ? curView.key : 'all';
    function viewLabel(key, fb) { var v = (views || []).filter(function (x) { return x.key === key; })[0]; return v ? v.label : fb; }
    var labels = { active: viewLabel('active', L.activeGroup || 'Active'), inactive: viewLabel('inactive', L.inactiveGroup || 'Inactive') };

    var view = el('<div class="dy-treimg dy-treimg--plugins"></div>');
    if (!table.querySelector('thead .column-auto-updates')) view.classList.add('dy-treimg--plugins-noauto');
    if (suppressRowAnimOnce) { view.classList.add('dy-treimg--no-anim'); suppressRowAnimOnce = false; }
    var list = el('<div class="dy-treimg__list"></div>');

    // Header (labels read from the native thead).
    function nl(col, fb) { var th = table.querySelector('thead th.column-' + col + ', thead th#' + col); return th ? thLabel(th, fb) : fb; }
    var head = el('<div class="dy-treimg__head">' +
      '<span></span>' +
      '<span class="dy-treimg__col dy-treimg__col--title dy-treimg__col--name">' + escHtml(nl('name', 'Plugin')) + '</span>' +
      '<span class="dy-treimg__col dy-treimg__col--description">' + escHtml(nl('description', 'Description')) + '</span>' +
      '<span class="dy-treimg__col dy-treimg__col--auto-updates" data-tip="' + escAttr(nl('auto-updates', L.autoUpdates || 'Auto-updates')) + '">' + escHtml(L.autoUpdates || 'Auto-updates') + '</span>' +
      '<span></span>' +
      '</div>');
    var checkAllCell = el('<span class="dy-treimg__checkall"></span>');
    var checkAll = el('<input type="checkbox" aria-label="' + escAttr(L.selectAll || 'Select all') + '">');
    checkAll.addEventListener('change', function () {
      list.querySelectorAll('.dy-trow').forEach(function (row) {
        var cb = row.querySelector('.dy-trow__check input');
        if (cb && !cb.disabled && !row.hidden) { cb.checked = checkAll.checked; cb.dispatchEvent(new Event('change')); }
      });
    });
    checkAllCell.appendChild(checkAll);
    head.insertBefore(checkAllCell, head.firstChild);
    list.appendChild(head);

    // Rows: active plugins first, then inactive, each under a group header
    // (only when both groups are present, i.e. not in the Active/Inactive views).
    var act = rows.filter(function (r) { return r.active; });
    var ina = rows.filter(function (r) { return !r.active; });
    if (act.length && ina.length) {
      list.appendChild(pluginGroupHeader(labels.active, act.length));
      act.forEach(function (r) { list.appendChild(renderRowPlugin(r, labels)); });
      list.appendChild(pluginGroupHeader(labels.inactive, ina.length));
      ina.forEach(function (r) { list.appendChild(renderRowPlugin(r, labels)); });
    } else {
      rows.forEach(function (r) { list.appendChild(renderRowPlugin(r, labels)); });
    }
    var empty = el('<div class="dy-tempty" hidden>' + escHtml(L.noMatch || 'No plugin matches your search.') + '</div>');
    list.appendChild(empty);

    // Filters: All / Active / Inactive / Updates as a segmented control, the
    // rarer views (recently active, must-use, drop-ins, auto-update…) in a
    // "More" chip. Search filters the rows instantly (everything is on one page).
    var state = { q: '' };
    var bar = el('<div class="dy-tfilters"></div>');
    if (views) {
      var seg = el('<div class="dy-tfseg"></div>');
      views.filter(function (v) { return PL_MAIN_VIEWS.indexOf(v.key) !== -1; }).forEach(function (v) {
        var b = el('<button type="button" data-view="' + escAttr(v.key) + '"' + (v.current ? ' class="is-active"' : '') + '></button>');
        b.appendChild(document.createTextNode(v.label + ' '));
        if (v.count) { var nn = el('<span class="n"></span>'); nn.textContent = v.count; b.appendChild(nn); }
        b.addEventListener('click', function () { navTo(v.href); });
        seg.appendChild(b);
      });
      bar.appendChild(seg);
      var rest = views.filter(function (v) { return PL_MAIN_VIEWS.indexOf(v.key) === -1; });
      if (rest.length) {
        var curRest = rest.filter(function (v) { return v.current; })[0];
        var more = el('<button type="button" class="dy-tfchip dy-tfchip--more' + (curRest ? ' is-set' : '') + '"></button>');
        var mt = el('<span class="dy-tfchip__txt"></span>');
        if (curRest) { var vv = el('<span class="v"></span>'); vv.textContent = curRest.label + (curRest.count ? ' ' + curRest.count : ''); mt.appendChild(vv); }
        else { mt.textContent = L.more || 'More'; }
        more.appendChild(mt);
        more.appendChild(el('<svg class="caret" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>'));
        var pop = el('<div class="dy-tfchip__pop" hidden></div>');
        rest.forEach(function (v) {
          var opt = el('<div class="dy-tfchip__opt' + (v.current ? ' is-current' : '') + '"></div>');
          opt.appendChild(document.createTextNode(v.label + ' '));
          if (v.count) { var cn = el('<span class="n"></span>'); cn.textContent = v.count; opt.appendChild(cn); }
          opt.addEventListener('click', function (e) { e.stopPropagation(); navTo(v.href); });
          pop.appendChild(opt);
        });
        more.appendChild(pop);
        more.addEventListener('click', function (e) { e.stopPropagation(); var wasHidden = pop.hidden; closeAllPops(); pop.hidden = !wasHidden; });
        bar.appendChild(more);
      }
    }
    var nativeSearch = nativeSearchInput();
    var right = el('<div class="dy-tfilters__right"></div>');
    var box = el('<div class="dy-tfsearch">' + svg(ICON.search) + '<input type="search"></div>');
    var q = box.querySelector('input');
    q.placeholder = (nativeSearch && nativeSearch.getAttribute('placeholder')) || (L.search || 'Search…');
    q.setAttribute('aria-label', q.placeholder);
    q.addEventListener('input', function () { state.q = q.value; applySearch(); });
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    right.appendChild(box);
    bar.appendChild(right);
    function applySearch() {
      var needle = (state.q || '').trim().toLowerCase();
      var shown = 0;
      list.querySelectorAll('.dy-trow--plugin').forEach(function (row) {
        var r = row.__model;
        var hay = (r.title + ' ' + r.author + ' ' + r.desc + ' ' + r.slug).toLowerCase();
        var hit = !needle || hay.indexOf(needle) !== -1;
        row.hidden = !hit;
        if (hit) shown++;
      });
      refreshPluginGroups(list);
      empty.hidden = shown > 0;
      refreshBulk();
    }
    var active = buildActive(views, []);

    // Update banner: "N updates available · Update all" (bulk update through
    // the core form: every row with an update is checked, then the native
    // "Apply" button is clicked so wp.updates queues the AJAX updates).
    var banner = el('<div class="dy-tupdates" hidden><span class="dy-tupdates__dot"></span><b class="dy-tupdates__txt"></b><button type="button" class="dy-tupdates__btn">' + svg(ICON.update) + '<span>' + escHtml(L.updateAll || 'Update all') + '</span></button></div>');
    var bannerTxt = banner.querySelector('.dy-tupdates__txt');
    function refreshUpdates() {
      var n = list.querySelectorAll('.dy-trow--plugin.has-update').length;
      banner.hidden = n === 0;
      bannerTxt.textContent = n === 1 ? (L.updatesOne || '1 update available') : (L.updatesMany || '%d updates available').replace('%d', n);
      // Keep the "Updates" view count in sync (the core only updates the admin bar / menu bubbles).
      var ub = bar.querySelector('.dy-tfseg button[data-view="upgrade"] .n');
      if (ub) ub.textContent = n;
    }
    banner.querySelector('.dy-tupdates__btn').addEventListener('click', function () {
      var form = document.querySelector('#bulk-action-form');
      var sel = form ? form.querySelector('select[name="action"]') : null;
      var btn = form ? form.querySelector('#doaction') : null;
      if (!sel || !btn) return;
      rows.forEach(function (r) {
        if (r.checkbox && !r.checkbox.disabled) r.checkbox.checked = !!r.upLink && r.tr.classList.contains('update');
      });
      sel.value = 'update-selected';
      btn.click();
    });
    list.querySelectorAll('.dy-trow--plugin').forEach(function (row) { row.__onUpdated = refreshUpdates; });
    refreshUpdates();

    // Bulk bar (core select re-styled; "Apply" clicks the native button).
    var bulk = buildBulk();
    refreshBulk = function () {
      var boxes = [].slice.call(list.querySelectorAll('.dy-trow__check input')).filter(function (cb) { return !cb.disabled && !cb.closest('.dy-trow').hidden; });
      var n = boxes.filter(function (cb) { return cb.checked; }).length;
      checkAll.checked = boxes.length > 0 && n === boxes.length;
      checkAll.indeterminate = n > 0 && n < boxes.length;
      if (!bulk) return;
      bulk.hidden = n === 0;
      if (bulk.__count) { bulk.__count.innerHTML = '<b>' + n + '</b> ' + escHtml(L.selected || 'selected'); }
    };

    view.appendChild(bar);
    if (active) view.appendChild(active);
    view.appendChild(banner);
    if (bulk) view.appendChild(bulk);
    view.appendChild(list);

    var gear = buildGear(list);
    list.__gear = gear.querySelector('.dy-table-options-panel');

    var anchor = table.closest('.dy-table-wrapper') || table;
    anchor.parentNode.insertBefore(view, anchor);
    var sb = buildSwitchbar(view);
    if (gear) { gear.classList.add('dy-table-options--insb'); sb.insertBefore(gear, sb.firstChild); }
    wireRows(view, list, false);

    // Rows deleted through the core (AJAX delete) disappear from the native
    // table → mirror the removal here.
    pluginObserve(table.querySelector('tbody'), function () {
      var changed = false;
      rows.forEach(function (r) {
        if (r.tr.isConnected) return;
        var row = list.querySelector('.dy-trow[data-id="' + r.id + '"]');
        if (row) { row.remove(); changed = true; }
      });
      if (changed) { refreshPluginGroups(list); refreshUpdates(); refreshBulk(); }
    }, { childList: true });
    return view;
  }

  function build() {
    if (KIND === 'generic') { return buildGeneric(); }
    if (KIND === 'orders') { return buildOrders(); }
    if (KIND === 'plugins') { return buildPlugins(); }
    var rows = buildModel();
    var isTree = KIND === 'post' && SCREEN.hierarchical && !!HIER;
    if (isTree) {
      // Recursive sort BEFORE rail computation: computeTree recalculates the
      // connectors (tee/ell/v) on the new order → consistent tree structure.
      if (treeSort.key) rows = sortTree(rows, treeSort.key, treeSort.dir);
      computeTree(rows);
    }

    var view = el('<div class="dy-treimg"></div>');
    view.classList.add(isTree ? 'dy-treimg--tree' : (KIND === 'user' ? 'dy-treimg--user' : (KIND === 'term' ? 'dy-treimg--term' : 'dy-treimg--flat')));
    if (KIND === 'post' && !isTree && !CLASSCOL) view.classList.add('dy-treimg--nocat');
    // Manual reorder (handles) only in default order: an active column sort
    // (treeSort) takes precedence, grip handles are hidden while it is active.
    if (ORDERABLE && isTree && !treeSort.key) view.classList.add('dy-treimg--orderable');
    if (isTree && treeSort.key) view.classList.add('dy-treimg--sorted');
    if (suppressRowAnimOnce) { view.classList.add('dy-treimg--no-anim'); suppressRowAnimOnce = false; }

    // (2) Liste
    var list = el('<div class="dy-treimg__list"></div>');
    var headEl = buildHead(isTree, list);
    list.appendChild(headEl);
    // One row that fails to render (an unexpected cell from a plugin) must
    // not empty the whole list: it is skipped and reported (audit, 21/10/2026).
    var rendered = 0;
    rows.forEach(function (r, idx) {
      try { list.appendChild(renderRow(r, idx, rows.length, isTree)); rendered++; }
      catch (e) { if (window.console) console.warn('[Double Youpï] Row ' + r.id + ' could not be redesigned:', e); }
    });
    // Nothing to list: say so with WordPress's own sentence ("No posts
    // found.", "No users found."…), which the native table carries in its
    // service row (the list ended at its header: compatibility lab, 24/10/2026).
    if (!rows.length) {
      var none = table.querySelector('tbody > tr.no-items td');
      // A plugin's own empty state (Secure Custom Fields: picture, heading,
      // "Add" button) is kept whole, without its inline script: as text it
      // showed the script's source and lost the button (lab, 27/10/2026).
      if (none && none.querySelector('img, svg, a, button, h1, h2, h3, p')) {
        var rich = el('<div class="dy-tempty dy-tempty--rich" role="status"></div>');
        [].forEach.call(none.childNodes, function (n) {
          if (n.nodeName !== 'SCRIPT') rich.appendChild(n.cloneNode(true));
        });
        list.appendChild(rich);
      } else {
        var noneText = none ? txt(none).trim() : '';
        if (noneText) list.appendChild(el('<div class="dy-tempty" role="status">' + escHtml(noneText) + '</div>'));
      }
    }
    if (KIND === 'post') {
      list.__hiddenCols = {};
      META_COLS.forEach(function (c) { if (c.hidden) list.__hiddenCols[c.id] = true; });
      applyMetaGrid(list);
      var rsT = null;
      window.addEventListener('resize', function () {
        if (!document.body.contains(list)) return;
        clearTimeout(rsT); rsT = setTimeout(function () { applyMetaGrid(list); }, 120);
      });
    }

    // (3) Filtres (segmented + chips + recherche)
    var filters = buildFilters();

    // (4) Gear icon (table options): placed in the SWITCHBAR, next to the
    // "Native/Redesigned view" button (see assembly below). Avoids any
    // overlap with the column header labels, and keeps it in the same
    // location on all screens.
    var gear = buildGear(list);
    list.__gear = gear.querySelector('.dy-table-options-panel');

    // (5) Contextual bulk action bar (hidden until something is checked)
    var bulk = buildBulk();
    refreshBulk = function () {
      if (!bulk) return;
      var n = list.querySelectorAll('.dy-trow__check input:checked').length;
      bulk.hidden = n === 0;
      if (bulk.__count) { bulk.__count.innerHTML = '<b>' + n + '</b> ' + escHtml(L.selected || 'selected'); }
    };

    // Assembly: filters → active filters → bulk → list (the gear now lives
    // in the table header; the native/reimagined toggle lives in the
    // switchbar, outside the view).
    view.appendChild(filters.bar);
    if (filters.active) view.appendChild(filters.active);
    if (bulk) view.appendChild(bulk);
    view.appendChild(list);

    // We insert BEFORE the native wrapper (admin.js wraps the table in
    // `.dy-table-wrapper`) to: (a) not inherit its context (duplicate
    // gear, tooltip clipping), (b) keep the view clean.
    var anchor = table.closest('.dy-table-wrapper') || table;
    anchor.parentNode.insertBefore(view, anchor);
    // Rows in the native table but none here: show the native list rather
    // than an empty one (a tester saw an empty redesigned list, 20/10/2026).
    if (!rendered && nativeRowCount() > 0) fallbackToNative('no row could be redesigned');

    // Single animated toggle bar (always visible, before the view) — same
    // button (and same animation) as generic screens. The gear is placed
    // to the LEFT of the button (visible in reimagined mode only, see CSS).
    var bar = buildSwitchbar(view);
    if (gear) { gear.classList.add('dy-table-options--insb'); bar.insertBefore(gear, bar.firstChild); }

    wireRows(view, list, isTree);
    return view;
  }

  function wireRows(view, list, isTree) {
    var rafPending = false;
    list.querySelectorAll('.dy-trow').forEach(function (row) {
      row.addEventListener('mouseenter', function (e) {
        delete row.dataset.drawerSide;
        updateSide(row, e.clientX, false);
        litPath(row, true);
      });
      row.addEventListener('mousemove', function (e) {
        // While the pointer is over the actions block itself, FREEZE its side.
        // Otherwise moving toward an action would cross the left/right threshold
        // and flip the block under the cursor, dodging the action you're reaching.
        if (e.target && e.target.closest && e.target.closest('.dy-trow__drawer')) return;
        if (rafPending) return; rafPending = true; var cx = e.clientX;
        requestAnimationFrame(function () { rafPending = false; updateSide(row, cx, true); });
      });
      row.addEventListener('mouseleave', function () { delete row.dataset.drawerSide; litPath(row, false); });
      // Keyboard: the drawer is revealed by :focus-within (CSS); anchor it on
      // the right the first time focus enters the row (no pointer position).
      row.addEventListener('focusin', function () {
        if (row.dataset.drawerSide) return;
        var rr = row.getBoundingClientRect();
        updateSide(row, rr.right - 1, false);
      });
      row.addEventListener('focusout', function (e) {
        if (!e.relatedTarget || !row.contains(e.relatedTarget)) { delete row.dataset.drawerSide; }
      });

      // chips → native action (Quick Edit = inline panel, see runAction)
      row.querySelectorAll('.dy-tchip').forEach(function (c) {
        c.addEventListener('click', function (e) { e.stopPropagation(); runAction(c.__action, row); });
      });
      // expand/collapse children
      var ex = row.querySelector('.dy-trow__expand[data-state="expanded"], .dy-trow__expand[data-state="collapsed"]');
      if (ex) ex.addEventListener('click', function (e) {
        e.stopPropagation();
        var wasExpanded = ex.getAttribute('data-state') === 'expanded';
        ex.setAttribute('data-state', wasExpanded ? 'collapsed' : 'expanded');
        ex.setAttribute('data-tip', wasExpanded ? (L.expand || 'Expand') : (L.collapse || 'Collapse'));
        // wasExpanded === true means we are collapsing, so hide descendants.
        toggleChildren(list, row, wasExpanded);
      });
      // chevron reorder buttons
      row.querySelectorAll('.dy-trow__grip-chev').forEach(function (ch) {
        ch.addEventListener('click', function (e) { e.stopPropagation(); if (ch.disabled) return; moveRow(row, ch.getAttribute('data-dir')); });
      });
      var gripBar = row.querySelector('.dy-trow__grip-bar');
      if (gripBar) gripBar.addEventListener('mousedown', function (e) { e.stopPropagation(); startDrag(row, e); });
    });
  }

  // Collapses/expands the descendants of a row (by parent data-id).
  function toggleChildren(list, row, collapse) {
    var id = parseInt(row.getAttribute('data-id'), 10);
    var depth = parseInt(row.getAttribute('data-depth') || '0', 10);
    var cur = row.nextElementSibling;
    while (cur && cur.classList.contains('dy-trow')) {
      var cd = parseInt(cur.getAttribute('data-depth') || '0', 10);
      if (cd <= depth) break;
      cur.style.display = collapse ? 'none' : '';
      cur = cur.nextElementSibling;
    }
  }

  // ---------------------------------------------------------------------
  // "Too many columns" guard in NATIVE view.
  // WP lets plugins stack columns → table crushed and unreadable. We force
  // nothing hidden (native = native):
  //  (1) CONDITIONAL scroll rail: minimum table width only if it overflows
  //      the container → the table scrolls instead of crushing; if it fits,
  //      we do NOT add overflow (otherwise the sticky `thead` would break).
  //  (2) Clean NATIVE options gear, in the switchbar next to the button:
  //      checkboxes reflecting the REAL column state (checked = visible),
  //      live + persistent hiding (WP meta via REST), live counter + red
  //      above the recommended max. (≠ the mock gear in admin.js, which stays hidden.)
  var MAX_COLS = 8;   // recommended max columns for readability
  var MINCOL_W = 130; // minimum width per data column (px) for the scroll rail
  // Columns that can never be hidden (checkbox + primary column per screen).
  var COL_SKIP = { 'column-cb': 1, 'column-title': 1, 'column-fichier': 1, 'column-name': 1, 'column-username': 1, 'column-order_number': 1 };
  var nativeGear = null;

  function nativeColTh() {
    return table.querySelectorAll('thead th[class*="column-"], thead td[class*="column-"]');
  }
  function colIsHidden(th) {
    return th.classList.contains('hidden') || getComputedStyle(th).display === 'none';
  }
  function countVisibleNativeCols() {
    var seen = {}, n = 0;
    nativeColTh().forEach(function (th) {
      var cc = [].slice.call(th.classList).find(function (c) { return c.indexOf('column-') === 0; });
      if (!cc || seen[cc]) return; seen[cc] = 1;
      if (!colIsHidden(th)) n++;
    });
    return n;
  }
  function persistNativeHidden() {
    if (!nativeGear) return;
    var hidden = [];
    nativeGear.querySelectorAll('.dy-table-options-panel li input[type="checkbox"]').forEach(function (cb) {
      if (cb.__col && !cb.checked) hidden.push(cb.__col);
    });
    post(CFG.rest.options, { signature: CFG.signature, hiddenColumns: hidden, hiddenColumnsMeta: SCREEN.hiddenColumnsMeta }, null);
  }
  function updateNativeNote() {
    if (!nativeGear) return;
    var n = countVisibleNativeCols();
    var over = n > MAX_COLS;
    nativeGear.classList.toggle('is-over', over);
    var trig = nativeGear.querySelector('.dy-table-options-trigger');
    var tip = (L.colsCount || '%d columns').replace('%d', n)
      + (over ? ' · ' + (L.colsAdvice || '%d recommended max for readability').replace('%d', MAX_COLS) : '');
    if (trig) { trig.setAttribute('title', tip); trig.setAttribute('aria-label', tip); }
    var panel = nativeGear.querySelector('.dy-table-options-panel');
    var note = panel.querySelector('.dy-cols-note');
    if (over) {
      if (!note) { note = el('<p class="dy-cols-note"></p>'); panel.insertBefore(note, panel.firstChild); }
      note.textContent = tip;
    } else if (note) { note.remove(); }
  }
  function buildNativeGear() {
    var wrap = el('<div class="dy-table-options dy-table-options--insb dy-table-options--native"></div>');
    var trigger = el('<button type="button" class="dy-table-options-trigger" aria-expanded="false" title="' + escAttr(L.screenOptions || 'Screen options') + '">' + svg(ICON.gear) + '</button>');
    var panel = el('<div class="dy-table-options-panel" role="dialog" hidden></div>');
    panel.appendChild(el('<h3>' + escHtml(L.columns || 'Columns') + '</h3>'));
    var ul = el('<ul></ul>');
    var seen = {};
    nativeColTh().forEach(function (th) {
      var cc = [].slice.call(th.classList).find(function (c) { return c.indexOf('column-') === 0; });
      if (!cc || COL_SKIP[cc] || seen[cc]) return; seen[cc] = 1;
      var id = cc.replace('column-', '');
      var li = el('<li><label><input type="checkbox"' + (colIsHidden(th) ? '' : ' checked') + '> ' + escHtml(thLabel(th, id)) + '</label></li>');
      var cb = li.querySelector('input'); cb.__col = id;
      cb.addEventListener('change', function () {
        var hide = !cb.checked;
        table.querySelectorAll('.column-' + id).forEach(function (cell) {
          cell.classList.toggle('hidden', hide);
          cell.style.display = hide ? 'none' : '';
        });
        persistNativeHidden();
        updateNativeNote();
        guardNativeColumns(); // re-measure the scroll rail: hiding columns should remove the min-width/overflow
      });
      ul.appendChild(li);
    });
    panel.appendChild(ul);
    panel.appendChild(el('<h3>' + escHtml(L.pagination || 'Pagination') + '</h3>'));
    var perRow = el('<div class="dy-table-options-panel-row"><label>' + escHtml(L.itemsPerPage || 'Items per page') + '</label><input type="number" class="dy-pp" min="1" max="999" step="5"></div>');
    var pp = perRow.querySelector('input'); pp.value = CFG.perPage || CFG.defaultPerPage || 20;
    pp.addEventListener('change', function () {
      var nn = parseInt(pp.value, 10) || CFG.defaultPerPage;
      post(CFG.rest.options, { signature: CFG.signature, perPage: nn, perPageOption: SCREEN.perPageOption }, function () { location.reload(); });
    });
    panel.appendChild(perRow);
    wrap.appendChild(trigger); wrap.appendChild(panel);
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = trigger.getAttribute('aria-expanded') === 'true';
      closeAllPops();
      trigger.setAttribute('aria-expanded', open ? 'false' : 'true');
      panel.hidden = open;
    });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    return wrap;
  }
  function guardNativeColumns() {
    if (!table) return;
    var n = countVisibleNativeCols();
    if (!n) return; // table hidden (reimagined mode) → nothing to do
    // (1) CONDITIONAL scroll rail (preserves sticky thead when it fits).
    var wrapper = table.closest('.dy-table-wrapper');
    if (wrapper) {
      var need = 44 + Math.max(0, n - 1) * MINCOL_W;
      var avail = wrapper.clientWidth || wrapper.getBoundingClientRect().width;
      if (need > avail + 1) { wrapper.classList.add('dy-cols-guarded'); table.style.minWidth = need + 'px'; }
      else { wrapper.classList.remove('dy-cols-guarded'); table.style.minWidth = ''; }
    }
    // (2) Native gear in the switchbar (built once).
    var bar = document.querySelector('.dy-treimg-switchbar');
    if (!bar) return;
    if (!nativeGear || !bar.contains(nativeGear)) {
      nativeGear = buildNativeGear();
      bar.insertBefore(nativeGear, bar.firstChild);
    }
    updateNativeNote();
  }

  // Accessible names from our tooltips (accessibility audit, 21/10/2026).
  // `data-tip` is drawn by CSS only, so screen readers never heard it: an
  // icon button (switch, chevron, grip) read as an empty button, a status
  // dot as nothing. An element with no text of its own takes its tooltip
  // as `aria-label`, kept in step when the tooltip changes (Expand ↔
  // Collapse). A status dot becomes an image with that name.
  var INTERACTIVE = 'button, a[href], [role="button"], [role="switch"], [tabindex]';
  function nameFromTip(node) {
    var tip = node.getAttribute('data-tip');
    if (!tip || node.textContent.trim() !== '' || node.getAttribute('aria-hidden') === 'true') return;
    var own = node.hasAttribute('data-dy-tipname');
    if (node.hasAttribute('aria-label') && !own) return;
    node.setAttribute('aria-label', tip);
    node.setAttribute('data-dy-tipname', '');
    if (!node.matches(INTERACTIVE) && !node.hasAttribute('role')) node.setAttribute('role', 'img');
  }
  function nameTips(scope) {
    if (!scope || scope.nodeType !== 1) return;
    if (scope.hasAttribute('data-tip')) nameFromTip(scope);
    scope.querySelectorAll('[data-tip]').forEach(nameFromTip);
  }
  if (window.MutationObserver) {
    new MutationObserver(function (records) {
      records.forEach(function (rec) {
        if (rec.type === 'attributes') nameFromTip(rec.target);
        else rec.addedNodes.forEach(nameTips);
      });
    }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-tip'] });
  }

  build();
  guardNativeColumns();
  nameTips(document.body);
})();

/**
 * editor-reimagined.js — "Workshop" direction for the classic editor.
 *
 * We RELOCATE native elements (never cloning, which would duplicate WP
 * logic): #title, #edit-slug-box, and the action buttons (#publish,
 * #save) migrate into a sticky command bar — they remain inside
 * <form id="post">, so native saving works without any hack. The
 * sidebar column (#postbox-container-1) becomes a collapsible inspector
 * (icon rail). Everything else is CSS.
 */
(function () {
  'use strict';
  var CFG = window.doubleyoupiEditor;
  if (!CFG) return;

  var body = document.body;
  var form = document.getElementById('post');
  if (!form) return;

  // ── Icons (stroke = currentColor) ──────────────────────────────────
  var ICON = {
    list:   '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    layoutv:'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M3 9h6"/>',
    save:   '<path d="M20 6 9 17l-5-5"/>',
    link:   '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
    chevR:  '<path d="M9 6l6 6-6 6"/>',
    chevD:  '<path d="M6 9l6 6 6-6"/>',
    rocket: '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09zM12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/>',
    folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    tag:    '<path d="M20.59 13.41 12 22l-9-9V3h10l7.59 7.59a2 2 0 0 1 0 2.82z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    image:  '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
    grid:   '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
    cog:    '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
    ai:     '<path d="M12 3l1.8 4.7L18.5 9l-4.7 1.8L12 15l-1.8-4.2L5.5 9l4.7-1.3L12 3Z"/><path d="M18 14l.9 2.3L21 17l-2.1.7L18 20l-.9-2.3L15 17l2.1-.7L18 14Z"/>',
    seo:    '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'
  };
  function svg(p, cls) { return '<svg class="' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + p + '</svg>'; }
  function el(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; }

  var L = CFG.i18n || {};
  var ST = L.statuses || {};

  // ── REST persistence (personal preferences) ────────────────────────
  function post(url, payload) {
    if (!url) return Promise.resolve();
    try {
      return fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': (CFG.rest && CFG.rest.nonce) || '' },
        body: JSON.stringify(payload || {})
      }).then(function (r) { return r; }, function () {});
    } catch (e) { return Promise.resolve(); }
  }
  function setMode(mode) {
    // IMPORTANT: we wait for the preference to be WRITTEN server-side before
    // reloading — otherwise the reload races the fetch and the mode does not change.
    var p = post(CFG.rest && CFG.rest.mode, { signature: CFG.signature, mode: mode });
    var done = function () { location.reload(); };
    if (p && p.then) { p.then(done, done); } else { done(); }
  }
  function setInspector(state) { post(CFG.rest && CFG.rest.inspector, { state: state }); }

  // =====================================================================
  //  Native↔redesigned toggle button — CONSISTENT with the redesigned tables
  //  (same `.dy-treimg__toggle` component: accent in redesigned mode, 360°
  //  ring + wipe). Difference: the editor RELOADS the page (setMode → reload),
  //  so the animation plays WHILE the preference is being saved, then the
  //  page reloads in the new mode. The button shows the CURRENT mode.
  // =====================================================================
  function makeViewToggle(currentIsNative, targetMode) {
    var ico = svg(currentIsNative ? ICON.list : ICON.layoutv);
    var txt = currentIsNative ? (L.nativeView || 'Native view') : (L.redesignedView || 'Redesigned view');
    var tip = currentIsNative ? (L.switchToReimagined || '') : (L.switchToNative || '');
    var btn = el('<button type="button" class="dy-treimg__toggle dy-ed-headtoggle" data-tip="' + esc(tip) + '">' +
      '<span class="dy-treimg__toggle-ring" aria-hidden="true"></span>' +
      '<span class="dy-treimg__toggle-ico">' + ico + '</span>' +
      '<span class="dy-treimg__toggle-txt">' + esc(txt) + '</span>' +
      '<span class="dy-treimg__toggle-wipe" aria-hidden="true"></span>' +
      '</button>');
    btn.addEventListener('click', function () {
      if (btn.__flip) return; btn.__flip = true;
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) { setMode(targetMode); return; }
      btn.classList.add('is-flipping', 'is-covering'); // 360° ring + wipe covers
      setMode(targetMode);                             // post + reload (animation plays during)
    });
    return btn;
  }

  // =====================================================================
  //  Native mode: we only inject the "Redesigned view" button.
  // =====================================================================
  function buildNativeToggle() {
    var anchor = document.querySelector('.wrap > h1, #wpbody-content .wrap') || form;
    var bar = el('<div class="dy-ed-nativebar"></div>');
    var btn = makeViewToggle(true, 'reimagined'); // current = native → switch to redesigned
    bar.appendChild(btn);
    var poststuff = document.getElementById('poststuff');
    if (poststuff && poststuff.parentNode) poststuff.parentNode.insertBefore(bar, poststuff);
    else if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(bar, anchor.nextSibling);
  }

  function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }

  // Disarm the WP/TinyMCE beforeunload guard (unsaved changes) before a
  // voluntary submission (Publish / Save).
  function releaseUnloadGuard() {
    try { window.onbeforeunload = null; } catch (e) {}
    // WP/TinyMCE bind via jQuery (`beforeunload.edit-post`, etc.) → we remove
    // all beforeunload handlers regardless of their namespace.
    try { if (window.jQuery) { window.jQuery(window).off('beforeunload'); } } catch (e) {}
  }

  // =====================================================================
  //  Redesigned mode: command bar + inspector.
  // =====================================================================
  function statusInfo() {
    var inp = document.getElementById('original_post_status') || document.getElementById('post_status') || document.getElementById('hidden_post_status');
    var key = inp && inp.value ? String(inp.value) : (CFG.screen && CFG.screen.isNew ? 'auto-draft' : 'draft');
    var cls = { 'publish': 'publish', 'future': 'future', 'pending': 'pending', 'private': 'private' }[key] || 'draft';
    var label = (ST && ST[key]) || (ST && ST.draft) || 'Draft';
    return { cls: cls, label: label };
  }

  function buildCmdbar() {
    var bar = el('<div class="dy-ed-cmdbar" role="region" aria-label="' + esc(L.settings || 'Actions') + '"></div>');
    var titlewrap = el('<div class="dy-ed-cmdbar__titlewrap"></div>');

    // (1) Native #title → relocated.
    var title = document.getElementById('title');
    if (title) {
      title.classList.add('dy-ed-cmdbar__title');
      if (!title.getAttribute('placeholder')) title.setAttribute('placeholder', L.titlePlaceholder || 'Add a title');
      titlewrap.appendChild(title);
    }
    // (2) Native #edit-slug-box → relocated (present as soon as a slug exists).
    var slug = document.getElementById('edit-slug-box');
    var slugWrap = el('<div class="dy-ed-cmdbar__slug">' + svg(ICON.link, 'dy-ed-slugi') + '</div>');
    if (slug && slug.textContent.trim()) { slugWrap.appendChild(slug); }
    else { slugWrap.appendChild(el('<span class="dy-ed-slug-empty">' + esc(L.permalink || 'Permalink') + '</span>')); }
    titlewrap.appendChild(slugWrap);
    bar.appendChild(titlewrap);

    // (3) No status badge here: the status is already shown by the
    // eyebrow below the H1 (otherwise "Draft/Published" would be duplicated).

    // (4) Actions (relocated from #submitdiv → remain inside the form).
    var actions = el('<div class="dy-ed-cmdbar__actions"></div>');
    var save = document.getElementById('save');           // "Save Draft"
    var publish = document.getElementById('publish');      // Publish / Update
    var preview = document.getElementById('post-preview'); // Preview (link)
    // On ALREADY published content, "Save Draft" (#save) is redundant with
    // "Update" (#publish): both submit name="save" → same action. We keep
    // "Save Draft" only for UNPUBLISHED content, where it has real meaning
    // (save without publishing).
    var st = (document.getElementById('original_post_status') || document.getElementById('post_status') || document.getElementById('hidden_post_status') || {}).value || '';
    var isPublished = (st === 'publish' || st === 'future' || st === 'private');
    if (preview) { preview.classList.add('dy-ed-btn', 'dy-ed-btn--ghost'); actions.appendChild(preview); }
    if (save && !isPublished) { save.classList.add('dy-ed-btn', 'dy-ed-btn--ghost'); actions.appendChild(save); }
    if (publish) { publish.classList.add('dy-ed-btn', 'dy-ed-btn--primary'); actions.appendChild(publish); }
    // WordPress disarms its "unsaved changes" guard (beforeunload) via a
    // DELEGATED handler on #submitpost. By relocating #publish / #save
    // outside of #submitpost, that handler no longer covers them → a "leave
    // the page?" prompt appears at publish time. We disarm the guard ourselves
    // on click of our submit buttons (the form#post fires right after).
    [save, publish].forEach(function (btn) {
      if (!btn) return;
      btn.addEventListener('click', releaseUnloadGuard);
    });
    // The "Native view" toggle is NO LONGER in the bar: it lives on the
    // H1 row (see buildHeadRow), all the way to the right.

    bar.appendChild(actions);
    return bar;
  }

  // Header: we wrap the WP H1 + the eyebrow stays below it; the
  // "Native view" toggle is placed on the H1 row, on the right.
  function buildHeadRow() {
    var h1 = document.querySelector('.wrap > h1.wp-heading-inline') || document.querySelector('.wrap > h1');
    if (!h1 || h1.closest('.dy-ed-headrow')) return;
    var row = el('<div class="dy-ed-headrow"></div>');
    h1.parentNode.insertBefore(row, h1);
    row.appendChild(h1);
    var toggle = makeViewToggle(false, 'native'); // current = redesigned → switch to native
    row.appendChild(toggle);
  }

  // RankMath "Content AI": moved OUT of #side-sortables, as a separate
  // CARD at the end of the inspector (see buildInspector → we isolate it).
  function relocateContentAI(side) {
    var box = document.getElementById('rank_math_metabox_content_ai');
    var c1 = document.getElementById('postbox-container-1');
    if (box && c1 && side && side.contains(box)) {
      c1.appendChild(box);            // leaves #side-sortables, becomes a sibling of the settings panel
      box.classList.add('dy-ed-ai-card');
    }
  }

  // Page builder buttons (Elementor / Bricks) grouped in a card
  // above the content box.
  function buildBuildersBox() {
    var content = document.getElementById('post-body-content');
    if (!content) return;
    var editor = document.getElementById('postdivrich') || document.getElementById('wp-content-editor-container') || document.getElementById('postdiv');
    var found = [];
    // Bricks FIRST: we move the native `.bricks-classic-editor-wrapper`
    // (NOT the admin bar link). Then Elementor.
    var bricks = document.querySelector('.bricks-classic-editor-wrapper');
    if (bricks) found.push(bricks);
    var el1 = document.getElementById('elementor-switch-mode'); if (el1) found.push(el1);
    if (!found.length) return;
    var box = el('<div class="dy-ed-builders"></div>');
    found.forEach(function (n) { box.appendChild(n); });
    if (editor && editor.parentNode === content) content.insertBefore(box, editor);
    else content.insertBefore(box, content.firstChild);
  }

  // Icon for a sidebar metabox based on its id.
  function iconForBox(id) {
    if (!id) return ICON.cog;
    if (id === 'submitdiv') return ICON.rocket;
    if (id === 'pageparentdiv' || id.indexOf('page_template') !== -1) return ICON.layoutv;
    if (id === 'categorydiv' || id.indexOf('categorychecklist') !== -1) return ICON.folder;
    if (id.indexOf('tagsdiv') !== -1) return ICON.tag;
    if (id === 'postimagediv') return ICON.image;
    if (id === 'formatdiv') return ICON.grid;
    if (id.indexOf('content_ai') !== -1) return ICON.ai;
    if (id.indexOf('wpseo') !== -1 || id.indexOf('rank_math') !== -1) return ICON.seo;
    if (id.indexOf('static') !== -1) return ICON.save;
    return ICON.cog;
  }

  function buildInspector() {
    var container = document.getElementById('postbox-container-1');
    if (!container) return;
    container.classList.add('dy-ed-inspector');
    var settings = container.querySelector('#side-sortables') || container.querySelector('.meta-box-sortables');
    if (settings) settings.classList.add('dy-ed-inspector__card');

    // Content AI → separate card (taken out of #side-sortables).
    relocateContentAI(settings);

    // Header (title + collapse) → INSIDE the settings card.
    if (settings) {
      var head = el('<div class="dy-ed-inspector__head"><span class="dy-ed-inspector__title">' + esc(L.settings || 'Settings') + '</span></div>');
      var collapse = el('<button type="button" class="dy-ed-inspector__collapse" aria-label="' + esc(L.collapse || 'Collapse') + '">' + svg(ICON.chevR) + '</button>');
      collapse.addEventListener('click', function () { toggleInspector(); });
      head.appendChild(collapse);
      settings.insertBefore(head, settings.firstChild);
    }

    // Decoration of each section (icon + chevron + individual collapse).
    container.querySelectorAll('.postbox').forEach(function (boxEl) { decorateBox(boxEl, true); });
  }

  // Decorate a metabox: icon (inspector), rename submitdiv, collapse CHEVRON
  // on the right ("Publishing options" style) + header click that collapses
  // the section (or expands the inspector if it is in rail mode).
  function decorateBox(boxEl, withIcon) {
    var hdr = boxEl.querySelector('.postbox-header') || boxEl.querySelector('h2.hndle, h3.hndle, .hndle');
    if (!hdr || hdr.dataset.dyDecorated) return;
    hdr.dataset.dyDecorated = '1';
    if (withIcon && !hdr.querySelector('.dy-ed-secic')) {
      hdr.insertBefore(el(svg(iconForBox(boxEl.id), 'dy-ed-secic')), hdr.firstChild);
    }
    if (boxEl.id === 'submitdiv') {
      var h = boxEl.querySelector('.hndle, h2, h3');
      if (h) { [].slice.call(h.childNodes).forEach(function (n) { if (n.nodeType === 3 && n.textContent.trim()) n.textContent = ' ' + (L.publishOptions || 'Publishing options'); }); }
    }
    // Collapse chevron (reflects .closed state via CSS).
    if (!hdr.querySelector('.dy-ed-chev')) {
      hdr.appendChild(el('<span class="dy-ed-chev" aria-hidden="true">' + svg(ICON.chevD) + '</span>'));
    }
    hdr.style.cursor = 'pointer';
    // Capture + stopPropagation: we become the SOLE collapse source (otherwise
    // the native WP toggle bound to .hndle also fires → double toggle = no-op).
    hdr.addEventListener('click', function (e) {
      if (e.target.closest('a, button, input, select, textarea, .handle-actions')) return;
      e.stopPropagation();
      if (body.classList.contains('dy-ed-inspector-collapsed')) { e.preventDefault(); toggleInspector(); return; }
      boxEl.classList.toggle('closed');
    }, true);
  }

  // Metaboxes in the NORMAL COLUMN (#postbox-container-2, below the editor
  // on the left): Yoast, Rank Math, Revisions, Excerpt, Slug, Author… All
  // receive the same decoration (chevron + click-to-collapse), without a DY
  // icon. Generalised (previously: only the 2 SEO boxes) → no more "raw"
  // blocks (visible border, no chevron) in this column. The right column
  // (#postbox-container-1) is handled separately by buildInspector().
  function decorateNormalBoxes() {
    var c2 = document.getElementById('postbox-container-2');
    if (!c2) return;
    c2.querySelectorAll('.postbox').forEach(function (b) { decorateBox(b, false); });
  }

  // Move the "normal" column (#postbox-container-2: Yoast, Rank Math,
  // Revisions…) INTO #post-body-content, just below the editor. Combined with
  // the CSS (1-row grid + display:contents), its metaboxes then flow in the
  // same column as the editor with consistent spacing — instead of being
  // pushed to a 2nd grid row (bloated by the inspector, ~900px gap).
  // We do NOT touch the TinyMCE iframe (#postdivrich stays in place; we only
  // APPEND the boxes container after it).
  function relocateNormalColumn() {
    var content = document.getElementById('post-body-content');
    var c2 = document.getElementById('postbox-container-2');
    if (content && c2 && c2.parentNode !== content) content.appendChild(c2);
  }

  // Animated reveal of the editing area. The CSS keeps it COLLAPSED
  // (grid-rows 0fr); TinyMCE (#content_ifr) jumps in height during its init
  // (wpautoresize) → we wait until it is ready, then add `dy-ed-tmce-ready`
  // which animates the reveal (0fr→1fr). Failsafe + Text mode both handled.
  function setupEditorReveal() {
    var container = document.getElementById('wp-content-editor-container');
    if (!container) return;
    var wrap = document.getElementById('wp-content-wrap');
    var opened = false;
    var failsafe = setTimeout(open, 3500);
    function open() {
      if (opened) return;
      opened = true;
      clearTimeout(failsafe);
      // TinyMCE's autoresize plays its jittery sequence right after init.
      // A fixed delay lets this sequence FINISH (the poll alone was catching
      // a false plateau mid-sequence and opening too early); the subsequent
      // poll only serves as a net to confirm final stability.
      setTimeout(startPoll, 500);
    }
    function startPoll() {
      // TinyMCE's autoresize causes #content_ifr height to OSCILLATE after
      // init (growing/shrinking). We POLL the container's natural height
      // (scrollHeight, measurable even while collapsed via hidden overflow)
      // as long as it changes; we only animate the reveal once STABLE →
      // the entire jittery phase happens while it is collapsed (invisible).
      var c = container, last = -1, stable = 0, ticks = 0;
      (function poll() {
        var h = c.scrollHeight;
        if (Math.abs(h - last) <= 1) { stable++; } else { stable = 0; last = h; }
        if (stable >= 4 || ++ticks > 90) { animateOpen(); return; } // stable ~4 frames OR ~1.5s max
        requestAnimationFrame(poll);
      })();
    }
    function animateOpen() {
      var c = container;
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      // Remove the CSS `height:0` constraint → height reverts to auto.
      body.classList.add('dy-ed-tmce-ready');
      if (reduce) return;                       // no animation: instant reveal
      var target = c.scrollHeight;              // final height (stabilised by the poll)
      if (!target) return;
      // FLIP pattern: lock the START at 0 without transition (reflow), then
      // animate to the target. All synchronous → no paint at the final size.
      c.style.transition = 'none';
      c.style.height = '0px';
      c.style.overflow = 'hidden';
      void c.offsetHeight;                      // commit the start at 0
      c.style.transition = 'height 460ms cubic-bezier(.16, 1, .3, 1)';
      c.style.height = target + 'px';
      var ft;
      var finish = function (e) {
        // IMPORTANT: ignore transitionend events that BUBBLE from TinyMCE
        // children (animated buttons/toolbar) — otherwise `finish` runs too
        // early and cuts the animation (height snaps to auto). We only react
        // to the `height` transition OF the container itself.
        if (e && (e.target !== c || e.propertyName !== 'height')) return;
        c.style.transition = '';
        c.style.height = '';                    // → auto: follows autoresize from now on
        c.style.overflow = '';
        c.removeEventListener('transitionend', finish);
        clearTimeout(ft);
      };
      ft = setTimeout(function () { finish(); }, 760); // failsafe if transitionend is missed
      c.addEventListener('transitionend', finish);
    }
    // Text mode (visual editor disabled / HTML tab): no iframe to wait for.
    if (wrap && wrap.classList.contains('html-active')) { open(); return; }
    // Visual editor already initialised (our script ran after TinyMCE)?
    var ed = window.tinymce && window.tinymce.get && window.tinymce.get('content');
    if (ed && ed.initialized) { open(); return; }
    // Otherwise, wait for the "content" editor to initialise.
    if (window.jQuery) {
      window.jQuery(document).on('tinymce-editor-init.dyedreveal', function (e, editor) {
        if (editor && editor.id === 'content') open();
      });
    }
    if (window.tinymce && window.tinymce.on) {
      window.tinymce.on('AddEditor', function (e) {
        if (e.editor && e.editor.id === 'content') { e.editor.on('init', open); }
      });
    }
  }

  function toggleInspector() {
    var collapsed = body.classList.toggle('dy-ed-inspector-collapsed');
    setInspector(collapsed ? 'collapsed' : 'open');
  }

  function buildReimagined() {
    body.classList.add('dy-ed-booting');
    buildHeadRow();
    var poststuff = document.getElementById('poststuff');
    var bar = buildCmdbar();
    if (poststuff && poststuff.parentNode) {
      poststuff.parentNode.insertBefore(bar, poststuff);
    } else {
      form.insertBefore(bar, form.firstChild);
    }
    buildBuildersBox();    // Elementor/Bricks grouped above the content
    buildInspector();      // (relocates Content AI as a separate card internally)
    decorateNormalBoxes(); // Normal column (left): chevron + collapse on ALL blocks
    relocateNormalColumn();// … then flow it below the editor (same column, consistent spacing)
    setupEditorReveal();   // animated reveal of the editing area when TinyMCE is ready
    // Animated entrance (next frame to trigger the CSS transition).
    requestAnimationFrame(function () { body.classList.remove('dy-ed-booting'); body.classList.add('dy-ed-ready'); });
  }

  // ── Boot ────────────────────────────────────────────────────────────
  function init() {
    if (body.classList.contains('dy-editor-reimagined')) buildReimagined();
    else if (body.classList.contains('dy-editor-native')) buildNativeToggle();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

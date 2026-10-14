/**
 * Admin · Double Youpi — admin JS
 *
 * Strictly progressive enhancement. The CSS handles the full visual
 * design; this script adds optional interactive features:
 *
 *   1. Theme toggle (dark ↔ light) on the #wp-admin-bar-theme-toggle node.
 *   2. Density toggle (compact) via Cmd/Ctrl+J — power-user only.
 *   3. Active-line body class — sets body.dy-line-<name> from the
 *      currently-active rail item so eyebrow + welcome stripe pick
 *      up the right color.
 *   4. Resizable rail — drag the right edge, snap on release, toggle
 *      button at the bottom, localStorage state. (No keyboard shortcut —
 *      Cmd/Ctrl+K stays with the native WP 7.0 command palette.)
 *   5. Rail tooltip — floats next to each icon while the rail is in
 *      collapsed state.
 *   6. Chip-bar animated underline — slides between .dy-chip nodes.
 *
 * All features degrade gracefully — disabling JS leaves a fully
 * usable, fully styled admin.
 */

(function () {
  'use strict';

  const STORAGE_THEME    = 'dy-admin-theme';
  const STORAGE_DENSITY  = 'dy-admin-density';
  const STORAGE_RAIL     = 'dy-admin-rail';

  const body = document.body;
  if (!body || !body.classList.contains('dy-admin')) return;

  // ── 0. Notice reveal ──────────────────────────────────
  //
  // Admin notices (`.notice`, legacy `.updated` / `.error`) are
  // collapsed by default in CSS (height: 0, padding: 0, opacity: 0)
  // → invisible and taking no space in the layout at first paint.
  //
  // After ~1s, we add the `.dy-notice-revealed` class → the CSS
  // height: 0 → auto transition expands the notice and progressively
  // pushes the content below it down. The title and everything above
  // remain fixed in place.
  //
  // Delay aligned with the root deblur timing: ~1s after boot, the
  // eye has finished absorbing the title and main content — that is
  // the right moment to reveal the notice without interrupting reading.
  (function () {
    // `.hide-if-js` notices only address browsers without JavaScript. Our
    // notice styles outrank core's `.js .hide-if-js` rule, so we hide them
    // here, otherwise the media grid would say it needs JavaScript.
    document.querySelectorAll('.notice.hide-if-js, div.updated.hide-if-js, div.error.hide-if-js')
      .forEach(function (n) { n.style.display = 'none'; });
    const notices = document.querySelectorAll('.notice:not(.hide-if-js), div.updated:not(.hide-if-js), div.error:not(.hide-if-js)');
    if (!notices.length) return;
    setTimeout(function () {
      notices.forEach(function (n) { n.classList.add('dy-notice-revealed'); });
    }, 1000);
  })();

  // ── 0. Boot pages ─────────────────────────────────────
  // Core's modern screens (Connectors, Font Library) and third-party
  // @wordpress/build pages render a React app in `.boot-layout-container`.
  // Flagged for scripts; the CSS keys off body:has() and needs no JS.
  if (document.querySelector('.boot-layout-container')) body.classList.add('dy-boot-page');

  // ── 1. Theme ──────────────────────────────────────────
  function applyTheme(theme) {
    if (theme === 'light') body.classList.add('dy-theme-light');
    else                   body.classList.remove('dy-theme-light');
    // Let third-party integrations follow the theme (e.g. LiteSpeed Cache's
    // own dark mode, see LiteSpeedTheme.php).
    try {
      document.dispatchEvent(new CustomEvent('doubleyoupi:theme', { detail: { theme: theme } }));
    } catch (_) {}
  }
  function readTheme() {
    try { return localStorage.getItem(STORAGE_THEME) || 'dark'; }
    catch (e) { return 'dark'; }
  }
  applyTheme(readTheme());

  const themeBtn = document.querySelector('#wp-admin-bar-theme-toggle .ab-item');
  if (themeBtn) {
    themeBtn.addEventListener('click', function (e) {
      e.preventDefault();
      const next = body.classList.contains('dy-theme-light') ? 'dark' : 'light';
      applyTheme(next);
      try { localStorage.setItem(STORAGE_THEME, next); } catch (_) {}
    });
  }

  // ── 1bis. Classic editor (TinyMCE): theme the iframe canvas ─────────
  // A `wp_editor()` field renders inside an iframe: our custom properties
  // stop at the boundary, so the "Visual" tab stayed a white slab in the
  // dark theme while the "Text" tab of the SAME field was dark (seen on
  // the SCF options page, 14/09/2026). The iframe loads TinyMCE's generic
  // admin canvas (`wp-content.css`), not the site's front-end styles, so
  // recolouring it costs no preview fidelity.
  //
  // We resolve the tokens in the parent document (where they exist) and
  // inject the result, which keeps the canvas in step with the live theme
  // toggle and with editors created later (ACF repeaters, flexible rows).
  (function () {
    const TOKENS = ['--dya-paper', '--dya-paper-2', '--dya-ink', '--dya-ink-mute', '--dya-line', '--dya-accent'];
    function sheet() {
      const cs = getComputedStyle(body);
      const v = {};
      TOKENS.forEach(function (t) { v[t] = (cs.getPropertyValue(t) || '').trim(); });
      if (!v['--dya-paper'] || !v['--dya-ink']) return '';
      return 'html,body#tinymce{background:' + v['--dya-paper'] + ' !important;color:' + v['--dya-ink'] + ' !important;}'
        + 'body#tinymce a{color:' + v['--dya-accent'] + ';}'
        + 'body#tinymce hr{border-color:' + v['--dya-line'] + ';}'
        + 'body#tinymce blockquote{border-left:3px solid ' + v['--dya-line'] + ';color:' + v['--dya-ink-mute'] + ';}'
        + 'body#tinymce code,body#tinymce pre{background:' + v['--dya-paper-2'] + ';color:' + v['--dya-ink'] + ';}'
        + 'body#tinymce table td,body#tinymce table th{border-color:' + v['--dya-line'] + ';}'
        + 'body#tinymce .mce-item-table,body#tinymce .mce-item-table td{border-color:' + v['--dya-line'] + ';}';
    }
    function paint(doc) {
      if (!doc || !doc.head) return;
      let st = doc.getElementById('dy-mce-theme');
      if (!st) { st = doc.createElement('style'); st.id = 'dy-mce-theme'; doc.head.appendChild(st); }
      st.textContent = sheet();
    }
    function syncAll() {
      document.querySelectorAll('iframe[id$="_ifr"]').forEach(function (f) {
        try { paint(f.contentDocument); } catch (_) {}
      });
    }
    let hooked = false;
    function hook() {
      if (hooked || !window.tinymce || typeof window.tinymce.on !== 'function') return false;
      hooked = true;
      window.tinymce.on('AddEditor', function (e) {
        if (!e.editor || typeof e.editor.on !== 'function') return;
        e.editor.on('init', function () { try { paint(e.editor.getDoc()); } catch (_) {} });
      });
      syncAll();
      return true;
    }
    if (!hook()) {
      // admin.js can run before TinyMCE is parsed: retry briefly, then stop.
      let tries = 0;
      const t = setInterval(function () { if (hook() || ++tries > 40) clearInterval(t); }, 250);
    }
    document.addEventListener('doubleyoupi:theme', syncAll);
    window.addEventListener('load', syncAll);
    // Filet : toute bascule de thème qui ne passerait pas par `applyTheme`
    // (script tiers, outil de recette) laisserait sinon le canevas sur
    // l'ancienne palette.
    try {
      let last = body.classList.contains('dy-theme-light');
      new MutationObserver(function () {
        const now = body.classList.contains('dy-theme-light');
        if (now !== last) { last = now; syncAll(); }
      }).observe(body, { attributes: true, attributeFilter: ['class'] });
    } catch (_) {}
  })();

  // ── 2. Density toggle ────────────────────────────────
  function applyDensity(d) {
    if (d === 'compact') body.classList.add('dy-density-compact');
    else                 body.classList.remove('dy-density-compact');
  }
  try { applyDensity(localStorage.getItem(STORAGE_DENSITY) || 'comfortable'); }
  catch (_) {}

  document.addEventListener('keydown', function (e) {
    const k = (e.key || '').toLowerCase();
    if (k !== 'j' || !(e.metaKey || e.ctrlKey)) return;
    const tag = document.activeElement && document.activeElement.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || (document.activeElement && document.activeElement.isContentEditable)) return;
    e.preventDefault();
    const next = body.classList.contains('dy-density-compact') ? 'comfortable' : 'compact';
    applyDensity(next);
    try { localStorage.setItem(STORAGE_DENSITY, next); } catch (_) {}
  });

  // ── 3. Active-line body class ────────────────────────
  const lineClasses = ['line-content', 'line-commerce', 'line-audience', 'line-site', 'line-system', 'line-developer'];
  function syncLineBodyClass() {
    const active = document.querySelector(
      '#adminmenu li.menu-top.current, #adminmenu li.menu-top.wp-has-current-submenu, #adminmenu li.menu-top.is-active'
    );
    if (!active) return;
    lineClasses.forEach(c => body.classList.remove('dy-' + c));
    lineClasses.forEach(c => { if (active.classList.contains(c)) body.classList.add('dy-' + c); });
  }
  syncLineBodyClass();

  // ── 4. Resizable rail ────────────────────────────────
  // The rail's width is driven by the --dya-rail-w-current CSS variable.
  // It defaults to var(--dya-rail-w) (the fluid collapsed clamp) so on
  // first load the rail matches the production layout exactly. JS
  // takes over via setProperty() once the user interacts.
  const wpwrap = document.getElementById('wpwrap');
  const rail   = document.getElementById('adminmenuwrap');
  const handle = rail && rail.querySelector('.dy-rail-handle');
  const toggle = rail && rail.querySelector('.dy-rail-toggle');
  const ghost  = document.querySelector('.dy-rail-ghost');
  const markerC = document.querySelector('.dy-rail-snap-marker[data-target="collapsed"]');
  const markerE = document.querySelector('.dy-rail-snap-marker[data-target="expanded"]');

  // The lab page sets a permanent helper element to resolve the
  // clamp() of --dya-rail-w into a real pixel value. Same trick here:
  // getComputedStyle on a custom property returns the raw STRING for
  // unregistered properties, not the resolved length. A real element
  // styled with the var forces the engine to compute the clamp.
  let sizeHelper = null;
  let _collapsedW = 0;

  function ensureSizeHelper() {
    if (sizeHelper) return sizeHelper;
    sizeHelper = document.createElement('div');
    sizeHelper.setAttribute('aria-hidden', 'true');
    sizeHelper.style.cssText =
      'position:absolute;left:-9999px;top:0;height:0;width:var(--dya-rail-w);' +
      'pointer-events:none;visibility:hidden;';
    document.body.appendChild(sizeHelper);
    return sizeHelper;
  }

  function recomputeCollapsedWidth() {
    const h = ensureSizeHelper();
    const w = h.getBoundingClientRect().width;
    _collapsedW = (w > 0) ? w : 64;
    return _collapsedW;
  }

  function getCollapsedWidth() {
    return _collapsedW || recomputeCollapsedWidth();
  }

  // Read expanded snap target from CSS (--dya-rail-w-expanded). Same
  // trick as collapsed: rely on a helper since the token can be a
  // calc() expression in theory.
  let _expandedW = 240;
  function recomputeExpandedWidth() {
    const probe = document.createElement('div');
    probe.style.cssText = 'position:absolute;left:-9999px;top:0;height:0;width:var(--dya-rail-w-expanded);visibility:hidden;';
    document.body.appendChild(probe);
    const w = probe.getBoundingClientRect().width;
    probe.remove();
    _expandedW = (w > 0) ? w : 240;
    return _expandedW;
  }
  function getExpandedWidth() { return _expandedW || recomputeExpandedWidth(); }

  function expandedThreshold() { return getCollapsedWidth() + 36; }
  function railMaxWidth()      { return 280; }
  const RETRACT_TOL = 28; // commit-on-start retract zone

  // Only enable resize if all the required DOM is present.
  if (rail && handle && toggle && wpwrap) {
    recomputeCollapsedWidth();
    recomputeExpandedWidth();
    initRailResize();
  }

  function initRailResize() {
    // ── State persistence — store the STATE NAME, not the pixel
    // value, so collapsed always resolves to the current fluid clamp.
    function readStoredState() {
      try {
        const raw = localStorage.getItem(STORAGE_RAIL);
        if (raw === 'expanded' || raw === 'collapsed') return raw;
        // legacy numeric value: map to state based on threshold
        const v = parseInt(raw || '', 10);
        if (isFinite(v) && v >= expandedThreshold()) return 'expanded';
        if (isFinite(v)) return 'collapsed';
      } catch (e) {}
      return null;
    }
    function writeStoredState(state) {
      try { localStorage.setItem(STORAGE_RAIL, state); } catch (e) {}
    }
    function widthForState(state) {
      return state === 'expanded' ? getExpandedWidth() : getCollapsedWidth();
    }

    function setWidth(w, opts) {
      opts = opts || {};
      const min = getCollapsedWidth();
      w = Math.max(min, Math.min(railMaxWidth(), w));
      // IMPORTANT: set on document.documentElement (= html), NOT on body.
      // `--dya-rail-gutter` is defined on `:root` (= html) and uses
      // `var(--dya-rail-w-current)` when resolved. CSS custom properties are
      // resolved ON THE ELEMENT THAT DEFINES the property that uses them (not
      // on the element that inherits them via descendance). Setting on body
      // left html holding the prepaint value (240px in expanded mode) → gutter
      // computed with 240 even after collapse → items misaligned.
      document.documentElement.style.setProperty('--dya-rail-w-current', w + 'px');
      const state = w >= expandedThreshold() ? 'expanded' : 'collapsed';
      body.dataset.railState = state;
      if (opts.persist) writeStoredState(state);
    }

    function animateTo(targetW, persist) {
      wpwrap.classList.add('is-animating');
      setWidth(targetW, { persist: !!persist });
      window.setTimeout(function () {
        wpwrap.classList.remove('is-animating');
      }, 420);
    }

    function isCurrentlyExpanded() {
      return rail.getBoundingClientRect().width >= expandedThreshold();
    }

    function positionMarkers() {
      if (!markerC || !markerE) return;
      const rect = rail.getBoundingClientRect();
      markerC.style.left = (rect.left + getCollapsedWidth()) + 'px';
      markerE.style.left = (rect.left + getExpandedWidth()) + 'px';
    }

    // ── Commit-on-start snap logic ─────────────────────
    // Once you start dragging from one state, the gesture is committed
    // to the OTHER state unless you retract within RETRACT_TOL of your
    // starting position.
    let isDragging       = false;
    let railLeft         = 0;
    let startWidth       = 0;
    let startedCollapsed = true;

    function computeIntendedTarget(currentW) {
      const collapsedW = getCollapsedWidth();
      const expandedW  = getExpandedWidth();
      if (startedCollapsed) {
        return (currentW <= collapsedW + RETRACT_TOL) ? collapsedW : expandedW;
      } else {
        return (currentW >= expandedW - RETRACT_TOL) ? expandedW : collapsedW;
      }
    }

    function onPointerDown(ev) {
      if (ev.button !== undefined && ev.button !== 0) return;
      ev.preventDefault();
      isDragging = true;
      body.classList.add('is-resizing');
      wpwrap.classList.remove('is-animating');

      const rect = rail.getBoundingClientRect();
      railLeft   = rect.left;
      startWidth = rect.width;
      startedCollapsed = startWidth < expandedThreshold();

      positionMarkers();
      if (handle.setPointerCapture) handle.setPointerCapture(ev.pointerId);

      document.addEventListener('pointermove', onPointerMove);
      document.addEventListener('pointerup',   onPointerUp,   { once: true });
      document.addEventListener('pointercancel', onPointerUp, { once: true });
    }

    function onPointerMove(ev) {
      if (!isDragging) return;
      const collapsedW = getCollapsedWidth();
      const w = ev.clientX - railLeft;
      setWidth(w);

      if (ghost) {
        const clamped = Math.max(collapsedW, Math.min(railMaxWidth(), w));
        ghost.style.left = (railLeft + clamped) + 'px';
      }

      const target = computeIntendedTarget(Math.max(collapsedW, Math.min(railMaxWidth(), w)));
      if (markerC) markerC.classList.toggle('is-target', target === collapsedW);
      if (markerE) markerE.classList.toggle('is-target', target === getExpandedWidth());
    }

    function onPointerUp() {
      if (!isDragging) return;
      isDragging = false;
      document.removeEventListener('pointermove', onPointerMove);
      body.classList.remove('is-resizing');
      if (markerC) markerC.classList.remove('is-target');
      if (markerE) markerE.classList.remove('is-target');

      const w = rail.getBoundingClientRect().width;
      animateTo(computeIntendedTarget(w), true);
    }

    handle.addEventListener('pointerdown', onPointerDown);

    // Double-click on the handle = toggle. Fires AFTER the two single
    // clicks have each snapped to the current state (no-op visually
    // because the width didn't change), so the user perceives one
    // smooth animation to the opposite state.
    handle.addEventListener('dblclick', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      animateTo(isCurrentlyExpanded() ? getCollapsedWidth() : getExpandedWidth(), true);
    });

    // Keyboard on handle: arrows nudge / Enter toggles.
    handle.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') {
        ev.preventDefault();
        const delta = ev.key === 'ArrowRight' ? 8 : -8;
        animateTo(rail.getBoundingClientRect().width + delta, true);
      } else if (ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault();
        animateTo(isCurrentlyExpanded() ? getCollapsedWidth() : getExpandedWidth(), true);
      }
    });

    // Toggle button click. (No keyboard shortcut: Cmd/Ctrl+K is left to the
    // native WordPress 7.0 command palette — the rail toggles via this button
    // or by dragging the handle.)
    toggle.addEventListener('click', function () {
      animateTo(isCurrentlyExpanded() ? getCollapsedWidth() : getExpandedWidth(), true);
    });

    // ── Viewport constraint ────────────────────────────
    // Below 900px the design system shifts to a tighter layout
    // (rail icons shrink, chip bar compresses). The expanded rail
    // (240 px) would eat almost half the viewport — force-collapse.
    // Preference in localStorage is preserved; when the viewport
    // widens again we restore the expanded state automatically.
    const NARROW_VIEWPORT = 900;

    function constrainToViewport(animate) {
      const narrow = window.innerWidth <= NARROW_VIEWPORT;
      const expanded = isCurrentlyExpanded();
      if (narrow && expanded) {
        if (animate) animateTo(getCollapsedWidth(), false);
        else         setWidth(getCollapsedWidth(), { persist: false });
        return true;
      }
      if (!narrow && !expanded && readStoredState() === 'expanded') {
        if (animate) animateTo(getExpandedWidth(), false);
        else         setWidth(getExpandedWidth(), { persist: false });
        return true;
      }
      return false;
    }

    // ── Restore on load ────────────────────────────────
    // EXPANDED is the default (fresh install / no stored value). We only stay
    // collapsed when the user explicitly chose it, or on a narrow viewport.
    const storedState = readStoredState();
    const initialState = (storedState !== 'collapsed' && window.innerWidth > NARROW_VIEWPORT)
      ? 'expanded'
      : 'collapsed';
    setWidth(widthForState(initialState), { persist: false });

    // ── Resize listener ────────────────────────────────
    window.addEventListener('resize', function () {
      recomputeCollapsedWidth();
      positionMarkers();
      // Apply the narrow/wide constraint first — may snap to the
      // opposite state via animateTo (with no persist).
      const constrained = constrainToViewport(true);
      // If we stayed collapsed, just sync to the new fluid value.
      if (!constrained && !isCurrentlyExpanded()) {
        setWidth(getCollapsedWidth(), { persist: false });
      }
    });
  }

  // ── 5. Rail tooltip (only when collapsed) ────────────
  const tip = document.createElement('div');
  tip.className = 'dy-rail-tip';
  tip.setAttribute('role', 'tooltip');
  body.appendChild(tip);

  function showTip(target, label) {
    if (body.dataset.railState === 'expanded') return;
    const r = target.getBoundingClientRect();
    tip.textContent = label;
    tip.style.top  = (r.top + r.height / 2) + 'px';
    tip.style.left = (r.right + 10) + 'px';
    tip.classList.add('is-visible');
  }
  function hideTip() { tip.classList.remove('is-visible'); }

  // Brand row → Dashboard tooltip (collapsed only).
  const brandEl = document.querySelector('.dy-rail-brand');
  if (brandEl) {
    const brandLabel = brandEl.getAttribute('aria-label') || 'Dashboard';
    brandEl.addEventListener('mouseenter', () => showTip(brandEl, brandLabel));
    brandEl.addEventListener('mouseleave', hideTip);
    brandEl.addEventListener('focusout',  hideTip);
  }

  // Top-level menu items. With the flyout on (section 5bis), the card
  // carries the label: no tooltip on the entries, only on the brand row.
  const hasFlyouts = !!document.querySelector('#adminmenu li.menu-top > .dy-rail-fly');
  const items = document.querySelectorAll('#adminmenu li.menu-top');
  items.forEach(function (li) {
    const nameEl = li.querySelector('.wp-menu-name');
    const label = nameEl ? nameEl.textContent.trim() : '';
    if (!label || hasFlyouts) return;

    li.addEventListener('mouseenter', () => showTip(li, label));
    li.addEventListener('mouseleave', hideTip);
    li.addEventListener('focusout',  hideTip);
  });

  // Hide tooltip during resize gestures.
  document.addEventListener('pointerdown', function () {
    if (body.classList.contains('is-resizing')) hideTip();
  });

  // ── 5bis. Rail flyout: the pages of a section on hover ──
  // Cards rendered by PHP inside each entry (Rail::markup_flyout) and moved
  // to <body>: #adminmenuwrap scrolls, and a fixed card must not be clipped
  // by it. Hover intent 120 ms, grace 220 ms (the card's ::before bridges
  // the gap), touch never opens (a tap navigates). Keyboard: right/down
  // arrow opens and focuses the first page, up/down move, Escape or left
  // arrow closes and returns to the entry. The entry stays "hot" while its
  // card is open. Setting: Settings::OPTION_RAIL_FLYOUT.
  (function () {
    const wrap = document.getElementById('adminmenuwrap');
    const cards = document.querySelectorAll('#adminmenu li.menu-top > .dy-rail-fly');
    if (!wrap || !cards.length) return;
    const host = document.createElement('div');
    host.id = 'dy-rail-flyouts';
    body.appendChild(host);
    const cardOf = new Map();
    cards.forEach(function (card) {
      const li = card.parentElement;
      host.appendChild(card);
      card.hidden = false;
      cardOf.set(li, card);
    });
    let openLi = null, openTimer = 0, closeTimer = 0;

    function place(li, card) {
      const r = li.getBoundingClientRect(), w = wrap.getBoundingClientRect();
      card.style.left = Math.round(w.right + 8) + 'px';
      card.style.top = '0px';
      const h = card.offsetHeight;
      card.style.top = Math.round(Math.max(12, Math.min(r.top - 6, window.innerHeight - h - 12))) + 'px';
    }
    function open(li) {
      clearTimeout(openTimer); clearTimeout(closeTimer);
      const card = cardOf.get(li);
      if (!card) return;
      if (openLi && openLi !== li) close(true);
      openLi = li;
      li.classList.add('is-hot');
      const a = li.querySelector('a');
      if (a) a.setAttribute('aria-expanded', 'true');
      // Section colour of the entry, read where it is defined.
      card.style.setProperty('--dya-line-color', getComputedStyle(li).getPropertyValue('--dya-line-color').trim() || 'var(--dya-accent)');
      place(li, card);
      card.classList.add('is-open');
    }
    function close(keepTimers) {
      if (!keepTimers) { clearTimeout(openTimer); clearTimeout(closeTimer); }
      if (!openLi) return;
      const card = cardOf.get(openLi);
      if (card) card.classList.remove('is-open');
      openLi.classList.remove('is-hot');
      const a = openLi.querySelector('a');
      if (a) a.setAttribute('aria-expanded', 'false');
      openLi = null;
    }
    function scheduleClose(ms) { clearTimeout(closeTimer); closeTimer = setTimeout(function () { close(); }, ms); }

    cardOf.forEach(function (card, li) {
      const a = li.querySelector('a');
      li.addEventListener('pointerenter', function (e) {
        if (e.pointerType === 'touch' || body.classList.contains('is-resizing')) return;
        clearTimeout(closeTimer); clearTimeout(openTimer);
        if (openLi) { open(li); } else { openTimer = setTimeout(function () { open(li); }, 120); }
      });
      li.addEventListener('pointerleave', function () { clearTimeout(openTimer); scheduleClose(220); });
      if (a) {
        a.setAttribute('aria-expanded', 'false');
        a.addEventListener('keydown', function (e) {
          if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            e.preventDefault(); open(li);
            const first = card.querySelector('a'); if (first) first.focus();
          } else if (e.key === 'Escape') { close(); }
        });
      }
      card.addEventListener('pointerenter', function () { clearTimeout(closeTimer); });
      card.addEventListener('pointerleave', function () { scheduleClose(160); });
      card.addEventListener('keydown', function (e) {
        const links = Array.prototype.slice.call(card.querySelectorAll('a'));
        const i = links.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); (links[i + 1] || links[0]).focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); (links[i - 1] || links[links.length - 1]).focus(); }
        else if (e.key === 'Escape' || e.key === 'ArrowLeft') { e.preventDefault(); close(); if (a) a.focus(); }
      });
      card.addEventListener('focusout', function (e) {
        const to = e.relatedTarget;
        if (!card.contains(to) && !(to && to.closest && to.closest('#adminmenu'))) scheduleClose(80);
      });
    });
    document.addEventListener('pointerdown', function (e) {
      if (openLi && !e.target.closest('.dy-rail-fly, #adminmenu')) close();
    });
    window.addEventListener('resize', function () { if (openLi) place(openLi, cardOf.get(openLi)); });
    wrap.addEventListener('scroll', function () { if (openLi) place(openLi, cardOf.get(openLi)); }, { passive: true });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openLi) close(); });
  })();

  // ── 6. Per-table screen-options gear ─────────────────
  // Auto-injects a gear button at the right end of every .tablenav.top
  // that owns a wp-list-table. Click opens a popover with column
  // visibility toggles + items-per-page input. Always-on; cards
  // mode (≤600px) hides the gear via CSS.
  function gearSvg() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">' +
           '<circle cx="12" cy="12" r="3"/>' +
           '<path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8 2 2 0 1 1-2.8 2.8 1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5 2 2 0 1 1-4 0 1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3 2 2 0 1 1-2.8-2.8 1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1 2 2 0 1 1 0-4 1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8 2 2 0 1 1 2.8-2.8 1.7 1.7 0 0 0 1.8.3 1.7 1.7 0 0 0 1-1.5 2 2 0 1 1 4 0 1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3 2 2 0 1 1 2.8 2.8 1.7 1.7 0 0 0-.3 1.8 1.7 1.7 0 0 0 1.5 1 2 2 0 1 1 0 4 1.7 1.7 0 0 0-1.5 1z" stroke-linejoin="round"/>' +
           '</svg>';
  }

  document.querySelectorAll('.wp-list-table').forEach(function (table) {
    // ── (a) Prepare each tbody row for mobile card mode ──
    // Below 600 px the table flips to stacked-card mode. To make
    // that work uniformly across every WP list table (posts, pages,
    // comments, users, plugins, WC orders, WC products, …) we do
    // three runtime steps here per row:
    //
    //   1. Mark the PRIMARY cell — the one that acts as the card's
    //      header. We don't rely on column classes because many WP
    //      tables (notably WC) emit the title cell with no class at
    //      all. Instead we use the universal signals: the cell that
    //      contains .row-actions, then .row-title, then the first
    //      non-checkbox cell as a last resort.
    //   2. Inject data-colname on every non-primary cell using the
    //      matching thead label, so the card-mode CSS can render
    //      "[LABEL] [value]" via a ::before pseudo.
    //   3. Wrap the value content of each data-colname cell in
    //      <div class="dy-cell-value"> so the cell has exactly two
    //      logical children at the grid level: ::before (label)
    //      and the value div. Inline content like
    //         <a>graphite</a>, <a>studio</a>
    //      stays as one flow inside the wrapper — no orphaned
    //      comma jumping under the label column.
    const headerCells = table.querySelectorAll('thead tr > th, thead tr > td');
    const colLabels = [];
    headerCells.forEach(function (cell) {
      colLabels.push((cell.textContent || '').trim());
    });

    function findPrimaryCell(row) {
      const cells = row.querySelectorAll(':scope > td, :scope > th');
      // Prefer the cell holding .row-actions (the universal WP
      // anchor for the row's identifier).
      for (let i = 0; i < cells.length; i++) {
        if (cells[i].querySelector(':scope > .row-actions, :scope > div > .row-actions')) return cells[i];
      }
      // Fallback 1 — cell containing the row-title link.
      for (let i = 0; i < cells.length; i++) {
        if (cells[i].querySelector('.row-title')) return cells[i];
      }
      // Fallback 2 — first content cell after the checkbox.
      for (let i = 0; i < cells.length; i++) {
        const c = cells[i];
        if (c.classList.contains('check-column') || c.classList.contains('column-cb')) continue;
        // Skip pure-thumbnail cells (image only, no text).
        if (c.classList.contains('column-thumb') || c.classList.contains('column-icon')) continue;
        return c;
      }
      return null;
    }

    function wrapCellValue(cell) {
      // Skip if already wrapped or empty.
      if (cell.querySelector(':scope > .dy-cell-value')) return;
      if (!cell.childNodes.length) return;
      const div = document.createElement('div');
      div.className = 'dy-cell-value';
      while (cell.firstChild) div.appendChild(cell.firstChild);
      cell.appendChild(div);
    }

    table.querySelectorAll('tbody tr').forEach(function (row) {
      const primary = findPrimaryCell(row);
      if (primary) {
        primary.classList.add('dy-card-title');
        primary.removeAttribute('data-colname');
      }

      const cells = row.querySelectorAll(':scope > td, :scope > th');
      cells.forEach(function (cell, idx) {
        if (cell === primary) return;
        if (cell.classList.contains('check-column') || cell.classList.contains('column-cb')) return;
        if (!cell.hasAttribute('data-colname')) {
          const label = colLabels[idx];
          if (label) cell.setAttribute('data-colname', label);
        }
        if (cell.hasAttribute('data-colname')) wrapCellValue(cell);
      });
    });

    // ── (b) Inject the screen-options gear ───────────────
    // Wrap the table in `.dy-table-wrapper` (once) so the gear
    // can be positioned relative to the TABLE, not relative to
    // a specific column cell. This keeps the gear visible even
    // when the user hides the Date column from the panel.
    let tableWrapper = table.parentElement;
    if (!tableWrapper || !tableWrapper.classList.contains('dy-table-wrapper')) {
      tableWrapper = document.createElement('div');
      tableWrapper.className = 'dy-table-wrapper';
      table.parentNode.insertBefore(tableWrapper, table);
      tableWrapper.appendChild(table);
    }
    if (tableWrapper.querySelector(':scope > .dy-table-options')) return; // already injected

    // Introspect <thead> for togglable columns. Skip the always-
    // critical ones (checkbox, primary title).
    const SKIP_COLS = new Set(['column-cb', 'column-title', 'column-fichier', 'column-name', 'column-username', 'plugin-title']);
    const columns = [];
    table.querySelectorAll('thead th, thead td').forEach(function (th) {
      const colClass = [].slice.call(th.classList).find(function (c) { return c.indexOf('column-') === 0; });
      if (!colClass || SKIP_COLS.has(colClass)) return;
      // Strip the screen-reader sort text ("Sort descending"…) WP injects in the
      // sort link, so the label stays clean ("Date", not "Date Sort descending").
      const clone = th.cloneNode(true);
      clone.querySelectorAll('.screen-reader-text').forEach(function (e) { e.remove(); });
      const label = (clone.textContent || '').replace(/\s+/g, ' ').trim() || colClass.replace('column-', '');
      columns.push({ cls: colClass, label: label });
    });
    if (columns.length === 0) return;

    const i18n = (window.doubleyoupiAdminI18n && window.doubleyoupiAdminI18n.tableOptions) || {};
    const labelScreenOptions = i18n.screenOptions || 'Screen options';
    const labelColumns       = i18n.columns       || 'Columns';
    const labelPagination    = i18n.pagination    || 'Pagination';
    const labelItemsPerPage  = i18n.itemsPerPage  || 'Items per page';

    const wrap = document.createElement('div');
    wrap.className = 'dy-table-options';
    wrap.innerHTML =
      '<button type="button" class="dy-table-options-trigger" aria-label="' + labelScreenOptions + '" aria-expanded="false" title="' + labelScreenOptions + '">' +
        gearSvg() +
      '</button>' +
      '<div class="dy-table-options-panel" role="dialog" aria-label="' + labelScreenOptions + '" hidden>' +
        '<h3>' + labelColumns + '</h3>' +
        '<ul>' +
          columns.map(function (c) {
            return '<li><label><input type="checkbox" checked data-col="' + c.cls + '"/>' + c.label + '</label></li>';
          }).join('') +
        '</ul>' +
        '<h3>' + labelPagination + '</h3>' +
        '<div class="dy-table-options-panel-row">' +
          '<label>' + labelItemsPerPage + '</label>' +
          '<input type="number" class="dy-table-options-per-page" value="20" min="5" max="200" step="5"/>' +
        '</div>' +
      '</div>';
    tableWrapper.appendChild(wrap);

    const trigger = wrap.querySelector('.dy-table-options-trigger');
    const panel   = wrap.querySelector('.dy-table-options-panel');

    function closePanel() {
      panel.setAttribute('hidden', '');
      trigger.setAttribute('aria-expanded', 'false');
    }
    function openPanel() {
      panel.removeAttribute('hidden');
      trigger.setAttribute('aria-expanded', 'true');
    }

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      if (panel.hasAttribute('hidden')) openPanel();
      else                              closePanel();
    });
    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) closePanel();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hasAttribute('hidden')) {
        closePanel();
        trigger.focus();
      }
    });

    // Column toggles — direct DOM hide, no CSS gymnastics
    panel.querySelectorAll('input[data-col]').forEach(function (input) {
      input.addEventListener('change', function () {
        const col = input.dataset.col;
        table.querySelectorAll('.' + col).forEach(function (cell) {
          cell.style.display = input.checked ? '' : 'none';
        });
      });
    });

    // Items-per-page — mockup is purely visual.
    const perPage = panel.querySelector('.dy-table-options-per-page');
    perPage.addEventListener('change', function () {
      // No-op (mockup): in production, the per-page setting is managed by
      // TablesReimagined (REST POST + user meta), not here.
    });
  });

  // ── 7. Site Health progress counter ──────────────────
  // The CSS animates the ring (stroke-dashoffset). Here we count
  // the numeric label from 0 to its target value in lockstep
  // (same duration + easing) so they tell one story together.
  document.querySelectorAll('#dashboard_site_health .site-health-progress-label').forEach(function (label) {
    const target = parseInt(label.textContent || '0', 10);
    if (!isFinite(target) || target <= 0) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const duration = 1400;
    const delay    = 200;
    const start    = performance.now() + delay;
    label.textContent = '0';
    function tick(now) {
      const elapsed = now - start;
      if (elapsed < 0) { requestAnimationFrame(tick); return; }
      const progress = Math.min(1, elapsed / duration);
      // cubic-bezier(.16,1,.3,1) approximation — smooth-out
      const eased = 1 - Math.pow(1 - progress, 3);
      label.textContent = Math.round(target * eased);
      if (progress < 1) requestAnimationFrame(tick);
      else              label.textContent = target;
    }
    requestAnimationFrame(tick);
  });

  // ── 8. Chip-bar underline track ──────────────────────
  const chipbar = document.querySelector('.dy-chipbar');
  if (chipbar) {
    let track = chipbar.querySelector('.dy-track');
    if (!track) {
      track = document.createElement('span');
      track.className = 'dy-track';
      const inner = chipbar.querySelector('.dy-chipbar-inner') || chipbar;
      inner.appendChild(track);
    }

    function moveTrackTo(el) {
      if (!el) { track.style.width = '0'; return; }
      const inner = chipbar.querySelector('.dy-chipbar-inner') || chipbar;
      const r1 = inner.getBoundingClientRect();
      const r2 = el.getBoundingClientRect();
      track.style.transform = 'translateX(' + (r2.left - r1.left + inner.scrollLeft) + 'px)';
      track.style.width = r2.width + 'px';
    }

    function currentChip() {
      return chipbar.querySelector('.dy-chip.current');
    }

    moveTrackTo(currentChip());
    window.addEventListener('resize', () => moveTrackTo(currentChip()));

    chipbar.addEventListener('mouseover', function (e) {
      const chip = e.target.closest('.dy-chip');
      if (chip && chip.tagName !== 'SPAN') moveTrackTo(chip);
    });
    chipbar.addEventListener('mouseleave', function () {
      moveTrackTo(currentChip());
    });
    chipbar.addEventListener('focusin', function (e) {
      const chip = e.target.closest('.dy-chip');
      if (chip) moveTrackTo(chip);
    });
  }

  // ── 9. View Transitions — direction sniff ────────────
  // The CSS in _view-transitions.scss opts the document into
  // cross-document VT and animates the content swap based on
  // `html[data-nav-dir]`. This section sniffs the user's
  // intent at click time (which selector did they hit?) and
  // hands the direction to the next page via sessionStorage.
  //
  // The pre-paint inline script in each page's <head> reads
  // the handoff and sets `data-nav-dir` BEFORE first paint so
  // the CSS selectors match when the browser starts the VT.
  //
  // Pattern: capture-phase listener, attached once. Any link
  // or submit button that matches one of the selectors below
  // sets the direction. Modifier-clicks, _blank targets,
  // downloads, and cross-origin links are ignored — they don't
  // trigger a same-document navigation anyway and we don't
  // want to leave a stale value behind.
  const VT_STORAGE = 'dy-nav-dir';
  const VT_TTL_MS  = 1500;

  // Selectors covering native WP patterns (and our extensions).
  const VT_FORWARD = [
    '.row-title',                 // list-tables → edit
    '.page-title-action',         // "Add New" header button
    '#publish', '#save-post',     // Edit screen save buttons
    '.button-primary[href]'       // primary action *with href*
  ].join(',');

  const VT_BACKWARD = [
    '.submitdelete',              // "Move to Trash" on Edit
    '#delete-action a',           // Permanently delete
    '[href*="action=trash"]',     // row-actions Trash link
    '[href*="action=untrash"]'    // restore
  ].join(',');

  const VT_SIBLING = [
    '.subsubsub a',               // "All | Published | Draft" filters
    '.nav-tab-wrapper a',         // tabbed settings (Yoast, RM, WC…)
    '.tablenav-pages a',          // pagination
    '.dy-chip',                   // Admin chipbar
    '.dy-subchip'                 // Admin subchipbar
  ].join(',');

  function vtWriteDir(dir) {
    try {
      sessionStorage.setItem(VT_STORAGE, JSON.stringify({ dir: dir, t: Date.now() }));
    } catch (_) {}
    // Immediately set data-nav-dir on the OUTGOING page's <html> so that
    // the CSS rule `html[data-nav-dir="..."] ::view-transition-old(root)`
    // matches when Chrome captures the snapshot. Without this, only the
    // NEW group receives the direction (via the prepaint script), and the
    // OLD keeps the default fade → the user does not perceive the direction.
    document.documentElement.dataset.navDir = dir;
  }

  document.addEventListener('click', function (e) {
    // Modifier-click / non-primary-mouse-button → new tab,
    // dialog, or no-op. Don't pollute sessionStorage.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (e.button !== undefined && e.button !== 0) return;

    const a = e.target.closest('a[href], button[type="submit"]');
    if (!a) return;

    // Skip anything that won't actually navigate the current tab.
    if (a.target === '_blank') return;
    if (a.hasAttribute('download')) return;
    // Cross-origin anchor → browser navigates away from our site.
    // a.host is empty for relative URLs (which IS what we want).
    if (a.tagName === 'A' && a.host && a.host !== window.location.host) return;

    let dir = 'fade';
    if      (a.matches(VT_FORWARD))  dir = 'forward';
    else if (a.matches(VT_BACKWARD)) dir = 'backward';
    else if (a.matches(VT_SIBLING))  dir = 'sibling';

    vtWriteDir(dir);

    // If the user clicks a chip (chip-bar or sub-chip), save the target
    // position of `.dy-track` for the next page. The prepaint script in
    // ViewTransitions.php will read sessionStorage and inject a <style>
    // that pre-positions `.dy-track` BEFORE the first paint, so that
    // the `dy-vt-chip-track` view-transition interpolates directly from
    // the previous hover position to the destination (no snap back to 0).
    var chipClicked = a.closest('.dy-chip, .dy-subchip');
    if (chipClicked) {
      var bar = chipClicked.closest('.dy-chipbar-inner, .dy-subchipbar-inner');
      if (bar) {
        var chipR = chipClicked.getBoundingClientRect();
        var barR  = bar.getBoundingClientRect();
        try {
          sessionStorage.setItem('dy-track-target', JSON.stringify({
            kind: chipClicked.classList.contains('dy-subchip') ? 'sub' : 'chip',
            x: Math.round(chipR.left - barR.left + bar.scrollLeft),
            w: Math.round(chipR.width),
            t: Date.now(),
          }));
        } catch (_) {}
      }
    }
  }, true);

  // ─────────────────────────────────────────────────────────
  // Cursor + wipe orchestrator
  // ─────────────────────────────────────────────────────────
  // Every admin H1 carries a terminal cursor (a blinking vertical accent
  // bar, see _view-transitions.scss). When the user clicks an admin nav
  // link, we orchestrate a wipe:
  //
  //   Phase 1 (150ms) lock     — the bar stops blinking (opacity 1)
  //   Phase 2 (300ms) wipe-out — the bar slides to the left of the title,
  //                              erasing it via a ::before eraser element;
  //                              the page content fades+blurs out in
  //                              parallel
  //   Phase 3 (cross-doc)      — navigation (the cross-doc VT plays for
  //                              the rail/chipbar chrome)
  //   Phase 4 (300ms) wipe-in  — arrival handoff: the bar starts at the
  //                              left of the new title and slides right,
  //                              revealing it; the content fades+blurs in
  //   Phase 5 (150ms) reveal   — the bar resumes blinking at 0.5 opacity
  var wipeInflight = false;

  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function wipeSelectorMatches(a) {
    if (!a || a.tagName !== 'A' || !a.href) return false;
    if (a.target === '_blank' || a.hasAttribute('download')) return false;
    if (a.host && a.host !== window.location.host) return false;
    if (a.href.startsWith('mailto:') || a.href.startsWith('javascript:')) return false;
    if (a.pathname === window.location.pathname && a.search === window.location.search && a.hash) return false;
    // Skip destructive action links (delete, trash) outside the chipbar
    if (a.matches('[href*="action="]') && !a.matches('.dy-chip, .dy-subchip')) return false;
    // Target our navs: rail, chipbar, subchipbar, subsubsub filters,
    // pagination, nav-tab, row-title — all admin navigation.
    return a.matches('.dy-chip, .dy-subchip, #adminmenu .menu-top a, .subsubsub a, .nav-tab, .tablenav-pages a, .row-title');
  }

  async function runWipeNav(href, dir) {
    if (wipeInflight) return;
    wipeInflight = true;

    var html = document.documentElement;

    // Phase 1: lock (150ms) — the bar stops blinking at opacity 1.
    html.dataset.dyCursor = 'lock';
    await wait(150);

    // Phase 2: wipe-out (300ms) — the bar slides left, the eraser covers
    // the title, content fades+blurs out.
    html.dataset.dyCursor = 'wipe-out';
    await wait(300);

    // Phase 3: handoff + navigation. The cross-doc VT plays for the chrome.
    sessionStorage.setItem('dy-cursor-arrival', JSON.stringify({ t: Date.now() }));
    vtWriteDir(dir);
    window.location.href = href;
  }

  // Hooked on the same capture phase as the direction sniffer. Registration
  // order = execution order → the sniffer writes dy-nav-dir first, then
  // this handler decides whether to intercept.
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (e.button !== undefined && e.button !== 0) return;

    var a = e.target.closest('a[href]');
    if (!a || !wipeSelectorMatches(a)) return;

    var url = new URL(a.href);
    if (url.origin !== location.origin) return;
    if (url.pathname === location.pathname && url.search === location.search) return;

    var dir = 'fade';
    if      (a.matches(VT_FORWARD))  dir = 'forward';
    else if (a.matches(VT_BACKWARD)) dir = 'backward';
    else if (a.matches(VT_SIBLING))  dir = 'sibling';

    e.preventDefault();
    e.stopPropagation();
    runWipeNav(a.href, dir);
  }, true);

  // Restoration from bfcache (back/forward button).
  //
  // Navigation is cross-document: `runWipeNav` sets `data-dy-cursor="wipe-out"`
  // (content fades+blurs out) BEFORE navigating. The outgoing page is therefore
  // stored in bfcache frozen in that state. The prepaint script in
  // ViewTransitions.php that clears `data-dy-cursor` runs ONLY on a fresh load,
  // never on a bfcache restore → on back navigation the page returns with its
  // content erased (blank page). In addition the bfcache restores the entire JS
  // heap: `wipeInflight` stays true → all subsequent nav clicks are frozen.
  //
  // We therefore restore a clean state: reveal the content and release the
  // navigation lock. (event.persisted is true only for a real bfcache restore,
  // never for a normal page load.)
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    delete document.documentElement.dataset.dyCursor;
    wipeInflight = false;
  });

  // Browser back / forward — always treat as backward.
  // Save the current track position to preserve its visual anchor across
  // the cross-document VT (otherwise it snaps back to translateX(0)).
  window.addEventListener('popstate', function () {
    vtWriteDir('backward');
    var t = document.querySelector('.dy-chipbar .dy-track');
    if (t && t.style.transform) {
      // Extract translateX(Xpx) from the inline style
      var m = /translateX\(([-0-9.]+)px\)/.exec(t.style.transform);
      var w = parseFloat(t.style.width) || 0;
      if (m) {
        try {
          sessionStorage.setItem('dy-track-target', JSON.stringify({
            kind: 'chip',
            x: Math.round(parseFloat(m[1])),
            w: Math.round(w),
            t: Date.now(),
          }));
        } catch (_) {}
      }
    }
  });

})();

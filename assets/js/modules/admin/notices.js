/**
 * Admin · Double Youpi — Notices center
 * ------------------------------------------------------------------
 * Intercepts ALL admin notices (WP core + third-party plugins, present
 * and future) and moves them into a "Notices" badge with a counter,
 * rather than letting them pollute the top of the page.
 *
 *   • Initial capture (DOM ready) + MutationObserver (late AJAX
 *     injections) → full coverage, current and future plugins alike.
 *   • Move (not clone) → each plugin's native dismiss handlers remain
 *     functional.
 *   • Anti-relocation guard: WP core (common.js) moves
 *     `.notice:not(.below-h2,.inline)` to `.wp-header-end` once. We
 *     add `below-h2` to every captured notice so WP does not reclaim it.
 *   • Action feedback (success/error after a submit, settings API) →
 *     ephemeral micro-toast IN ADDITION to the badge (product decision).
 *
 * Progressive enhancement: inert without body.dy-admin. If this script
 * does not load, the fallback in `admin.js` reveals notices in place
 * after ~1 s (no notice is lost).
 */
(function () {
  'use strict';

  var body = document.body;
  if (!body || !body.classList.contains('dy-admin')) return;

  var CFG  = (typeof window.doubleyoupiNotices === 'object' && window.doubleyoupiNotices) || {};
  var I18N = CFG.i18n || {};

  // ── Selectors & guard ────────────────────────────────────────────
  // Recognised notice classes (modern + legacy). `.update-nag` covers
  // the core update banner. The legacy form is restricted to `div.updated`
  // / `div.error` (WP convention) — bare `.error` is used by plugins
  // for inline field errors that we do not want to capture.
  // `#wf-onboarding-banner`: Wordfence onboarding banner (custom markup,
  // not a `.notice`) injected site-wide → we capture it in the center too.
  var NOTICE_SELECTOR = '.notice, div.updated, div.error, .update-nag, #wf-onboarding-banner';

  // Containers where a "notice" is actually a functional screen element
  // (meta box, table, media frame, options screen, our own chrome) —
  // we leave these untouched to avoid breaking the layout.
  var SKIP_ANCESTORS = [
    '#templateside', // theme/plugin file editors: the current-file link carries `.notice`
    '#pluginside',
    '.dy-notices-modal',
    '.dy-notice-dock',
    '.dy-notice-toast-host',
    '.postbox',
    '.inside',
    '.wp-list-table',
    '.media-frame',
    '.media-modal',
    '.theme-browser',
    '#screen-options-wrap',
    '#contextual-help-wrap',
    '.acf-postbox',
    '.acf-field',
    '.bricks-panel',
    '.edit-post-layout',
    '.block-editor',
    '.wp-editor-container'
  ].join(',');

  /**
   * Is a notice interceptable? Excludes:
   *   - `.inline` notices (WP convention "leave me in place"),
   *   - hidden notices (templates),
   *   - those inside a functional container (SKIP_ANCESTORS),
   *   - our own chrome.
   */
  function isInterceptable(el) {
    if (!el || el.nodeType !== 1 || !el.isConnected) return false;
    if (el.dataset && el.dataset.dyCaptured) return false;
    if (el.classList.contains('inline')) return false;
    if (el.classList.contains('hidden')) return false;
    // Written for browsers without JavaScript (e.g. the media grid's
    // "requires JavaScript" error): reaching this code means it does not apply.
    if (el.classList.contains('hide-if-js')) return false;
    if (el.hidden) return false;
    // Exclude notices nested inside another already-managed notice.
    if (el.parentElement && el.parentElement.closest && el.parentElement.closest('[data-dy-captured]')) return false;
    if (el.closest(SKIP_ANCESTORS)) return false;
    return true;
  }

  // ── DOM references (shell rendered server-side by PHP) ─────────────
  var dock     = document.getElementById('dy-notice-dock');
  var trigger  = document.getElementById('dy-notice-trigger');
  var countEl  = trigger && trigger.querySelector('.dy-notice-trigger__count');
  var modal    = document.getElementById('dy-notices-modal');
  var list     = document.getElementById('dy-notices-list');
  var emptyEl  = document.getElementById('dy-notices-empty');
  var counter  = document.getElementById('dy-notices-modal-counter');
  var closeBtn = document.getElementById('dy-notices-close');
  var clearBtn = document.getElementById('dy-notices-clear');
  var toastHost = document.getElementById('dy-notice-toast-host');

  if (!dock || !trigger || !modal || !list) return; // shell missing → clean abort.

  var hasOpened = false;

  // ── Capture ───────────────────────────────────────────────────────
  function capture(el) {
    if (!isInterceptable(el)) return false;

    // Decide the toast BEFORE moving (read the page context).
    var actionTone = actionResultTone(el);

    el.dataset.dyCaptured = '1';
    // Anti-relocation guard for WP core + "revealed" state inside the modal
    // (the _notices.scss CSS collapses notices by default).
    el.classList.add('below-h2');
    el.classList.add('dy-notice-revealed');

    // `is-dismissible` notices injected after WP ready: WP no longer adds
    // the dismiss button (its makeNoticesDismissible runs only once). We
    // provide a client-side fallback if the button is absent.
    ensureDismissButton(el);

    list.appendChild(el);

    if (actionTone) {
      spawnToast(el, actionTone);
    }
    return true;
  }

  function scan(scope) {
    if (!scope || scope.nodeType !== 1) return;
    var nodes;
    try {
      nodes = scope.matches(NOTICE_SELECTOR)
        ? [scope].concat([].slice.call(scope.querySelectorAll(NOTICE_SELECTOR)))
        : [].slice.call(scope.querySelectorAll(NOTICE_SELECTOR));
    } catch (e) {
      return;
    }
    var moved = false;
    nodes.forEach(function (n) { if (capture(n)) moved = true; });
    if (moved) refresh();
  }

  /**
   * Reclaims already-captured notices that a plugin has re-parented OUTSIDE
   * our list after the fact. Real-world case: UpdraftPlus moves its promo
   * banner (`.updraft-ad-container`) into `.wrap` via its own JS AFTER our
   * capture → the notice kept `data-dy-captured=1` (so no longer
   * interceptable) but reappeared at the top of the page. We move it back.
   *
   * No loop: once returned to `list`, `list.contains(el)` is true
   * → no more reclaim (and the plugins involved only re-move once).
   */
  function reclaimEscaped() {
    if (!list) return;
    var escaped = [].slice.call(root.querySelectorAll('[data-dy-captured="1"]'))
      .filter(function (el) { return !list.contains(el); });
    if (!escaped.length) return;
    escaped.forEach(function (el) { list.appendChild(el); });
    refresh();
  }

  // ── Toast (retours d'action) ───────────────────────────────────────
  var ACTION_PARAMS = [
    'settings-updated', 'updated', 'message', 'saved', 'activated',
    'deactivated', 'trashed', 'untrashed', 'deleted', 'added', 'approved'
  ];
  var pageHasAction = (function () {
    try {
      var q = new URLSearchParams(window.location.search);
      for (var i = 0; i < ACTION_PARAMS.length; i++) {
        if (q.has(ACTION_PARAMS[i])) return true;
      }
    } catch (e) {}
    return false;
  })();
  // Notices present at the first scan are "initial"; those that arrive
  // later (observer) are dynamic = almost always an AJAX action result.
  var initialPassDone = false;

  function noticeTone(el) {
    var c = el.classList;
    if (c.contains('notice-error') || c.contains('error') || el.matches('div.error')) return 'error';
    if (c.contains('notice-warning')) return 'warning';
    if (c.contains('notice-success') || c.contains('updated')) return 'success';
    if (c.contains('notice-info')) return 'info';
    return 'info';
  }

  /**
   * Returns the toast tone if the notice is an action result, otherwise
   * null (persistent/promo notice → silent badge).
   */
  function actionResultTone(el) {
    var tone = noticeTone(el);
    var isResultLike = tone === 'success' || tone === 'error';
    if (!isResultLike) return null;

    var isSettingsApi = el.classList.contains('settings-error'); // settings_errors()
    var isDynamic = initialPassDone;
    // The post editor renders its save/publish/update feedback in `#message`
    // ("Post published", "Page updated"…). WordPress often clears the
    // `&message=N` URL parameter via history.replaceState right after render
    // → pageHasAction becomes false. We therefore treat `#message` as an
    // explicit action result to guarantee the confirmation toast.
    var isEditorSave = el.id === 'message';

    if (isSettingsApi || isDynamic || pageHasAction || isEditorSave) return tone;
    return null;
  }

  function noticeText(el) {
    var clone = el.cloneNode(true);
    var dismiss = clone.querySelector('.notice-dismiss, .screen-reader-text');
    while (dismiss) { dismiss.remove(); dismiss = clone.querySelector('.notice-dismiss, .screen-reader-text'); }
    var txt = (clone.textContent || '').replace(/\s+/g, ' ').trim();
    if (txt.length > 160) txt = txt.slice(0, 157).replace(/\s+\S*$/, '') + '…';
    return txt;
  }

  function spawnToast(el, tone) {
    if (!toastHost) return;
    var msg = noticeText(el);
    if (!msg) return;

    var toast = document.createElement('div');
    toast.className = 'dy-notice-toast';
    toast.setAttribute('data-tone', tone);
    toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');

    var dot = document.createElement('span');
    dot.className = 'dy-notice-toast__dot';
    dot.setAttribute('aria-hidden', 'true');

    var p = document.createElement('p');
    p.className = 'dy-notice-toast__msg';
    p.textContent = msg;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'dy-notice-toast__close';
    btn.setAttribute('aria-label', I18N.dismissed || 'Dismiss');
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    toast.appendChild(dot);
    toast.appendChild(p);
    toast.appendChild(btn);
    toastHost.appendChild(toast);

    // Entrance (next frame to trigger the transition).
    requestAnimationFrame(function () { toast.classList.add('is-in'); });

    var life = tone === 'error' ? 7000 : 4500;
    var timer = null;
    function close() {
      if (!toast.parentNode) return;
      toast.classList.remove('is-in');
      toast.classList.add('is-out');
      window.setTimeout(function () { if (toast.parentNode) toast.remove(); }, 280);
    }
    function arm() { timer = window.setTimeout(close, life); }
    function disarm() { if (timer) { window.clearTimeout(timer); timer = null; } }

    btn.addEventListener('click', function () { disarm(); close(); });
    toast.addEventListener('mouseenter', disarm);
    toast.addEventListener('mouseleave', arm);
    arm();
  }

  // ── Dismiss (passthrough + fallback) ───────────────────────────────
  function ensureDismissButton(el) {
    if (!el.classList.contains('is-dismissible')) return;
    if (el.querySelector('.notice-dismiss')) return;
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'notice-dismiss';
    b.innerHTML = '<span class="screen-reader-text">' + (I18N.dismissed || 'Dismiss this notice.') + '</span>';
    b.addEventListener('click', function () {
      el.parentNode && el.parentNode.removeChild(el);
      refresh();
    });
    el.appendChild(b);
  }

  // ── Counter & dock state ─────────────────────────────────────────────
  function currentNotices() {
    return [].slice.call(list.querySelectorAll('[data-dy-captured="1"]'));
  }

  function refresh() {
    var items = currentNotices();
    var n = items.length;

    if (countEl) countEl.textContent = String(n);
    trigger.setAttribute('data-count', String(n));
    dock.setAttribute('data-empty', n === 0 ? 'true' : 'false');

    // Accessible label.
    var label = n === 0 ? (I18N.empty || 'No notices')
      : (n === 1 ? (I18N.one || '1 notice')
        : (I18N.countLabel || '%d notices').replace('%d', String(n)));
    trigger.setAttribute('aria-label', label);
    trigger.setAttribute('title', label);

    if (counter) counter.textContent = String(n);

    // Modal empty state.
    if (emptyEl) emptyEl.hidden = n !== 0;
    list.hidden = n === 0;

    // "Dismiss all" button visible only when at least 1 dismissible notice exists.
    if (clearBtn) {
      var dismissibles = items.filter(function (el) {
        return el.classList.contains('is-dismissible') && el.querySelector('.notice-dismiss');
      });
      clearBtn.hidden = dismissibles.length === 0;
    }

    // "Unread" pulse as long as the user has not opened the badge.
    if (n > 0 && !hasOpened && !isOpen()) {
      dock.setAttribute('data-unseen', 'true');
    } else {
      dock.removeAttribute('data-unseen');
    }
  }

  // ── Open / close ─────────────────────────────────────────────────
  // "Container transform" morph as an isolated FLIP (Web Animations API). We
  // do NOT use the View Transitions API: the driver module already runs a
  // global VT for navigation (content blur, root cross-fade, chrome morph),
  // and any startViewTransition call wakes that machinery → blur + jank +
  // flash. Here, an autonomous ghost flies from the button to the panel,
  // completely independent of the chrome. The button and the panel share
  // --dya-bg-elev, so the ghost→panel relay is invisible at the pixel level.
  var chromeEl   = modal.querySelector('.dy-notices-modal__chrome');
  var OPEN_MS    = 440;
  var CLOSE_MS   = 360;
  var MORPH_EASE = 'cubic-bezier(.32,.72,0,1)';
  var PANEL_R    = '10px';   // = var(--dya-r-lg)
  var SH_SM      = '0 1px 2px rgba(0,0,0,.25)';   // = var(--dya-shadow-sm)
  var SH_LG      = '0 24px 60px rgba(0,0,0,.55)'; // = var(--dya-shadow-lg)

  function isOpen() { return modal.open; }
  function isBusy() { return body.classList.contains('dy-notif-busy'); }

  function prefersReducedMotion() {
    try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
    catch (e) { return false; }
  }
  function canMorph() {
    return chromeEl && typeof chromeEl.animate === 'function' && !prefersReducedMotion();
  }

  // Stagger index on each notice → staggered entrance cascade (var --dy-stagger).
  function staggerNotices() {
    currentNotices().forEach(function (el, i) {
      el.style.setProperty('--dy-stagger', String(i));
    });
  }

  function applyOpen() {
    hasOpened = true;
    dock.removeAttribute('data-unseen');
    trigger.setAttribute('aria-expanded', 'true');
    if (typeof modal.showModal === 'function') {
      if (!modal.open) modal.showModal();
    } else {
      modal.setAttribute('open', '');
    }
    body.classList.add('dy-notices-open');
    refresh();
    staggerNotices();
  }
  function applyClose() {
    trigger.setAttribute('aria-expanded', 'false');
    if (typeof modal.close === 'function' && modal.open) {
      modal.close();
    } else {
      modal.removeAttribute('open');
    }
    body.classList.remove('dy-notices-open');
  }

  // FLIP applied directly to `.dy-notices-modal__chrome`, which ALREADY lives
  // in the top layer of the <dialog> (therefore above the backdrop — a ghost
  // in normal flow would pass BEHIND the backdrop). We invert the panel to
  // the button's geometry then animate it to identity. The content (children)
  // is hidden during the morph (class `dy-notif-busy`) so no text is distorted
  // by the scale; it enters in a cascade once done.
  //
  // The border-radius is counter-scaled (PANEL_R / scale) to remain ~pill-shaped
  // throughout despite the panel scale.
  var PANEL_R_PX = 10;

  function flipFrames(bRect, pRect, fromButton) {
    var sx = bRect.width / pRect.width;
    var sy = bRect.height / pRect.height;
    var tx = bRect.left - pRect.left;
    var ty = bRect.top - pRect.top;
    var pilled = (PANEL_R_PX / Math.max(0.001, Math.min(sx, sy))) + 'px';
    var buttonState = {
      transformOrigin: 'top left',
      transform: 'translate(' + tx + 'px,' + ty + 'px) scale(' + sx + ',' + sy + ')',
      borderRadius: pilled,
      boxShadow: SH_SM
    };
    var panelState = {
      transformOrigin: 'top left',
      transform: 'none',
      borderRadius: PANEL_R,
      boxShadow: SH_LG
    };
    return fromButton ? [ buttonState, panelState ] : [ panelState, buttonState ];
  }

  function open() {
    if (isOpen() || isBusy()) return;
    if (!canMorph()) { applyOpen(); return; }

    var bRect = trigger.getBoundingClientRect();
    applyOpen();                                  // showModal → chrome at its final size (top layer)
    var pRect = chromeEl.getBoundingClientRect();

    body.classList.add('dy-notif-busy');          // removes dy-notices-in + hides content
    dock.style.visibility = 'hidden';

    var anim = chromeEl.animate(
      flipFrames(bRect, pRect, true),
      { duration: OPEN_MS, easing: MORPH_EASE, fill: 'both' }
    );
    function done() {
      anim.cancel();                              // release the transform (fill)
      chromeEl.classList.add('dy-notif-revealed');// cascade content in
      dock.style.visibility = '';
      body.classList.remove('dy-notif-busy');
    }
    anim.finished.then(done, done);
  }

  function close() {
    if (!isOpen() || isBusy()) return;
    if (!canMorph()) { applyClose(); return; }

    var pRect = chromeEl.getBoundingClientRect();
    var bRect = trigger.getBoundingClientRect(); // button behind the backdrop but still measurable

    body.classList.add('dy-notif-busy', 'dy-notif-closing'); // hide content + fade the backdrop
    chromeEl.classList.remove('dy-notif-revealed');
    dock.style.visibility = 'hidden';

    var anim = chromeEl.animate(
      flipFrames(bRect, pRect, false),
      { duration: CLOSE_MS, easing: MORPH_EASE, fill: 'both' }
    );
    function done() {
      anim.cancel();                              // release the transform before closing
      applyClose();                               // close the dialog (backdrop disappears)
      dock.style.visibility = '';
      body.classList.remove('dy-notif-busy', 'dy-notif-closing');
    }
    anim.finished.then(done, done);
  }

  trigger.addEventListener('click', function (e) {
    e.preventDefault();
    isOpen() ? close() : open();
  });
  if (closeBtn) closeBtn.addEventListener('click', function () { close(); });

  // Click on the backdrop (the <dialog> area outside the chrome) → close.
  modal.addEventListener('click', function (e) {
    if (e.target === modal) close();
  });
  // Safety net: any close (Esc, programmatic, outside morph) restores a
  // clean state (panel + button visible, classes/ghost cleaned up).
  function resetMorphState() {
    body.classList.remove('dy-notices-open', 'dy-notif-busy', 'dy-notif-closing');
    dock.style.visibility = '';
    if (chromeEl) {
      chromeEl.classList.remove('dy-notif-revealed');
      // Cancel any residual transform from an interrupted FLIP.
      chromeEl.getAnimations().forEach(function (a) { a.cancel(); });
    }
  }
  modal.addEventListener('close', function () {
    trigger.setAttribute('aria-expanded', 'false');
    if (!isBusy()) resetMorphState();
  });
  modal.addEventListener('cancel', function () {
    if (!isBusy()) resetMorphState();
  });

  // Delegation: click on a native dismiss in the list → recount after
  // the WP removal animation (slideUp ~150 ms).
  list.addEventListener('click', function (e) {
    var d = e.target.closest && e.target.closest('.notice-dismiss');
    if (!d) return;
    window.setTimeout(refresh, 60);
    window.setTimeout(refresh, 320);
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      currentNotices().forEach(function (el) {
        var d = el.querySelector('.notice-dismiss');
        if (d) d.click();
      });
      window.setTimeout(refresh, 360);
    });
  }

  // Recount when a notice disappears from the DOM (WP dismiss removes the node).
  new MutationObserver(function () { refresh(); })
    .observe(list, { childList: true });

  // ── Boot ───────────────────────────────────────────────────────────
  var root = document.getElementById('wpbody') || document.getElementById('wpcontent') || document.body;

  function init() {
    scan(root);
    // Second pass on the next frame: catches notices that WP core
    // (common.js) has just relocated to `.wp-header-end` if its ready
    // handler ran after our first scan.
    requestAnimationFrame(function () { scan(root); });
    // Close the initial pass via setTimeout (not rAF alone, which is throttled
    // when the tab is not visible): after this delay, any captured notice is
    // treated as dynamic (AJAX action result) → eligible for a toast.
    window.setTimeout(function () {
      scan(root);
      initialPassDone = true;
      refresh();
    }, 90);
    // Late reclaims: some plugins (UpdraftPlus) re-parent their notice
    // into `.wrap` via JS that runs after our initial capture.
    window.setTimeout(reclaimEscaped, 350);
    window.setTimeout(reclaimEscaped, 1200);
    refresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Global observer: late injections (AJAX, plugins). Scoped to #wpbody
  // to ignore mutations internal to our modal/toast.
  if (typeof MutationObserver !== 'undefined' && root) {
    new MutationObserver(function (mutations) {
      var batch = [];
      mutations.forEach(function (m) {
        for (var i = 0; i < m.addedNodes.length; i++) {
          var n = m.addedNodes[i];
          if (n.nodeType === 1) batch.push(n);
        }
      });
      if (!batch.length) return;
      var run = function () { batch.forEach(scan); reclaimEscaped(); };
      if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(run, { timeout: 400 });
      } else {
        window.setTimeout(run, 120);
      }
    }).observe(root, { childList: true, subtree: true });
  }
})();

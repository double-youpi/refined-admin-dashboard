/**
 * Admin Adoption — detects third-party elements by selectors (window.doubleyoupiAdoptRegistry)
 * and sets data-dy-adopt + targeted inline style removal for the DS.
 *
 * Non-destructive for the rest of the admin: early-return without body.dy-admin.
 */
(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('dy-admin')) {
    return;
  }

  const REG = typeof window.doubleyoupiAdoptRegistry === 'object' && window.doubleyoupiAdoptRegistry !== null
    ? window.doubleyoupiAdoptRegistry
    : {};

  /** Order = most specific first (prevents a `.e-notice` from taking precedence over `--cta`). */
  const ROLE_ORDER = [
    'toolbar-widget',
    'notice-dismiss',
    'notice-title',
    'pill',
    'badge',
    'notice-cta',
    'notice',
    'button-primary',
    'button',
    'card',
    'field',
  ];

  const INLINE_STRIP = {
    'toolbar-widget': ['padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
      'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
      'background', 'background-color'],
    'button-primary': ['color', 'background', 'background-color', 'border', 'border-color',
      'padding', 'text-decoration', 'box-shadow'],
    'button': ['color', 'background', 'background-color', 'border', 'border-color',
      'padding', 'text-decoration', 'box-shadow'],
    'notice-cta': ['background', 'background-color', 'border', 'border-color', 'padding', 'box-shadow'],
    'notice': ['background', 'background-color', 'border', 'border-color', 'padding', 'box-shadow'],
    'card': ['background', 'background-color'],
    'badge': [],
    'notice-dismiss': [],
    'notice-title': [],
    'pill': ['background', 'background-color', 'color', 'border', 'border-color'],
    'field': ['background', 'background-color', 'color', 'border', 'border-color', 'box-shadow'],
  };

  /** Filtered roles (PHP), plus unknown filter extensions. */
  function orderedRoles() {
    const known = ROLE_ORDER.concat(
      Object.keys(REG).filter(function (r) { return ROLE_ORDER.indexOf(r) === -1; })
    );

    return known.filter(function (r) {
      var sels = REG[r];
      return Array.isArray(sels) && sels.length > 0;
    });
  }

  function stripInlineProps(el, role) {
    const props = INLINE_STRIP[role];
    if (!props || props.length === 0 || !el.style) return;
    props.forEach(function (p) { el.style.removeProperty(p); });

    var attr = el.getAttribute('style');
    if (attr !== null && attr.trim() === '') {
      el.removeAttribute('style');
    }
  }

  function tag(el, role) {
    if (el.dataset.dyAdopt) return;
    el.dataset.dyAdopt = role;
    stripInlineProps(el, role);
  }

  /**
   * @param {Document|ShadowRoot|Element} scope
   */
  function scan(scope) {
    if (!scope || scope.nodeType !== 1) return;

    orderedRoles().forEach(function (role) {
      var sels = REG[role];
      var sel = sels.join(',');
      var nodes;
      try {
        nodes = scope.querySelectorAll(sel);
      } catch (e) {
        return;
      }
      nodes.forEach(function (el) { tag(el, role); });
    });
  }

  function flushBatch(batch) {
    batch.forEach(function (n) {
      if (!n || n.nodeType !== 1) return;
      scan(n);
      if (!n.shadowRoot) return;
      scan(n.shadowRoot);
    });
  }

  /** @type {HTMLElement|null} */
  const rootEl = document.getElementById('wpwrap');

  // A JavaScript app screen recognised in the browser (NativeContent, app
  // detection) gets `dy-native-content` after this file has loaded: checked
  // at run time, like the server-side guard that does not load it at all.
  function steppedBack() {
    return body.classList.contains('dy-native-content');
  }

  function runOnce() {
    if (!rootEl || steppedBack()) return;
    scan(rootEl);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', runOnce);
  } else {
    runOnce();
  }

  if (!rootEl || typeof MutationObserver === 'undefined') return;

  new MutationObserver(function (mutations) {
    if (steppedBack()) return;
    var batch = [];
    mutations.forEach(function (m) {
      for (var i = 0; i < m.addedNodes.length; i++) batch.push(m.addedNodes[i]);
    });
    if (!batch.length) return;

    var ric = window.requestIdleCallback;
    var run = function () { flushBatch(batch); };
    if (typeof ric === 'function') {
      ric(run, { timeout: 420 });
    } else {
      window.setTimeout(run, 160);
    }
  }).observe(rootEl, { childList: true, subtree: true });
})();

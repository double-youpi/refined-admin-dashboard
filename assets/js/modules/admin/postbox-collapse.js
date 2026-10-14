/**
 * postbox-collapse.js — ANIMATED collapse/expand of metaboxes (.postbox).
 *
 * WordPress (and our DY chevron) toggles the `.closed` class on a postbox
 * to collapse it; the native render then instantly hides the body (`.inside`).
 * This module instead animates the HEIGHT of `.inside` (0 ↔ content), for a
 * smooth collapse/expand — regardless of height, on any admin page
 * (editor, dashboard, settings, plugin metaboxes).
 *
 * Mechanism: we observe class changes (`.closed`) via MutationObserver →
 * we intercept the toggle and animate `.inside`. The CSS sets `body.dy-pb-anim`
 * (via this script) to stop hiding the body with `display:none` and let JS
 * control the height. Without JS or under `prefers-reduced-motion`,
 * we fall back to instant hiding (CSS).
 *
 * box-sizing:border-box during animation → height includes padding,
 * so `height:0` TRULY collapses everything (padding included), with no residual gap.
 */
(function () {
  'use strict';

  var body = document.body;
  if (!body || body.className.indexOf('dy-admin') === -1) return;

  var DUR = 300;
  var EASE = 'cubic-bezier(.16, 1, .3, 1)';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // `.inside` as a DIRECT child of the postbox (not a nested .inside from a widget).
  function insideOf(box) {
    for (var i = 0; i < box.children.length; i++) {
      var c = box.children[i];
      if (c.classList && c.classList.contains('inside')) return c;
    }
    return null;
  }

  function reset(el) {
    el.style.transition = '';
    el.style.height = '';
    el.style.overflow = '';
    el.style.boxSizing = '';
    el.style.paddingTop = '';
    el.style.paddingBottom = '';
    if (el.__pbEnd) { el.removeEventListener('transitionend', el.__pbEnd); el.__pbEnd = null; }
    if (el.__pbTimer) { clearTimeout(el.__pbTimer); el.__pbTimer = null; }
  }

  // We animate both HEIGHT and vertical padding: `height:0` alone leaves a
  // residual gap equal to the padding (even in border-box, the box does not
  // shrink below its padding). Collapsing padding-top/bottom as well → truly folds to 0.
  function animate(inside, closing) {
    // Start = current instantaneous state (fast toggle → smooth interruption).
    var current = inside.getBoundingClientRect().height;
    var cs = getComputedStyle(inside);
    var padT = cs.paddingTop, padB = cs.paddingBottom;
    reset(inside);

    if (reduce) { return; } // CSS handles display none/block, no animation

    inside.style.boxSizing = 'border-box';
    inside.style.overflow = 'hidden';

    var hTo, ptTo, pbTo;
    if (closing) {
      inside.style.height = current + 'px';
      inside.style.paddingTop = padT; inside.style.paddingBottom = padB;
      hTo = '0px'; ptTo = '0px'; pbTo = '0px';
    } else {
      // Target = NATURAL height + padding (restored temporarily to measure).
      inside.style.height = 'auto';
      inside.style.paddingTop = ''; inside.style.paddingBottom = '';
      var ncs = getComputedStyle(inside);
      hTo = inside.getBoundingClientRect().height + 'px';
      ptTo = ncs.paddingTop; pbTo = ncs.paddingBottom;
      // …then we START OVER from the collapsed state to animate toward the target.
      inside.style.height = current + 'px';
      inside.style.paddingTop = '0px'; inside.style.paddingBottom = '0px';
    }
    void inside.offsetHeight; // flush the start state

    inside.style.transition = 'height ' + DUR + 'ms ' + EASE +
      ', padding-top ' + DUR + 'ms ' + EASE + ', padding-bottom ' + DUR + 'ms ' + EASE;
    inside.style.height = hTo;
    inside.style.paddingTop = ptTo;
    inside.style.paddingBottom = pbTo;

    var end = function (e) {
      if (e && (e.target !== inside || e.propertyName !== 'height')) return;
      inside.removeEventListener('transitionend', end);
      inside.__pbEnd = null;
      if (inside.__pbTimer) { clearTimeout(inside.__pbTimer); inside.__pbTimer = null; }
      if (closing) {
        inside.style.transition = ''; // stays collapsed (height 0, padding 0, overflow hidden)
      } else {
        reset(inside); // open → height/padding auto, follows content from now on
      }
    };
    inside.__pbEnd = end;
    inside.addEventListener('transitionend', end);
    inside.__pbTimer = setTimeout(function () { end(); }, DUR + 140); // safety net if transitionend fires late
  }

  // Postbox already collapsed at page load → body to 0 WITHOUT animation.
  function initBox(box) {
    if (!box.classList.contains('closed')) return;
    var inside = insideOf(box);
    if (!inside) return;
    if (reduce) return; // CSS already hides it with display:none
    inside.style.boxSizing = 'border-box';
    inside.style.overflow = 'hidden';
    inside.style.height = '0px';
    inside.style.paddingTop = '0px';
    inside.style.paddingBottom = '0px';
  }

  function boot() {
    body.classList.add('dy-pb-anim'); // CSS stops hiding the body with display:none
    var boxes = document.querySelectorAll('.postbox');
    for (var i = 0; i < boxes.length; i++) initBox(boxes[i]);

    var obs = new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (m.attributeName !== 'class') continue;
        var box = m.target;
        if (!box.classList || !box.classList.contains('postbox')) continue;
        var was = m.oldValue ? /(^|\s)closed(\s|$)/.test(m.oldValue) : false;
        var is = box.classList.contains('closed');
        if (was === is) continue; // not a collapse toggle
        var inside = insideOf(box);
        if (inside) animate(inside, is);
      }
    });
    obs.observe(document.documentElement, {
      subtree: true, attributes: true, attributeFilter: ['class'], attributeOldValue: true
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

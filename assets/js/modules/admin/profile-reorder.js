/**
 * Admin · Double Youpi — profile.php: pushes SEO sections to the bottom
 * ------------------------------------------------------------------
 * Rank Math and Yoast inject their metaboxes into the profile via the
 * WP `show_user_profile` / `edit_user_profile` hooks: they end up IN THE MIDDLE
 * of <form id="your-profile">, interleaved between native fields. We move them
 * physically to the END of the form (just before the submit button)
 * so they no longer disrupt the profile reading flow.
 *
 * `appendChild` moves the node without touching field `name=` attributes → the
 * form submission order is unchanged. No-op outside body.dy-admin.
 */
(function () {
  'use strict';

  var body = document.body;
  if (!body || !body.classList.contains('dy-admin')) return;

  var form = document.getElementById('your-profile');
  if (!form) return;

  // SEO section containers to push to the bottom (Rank Math + Yoast).
  // We target stable wrappers; for Yoast we go up to the <h2>+content block
  // via their root container.
  var SELECTORS = [
    '.rank-math-metabox-wrap',
    '.rank-math-metabox-frame',
    '#wpseo_meta',
    '.wpseo-metabox-content',
    '#wpseo-metabox-root',
    '.yoast-settings'
  ];

  function reorder() {
    var moved = [];
    SELECTORS.forEach(function (sel) {
      form.querySelectorAll(sel).forEach(function (el) {
        // Avoid moving an element already nested inside another targeted element.
        if (moved.some(function (m) { return m.contains(el); })) return;
        moved.push(el);
      });
    });
    if (!moved.length) return;

    // Insertion point: before the submit block if it exists, otherwise at the end of the form.
    var submit = form.querySelector('p.submit, .submit');
    moved.forEach(function (el) {
      // Walk up to the <h2>/<h3> heading that immediately precedes the section,
      // to carry along the "Rank Math SEO" / "Yoast SEO Settings" label.
      var heading = null;
      var prev = el.previousElementSibling;
      if (prev && /^H[23]$/.test(prev.tagName)) heading = prev;

      if (submit) {
        if (heading) form.insertBefore(heading, submit);
        form.insertBefore(el, submit);
      } else {
        if (heading) form.appendChild(heading);
        form.appendChild(el);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', reorder);
  } else {
    reorder();
  }
})();

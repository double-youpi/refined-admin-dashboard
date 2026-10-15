/**
 * block-editor-loading.js — ends the loading state of the block editor.
 *
 * PHP sets `dy-be-loading` on <body>; the CSS draws the editor frame and keeps
 * React's skeleton transparent (see _gutenberg.scss, "Loading"). We swap to
 * `dy-be-ready` once the editor store is ready AND the canvas shows its
 * blocks (or the Code editor is mounted), two frames later so the iframe has
 * painted its styles. Nets: 4.5 s here, 5 s in CSS.
 */
(function () {
  'use strict';
  var body = document.body;
  if (!body || !body.classList.contains('dy-be-loading')) return;

  var done = false;
  var failsafe = setTimeout(reveal, 4500);
  function reveal() {
    if (done) return;
    done = true;
    clearTimeout(failsafe);
    body.classList.remove('dy-be-loading');
    body.classList.add('dy-be-ready');
  }

  function sel(store) {
    try { return window.wp && wp.data && wp.data.select(store); } catch (e) { return null; }
  }
  function editorReady() {
    var ed = sel('core/editor');
    if (!ed) return false;
    return typeof ed.__unstableIsEditorReady === 'function' ? ed.__unstableIsEditorReady() : !!ed.getCurrentPostId();
  }
  function canvasReady() {
    var ed = sel('core/editor');
    if (ed && typeof ed.getEditorMode === 'function' && ed.getEditorMode() === 'text') {
      return !!document.querySelector('.editor-text-editor, .editor-post-text-editor');
    }
    var frame = document.querySelector('iframe[name="editor-canvas"]');
    var doc = document; // non-iframed canvas (legacy meta boxes, apiVersion < 3 blocks)
    if (frame) {
      try { doc = frame.contentDocument; } catch (e) { return true; }
    }
    var root = doc && doc.querySelector('.is-root-container');
    if (!root) return false;
    var be = sel('core/block-editor');
    var count = be && typeof be.getBlockCount === 'function' ? be.getBlockCount() : 1;
    return count === 0 || root.children.length > 0;
  }
  function tick() {
    if (done) return;
    if (editorReady() && canvasReady()) {
      requestAnimationFrame(function () { requestAnimationFrame(reveal); });
      return;
    }
    requestAnimationFrame(tick);
  }
  if (window.wp && wp.domReady) wp.domReady(tick);
  else document.addEventListener('DOMContentLoaded', tick);
})();

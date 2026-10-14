/**
 * Admin · Double Youpi — drag & drop reordering for WP-list-tables.
 *
 * Active only on edit.php screens for an eligible post type
 * (PHP side: post_type_supports => page-attributes). The PHP bootstrap
 * localizes `window.doubleyoupiPostOrdering` with:
 *
 *   - restRoot     : full URL of the POST endpoint
 *   - nonce        : wp_rest nonce (header X-WP-Nonce)
 *   - postType     : slug of the current post type
 *   - hierarchical : bool — allows parent change on horizontal drag
 *   - defaultSort  : bool — true if current sort is menu_order ASC
 *   - canEdit      : bool — base capability (fine-grained check is REST-side)
 *   - indentStep   : px per indentation level during drag
 *
 * Pointer Events pattern identical to the resizable rail (admin.js §4):
 *   - pointerdown on .dy-order-handle → setPointerCapture
 *   - pointermove → computes (drop index, indent level), updates
 *     visual classes `.dy-dragging` / `.dy-drop-above` / `.dy-drop-below`
 *     and `data-dy-indent` on the current row
 *   - pointerup → if position changed, atomic POST to REST;
 *     optimistic DOM update with rollback on error
 */

(function () {
  'use strict';

  const cfg = window.doubleyoupiPostOrdering;
  if (!cfg || !cfg.restRoot) return;

  const body = document.body;
  if (!body || !body.classList.contains('dy-admin')) {
    // Admin redesign is not active → drag is also disabled
    // (styles would not be applied). Consistent with CSS scoping.
    return;
  }

  if (!cfg.canEdit) return;

  // Mark active state — used by CSS to gate the rules.
  body.classList.add('dy-post-ordering-active');
  if (!cfg.defaultSort) {
    body.classList.add('dy-post-ordering-disabled');
    return; // No listener attached if reordering is not available.
  }

  const table = document.querySelector('table.wp-list-table');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  if (!tbody) return;

  // ────────────────────────────────────────────────────────────
  // Drag state
  // ────────────────────────────────────────────────────────────
  let dragRow = null;       // <tr> currently being dragged
  let pointerStartX = 0;    // initial X position of the pointer
  let pointerStartY = 0;    // initial Y position (for "real drag" threshold)
  let dropTarget = null;    // <tr> currently hovered over
  let dropPosition = null;  // 'above' | 'below'
  let dragIndent = 0;       // target indentation level (horizontal drag)
  let dragging = false;     // true after threshold has been crossed
  const DRAG_THRESHOLD = 4; // px before we consider it a drag

  // ────────────────────────────────────────────────────────────
  // Helpers
  // ────────────────────────────────────────────────────────────
  function indentStep() {
    return Math.max(8, Number(cfg.indentStep) || 28);
  }

  function clearDropMarkers() {
    tbody.querySelectorAll('tr.dy-drop-above, tr.dy-drop-below').forEach((tr) => {
      tr.classList.remove('dy-drop-above', 'dy-drop-below');
      tr.removeAttribute('data-dy-indent');
    });
  }

  function rowsArray() {
    return Array.from(tbody.querySelectorAll('tr')).filter((tr) => {
      // Ignore "no items" rows (.no-items)
      return !tr.classList.contains('no-items');
    });
  }

  function findRowAt(y) {
    const rows = rowsArray();
    for (let i = 0; i < rows.length; i++) {
      const r = rows[i].getBoundingClientRect();
      if (y >= r.top && y <= r.bottom) {
        const mid = r.top + r.height / 2;
        return { row: rows[i], position: y < mid ? 'above' : 'below', index: i };
      }
    }
    // Above all rows
    if (rows.length && y < rows[0].getBoundingClientRect().top) {
      return { row: rows[0], position: 'above', index: 0 };
    }
    // Below all rows
    if (rows.length) {
      return { row: rows[rows.length - 1], position: 'below', index: rows.length - 1 };
    }
    return null;
  }

  function postIdOf(tr) {
    if (!tr) return 0;
    // WP sets `id="post-123"` on wp-list-table <tr> elements.
    const m = /^post-(\d+)$/.exec(tr.id || '');
    return m ? Number(m[1]) : 0;
  }

  // ────────────────────────────────────────────────────────────
  // Pointer handlers
  // ────────────────────────────────────────────────────────────
  function onPointerDown(e) {
    if (e.button !== 0) return; // left button only
    const handle = e.target.closest('.dy-order-handle');
    if (!handle) return;
    const row = handle.closest('tr');
    if (!row) return;

    e.preventDefault();
    dragRow = row;
    pointerStartX = e.clientX;
    pointerStartY = e.clientY;
    dragging = false;
    try { handle.setPointerCapture(e.pointerId); } catch (_) {}

    handle.addEventListener('pointermove', onPointerMove);
    handle.addEventListener('pointerup', onPointerUp, { once: true });
    handle.addEventListener('pointercancel', onPointerUp, { once: true });
  }

  function onPointerMove(e) {
    if (!dragRow) return;
    const dx = e.clientX - pointerStartX;
    const dy = e.clientY - pointerStartY;

    if (!dragging) {
      if (Math.abs(dx) + Math.abs(dy) < DRAG_THRESHOLD) return;
      dragging = true;
      dragRow.classList.add('dy-dragging');
      body.classList.add('dy-post-ordering-dragging');
    }

    // Find the hovered row and relative position
    const hit = findRowAt(e.clientY);
    clearDropMarkers();
    if (hit && hit.row !== dragRow) {
      dropTarget = hit.row;
      dropPosition = hit.position;
      hit.row.classList.add(hit.position === 'above' ? 'dy-drop-above' : 'dy-drop-below');
    } else {
      dropTarget = null;
      dropPosition = null;
    }

    // Compute indent only for hierarchical post types
    if (cfg.hierarchical) {
      dragIndent = Math.max(0, Math.min(4, Math.round(dx / indentStep())));
      if (dropTarget) dropTarget.setAttribute('data-dy-indent', String(dragIndent));
    } else {
      dragIndent = 0;
    }
  }

  function onPointerUp() {
    if (!dragRow) return;
    const row = dragRow;
    const wasDragging = dragging;
    const target = dropTarget;
    const position = dropPosition;
    const indent = dragIndent;

    // Visual cleanup — always, even if no real drag occurred.
    row.classList.remove('dy-dragging');
    body.classList.remove('dy-post-ordering-dragging');
    clearDropMarkers();
    dragRow = null;
    dropTarget = null;
    dropPosition = null;
    dragging = false;
    dragIndent = 0;

    if (!wasDragging || !target) return;

    // DOM snapshot before modification (for rollback on error).
    const snapshot = rowsArray();

    // Optimistically move the row in the DOM.
    if (position === 'above') {
      target.parentNode.insertBefore(row, target);
    } else {
      target.parentNode.insertBefore(row, target.nextSibling);
    }

    // Build the new item sequence
    const items = buildItemsFromDOM(row, indent);
    if (!items.length) return;

    persist(items, snapshot);
  }

  // ────────────────────────────────────────────────────────────
  // DOM serialization → REST items
  // ────────────────────────────────────────────────────────────
  /**
   * Walks rows after reordering to produce the item list to send to the REST
   * endpoint. For hierarchical post types, computes the parent based on the
   * target indent level of the moved row.
   *
   * To stay simple and predictable:
   *   - We send ALL visible rows (safety: the server deduplicates by only
   *     writing values that actually changed via wp_update_post —
   *     this is idempotent and lightweight).
   *   - menu_order is assigned in increments of 1, starting at 1 for
   *     the first visible row (WP sort treats NULL/0 differently;
   *     we keep 1+ to avoid that pitfall).
   *   - parent: for the moved row, it is the ID of the nearest previous
   *     visible row if dragIndent > its native indent; otherwise parent unchanged.
   *     All other rows keep their existing parent.
   */
  function buildItemsFromDOM(movedRow, movedIndent) {
    const rows = rowsArray();
    const items = [];
    let order = 1;

    rows.forEach((tr) => {
      const id = postIdOf(tr);
      if (!id) return;
      const row = { id: id, order: order++ };

      if (cfg.hierarchical) {
        if (tr === movedRow && movedIndent > 0) {
          // Find the nearest previous row in the DOM.
          let prev = tr.previousElementSibling;
          while (prev && !postIdOf(prev)) prev = prev.previousElementSibling;
          row.parent = prev ? postIdOf(prev) : 0;
        } else if (tr === movedRow && movedIndent === 0) {
          // Flat drag → parent = 0 (top-level).
          row.parent = 0;
        }
        // For other rows, we do NOT send `parent`: the server
        // will only update menu_order and preserve post_parent.
      }
      items.push(row);
    });
    return items;
  }

  // ────────────────────────────────────────────────────────────
  // REST Persistence
  // ────────────────────────────────────────────────────────────
  function persist(items, snapshot) {
    const payload = { post_type: cfg.postType, items: items };

    fetch(cfg.restRoot, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce
      },
      body: JSON.stringify(payload)
    })
      .then((res) => res.ok ? res.json() : Promise.reject(res))
      .then((json) => {
        if (json && json.success) return;
        rollback(snapshot);
        showError();
      })
      .catch(() => {
        rollback(snapshot);
        showError();
      });
  }

  function rollback(snapshot) {
    if (!snapshot || !snapshot.length) return;
    // Re-insert rows in snapshot order.
    snapshot.forEach((tr) => tbody.appendChild(tr));
  }

  function showError() {
    const msg = (cfg.i18n && cfg.i18n.saveFailed) || 'Reorder failed. Please refresh.';
    if (window.console && console.error) console.error('[doubleyoupi]', msg);
    // Native WP notice — visible in the list context.
    const wrap = document.querySelector('.wrap');
    if (!wrap) return;
    const notice = document.createElement('div');
    notice.className = 'notice notice-error is-dismissible';
    const p = document.createElement('p');
    p.textContent = msg;
    notice.appendChild(p);
    wrap.insertBefore(notice, wrap.firstChild);
    setTimeout(() => notice.remove(), 5000);
  }

  // ────────────────────────────────────────────────────────────
  // Bootstrap: a single delegated listener on tbody.
  // ────────────────────────────────────────────────────────────
  tbody.addEventListener('pointerdown', onPointerDown);
})();

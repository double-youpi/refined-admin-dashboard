/**
 * Double Youpi — Dashboard modulable v4
 *
 * Internal modules:
 *   State       — source of truth (bootstrap snapshot + UI state)
 *   Api         — REST wrappers (POST /layout, DELETE /layout, POST /widget/render)
 *   Nav         — multi-screen navigation (arrows, dots, keyboard, scroll-snap)
 *   Sidebar     — Customize panel (accordion sections, tiles drag source)
 *   DragDrop    — drag from sidebar to the slot row, push if full
 *   NativeShim  — toggle visibility of native WP widgets from the sidebar
 *
 * CSS class convention throughout this file: `.dy-dashboard__*`.
 * Initial HTML rendering comes from the PHP Renderer — this JS hydrates, does not reconstruct.
 */
(function () {
	'use strict';

	// ============================================================
	// STATE
	// ============================================================
	const state = {
		snapshot: window.doubleyoupiDashboardData || null,
		activeScreenIndex: 0,
		sidebarOpen: false,
		canCustomize: false,
		savingLayout: false,
		// Current drag state — set by DragDrop when the pointer drag starts.
		drag: null,
	};

	const dom = {
		root: null,
		viewport: null,
		screens: [],
		navArrows: { prev: null, next: null },
		dots: [],
		customizeBtn: null,
		sidebar: null,
		toastRoot: null,
	};

	// ============================================================
	// UTIL
	// ============================================================
	const Util = {
		uid(prefix) {
			return (prefix || 'dy') + '-' + Math.random().toString(36).slice(2, 9);
		},
		debounce(fn, delay) {
			let t = null;
			return function () {
				clearTimeout(t);
				const args = arguments;
				t = setTimeout(function () { fn.apply(null, args); }, delay);
			};
		},
		clamp(n, min, max) { return Math.max(min, Math.min(max, n)); },
		// "#1a2b3c" / "#abc" → "26, 43, 60" (for rgba(var(--…-rgb), .x)).
		hexToRgb(hex) {
			let h = String(hex || '').trim().replace(/^#/, '');
			if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
			if (!/^[0-9a-f]{6}$/i.test(h)) return '';
			return parseInt(h.slice(0,2),16) + ', ' + parseInt(h.slice(2,4),16) + ', ' + parseInt(h.slice(4,6),16);
		},
		// Parses any CSS color (#hex, rgb(), name) into [r,g,b] via a
		// 1×1 canvas. Memoized. Returns null if not parsable.
		_colorCache: {},
		parseColor(input) {
			const key = String(input || '').trim();
			if (!key) return null;
			if (this._colorCache[key] !== undefined) return this._colorCache[key];
			let out = null;
			try {
				const cv = document.createElement('canvas');
				cv.width = cv.height = 1;
				const ctx = cv.getContext('2d');
				ctx.fillStyle = '#000'; ctx.fillStyle = key; // 2× → invalid remains '#000'
				ctx.fillRect(0, 0, 1, 1);
				const d = ctx.getImageData(0, 0, 1, 1).data;
				out = [d[0], d[1], d[2]];
			} catch (e) { out = null; }
			this._colorCache[key] = out;
			return out;
		},
		// Interpolates two [r,g,b] arrays (t ∈ 0..1) → rounded "r, g, b" string.
		lerpRgb(a, b, t) {
			t = t < 0 ? 0 : t > 1 ? 1 : t;
			return Math.round(a[0] + (b[0]-a[0])*t) + ', '
				+ Math.round(a[1] + (b[1]-a[1])*t) + ', '
				+ Math.round(a[2] + (b[2]-a[2])*t);
		},
		escapeHtml(s) {
			return String(s).replace(/[&<>"']/g, c => ({
				'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
			}[c]));
		},
		// Auto-wraps the last non-whitespace token of the title in <em class="dy-dashboard__title-em">.
		// `\S+` captures the word AND any immediately attached punctuation ("nice.", "admin!",
		// "hello…") — so all trailing punctuation gets the same accent color as the word.
		// Exact mirror of Renderer::wrap_title_accent() on the PHP side,
		// so that live editing reflects the accent in real time.
		wrapTitleAccent(title) {
			if (!title) return '';
			const m = title.match(/(\S+)(\s*)$/);
			if (!m) return Util.escapeHtml(title);
			const lastWord = m[1];
			const trailingWs = m[2] || '';
			const beforeLen = title.length - lastWord.length - trailingWs.length;
			const before = title.slice(0, beforeLen);
			const after  = trailingWs;
			return Util.escapeHtml(before)
				+ '<em class="dy-dashboard__title-em">' + Util.escapeHtml(lastWord) + '</em>'
				+ Util.escapeHtml(after);
		},
		// Source of truth = state. computeUsedFromState prevents
		// DOM/state desynchronization.
		computeUsedFromState(screenIdx) {
			const screen = state.snapshot.layout.screens[screenIdx];
			if (!screen) return 0;
			return (screen.widgets || []).reduce((s, w) => s + (parseInt(w.slots, 10) || 1), 0);
		},
		// Simple token resolution via the server-side `token_values` map.
		resolveTokens(text) {
			if (!text) return '';
			const map = (state.snapshot && state.snapshot.token_values) || {};
			let out = text;
			Object.keys(map).forEach(k => { out = out.split(k).join(map[k]); });
			return out;
		},
		// Light Markdown for the paragraph: **bold**, *italic*, `code`,
		// [label](url). Identical to Renderer::apply_light_markdown on the PHP side.
		lightMarkdown(text) {
			let out = Util.escapeHtml(text);
			out = out.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
			out = out.replace(/(^|[^\w*])\*([^*\n]+)\*(?![\w*])/g, '$1<em>$2</em>');
			out = out.replace(/`([^`\n]+)`/g, '<code>$1</code>');
			out = out.replace(
				/\[([^\]]+)\]\(([^)\s]+)\)/g,
				(m, label, url) => '<a class="dy-dashboard__paragraph-link" href="' + Util.escapeHtml(url) + '">' + label + '</a>'
			);
			return out;
		},
		// i18n — retrieves a translated string from the bootstrap data (state.snapshot.i18n).
		// Falls back to the key itself if missing (never in production: PHP provides everything).
		t(key) {
			const map = (state.snapshot && state.snapshot.i18n) || {};
			return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : key;
		},
		// Minimal sprintf: replaces %s / %d sequentially.
		sprintf(fmt) {
			const args = Array.prototype.slice.call(arguments, 1);
			let i = 0;
			return String(fmt).replace(/%[sd]/g, () => (i < args.length ? String(args[i++]) : ''));
		}
	};

	// Global i18n shortcut within the module.
	const t = (key) => Util.t(key);

	// ============================================================
	// NOTICES — moves all native WP notices into a fixed stack
	// at the bottom-right so they do not push content and
	// do not overlap each other.
	// ============================================================
	const NoticesRelocator = {
		stack: null,
		_initted: false,
		init() {
			if (this._initted) return;
			this._initted = true;

			const selectors = [
				'#wpbody-content > .notice',
				'#wpbody-content > .updated',
				'#wpbody-content > .error',
				'#wpbody-content .wrap > .notice',
				'#wpbody-content .wrap > .updated',
				'#wpbody-content .wrap > .error'
			];
			document.querySelectorAll(selectors.join(',')).forEach(n => this._handle(n));

			// Observer for dynamically added notices (e.g., after an AJAX action
			// that injects a notice). We process them as they appear.
			const observer = new MutationObserver(muts => {
				muts.forEach(m => {
					m.addedNodes.forEach(node => {
						if (node.nodeType !== 1) return;
						if (node.classList && (node.classList.contains('notice') || node.classList.contains('updated') || node.classList.contains('error'))) {
							this._handle(node);
						}
					});
				});
			});
			const target = document.getElementById('wpbody-content');
			if (target) observer.observe(target, { childList: true, subtree: true });
		},
		// Decides the fate of a notice: promo/upsell banner from a third-party plugin →
		// suppressed (the dashboard is "curated", not an advertising display panel);
		// genuine actionable notice → relocated into the stack.
		_handle(node) {
			if (node.dataset.dyNoticeHandled === '1') return;
			// Kept by its plugin inside a hidden parent: a template, not a
			// message (Backuply, compatibility lab, 26/10/2026).
			for (let a = node.parentElement; a && a !== document.body; a = a.parentElement) {
				if (a.hidden || getComputedStyle(a).display === 'none') return;
			}
			node.dataset.dyNoticeHandled = '1';
			if (this._isPromo(node)) {
				node.classList.add('dy-dashboard__notice-suppressed');
				return;
			}
			// `below-h2` keeps WordPress (common.js, on jQuery ready) from moving
			// it back under the page header, that is under the carousel, where
			// no notice could be seen (compatibility lab, R003, 24/10/2026).
			node.classList.add('below-h2');
			this._ensureStack();
			this.list.appendChild(node);
			this._refresh();
		},
		// Heuristic for "promotional notice" (multi-vendor, without a fragile blocklist).
		// A legitimate WP notice (settings saved, update available,
		// errors) is text + buttons, compact and often dismissible.
		_isPromo(node) {
			// 1. An <img> = marketing logo/banner. Real notices use
			//    dashicons/SVG icons via CSS, never an <img> tag.
			if (node.querySelector('img')) return true;
			// 2. Promo class signatures, generic to the WP ecosystem.
			if (node.querySelector(
				'[class*="promo"],[class*="upsell"],[class*="banner"],[class*="-cta"],' +
				'[class*="dashboard-notice"],[class*="business-notice"],[class*="review-notice"]'
			)) return true;
			// 3. Outbound upsell link: pricing, "go pro", campaign parameters
			//    (utm Upgrade), /pro, ?plan=… → it is an invitation to pay, not
			//    an actionable notice. The most reliable signal for "text-only" promos
			//    (e.g. Rank Math PRO) that slip past the first two checks.
			var UPSELL = /pricing|go-?pro|\/pro\b|[?&]plan=|utm_medium=upgrade|utm_campaign=(?:upgrade|upsell|pro)\b/i;
			var links = node.querySelectorAll('a[href]');
			for (var i = 0; i < links.length; i++) {
				if (UPSELL.test(links[i].getAttribute('href') || '')) return true;
			}
			// 4. Fallback: abnormally tall block AND not dismissible.
			if (!node.classList.contains('is-dismissible')
				&& node.getBoundingClientRect().height > 220) return true;
			return false;
		},
		// Collapsed into the « Notifications » pill of the other screens, which
		// unfolds the notices above it on click: spread out, the stack covered
		// half of the widgets on every screen (Raphaël, 26/10/2026, option A).
		_ensureStack() {
			if (!this.stack) {
				const label = t('notifications') === 'notifications' ? 'Notifications' : t('notifications');
				this.stack = document.createElement('div');
				this.stack.className = 'dy-dashboard__notices-stack';
				this.list = document.createElement('div');
				this.list.className = 'dy-dashboard__notices-list';
				this.list.id = 'dy-dashboard-notices';
				this.list.hidden = true;
				this.toggle = document.createElement('button');
				this.toggle.type = 'button';
				this.toggle.className = 'dy-notice-trigger dy-dashboard__notices-toggle';
				this.toggle.setAttribute('aria-expanded', 'false');
				this.toggle.setAttribute('aria-controls', this.list.id);
				this.toggle.innerHTML = '<svg class="dy-notice-trigger__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'
					+ '<span class="dy-notice-trigger__label"></span><span class="dy-notice-trigger__count">0</span>';
				this.toggle.querySelector('.dy-notice-trigger__label').textContent = label;
				this.toggle.addEventListener('click', () => this._open(this.list.hidden));
				this.stack.addEventListener('keydown', (e) => {
					if (e.key === 'Escape' && !this.list.hidden) { this._open(false); this.toggle.focus(); }
				});
				this.stack.appendChild(this.list);
				this.stack.appendChild(this.toggle);
				document.body.appendChild(this.stack);
				// A dismissed notice leaves the list: recount, and go away at zero.
				new MutationObserver(() => this._refresh()).observe(this.list, { childList: true });
			}
			return this.stack;
		},
		_open(open) {
			this.list.hidden = !open;
			this.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			this.stack.classList.toggle('is-open', open);
			// The notices are visible now: let the light islands read them.
			if (open) { try { document.dispatchEvent(new CustomEvent('doubleyoupi:rescan')); } catch (e) { /* old browsers */ } }
		},
		_refresh() {
			const n = this.list.children.length;
			this.toggle.querySelector('.dy-notice-trigger__count').textContent = String(n);
			this.stack.hidden = n === 0;
		}
	};

	// ============================================================
	// TOAST (ephemeral notification)
	// ============================================================
	const Toast = {
		show(message, type) {
			type = type || 'info';
			if (!dom.toastRoot) {
				dom.toastRoot = document.createElement('div');
				dom.toastRoot.className = 'dy-dashboard__toast-root';
				// Mounted on <body>, outside the fixed dashboard root (z-index 1): a
				// toast must float above the WP notices stack (z-index 75), which
				// lives at document level and otherwise covered it.
				document.body.appendChild(dom.toastRoot);
			}
			const el = document.createElement('div');
			el.className = 'dy-dashboard__toast dy-dashboard__toast--' + type;
			el.textContent = message;
			dom.toastRoot.appendChild(el);
			requestAnimationFrame(() => el.classList.add('is-visible'));
			setTimeout(() => {
				el.classList.remove('is-visible');
				setTimeout(() => el.remove(), 320);
			}, 2600);
		}
	};

	// ============================================================
	// TOKEN PICKER — displays a "dynamic content" button next to
	// an input/textarea and offers a dropdown of available tokens
	// ({{user.firstname}}, {{site.name}}, etc.). Inspired by the
	// "Dynamic data" picker in Bricks Builder.
	// ============================================================
	// Singleton — a single shared DOM dropdown for all fields.
	// Position is calculated dynamically based on the active field.
	const TokenPicker = {
		drop: null,
		activeField: null,
		activeBtn: null,
		_globalsBound: false,

		attach(field) {
			if (!field || field.__dyTokenAttached) return;
			field.__dyTokenAttached = true;

			const tokens = (state.snapshot && Array.isArray(state.snapshot.tokens)) ? state.snapshot.tokens : [];
			if (!tokens.length) return;

			// Relative wrapper around the field to anchor the picker button.
			const wrap = document.createElement('div');
			wrap.className = 'dy-dashboard__token-wrap';
			field.parentNode.insertBefore(wrap, field);
			wrap.appendChild(field);

			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'dy-dashboard__token-btn';
			btn.setAttribute('aria-label', t('insert_token'));
			btn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
			wrap.appendChild(btn);

			btn.addEventListener('mousedown', (e) => e.preventDefault()); // keep field focus
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				e.stopPropagation();
				if (TokenPicker.activeField === field) {
					TokenPicker.close();
				} else {
					TokenPicker.open(field, wrap, btn);
				}
			});
		},

		_ensureDrop() {
			if (this.drop) return this.drop;

			const tokens = (state.snapshot && Array.isArray(state.snapshot.tokens)) ? state.snapshot.tokens : [];
			const groups = {};
			tokens.forEach(t => {
				const g = t.group || 'misc';
				if (!groups[g]) groups[g] = [];
				groups[g].push(t);
			});
			const groupLabels = { user: 'Utilisateur', site: 'Site', date: 'Date', updates: 'Mises à jour', misc: 'Autres' };

			const drop = document.createElement('div');
			drop.className = 'dy-dashboard__token-drop';
			drop.setAttribute('hidden', '');
			let html = '';
			Object.keys(groups).forEach(g => {
				html +=
					'<div class="dy-dashboard__token-group">' +
						'<h5>' + Util.escapeHtml(groupLabels[g] || g) + '</h5>' +
						groups[g].map(t =>
							'<button type="button" class="dy-dashboard__token-item" data-token="' + Util.escapeHtml(t.token) + '">' +
								'<code>' + Util.escapeHtml(t.token) + '</code>' +
								'<span>' + Util.escapeHtml(t.label) + '</span>' +
							'</button>'
						).join('') +
					'</div>';
			});
			drop.innerHTML = html;
			document.body.appendChild(drop);

			drop.addEventListener('mousedown', (e) => e.preventDefault()); // keep field focus
			drop.addEventListener('click', (e) => {
				const item = e.target.closest('[data-token]');
				if (!item) return;
				e.preventDefault();
				if (this.activeField) {
					this._insertAt(this.activeField, item.dataset.token);
					this.activeField.dispatchEvent(new Event('input', { bubbles: true }));
					this.activeField.focus();
				}
				this.close();
			});

			if (!this._globalsBound) {
				this._globalsBound = true;
				document.addEventListener('mousedown', (e) => {
					if (!this.drop || this.drop.hasAttribute('hidden')) return;
					if (this.drop.contains(e.target)) return;
					if (this.activeBtn && this.activeBtn.contains(e.target)) return;
					this.close();
				});
				document.addEventListener('keydown', (e) => {
					if (e.key === 'Escape') this.close();
				});
				window.addEventListener('resize', () => {
					if (this.activeField && this.activeBtn) {
						this._positionAt(this.activeBtn);
					}
				});
				window.addEventListener('scroll', () => {
					if (this.activeField && this.activeBtn) {
						this._positionAt(this.activeBtn);
					}
				}, true);
			}

			this.drop = drop;
			return drop;
		},

		open(field, wrap, btn) {
			this._ensureDrop();
			this.activeField = field;
			this.activeBtn   = btn;
			this._positionAt(btn);
			this.drop.removeAttribute('hidden');
			btn.classList.add('is-active');
		},

		close() {
			if (this.activeBtn) this.activeBtn.classList.remove('is-active');
			if (this.drop) this.drop.setAttribute('hidden', '');
			this.activeField = null;
			this.activeBtn = null;
		},

		_positionAt(btn) {
			const rect = btn.getBoundingClientRect();
			const drop = this.drop;
			// Position below-right of the button with a small gap. Clamped to the screen.
			drop.style.position = 'fixed';
			drop.style.top = (rect.bottom + 6) + 'px';
			drop.style.right = Math.max(8, (window.innerWidth - rect.right)) + 'px';
			drop.style.left = 'auto';
			drop.style.maxHeight = (window.innerHeight - rect.bottom - 24) + 'px';
		},

		_insertAt(field, token) {
			const start = field.selectionStart || 0;
			const end   = field.selectionEnd || 0;
			const val   = field.value || '';
			field.value = val.slice(0, start) + token + val.slice(end);
			const pos   = start + token.length;
			field.selectionStart = field.selectionEnd = pos;
		}
	};

	// ============================================================
	// API
	// ============================================================
	const Api = {
		_headers() {
			return {
				'Content-Type': 'application/json',
				'X-WP-Nonce': state.snapshot.nonce
			};
		},
		async saveLayout(layout) {
			state.savingLayout = true;
			try {
				const res = await fetch(state.snapshot.rest_url + '/layout', {
					method: 'POST',
					credentials: 'same-origin',
					headers: this._headers(),
					body: JSON.stringify({ layout: layout }),
					// `keepalive`: the request completes on the server even if
					// the user navigates away right after (otherwise the browser
					// cancels the in-flight fetch → the added widget would be lost).
					keepalive: true
				});
				const json = await res.json();
				if (!res.ok) {
					throw new Error((json && json.message) || 'Save failed');
				}
				state.snapshot.layout = json.layout;
				return json.layout;
			} finally {
				state.savingLayout = false;
			}
		},
		async resetLayout() {
			const res = await fetch(state.snapshot.rest_url + '/layout', {
				method: 'DELETE',
				credentials: 'same-origin',
				headers: this._headers()
			});
			const json = await res.json();
			if (!res.ok) throw new Error((json && json.message) || 'Reset failed');
			return json;
		},
		// #6 — Restores a layout (undo/redo): the server validates, saves, AND returns
		// the full HTML of the screens → re-render without reload.
		async restoreLayout(layout) {
			const res = await fetch(state.snapshot.rest_url + '/restore', {
				method: 'POST',
				credentials: 'same-origin',
				headers: this._headers(),
				body: JSON.stringify({ layout: layout })
			});
			const json = await res.json();
			if (!res.ok) throw new Error((json && json.message) || 'Restore failed');
			return json;
		},
		async renderWidget(id, config, instance) {
			const res = await fetch(state.snapshot.rest_url + '/widget/render', {
				method: 'POST',
				credentials: 'same-origin',
				headers: this._headers(),
				body: JSON.stringify({ id: id, config: config || {}, instance: instance || null })
			});
			const json = await res.json();
			if (!res.ok) throw new Error((json && json.message) || 'Render failed');
			return json;
		},
		async saveDisposition(payload) {
			const res = await fetch(state.snapshot.rest_url + '/disposition', {
				method: 'POST',
				credentials: 'same-origin',
				headers: this._headers(),
				body: JSON.stringify({
					name: payload.name || '',
					slug: payload.slug || '',
					layout: payload.layout
				})
			});
			const json = await res.json();
			if (!res.ok) throw new Error((json && json.message) || 'Save failed');
			return json;
		},
		async assignDisposition(slug, roles) {
			const res = await fetch(state.snapshot.rest_url + '/disposition/assign', {
				method: 'POST',
				credentials: 'same-origin',
				headers: this._headers(),
				body: JSON.stringify({ slug: slug, roles: roles })
			});
			const json = await res.json();
			if (!res.ok) throw new Error((json && json.message) || 'Assign failed');
			return json;
		},
		async deleteDisposition(slug) {
			const res = await fetch(state.snapshot.rest_url + '/disposition', {
				method: 'DELETE',
				credentials: 'same-origin',
				headers: this._headers(),
				body: JSON.stringify({ slug: slug })
			});
			const json = await res.json();
			if (!res.ok) throw new Error((json && json.message) || 'Delete failed');
			return json;
		}
	};

	// ============================================================
	// NAV — Phase 5
	// ============================================================
	const Nav = {
		init() {
			if (!dom.viewport) return;

			if (dom.navArrows.prev) {
				dom.navArrows.prev.addEventListener('click', () => this.go(state.activeScreenIndex - 1));
			}
			if (dom.navArrows.next) {
				dom.navArrows.next.addEventListener('click', () => this.go(state.activeScreenIndex + 1));
			}

			dom.dots.forEach(dot => this._bindDot(dot));

			// #2 — trash affordance in the eyebrow of user screens
			// rendered server-side (screens added live go through
			// _resyncScreens). Idempotent.
			if (state.canCustomize) {
				dom.root.querySelectorAll('.dy-dashboard__screen[data-screen-kind="user"]').forEach(s => this._ensureEyebrowDelete(s));
			}

			this._buildNavAux();

			// Leaving the entire pagination area → close all insertion "+" buttons.
			const pag = dom.root.querySelector('.dy-dashboard__pagination');
			if (pag) pag.addEventListener('mouseleave', () => this._hideAllInserts());

			document.addEventListener('keydown', (e) => {
				if (state.sidebarOpen) return; // sidebar has its own keyboard handler
				if (document.activeElement && /input|textarea|select/i.test(document.activeElement.tagName)) return;
				// Horizontal AND vertical: up = left = previous; down = right
				// = next. PageUp/Home/PageDown/End follow the same logic.
				if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === 'PageDown') { e.preventDefault(); this.go(state.activeScreenIndex + 1); }
				else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp' || e.key === 'PageUp') { e.preventDefault(); this.go(state.activeScreenIndex - 1); }
				else if (e.key === 'Home') { e.preventDefault(); this.go(0); }
				else if (e.key === 'End') { e.preventDefault(); this.go(dom.screens.length - 1); }
			});

			// ── Navigation by wheel / VERTICAL swipe (horizontal desktop layout) ──
			// The viewport scrolls horizontally; we map vertical intent
			// (wheel up/down, swipe up/down) to prev/next. The native horizontal
			// gesture (lateral trackpad, shift+wheel) continues to scroll the
			// viewport directly → we do not intercept it.
			this._wireVerticalNav();

			// ── MOUSE drag (horizontal click-drag) → navigation ──
			// Native scroll-snap only responds to trackpad/touch, never to
			// mouse drag: we implement it ourselves (mouse Pointer Events).
			this._wireDragScroll();

			// Sync on native horizontal scroll (desktop swipe/trackpad).
			dom.viewport.addEventListener('scroll', Util.debounce(() => this.syncFromScroll(), 80));

			// Gradient tint synchronized to the swipe: any scroll event
			// starts a rAF loop that samples the position at 60fps (scroll
			// events alone are throttled by scroll-snap during a swipe
			// → insufficient). The loop self-terminates when the scroll stabilizes.
			dom.viewport.addEventListener('scroll', () => Ambient.startTintLoop(), { passive: true });

			// Robust active screen detection, valid in both
			// orientations (horizontal desktop / vertical mobile), via
			// IntersectionObserver — each screen that occupies the center of
			// the visible area becomes the active one.
			if ('IntersectionObserver' in window) {
				this._io = new IntersectionObserver((entries) => {
					// During an undo/redo re-render, we (re)observe all screens:
					// the IO refires at the old scroll position and would overwrite
					// the target screen. We suppress it until the swipe is done.
					if (this._ioSuppressed) return;
					entries.forEach(entry => {
						if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
							const idx = dom.screens.indexOf(entry.target);
							if (idx !== -1 && idx !== state.activeScreenIndex) {
								state.activeScreenIndex = idx;
								this.sync();
							}
						}
					});
				}, { threshold: [0.5] });
				dom.screens.forEach(s => this._io.observe(s));
			}

			// Restores the active screen after a reload or browser back/forward.
			// We store the index in sessionStorage per URL → we return
			// exactly to the right screen without animation (direct jump).
			this._restoreActiveScreen();
			this.sync();
		},
		// sessionStorage key per admin page (pathname) — scoped to this dashboard.
		_sessionKey() {
			return 'dy-dash-screen:' + (location.pathname || '');
		},
		_persistActiveScreen() {
			try { window.sessionStorage.setItem(this._sessionKey(), String(state.activeScreenIndex)); } catch (e) { /* noop */ }
		},
		// Resets the stored screen → the next (re)load starts on
		// screen 1. Called by flows that replace the layout and then reload
		// (apply / clear / import a layout preset, reset).
		_clearStoredScreen() {
			state.activeScreenIndex = 0;
			try { window.sessionStorage.setItem(this._sessionKey(), '0'); } catch (e) { /* noop */ }
		},
		_restoreActiveScreen() {
			// The inline script (DashboardOverride::render_root) has already positioned the
			// scroll BEFORE the first paint to avoid the flash. If so,
			// we simply synchronize the state index — no re-scroll needed.
			const restored = dom.viewport && dom.viewport.getAttribute('data-restored-screen');
			if (restored != null && restored !== '') {
				const ri = Util.clamp(parseInt(restored, 10) || 0, 0, dom.screens.length - 1);
				state.activeScreenIndex = ri;
				dom.viewport.removeAttribute('data-restored-screen');
				return;
			}
			let idx = NaN;
			try { idx = parseInt(window.sessionStorage.getItem(this._sessionKey()), 10); } catch (e) { /* noop */ }
			if (!isFinite(idx) || idx <= 0) return; // 0 = default, nothing to do
			const max = dom.screens.length - 1;
			idx = Util.clamp(idx, 0, max);
			if (idx === state.activeScreenIndex) return;
			state.activeScreenIndex = idx;
			// Fallback (if the inline script did not run): position the scroll without smooth.
			if (dom.viewport && !this._isVertical()) {
				const prev = dom.viewport.style.scrollBehavior;
				dom.viewport.style.scrollBehavior = 'auto';
				dom.viewport.scrollLeft = (dom.screens[idx] && dom.screens[idx].offsetLeft) || idx * dom.viewport.clientWidth;
				requestAnimationFrame(() => { dom.viewport.style.scrollBehavior = prev || ''; });
			} else if (dom.screens[idx] && dom.screens[idx].scrollIntoView) {
				dom.screens[idx].scrollIntoView({ block: 'start' });
			}
		},
		_isVertical() {
			return dom.viewport && getComputedStyle(dom.viewport).flexDirection === 'column';
		},
		// Is the pointer hovering over an inner scrollable area (e.g. the native screen
		// list) that should keep its own vertical scroll? If so, we do not
		// intercept the wheel/swipe.
		_overInnerScroll(target) {
			const list = target && target.closest && target.closest('.dy-dashboard__native-list, .dy-dashboard__sidebar-scroll, [data-inner-scroll]');
			if (!list) return false;
			return list.scrollHeight > list.clientHeight + 2; // actually scrollable
		},
		// VERTICAL wheel → nav (up = previous, down = next). Short, fixed lock:
		// one notch triggers a jump, then events are ignored for
		// ~380ms. A powerful inertia flick may skip a few screens — this is
		// accepted (no attempt to neutralize it). Active in horizontal layout
		// (desktop); in vertical (mobile) the native page scroll handles everything.
		// NB: no touch handlers here — HORIZONTAL touch swipe remains handled
		// natively by the viewport (scroll-snap x).
		_wireVerticalNav() {
			if (!dom.viewport) return;

			let lock = false;
			const THRESH = 8; // ignore micro-movements
			dom.viewport.addEventListener('wheel', (e) => {
				if (this._isVertical() || state.sidebarOpen) return;
				if (this._overInnerScroll(e.target)) return;
				// Predominantly horizontal gesture → let native scroll handle it.
				if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) return;
				e.preventDefault(); // hijack vertical scroll for navigation
				if (lock || Math.abs(e.deltaY) < THRESH) return;
				lock = true;
				setTimeout(() => { lock = false; }, 380);
				this.go(state.activeScreenIndex + (e.deltaY > 0 ? 1 : -1));
			}, { passive: false });
		},
		// MOUSE drag-to-navigate (native scroll-snap ignores mouse drag).
		// We follow the pointer by setting scrollLeft directly
		// (snap disabled during drag), then snap to the nearest screen on release —
		// with a "flick" threshold to validate even a small fast gesture.
		// Mouse only: touch remains 100% native, and
		// we do not interfere with widget drag (edit mode).
		_wireDragScroll() {
			if (!dom.viewport) return;
			const vp = dom.viewport;
			let dragging = false, startX = 0, startScroll = 0, lastX = 0, moved = false;

			const onMove = (e) => {
				if (!dragging) return;
				const dx = e.clientX - startX;
				if (!moved && Math.abs(dx) < 4) return; // tolerance before capturing
				moved = true;
				lastX = e.clientX;
				vp.scrollLeft = startScroll - dx;
			};
			const restoreSnap = () => { vp.style.removeProperty('scroll-snap-type'); };
			const onUp = (e) => {
				document.removeEventListener('pointermove', onMove);
				document.removeEventListener('pointerup', onUp);
				vp.classList.remove('is-drag-scrolling');
				if (!dragging) { restoreSnap(); return; }
				dragging = false;
				if (!moved) { restoreSnap(); return; } // plain click → do not navigate

				const w = vp.clientWidth || 1;
				const totalDx = (e.clientX || lastX) - startX;
				const FLICK = 60; // a decisive gesture validates a jump even < half-screen
				let target = Math.round(vp.scrollLeft / w);
				if (Math.abs(totalDx) > FLICK) {
					// gesture direction: drag to the left = next screen.
					target = state.activeScreenIndex + (totalDx < 0 ? 1 : -1);
				}
				target = Util.clamp(target, 0, dom.screens.length - 1);

				// IMPORTANT: we animate toward the target WITH snap still disabled.
				// If we re-enabled snap now, the browser would instantly snap
				// to the current screen ("full-screen return") before
				// our animation. We therefore scroll manually (smooth) — including
				// when target = current screen (small drag → gentle return) —
				// then re-enable snap once the animation completes.
				state.activeScreenIndex = target;
				vp.scrollTo({ left: target * w, behavior: 'smooth' });
				this.sync();
				Ambient.startTintLoop();
				clearTimeout(this._dragSnapT);
				this._dragSnapT = setTimeout(restoreSnap, 620);
			};

			vp.addEventListener('pointerdown', (e) => {
				if (e.pointerType !== 'mouse' || e.button !== 0) return; // mouse, left click
				if (this._isVertical()) return;
				// Edit mode (widget drag) → let DragDrop operate.
				if (dom.root.classList.contains('dy-dashboard--editable')) return;
				// Interactive targets: do not capture (links, buttons, fields,
				// inner scroll areas).
				if (e.target.closest && e.target.closest('a, button, input, textarea, select, [contenteditable], .dy-dashboard__native-list, [data-inner-scroll]')) return;

				dragging = true; moved = false;
				startX = e.clientX; lastX = e.clientX;
				startScroll = vp.scrollLeft;
				// Snap off during drag → free pointer tracking.
				vp.style.scrollSnapType = 'none';
				vp.classList.add('is-drag-scrolling');
				document.addEventListener('pointermove', onMove);
				document.addEventListener('pointerup', onUp);
			});
		},
		go(index) {
			const max = dom.screens.length - 1;
			const target = Util.clamp(index, 0, max);
			if (target === state.activeScreenIndex) return;
			const screenEl = dom.screens[target];
			if (this._isVertical()) {
				// Mobile: vertical scroll to the target screen inside the
				// scrollable admin container (#wpcontent).
				if (screenEl) screenEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
			} else {
				dom.viewport.scrollTo({
					left: target * dom.viewport.clientWidth,
					behavior: 'smooth'
				});
			}
			state.activeScreenIndex = target;
			this.sync();
			// Drives the tint throughout the smooth-scroll animation.
			Ambient.startTintLoop();
		},
		syncFromScroll() {
			if (!dom.viewport || this._isVertical()) return;
			const w = dom.viewport.clientWidth;
			if (w <= 0) return;
			const idx = Math.round(dom.viewport.scrollLeft / w);
			if (idx !== state.activeScreenIndex) {
				state.activeScreenIndex = idx;
				this.sync();
			}
		},
		sync() {
			const idx = state.activeScreenIndex;
			dom.dots.forEach((d, i) => {
				d.classList.toggle('is-active', i === idx);
				if (i === idx) { d.setAttribute('aria-current', 'true'); } else { d.removeAttribute('aria-current'); }
			});
			if (dom.navArrows.prev) {
				dom.navArrows.prev.disabled = idx <= 0;
				dom.navArrows.prev.setAttribute('aria-disabled', idx <= 0 ? 'true' : 'false');
			}
			if (dom.navArrows.next) {
				const last = dom.screens.length - 1;
				dom.navArrows.next.disabled = idx >= last;
				dom.navArrows.next.setAttribute('aria-disabled', idx >= last ? 'true' : 'false');
			}
			Ambient.update(idx);
			Worm.moveTo(idx, true);
			// Persist the active screen (reload/back nav restore this same screen).
			this._persistActiveScreen();
			// #5 — when the panel is open, the active screen has changed: we
			// resynchronize the "Current Screen" sub-panel (title/para fields)
			// and inline editability. Without this, the fields stayed frozen on
			// the previous screen.
			if (state.sidebarOpen && Sidebar.onActiveScreenChanged) Sidebar.onActiveScreenChanged();
		},

		// Creates the tooltip + the interleaved "+" insertion buttons (once only).
		_buildNavAux() {
			const nav = dom.root && dom.root.querySelector('.dy-dashboard__nav');
			const pag = dom.root && dom.root.querySelector('.dy-dashboard__pagination');
			if (!nav || !pag) return;
			if (!this._tip) {
				this._tip = document.createElement('span');
				this._tip.className = 'dy-dashboard__nav-tip';
				this._tip.setAttribute('aria-hidden', 'true');
				nav.appendChild(this._tip);
			}
			if (state.canCustomize) this._buildInserts();
		},
		// Builds the "+" insertion buttons as FLEX elements interleaved between
		// dots (not as absolute overlays): their expansion on hover pushes
		// neighboring dots → regular spacing. Index k = insert a screen at
		// position k (0..userCount). Never around the native dot.
		_buildInserts() {
			const pag = dom.root.querySelector('.dy-dashboard__pagination');
			if (!pag) return;
			// Clean up any existing inserts.
			pag.querySelectorAll('.dy-dashboard__nav-insert').forEach(n => n.remove());
			this._inserts = [];
			const userCount = ((state.snapshot.layout && state.snapshot.layout.screens) || []).length;
			const lis = Array.from(pag.querySelectorAll(':scope > li'));
			const make = (k) => {
				const ins = document.createElement('span');
				ins.className = 'dy-dashboard__nav-insert';
				ins.setAttribute('role', 'button');
				ins.setAttribute('tabindex', '0');
				ins.dataset.insertIndex = String(k);
				ins.setAttribute('aria-label', t('add_screen_plain'));
				ins.innerHTML = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
				ins.addEventListener('click', (e) => { e.stopPropagation(); Sidebar._insertScreen(parseInt(ins.dataset.insertIndex, 10)); });
				ins.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); Sidebar._insertScreen(parseInt(ins.dataset.insertIndex, 10)); } });
				// Keep the "+" open + tooltip when hovering over it.
				ins.addEventListener('mouseenter', () => { ins.classList.add('is-shown'); this._showInsertTip(ins); });
				ins.addEventListener('mouseleave', () => this.hideTip());
				return ins;
			};
			// Insert k placed before li[k] (li[userCount] = native screen or nothing).
			for (let k = 0; k <= userCount; k++) {
				const ins = make(k);
				const ref = lis[k] || null;
				pag.insertBefore(ins, ref);
				this._inserts.push(ins);
			}
		},
		// Shows the two "+" buttons flanking dot at index i (insertions i and
		// i+1), hides the rest. i = dot index; does not flank the native dot.
		_showFlankingInserts(i) {
			if (!this._inserts || !this._inserts.length) return;
			// Insertion "+" buttons only appear in edit mode (Customize panel
			// open). Outside edit mode, hovering a dot → nothing.
			if (!dom.root.classList.contains('dy-dashboard--editable')) return;
			const userCount = ((state.snapshot.layout && state.snapshot.layout.screens) || []).length;
			// For a user screen dot i: insertions i (left) and i+1 (right).
			// For the native dot (i === userCount): only insertion userCount to its left.
			const left = i;
			const right = (i < userCount) ? i + 1 : -1;
			this._inserts.forEach(ins => {
				const k = parseInt(ins.dataset.insertIndex, 10);
				ins.classList.toggle('is-shown', k === left || k === right);
			});
			Worm.follow(260); // follows dot movement during expansion
		},
		_hideAllInserts() {
			if (!this._inserts) return;
			this._inserts.forEach(ins => ins.classList.remove('is-shown'));
			this.hideTip();
			Worm.follow(260);
		},
		_showInsertTip(ins) {
			if (!this._tip) return;
			this._tip.textContent = t('add_screen_plain');
			this._tip.classList.add('is-visible');
			const navRect = this._tip.parentElement.getBoundingClientRect();
			const r = ins.getBoundingClientRect();
			this._tip.style.left = (r.left + r.width / 2 - navRect.left) + 'px';
		},
		// Dot binding: the index is derived LIVE from the position in dom.dots
		// (not captured) → stays correct after screen insertion/reordering.
		_bindDot(dot) {
			if (dot.dataset.dyBound === '1') return; // idempotent (undo/redo re-render)
			dot.dataset.dyBound = '1';
			dot.addEventListener('click', () => this.go(dom.dots.indexOf(dot)));
			dot.addEventListener('mouseenter', () => { const i = dom.dots.indexOf(dot); this.showTip(i, dot); this._showFlankingInserts(i); });
			dot.addEventListener('mouseleave', () => this.hideTip());
			dot.addEventListener('focus', () => this.showTip(dom.dots.indexOf(dot), dot));
			dot.addEventListener('blur', () => this.hideTip());
		},
		// ── Live screen insertion (no reload) + swipe to it (#5) ──
		// state.snapshot.layout.screens already contains the new screen at `index`.
		// We build its article, insert it, resynchronize dots/eyebrows/
		// IO/worm/inserts, then slide to it.
		addScreenLive(index) {
			const screens = state.snapshot.layout.screens;
			const screen  = screens[index];
			if (!dom.viewport || !screen) return false;

			// 1. Article for the new (empty) screen.
			const art = document.createElement('article');
			art.className = 'dy-dashboard__screen';
			art.dataset.screenId = screen.id || ('scr-' + (index + 1));
			art.dataset.screenIndex = String(index);
			art.dataset.screenKind = 'user';
			const titleHtml = (screen.title && screen.title.trim() !== '')
				? '<h2 class="dy-dashboard__title">' + Util.wrapTitleAccent(Util.resolveTokens(screen.title)) + '</h2>'
				: '<h2 class="dy-dashboard__title dy-dashboard__title--placeholder" aria-hidden="true">&nbsp;</h2>';
			art.innerHTML =
				'<div class="dy-dashboard__screen-inner">' +
					'<header class="dy-dashboard__screen-header">' +
						'<div class="dy-dashboard__eyebrow"><span class="dy-dashboard__eyebrow-count"></span></div>' +
						titleHtml +
					'</header>' +
					'<div class="dy-dashboard__slots" data-slots-total="6" data-slots-used="0"></div>' +
				'</div>';

			// 2. DOM insertion: before the article currently at that index,
			//    otherwise before the native screen, otherwise at the end of the viewport.
			const userArticles = Array.from(dom.viewport.querySelectorAll('.dy-dashboard__screen[data-screen-kind="user"]'));
			const nativeArticle = dom.viewport.querySelector('.dy-dashboard__screen--native');
			const ref = userArticles[index] || nativeArticle || null;
			dom.viewport.insertBefore(art, ref);

			// 3. Ghosts (free cells) on the new row.
			const slotsEl = art.querySelector('.dy-dashboard__slots');
			DragDrop._updateSlotsUsed(slotsEl);

			// 4. New dot in the pagination at position `index`.
			const pag = dom.root.querySelector('.dy-dashboard__pagination');
			if (pag) {
				const li = document.createElement('li');
				li.setAttribute('role', 'presentation');
				const dot = document.createElement('button');
				dot.type = 'button';
				dot.className = 'dy-dashboard__nav-dot';
				li.appendChild(dot);
				const refLi = pag.querySelectorAll(':scope > li')[index] || null;
				pag.insertBefore(li, refLi);
				this._bindDot(dot);
			}

			// 5. Resync collections + re-index + eyebrows + IO + worm + "+" buttons.
			this._resyncScreens();

			// 6. Swipe to the new screen (go() animates if target ≠ active screen).
			if (state.activeScreenIndex === index) { this.sync(); }
			else { this.go(index); }
			return true;
		},
		// Shared resync after any structural screen mutation (add,
		// delete, reorder). Rebuilds dom.screens/dom.dots,
		// re-indexes data-screen-index, recalculates "n / N" eyebrows (+ injects
		// the trash affordance #2), (re)observes the IntersectionObserver, and
		// rebuilds worm + inserts + slot interactions.
		_resyncScreens() {
			dom.screens = Array.from(dom.root.querySelectorAll('.dy-dashboard__screen'));
			dom.dots    = Array.from(dom.root.querySelectorAll('.dy-dashboard__nav-dot'));
			const total = dom.screens.length;
			dom.screens.forEach((s, k) => {
				if (s.dataset.screenKind === 'user') s.dataset.screenIndex = String(k);
				let eb = s.querySelector('.dy-dashboard__eyebrow-count');
				if (!eb) {
					const header = s.querySelector('.dy-dashboard__screen-header');
					if (header) {
						const wrap = document.createElement('div');
						wrap.className = 'dy-dashboard__eyebrow';
						wrap.innerHTML = '<span class="dy-dashboard__eyebrow-count"></span>';
						header.insertBefore(wrap, header.firstChild);
						eb = wrap.querySelector('.dy-dashboard__eyebrow-count');
					}
				}
				if (eb) eb.textContent = (k + 1) + ' / ' + total;
				// #2 — trash affordance in the eyebrow of user screens.
				this._ensureEyebrowDelete(s);
				// Ghosts (free cells) — re-injected on server-side rendered slots
				// (which do not include them). Idempotent.
				if (s.dataset.screenKind === 'user') {
					const slotsEl = s.querySelector('.dy-dashboard__slots');
					if (slotsEl) DragDrop._updateSlotsUsed(slotsEl);
				}
				if (this._io) this._io.observe(s);
			});

			Worm.reposition();
			this._buildInserts();
			DragDrop.refreshSlotInteractions(); // handles/delete based on edit mode
		},
		// #2 — Ensures the trash button is present in the eyebrow of a user screen
		// (never on the native screen). Visible only in edit mode
		// (CSS, descendant selector `.dy-dashboard--editable`).
		_ensureEyebrowDelete(screenEl) {
			if (!screenEl || screenEl.dataset.screenKind !== 'user') return;
			const eyebrow = screenEl.querySelector('.dy-dashboard__eyebrow');
			if (!eyebrow || eyebrow.querySelector('.dy-dashboard__eyebrow-del')) return;
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'dy-dashboard__eyebrow-del';
			btn.setAttribute('aria-label', t('delete_screen'));
			btn.setAttribute('title', t('delete_screen'));
			btn.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
			btn.addEventListener('click', (e) => {
				e.stopPropagation();
				const idx = parseInt(screenEl.dataset.screenIndex, 10);
				this.requestDeleteScreen(Number.isNaN(idx) ? state.activeScreenIndex : idx);
			});
			eyebrow.appendChild(btn);
		},
		// #2 — Request to delete a user screen (with confirmation and
		// a "at least one screen" guard).
		requestDeleteScreen(index) {
			const screens = state.snapshot.layout.screens || [];
			if (screens.length <= 1) { Toast.show(t('min_one_screen'), 'warn'); return; }
			if (!window.confirm(t('confirm_delete_screen'))) return;
			this.removeScreenLive(index);
		},
		// #6 — Live screen deletion (no reload): removes the article + dot,
		// splices the layout, resyncs, navigates to a neighbor, persists +
		// records in history.
		removeScreenLive(index) {
			const screens = state.snapshot.layout.screens || [];
			if (index < 0 || index >= screens.length || screens.length <= 1) return false;
			// Id of the deleted screen → undo reintroduces this screen and slides to it.
			const removedId = screens[index] ? screens[index].id : null;

			// 1. Remove user article from the DOM at this index.
			const userArticles = Array.from(dom.viewport.querySelectorAll('.dy-dashboard__screen[data-screen-kind="user"]'));
			if (userArticles[index]) {
				if (this._io) this._io.unobserve(userArticles[index]);
				userArticles[index].remove();
			}
			// 2. Remove the corresponding dot.
			const pag = dom.root.querySelector('.dy-dashboard__pagination');
			if (pag) {
				const li = pag.querySelectorAll(':scope > li')[index];
				if (li) li.remove();
			}
			// 3. Splice the layout + resync.
			screens.splice(index, 1);
			this._resyncScreens();

			// 4. Navigate to a valid neighbor.
			const target = Util.clamp(index, 0, dom.screens.length - 1);
			state.activeScreenIndex = -1; // force sync (go ignores an identical target)
			this.go(target);
			if (state.activeScreenIndex !== target) { state.activeScreenIndex = target; this.sync(); }

			// 5. Silent persist + history (labeled with the deleted screen).
			Sidebar._persistAndRehydrate('screen-del-live', { historyScreenId: removedId });
			return true;
		},
		// #6 — Live screen reordering according to a permutation (array of
		// old indices in the new order). Reorders articles + dots + layout.
		reorderScreensLive(perm) {
			const screens = state.snapshot.layout.screens || [];
			if (!Array.isArray(perm) || perm.length !== screens.length) return false;

			const userArticles = Array.from(dom.viewport.querySelectorAll('.dy-dashboard__screen[data-screen-kind="user"]'));
			const nativeArticle = dom.viewport.querySelector('.dy-dashboard__screen--native');
			const pag = dom.root.querySelector('.dy-dashboard__pagination');
			const lis = pag ? Array.from(pag.querySelectorAll(':scope > li')) : [];
			// User dots only (the native one, if present, is the last li).
			const userLis = lis.filter(li => !li.querySelector('.dy-dashboard__nav-dot[data-native]'));

			// Re-inserts articles + dots in the desired order (before the native / at the end).
			perm.forEach((oldIdx) => {
				if (userArticles[oldIdx]) dom.viewport.insertBefore(userArticles[oldIdx], nativeArticle || null);
				if (pag && userLis[oldIdx]) {
					const nativeLi = lis.find(li => li.querySelector('.dy-dashboard__nav-dot[data-native]')) || null;
					pag.insertBefore(userLis[oldIdx], nativeLi);
				}
			});

			state.snapshot.layout.screens = perm.map(i => screens[i]);
			this._resyncScreens();
			this.sync();
			Sidebar._persistAndRehydrate('screen-reorder-live');
			return true;
		},
		// #6 — Re-renders the dashboard from server HTML (Undo/Redo) WITHOUT
		// reloading the page. Reconciles screen articles (replaces only
		// those that change → scroll preserved), rebuilds pagination if
		// structure changes, re-wires interactions, then slides to the
		// affected screen (`swipeTo`) or stays in place (`swipeTo` null).
		rerenderFromHtml(html, swipeTo) {
			if (!dom.viewport) return;
			const tmp = document.createElement('div');
			tmp.innerHTML = html;
			const newViewport = tmp.querySelector('.dy-dashboard__viewport');
			const newPag      = tmp.querySelector('.dy-dashboard__pagination');
			if (!newViewport) return;

			// Suppress the IO during the entire re-render + swipe: re-observing
			// screens would cause it to refire at the old position and overwrite
			// the target. We re-enable it once the slide is done.
			this._ioSuppressed = true;

			const prevScrollLeft = dom.viewport.scrollLeft;
			const oldArticles = Array.from(dom.viewport.querySelectorAll(':scope > .dy-dashboard__screen'));
			const newArticles = Array.from(newViewport.querySelectorAll(':scope > .dy-dashboard__screen'));
			const sameStructure = oldArticles.length === newArticles.length
				&& oldArticles.every((a, i) => a.dataset.screenId === newArticles[i].dataset.screenId);

			if (sameStructure) {
				// Replace ONLY the screens whose markup differs → unchanged nodes
				// (and the scroll) remain intact, minimal transition.
				oldArticles.forEach((oldA, i) => {
					const fresh = newArticles[i];
					if (oldA.outerHTML !== fresh.outerHTML) {
						if (this._io) this._io.unobserve(oldA);
						oldA.replaceWith(fresh);
					}
				});
			} else {
				// Structural change (add/delete/reorder): rebuild
				// the viewport and pagination.
				oldArticles.forEach(a => { if (this._io) this._io.unobserve(a); });
				dom.viewport.innerHTML = '';
				newArticles.forEach(a => dom.viewport.appendChild(a));
				const oldPag = dom.root.querySelector('.dy-dashboard__pagination');
				if (oldPag && newPag) oldPag.innerHTML = newPag.innerHTML;
			}

			// Re-grab + re-wire.
			dom.screens = Array.from(dom.root.querySelectorAll('.dy-dashboard__screen'));
			dom.dots    = Array.from(dom.root.querySelectorAll('.dy-dashboard__nav-dot'));
			dom.dots.forEach(dot => this._bindDot(dot)); // idempotent (guard dyBound)
			this._resyncScreens(); // re-index + eyebrow del + IO + worm + inserts + slots
			if (typeof NoteEdit !== 'undefined') NoteEdit.init();        // editable notes
			if (typeof ScreenTextEdit !== 'undefined') ScreenTextEdit.refresh();

			// Preserve the scroll position to chain a REAL swipe.
			const maxIdx = dom.screens.length - 1;
			if (sameStructure) {
				dom.viewport.scrollLeft = prevScrollLeft;
			} else {
				dom.viewport.scrollLeft = Math.min(prevScrollLeft, maxIdx * dom.viewport.clientWidth);
			}

			// Slide to the affected screen, or simply resynchronize the UI.
			if (swipeTo != null && swipeTo >= 0 && swipeTo !== state.activeScreenIndex) {
				this.go(swipeTo);
			} else {
				state.activeScreenIndex = Util.clamp(state.activeScreenIndex, 0, maxIdx);
				this.sync();
			}
			// Re-enable the IO once the slide (≈ smooth scroll) is done.
			setTimeout(() => { this._ioSuppressed = false; }, 700);
		},
		// Title displayed by the tooltip for dot i (user or native screen).
		_screenTitleForDot(i) {
			const screens = (state.snapshot.layout && state.snapshot.layout.screens) || [];
			if (i < screens.length) {
				const resolved = Util.resolveTokens(screens[i].title || '');
				return resolved || Util.sprintf(t('screen_n'), i + 1);
			}
			// Native dot: reads the server-rendered title.
			const nativeTitle = dom.root && dom.root.querySelector('.dy-dashboard__screen--native .dy-dashboard__title');
			return nativeTitle ? nativeTitle.textContent.trim() : t('section_screens');
		},
		showTip(i, dot) {
			if (!this._tip) return;
			this._tip.textContent = this._screenTitleForDot(i);
			this._tip.classList.add('is-visible');
			// Center the tooltip above the dot (coordinates relative to the nav).
			const nav = this._tip.parentElement;
			const navRect = nav.getBoundingClientRect();
			const dotRect = dot.getBoundingClientRect();
			const cx = dotRect.left + dotRect.width / 2 - navRect.left;
			this._tip.style.left = cx + 'px';
		},
		hideTip() {
			if (this._tip) this._tip.classList.remove('is-visible');
		},
		// (Re)positions screen insertion "+" buttons at the centers of gaps between
		// user screen dots (never around the native dot). Visibility managed
		// in CSS (editable + nav hover). See plan §5.
		renderInserts() {
			if (!this._insertLayer) return;
			const screens = (state.snapshot.layout && state.snapshot.layout.screens) || [];
			const userCount = screens.length;
			const dots = dom.dots;
			if (!dots.length) { this._insertLayer.innerHTML = ''; return; }
			const STEP = (Worm.DOT + Worm.GAP); // nominal dot→dot spacing
			const center = (i) => {
				const d = dots[i];
				return d ? (d.offsetLeft + d.offsetWidth / 2) : i * STEP + Worm.DOT / 2;
			};
			const html = [];
			for (let k = 0; k <= userCount; k++) {
				let x;
				if (k === 0)               x = center(0) - STEP / 2;
				else if (k < userCount)    x = (center(k - 1) + center(k)) / 2;
				else                       x = center(userCount - 1) + STEP / 2;
				html.push(
					'<span class="dy-dashboard__nav-insert" role="button" tabindex="0" data-insert-index="' + k + '"' +
						' style="left:' + Math.round(x) + 'px" aria-label="' + Util.escapeHtml(t('insert_screen_here')) + '">' +
						'<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>' +
					'</span>'
				);
			}
			this._insertLayer.innerHTML = html.join('');
		}
	};

	// ============================================================
	// WORM — active accent line that slides between dots (overlay).
	// The active dot reserves its width in-flow (transparent); the worm carries
	// the visual. Phase 1: stretch to cover the gap toward the target.
	// Phase 2: contract onto the target slot → liquid/organic movement.
	// Deterministic target position: inactive dots = 8px, gap = 13px, so
	// the active slot starts at index*(8+13)=index*21 and is 26px wide.
	// ============================================================
	const Worm = {
		el: null,
		_settleT: null,
		DOT: 8, GAP: 13, ACTIVE_W: 26,

		ensure() {
			if (this.el) return this.el;
			const pag = dom.root && dom.root.querySelector('.dy-dashboard__pagination');
			if (!pag) return null;
			this.el = document.createElement('span');
			this.el.className = 'dy-dashboard__nav-worm no-anim';
			pag.appendChild(this.el);
			return this.el;
		},

		EDGE: 6.5, // half dot margin (margin: 0 6.5px) = leading space
		_target(index) {
			const dot = dom.dots[index];
			const top = dot ? (dot.offsetTop + dot.offsetHeight / 2) : 4;
			// WIDTH: always the ACTIVE_W constant. DO NOT read dot.offsetWidth:
			// right after navigation, the active dot is mid-transition
			// in width (8→26) → we would capture an intermediate value and the worm
			// would stay frozen as a small circle (observed bug).
			// LEFT: "settled" formula index*(DOT+GAP)+EDGE (exact for the active
			// dot, since all preceding dots are inactive/8px). We only
			// read the LIVE position (offsetLeft) when an insertion "+" is
			// open (edit mode), where dots are actually pushed —
			// then tracked by Worm.follow().
			const anyInsertShown = this._insertShown();
			const left = (anyInsertShown && dot) ? dot.offsetLeft : (index * (this.DOT + this.GAP) + this.EDGE);
			return { left: left, width: this.ACTIVE_W, top: top };
		},
		_insertShown() {
			return !!(Nav._inserts && Nav._inserts.some(x => x.classList.contains('is-shown')));
		},
		// Continuously snaps the worm to the active dot for `ms` milliseconds
		// (follows dot movement when an insertion "+" opens/closes).
		follow(ms) {
			const end = (typeof performance !== 'undefined' ? performance.now() : Date.now()) + (ms || 240);
			const tick = (now) => {
				const n = now || (typeof performance !== 'undefined' ? performance.now() : Date.now());
				const el = this.ensure(); if (!el) return;
				const t = this._target(state.activeScreenIndex);
				el.classList.add('no-anim');
				el.style.left = t.left + 'px';
				el.style.width = t.width + 'px';
				el.style.top = t.top + 'px';
				if (n < end) requestAnimationFrame(tick);
				else requestAnimationFrame(() => el.classList.remove('no-anim'));
			};
			requestAnimationFrame(tick);
		},

		moveTo(index, animate) {
			const el = this.ensure();
			if (!el) return;
			const t = this._target(index);
			el.style.top = t.top + 'px';

			if (!animate || el.classList.contains('no-anim')) {
				el.style.left = t.left + 'px';
				el.style.width = t.width + 'px';
				void el.offsetWidth;
				requestAnimationFrame(() => el.classList.remove('no-anim'));
				return;
			}

			const curLeft = parseFloat(el.style.left);
			const curWidth = parseFloat(el.style.width);
			if (isNaN(curLeft) || isNaN(curWidth)) {
				el.style.left = t.left + 'px';
				el.style.width = t.width + 'px';
				return;
			}
			const curRight = curLeft + curWidth;
			const tRight = t.left + t.width;

			// Phase 1 — stretch toward the target.
			if (t.left >= curLeft) {
				el.style.width = (tRight - curLeft) + 'px'; // extend right
			} else {
				el.style.left = t.left + 'px';
				el.style.width = (curRight - t.left) + 'px'; // extend left
			}

			// Phase 2 — contract onto the target slot (mid-transition).
			clearTimeout(this._settleT);
			this._settleT = setTimeout(() => {
				el.style.left = t.left + 'px';
				el.style.width = t.width + 'px';
			}, 200);
		},

		reposition() {
			const el = this.ensure();
			if (!el) return;
			el.classList.add('no-anim');
			this.moveTo(state.activeScreenIndex, false);
		}
	};

	// ============================================================
	// AMBIENT — drives the position of the 2 ambient gradient focal points.
	// On screen change, the centers move (fast transition).
	// On the native WP screen (no gradient), the points are sent FAR
	// off-page: they slide out → smooth, organic transition
	// (rather than a simple opacity fade).
	// ============================================================
	const Ambient = {
		layer: null,
		grad: null,

		ensure() {
			if (this.grad) return;
			this.layer = dom.root && dom.root.querySelector('.dy-dashboard__ambient');
			this.grad  = dom.root && dom.root.querySelector('.dy-dashboard__ambient-grad');
		},

		_set(x, y, x2, y2) {
			this.grad.style.setProperty('--dy-amb-x',  x.toFixed(1) + '%');
			this.grad.style.setProperty('--dy-amb-y',  y.toFixed(1) + '%');
			this.grad.style.setProperty('--dy-amb-x2', x2.toFixed(1) + '%');
			this.grad.style.setProperty('--dy-amb-y2', y2.toFixed(1) + '%');
		},

		// Ambient gradient tint based on the active screen. `--dy-amb-accent` lives on
		// the ambient layer (fixed), registered as a <color> via @property →
		// INTERPOLABLE → smooth morph during the slide. Empty → remove
		// the override to fall back to the global tint (= global accent).
		// NB: the REAL background behind the gradients (--dy-amb-bg) is NEVER
		// driven here — it stays fixed based on light/dark mode.
		_setGradientTint(tint) {
			this.ensure();
			const target = (this.layer || (dom.root && dom.root.querySelector('.dy-dashboard__ambient')));
			if (!target) return;
			if (tint) target.style.setProperty('--dy-amb-accent', tint);
			else      target.style.removeProperty('--dy-amb-accent');
		},
		// Reads the colors of screen `index` and applies them:
		//   • accent_color → global UI accent of the dashboard (panel, active
		//     worm/dot, screen) set on dom.root.
		// The gradient tint (bg_color) is NO LONGER set here: it is
		// driven continuously by the scroll (syncTintToScroll) to stay
		// synchronized with the swipe. We just trigger an immediate sync.
		updateColors(index) {
			const screens = (state.snapshot.layout && state.snapshot.layout.screens) || [];
			const screen = screens[index];
			Sidebar.applyRootAccent(screen ? (screen.accent_color || '') : '');
			this.syncTintToScroll();
		},
		// EFFECTIVE tint of a screen (bg_color override, otherwise the frozen global accent).
		_screenTint(index) {
			const screens = (state.snapshot.layout && state.snapshot.layout.screens) || [];
			const s = screens[index];
			const hex = (s && s.bg_color) ? s.bg_color : this._globalTint();
			return Util.parseColor(hex);
		},
		_globalTint() {
			if (this._globalTintCache) return this._globalTintCache;
			const v = (dom.root && getComputedStyle(dom.root).getPropertyValue('--dy-global-accent'))
				|| (getComputedStyle(document.body).getPropertyValue('--dya-accent'));
			this._globalTintCache = (v || '#d4b27a').trim();
			return this._globalTintCache;
		},
		// Drives the gradient tint CONTINUOUSLY from the scroll position:
		// we interpolate between the current screen and its neighbor based on the
		// swipe fraction → color perfectly in sync with the gesture, with no lag.
		syncTintToScroll() {
			this.ensure();
			const screens = (state.snapshot.layout && state.snapshot.layout.screens) || [];
			if (!dom.viewport || !screens.length) return;
			const vertical = Nav._isVertical && Nav._isVertical();
			const size = vertical ? dom.viewport.clientHeight : dom.viewport.clientWidth;
			if (size <= 0) return;
			const pos = (vertical ? dom.viewport.scrollTop : dom.viewport.scrollLeft) / size;
			const last = screens.length - 1;
			// Beyond the last user screen (native screen): keep the
			// tint of the last user screen (the native one has no gradient).
			const base = Math.max(0, Math.min(last, Math.floor(pos)));
			const next = Math.min(last, base + 1);
			let frac = Math.max(0, Math.min(1, pos - base));
			// The tint LEADS the gesture toward the DESTINATION screen (in both
			// directions): we bias `frac` based on scroll direction. Forward (pos grows) →
			// push toward `next`; backward → push toward `base`. Result: the
			// color arrives early, perceived as "glued" to the swipe.
			const dir = (this._lastTintPos == null) ? 1 : Math.sign(pos - this._lastTintPos);
			this._lastTintPos = pos;
			const EASE = 0.5; // <1 = leads; smaller = earlier
			if (dir < 0) frac = 1 - Math.pow(1 - frac, EASE); // toward base
			else         frac = Math.pow(frac, EASE);          // toward next
			const from = this._screenTint(base);
			const to   = this._screenTint(next);
			if (!from || !to) return;
			this._setGradientTint('rgb(' + Util.lerpRgb(from, to, frac) + ')');
			return pos;
		},

		// rAF loop that samples the scroll position at EVERY frame while
		// it is moving. Essential: with scroll-snap, the browser batches/
		// throttles `scroll` events during a trackpad/touch swipe → the
		// tint only changed at the end. Here we read scrollLeft at 60fps → the
		// color follows the gesture in real time. The loop self-terminates when the
		// position stabilizes (≈8 frames without change).
		startTintLoop() {
			if (this._tintLoopOn) { this._tintIdle = 0; return; }
			this._tintLoopOn = true;
			this._tintIdle = 0;
			let prev = null;
			const tick = () => {
				const pos = this.syncTintToScroll();
				if (pos == null) { this._tintLoopOn = false; return; }
				if (prev != null && Math.abs(pos - prev) < 0.0008) {
					if (++this._tintIdle > 8) { this._tintLoopOn = false; return; }
				} else {
					this._tintIdle = 0;
				}
				prev = pos;
				requestAnimationFrame(tick);
			};
			requestAnimationFrame(tick);
		},

		update(index) {
			this.ensure();
			if (!this.grad) return;

			const isNative = index >= state.snapshot.layout.screens.length;
			if (this.layer) this.layer.classList.toggle('is-muted', isNative);
			// Screen color (accent + gradient background), smooth morph.
			this.updateColors(index);

			if (isNative) {
				// Points sent far off-frame (top-left / bottom-right)
				// → they escape smoothly, the native screen remains neutral.
				this._set(-60, -50, 160, 150);
				return;
			}

			// Distributes the 2 centers based on the screen (staying within the
			// content area). The main point sweeps left→right, the
			// secondary glow mirrors it, for a depth effect.
			const total = Math.max(state.snapshot.layout.screens.length - 1, 1);
			const t = index / total;
			const x  = 32 + t * 30;            // 32% → 62%
			const y  = 26 + (index % 2) * 10;  // alterne 26% / 36%
			const x2 = 78 - t * 26;            // 78% → 52% (miroir)
			const y2 = 60 + (index % 2) * 8;   // alterne 60% / 68%
			this._set(x, y, x2, y2);
		}
	};

	// ============================================================
	// NATIVE SCROLLBAR — custom design-system scrollbar always visible
	// on the native WP screen (macOS overlay scrollbars cannot be
	// styled reliably). Track + thumb driven by JS, draggable.
	// ============================================================
	const NativeScrollbar = {
		init() {
			const list = dom.root && dom.root.querySelector('.dy-dashboard__screen--native .dy-dashboard__native-list');
			if (!list || list.__dyScrollbar) return;
			list.__dyScrollbar = true;

			// Wrap the list to anchor the custom scrollbar to its exact height.
			const wrap = document.createElement('div');
			wrap.className = 'dy-dashboard__native-scroll-wrap';
			list.parentNode.insertBefore(wrap, list);
			wrap.appendChild(list);

			const bar = document.createElement('div');
			bar.className = 'dy-dashboard__native-scrollbar';
			const thumb = document.createElement('div');
			thumb.className = 'dy-dashboard__native-thumb';
			bar.appendChild(thumb);
			wrap.appendChild(bar);

			this.list = list;
			this.bar = bar;
			this.thumb = thumb;

			list.addEventListener('scroll', () => this.update(), { passive: true });
			if ('ResizeObserver' in window) {
				new ResizeObserver(() => this.update()).observe(list);
				new ResizeObserver(() => this.update()).observe(bar);
			}
			this._bindDrag();
			// First measurement (after the layout is settled).
			requestAnimationFrame(() => this.update());
		},

		update() {
			if (!this.list) return;
			const { scrollTop, scrollHeight, clientHeight } = this.list;
			if (scrollHeight <= clientHeight + 1) {
				this.bar.classList.remove('is-visible');
				return;
			}
			this.bar.classList.add('is-visible');
			const trackH = this.bar.clientHeight;
			const thumbH = Math.max(32, Math.round(trackH * clientHeight / scrollHeight));
			const maxThumbTop = trackH - thumbH;
			const ratio = scrollTop / (scrollHeight - clientHeight);
			const thumbTop = Math.round(maxThumbTop * ratio);
			this.thumb.style.height = thumbH + 'px';
			this.thumb.style.transform = 'translateY(' + thumbTop + 'px)';
		},

		_bindDrag() {
			let dragging = false;
			let startY = 0;
			let startScroll = 0;

			const onMove = (e) => {
				if (!dragging) return;
				const y = (e.touches ? e.touches[0].clientY : e.clientY);
				const dy = y - startY;
				const { scrollHeight, clientHeight } = this.list;
				const trackH = this.bar.clientHeight;
				const thumbH = this.thumb.offsetHeight;
				const maxThumbTop = trackH - thumbH;
				if (maxThumbTop <= 0) return;
				const scrollRange = scrollHeight - clientHeight;
				this.list.scrollTop = startScroll + (dy / maxThumbTop) * scrollRange;
			};
			const onUp = () => {
				dragging = false;
				this.thumb.classList.remove('is-grabbing');
				document.removeEventListener('mousemove', onMove);
				document.removeEventListener('mouseup', onUp);
				document.removeEventListener('touchmove', onMove);
				document.removeEventListener('touchend', onUp);
			};
			const onDown = (e) => {
				dragging = true;
				startY = (e.touches ? e.touches[0].clientY : e.clientY);
				startScroll = this.list.scrollTop;
				this.thumb.classList.add('is-grabbing');
				document.addEventListener('mousemove', onMove);
				document.addEventListener('mouseup', onUp);
				document.addEventListener('touchmove', onMove, { passive: false });
				document.addEventListener('touchend', onUp);
				e.preventDefault();
			};
			this.thumb.addEventListener('mousedown', onDown);
			this.thumb.addEventListener('touchstart', onDown, { passive: false });

			// Click on the track = page jump.
			this.bar.addEventListener('mousedown', (e) => {
				if (e.target === this.thumb) return;
				const rect = this.bar.getBoundingClientRect();
				const clickRatio = (e.clientY - rect.top) / rect.height;
				const { scrollHeight, clientHeight } = this.list;
				this.list.scrollTop = clickRatio * (scrollHeight - clientHeight);
			});
		}
	};

	// ============================================================
	// NATIVE MASONRY — distributes native WP screen cards into N balanced
	// columns (greedy: each card goes into the shortest column).
	// CSS multi-column overflows horizontally in a fixed-height container;
	// we therefore manage the distribution in JS for a true masonry that
	// scrolls vertically. 2 columns ≥1200px, 1 column below.
	// ============================================================
	const NativeMasonry = {
		init() {
			this.list = dom.root && dom.root.querySelector('.dy-dashboard__screen--native .dy-dashboard__native-list');
			if (!this.list) return;
			// Source order of cards (fixed: used as the basis for each redistribution).
			this.items = Array.from(this.list.querySelectorAll('.dy-dashboard__native-item'));
			if (!this.items.length) return;

			this._current = -1;
			this._relayout(true);
			// Rebalance once images/illustrations are loaded (their
			// heights distort the initial measurement).
			window.addEventListener('load', () => this._relayout(true));
			setTimeout(() => this._relayout(true), 800);
			window.addEventListener('resize', Util.debounce(() => this._relayout(false), 200));
		},

		_colCount() {
			return window.innerWidth >= 1200 ? 2 : 1;
		},

		_relayout(force) {
			const n = this._colCount();
			if (!force && n === this._current) return;
			this._current = n;

			// Always restart from source order for a deterministic distribution.
			this.list.querySelectorAll(':scope > .dy-dashboard__native-col').forEach(c => c.remove());
			const cols = [];
			for (let i = 0; i < n; i++) {
				const c = document.createElement('div');
				c.className = 'dy-dashboard__native-col';
				this.list.appendChild(c);
				cols.push(c);
			}

			if (n === 1) {
				this.items.forEach(it => cols[0].appendChild(it));
			} else {
				// Greedy: card → shortest column at time T.
				this.items.forEach(it => {
					let min = 0;
					for (let i = 1; i < n; i++) {
						if (cols[i].offsetHeight < cols[min].offsetHeight) min = i;
					}
					cols[min].appendChild(it);
				});
			}

			if (typeof NativeScrollbar !== 'undefined' && NativeScrollbar.list) {
				requestAnimationFrame(() => NativeScrollbar.update());
			}
		}
	};

	// ============================================================
	// SIDEBAR — Phase 6 + 7
	// ============================================================
	const Sidebar = {
		inject() {
			const aside = document.createElement('aside');
			aside.id = 'dy-dashboard-sidebar';
			aside.className = 'dy-dashboard__sidebar';
			aside.setAttribute('aria-hidden', 'true');
			// Closed = out of reach for the keyboard too, not only hidden from
			// screen readers (accessibility audit, 21/10/2026).
			aside.inert = true;
			aside.innerHTML = this._template();
			dom.root.appendChild(aside);
			dom.sidebar = aside;

			// Wire up close + sections.
			aside.querySelector('[data-action="close-sidebar"]').addEventListener('click', () => this.close());
			aside.querySelector('[data-action="config-back"]').addEventListener('click', () => this.closeWidgetConfig());
			// #6 — Undo / Redo buttons.
			aside.querySelector('[data-action="undo"]').addEventListener('click', () => History.undo());
			aside.querySelector('[data-action="redo"]').addEventListener('click', () => History.redo());
			aside.querySelectorAll('[data-section-toggle]').forEach(btn => {
				btn.addEventListener('click', (e) => {
					// The "Import" icon lives in the section header: its click
					// triggers the import and does NOT collapse the section.
					const imp = e.target.closest('[data-action="import-disposition-trigger"]');
					if (imp) { e.preventDefault(); e.stopPropagation(); this._triggerDispositionImport(); return; }
					this._toggleSection(btn);
				});
			});

			this._wireScreenMetaSection();
			this._wirePresetsSection();
			this._wireScreensList();
			this._wireExportImport();
			this._wireLockToggle();
			this._wireRoleSelect();
			this._wireNativeToggle();
			this.refreshCatalog();
		},
		_template() {
			const chevron = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';
			const canManage = !!(state.snapshot.caps && state.snapshot.caps.can_manage);
			const esc = Util.escapeHtml;

			return [
				'<header class="dy-dashboard__sidebar-header">',
					// Back button (visible only in widget config mode).
					'<button type="button" class="dy-dashboard__sidebar-back" data-action="config-back" hidden>',
						'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>',
						'<span data-config-back-label></span>',
					'</button>',
					'<span class="dy-dashboard__sidebar-eyebrow">', esc(t('customize')), '</span>',
					// #6 — Undo / Redo (history of current edits).
					'<div class="dy-dashboard__history" role="group" aria-label="', esc(t('history')), '">',
						'<button type="button" class="dy-dashboard__history-btn" data-action="undo" title="', esc(t('undo')), '" aria-label="', esc(t('undo')), '" disabled>',
							'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/></svg>',
						'</button>',
						'<button type="button" class="dy-dashboard__history-btn" data-action="redo" title="', esc(t('redo')), '" aria-label="', esc(t('redo')), '" disabled>',
							'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 14l5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h1"/></svg>',
						'</button>',
					'</div>',
					'<button type="button" class="dy-dashboard__sidebar-close" data-action="close-sidebar" aria-label="', esc(t('close_panel')), '">',
						'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
					'</button>',
				'</header>',
				'<div class="dy-dashboard__sidebar-scroll">',

					// ── Layout preset (at top): card grid + "new" form ──
					'<section class="dy-dashboard__sidebar-section" data-section="presets">',
						'<button type="button" class="dy-dashboard__section-toggle" data-section-toggle="presets" aria-expanded="true">',
							'<span>', esc(t('section_layout')), '</span>',
							// "Import a layout preset" icon (admin) — just to the left
							// of the chevron. role=button inside a <button> = valid; click
							// stops propagation so the section does not collapse.
							canManage ? '<span class="dy-dashboard__section-action" role="button" tabindex="0" data-action="import-disposition-trigger" title="' + esc(t('menu_import_disposition')) + '" aria-label="' + esc(t('menu_import_disposition')) + '"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><polyline points="7 10 12 15 17 10"/><path d="M5 21h14"/></svg></span>' : '',
							chevron,
						'</button>',
						'<div class="dy-dashboard__section-body">',
							'<div class="dy-dashboard__presets" data-presets-root></div>',
							// Inline "Save as new layout preset" form
							// (triggered by the 3-dot menu on the Custom card).
							canManage ? [
								'<div class="dy-dashboard__save-form" data-save-form hidden>',
									'<input type="text" data-save-name class="dy-dashboard__input" placeholder="', esc(t('disposition_name_placeholder')), '">',
									'<div class="dy-dashboard__btn-row">',
										'<button type="button" class="dy-dashboard__btn dy-dashboard__btn--primary" data-action="save-disposition-confirm">', esc(t('save')), '</button>',
										'<button type="button" class="dy-dashboard__btn dy-dashboard__btn--ghost" data-action="save-disposition-cancel">', esc(t('cancel')), '</button>',
									'</div>',
								'</div>',
								'<input type="file" hidden data-disposition-import accept="application/json">'
							].join('') : '',
						'</div>',
					'</section>',

					// ── Current screen ───────────────────────────────────
					'<section class="dy-dashboard__sidebar-section" data-section="screen-meta">',
						'<button type="button" class="dy-dashboard__section-toggle" data-section-toggle="screen-meta" aria-expanded="true">',
							'<span>', esc(t('screen_current')), '</span>',
							chevron,
						'</button>',
						'<div class="dy-dashboard__section-body">',
							'<label class="dy-dashboard__field">',
								'<span class="dy-dashboard__field-label">', esc(t('field_title')), '</span>',
								'<input type="text" data-field="title" class="dy-dashboard__input">',
							'</label>',
							'<label class="dy-dashboard__field">',
								'<span class="dy-dashboard__field-label dy-dashboard__field-label--with-toggle">',
									'<span>', esc(t('field_paragraph')), '</span>',
									'<input type="checkbox" data-field="paragraph_enabled" class="dy-dashboard__field-toggle">',
								'</span>',
								// The textarea only appears when paragraph is enabled
								// (toggled and resynchronized in _hydrateFromState).
								'<textarea data-field="paragraph" data-paragraph-field class="dy-dashboard__textarea" rows="3" hidden></textarea>',
							'</label>',
							// Per-screen colors (UI accent + gradient tint), on a
							// single line. Empty = inherits the global color; reset
							// per field (return to global).
							'<div class="dy-dashboard__field dy-dashboard__color-row">',
								'<span class="dy-dashboard__color-cell">',
									'<span class="dy-dashboard__field-label">', esc(t('screen_accent')), '</span>',
									this._colorControlHtml('accent_color', t('screen_accent')),
								'</span>',
								'<span class="dy-dashboard__color-cell">',
									'<span class="dy-dashboard__field-label">', esc(t('screen_bg')), '</span>',
									this._colorControlHtml('bg_color', t('screen_bg')),
								'</span>',
							'</div>',
						'</div>',
					'</section>',

					// ── Selected widget (contextual config) ────────
					'<section class="dy-dashboard__sidebar-section" data-section="widget-config" hidden>',
						'<button type="button" class="dy-dashboard__section-toggle" data-section-toggle="widget-config" aria-expanded="true">',
							'<span data-widget-config-title>', esc(t('section_widget')), '</span>',
							chevron,
						'</button>',
						'<div class="dy-dashboard__section-body">',
							'<div data-widget-config-root></div>',
						'</div>',
					'</section>',

					// ── Widgets ──────────────────────────────────────────
					'<section class="dy-dashboard__sidebar-section" data-section="widgets">',
						'<button type="button" class="dy-dashboard__section-toggle" data-section-toggle="widgets" aria-expanded="true">',
							'<span>', esc(t('section_widgets')), '</span>',
							chevron,
						'</button>',
						'<div class="dy-dashboard__section-body">',
							'<div class="dy-dashboard__catalog" data-catalog-root></div>',
							'<p class="dy-dashboard__hint">', esc(t('drag_hint')), '</p>',
						'</div>',
					'</section>',

					// ── Screens ──────────────────────────────────────────
					'<section class="dy-dashboard__sidebar-section" data-section="screens">',
						'<button type="button" class="dy-dashboard__section-toggle" data-section-toggle="screens" aria-expanded="false">',
							'<span>', esc(t('section_screens')), '</span>',
							chevron,
						'</button>',
						'<div class="dy-dashboard__section-body" hidden>',
							'<ul class="dy-dashboard__screens-list" data-screens-root></ul>',
							'<button type="button" class="dy-dashboard__btn dy-dashboard__btn--ghost" data-action="add-screen">', esc(t('add_screen')), '</button>',
							'<label class="dy-dashboard__field dy-dashboard__field--inline" data-native-toggle-row>',
								'<input type="checkbox" data-field="wp_native_enabled" class="dy-dashboard__field-toggle">',
								'<span>', esc(t('show_wp_screen')), '</span>',
							'</label>',
						'</div>',
					'</section>',

					// ── Advanced (at the very bottom, collapsed) ────────
					'<section class="dy-dashboard__sidebar-section" data-section="advanced">',
						'<button type="button" class="dy-dashboard__section-toggle" data-section-toggle="advanced" aria-expanded="false">',
							'<span>', esc(t('section_advanced')), '</span>',
							chevron,
						'</button>',
						'<div class="dy-dashboard__section-body" hidden>',
							// "Assign role(s)" and "Lock" have moved into
							// the 3-dot menu of layout presets. Advanced only keeps
							// export / import of the current layout.
							'<div class="dy-dashboard__btn-row">',
								'<button type="button" class="dy-dashboard__btn dy-dashboard__btn--ghost" data-action="export-layout">', esc(t('export_json')), '</button>',
								'<button type="button" class="dy-dashboard__btn dy-dashboard__btn--ghost" data-action="import-layout">', esc(t('import')), '</button>',
							'</div>',
							'<input type="file" hidden data-import-file accept="application/json">',
						'</div>',
					'</section>',

				'</div>',
				'<footer class="dy-dashboard__sidebar-footer">',
					'<span class="dy-dashboard__sidebar-saved" data-save-indicator>', esc(t('autosave')), '</span>',
					'<button type="button" class="dy-dashboard__btn dy-dashboard__btn--ghost" data-action="reset-layout">', esc(t('reset')), '</button>',
				'</footer>'
			].join('');
		},
		_toggleSection(btn) {
			const expanded = btn.getAttribute('aria-expanded') === 'true';
			btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
			const body = btn.nextElementSibling;
			if (body) {
				if (expanded) body.setAttribute('hidden', '');
				else body.removeAttribute('hidden');
			}
		},
		_currentScreen() {
			return state.snapshot.layout.screens[state.activeScreenIndex] || null;
		},
		_currentScreenIsNative() {
			// Active index beyond the number of user screens = native screen.
			return state.activeScreenIndex >= state.snapshot.layout.screens.length;
		},
		// Color control "swatch + hex field + reset" for a screen field.
		// `key` ∈ {accent_color, bg_color}. Empty = inherits from global.
		_colorControlHtml(key, label) {
			const esc = Util.escapeHtml;
			return [
				'<span class="dy-dashboard__color-control" data-color-control="', esc(key), '">',
					'<label class="dy-dashboard__color-swatch" aria-label="', esc(label), '">',
						'<input type="color" data-color-picker="', esc(key), '" value="#000000">',
						'<span class="dy-dashboard__color-swatch-chip" data-color-chip aria-hidden="true"></span>',
					'</label>',
					'<input type="text" class="dy-dashboard__color-hex" data-color-hex="', esc(key), '" ',
						'placeholder="', esc(t('inherits_global')), '" spellcheck="false" autocomplete="off" maxlength="7">',
					'<button type="button" class="dy-dashboard__color-reset" data-color-reset="', esc(key), '" ',
						'title="', esc(t('reset_to_global')), '" aria-label="', esc(t('reset_to_global')), '" hidden>',
						'<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
					'</button>',
				'</span>'
			].join('');
		},
		_wireScreenMetaSection() {
			const root = dom.sidebar.querySelector('[data-section="screen-meta"] .dy-dashboard__section-body');
			const titleInput   = root.querySelector('[data-field="title"]');
			const paraInput    = root.querySelector('[data-field="paragraph"]');
			const paraToggle   = root.querySelector('[data-field="paragraph_enabled"]');
			this._wireColorControls(root);

			const applyToScreen = Util.debounce(() => {
				if (this._currentScreenIsNative()) return;
				const screen = this._currentScreen();
				if (!screen) return;
				screen.title             = titleInput.value;
				screen.paragraph         = paraInput.value;
				screen.paragraph_enabled = paraToggle.checked;
				// 1) Live DOM update (title/paragraph visible in the current screen)
				Sidebar._updateActiveScreenHeaderDom();
				// 2) Async persist (no reload)
				this._persistAndRehydrate('screen-meta');
			}, 220);

			[titleInput, paraInput].forEach(el => el.addEventListener('input', applyToScreen));
			paraToggle.addEventListener('change', applyToScreen);
			// Shows/hides the paragraph field (+ its "dynamic content" button)
			// AND updates the current screen in real time, without waiting for the debounce.
			paraToggle.addEventListener('change', () => {
				this._setParaFieldVisible(paraToggle.checked);
				if (this._currentScreenIsNative()) return;
				const screen = this._currentScreen();
				if (!screen) return;
				screen.paragraph_enabled = paraToggle.checked;
				// Enables/removes inline paragraph editing based on state, then
				// resynchronizes the screen DOM (immediate show/hide).
				if (typeof ScreenTextEdit !== 'undefined') ScreenTextEdit.refresh();
				this._updateActiveScreenHeaderDom();
			});

			// Dynamic content picker: injects a button + dropdown next to
			// fields that support tokens (title, paragraph).
			TokenPicker.attach(titleInput);
			TokenPicker.attach(paraInput);
		},
		// Wires the two color controls (accent_color, bg_color). An empty value
		// means "inherit from global": nothing is written to the screen.
		_wireColorControls(root) {
			const HEX_RE = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;
			['accent_color', 'bg_color'].forEach((key) => {
				const ctrl = root.querySelector('[data-color-control="' + key + '"]');
				if (!ctrl) return;
				const picker = ctrl.querySelector('[data-color-picker]');
				const hex    = ctrl.querySelector('[data-color-hex]');
				const reset  = ctrl.querySelector('[data-color-reset]');

				const commit = (value, opts) => {
					if (this._currentScreenIsNative()) return;
					const screen = this._currentScreen();
					if (!screen) return;
					screen[key] = value; // '' = inherit from global
					this._reflectColorControl(key, value);
					this._applyScreenColors();           // live update screen + ambient
					this._persistAndRehydrate('screen-meta', (opts && opts.silent) ? { silent: true } : undefined);
				};

				// Native color swatch → immediate value.
				picker.addEventListener('input', () => commit(picker.value.toLowerCase()));
				// Hex field: applies only if valid (or empty = reset).
				const hexApply = Util.debounce(() => {
					const v = hex.value.trim().toLowerCase();
					if (v === '') { commit(''); return; }
					if (HEX_RE.test(v)) commit(v.length === 4
						? '#' + v[1]+v[1]+v[2]+v[2]+v[3]+v[3] // #abc → #aabbcc
						: v);
				}, 260);
				hex.addEventListener('input', hexApply);
				hex.addEventListener('blur', () => { // clear an invalid hex on blur
					const screen = this._currentScreen();
					this._reflectColorControl(key, screen ? (screen[key] || '') : '');
				});
				// Reset → inherit from global.
				reset.addEventListener('click', () => commit(''));
			});
		},
		// Syncs a color field control to a given value.
		_reflectColorControl(key, value) {
			const root = dom.sidebar && dom.sidebar.querySelector('[data-section="screen-meta"]');
			if (!root) return;
			const ctrl = root.querySelector('[data-color-control="' + key + '"]');
			if (!ctrl) return;
			const picker = ctrl.querySelector('[data-color-picker]');
			const hex    = ctrl.querySelector('[data-color-hex]');
			const chip   = ctrl.querySelector('[data-color-chip]');
			const reset  = ctrl.querySelector('[data-color-reset]');
			// Effective color shown in the swatch: the screen value,
			// otherwise the resolved global color (to show what is being inherited).
			const effective = value || this._globalColorFor(key);
			if (picker && effective) picker.value = effective;
			if (chip) {
				chip.style.background = effective || 'transparent';
				ctrl.classList.toggle('is-inherited', !value);
			}
			if (hex) hex.value = value || '';
			if (reset) reset.hidden = !value;
		},
		// Resolved GLOBAL color for a color field (placeholder/swatch
		// when the screen inherits). We read from an element WITHOUT an override
		// to avoid falling back to the current screen override:
		//   • accent_color → the global accent (dashboard --dya-accent, without override)
		//   • bg_color (= gradient tint) → also the global accent, since
		//     the default gradient tint IS the global accent.
		_globalColorFor(key) {
			// The global accent lives on <body> (DS token / custom client accent);
			// dom.root carries the screen override → we read from document.body
			// to get the true global value, never the screen-level one.
			void key;
			const v = getComputedStyle(document.body).getPropertyValue('--dya-accent');
			return (v || '').trim();
		},
		// GLOBAL UI accent of the dashboard for the active screen: set on dom.root so
		// that EVERYTHING inherits it — the Customize panel, active nav worm/dot, and
		// the screen (title em, eyebrow, slots, swatches). Empty = remove the override
		// → fall back to the global accent (DS default or custom client).
		applyRootAccent(accent) {
			if (!dom.root) return;
			if (accent) {
				const rgb = Util.hexToRgb(accent);
				dom.root.style.setProperty('--dya-accent', accent);
				if (rgb) dom.root.style.setProperty('--dya-accent-rgb', rgb);
				dom.root.style.setProperty('--dya-accent-on', this._readableOn(accent));
			} else {
				dom.root.style.removeProperty('--dya-accent');
				dom.root.style.removeProperty('--dya-accent-rgb');
				dom.root.style.removeProperty('--dya-accent-on');
			}
		},
		// Readable text color (light/dark) on an accent color — WCAG luminance.
		_readableOn(hex) {
			let h = String(hex || '').replace(/^#/, '');
			if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
			if (!/^[0-9a-f]{6}$/i.test(h)) return '#fff';
			const lin = (c) => { c /= 255; return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); };
			const L = 0.2126*lin(parseInt(h.slice(0,2),16)) + 0.7152*lin(parseInt(h.slice(2,4),16)) + 0.0722*lin(parseInt(h.slice(4,6),16));
			return L > 0.45 ? '#10131a' : '#ffffff';
		},
		// Applies the current screen's colors WITHOUT reload:
		//   • inline accent on the article (each screen keeps its color during
		//     the swipe: title em, eyebrow, slots, swatches of THIS screen);
		//   • global UI accent on dom.root (Customize panel + active nav
		//     worm/dot, which live outside the article);
		//   • ambient gradient tint. The real background stays fixed (light/dark).
		_applyScreenColors() {
			const idx = state.activeScreenIndex;
			const screen = this._currentScreen();
			const screenEl = dom.root.querySelector('.dy-dashboard__screen[data-screen-index="' + idx + '"]');
			if (screen && screenEl && screenEl.dataset.screenKind !== 'native') {
				const accent = screen.accent_color || '';
				if (accent) {
					const rgb = Util.hexToRgb(accent);
					screenEl.style.setProperty('--dya-accent', accent);
					if (rgb) screenEl.style.setProperty('--dya-accent-rgb', rgb);
					screenEl.style.setProperty('--dya-accent-on', this._readableOn(accent));
					screenEl.dataset.screenAccent = accent;
				} else {
					screenEl.style.removeProperty('--dya-accent');
					screenEl.style.removeProperty('--dya-accent-rgb');
					screenEl.style.removeProperty('--dya-accent-on');
					delete screenEl.dataset.screenAccent;
				}
			}
			// Root accent (panel + worm) + gradient tint (ambient).
			Ambient.updateColors(idx);
		},
		_wirePresetsSection() {
			this._presetsRoot = dom.sidebar.querySelector('[data-presets-root]');
			this._renderDispositions();

			// Keyboard: Enter/Space activates the tile (apply) or the menu button.
			// Escape closes any open menu.
			this._presetsRoot.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') { this._closeAllPresetMenus(); return; }
				if (e.key !== 'Enter' && e.key !== ' ') return;
				const menuBtn = e.target.closest('[data-preset-menu-btn]');
				if (menuBtn) { e.preventDefault(); this._togglePresetMenu(menuBtn); return; }
				const item = e.target.closest('[data-menu-action]');
				if (item) { e.preventDefault(); item.click(); return; }
				const tile = e.target.closest('[data-disposition-slug]');
				if (tile && tile.getAttribute('role') === 'button') {
					e.preventDefault();
					const slug = tile.dataset.dispositionSlug;
					if (slug !== 'custom') this._applyDisposition(slug);
				}
			});

			// Click delegation: menu button → toggle; menu item → action;
			// tile → apply (except the "Custom" card).
			this._presetsRoot.addEventListener('click', (e) => {
				const menuBtn = e.target.closest('[data-preset-menu-btn]');
				if (menuBtn) { e.stopPropagation(); this._togglePresetMenu(menuBtn); return; }

				const item = e.target.closest('[data-menu-action]');
				if (item) {
					e.stopPropagation();
					const tile = item.closest('[data-disposition-slug]');
					const slug = tile ? tile.dataset.dispositionSlug : 'custom';
					this._closeAllPresetMenus();
					this._onDispositionMenuAction(item.dataset.menuAction, slug);
					return;
				}

				const tile = e.target.closest('[data-disposition-slug]');
				if (!tile) return;
				const slug = tile.dataset.dispositionSlug;
				if (slug === 'custom') return; // virtual card: not applicable on click
				this._applyDisposition(slug);
			});

			// Close menus on outside click + global Escape.
			if (!this._presetMenuOutside) {
				this._presetMenuOutside = (e) => {
					if (e.target.closest && e.target.closest('[data-preset-menu-btn]')) return;
					if (this._floatMenu && this._floatMenu.contains(e.target)) return;
					this._closeAllPresetMenus();
				};
				document.addEventListener('click', this._presetMenuOutside);
				document.addEventListener('keydown', (e) => { if (e.key === 'Escape') this._closeAllPresetMenus(); });
				// The menu is position:fixed (anchored to the button) → close it if
				// the sidebar scrolls, to prevent it from "floating" detached.
				const scroll = dom.sidebar.querySelector('.dy-dashboard__sidebar-scroll');
				if (scroll) scroll.addEventListener('scroll', () => this._closeAllPresetMenus(), { passive: true });
			}

			// "Save as new layout preset" form + file import.
			this._wireSaveDisposition();
			this._wireDispositionImport();
		},
		_togglePresetMenu(btn) {
			const menu = this._ensureFloatMenu();
			const tile = btn.closest('[data-disposition-slug]');
			const slug = tile ? tile.dataset.dispositionSlug : 'custom';
			const alreadyOpen = !menu.hasAttribute('hidden') && this._floatMenuBtn === btn;
			this._closeAllPresetMenus();
			if (alreadyOpen) return;
			this._floatMenuSlug = slug;
			this._floatMenuBtn  = btn;
			menu.innerHTML = this._dispositionMenuItems(btn.dataset.menuKind || (slug === 'custom' ? 'custom' : 'user'), slug);
			menu.removeAttribute('hidden');
			btn.setAttribute('aria-expanded', 'true');
			this._positionPresetMenu(btn, menu);
		},
		// Positions the floating menu (position:fixed, viewport coordinates) anchored
		// to the button: right-aligned, opens UPWARD by default.
		_positionPresetMenu(btn, menu) {
			const r = btn.getBoundingClientRect();
			const mh = menu.offsetHeight || 180;
			const mw = menu.offsetWidth || 168;
			const gap = 6;
			let top = r.top - mh - gap;
			if (top < 8) top = r.bottom + gap;
			let left = r.right - mw;
			if (left < 8) left = 8;
			if (left + mw > window.innerWidth - 8) left = window.innerWidth - 8 - mw;
			menu.style.top = Math.round(top) + 'px';
			menu.style.left = Math.round(left) + 'px';
		},
		_closeAllPresetMenus() {
			if (this._floatMenu) this._floatMenu.setAttribute('hidden', '');
			this._floatMenuBtn = null;
			if (this._presetsRoot) {
				this._presetsRoot.querySelectorAll('[data-preset-menu-btn]').forEach(b => b.setAttribute('aria-expanded', 'false'));
			}
		},
		// Router for 3-dot menu actions.
		_onDispositionMenuAction(action, slug) {
			switch (action) {
				case 'apply':    this._applyDisposition(slug); break;
				case 'update':   this._updateDisposition(slug); break;
				case 'export':   this._exportDisposition(slug); break;
				case 'delete':   this._deleteDisposition(slug); break;
				case 'clear':    this._clearLayout(); break;
				case 'save-new': this._openSaveForm(); break;
				case 'import':   this._triggerDispositionImport(); break;
				case 'assign':   this._openRoleAssign(slug); break;
				case 'lock':     this._toggleLockFromMenu(); break;
			}
		},
		// Locks / unlocks the current layout (from the active preset menu).
		// Reuses the logic from the former "Advanced" toggle.
		_toggleLockFromMenu() {
			const L = state.snapshot.layout;
			if (!L) return;
			L.lock = !L.lock;
			if (typeof DragDrop !== 'undefined' && DragDrop.refreshSlotInteractions) DragDrop.refreshSlotInteractions();
			this._persistAndRehydrate('lock', { silent: true });
			Toast.show(L.lock ? t('layout_locked') : t('layout_unlocked'), 'success');
		},
		_applyDisposition(slug) {
			const disp = (state.snapshot.dispositions || []).find(d => d.slug === slug);
			if (!disp || !disp.layout) return;
			if (!confirm(Util.sprintf(t('apply_disposition_confirm'), disp.name))) return;
			const next = JSON.parse(JSON.stringify(disp.layout));
			next.preset = slug;
			next.active_screen = 0;
			state.snapshot.layout = next;
			if (typeof History !== 'undefined') History.reset(); // "hard" reset → new history
			Nav._clearStoredScreen(); // #6 — return to screen 1 after applying
			Api.saveLayout(next).then(() => location.reload())
				.catch(err => Toast.show(err.message || t('error'), 'error'));
		},
		_updateDisposition(slug) {
			const disp = (state.snapshot.dispositions || []).find(d => d.slug === slug);
			const name = disp ? disp.name : slug;
			if (!confirm(Util.sprintf(t('update_disposition_confirm'), name))) return;
			Api.saveDisposition({ slug: slug, name: '', layout: state.snapshot.layout }).then(res => {
				state.snapshot.dispositions = res.dispositions || [];
				this._renderDispositions();
				Toast.show(t('disposition_updated'), 'success');
			}).catch(err => Toast.show(err.message || t('error'), 'error'));
		},
		_exportDisposition(slug) {
			let name, layout;
			if (slug === 'custom') {
				name = t('custom_layout');
				layout = state.snapshot.layout;
			} else {
				const disp = (state.snapshot.dispositions || []).find(d => d.slug === slug);
				if (!disp || !disp.layout) return;
				name = disp.name;
				layout = disp.layout;
			}
			const payload = { schema: 'doubleyoupi.disposition.v1', name: name, layout: layout };
			const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = 'disposition-' + (slug || 'custom') + '.json';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
			Toast.show(t('layout_exported'), 'success');
		},
		_deleteDisposition(slug) {
			const disp = (state.snapshot.dispositions || []).find(d => d.slug === slug);
			const name = disp ? disp.name : slug;
			if (!confirm(Util.sprintf(t('confirm_delete_disposition'), name))) return;
			Api.deleteDisposition(slug).then(res => {
				state.snapshot.dispositions = res.dispositions || [];
				this._renderDispositions();
				Toast.show(t('disposition_deleted'), 'success');
			}).catch(err => Toast.show(err.message || t('error'), 'error'));
		},
		// "Assign role(s)" modal — checks the roles to assign this preset to.
		// Conflict (role already on another preset) → confirmation.
		_openRoleAssign(slug) {
			const disp = (state.snapshot.dispositions || []).find(d => d.slug === slug);
			if (!disp) return;
			const roles = state.snapshot.roles || {};
			const assignments = state.snapshot.role_assignments || {};
			const defaults = state.snapshot.role_defaults || {};
			const esc = Util.escapeHtml;
			const nameOf = (s) => { const d = (state.snapshot.dispositions || []).find(x => x.slug === s); return d ? d.name : s; };

			const rolesHtml = Object.keys(roles).map(rk => {
				const on = assignments[rk];
				const checked = on === slug ? ' checked' : '';
				// Contextual hint: conflict (already assigned elsewhere) OR, in absence
				// of explicit assignment, the heuristic default for the role.
				let conflict = '';
				if (on && on !== slug) {
					conflict = '<span class="dy-dashboard__role-conflict">' + esc(Util.sprintf(t('role_already_on'), nameOf(on))) + '</span>';
				} else if (!on && defaults[rk]) {
					conflict = '<span class="dy-dashboard__role-default">' + esc(Util.sprintf(t('role_default_is'), nameOf(defaults[rk]))) + '</span>';
				}
				return '<label class="dy-dashboard__role-item">' +
					'<input type="checkbox" class="dy-dashboard__field-toggle" data-role="' + esc(rk) + '"' + checked + '>' +
					'<span class="dy-dashboard__role-name">' + esc(roles[rk]) + '</span>' + conflict +
				'</label>';
			}).join('');

			const overlay = document.createElement('div');
			overlay.className = 'dy-dashboard__modal-overlay';
			overlay.innerHTML =
				'<div class="dy-dashboard__modal" role="dialog" aria-modal="true">' +
					'<header class="dy-dashboard__modal-head">' +
						'<h2 class="dy-dashboard__modal-title">' + esc(Util.sprintf(t('assign_roles_to'), disp.name)) + '</h2>' +
						'<button type="button" class="dy-dashboard__modal-close" data-modal-close aria-label="' + esc(t('close_panel')) + '">' +
							'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
						'</button>' +
					'</header>' +
					'<p class="dy-dashboard__modal-sub">' + esc(t('assign_roles_hint')) + '</p>' +
					'<div class="dy-dashboard__role-list">' + rolesHtml + '</div>' +
					'<footer class="dy-dashboard__modal-foot">' +
						'<button type="button" class="dy-dashboard__btn dy-dashboard__btn--ghost" data-modal-close>' + esc(t('cancel')) + '</button>' +
						'<button type="button" class="dy-dashboard__btn dy-dashboard__btn--primary" data-modal-save>' + esc(t('save')) + '</button>' +
					'</footer>' +
				'</div>';
			document.body.appendChild(overlay);
			requestAnimationFrame(() => overlay.classList.add('is-open'));

			const onKey = (e) => { if (e.key === 'Escape') close(); };
			function close() {
				overlay.classList.remove('is-open');
				document.removeEventListener('keydown', onKey);
				setTimeout(() => overlay.remove(), 200);
			}
			document.addEventListener('keydown', onKey);

			overlay.addEventListener('click', (e) => {
				if (e.target === overlay || e.target.closest('[data-modal-close]')) { close(); return; }
				const saveBtn = e.target.closest('[data-modal-save]');
				if (!saveBtn) return;
				const selected = Array.from(overlay.querySelectorAll('input[data-role]:checked')).map(cb => cb.dataset.role);
				// Conflict: selected roles already assigned to ANOTHER preset.
				const conflicts = selected.filter(r => assignments[r] && assignments[r] !== slug);
				if (conflicts.length) {
					const list = conflicts.map(r => (roles[r] || r) + ' → ' + nameOf(assignments[r])).join(', ');
					if (!confirm(Util.sprintf(t('role_conflict_confirm'), list))) return;
				}
				saveBtn.disabled = true;
				Api.assignDisposition(slug, selected).then(res => {
					state.snapshot.role_assignments = res.assignments || {};
					Toast.show(t('roles_assigned'), 'success');
					close();
				}).catch(err => { saveBtn.disabled = false; Toast.show(err.message || t('error'), 'error'); });
			});
		},
		// "Clear completely": starts from a single empty screen (title "Let's start fresh"),
		// preserving wp_native_screen / role_target / lock.
		_clearLayout() {
			if (!confirm(t('confirm_clear_all'))) return;
			const L = state.snapshot.layout || {};
			const next = {
				version: 4,
				schema: 'doubleyoupi.dashboard.v4',
				preset: 'custom',
				active_screen: 0,
				lock: !!L.lock,
				role_target: L.role_target || null,
				wp_native_screen: L.wp_native_screen || { enabled: true, hidden_widgets: [] },
				screens: [ {
					id: Util.uid('scr'),
					title: t('blank_slate_title'),
					title_em: '',
					paragraph: '',
					paragraph_enabled: false,
					accent_intensity: 'auto',
					widgets: []
				} ]
			};
			state.snapshot.layout = next;
			if (typeof History !== 'undefined') History.reset(); // "hard" reset → new history
			Nav._clearStoredScreen(); // #6 — return to screen 1
			Api.saveLayout(next).then(() => location.reload())
				.catch(err => Toast.show(err.message || t('error'), 'error'));
		},
		// Import a standalone preset file (schema doubleyoupi.disposition.v1).
		_wireDispositionImport() {
			const input = dom.sidebar.querySelector('[data-disposition-import]');
			if (!input) return;
			this._dispositionImportInput = input;
			input.addEventListener('change', (e) => {
				const file = e.target.files && e.target.files[0];
				if (!file) return;
				const reader = new FileReader();
				reader.onload = () => {
					let data;
					try { data = JSON.parse(reader.result); } catch (err) { Toast.show(t('invalid_json'), 'error'); return; }
					if (!data || data.schema !== 'doubleyoupi.disposition.v1' || typeof data.layout !== 'object') {
						Toast.show(t('invalid_disposition_file'), 'error');
						return;
					}
					Api.saveDisposition({ name: data.name || t('imported_disposition'), slug: '', layout: data.layout }).then(res => {
						state.snapshot.dispositions = res.dispositions || [];
						this._renderDispositions();
						Toast.show(t('disposition_saved'), 'success');
					}).catch(err => Toast.show(err.message || t('error'), 'error'));
				};
				reader.readAsText(file);
				input.value = '';
			});
		},
		_triggerDispositionImport() {
			if (this._dispositionImportInput) this._dispositionImportInput.click();
		},
		// "Save as preset" form: overwrite an existing custom preset OR create a new one.
		// "Save as new layout preset" form: name field only (overwriting an
		// existing preset now goes through "Update" in each tile's 3-dot menu).
		// Triggered by _openSaveForm() from the Custom card.
		_wireSaveDisposition() {
			const form = dom.sidebar.querySelector('[data-save-form]');
			if (!form) return; // non-admin
			const nameIn  = form.querySelector('[data-save-name]');
			const confirm = form.querySelector('[data-action="save-disposition-confirm"]');
			const cancel  = form.querySelector('[data-action="save-disposition-cancel"]');

			this._saveForm   = form;
			this._saveNameIn = nameIn;
			const closeForm = () => form.setAttribute('hidden', '');

			cancel.addEventListener('click', closeForm);
			nameIn.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); confirm.click(); } });

			confirm.addEventListener('click', () => {
				const name = nameIn.value.trim();
				if (!name) { nameIn.focus(); return; }
				Api.saveDisposition({ name: name, slug: '', layout: state.snapshot.layout }).then(res => {
					state.snapshot.dispositions = res.dispositions || [];
					if (res.disposition && res.disposition.slug) {
						state.snapshot.layout.preset = res.disposition.slug;
					}
					this._renderDispositions();
					closeForm();
					Toast.show(t('disposition_saved'), 'success');
				}).catch(err => Toast.show(err.message || t('error'), 'error'));
			});
		},
		// Opens the name form (from the "Save as new" menu item).
		_openSaveForm() {
			if (!this._saveForm) return;
			this._saveNameIn.value = '';
			this._saveForm.removeAttribute('hidden');
			this._saveNameIn.focus();
		},
		// Active preset slug: layout.preset if it matches a known preset,
		// otherwise 'custom' (the "Custom" card).
		_activeDispositionSlug() {
			const preset = (state.snapshot.layout && state.snapshot.layout.preset) || 'custom';
			const known  = (state.snapshot.dispositions || []).some(d => d.slug === preset);
			return known ? preset : 'custom';
		},
		// 3-dot button + contextual menu for a preset tile.
		// `kind`: 'builtin' | 'user' | 'custom' — determines menu items.
		// Tiles = <div role="button"> (not <button>) to allow nesting
		// real <button> elements in the menu without invalid HTML.
		// Only the 3-dot BUTTON (the menu itself is a single element
		// rendered at <body> level via _ensureFloatMenu, to escape the
		// sidebar's transform + overflow:hidden which clipped position:fixed menus).
		// `data-menu-kind` indicates which items to show.
		_dispositionMenuHtml(kind) {
			const esc = Util.escapeHtml;
			return '<span class="dy-dashboard__preset-menu-btn" role="button" tabindex="0" data-preset-menu-btn data-menu-kind="' + esc(kind) + '" aria-haspopup="true" aria-expanded="false" aria-label="' + esc(t('more_actions')) + '">' +
					'<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="12" cy="19" r="1.7"/></svg>' +
				'</span>';
		},
		// Menu items based on tile type. `slug` allows adding contextual actions:
		// "Assign role(s)" (any named preset) and
		// "Lock / Unlock" (only the active preset).
		_dispositionMenuItems(kind, slug) {
			const esc = Util.escapeHtml;
			const item = (action, label) =>
				'<button type="button" class="dy-dashboard__preset-menu-item" data-menu-action="' + action + '" role="menuitem">' + esc(label) + '</button>';
			if (kind === 'custom') {
				return item('clear', t('menu_clear_all')) + item('export', t('menu_export')) + item('save-new', t('menu_save_new'));
			}
			let out = item('apply', t('menu_apply')) + item('update', t('menu_update_current'));
			// Assign role(s) — available on any saved preset.
			out += item('assign', t('menu_assign_roles'));
			// Lock: only on the currently active preset
			// (the lock acts on the user's current layout).
			if (slug && slug === this._activeDispositionSlug()) {
				const locked = !!(state.snapshot.layout && state.snapshot.layout.lock);
				out += item('lock', locked ? t('menu_unlock_layout') : t('menu_lock_layout'));
			}
			out += item('export', t('menu_export')) + item('delete', t('menu_delete'));
			return out;
		},
		// Single floating menu at <body> level (outside the transformed sidebar).
		_ensureFloatMenu() {
			if (this._floatMenu) return this._floatMenu;
			const m = document.createElement('div');
			m.className = 'dy-dashboard__preset-menu';
			m.setAttribute('role', 'menu');
			m.setAttribute('hidden', '');
			document.body.appendChild(m);
			m.addEventListener('click', (e) => {
				const it = e.target.closest('[data-menu-action]');
				if (!it) return;
				e.stopPropagation();
				const action = it.dataset.menuAction;
				const slug = this._floatMenuSlug;
				this._closeAllPresetMenus();
				this._onDispositionMenuAction(action, slug);
			});
			this._floatMenu = m;
			return m;
		},
		// (Re)renders the 2-column preset grid + the virtual "Custom" card.
		_renderDispositions() {
			const root = this._presetsRoot;
			if (!root) return;
			const canManage = !!(state.snapshot.caps && state.snapshot.caps.can_manage);
			const active    = this._activeDispositionSlug();
			const esc       = Util.escapeHtml;
			const cards     = [];

			(state.snapshot.dispositions || []).forEach(d => {
				const isActive = d.slug === active;
				const menu = canManage ? this._dispositionMenuHtml(d.builtin ? 'builtin' : 'user') : '';
				cards.push(
					'<div class="dy-dashboard__preset-btn' + (isActive ? ' is-active' : '') + '" role="button" tabindex="0" data-disposition-slug="' + esc(d.slug) + '" data-disposition-name="' + esc(d.name) + '">' +
						'<span class="dy-dashboard__preset-name">' + esc(d.name) + '</span>' +
						'<span class="dy-dashboard__preset-desc">' + esc(d.description) + '</span>' +
						menu +
					'</div>'
				);
			});

			// Virtual "Custom" card — full width, icon, distinct style.
			// Active as soon as the layout diverges from a named preset.
			const customActive = active === 'custom';
			const customMenu = canManage ? this._dispositionMenuHtml('custom') : '';
			cards.push(
				'<div class="dy-dashboard__preset-btn dy-dashboard__preset-btn--custom' + (customActive ? ' is-active' : '') + '" data-disposition-slug="custom">' +
					'<span class="dy-dashboard__preset-custom-icon" aria-hidden="true">' +
						'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5 10.1 7.6z"/><path d="M19 14l.7 1.8L21.5 16.5 19.7 17.2 19 19l-.7-1.8L16.5 16.5 18.3 15.8z"/></svg>' +
					'</span>' +
					'<span class="dy-dashboard__preset-custom-text">' +
						'<span class="dy-dashboard__preset-name">' + esc(t('custom_layout')) + '</span>' +
						'<span class="dy-dashboard__preset-desc">' + esc(t('custom_layout_desc')) + '</span>' +
					'</span>' +
					customMenu +
				'</div>'
			);

			root.innerHTML = cards.join('');
		},
		// Creates a blank v4 screen object with a title (default: picked at random
		// from 5 suggestions). Centralizes default fields for
		// add-screen, insert-screen (pagination), and clear-layout.
		_makeScreen(title) {
			return {
				id: Util.uid('scr'),
				title: (typeof title === 'string') ? title : this._randomScreenTitle(),
				title_em: '',
				paragraph: '',
				paragraph_enabled: false,
				accent_intensity: 'auto',
				accent_color: '',
				bg_color: '',
				widgets: []
			};
		},
		// Picks a "simple and fun" screen title from 5 options (i18n).
		_randomScreenTitle() {
			const keys = ['new_screen_title_1', 'new_screen_title_2', 'new_screen_title_3', 'new_screen_title_4', 'new_screen_title_5'];
			return t(keys[Math.floor(Math.random() * keys.length)]);
		},
		// Inserts a new screen at `index` (clamped to 0..len) then persists (reload).
		_insertScreen(index) {
			const screens = state.snapshot.layout.screens;
			const i = Math.max(0, Math.min(screens.length, index | 0));
			const created = this._makeScreen();
			screens.splice(i, 0, created);
			state.snapshot.layout.active_screen = i;
			// Live insertion + swipe (no reload), then in-place persistence.
			Nav.addScreenLive(i);
			if (this._screensListRender) this._screensListRender();
			// History entry labeled with the added screen → redo navigates to it.
			this._persistAndRehydrate('screen-add-live', { historyScreenId: created.id || null });
		},
		_wireScreensList() {
			const root = dom.sidebar.querySelector('[data-screens-root]');
			const grip = '<span class="dy-dashboard__screen-item-grip" data-screen-grip aria-hidden="true">' +
				'<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg></span>';
			const render = () => {
				root.innerHTML = state.snapshot.layout.screens.map((s, i) => (
					'<li class="dy-dashboard__screen-item' + (i === state.activeScreenIndex ? ' is-active' : '') + '" data-screen-i="' + i + '">' +
						grip +
						'<span class="dy-dashboard__screen-item-num">Ø' + (i + 1).toString().padStart(2, '0') + '</span>' +
						'<span class="dy-dashboard__screen-item-title">' + Util.escapeHtml(Util.resolveTokens(s.title) || Util.sprintf(t('screen_n'), i + 1)) + '</span>' +
						'<button type="button" class="dy-dashboard__screen-item-del" data-action="del-screen" data-screen-i="' + i + '" aria-label="' + Util.escapeHtml(t('delete_screen')) + '">' +
							'<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>' +
						'</button>' +
					'</li>'
				)).join('');
			};
			render();

			root.addEventListener('click', (e) => {
				// Synthetic click fired after a drag-reorder → ignore it.
				if (this._screenDraggedAt && (Date.now() - this._screenDraggedAt) < 300) {
					e.preventDefault(); e.stopPropagation(); return;
				}
				const del = e.target.closest('[data-action="del-screen"]');
				if (del) {
					e.stopPropagation();
					const i = parseInt(del.dataset.screenI, 10);
					if (state.snapshot.layout.screens.length <= 1) {
						Toast.show(t('min_one_screen'), 'warn');
						return;
					}
					if (!confirm(t('confirm_delete_screen'))) return;
					state.snapshot.layout.screens.splice(i, 1);
					this._persistAndRehydrate('screens-list');
					return;
				}
				const item = e.target.closest('[data-screen-i]');
				if (item) {
					Nav.go(parseInt(item.dataset.screenI, 10));
				}
			});

			// Pointer-based drag reorder triggered from the grip handle.
			root.addEventListener('pointerdown', (e) => {
				const gripEl = e.target.closest('[data-screen-grip]');
				if (!gripEl) return;
				e.preventDefault();
				this._startScreenReorder(root, gripEl.closest('.dy-dashboard__screen-item'));
			});

			dom.sidebar.querySelector('[data-action="add-screen"]').addEventListener('click', () => {
				const i = state.snapshot.layout.screens.length; // append (before the native screen)
				const created = this._makeScreen();
				state.snapshot.layout.screens.push(created);
				state.snapshot.layout.active_screen = i;
				Nav.addScreenLive(i);
				render();
				this._persistAndRehydrate('screen-add-live', { historyScreenId: created.id || null });
			});

			this._screensListRender = render;
		},
		// Pointer-based drag of a screen item; reorders the DOM live and,
		// on pointer-up, rebuilds state.screens in the final order and persists.
		_startScreenReorder(root, li) {
			if (!li) return;
			li.classList.add('is-dragging');
			const onMove = (e) => {
				const under = document.elementFromPoint(e.clientX, e.clientY);
				const over = under && under.closest('.dy-dashboard__screen-item');
				if (!over || over === li || over.parentElement !== root) return;
				const rect = over.getBoundingClientRect();
				const before = e.clientY < rect.top + rect.height / 2;
				root.insertBefore(li, before ? over : over.nextSibling);
			};
			const onUp = () => {
				document.removeEventListener('pointermove', onMove);
				document.removeEventListener('pointerup', onUp);
				li.classList.remove('is-dragging');
				const perm = Array.from(root.children).map(c => parseInt(c.dataset.screenI, 10));
				const identity = perm.every((v, idx) => v === idx);
				this._screenDraggedAt = Date.now();
				if (identity) return; // no change
				const screens = state.snapshot.layout.screens;
				state.snapshot.layout.screens = perm.map(i => screens[i]);
				state.snapshot.layout.active_screen = 0;
				this._persistAndRehydrate('screens-list');
			};
			document.addEventListener('pointermove', onMove);
			document.addEventListener('pointerup', onUp);
		},
		_wireExportImport() {
			const exportBtn = dom.sidebar.querySelector('[data-action="export-layout"]');
			const importBtn = dom.sidebar.querySelector('[data-action="import-layout"]');
			const fileInput = dom.sidebar.querySelector('[data-import-file]');

			exportBtn.addEventListener('click', () => {
				const blob = new Blob([JSON.stringify(state.snapshot.layout, null, 2)], { type: 'application/json' });
				const url = URL.createObjectURL(blob);
				const a = document.createElement('a');
				a.href = url;
				a.download = 'dashboard-layout.json';
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				URL.revokeObjectURL(url);
				Toast.show(t('layout_exported'), 'success');
			});

			importBtn.addEventListener('click', () => fileInput.click());
			fileInput.addEventListener('change', (e) => {
				const file = e.target.files && e.target.files[0];
				if (!file) return;
				const reader = new FileReader();
				reader.onload = () => {
					try {
						const data = JSON.parse(reader.result);
						state.snapshot.layout = data;
						if (typeof History !== 'undefined') History.reset(); // heavy import → fresh history
						this._persistAndRehydrate('import');
					} catch (err) {
						Toast.show(t('invalid_json'), 'error');
					}
				};
				reader.readAsText(file);
				fileInput.value = '';
			});

			dom.sidebar.querySelector('[data-action="reset-layout"]').addEventListener('click', () => {
				if (!confirm(t('confirm_reset'))) return;
				if (typeof History !== 'undefined') History.reset(); // heavy reset → fresh history
				Nav._clearStoredScreen(); // #6 — restart from screen 1
				Api.resetLayout().then(() => location.reload()).catch(err => Toast.show(err.message || t('error'), 'error'));
			});
		},
		_wireLockToggle() {
			const cb = dom.sidebar.querySelector('[data-field="lock"]');
			if (!cb) return;
			cb.addEventListener('change', () => {
				state.snapshot.layout.lock = cb.checked;
				DragDrop.refreshSlotInteractions();
				this._persistAndRehydrate('lock', { silent: true });
			});
		},
		_wireRoleSelect() {
			const select = dom.sidebar.querySelector('[data-field="role_target"]');
			if (!select) return; // field reserved for admins (can_manage)
			// List of WP roles (slug => translated name) provided by the bootstrap.
			const roles = (state.snapshot && state.snapshot.roles) || {};
			select.innerHTML += Object.keys(roles).map(slug => (
				'<option value="' + Util.escapeHtml(slug) + '">' + Util.escapeHtml(roles[slug]) + '</option>'
			)).join('');
			select.addEventListener('change', () => {
				state.snapshot.layout.role_target = select.value || null;
				this._persistAndRehydrate('role', { silent: true });
			});
		},
		_wireNativeToggle() {
			const cb = dom.sidebar.querySelector('[data-field="wp_native_enabled"]');
			cb.addEventListener('change', () => {
				state.snapshot.layout.wp_native_screen = state.snapshot.layout.wp_native_screen || { enabled: true, hidden_widgets: [] };
				state.snapshot.layout.wp_native_screen.enabled = cb.checked;
				this._persistAndRehydrate('wp-native-toggle');
			});
		},
		refreshCatalog() {
			const catalog = state.snapshot.catalog || { doubleyoupi: [], woocommerce: [], extensions: [], wordpress: [], plugins: [] };
			const groups = [
				{ key: 'doubleyoupi', label: 'Double Youpï', tiles: catalog.doubleyoupi || [] },
				{ key: 'woocommerce', label: 'WooCommerce',  tiles: catalog.woocommerce || [] },
				// Widgets added by other plugins through doubleyoupi_dashboard_widgets_registered.
				{ key: 'extensions',  label: t('catalog_extensions'), tiles: catalog.extensions || [] },
				{ key: 'wordpress',   label: 'WordPress',   tiles: catalog.wordpress   || [] },
				{ key: 'plugins',     label: 'Plugins',     tiles: catalog.plugins     || [] }
			];
			// Group title shown only when there are multiple groups
			// (if WP/plugins are hidden, only Double Youpï remains → no point showing it).
			const nonEmpty = groups.filter(g => g.tiles.length);
			const showTitles = nonEmpty.length > 1;
			const html = nonEmpty.map(group => {
				return [
					'<div class="dy-dashboard__catalog-group" data-catalog-group="' + group.key + '">',
						showTitles ? '<span class="dy-dashboard__field-label dy-dashboard__catalog-group-title">' + Util.escapeHtml(group.label) + '</span>' : '',
						'<div class="dy-dashboard__tiles">',
							group.tiles.map(t => Sidebar._tileHtml(t, group.key)).join(''),
						'</div>',
					'</div>'
				].join('');
			}).join('');
			dom.sidebar.querySelector('[data-catalog-root]').innerHTML = html;

			// Attach drag handlers via DragDrop module.
			DragDrop.bindTiles();
		},
		_tileHtml(tile, originKey) {
			const slots = tile.recommended_slots || 2;
			// `validated` indicates the widget is styled and verified. Otherwise, the tile
			// is grayed out (but remains addable during development) with a badge.
			const validated = tile.validated !== false;
			const cls = 'dy-dashboard__tile' + (validated ? '' : ' dy-dashboard__tile--unvalidated');
			const badge = validated
				? ''
				: '<span class="dy-dashboard__tile-badge" title="' + Util.escapeHtml(t('not_styled_yet')) + '">' + Util.escapeHtml(t('not_styled_yet')) + '</span>';
			return [
				'<div class="', cls, '"',
					' data-tile-id="', Util.escapeHtml(tile.id), '"',
					' data-tile-origin="', Util.escapeHtml(originKey), '"',
					' data-tile-slots="', slots, '"',
					' data-tile-instanceable="', tile.is_instanceable ? '1' : '0', '"',
					' data-tile-validated="', validated ? '1' : '0', '"',
					' tabindex="0">',
					'<div class="dy-dashboard__tile-icon">', tile.icon_svg || '', '</div>',
					'<div class="dy-dashboard__tile-meta">',
						'<span class="dy-dashboard__tile-name">', Util.escapeHtml(tile.name), (tile.brand_logo_svg || ''), '</span>',
						tile.description ? '<span class="dy-dashboard__tile-desc">' + Util.escapeHtml(tile.description) + '</span>' : '',
					'</div>',
					'<span class="dy-dashboard__tile-slots">', slots, ' slot', slots > 1 ? 's' : '', '</span>',
					badge,
				'</div>'
			].join('');
		},
		open() {
			if (state.sidebarOpen) return;
			state.sidebarOpen = true;
			dom.sidebar.classList.add('is-open');
			dom.sidebar.setAttribute('aria-hidden', 'false');
			dom.sidebar.inert = false;
			dom.root.classList.add('dy-dashboard--sidebar-open');
			this._hydrateFromState();
			// Enters edit mode: ghost cells, handles, delete buttons.
			DragDrop.refreshSlotInteractions();
			// #6 — inline title/paragraph editing + history button state.
			if (typeof ScreenTextEdit !== 'undefined') ScreenTextEdit.refresh();
			if (typeof History !== 'undefined') History.updateButtons();
		},
		close() {
			if (!state.sidebarOpen) return;
			state.sidebarOpen = false;
			dom.sidebar.classList.remove('is-open');
			dom.sidebar.setAttribute('aria-hidden', 'true');
			// Focus inside would be lost once inert: back to "Customize".
			if (dom.sidebar.contains(document.activeElement)) {
				const opener = document.querySelector('[data-action="open-sidebar"]');
				if (opener) opener.focus();
			}
			dom.sidebar.inert = true;
			dom.root.classList.remove('dy-dashboard--sidebar-open');
			this.deselectWidget();
			// Exits edit mode: removes ghost cells, handles, delete buttons.
			DragDrop.refreshSlotInteractions();
			// #6 — disables inline title/paragraph editing.
			if (typeof ScreenTextEdit !== 'undefined') ScreenTextEdit.refresh();
		},
		toggle() {
			state.sidebarOpen ? this.close() : this.open();
		},
		// Opens the Customize panel and highlights the Widgets section — triggered by
		// clicking an empty slot (add affordance, see plan §9).
		openForWidgetAdd() {
			this.open();
			// If we were configuring a widget, exit config first
			// (panel "back" animation), THEN expand the Widgets section once the
			// swap completes → smooth "back → Widgets" flow instead of a hard jump.
			const wasConfig = dom.sidebar && dom.sidebar.classList.contains('is-config-mode');
			const reveal = () => {
				const sec = dom.sidebar && dom.sidebar.querySelector('[data-section="widgets"]');
				if (!sec) return;
				const toggle = sec.querySelector('[data-section-toggle]');
				if (toggle && toggle.getAttribute('aria-expanded') !== 'true') this._toggleSection(toggle);
				const body = sec.querySelector('.dy-dashboard__section-body');
				if (body && body.scrollIntoView) body.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			};
			if (wasConfig) {
				this.closeWidgetConfig();           // trigger the "back" anim (260ms)
				setTimeout(reveal, 270);             // expand Widgets after the swap
			} else {
				reveal();
			}
		},

		// ── Contextual widget configuration ───────────────────
		// Double Youpi tile registry (schema + name) indexed by id.
		_tileFor(id) {
			// Every registered widget can carry settings, whatever its section.
			const c = state.snapshot.catalog || {};
			const all = [].concat(c.doubleyoupi || [], c.woocommerce || [], c.extensions || []);
			return all.find(tile => tile.id === id) || null;
		},
		// Returns whether a widget (by id) is configurable via the panel.
		isConfigurable(id) {
			const tile = this._tileFor(id);
			return !!(tile && Array.isArray(tile.config_schema) && tile.config_schema.length);
		},
		// Selects the widget in a slot and switches the panel to config mode.
		selectWidget(slot) {
			const screenEl = slot.closest('.dy-dashboard__screen');
			if (!screenEl || screenEl.dataset.screenKind === 'native') return;
			const screenIdx = parseInt(screenEl.dataset.screenIndex, 10);
			const slotsEl   = screenEl.querySelector('.dy-dashboard__slots');
			const widgetIdx = Array.from(slotsEl.querySelectorAll(':scope > .dy-dashboard__slot')).indexOf(slot);
			const screen    = state.snapshot.layout.screens[screenIdx];
			if (!screen || widgetIdx < 0 || !screen.widgets[widgetIdx]) return;

			this._selected = { screenIndex: screenIdx, widgetIndex: widgetIdx, slot: slot };
			dom.root.querySelectorAll('.dy-dashboard__slot.is-selected').forEach(s => s.classList.remove('is-selected'));
			slot.classList.add('is-selected');

			this.openWidgetConfig();
		},
		// Switches the Customize panel to "widget config mode": only the widget's
		// settings are displayed along with a "back" button to exit.
		openWidgetConfig() {
			const widget = this._selectedWidget();
			if (!widget) { this.deselectWidget(); return; }
			const tile   = this._tileFor(widget.id);
			const schema = (tile && Array.isArray(tile.config_schema)) ? tile.config_schema : [];

			const section = dom.sidebar.querySelector('[data-section="widget-config"]');
			const root    = section.querySelector('[data-widget-config-root]');
			widget.config = widget.config || {};
			root.innerHTML = schema.map(f => this._fieldHtml(f, widget.config[f.key])).join('');
			this._wireConfigFields(root, schema, widget);

			const backLabel = dom.sidebar.querySelector('[data-config-back-label]');
			if (backLabel) backLabel.textContent = tile ? tile.name : t('section_widget');

			section.removeAttribute('hidden');
			dom.sidebar.classList.add('is-config-mode');
			const scroll = dom.sidebar.querySelector('.dy-dashboard__sidebar-scroll');
			if (scroll) scroll.scrollTop = 0;
			this._animatePanelSwap('fwd');
		},
		closeWidgetConfig() {
			dom.sidebar.classList.remove('is-config-mode');
			const section = dom.sidebar.querySelector('[data-section="widget-config"]');
			if (section) section.setAttribute('hidden', '');
			this.deselectWidget();
			this._animatePanelSwap('back');
		},
		// Smooth transition when the panel switches between config and normal mode:
		// content fades and slides (left on enter, right on exit).
		_animatePanelSwap(dir) {
			const scroll = dom.sidebar && dom.sidebar.querySelector('.dy-dashboard__sidebar-scroll');
			if (!scroll) return;
			scroll.classList.remove('is-swapping-fwd', 'is-swapping-back');
			void scroll.offsetWidth; // reflow → relance l'animation
			scroll.classList.add(dir === 'back' ? 'is-swapping-back' : 'is-swapping-fwd');
		},
		deselectWidget() {
			this._selected = null;
			if (dom.root) dom.root.querySelectorAll('.dy-dashboard__slot.is-selected').forEach(s => s.classList.remove('is-selected'));
			if (dom.sidebar) {
				dom.sidebar.classList.remove('is-config-mode');
				// Hide the config section so it does not reappear as a
				// "normal" section when the panel is reopened.
				const section = dom.sidebar.querySelector('[data-section="widget-config"]');
				if (section) section.setAttribute('hidden', '');
			}
		},
		_selectedWidget() {
			if (!this._selected) return null;
			const screen = state.snapshot.layout.screens[this._selected.screenIndex];
			return screen ? screen.widgets[this._selected.widgetIndex] || null : null;
		},
		// Builds the markup for a config field according to its type.
		_fieldHtml(field, value) {
			const esc = Util.escapeHtml;
			const label = '<span class="dy-dashboard__field-label">' + esc(field.label || field.key) + '</span>';
			const ph = field.placeholder ? ' placeholder="' + esc(field.placeholder) + '"' : '';

			if (field.type === 'info') {
				return '<p class="dy-dashboard__field-info">' + esc(field.text || '') + '</p>';
			}
			if (field.type === 'checkbox') {
				return '<label class="dy-dashboard__field dy-dashboard__field--inline">' +
					'<input type="checkbox" data-cfg-bool="' + esc(field.key) + '" class="dy-dashboard__field-toggle"' + (value ? ' checked' : '') + '>' +
					'<span>' + esc(field.label || field.key) + '</span></label>';
			}
			if (field.type === 'textarea') {
				return '<label class="dy-dashboard__field">' + label +
					'<textarea data-cfg="' + esc(field.key) + '" class="dy-dashboard__textarea" rows="' + (field.rows || 4) + '"' + ph + '>' + esc(value || '') + '</textarea></label>';
			}
			if (field.type === 'select') {
				const opts = (field.options || []).map(o =>
					'<option value="' + esc(o.value) + '"' + (String(value) === String(o.value) ? ' selected' : '') + '>' + esc(o.label) + '</option>'
				).join('');
				return '<label class="dy-dashboard__field">' + label +
					'<select data-cfg="' + esc(field.key) + '" class="dy-dashboard__input">' + opts + '</select></label>';
			}
			if (field.type === 'picklist') {
				return '<div class="dy-dashboard__field" data-cfg-pick="' + esc(field.key) + '"' + (field.max ? ' data-max="' + field.max + '"' : '') + '>' +
					this._picklistInner(field, value) + '</div>';
			}
			// text / url (default)
			return '<label class="dy-dashboard__field">' + label +
				'<input type="text" data-cfg="' + esc(field.key) + '" class="dy-dashboard__input" value="' + esc(value || '') + '"' + ph + '></label>';
		},
		// Inner content of a picklist (label + selected list + add dropdown).
		_picklistInner(field, value) {
			const esc = Util.escapeHtml;
			const selected = Array.isArray(value) ? value.map(String) : [];
			const byVal = {};
			(field.options || []).forEach(o => { byVal[String(o.value)] = o.label; });
			const items = selected.map(v =>
				'<li class="dy-dashboard__pick-item" data-pick-item="' + esc(v) + '">' +
					'<span>' + esc(byVal[v] || v) + '</span>' +
					'<button type="button" class="dy-dashboard__pick-del" data-pick-del aria-label="' + esc(t('remove_item')) + '">' +
					'<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg></button></li>'
			).join('');
			const remaining = (field.options || []).filter(o => selected.indexOf(String(o.value)) === -1);
			const opts = '<option value="">' + esc(field.add_label || t('add_item')) + '</option>' +
				remaining.map(o => '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>').join('');
			return '<span class="dy-dashboard__field-label">' + esc(field.label || field.key) + '</span>' +
				(items ? '<ul class="dy-dashboard__picklist" data-pick-list>' + items + '</ul>' : '') +
				'<select class="dy-dashboard__input dy-dashboard__pick-select" data-pick-select' + (remaining.length ? '' : ' disabled') + '>' + opts + '</select>';
		},
		_wireConfigFields(root, schema, widget) {
			const persist = Util.debounce(() => {
				this._persistAndRehydrate('widget-config');
				this._rerenderSelectedSlot();
			}, 300);

			// IMPORTANT: do NOT close over the `widget` argument. After the
			// first edit, `_persistAndRehydrate` → `Api.saveLayout` reassigns
			// `state.snapshot.layout = json.layout`: the captured `widget` object
			// becomes orphaned (outside the current state tree) and subsequent edits
			// are lost. So re-resolve the current widget on EACH event via
			// `_selectedWidget()` (index-based resolution, stable across layout replacements).
			// See plan §1.
			const cfg = () => {
				const w = this._selectedWidget();
				if (w && !w.config) w.config = {};
				return w;
			};

			// Simple fields (text/url/textarea/select).
			root.querySelectorAll('[data-cfg]').forEach(el => {
				const evt = (el.tagName === 'SELECT') ? 'change' : 'input';
				el.addEventListener(evt, () => { const w = cfg(); if (!w) return; w.config[el.dataset.cfg] = el.value; persist(); });
			});
			// Checkboxes (single boolean).
			root.querySelectorAll('[data-cfg-bool]').forEach(cb => {
				cb.addEventListener('change', () => { const w = cfg(); if (!w) return; w.config[cb.dataset.cfgBool] = cb.checked; persist(); });
			});
			// Picklists: add via dropdown, remove via × button, optional cap.
			root.querySelectorAll('[data-cfg-pick]').forEach(pick => {
				const key = pick.dataset.cfgPick;
				const max = parseInt(pick.dataset.max, 10) || 0;
				const fieldDef = schema.find(f => f.key === key) || { options: [], key: key };
				pick.addEventListener('change', (e) => {
					if (!e.target.matches('[data-pick-select]')) return;
					const w = cfg(); if (!w) return;
					const val = e.target.value;
					if (!val) return;
					const cur = Array.isArray(w.config[key]) ? w.config[key].slice() : [];
					if (max && cur.length >= max) { Toast.show(Util.sprintf(t('max_items'), max), 'warn'); e.target.value = ''; return; }
					if (cur.indexOf(val) === -1) cur.push(val);
					w.config[key] = cur;
					pick.innerHTML = this._picklistInner(fieldDef, cur);
					persist();
				});
				pick.addEventListener('click', (e) => {
					const del = e.target.closest('[data-pick-del]');
					if (!del) return;
					const w = cfg(); if (!w) return;
					const v = del.closest('[data-pick-item]').dataset.pickItem;
					w.config[key] = (Array.isArray(w.config[key]) ? w.config[key] : []).filter(x => String(x) !== String(v));
					pick.innerHTML = this._picklistInner(fieldDef, w.config[key]);
					persist();
				});
			});
		},

		// Re-renders the selected widget HTML in place (preserves handle/delete).
		_rerenderSelectedSlot() {
			if (!this._selected) return;
			const widget = this._selectedWidget();
			const slot   = this._selected.slot;
			if (!widget || !slot || !slot.isConnected) return;
			Api.renderWidget(widget.id, widget.config, widget.instance || null).then(res => {
				const slotsEl = slot.parentElement;
				DragDrop._flipHeights(slotsEl, () => {
					Array.from(slot.children).forEach(ch => {
						if (!ch.classList.contains('dy-dashboard__slot-handle') && !ch.classList.contains('dy-dashboard__slot-delete')) {
							ch.remove();
						}
					});
					slot.insertAdjacentHTML('afterbegin', res.html);
				});
				WidgetFX.track(slot);
			}).catch(err => Toast.show(err.message || t('render_error'), 'error'));
		},
		// Shows/hides the paragraph field in the panel. The wrapper
		// `.dy-dashboard__token-wrap` (created by TokenPicker around the textarea) is
		// hidden — not just the textarea — so the "dynamic content" button also
		// disappears when the paragraph is disabled.
		_setParaFieldVisible(visible) {
			const meta = dom.sidebar && dom.sidebar.querySelector('[data-section="screen-meta"]');
			if (!meta) return;
			const field = meta.querySelector('[data-paragraph-field]');
			if (!field) return;
			// Toggle both the textarea (which carries `hidden` from the template)
			// AND its TokenPicker wrapper (which includes the "dynamic content" button)
			// — otherwise the field would stay hidden even if the wrapper is visible.
			field.hidden = !visible;
			const wrap = field.closest('.dy-dashboard__token-wrap');
			if (wrap) wrap.hidden = !visible;
		},
		_hydrateFromState() {
			// Current screen inputs.
			const isNative = this._currentScreenIsNative();
			const metaRoot = dom.sidebar.querySelector('[data-section="screen-meta"]');
			metaRoot.classList.toggle('is-disabled', isNative);
			if (!isNative) {
				const screen = this._currentScreen();
				if (screen) {
					metaRoot.querySelector('[data-field="title"]').value             = screen.title || '';
					metaRoot.querySelector('[data-field="paragraph"]').value         = screen.paragraph || '';
					metaRoot.querySelector('[data-field="paragraph_enabled"]').checked = !!screen.paragraph_enabled;
					// The paragraph field (+ its picker button) only shows when enabled.
					this._setParaFieldVisible(!!screen.paragraph_enabled);
					// Color controls (accent + background) reflect the current screen.
					this._reflectColorControl('accent_color', screen.accent_color || '');
					this._reflectColorControl('bg_color', screen.bg_color || '');
				}
			}
			const lockField = dom.sidebar.querySelector('[data-field="lock"]');
			if (lockField) lockField.checked = !!state.snapshot.layout.lock;
			const roleField = dom.sidebar.querySelector('[data-field="role_target"]');
			if (roleField) roleField.value = state.snapshot.layout.role_target || '';
			dom.sidebar.querySelector('[data-field="wp_native_enabled"]').checked = !!(state.snapshot.layout.wp_native_screen && state.snapshot.layout.wp_native_screen.enabled);

			if (this._screensListRender) this._screensListRender();
			if (this._presetsRoot) this._renderDispositions();
		},
		// #5 — called by Nav.sync when the active screen changes while the panel is open.
		onActiveScreenChanged() {
			this._hydrateFromState();
			// If a widget config is open but the selected slot is no longer
			// on the current screen, close it cleanly.
			if (this._selected && this._selected.screenIndex !== state.activeScreenIndex) {
				this.closeWidgetConfig();
			}
			// (Re)activate inline editing on the new current screen.
			if (typeof ScreenTextEdit !== 'undefined') ScreenTextEdit.refresh();
		},
		// Sources that only need a silent save (no reload):
		// DOM mutations are handled upstream by the calling modules.
		// Sources that force a reload: structural changes that touch
		// server-side rendering (presets, screens-list, role, etc.).
		// Updates title + paragraph + eyebrow of the current screen directly
		// in the DOM, without reloading, with token resolution (server-side
		// resolved values from state.snapshot.token_values).
		_updateActiveScreenHeaderDom() {
			const idx = state.activeScreenIndex;
			const screenEl = dom.root.querySelector('.dy-dashboard__screen[data-screen-index="' + idx + '"]');
			if (!screenEl || screenEl.dataset.screenKind === 'native') return;
			const screen = state.snapshot.layout.screens[idx];
			if (!screen) return;

			const headerEl = screenEl.querySelector('.dy-dashboard__screen-header');
			if (!headerEl) return;

			// TITLE
			let titleEl = headerEl.querySelector('.dy-dashboard__title');
			if (!titleEl) {
				titleEl = document.createElement('h2');
				titleEl.className = 'dy-dashboard__title';
				headerEl.appendChild(titleEl);
			}
			const resolvedTitle = Util.resolveTokens(screen.title || '');
			// #3 — if the title is being inline-edited (contenteditable), write
			// RAW text (no <em> accent) to avoid injecting markup into the editable
			// area. The accent is re-applied on teardown.
			const titleEditing = titleEl.getAttribute('contenteditable') === 'true';
			if (titleEditing) {
				// Inline editing: RAW text (tokens preserved), matching the panel.
				const rawTitle = screen.title || '';
				if (titleEl.textContent !== rawTitle) titleEl.textContent = rawTitle;
			} else if (resolvedTitle === '') {
				titleEl.classList.add('dy-dashboard__title--placeholder');
				titleEl.setAttribute('aria-hidden', 'true');
				titleEl.innerHTML = '&nbsp;';
			} else {
				titleEl.classList.remove('dy-dashboard__title--placeholder');
				titleEl.removeAttribute('aria-hidden');
				// Auto-wrap the last word (+ adjacent punctuation) in <em>
				// — mirrors Renderer::wrap_title_accent() on the PHP side so that
				// live editing reflects the accent in real time.
				titleEl.innerHTML = Util.wrapTitleAccent(resolvedTitle);
			}

			// PARAGRAPH (created/removed dynamically based on the enabled flag)
			let paraEl = headerEl.querySelector('.dy-dashboard__paragraph');
			const paraEditing = paraEl && paraEl.getAttribute('contenteditable') === 'true';
			if (!screen.paragraph_enabled) {
				// Paragraph disabled → removed from the screen in real time,
				// even during inline editing (takes priority over contenteditable mode).
				if (paraEl) paraEl.remove();
			} else if (paraEditing) {
				// Inline editing: RAW text, area kept even when empty (placeholder).
				const rawPara = screen.paragraph || '';
				if (paraEl.textContent !== rawPara) paraEl.textContent = rawPara;
			} else if (screen.paragraph_enabled && (screen.paragraph || '').trim() !== '') {
				if (!paraEl) {
					paraEl = document.createElement('p');
					paraEl.className = 'dy-dashboard__paragraph';
					headerEl.appendChild(paraEl);
				}
				const resolved = Util.resolveTokens(screen.paragraph);
				paraEl.innerHTML = Util.lightMarkdown(resolved);
			} else if (paraEl) {
				paraEl.remove();
			}
		},

		_persistAndRehydrate(source, opts) {
			opts = opts || {};
			const inplaceOK = [
				'screen-meta',  // header DOM updated live by _renderScreenHeader
				'drop-widget',  // slot DOM added live
				'reorder-widget',
				'delete-widget',
				'widget-config', // widget config: slot re-rendered in place, no reload
				'lock',
				'role',
				'wp-native-toggle',
				'screen-add-live',     // screen added live (DOM + swipe) → no reload
				'screen-del-live',     // #6 — screen deleted live
				'screen-reorder-live'  // #6 — screens reordered live
			];
			const inplace = inplaceOK.indexOf(source) !== -1 || opts.silent;

			// Any user modification diverges from the current named layout
			// → switch to "Custom" and refresh the grid.
			if (state.snapshot.layout.preset !== 'custom') {
				state.snapshot.layout.preset = 'custom';
				if (this._presetsRoot) this._renderDispositions();
			}

			const indicator = dom.sidebar.querySelector('[data-save-indicator]');
			indicator.textContent = t('saving');
			indicator.classList.add('is-saving');

			Api.saveLayout(state.snapshot.layout).then(layout => {
				state.snapshot.layout = layout;
				indicator.textContent = t('saved');
				indicator.classList.remove('is-saving');
				setTimeout(() => { indicator.textContent = t('autosave'); }, 1200);

				if (!inplace) {
					// Structural change (presets, screens, import):
					// reload to regenerate server-side markup.
					setTimeout(() => location.reload(), 400);
				} else if (!opts.noHistory && typeof History !== 'undefined') {
					// #6 — every in-place edit (no reload) creates an undoable
					// history entry, labeled with the affected screen (so undo/redo
					// navigates to it). `opts.historyScreenId` lets structural
					// mutations specify the added/deleted screen.
					const sid = (opts.historyScreenId !== undefined)
						? opts.historyScreenId
						: History.screenIdForSource(source);
					History.record(sid);
				}
			}).catch(err => {
				indicator.textContent = t('error');
				Toast.show(err.message || t('save_failed'), 'error');
			});
		}
	};

	// ============================================================
	// DRAG & DROP — Phase 9
	// ============================================================
	const DragDrop = {
		init() {
			if (!dom.viewport) return;

			// Custom pointer-based drag instead of native HTML5 DnD: the native API
			// does not allow forcing a "closed hand" cursor during the drag
			// (it is browser-controlled). We only inject the 6 ghost cells;
			// drop hit-testing is done via document.elementFromPoint on pointermove.
			dom.root.querySelectorAll('.dy-dashboard__screen[data-screen-kind="user"] .dy-dashboard__slots').forEach(slots => {
				this._updateSlotsUsed(slots); // sets data-slots-used + (re)syncs free ghost cells
			});

			// "Empty slot" affordance available as soon as the user can customize,
			// panel open OR closed (see plan §9). Clicking an empty cell opens
			// the Customize panel. Delegated on the root.
			if (state.canCustomize) {
				dom.root.classList.add('dy-dashboard--customizable');
				dom.root.addEventListener('click', (e) => {
					if (state.drag) return; // not during a drag
					const cell = e.target.closest('.dy-dashboard__slot-ghost');
					if (!cell) return;
					e.preventDefault();
					e.stopPropagation();
					Sidebar.openForWidgetAdd();
				});
			}

			this.refreshSlotInteractions();
		},

		// Injects 6 ghost cells (dashed border + large "+"). Visible in edit mode
		// (marching ants) AND on hover when the user can customize
		// (add affordance). See _dashboard.scss.
		// Syncs "ghost" cells (free slots): rendered as REAL grid items inside
		// `.dy-dashboard__slots` (after real slots), not as absolute overlays —
		// so alignment follows the grid at all breakpoints and across multiple rows
		// (see #1). Inserts exactly `6 - slotsUsed` cells (remaining capacity). Idempotent.
		_syncGhostCells(slotsEl) {
			if (!slotsEl) return;
			let used = 0;
			slotsEl.querySelectorAll(':scope > .dy-dashboard__slot').forEach(s => {
				used += parseInt(s.dataset.slots, 10) || 1;
			});
			const free = Math.max(0, 6 - used);
			const existing = slotsEl.querySelectorAll(':scope > .dy-dashboard__slot-ghost');
			if (existing.length === free) return; // already up to date
			existing.forEach(n => n.remove());
			const plus = '<span class="dy-dashboard__slot-ghost-plus" aria-hidden="true">' +
				'<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>' +
				'</span>';
			for (let i = 0; i < free; i++) {
				const cell = document.createElement('div');
				cell.className = 'dy-dashboard__slot-ghost';
				cell.setAttribute('aria-hidden', 'true');
				cell.innerHTML = plus;
				slotsEl.appendChild(cell);
			}
		},
		// Enables/disables drag from existing slots + injects handles/delete buttons.
		// Edit mode is active when: sidebar is open AND !lock.
		refreshSlotInteractions() {
			const editable = state.canCustomize && state.sidebarOpen && !state.snapshot.layout.lock;
			dom.root.classList.toggle('dy-dashboard--editable', editable);

			dom.root.querySelectorAll('.dy-dashboard__screen[data-screen-kind="user"] .dy-dashboard__slot').forEach(slot => {
				// Remove any existing handles/buttons to avoid duplicates.
				slot.querySelectorAll(':scope > .dy-dashboard__slot-handle, :scope > .dy-dashboard__slot-delete').forEach(n => n.remove());

				// Mark configurable widgets (accent hover + cursor in edit mode).
				slot.classList.toggle('is-configurable', editable && Sidebar.isConfigurable(slot.dataset.widgetId));

				if (!editable) {
					return;
				}

				// Drag handle (purely visual — the drag operates on the whole slot).
				const handle = document.createElement('span');
				handle.className = 'dy-dashboard__slot-handle';
				handle.setAttribute('aria-hidden', 'true');
				handle.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>';
				slot.appendChild(handle);

				// Delete button.
				const del = document.createElement('button');
				del.type = 'button';
				del.className = 'dy-dashboard__slot-delete';
				del.setAttribute('aria-label', t('delete_widget'));
				del.innerHTML = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>';
				del.addEventListener('click', (e) => this._onDelete(e, slot));
				slot.appendChild(del);

				// Pointer-based drag (existing slot as source).
				this._bindSlotPointer(slot);
			});
		},

		// ════════════════════════════════════════════════════════════
		// POINTER-BASED DRAG ENGINE (replaces native HTML5 DnD)
		// ------------------------------------------------------------
		// Native DnD does NOT allow controlling the cursor during a drag
		// (the browser imposes its own copy/move/no-drop cursor). To keep a
		// "closed hand" (grabbing) cursor throughout, we handle drag ourselves
		// via Pointer Events:
		//   • 6px threshold before activating (clicks remain clicks)
		//   • floating ghost that follows the cursor
		//   • cursor locked to grabbing on the entire document
		//   • drop hit-test via document.elementFromPoint
		// ════════════════════════════════════════════════════════════
		_DRAG_THRESHOLD: 6,

		// Attaches a pointerdown listener to a drag source.
		// `type` ∈ {'tile','slot'}; `makeDrag()` returns {state,label,icon}
		// (or null to cancel) when the threshold is crossed.
		_bindPointerSource(el, type, makeDrag) {
			if (el.dataset.dyPointerBound === '1') return;
			el.dataset.dyPointerBound = '1';
			el.addEventListener('pointerdown', (e) => this._onPointerDown(e, el, type, makeDrag));
		},

		_bindSlotPointer(slot) {
			this._bindPointerSource(slot, 'slot', () => {
				const screenEl  = slot.closest('.dy-dashboard__screen');
				const screenIdx = parseInt(screenEl.dataset.screenIndex, 10);
				const slotEls   = Array.from(screenEl.querySelectorAll('.dy-dashboard__slots > .dy-dashboard__slot'));
				const widgetIdx = slotEls.indexOf(slot);
				if (widgetIdx < 0) return null;
				const titleEl = slot.querySelector('.dy-dashboard__card-title, .dy-dashboard__create-tile-label');
				// Widgets without a card title (create button…): fall back to the
				// catalogue tile name rather than exposing the internal widget id.
				const tileEl = slot.dataset.widgetId
					? document.querySelector('.dy-dashboard__tile[data-tile-id="' + slot.dataset.widgetId + '"] .dy-dashboard__tile-name')
					: null;
				return {
					el: slot,
					state: {
						kind: 'reorder',
						screenIndex: screenIdx,
						widgetIndex: widgetIdx,
						slots: parseInt(slot.dataset.slots, 10) || 1
					},
					label: (titleEl && titleEl.textContent.trim()) || (tileEl && tileEl.textContent.trim()) || '',
					icon: ''
				};
			});
		},

		_onPointerDown(e, el, type, makeDrag) {
			if (e.button !== 0) return; // left button only
			// Drag from a slot: only in edit mode, and never from an
			// editable field (Note) or the delete button.
			if (type === 'slot') {
				if (!dom.root.classList.contains('dy-dashboard--editable')) return;
				if (e.target.closest('input, textarea, select, [contenteditable="true"], .dy-dashboard__slot-delete')) return;
			}
			this._pending = { el: el, type: type, makeDrag: makeDrag, startX: e.clientX, startY: e.clientY, dragging: false };
			this._pmove   = (ev) => this._onPointerMove(ev);
			this._pup     = (ev) => this._onPointerUp(ev);
			this._pcancel = () => this._onPointerCancel();
			document.addEventListener('pointermove', this._pmove, { passive: false });
			document.addEventListener('pointerup', this._pup);
			document.addEventListener('pointercancel', this._pcancel);
		},

		_teardownPending() {
			document.removeEventListener('pointermove', this._pmove);
			document.removeEventListener('pointerup', this._pup);
			document.removeEventListener('pointercancel', this._pcancel);
			this._pending = null;
		},

		_onPointerMove(e) {
			const p = this._pending;
			if (!p) return;
			if (!p.dragging) {
				const dx = e.clientX - p.startX, dy = e.clientY - p.startY;
				if ((dx * dx + dy * dy) < (this._DRAG_THRESHOLD * this._DRAG_THRESHOLD)) return;
				const drag = p.makeDrag();
				if (!drag) { this._teardownPending(); return; }
				p.dragging = true;
				state.drag = drag.state;
				dom.root.classList.add('dy-dashboard--dragging');
				document.body.classList.add('dy-dashboard-dragging-cursor'); // global cursor lock
				p.el.classList.add('is-dragging');
				this._ghost = this._makeGhost(drag.label, drag.icon);
				this._moveGhost(e.clientX, e.clientY);
				this._updateDropFeedback(e.clientX, e.clientY);
			} else {
				e.preventDefault(); // prevent text selection / touch scroll
				this._moveGhost(e.clientX, e.clientY);
				this._updateDropFeedback(e.clientX, e.clientY);
			}
		},

		_onPointerUp(e) {
			const p = this._pending;
			this._teardownPending();
			if (!p) return;
			if (p.dragging) {
				const target = this._updateDropFeedback(e.clientX, e.clientY);
				this._commitDrop(target);
				this._endDrag();
				this._draggedAt = Date.now(); // neutralizes the synthetic click after drag
			}
		},

		// pointercancel (gesture interrupted by the OS/browser): cancel WITHOUT
		// dropping the widget — unlike pointerup which commits the drop.
		_onPointerCancel() {
			const p = this._pending;
			this._teardownPending();
			if (p && p.dragging) {
				this._endDrag();
				this._draggedAt = Date.now();
			}
		},

		// Builds and inserts the floating ghost that follows the cursor.
		_makeGhost(label, iconHtml) {
			const g = document.createElement('div');
			g.className = 'dy-dashboard__drag-ghost';
			g.setAttribute('aria-hidden', 'true');
			g.innerHTML =
				'<span class="dy-dashboard__drag-ghost-grip"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg></span>'
				+ (iconHtml ? '<span class="dy-dashboard__drag-ghost-icon">' + iconHtml + '</span>' : '')
				+ '<span class="dy-dashboard__drag-ghost-label">' + Util.escapeHtml(label || '') + '</span>';
			document.body.appendChild(g);
			return g;
		},
		_moveGhost(x, y) {
			if (this._ghost) this._ghost.style.transform = 'translate3d(' + (x + 14) + 'px,' + (y + 16) + 'px,0)';
		},

		// Updates the drop visual feedback based on cursor position.
		// Returns {slotsEl, overSlot, refused, screenIdx} or null if off-target.
		_updateDropFeedback(x, y) {
			if (!state.drag) return null;
			const under   = document.elementFromPoint(x, y);
			const slotsEl = under ? under.closest('.dy-dashboard__screen[data-screen-kind="user"] .dy-dashboard__slots') : null;

			document.querySelectorAll('.dy-dashboard__slots.is-drop-target').forEach(s => {
				if (s !== slotsEl) s.classList.remove('is-drop-target', 'is-drop-refused');
			});
			document.querySelectorAll('.dy-dashboard__slot.is-drop-here').forEach(s => s.classList.remove('is-drop-here'));

			if (!slotsEl) {
				if (this._ghost) this._ghost.classList.add('is-refused');
				return null;
			}

			const screenEl  = slotsEl.closest('.dy-dashboard__screen');
			const screenIdx = parseInt(screenEl.dataset.screenIndex, 10);
			const used      = Util.computeUsedFromState(screenIdx);
			const sameRow   = (state.drag.kind === 'reorder' && state.drag.screenIndex === screenIdx);
			const effective = sameRow ? (used - state.drag.slots) : used;
			const refused   = (effective + state.drag.slots) > 6;

			slotsEl.classList.add('is-drop-target');
			slotsEl.classList.toggle('is-drop-refused', refused);
			if (this._ghost) this._ghost.classList.toggle('is-refused', refused);

			const overSlot = under ? under.closest('.dy-dashboard__slot') : null;
			if (overSlot && slotsEl.contains(overSlot) && !refused) overSlot.classList.add('is-drop-here');

			return { slotsEl: slotsEl, overSlot: overSlot, refused: refused, screenIdx: screenIdx };
		},

		_clearDropFeedback() {
			document.querySelectorAll('.dy-dashboard__slots.is-drop-target').forEach(s => s.classList.remove('is-drop-target', 'is-drop-refused'));
			document.querySelectorAll('.dy-dashboard__slot.is-drop-here').forEach(s => s.classList.remove('is-drop-here'));
		},

		_endDrag() {
			if (this._ghost) { this._ghost.remove(); this._ghost = null; }
			dom.root.classList.remove('dy-dashboard--dragging');
			document.body.classList.remove('dy-dashboard-dragging-cursor');
			document.querySelectorAll('.dy-dashboard__slot.is-dragging, .dy-dashboard__tile.is-dragging').forEach(n => n.classList.remove('is-dragging'));
			this._clearDropFeedback();
			state.drag = null;
		},

		_onDelete(e, slot) {
			e.preventDefault();
			e.stopPropagation();
			const screenEl  = slot.closest('.dy-dashboard__screen');
			const screenIdx = parseInt(screenEl.dataset.screenIndex, 10);
			const slotsEl   = screenEl.querySelector('.dy-dashboard__slots');
			const slotEls   = Array.from(slotsEl.querySelectorAll(':scope > .dy-dashboard__slot'));
			const widgetIdx = slotEls.indexOf(slot);
			if (widgetIdx < 0) return;

			const screen = state.snapshot.layout.screens[screenIdx];
			if (!screen) return;
			screen.widgets.splice(widgetIdx, 1);

			// If we delete the widget currently being configured, close the config
			// panel and return to the Customize view (smooth transition).
			if (Sidebar._selected && Sidebar._selected.slot === slot) {
				Sidebar.closeWidgetConfig();
			}

			// In-place DOM update (fade out + remove), then smooth height animation
			// on neighboring slots (which shrink if the tallest widget was removed).
			slot.style.transition = 'opacity 180ms var(--dya-ease, ease), transform 180ms var(--dya-ease, ease)';
			slot.style.opacity = '0';
			slot.style.transform = 'scale(.92)';
			setTimeout(() => {
				this._flipHeights(slotsEl, () => {
					slot.remove();
					this._updateSlotsUsed(slotsEl);
				});
			}, 200);

			Toast.show(t('widget_removed'), 'success');
			Sidebar._persistAndRehydrate('delete-widget');
		},

		_updateSlotsUsed(slotsEl) {
			let used = 0;
			slotsEl.querySelectorAll(':scope > .dy-dashboard__slot').forEach(s => {
				used += parseInt(s.dataset.slots, 10) || 1;
			});
			slotsEl.dataset.slotsUsed = String(used);
			// Re-inject free ghost cells (in flow within the grid). Only truly
			// free cells exist → no "is-occupied" logic or multi-row misalignment.
			// See #1.
			this._syncGhostCells(slotsEl);
		},
		// FLIP: smoothly animates the height of slots in a row when a
		// mutation (`fn`) changes the row height (adding/removing a widget
		// taller than the others). Stretched slots (grid stretch) are set to a
		// fixed height for the duration of the transition, then cleaned up.
		_flipHeights(slotsEl, fn) {
			const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			if (reduced || !slotsEl) { fn(); return; }
			const before = new Map();
			slotsEl.querySelectorAll(':scope > .dy-dashboard__slot').forEach(s => before.set(s, s.offsetHeight));
			fn();
			slotsEl.querySelectorAll(':scope > .dy-dashboard__slot').forEach(s => {
				const h0 = before.get(s);
				if (h0 == null) return; // new slot → no height animation
				const h1 = s.offsetHeight;
				if (Math.abs(h0 - h1) < 2) return;
				s.style.transition = 'none';
				s.style.height = h0 + 'px';
				void s.offsetHeight; // reflow to lock in the start position
				s.style.transition = 'height 360ms var(--dya-ease, ease)';
				s.style.height = h1 + 'px';
				const clear = () => {
					s.style.transition = '';
					s.style.height = '';
					s.removeEventListener('transitionend', clear);
				};
				s.addEventListener('transitionend', clear);
				setTimeout(clear, 440);
			});
		},
		bindTiles() {
			if (!dom.sidebar) return;
			dom.sidebar.querySelectorAll('[data-tile-id]').forEach(tile => {
				this._bindPointerSource(tile, 'tile', () => {
					const iconEl = tile.querySelector('.dy-dashboard__tile-icon');
					const nameEl = tile.querySelector('.dy-dashboard__tile-name');
					return {
						el: tile,
						state: {
							id: tile.dataset.tileId,
							origin: tile.dataset.tileOrigin,
							slots: parseInt(tile.dataset.tileSlots, 10) || 2,
							instanceable: tile.dataset.tileInstanceable === '1'
						},
						label: (nameEl && nameEl.textContent.trim()) || '',
						icon: (iconEl && iconEl.innerHTML) || ''
					};
				});
				// Robust fallback: a click adds the widget at the end of the current
				// screen's row (if space allows).
				tile.addEventListener('click', () => DragDrop._onTileClick(tile));
				tile.addEventListener('keydown', (e) => {
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						DragDrop._onTileClick(tile);
					}
				});
			});
		},
		_onTileClick(tile) {
			// Ignore the click fired immediately after a drag (some browsers).
			if (this._draggedAt && (Date.now() - this._draggedAt) < 300) return;
			const slotsEl = this._currentScreenSlotsEl();
			if (!slotsEl) {
				Toast.show(t('drop_impossible'), 'error');
				return;
			}
			const ok = this._insertWidget(slotsEl, {
				id: tile.dataset.tileId,
				slots: parseInt(tile.dataset.tileSlots, 10) || 2,
				instanceable: tile.dataset.tileInstanceable === '1'
			}, Infinity);
			// Brief visual feedback on the clicked tile.
			if (ok) {
				tile.classList.add('is-added');
				setTimeout(() => tile.classList.remove('is-added'), 360);
			}
		},
		// Commits the current drop (called on pointerup). `target` comes from
		// _updateDropFeedback: {slotsEl, overSlot, refused, screenIdx} or null.
		_commitDrop(target) {
			if (!state.drag) return;
			if (!target) return; // released outside a row → silent cancel
			if (target.refused) {
				Toast.show(state.drag.kind === 'reorder' ? t('no_room_target') : t('no_room_screen'), 'warn');
				return;
			}

			const slotsEl   = target.slotsEl;
			const screenIdx = target.screenIdx;
			if (isNaN(screenIdx) || screenIdx >= state.snapshot.layout.screens.length) {
				Toast.show(t('drop_impossible'), 'error');
				return;
			}
			const screen = state.snapshot.layout.screens[screenIdx];

			const overSlot = target.overSlot;
			const slotEls  = Array.from(slotsEl.querySelectorAll(':scope > .dy-dashboard__slot'));
			let targetIdx  = overSlot ? slotEls.indexOf(overSlot) : slotEls.length;
			if (targetIdx < 0) targetIdx = slotEls.length;

			// ── REORDER mode ─────────────────────────────────────
			if (state.drag.kind === 'reorder') {
				const sourceScreen = state.snapshot.layout.screens[state.drag.screenIndex];
				if (!sourceScreen) return;
				const sameScreen = state.drag.screenIndex === screenIdx;

				// Check available slots (cross-screen).
				const moved = sourceScreen.widgets[state.drag.widgetIndex];
				if (!sameScreen) {
					const used = screen.widgets.reduce((s, w) => s + (parseInt(w.slots, 10) || 1), 0);
					if (used + (parseInt(moved.slots, 10) || 1) > 6) {
						Toast.show(t('no_room_target'), 'warn');
						return;
					}
				}

				// State mutation.
				sourceScreen.widgets.splice(state.drag.widgetIndex, 1);
				let insertIdx = targetIdx;
				if (sameScreen && state.drag.widgetIndex < targetIdx) insertIdx -= 1;
				screen.widgets.splice(insertIdx, 0, moved);

				// DOM mutation: move the existing slot to the new position.
				const movedEl = slotEls[ sameScreen ? state.drag.widgetIndex : -1 ];
				const sourceSlotsEl = sameScreen
					? slotsEl
					: dom.root.querySelector('.dy-dashboard__screen[data-screen-index="' + state.drag.screenIndex + '"] .dy-dashboard__slots');
				const sourceSlotEls = Array.from(sourceSlotsEl.querySelectorAll(':scope > .dy-dashboard__slot'));
				const moveEl = sourceSlotEls[state.drag.widgetIndex];

				if (moveEl) {
					const refEl = slotsEl.querySelectorAll(':scope > .dy-dashboard__slot')[insertIdx] || null;
					slotsEl.insertBefore(moveEl, refEl);
					this._updateSlotsUsed(slotsEl);
					if (!sameScreen) this._updateSlotsUsed(sourceSlotsEl);
				}

				Toast.show(t('position_updated'), 'success');
				Sidebar._persistAndRehydrate('reorder-widget');
				return;
			}

			// ── ADD mode (from the palette) ─────────────────────
			this._insertWidget(slotsEl, {
				id: state.drag.id,
				slots: state.drag.slots,
				instanceable: state.drag.instanceable
			}, targetIdx);
		},

		// Inserts a widget into a row of slots at the given index (or at the end
		// if targetIdx >= slot count). State + DOM mutation + persistence.
		// Shared between drop (drag & drop) and click-to-add.
		// Returns true if inserted, false if refused (no room).
		_insertWidget(slotsEl, tileData, targetIdx) {
			const screenEl  = slotsEl.closest('.dy-dashboard__screen');
			const screenIdx = parseInt(screenEl.dataset.screenIndex, 10);
			if (isNaN(screenIdx) || screenIdx >= state.snapshot.layout.screens.length) {
				Toast.show(t('drop_impossible'), 'error');
				return false;
			}
			const screen = state.snapshot.layout.screens[screenIdx];
			const slots  = parseInt(tileData.slots, 10) || 1;

			const used = screen.widgets.reduce((s, w) => s + (parseInt(w.slots, 10) || 1), 0);
			if (used + slots > 6) {
				Toast.show(t('no_room_screen'), 'warn');
				return false;
			}

			const slotCount = slotsEl.querySelectorAll(':scope > .dy-dashboard__slot').length;
			if (typeof targetIdx !== 'number' || targetIdx < 0 || targetIdx > slotCount) {
				targetIdx = slotCount;
			}

			const newWidget = { id: tileData.id, slots: slots, config: {} };
			if (tileData.instanceable) {
				newWidget.instance = tileData.id + '-' + Math.random().toString(36).slice(2, 8);
			}
			screen.widgets.splice(targetIdx, 0, newWidget);

			// Create an animated empty placeholder while fetching the HTML.
			const placeholder = this._buildSlotShell(newWidget, true);
			const refEl = slotsEl.querySelectorAll(':scope > .dy-dashboard__slot')[targetIdx] || null;
			slotsEl.insertBefore(placeholder, refEl);
			this._updateSlotsUsed(slotsEl);

			// Fetch server-rendered HTML then inject it.
			Api.renderWidget(newWidget.id, newWidget.config, newWidget.instance || null)
				.then(res => {
					this._flipHeights(slotsEl, () => {
						placeholder.classList.remove('is-loading');
						placeholder.querySelectorAll(':scope > .dy-dashboard__slot-skeleton').forEach(n => n.remove());
						placeholder.insertAdjacentHTML('afterbegin', res.html);
					});
					DragDrop._attachEditableAffordances(placeholder);
					WidgetFX.track(placeholder);
					NoteEdit.enhance(placeholder);
				})
				.catch(err => {
					placeholder.remove();
					this._updateSlotsUsed(slotsEl);
					Toast.show(err.message || t('render_error'), 'error');
				});

			Toast.show(t('widget_added'), 'success');
			Sidebar._persistAndRehydrate('drop-widget');
			return true;
		},

		// Slots row for the active screen (null if native screen or out of bounds).
		_currentScreenSlotsEl() {
			const idx = state.activeScreenIndex;
			if (idx >= state.snapshot.layout.screens.length) return null; // native screen
			const screenEl = dom.root.querySelector(
				'.dy-dashboard__screen[data-screen-kind="user"][data-screen-index="' + idx + '"]'
			);
			return screenEl ? screenEl.querySelector('.dy-dashboard__slots') : null;
		},

		// Builds the `.dy-dashboard__slot` shell for a given widget. If
		// `loading` is true, includes a skeleton and the `is-loading` class.
		_buildSlotShell(widget, loading) {
			const slot = document.createElement('div');
			slot.className = 'dy-dashboard__slot dy-dashboard__slot--' + (parseInt(widget.slots, 10) || 1)
				+ (loading ? ' is-loading' : '');
			slot.dataset.widgetId = widget.id;
			slot.dataset.instance = widget.instance || widget.id;
			slot.dataset.slots    = String(parseInt(widget.slots, 10) || 1);
			if (loading) {
				slot.innerHTML = '<div class="dy-dashboard__slot-skeleton" aria-hidden="true"></div>';
			}
			return slot;
		},

		// Attaches handle + delete button to an existing slot if edit mode is active.
		_attachEditableAffordances(slot) {
			if (!dom.root.classList.contains('dy-dashboard--editable')) return;
			slot.classList.toggle('is-configurable', Sidebar.isConfigurable(slot.dataset.widgetId));
			if (slot.querySelector(':scope > .dy-dashboard__slot-handle')) return; // already attached

			const handle = document.createElement('span');
			handle.className = 'dy-dashboard__slot-handle';
			handle.setAttribute('aria-hidden', 'true');
			handle.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>';
			slot.appendChild(handle);

			const del = document.createElement('button');
			del.type = 'button';
			del.className = 'dy-dashboard__slot-delete';
			del.setAttribute('aria-label', t('delete_widget'));
			del.innerHTML = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>';
			del.addEventListener('click', (e) => this._onDelete(e, slot));
			slot.appendChild(del);

			this._bindSlotPointer(slot);
		}
	};

	// ============================================================
	// WIDGET FX — cascading reveal on scroll + count-up for numbers.
	// Progressive enhancement: without JS, content is visible normally
	// (CSS only hides under `.js-fx`). Respects prefers-reduced-motion.
	// ============================================================
	const WidgetFX = {
		reduced: false,
		io: null,
		init() {
			if (!dom.root) return;
			this.reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			const slots = dom.root.querySelectorAll('.dy-dashboard__screen[data-screen-kind="user"] .dy-dashboard__slot');
			if (this.reduced || !('IntersectionObserver' in window)) {
				slots.forEach(s => s.classList.add('is-revealed'));
				return;
			}
			this.io = new IntersectionObserver((entries) => {
				entries.forEach(e => {
					if (e.isIntersecting) {
						this.reveal(e.target);
						this.io.unobserve(e.target);
					}
				});
			}, { root: dom.viewport || null, threshold: 0.2 });
			// The screen on display has already been brought in by the CSS
			// entrance (.dy-dashboard__slots fade-up), which starts at first
			// paint, long before this footer script runs. Hiding its cards
			// again with .js-fx would play their entrance a second time:
			// they are marked as revealed and left alone. The cascade stays
			// for the other screens, revealed when they are swiped into view.
			const shown = dom.screens[state.activeScreenIndex] || null;
			slots.forEach(s => {
				if (shown && shown.contains(s)) { s.classList.add('is-revealed'); return; }
				s.classList.add('js-fx');
				this.io.observe(s);
			});
		},
		// Reveals a slot (CSS cascade via .is-revealed) + triggers count-ups.
		reveal(slot) {
			if (!slot || slot.classList.contains('is-revealed')) return;
			slot.classList.add('is-revealed');
			this.countUp(slot);
		},
		// For a slot injected on the fly (drag/click): already visible → reveal immediately.
		track(slot) {
			if (!slot) return;
			if (this.reduced || !this.io) { slot.classList.add('is-revealed'); this.countUp(slot); return; }
			slot.classList.add('js-fx');
			this.reveal(slot);
		},
		countUp(slot) {
			if (this.reduced) return;
			slot.querySelectorAll('.dy-dashboard__big-num, .dy-dashboard__row-v--num').forEach(el => this._count(el));
		},
		_count(el) {
			if (el.dataset.counted) return;
			const unit = el.querySelector('.dy-dashboard__big-num-unit');
			const unitHTML = unit ? unit.outerHTML : '';
			// Text without the unit sub-element: isolate the FIRST number group
			// for animation, PRESERVING everything surrounding it (prefix
			// "$", suffix "€", spaces). Otherwise the currency symbol on
			// revenue/sales amounts was lost after the count-up.
			const baseText = unit
				? (el.textContent || '').replace(unit.textContent || '', '')
				: (el.textContent || '');
			const m = baseText.match(/[\d  .,\s]*\d/); // first number (separators included)
			if (!m) return;
			const numStr = m[0];
			const target = parseInt(numStr.replace(/[^\d-]/g, ''), 10);
			if (!isFinite(target) || target <= 0 || target > 1000000) return;
			const prefix = baseText.slice(0, m.index);
			const suffix = baseText.slice(m.index + numStr.length);
			// Group thousands as in the original value (thin space if present).
			const groupSep = /[  ]/.test(numStr) ? ' ' : (numStr.indexOf(' ') >= 0 ? ' ' : '');
			const fmt = (n) => {
				if (!groupSep) return String(n);
				return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, groupSep);
			};
			el.dataset.counted = '1';
			const dur = 720;
			const start = performance.now();
			const ease = (x) => 1 - Math.pow(1 - x, 3);
			const tick = (now) => {
				const p = Math.min(1, (now - start) / dur);
				const val = Math.round(target * ease(p));
				const numHTML = prefix + fmt(val) + suffix;
				if (unitHTML) { el.innerHTML = numHTML + unitHTML; } else { el.textContent = numHTML; }
				if (p < 1) requestAnimationFrame(tick);
			};
			requestAnimationFrame(tick);
		}
	};

	// ============================================================
	// NOTE EDIT — the Note is edited DIRECTLY by clicking on it (title +
	// body in place), without going through the Customize panel. Reserved for
	// users who can customize. Silent save on blur.
	// ============================================================
	const NoteEdit = {
		init() {
			if (!state.canCustomize || !dom.root) return;
			dom.root.querySelectorAll('.dy-dashboard__screen[data-screen-kind="user"] .dy-dashboard__slot[data-widget-id="note"]')
				.forEach(slot => this.enhance(slot));
		},
		enhance(slot) {
			if (!state.canCustomize || !slot || slot.dataset.widgetId !== 'note') return;
			if (slot.dataset.noteEnhanced) return;
			slot.dataset.noteEnhanced = '1';

			const titleEl = slot.querySelector('.dy-dashboard__card-title');
			let bodyEl    = slot.querySelector('.dy-dashboard__note-content');
			// Empty state → replace the placeholder with an editable area.
			if (!bodyEl) {
				const wrap = slot.querySelector('.dy-dashboard__note-body') || slot.querySelector('.dy-dashboard__card-body');
				if (wrap) {
					wrap.innerHTML = '<div class="dy-dashboard__note-content"></div>';
					bodyEl = wrap.querySelector('.dy-dashboard__note-content');
				}
			}
			const mk = (el, ph) => {
				if (!el) return;
				el.setAttribute('contenteditable', 'true');
				el.classList.add('dy-dashboard__note-edit');
				el.setAttribute('role', 'textbox');
				// Its placeholder is its name for screen readers (audit, 21/10/2026).
				el.setAttribute('aria-label', ph);
				el.dataset.placeholder = ph;
			};
			mk(titleEl, t('note_title_ph'));
			mk(bodyEl, t('note_body_ph'));

			const save = Util.debounce(() => this.save(slot), 500);
			[titleEl, bodyEl].forEach(el => {
				if (!el) return;
				el.addEventListener('input', save);
				el.addEventListener('blur', () => this.save(slot));
			});
			// Enter in the title → move to the body (no line break).
			if (titleEl) titleEl.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') { e.preventDefault(); if (bodyEl) bodyEl.focus(); }
			});
			// The pointer-based drag already ignores pointerdown events on a
			// contenteditable field (see DragDrop._onPointerDown), so the Note
			// caret stays usable without tweaking the draggable attribute.
		},
		_locate(slot) {
			const screenEl = slot.closest('.dy-dashboard__screen');
			if (!screenEl) return null;
			const s = parseInt(screenEl.dataset.screenIndex, 10);
			const slotsEl = screenEl.querySelector('.dy-dashboard__slots');
			const w = Array.from(slotsEl.querySelectorAll(':scope > .dy-dashboard__slot')).indexOf(slot);
			if (isNaN(s) || w < 0) return null;
			return { s: s, w: w };
		},
		save(slot) {
			const loc = this._locate(slot);
			if (!loc) return;
			const screen = state.snapshot.layout.screens[loc.s];
			const widget = screen && screen.widgets[loc.w];
			if (!widget || widget.id !== 'note') return;
			const titleEl = slot.querySelector('.dy-dashboard__card-title');
			const bodyEl  = slot.querySelector('.dy-dashboard__note-content');
			const title = titleEl ? titleEl.textContent.trim() : '';
			const body  = bodyEl ? bodyEl.innerText.replace(/\n{3,}/g, '\n\n').replace(/\s+$/,'') : '';
			widget.config = widget.config || {};
			if (widget.config.title === title && widget.config.body === body) return; // nothing to save
			widget.config.title = title;
			widget.config.body  = body;
			state.snapshot.layout.preset = 'custom';
			const noteScreenId = screen ? (screen.id || null) : null;
			Api.saveLayout(state.snapshot.layout).then((layout) => {
				if (layout) state.snapshot.layout = layout;
				if (Sidebar._presetsRoot) Sidebar._renderDispositions();
				// #6 — Note edit in history, labeled with its screen.
				if (typeof History !== 'undefined') History.record(noteScreenId);
			}).catch(err => Toast.show(err.message || t('save_failed'), 'error'));
		}
	};

	// ============================================================
	// #3 — INLINE TITLE / PARAGRAPH EDITING (panel open)
	// ============================================================
	// Makes the current screen's title and paragraph directly editable on the
	// dashboard when the Customize panel is open. Coexists with the panel fields
	// (synchronized in both directions). During editing the text is RAW
	// (no <em> accent / markdown); the accent is re-applied on teardown
	// (screen change / panel close).
	const ScreenTextEdit = {
		// Enables/disables inline editing based on panel state. Called on
		// panel open/close and on each active screen change.
		refresh() {
			if (!dom.root) return;
			const editable = state.sidebarOpen && state.canCustomize && !state.snapshot.layout.lock;
			// Teardown screens that should no longer be editable (re-accent).
			dom.root.querySelectorAll('.dy-dashboard__screen[data-screen-kind="user"]').forEach(screenEl => {
				const idx = parseInt(screenEl.dataset.screenIndex, 10);
				const hasCe = screenEl.querySelector('.dy-dashboard__title[contenteditable], .dy-dashboard__paragraph[contenteditable]');
				if (hasCe && !(editable && idx === state.activeScreenIndex)) this._teardown(screenEl, idx);
			});
			if (!editable) return;
			const idx = state.activeScreenIndex;
			const screenEl = dom.root.querySelector('.dy-dashboard__screen[data-screen-index="' + idx + '"]');
			if (!screenEl || screenEl.dataset.screenKind === 'native') return;
			this.enhance(screenEl, idx);
		},
		enhance(screenEl, idx) {
			const screen   = state.snapshot.layout.screens[idx];
			const headerEl = screenEl.querySelector('.dy-dashboard__screen-header');
			if (!screen || !headerEl) return;

			// TITLE — raw editable text.
			let titleEl = headerEl.querySelector('.dy-dashboard__title');
			if (!titleEl) {
				titleEl = document.createElement('h2');
				titleEl.className = 'dy-dashboard__title';
				headerEl.appendChild(titleEl);
			}
			titleEl.classList.remove('dy-dashboard__title--placeholder');
			titleEl.removeAttribute('aria-hidden');
			// RAW text (with {{…}} tokens) — consistent with the panel field
			// and essential to avoid overwriting tokens on save.
			if (titleEl.getAttribute('contenteditable') !== 'true') {
				titleEl.textContent = screen.title || '';
			}
			this._mk(titleEl, t('screen_title_ph'));

			// PARAGRAPH — editable only when the paragraph is enabled; otherwise
			// remove any existing area (no ghost paragraph on the screen).
			let paraEl = headerEl.querySelector('.dy-dashboard__paragraph');
			if (screen.paragraph_enabled) {
				if (!paraEl) {
					paraEl = document.createElement('p');
					paraEl.className = 'dy-dashboard__paragraph';
					headerEl.appendChild(paraEl);
				}
				if (paraEl.getAttribute('contenteditable') !== 'true') {
					paraEl.textContent = screen.paragraph || '';
				}
				this._mk(paraEl, t('screen_paragraph_ph'));
				this._bind(paraEl, screenEl);
			} else if (paraEl) {
				paraEl.remove();
			}

			this._bind(titleEl, screenEl);
			if (titleEl.dataset.steKeys !== '1') {
				titleEl.dataset.steKeys = '1';
				titleEl.addEventListener('keydown', (e) => {
					if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); }
				});
			}
		},
		_mk(el, ph) {
			el.setAttribute('contenteditable', 'true');
			el.classList.add('dy-dashboard__inline-edit');
			el.setAttribute('role', 'textbox');
			el.setAttribute('aria-label', ph);
			el.dataset.placeholder = ph;
		},
		// Index resolved LIVE from the DOM (stable across reordering / deletion).
		_bind(el, screenEl) {
			if (!el || el.dataset.steBound === '1') return;
			el.dataset.steBound = '1';
			const save = Util.debounce(() => this.save(screenEl), 280);
			el.addEventListener('input', save);
			el.addEventListener('blur', () => this.save(screenEl));
		},
		_teardown(screenEl, idx) {
			const headerEl = screenEl.querySelector('.dy-dashboard__screen-header');
			if (!headerEl) return;
			[ '.dy-dashboard__title', '.dy-dashboard__paragraph' ].forEach(sel => {
				const el = headerEl.querySelector(sel);
				if (!el) return;
				el.removeAttribute('contenteditable');
				el.classList.remove('dy-dashboard__inline-edit');
				el.removeAttribute('role');
				delete el.dataset.placeholder;
			});
			this._renderHeader(screenEl, idx);
		},
		// Re-renders the header (accented title + markdown paragraph) of a given
		// screen from state — generic version of _updateActiveScreenHeaderDom.
		_renderHeader(screenEl, idx) {
			const screen   = state.snapshot.layout.screens[idx];
			const headerEl = screenEl.querySelector('.dy-dashboard__screen-header');
			if (!screen || !headerEl) return;

			let titleEl = headerEl.querySelector('.dy-dashboard__title');
			if (!titleEl) {
				titleEl = document.createElement('h2');
				titleEl.className = 'dy-dashboard__title';
				headerEl.appendChild(titleEl);
			}
			const resolvedTitle = Util.resolveTokens(screen.title || '');
			if (resolvedTitle === '') {
				titleEl.classList.add('dy-dashboard__title--placeholder');
				titleEl.setAttribute('aria-hidden', 'true');
				titleEl.innerHTML = '&nbsp;';
			} else {
				titleEl.classList.remove('dy-dashboard__title--placeholder');
				titleEl.removeAttribute('aria-hidden');
				titleEl.innerHTML = Util.wrapTitleAccent(resolvedTitle);
			}

			let paraEl = headerEl.querySelector('.dy-dashboard__paragraph');
			if (screen.paragraph_enabled && (screen.paragraph || '').trim() !== '') {
				if (!paraEl) {
					paraEl = document.createElement('p');
					paraEl.className = 'dy-dashboard__paragraph';
					headerEl.appendChild(paraEl);
				}
				paraEl.innerHTML = Util.lightMarkdown(Util.resolveTokens(screen.paragraph));
			} else if (paraEl) {
				paraEl.remove();
			}
		},
		save(screenEl) {
			const idx = parseInt(screenEl.dataset.screenIndex, 10);
			const screen = state.snapshot.layout.screens[idx];
			if (!screen) return;
			const headerEl = screenEl.querySelector('.dy-dashboard__screen-header');
			if (!headerEl) return;
			const titleEl = headerEl.querySelector('.dy-dashboard__title');
			const paraEl  = headerEl.querySelector('.dy-dashboard__paragraph');
			const newTitle = titleEl ? titleEl.textContent.trim() : '';
			const newPara  = paraEl ? paraEl.textContent.trim() : '';

			let changed = false;
			if ((screen.title || '') !== newTitle) { screen.title = newTitle; changed = true; }
			if (newPara !== '' && !screen.paragraph_enabled) { screen.paragraph_enabled = true; changed = true; }
			if ((screen.paragraph || '') !== newPara) { screen.paragraph = newPara; changed = true; }
			if (!changed) return;

			// Mirror → panel fields (current screen only).
			if (idx === state.activeScreenIndex && dom.sidebar) {
				const metaRoot = dom.sidebar.querySelector('[data-section="screen-meta"]');
				if (metaRoot) {
					const tf = metaRoot.querySelector('[data-field="title"]');
					const pf = metaRoot.querySelector('[data-field="paragraph"]');
					const cf = metaRoot.querySelector('[data-field="paragraph_enabled"]');
					if (tf) tf.value = newTitle;
					if (pf) pf.value = newPara;
					if (cf) cf.checked = !!screen.paragraph_enabled;
				}
			}
			Sidebar._persistAndRehydrate('screen-meta');
		}
	};

	// ============================================================
	// #6 — UNDO / REDO HISTORY (persisted in sessionStorage)
	// ============================================================
	// Stack of cloned `layout` snapshots, surviving reloads (F5) via
	// sessionStorage. undo/redo apply a snapshot WITHOUT reloading the page:
	// the server re-renders the screen HTML, the client reconciles the DOM and
	// SWIPES to the screen affected by the edit (or stays in place if the edit
	// touches the current screen or is global). "Heavy" flows
	// (apply/import/clear/reset a layout) reset the history.
	const History = {
		KEY: 'dy-dash-history',
		CAP: 50,
		_stack: [],   // [{ layout, screenId }] — screenId = screen affected by the edit
		_cursor: 0,
		_busy: false,
		// GLOBAL edit sources (no specific screen → stay in place).
		GLOBAL_SOURCES: [ 'lock', 'role', 'wp-native-toggle', 'screen-reorder-live' ],
		_clone(o) { return JSON.parse(JSON.stringify(o)); },
		init() {
			let restored = false;
			try {
				const raw = window.sessionStorage.getItem(this.KEY);
				if (raw) {
					const data = JSON.parse(raw);
					if (data && Array.isArray(data.stack) && data.stack.length) {
						this._stack = data.stack;
						this._cursor = Math.min(Math.max(0, data.cursor | 0), this._stack.length - 1);
						restored = true;
					}
				}
			} catch (e) { /* sessionStorage unavailable → in-memory history */ }
			if (!restored) {
				this._stack = [ { layout: this._clone(state.snapshot.layout), screenId: null } ];
				this._cursor = 0;
				this._persist();
			}
			this.updateButtons();
		},
		_persist() {
			try {
				window.sessionStorage.setItem(this.KEY, JSON.stringify({ stack: this._stack, cursor: this._cursor }));
			} catch (e) { /* quota / unavailable → keep history in memory */ }
		},
		// Id of the screen affected by an edit source. Global sources →
		// null. Structural ones pass an explicit id (see opts below).
		// Others → the active screen (where the edit is visible).
		screenIdForSource(source) {
			if (this.GLOBAL_SOURCES.indexOf(source) !== -1) return null;
			const s = state.snapshot.layout.screens[state.activeScreenIndex];
			return s ? (s.id || null) : null;
		},
		// Records a step. `screenId` = affected screen (or null = global).
		// The snapshot differs from the top ONLY if the layout changed → coalescing.
		record(screenId) {
			const snap = this._clone(state.snapshot.layout);
			if (JSON.stringify(snap) === JSON.stringify(this._stack[this._cursor].layout)) return;
			// Truncate the redo branch, then push.
			this._stack = this._stack.slice(0, this._cursor + 1);
			this._stack.push({ layout: snap, screenId: screenId === undefined ? null : screenId });
			// Cap the size (drop the oldest entries).
			if (this._stack.length > this.CAP) {
				this._stack = this._stack.slice(this._stack.length - this.CAP);
			}
			this._cursor = this._stack.length - 1;
			this._persist();
			this.updateButtons();
		},
		canUndo() { return this._cursor > 0; },
		canRedo() { return this._cursor < this._stack.length - 1; },
		// Resolves the screen to swipe to: screen `affId` in the target layout,
		// unless it is already the current screen (or absent / global) → null = stay.
		_swipeTarget(affId, targetLayout) {
			if (!affId) return null;
			const screens = (targetLayout && targetLayout.screens) || [];
			const idx = screens.findIndex(s => s.id === affId);
			if (idx < 0) return null;                       // screen not in target
			const cur = state.snapshot.layout.screens[state.activeScreenIndex];
			if (cur && cur.id === affId) return null;       // edit is on the current screen
			return idx;
		},
		_apply(affId) {
			if (this._busy) return;
			this._busy = true;
			const entry   = this._stack[this._cursor];
			const target  = this._clone(entry.layout);
			const swipeTo = this._swipeTarget(affId, target);
			this._persist();
			this.updateButtons();
			Api.restoreLayout(target)
				.then(res => {
					state.snapshot.layout = res.layout || target;
					// Re-render without reload + swipe to the affected screen.
					Nav.rerenderFromHtml(res.html, swipeTo);
					// Refresh panel fields (title/paragraph of the current screen).
					if (state.sidebarOpen) Sidebar._hydrateFromState();
					this.updateButtons();
				})
				.catch(err => Toast.show(err.message || t('error'), 'error'))
				.finally(() => { this._busy = false; });
		},
		undo() {
			if (this._busy || !this.canUndo()) return;
			// The affected screen is the one that produced the step we are leaving.
			const affId = this._stack[this._cursor].screenId;
			this._cursor--;
			this._apply(affId);
		},
		redo() {
			if (this._busy || !this.canRedo()) return;
			this._cursor++;
			// The affected screen is the one that produced the step being replayed.
			const affId = this._stack[this._cursor].screenId;
			this._apply(affId);
		},
		// "Heavy" flow: fresh history starting from the current layout.
		reset() {
			try {
				window.sessionStorage.removeItem(this.KEY);
			} catch (e) { /* noop */ }
			this._stack = [ { layout: this._clone(state.snapshot.layout), screenId: null } ];
			this._cursor = 0;
			this.updateButtons();
		},
		updateButtons() {
			if (!dom.sidebar) return;
			const u = dom.sidebar.querySelector('[data-action="undo"]');
			const r = dom.sidebar.querySelector('[data-action="redo"]');
			if (u) u.disabled = !this.canUndo();
			if (r) r.disabled = !this.canRedo();
		}
	};

	// ============================================================
	// BOOTSTRAP
	// ============================================================
	function init() {
		if (!state.snapshot) {
			console.warn('[dy-dashboard] No bootstrap data; aborting.');
			return;
		}
		state.canCustomize = !!(state.snapshot.caps && state.snapshot.caps.can_customize);

		dom.root = document.getElementById('dy-dashboard-root');
		if (!dom.root) return;

		// Lock the GLOBAL accent (DS token or client custom accent) in a dedicated
		// var BEFORE any per-screen override: the default gradient tint
		// ("Background color") references it, so it stays independent of the
		// per-screen accent applied later on the root.
		(function () {
			var ga = getComputedStyle(document.body).getPropertyValue('--dya-accent');
			if (ga && ga.trim()) dom.root.style.setProperty('--dy-global-accent', ga.trim());
		}());

		// Bfcache restore: when the user navigates to a settings page
		// (StaticSite, Toolkit…) then returns via the browser back button,
		// Chrome/Firefox restore the dashboard from the back/forward cache.
		// PHP does NOT run, the DOM is frozen as it was on the last visit →
		// stale data (static mode, counters, last export…). Force a reload to
		// get a fresh snapshot. event.persisted = true only for bfcache restores,
		// so no reload on regular navigation or F5.
		window.addEventListener('pageshow', (e) => {
			if (e.persisted) location.reload();
		});

		NoticesRelocator.init();

		// Runtime measurement of the topbar (#wpadminbar) and rail (#adminmenuwrap)
		// so the dashboard root is sized EXACTLY, regardless of the --dya-topbar-h token.
		const measureChrome = () => {
			const topbar = document.getElementById('wpadminbar');
			if (topbar) {
				const h = Math.round(topbar.getBoundingClientRect().height);
				if (h > 0) document.documentElement.style.setProperty('--dya-topbar-h', h + 'px');
			}
			const rail = document.getElementById('adminmenuwrap');
			if (rail) {
				const w = Math.round(rail.getBoundingClientRect().width);
				if (w > 0) document.documentElement.style.setProperty('--dya-rail-w-current', w + 'px');
			}
		};
		measureChrome();
		window.addEventListener('resize', Util.debounce(measureChrome, 100));
		// If the rail expands/collapses (CSS animation), re-measure afterward.
		const railEl = document.getElementById('adminmenuwrap');
		if (railEl && 'ResizeObserver' in window) {
			new ResizeObserver(measureChrome).observe(railEl);
		}

		dom.viewport     = dom.root.querySelector('.dy-dashboard__viewport');
		dom.screens      = Array.from(dom.root.querySelectorAll('.dy-dashboard__screen'));
		dom.dots         = Array.from(dom.root.querySelectorAll('.dy-dashboard__nav-dot'));
		dom.customizeBtn = dom.root.querySelector('.dy-dashboard__customize-btn');
		dom.navArrows.prev = dom.root.querySelector('.dy-dashboard__nav-arrow--prev');
		dom.navArrows.next = dom.root.querySelector('.dy-dashboard__nav-arrow--next');

		Nav.init();
		NativeScrollbar.init();
		NativeMasonry.init();
		WidgetFX.init();
		NoteEdit.init();

		// "Run a full backup" button (Backup widget): POST to the admin-ajax
		// action the Backups module declared (data-action). Delegated + outside
		// edit mode (the widget is not interactive when the Customize panel is open).
		dom.root.addEventListener('click', (e) => {
			const btn = e.target.closest('[data-dy-run-backup]');
			if (!btn || btn.disabled || !btn.dataset.action) return;
			e.preventDefault();
			const body = new URLSearchParams();
			body.set('action', btn.dataset.action);
			body.set('type', 'full');
			body.set('nonce', btn.dataset.nonce || '');
			btn.disabled = true;
			btn.classList.add('is-busy');
			fetch(btn.dataset.ajax, { method: 'POST', credentials: 'same-origin', body: body })
				.then(r => r.json())
				.then(json => {
					if (json && json.success) {
						Toast.show(t('backup_started'), 'success');
					} else {
						throw new Error((json && json.data && json.data.message) || t('backup_failed'));
					}
				})
				.catch(err => Toast.show(err.message || t('backup_failed'), 'error'))
				.finally(() => { btn.disabled = false; btn.classList.remove('is-busy'); });
		});

		// Sidebar / drag&drop: enabled only if capability + viewport ≥1300.
		const viewportW = window.innerWidth;
		const breakMin  = (state.snapshot.breakpoints && state.snapshot.breakpoints.customize_min_px) || 1300;
		if (state.canCustomize && viewportW >= breakMin) {
			Sidebar.inject();
			DragDrop.init();
			History.init(); // #6 — restores history + reopens the panel after undo
			if (dom.customizeBtn) {
				dom.customizeBtn.addEventListener('click', () => Sidebar.toggle());
			}

			// #6 — Undo / Redo keyboard shortcuts. NOT captured when focus
			// is in an editable field (native text undo takes over).
			document.addEventListener('keydown', (e) => {
				const mod = e.metaKey || e.ctrlKey;
				if (!mod) return;
				const k = e.key.toLowerCase();
				if (k !== 'z' && k !== 'y') return;
				const el = document.activeElement;
				if (el && (el.isContentEditable || el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT')) return;
				// Redo: Cmd/Ctrl+Y OR Cmd/Ctrl+Shift+Z. Undo: Cmd/Ctrl+Z.
				if (k === 'y' || (k === 'z' && e.shiftKey)) { e.preventDefault(); History.redo(); }
				else if (k === 'z') { e.preventDefault(); History.undo(); }
			});
			// In edit mode (Customize open), clicking a widget does NOT
			// trigger its action. Capture phase to beat <a>/button handlers.
			//   - Note                → inline editing (focus goes to the field).
			//   - Configurable widget → switch the panel to its settings.
			//   - Non-configurable widget → nothing (action blocked).
			dom.root.addEventListener('click', (e) => {
				if (!dom.root.classList.contains('dy-dashboard--editable')) return;
				// Neutralize the synthetic click fired just after a pointer drag
				// (otherwise releasing a widget would reopen its config by mistake).
				if (DragDrop._draggedAt && (Date.now() - DragDrop._draggedAt) < 300) {
					e.preventDefault(); e.stopPropagation(); return;
				}
				const slot = e.target.closest('.dy-dashboard__screen[data-screen-kind="user"] .dy-dashboard__slot');
				if (!slot) return;
				// Let edit affordances through (handle, delete).
				if (e.target.closest('.dy-dashboard__slot-handle, .dy-dashboard__slot-delete')) return;
				// The Note is edited in place: do not intercept (the field gets focus).
				if (slot.dataset.widgetId === 'note') return;
				e.preventDefault();
				e.stopPropagation();
				if (Sidebar.isConfigurable(slot.dataset.widgetId)) {
					Sidebar.selectWidget(slot);
				}
				// otherwise: nothing (the widget's action is simply blocked).
			}, true);
		} else if (dom.customizeBtn) {
			dom.customizeBtn.setAttribute('hidden', '');
		}

		// Listen for window resize to refresh sidebar visibility + indicator.
		window.addEventListener('resize', Util.debounce(() => {
			const w = window.innerWidth;
			if (w < breakMin && state.sidebarOpen) Sidebar.close();
			if (dom.customizeBtn) {
				if (w >= breakMin && state.canCustomize) dom.customizeBtn.removeAttribute('hidden');
				else dom.customizeBtn.setAttribute('hidden', '');
			}
			Worm.reposition();
		}, 160));

		// Expose public API for debugging + integrations.
		window.doubleyoupiDashboard = {
			version: 'v4',
			state: state,
			dom: dom,
			Nav: Nav,
			Ambient: Ambient,
			Worm: Worm,
			Sidebar: Sidebar,
			DragDrop: DragDrop,
			WidgetFX: WidgetFX,
			NoteEdit: NoteEdit,
			ScreenTextEdit: ScreenTextEdit,
			History: History,
			Api: Api
		};
	}

	// WordPress Events and News, on the native screen. WordPress starts it
	// only when `#dashboard_primary` is visible (wp-admin/js/dashboard.js),
	// an id our card does not carry on purpose (see Renderer.php): the events
	// never came and the news stayed on "Loading…" (compatibility lab,
	// 24/10/2026). Started here, after WordPress's own ready handler.
	function wakeWordPressNews() {
		const box = document.querySelector('.dy-dashboard__native-item[data-native-id="dashboard_primary"]');
		const $ = window.jQuery;
		if (!box || !$) return;
		$(function () {
			setTimeout(function () {
				const events = window.wp && window.wp.communityEvents;
				if (events && !events.initialized && typeof events.init === 'function') {
					try { events.init(); } catch (e) { /* WordPress's widget keeps its own error message */ }
				}
				const loading = box.querySelector('.widget-loading');
				if (loading && loading.parentElement && window.ajaxurl) {
					$(loading.parentElement).load(window.ajaxurl + '?action=dashboard-widgets&widget=dashboard_primary&pagenow=' + encodeURIComponent(window.pagenow || 'dashboard'));
				}
			}, 0);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
		document.addEventListener('DOMContentLoaded', wakeWordPressNews);
	} else {
		init();
		wakeWordPressNews();
	}
}());

/**
 * Module Admin: settings page, General tab.
 *
 *  1. Accent color picker: curated swatches, WP Iris and an OKLCH validator
 *     that suggests the closest acceptable color.
 *  2. wp.media frame for the custom-logo image picker.
 *  3. Auto-save of every control (AJAX), with a live preview on the rail:
 *     the accent, the accent on every line and the logo repaint at once,
 *     and the entries whose colour changed shimmer. The tree and the
 *     flyouts are drawn by the server, so those two reload the page.
 *
 * VALIDATION — L + C model (lightness + chroma) in OKLCH :
 *
 *   - Highly saturated vivid colors (electric mint #00ffa7, neon cyan
 *     #00e1ff, gold #ffc700…) : ACCEPTED. They have high lightness
 *     but strong chroma (C > 0.05) that gives them a clear chromatic
 *     identity even on tinted surfaces.
 *   - Pastels and washes close to white (#f0f0f0, #efe8d8…) : REJECTED.
 *     Low chroma + high lightness → collapse into flat surfaces
 *     on light backgrounds.
 *   - Dark grays close to black (#111, #222…) : REJECTED. Low
 *     chroma + low lightness → indistinguishable from dark paper tones.
 *   - Pure whites/blacks (L < 0.10 or L > 0.95) : REJECTED regardless
 *     of C — beyond these extremes, even strong chroma cannot
 *     compensate for the loss of contrast.
 *
 * Why this logic: pure L validation (single-axis) wrongly rejected
 * vivid but bright colors like #ffc700 even though they retain a very
 * strong chromatic identity. The combined condition (extreme L AND low C)
 * precisely catches pastels/washes/grays without collateral on neons
 * and vivid colors.
 */
(function ($) {
	'use strict';

	// ── Constants ─────────────────────────────────────────────

	// HARD chroma-compensated bounds :
	//   the more saturated the color, the wider the L range becomes.
	//   L_eff = L_BASE ± min(C, 0.25) * BONUS
	//
	//   At C=0    : range [0.45, 0.85] (tight neutral pastels)
	//   At C=0.10 : range [0.41, 0.89] (mid-saturation)
	//   At C=0.20 : range [0.37, 0.93] (vivid mint #00ffa7 L=0.88 → ACCEPT)
	//
	// Why this compensation: an electric mint at L=0.88 retains a strong
	// chromatic identity despite its brightness — chroma "carries" the color.
	// Without saturation, the same L shifts to a washed-out gray.
	const L_HARD_MIN_BASE = 0.45;
	const L_HARD_MAX_BASE = 0.85;
	const CHROMA_BONUS    = 0.40;
	const CHROMA_BONUS_CAP = 0.25;

	// SOFT bounds (applied ONLY when C < CHROMA_THRESHOLD,
	// i.e. the color is grayish). Tighter than HARD : without
	// saturation, contrast on DS tints collapses faster.
	const L_SOFT_MIN = 0.55;
	const L_SOFT_MAX = 0.78;

	// Chroma threshold above which the color is considered "vivid"
	// (has a chromatic identity). In OKLCH, ~0.05 separates true
	// grays from colors with a perceptible hue.
	const CHROMA_THRESHOLD = 0.05;

	const HEX_RE = /^#[0-9a-fA-F]{6}$/;

	// ── OKLCH conversion (hex ↔ OKLCH) ────────────────────────
	// Reference : https://bottosson.github.io/posts/oklab/

	function hexToRgb (hex) {
		const h = hex.replace('#', '');
		return {
			r: parseInt(h.slice(0, 2), 16) / 255,
			g: parseInt(h.slice(2, 4), 16) / 255,
			b: parseInt(h.slice(4, 6), 16) / 255,
		};
	}

	function srgbToLinear (c) {
		return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
	}

	function linearToSrgb (c) {
		return c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(c, 1 / 2.4) - 0.055;
	}

	function linearToOklab (rgb) {
		const l = 0.4122214708 * rgb.r + 0.5363325363 * rgb.g + 0.0514459929 * rgb.b;
		const m = 0.2119034982 * rgb.r + 0.6806995451 * rgb.g + 0.1073969566 * rgb.b;
		const s = 0.0883024619 * rgb.r + 0.2817188376 * rgb.g + 0.6299787005 * rgb.b;
		const l_ = Math.cbrt(l), m_ = Math.cbrt(m), s_ = Math.cbrt(s);
		return {
			L: 0.2104542553 * l_ + 0.7936177850 * m_ - 0.0040720468 * s_,
			a: 1.9779984951 * l_ - 2.4285922050 * m_ + 0.4505937099 * s_,
			b: 0.0259040371 * l_ + 0.7827717662 * m_ - 0.8086757660 * s_,
		};
	}

	function oklabToLinear (lab) {
		const l_ = lab.L + 0.3963377774 * lab.a + 0.2158037573 * lab.b;
		const m_ = lab.L - 0.1055613458 * lab.a - 0.0638541728 * lab.b;
		const s_ = lab.L - 0.0894841775 * lab.a - 1.2914855480 * lab.b;
		const l = l_ * l_ * l_, m = m_ * m_ * m_, s = s_ * s_ * s_;
		return {
			r:  4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
			g: -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
			b: -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s,
		};
	}

	function hexToOklch (hex) {
		const rgb = hexToRgb(hex);
		const lin = { r: srgbToLinear(rgb.r), g: srgbToLinear(rgb.g), b: srgbToLinear(rgb.b) };
		const lab = linearToOklab(lin);
		return {
			L: lab.L,
			C: Math.sqrt(lab.a * lab.a + lab.b * lab.b),
			H: Math.atan2(lab.b, lab.a) * 180 / Math.PI,
		};
	}

	function oklchToHex (lch) {
		const rad = lch.H * Math.PI / 180;
		const lab = { L: lch.L, a: lch.C * Math.cos(rad), b: lch.C * Math.sin(rad) };
		const lin = oklabToLinear(lab);
		const rgb = {
			r: linearToSrgb(lin.r),
			g: linearToSrgb(lin.g),
			b: linearToSrgb(lin.b),
		};
		const clamp = v => Math.max(0, Math.min(1, v));
		const to = v => Math.round(clamp(v) * 255).toString(16).padStart(2, '0');
		return '#' + to(rgb.r) + to(rgb.g) + to(rgb.b);
	}

	// ── Validation ────────────────────────────────────────────
	// Same bounds as Settings::accent_problem(), which refuses the same colors
	// on save. The messages come translated from PHP (doubleyoupiAdminSettings).

	const CFG  = window.doubleyoupiAdminSettings || { i18n: {} };
	const t    = key => (CFG.i18n && CFG.i18n[key]) || '';

	function validateAccent (hex) {
		if (!HEX_RE.test(hex)) {
			return { valid: false, message: t('format') };
		}
		const lch = hexToOklch(hex);

		// Chroma compensation → effective L range.
		const cBonus  = Math.min(lch.C, CHROMA_BONUS_CAP) * CHROMA_BONUS;
		const lMinEff = L_HARD_MIN_BASE - cBonus;
		const lMaxEff = L_HARD_MAX_BASE + cBonus;

		if (lch.L < lMinEff) {
			return { valid: false, message: t('tooDark'), suggestion: oklchToHex({ L: lMinEff, C: Math.max(lch.C, 0.15), H: lch.H }) };
		}
		if (lch.L > lMaxEff) {
			return { valid: false, message: t('tooLight'), suggestion: oklchToHex({ L: lMaxEff, C: Math.max(lch.C, 0.15), H: lch.H }) };
		}
		// Low-chroma colors (grayscale-ish) get the tighter SOFT bounds.
		if (lch.C < CHROMA_THRESHOLD) {
			if (lch.L < L_SOFT_MIN) {
				return { valid: false, message: t('grayDark'), suggestion: oklchToHex({ L: L_SOFT_MIN, C: 0.15, H: lch.H }) };
			}
			if (lch.L > L_SOFT_MAX) {
				return { valid: false, message: t('grayLight'), suggestion: oklchToHex({ L: L_SOFT_MAX, C: 0.15, H: lch.H }) };
			}
		}
		return { valid: true };
	}

	// ── Auto-save ─────────────────────────────────────────────
	// One request per change. The options.php form under the cards stays the
	// fallback without JavaScript; with it, its button is hidden
	// (.dy-mlset--js, admin-overview.css).

	function save (key, value, extra) {
		return $.post(CFG.ajaxUrl, $.extend({
			action: 'doubleyoupi_admin_save_setting',
			nonce: CFG.nonce,
			key: key,
			value: value,
		}, extra || {})).then(function (res) {
			if (!res || !res.success) {
				return $.Deferred().reject(res && res.data && res.data.message).promise();
			}
			clearError();
			flashSaved(key);
			return res.data;
		}).fail(function (message) {
			// The server sends a sentence for a refused color, a bare code
			// (`locked`, `forbidden`…) otherwise.
			showError(typeof message === 'string' && message.indexOf(' ') > 0 ? message : t('error'));
		});
	}

	function flashSaved (key) {
		const unit = document.querySelector('#dy-adset [data-unit="' + key + '"]');
		if (!unit) return;
		unit.classList.remove('is-saved');
		void unit.offsetWidth; // restart the transition on a quick second change
		unit.classList.add('is-saved');
		clearTimeout(unit._dySavedTimer);
		unit._dySavedTimer = setTimeout(function () { unit.classList.remove('is-saved'); }, 1500);
	}

	// A native notice above the cards, gone with the next successful save.
	function showError (message) {
		clearError();
		const notice = document.createElement('div');
		notice.className = 'notice notice-error inline dy-adset__error';
		notice.innerHTML = '<p></p>';
		notice.firstChild.textContent = message;
		const root = document.getElementById('dy-adset');
		if (root) root.parentNode.insertBefore(notice, root);
	}
	function clearError () {
		document.querySelectorAll('.dy-adset__error').forEach(function (n) { n.remove(); });
	}

	// ── Live preview on the rail ──────────────────────────────
	// The rail is on screen next to the settings: a new accent or "Accent on
	// every menu line" repaints it at once, then the entries whose colour
	// changed shimmer, top to bottom (_rail.scss, `.dy-shimmer`).

	const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function railEntries () {
		return Array.prototype.slice.call(document.querySelectorAll('#adminmenu > li.menu-top'))
			.map(function (li) { return { li: li, a: li.querySelector(':scope > a') }; })
			.filter(function (e) { return e.a; });
	}

	// The colour of each entry's dot, to compare before and after a change.
	function railSnapshot () {
		return railEntries().map(function (e) {
			return { li: e.li, a: e.a, color: getComputedStyle(e.a, '::after').backgroundColor };
		});
	}

	function shimmer (before) {
		if (!before || reduceMotion) return;
		let i = 0;
		before.forEach(function (e) {
			if (getComputedStyle(e.a, '::after').backgroundColor === e.color) return;
			e.li.style.setProperty('--dy-shimmer-i', String(i++));
			e.li.classList.remove('dy-shimmer');
			void e.li.offsetWidth;
			e.li.classList.add('dy-shimmer');
		});
		if (!i) return;
		clearTimeout(shimmer.timer);
		shimmer.timer = setTimeout(function () {
			document.querySelectorAll('#adminmenu > li.dy-shimmer').forEach(function (li) {
				li.classList.remove('dy-shimmer');
				li.style.removeProperty('--dy-shimmer-i');
			});
		}, i * 55 + 700);
	}

	// Brand.php prints the accent on `:root, body.dy-admin`; the preview
	// writes it on both, where an inline style wins.
	function previewAccent (hex) {
		const rgb = hexToRgb(hex);
		const triplet = [rgb.r, rgb.g, rgb.b].map(function (v) { return Math.round(v * 255); }).join(',');
		[document.documentElement, document.body].forEach(function (el) {
			el.style.setProperty('--dya-accent', hex);
			el.style.setProperty('--dya-accent-rgb', triplet);
		});
	}

	// ── Accent color ──────────────────────────────────────────

	$(function () {
		const $root = $('#dy-adset');
		if (!$root.length) return;
		$root.addClass('dy-mlset--js');

		const $input    = $('#dy_accent');
		const $picker   = $('.dy-accent-picker');
		const $swatches = $('.dy-accent-swatch');
		const $feedback = $picker.find('.dy-accent-feedback');
		const $text     = $feedback.find('.dy-accent-feedback__text');
		const $sugg     = $feedback.find('.dy-accent-suggestion');
		const $suggHex  = $sugg.find('.dy-accent-suggestion__hex');

		if (!$input.length) return;

		let committed = ($input.val() || '').toLowerCase();
		let before    = null; // rail colours before the first preview of a change
		let timer     = null;

		// Iris fires `change` on every tick of a drag: the rail follows
		// continuously, the save (and the shimmer) wait for the hand to stop.
		function onColor (color) {
			color = (color || '').toLowerCase();
			markSelectedSwatch(color);
			const result = validateAccent(color);
			if (!result.valid) {
				showFeedback(result);
				clearTimeout(timer);
				return;
			}
			$picker.attr('data-state', 'valid');
			$feedback.attr('hidden', true);
			if (color === committed && !before) return;
			if (!before) before = railSnapshot();
			previewAccent(color);
			clearTimeout(timer);
			timer = setTimeout(function () { commit(color); }, 450);
		}

		function commit (color) {
			const snapshot = before;
			before = null;
			if (color === committed) return;
			shimmer(snapshot);
			save('accent', color).done(function () {
				committed = color;
				closePicker();
			});
		}

		// Once the color is saved, the Iris panel closes: the choice is made.
		// Never under a hand still dragging in it; that waits for the release.
		let dragging = false;
		$picker.on('pointerdown', '.iris-picker', function () { dragging = true; });
		$(document).on('pointerup pointercancel', function () {
			if (!dragging) return;
			dragging = false;
			if (closePicker.pending) closePicker();
		});
		function closePicker () {
			closePicker.pending = dragging;
			if (dragging || !$.fn.wpColorPicker) return;
			// Only when open: close() toggles Iris, so on a panel WordPress
			// already closed (a click outside it, on a swatch) it would reopen it.
			if (!$input.closest('.wp-picker-container').hasClass('wp-picker-active')) return;
			try { $input.wpColorPicker('close'); } catch (_) {}
		}

		function showFeedback (result) {
			$picker.attr('data-state', 'invalid');
			$feedback.removeAttr('hidden');
			$text.text(result.message);
			if (result.suggestion) {
				$sugg.attr('data-color', result.suggestion).removeAttr('hidden');
				// Native setProperty: jQuery's .css() is unreliable on custom properties.
				$sugg[0].style.setProperty('--swatch', result.suggestion);
				$suggHex.text(result.suggestion);
			} else {
				$sugg.attr('hidden', true);
			}
		}

		function setColor (color) {
			$input.val(color);
			if ($.fn.wpColorPicker) {
				try { $input.wpColorPicker('color', color); } catch (_) {}
			}
			onColor(color);
		}

		if ($.fn.wpColorPicker) {
			$input.wpColorPicker({
				change: function (event, ui) { onColor(ui.color.toString()); },
			});
		}
		$input.on('input', function () { onColor(this.value); });
		$swatches.on('click', function (e) {
			e.preventDefault();
			setColor(this.getAttribute('data-color'));
		});
		$sugg.on('click', function (e) {
			e.preventDefault();
			const color = this.getAttribute('data-color');
			if (color) setColor(color);
		});

		markSelectedSwatch(committed);

		function markSelectedSwatch (color) {
			$swatches.each(function () {
				const on = (this.getAttribute('data-color') || '').toLowerCase() === color;
				this.classList.toggle('is-selected', on);
				this.setAttribute('aria-checked', on ? 'true' : 'false');
			});
		}
	});

	// ── Toggles and choices ───────────────────────────────────

	$(function () {
		const $root = $('#dy-adset');
		if (!$root.length) return;

		$root.on('change', '.dy-mlset__input[data-key]', function () {
			const key  = this.getAttribute('data-key');
			const on   = this.checked;
			const card = this.closest('.dy-mlset__card');
			if (card) card.setAttribute('data-state', on ? 'active' : 'default');
			if ('accent_to_lines' === key) {
				const before = railSnapshot();
				document.body.classList.toggle('dy-lines-accent', on);
				shimmer(before);
			}
			save(key, on ? '1' : '0');
		});

		// The tree and the flyouts are drawn by the server: the page reloads
		// once the choice is saved, so the rail on screen matches it.
		$root.on('change', 'input[type="radio"][data-key]', function () {
			const key = this.getAttribute('data-key');
			save(key, this.value).done(function () {
				setTimeout(function () { window.location.reload(); }, 450);
			});
		});
	});

	// ── Logo ──────────────────────────────────────────────────

	$(function () {
		const $pickBtn  = $('#dy_logo_pick');
		const $clearBtn = $('#dy_logo_clear');
		const $urlInput = $('#dy_logo_url_input');
		const $idInput  = $('#dy_logo_id_input');
		const $preview  = $('#dy-logo-preview');
		const $hint     = $('.dy-logo-field__hint');
		const tpl       = document.getElementById('dy-default-logo');
		const fallback  = tpl ? tpl.innerHTML : '';
		let frame;

		function paint (url) {
			const html = url ? '<img src="' + encodeURI(url) + '" alt="" />' : fallback;
			$preview.html(html);
			// The logo above the rail follows at once.
			$('.dy-rail-logo').html(html);
			$pickBtn.text(url ? t('changeImage') : t('chooseImage'));
			$clearBtn.prop('disabled', !url);
			$hint.prop('hidden', !!url);
		}

		$pickBtn.on('click', function (e) {
			e.preventDefault();
			if (frame) { frame.open(); return; }
			frame = wp.media({
				title: t('chooseLogo'),
				button: { text: t('useImage') },
				library: { type: ['image'] },
				multiple: false,
			});
			frame.on('select', function () {
				const att = frame.state().get('selection').first().toJSON();
				$urlInput.val(att.url);
				$idInput.val(att.id);
				paint(att.url);
				save('logo', att.url, { id: att.id });
			});
			frame.open();
		});

		$clearBtn.on('click', function (e) {
			e.preventDefault();
			$urlInput.val('');
			$idInput.val('');
			paint('');
			save('logo', '', { id: 0 });
		});
	});
})(jQuery);

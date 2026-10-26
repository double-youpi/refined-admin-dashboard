<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LightIslands: keeps plugin panels readable in the dark theme.
 *
 * A plugin that draws its own white or pale panels does not follow the dark
 * theme, but our ink still did: light text on white (WP Fastest Cache, Limit
 * Login Attempts, WP Go Maps, Independent Analytics, Redirection; translator
 * feedback and compatibility lab, 23/10/2026). Listing each plugin by hand,
 * as for Yoast and Rank Math, cannot keep up.
 *
 * In the dark theme, the script looks in the page content for panels whose
 * own background is light, large enough to hold content, and marks the
 * outermost one `dy-light-island`; _light-islands.scss restates the light
 * palette inside, as the translator suggested: when the background stays
 * light, the text keeps a colour that reads on it. Buttons, fields, media and
 * our own components are left out. It follows content added later (plugins
 * built with React render after the page) and the theme switch.
 *
 * The reverse case gets the same care: a plugin that writes its own dark
 * text straight on the page (Limit Login Attempts' « App Settings » and
 * name) lost it on our dark ground. Text that falls under the AA contrast
 * on a dark background takes our ink (`dy-ink-lift`), on plugin pages and
 * plugin dashboard widgets. Only that text: a plugin colour that reads well
 * is never changed.
 *
 * When at least half of a plugin page's text sits on such panels (and the
 * page holds 200 characters or more), the whole screen moves to the native
 * light canvas instead, and an administrator's visit records the page for
 * NativeContent (route doubleyoupi/v1/admin/native-screens), so the next
 * visits render that way from the start (Raphaël, 23/10/2026).
 *
 * Not on screens already left on a light canvas (native content, builders)
 * nor in the block editor.
 */
final class LightIslands {

	private const JS = <<<'JS'
(function () {
	var body = document.body, root = document.getElementById('wpbody-content');
	if (!root || !body.classList.contains('dy-admin')) { return; }
	if (/(^| )(dy-native-content|dy-builder-native|block-editor-page)( |$)/.test(body.className)) { return; }
	var CLS = 'dy-light-island', LIFT = 'dy-ink-lift';
	var SKIP = 'img,svg,video,canvas,iframe,picture,input,select,textarea,button,option,code,pre,.button,.components-button,.page-title-action,[class^="dy-"],[class*=" dy-"],[id^="dy-"]';
	var CFG = window.doubleyoupiLightIslands || {};
	// Dark theme, and not already moved to the native canvas.
	function dark() { return !body.classList.contains('dy-theme-light') && !body.classList.contains('dy-native-content'); }
	function light(c) {
		var m = c && c.match(/[\d.]+/g);
		if (!m || m.length < 3 || (m.length > 3 && +m[3] < 0.85)) { return false; }
		var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
		return 0.2126 * f(+m[0]) + 0.7152 * f(+m[1]) + 0.0722 * f(+m[2]) > 0.6;
	}
	function mark(el) {
		if (el.closest('.' + CLS) || el.matches(SKIP)) { return; }
		var cs = getComputedStyle(el);
		if (cs.display === 'none' || cs.visibility === 'hidden' || !light(cs.backgroundColor)) { return; }
		var r = el.getBoundingClientRect();
		if (r.width < 160 || r.height < 40 || !el.textContent.trim()) { return; }
		el.classList.add(CLS);
	}
	function rgb(c) {
		if (!/^rgba?\(/.test(c || '')) { return null; }
		var m = c.match(/[\d.]+/g);
		return m && m.length >= 3 ? { r: +m[0], g: +m[1], b: +m[2], a: m.length > 3 ? +m[3] : 1 } : null;
	}
	function lum(c) {
		var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
		return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
	}
	// Plain rgb() only: our own colours (oklch) are readable by design.
	function groundOf(el) {
		for (var a = el; a && a !== document.documentElement; a = a.parentElement) {
			var cs = getComputedStyle(a);
			// A real image hides the ground; our grain texture (an inline SVG
			// over the ground colour) does not.
			if (cs.backgroundImage && cs.backgroundImage !== 'none' && !/^url\("?data:image\/svg/.test(cs.backgroundImage)) { return null; }
			var c = rgb(cs.backgroundColor);
			if (c && c.a >= 0.85) { return c; }
		}
		return rgb(getComputedStyle(body).backgroundColor);
	}
	// Plugin pages and plugin dashboard widgets only: WordPress' and our own
	// screens are audited, and some of their grounds are pseudo-elements this
	// measure cannot see.
	var params = new URLSearchParams(location.search);
	var pluginPage = !!params.get('page') && !/^doubleyoupi/.test(params.get('page'));
	function lift(el) {
		if (!pluginPage && !el.closest('.dy-dashboard__native-item[data-native-origin="plugin"]')) { return; }
		if (el.closest('.' + CLS + ', .' + LIFT) || el.matches(SKIP)) { return; }
		var own = false;
		for (var n = el.firstChild; n; n = n.nextSibling) { if (n.nodeType === 3 && n.nodeValue.trim()) { own = true; break; } }
		if (!own) { return; }
		if (!el.getClientRects().length) { return; }
		var cs = getComputedStyle(el), fg = rgb(cs.color);
		if (!fg || fg.a < 0.3 || cs.visibility === 'hidden') { return; }
		var bg = groundOf(el);
		if (!bg || lum(bg) > 0.2) { return; }
		var hi = Math.max(lum(fg), lum(bg)), lo = Math.min(lum(fg), lum(bg));
		var big = parseFloat(cs.fontSize) >= 24 || (parseFloat(cs.fontSize) >= 18.66 && +cs.fontWeight >= 700);
		if ((hi + 0.05) / (lo + 0.05) < (big ? 3 : 4.5)) { el.classList.add(LIFT); }
	}
	function scan(scope) {
		if (!dark() || !scope || scope.nodeType !== 1) { return; }
		if (scope !== root) { mark(scope); }
		var all = scope.getElementsByTagName('*');
		for (var i = 0; i < all.length; i++) { mark(all[i]); }
		if (scope !== root) { lift(scope); }
		for (var j = 0; j < all.length; j++) { lift(all[j]); }
	}
	function clear() {
		[CLS, LIFT].forEach(function (k) {
			[].slice.call(document.getElementsByClassName(k)).forEach(function (el) { el.classList.remove(k); });
		});
	}
	var queue = [], queued = false;
	function flush() {
		queued = false;
		var list = queue; queue = [];
		list.forEach(scan);
	}
	// Plugin dialogs are often appended to <body>, outside the content: they
	// are scanned too, but never WordPress' or our own frame.
	var FRAME = /^(wpwrap|wpadminbar|adminmenumain|screen-meta|dy-)/;
	function outside() {
		return [].filter.call(body.children, function (n) {
			return !/^(SCRIPT|STYLE|LINK|TEMPLATE|NOSCRIPT)$/.test(n.tagName) && !FRAME.test(n.id || '') && !/(^| )dy-/.test(n.className || '');
		});
	}
	function scanAll() { scan(root); outside().forEach(scan); }
	var watch = new MutationObserver(function (records) {
		records.forEach(function (rec) {
			[].forEach.call(rec.addedNodes, function (n) {
				if (n.nodeType === 1 && (root.contains(n) || outside().indexOf(n) > -1 || outside().some(function (o) { return o.contains(n); }))) { queue.push(n); }
			});
		});
		if (queue.length && !queued) { queued = true; window.requestAnimationFrame(flush); }
	});
	watch.observe(root, { childList: true, subtree: true });
	watch.observe(body, { childList: true });
	outside().forEach(function (o) { watch.observe(o, { childList: true, subtree: true }); });
	// When most of a plugin page sits on its own white panels, a half-dark
	// screen is worse than a light one: the page moves to the native canvas
	// (rail and bars stay ours), and an administrator's visit records it so
	// the next ones render that way from the start (NativeContent).
	var decided = false;
	function decide() {
		// Screens we skin on purpose keep their islands but never move.
		if (decided || !pluginPage || !dark() || CFG.skinned) { return; }
		var all = 0, isl = 0, w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
		while (w.nextNode()) {
			var t = w.currentNode.nodeValue.trim(), e = w.currentNode.parentElement;
			if (!t || !e || e.closest('.notice, .error, .updated, [id^="dy-"], script, style') || !e.getClientRects().length) { continue; }
			all += t.length;
			if (e.closest('.' + CLS)) { isl += t.length; }
		}
		if (all < 200 || isl / all < 0.5) { return; }
		decided = true;
		clear();
		body.classList.add('dy-native-content');
		if (CFG.canSave && CFG.rest && window.fetch) {
			fetch(CFG.rest, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
				body: JSON.stringify({ page: (params.get('page') || '').toLowerCase() })
			}).catch(function () {});
		}
	}
	document.addEventListener('doubleyoupi:theme', function () { clear(); scanAll(); decide(); });
	scanAll();
	decide();
	window.addEventListener('load', function () { scanAll(); decide(); setTimeout(function () { scanAll(); decide(); }, 1500); });
})();
JS;

	public function boot(): void {
		add_action( 'admin_footer', [ $this, 'print_script' ], 2 );
	}

	/** The `page` query value of the current admin screen, lower-cased. */
	private static function current_page(): string {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display state.
		return strtolower( $page );
	}

	public function print_script(): void {
		$config = [
			'rest'    => esc_url_raw( rest_url( 'doubleyoupi/v1/admin/native-screens' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'canSave' => current_user_can( 'manage_options' ),
			'skinned' => NativeContent::is_skinned_app( self::current_page() ),
		];
		wp_print_inline_script_tag( 'window.doubleyoupiLightIslands = ' . wp_json_encode( $config ) . ";\n" . self::JS, [ 'id' => 'doubleyoupi-light-islands' ] );
	}
}

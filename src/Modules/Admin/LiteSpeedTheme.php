<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LiteSpeedTheme — hands LiteSpeed Cache's own dark mode over to the
 * Double Youpi theme.
 *
 * LiteSpeed Cache (7.x) ships a complete dark theme for its admin pages
 * (`assets/css/litespeed-dark-mode.css`), driven by the OS preference and by a
 * `localStorage['litespeed-dark-preference']` override that its footer script
 * turns into a `litespeed-darkmode` / `litespeed-lightmode` body class. It also
 * mounts a floating 🌙/☀️ toggle in the top-right corner, which overlaps our
 * admin bar.
 *
 * Manifesto §2 "detect and step back": rather than re-theme 10 bespoke pages,
 * we mirror our theme into LiteSpeed's preference BEFORE its script runs, keep
 * the two in sync on live toggles (see `doubleyoupi:theme` in admin.js), and
 * hide its floating toggle (CSS, _15-third-party-fixes). LiteSpeed then paints
 * its own dark UI on our canvas.
 */
final class LiteSpeedTheme {

	private const LITESPEED_THEME_SYNC_JS = <<<'JS'
(function () {
	var KEY = 'litespeed-dark-preference';
	function ours() {
		try { return localStorage.getItem('dy-admin-theme') === 'light' ? 'light' : 'dark'; }
		catch (e) { return 'dark'; }
	}
	function mirror(theme) {
		try { localStorage.setItem(KEY, theme); } catch (e) {}
		var b = document.body;
		if (!b) { return; }
		// Same decision table as LiteSpeed's applyDarkMode(): a body class is
		// only needed when the preference contradicts the OS scheme, the
		// stylesheet's media query covers the other case.
		var osDark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
		b.classList.remove('litespeed-darkmode', 'litespeed-lightmode');
		if (theme === 'dark' && !osDark) { b.classList.add('litespeed-darkmode'); }
		if (theme === 'light' && osDark) { b.classList.add('litespeed-lightmode'); }
	}
	mirror(ours());
	document.addEventListener('doubleyoupi:theme', function (e) {
		mirror(e && e.detail && e.detail.theme === 'light' ? 'light' : 'dark');
	});
})();
JS;


	public function boot(): void {
		add_action( 'admin_head', [ $this, 'print_sync_script' ], 2 );
	}

	/**
	 * True on any LiteSpeed Cache admin page (`?page=litespeed…`), which is the
	 * exact condition LiteSpeed's own script uses to mount its toggle.
	 */
	public static function is_current(): bool {
		if ( ! is_admin() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return str_starts_with( $page, 'litespeed' );
	}

	/**
	 * Inline script, printed in <head> (LiteSpeed's script is enqueued in the
	 * footer, so ours always runs first):
	 *
	 *   1. Copies our theme (`localStorage.dy-admin-theme`, default dark) into
	 *      `localStorage.litespeed-dark-preference`, which LiteSpeed reads at
	 *      init to compute its body class.
	 *   2. On `doubleyoupi:theme` (dispatched by admin.js on every toggle),
	 *      re-mirrors the preference and applies LiteSpeed's class logic
	 *      immediately, without waiting for a reload.
	 */
	public function print_sync_script(): void {
		if ( ! self::is_current() ) {
			return;
		}
		wp_print_inline_script_tag( self::LITESPEED_THEME_SYNC_JS, [ 'id' => 'doubleyoupi-litespeed-theme-sync' ] );
	}
}

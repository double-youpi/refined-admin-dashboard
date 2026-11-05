<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Core\Build;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PluginCompat — small gestures for named plugins whose screens would
 * otherwise fight the redesign (24/10/2026, translator's and users' reports).
 *
 * - MailPoet: its "conflict resolver" dequeues every stylesheet and script
 *   whose address is not on its allowlist, on its own pages. The redesign
 *   lost its CSS there (the rail and the bars came out as raw links).
 *   MailPoet offers a filter for exactly this: we add our build folder.
 * - Fluent apps (FluentCart, FluentSMTP, FluentAuth, FluentSnippets): they
 *   share one dark mode (`html.fluent_theme_dark`, `data-fct-theme`,
 *   `fluent_theme_mode`), which follows the system by default. Dark cards on
 *   the redesign's light canvas, or the reverse, made text unreadable. They
 *   now follow the Double Youpï theme (FluentCart since 1.0.16, the others
 *   since 1.0.32, user feedback of 05/11/2026).
 *
 * Layout fixes (headers pinned to WordPress's geometry) live in the SCSS
 * partial `_plugin-headers.scss`.
 */
final class PluginCompat {

	private const FLUENTCART_THEME_SYNC_JS = <<<'JS'
(function () {
	var h = document.documentElement;
	if (!h.hasAttribute('data-fct-theme')) { return; }
	function light() {
		var b = document.body;
		if (b && b.classList.contains('dy-theme-light')) { return true; }
		if (b && b.classList.contains('dy-theme-dark')) { return false; }
		try { return localStorage.getItem('dy-admin-theme') === 'light'; } catch (e) { return false; }
	}
	var last = null;
	function apply() {
		var l = light();
		if (l === last) { return; }
		last = l;
		h.classList.toggle('fluent_theme_dark', !l);
		h.setAttribute('data-fct-theme', l ? 'light' : 'dark');
		try { localStorage.setItem('fluent_theme_mode', l ? 'light' : 'dark'); } catch (e) {}
	}
	apply();
	function watch() {
		apply();
		try { new MutationObserver(apply).observe(document.body, { attributes: true, attributeFilter: ['class'] }); } catch (e) {}
	}
	if (document.body) { watch(); } else { document.addEventListener('DOMContentLoaded', watch); }
})();
JS;

	public function boot(): void {
		add_filter( 'mailpoet_conflict_resolver_whitelist_style', [ self::class, 'allow_our_assets' ] );
		add_filter( 'mailpoet_conflict_resolver_whitelist_script', [ self::class, 'allow_our_assets' ] );
		// After the Fluent apps' own init script (admin_head, priority 10). The
		// script does nothing on a page without their `data-fct-theme`.
		add_action( 'admin_head', [ self::class, 'print_fluentcart_theme_sync' ], 20 );
	}

	/**
	 * Adds the address of our build folder to a list of regex fragments
	 * matched against each asset's URL (MailPoet's allowlists).
	 *
	 * @param mixed $patterns
	 * @return mixed
	 */
	public static function allow_our_assets( $patterns ) {
		if ( ! is_array( $patterns ) ) {
			return $patterns;
		}
		$path = (string) wp_parse_url( Build::url( __FILE__ ), PHP_URL_PATH );
		if ( '' !== trim( $path, '/' ) ) {
			$patterns[] = preg_quote( untrailingslashit( $path ), '!' );
		}
		return $patterns;
	}

	public static function print_fluentcart_theme_sync(): void {
		wp_print_inline_script_tag( self::FLUENTCART_THEME_SYNC_JS, [ 'id' => 'doubleyoupi-fluentcart-theme' ] );
	}
}

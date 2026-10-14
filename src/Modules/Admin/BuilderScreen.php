<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BuilderScreen — detects "we are on a screen belonging to a builder with an
 * externally managed UI" (Elementor & co, see PluginMap::is_externally_managed_builder).
 *
 * On these screens the builder ships its own admin interface (React/SPA)
 * that does not use native WP layouts. Our design system cannot redraw it
 * cleanly and pollutes it (film-grain overlay, inherited typography,
 * chipbar redundant with the builder's internal nav, notices dock).
 *
 * Product decision (see CLAUDE.md §2 "detect and step back"): we KEEP the
 * global rail for navigation consistency, but surgically remove:
 *   - the chipbar              (Chipbar::render short-circuits)
 *   - the notices dock/modal   (Notices::* short-circuits)
 *   - the content design system (SCSS partial `_builder-native`, driven by
 *     the `dy-builder-native` body class set here)
 * and the reorganizer shows "hierarchy managed by the extension" instead of
 * listing the builder's sub-pages (see ColumnModel + admin-developer.js).
 *
 * Bonus: we synchronize the Elementor admin app theme (design system "eps")
 * with the Double Youpi theme (dark/light) client-side.
 */
final class BuilderScreen {

	private const ELEMENTOR_THEME_SYNC_JS = <<<'JS'
(function () {
	var last = null;
	function isLight() {
		var b = document.body;
		if ( b.classList.contains( 'dy-theme-light' ) ) { return true; }
		if ( b.classList.contains( 'dy-theme-dark' ) )  { return false; }
		try {
			var t = localStorage.getItem( 'dy-admin-theme' );
			if ( t === 'light' ) { return true; }
			if ( t === 'dark' )  { return false; }
		} catch ( e ) {}
		return false; // Double Youpi defaults to dark.
	}
	function apply() {
		var light = isLight();
		if ( light === last ) { return; } // re-entrancy guard (the observer sees our own toggles).
		last = light;
		var b = document.body, h = document.documentElement;
		b.classList.toggle( 'eps-theme-dark', ! light );
		b.classList.toggle( 'eps-theme-light', light );
		h.setAttribute( 'data-theme', light ? 'light' : 'dark' );
	}
	apply();
	try {
		new MutationObserver( apply ).observe( document.body, {
			attributes: true, attributeFilter: [ 'class' ]
		} );
	} catch ( e ) {}
})();
JS;


	/** Post types whose admin screens belong to an externally managed builder. */
	private const BUILDER_POST_TYPES = [
		'elementor_library' => 'elementor',
	];

	/**
	 * Slug of the externally managed builder for the current screen, or null.
	 *
	 * Resolves via Rail::resolve_top_level_slug() (which handles sub-pages
	 * `?page=…` → parent). Memoizes only positive results: as long as
	 * detection is negative we recalculate, because a call too early
	 * (before `$parent_file`/`$plugin_page` are populated) could return
	 * a false negative that we do not want to cache.
	 */
	public static function current(): ?string {
		static $cache = null; // string slug once detected, recalculate otherwise.
		if ( null !== $cache ) {
			return $cache;
		}
		$slug = Rail::resolve_top_level_slug();
		// Builder-owned post types (template libraries) live under edit.php but
		// belong to the builder's admin UI (Elementor 4 wraps them in its own
		// « Editor One » layout).
		global $typenow;
		if ( is_string( $typenow ) && isset( self::BUILDER_POST_TYPES[ $typenow ] ) ) {
			$slug = self::BUILDER_POST_TYPES[ $typenow ];
		}
		if ( null === $slug || '' === $slug ) {
			return null;
		}
		if ( ! PluginMap::is_externally_managed_builder( $slug ) ) {
			return null;
		}
		$cache = PluginMap::normalize_slug( $slug );
		return $cache;
	}

	/**
	 * True if the current admin screen belongs to an externally managed builder.
	 */
	public static function is_managed(): bool {
		return null !== self::current();
	}

	public function boot(): void {
		// Body class that drives the `_builder-native` SCSS partial (neutralizes
		// the content design system on the builder screen).
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );

		// Sync Elementor (eps) theme ↔ DY theme, client-side. Priority 1:
		// early in the footer, but <body> is already parsed.
		add_action( 'admin_print_footer_scripts', [ $this, 'print_theme_sync' ], 1 );
	}

	/**
	 * Adds `dy-builder-native` (+ `dy-builder-{slug}`) on managed screens.
	 *
	 * @param string $classes Space-separated WP class list.
	 */
	public function body_class( string $classes ): string {
		$slug = self::current();
		if ( null === $slug ) {
			return $classes;
		}
		$safe = preg_replace( '/[^a-z0-9_-]/', '', $slug ) ?? '';
		$classes .= ' dy-builder-native';
		if ( '' !== $safe ) {
			$classes .= ' dy-builder-' . $safe;
		}
		return $classes;
	}

	/**
	 * Theme synchronization script.
	 *
	 * Double Youpi drives the theme via the `dy-theme-light` body class
	 * (toggled live by admin.js) + `localStorage['dy-admin-theme']`.
	 * The Elementor admin app (design system "eps") themes itself via the
	 * body classes `eps-theme-dark` / `eps-theme-light` + a `data-theme` attribute.
	 * We mirror DY → Elementor on load and on every toggle.
	 *
	 * Best-effort: Elementor also reads its usermeta preference at server render,
	 * so a slight flash is possible on the very first paint.
	 */
	public function print_theme_sync(): void {
		if ( ! self::is_managed() ) {
			return;
		}
		wp_print_inline_script_tag( self::ELEMENTOR_THEME_SYNC_JS, [ 'id' => 'doubleyoupi-elementor-theme-sync' ] );
	}
}

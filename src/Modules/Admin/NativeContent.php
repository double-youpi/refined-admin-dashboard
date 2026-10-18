<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NativeContent — plugin screens with a "self-sufficient" UI (Rank Math,
 * Yoast SEO…) whose interface has its own complete design system.
 *
 * Difference from BuilderScreen: here we do NOT remove the chipbar (these
 * plugins' internal nav is useful and coexists well with our sub-page chipbar).
 * We simply render the CONTENT AREA on a native WP canvas (no design-system
 * tint, no film grain, native typography + ink) via the body class
 * `dy-native-content` (see _builder-native / _wp-production).
 *
 * Rationale (see CLAUDE.md §2 "detect and step back"): our design system
 * cannot cleanly redraw these SPA/React UIs and was rendering them "half-styled"
 * (mismatched backgrounds, hybrid controls). We step back from their content
 * but keep the shell (rail + chipbar) for navigation consistency.
 */
final class NativeContent {

	/**
	 * Browser-side detection of JavaScript app screens (18/10/2026).
	 *
	 * A plugin page whose server-rendered content is only an empty mount point
	 * (a React/Vue/Tailwind app such as Animation Addons for Elementor fills it
	 * later) ships its own complete interface: our dark-mode ink recoloured its
	 * text on its own light cards. Runs at `admin_footer`, before the footer
	 * scripts that mount the app, so the class is set before the app paints.
	 * Notices, screen options and our own nodes are ignored; a hidden input is
	 * not content. Any visible text or real control means a classic page.
	 */
	private const APP_DETECT_JS = <<<'JS'
(function () {
	var b = document.body, w = document.getElementById('wpbody-content');
	if (!b || !w || b.classList.contains('dy-native-content')) { return; }
	var skip = '#screen-meta,#screen-meta-links,.clear,.notice,.updated,.error,.update-nag,[id^="dy-"],[class^="dy-"]';
	var solid = 'input:not([type="hidden"]),select,textarea,button,table,form,img,svg,a,h1,h2,h3,h4,p,label,ul,ol,iframe';
	var mounts = 0;
	for (var i = 0; i < w.children.length; i++) {
		var e = w.children[i];
		if (/^(SCRIPT|STYLE|LINK|TEMPLATE|NOSCRIPT|BR)$/.test(e.tagName) || e.matches(skip)) { continue; }
		if (e.textContent.trim() !== '' || e.querySelector(solid)) { return; }
		if (e.id || e.className) { mounts++; }
	}
	if (mounts) { b.classList.add('dy-native-content', 'dy-native-app'); }
})();
JS;

	/**
	 * App screens we skin on purpose, kept out of the browser-side detection:
	 * WooCommerce's admin (`page=wc-admin`, a React app mounted in an empty
	 * root) has its own part of the design system. Prefixes, filterable via
	 * `doubleyoupi_native_app_skinned_prefixes`.
	 *
	 * @var string[]
	 */
	private const SKINNED_APP_PREFIXES = [
		'wc-admin',
	];

	/**
	 * Page slug prefixes (`?page=…`) treated as native content.
	 * Filterable via `doubleyoupi_native_content_prefixes`.
	 *
	 * @var string[]
	 */
	private const PAGE_PREFIXES = [
		// ── Plugins with a self-sufficient admin design system ──
		'rank-math', // Rank Math SEO (all sub-pages)
		'wpseo',     // Yoast SEO (wpseo_dashboard, wpseo_titles…)
		// audit-2 (2026-09): bespoke admin design systems that only half-style
		// under our tokens (white cards, own tabs, own buttons). Same policy as
		// Yoast: rail + chipbar kept, content rendered on its native canvas.
		'wp-mail-smtp',   // WP Mail SMTP (settings, logs, tools, about)
		'wpforms',        // WPForms (overview, settings, tools, analytics)
		'monsterinsights', // MonsterInsights (reports, settings, tools)
		'jetpack',        // Jetpack (React app)
		'my-jetpack',     // My Jetpack
		'wordfence',      // Wordfence (page=Wordfence, WordfenceScan, WordfenceWAF… lower-cased by sanitize_key)
		'wfls',           // Wordfence Login Security
		// Admin Columns (Tailwind settings UI). Since 12/10/2026 we step aside
		// instead of deactivating it (see AdminColumnsConflict).
		'codepress-admin-columns',
		// W3 Total Cache: own header, menus and dashboard grid, which our
		// design system broke apart (18/10/2026, seen by Raphaël).
		'w3tc',

		// ── Themes with their OWN admin design system (React/SPA dashboards) ──
		// Manifesto §2 "detect and step back": for mainstream themes that ship a
		// full-page bespoke admin (Astra/Kadence/OceanWP/Blocksy/Neve/GeneratePress,
		// and Avada/Divi), we keep our shell (rail + chipbar for navigation) but
		// render their content on a native canvas instead of half-styling it.
		// Brand-specific slugs → no collision risk. Extend via the filter below.
		'astra',           // Astra (page=astra, astra-sites…)
		'theme-builder-free', // Astra Site Builder (Tailwind UI, page=theme-builder-free)
		'kadence',         // Kadence (page=kadence…)
		'oceanwp',         // OceanWP (page=oceanwp-panel…)
		'blocksy',         // Blocksy (page=blocksy-dashboard, blocksy-ext…)
		'ct-dashboard',    // Blocksy 2.x dashboard slug (audit-2)
		'neve',            // Neve (page=neve-welcome…)
		'ti-about-neve',   // Neve « About » (Themeisle SDK page, audit-2)
		'hello-elementor', // Hello Elementor home/settings/AI planner (MUI, audit-2)
		'generatepress',   // GeneratePress (page=generatepress…)
		'generate-options', // GeneratePress 3.x dashboard slug (audit-2)
		'avada',           // Avada (page=avada…)
		'et_divi_options', // Divi — theme options
		'et_theme_builder',// Divi — theme builder
	];

	/**
	 * True if the current admin screen is a "native content" screen.
	 */
	public static function is_current(): bool {
		if ( ! is_admin() ) {
			return false;
		}
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		if ( '' === $page ) {
			return false;
		}
		// Case-insensitive: some plugins register mixed-case slugs
		// (Wordfence: page=Wordfence, WordfenceScan, WFLS…).
		$page = strtolower( $page );

		/** @var string[] $prefixes */
		$prefixes = (array) apply_filters( 'doubleyoupi_native_content_prefixes', self::PAGE_PREFIXES );
		foreach ( $prefixes as $prefix ) {
			$prefix = (string) $prefix;
			if ( '' !== $prefix && str_starts_with( $page, strtolower( $prefix ) ) ) {
				return true;
			}
		}
		return false;
	}

	public function boot(): void {
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_action( 'admin_footer', [ $this, 'print_app_detection' ], 1 );
	}

	/**
	 * Prints the app-screen detection on plugin pages (`?page=…`) that are not
	 * already handled: listed native content, builder screens, our own pages,
	 * and the app screens we skin on purpose.
	 */
	public function print_app_detection(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display state.
		if ( '' === $page || str_starts_with( $page, 'doubleyoupi' ) || self::is_current() || BuilderScreen::is_managed() ) {
			return;
		}
		/** @var string[] $skinned */
		$skinned = (array) apply_filters( 'doubleyoupi_native_app_skinned_prefixes', self::SKINNED_APP_PREFIXES );
		foreach ( $skinned as $prefix ) {
			if ( '' !== (string) $prefix && str_starts_with( $page, strtolower( (string) $prefix ) ) ) {
				return;
			}
		}
		wp_print_inline_script_tag( self::APP_DETECT_JS, [ 'id' => 'doubleyoupi-native-app-detect' ] );
	}

	/**
	 * @param string $classes Space-separated WP class list.
	 */
	public function body_class( string $classes ): string {
		return self::is_current() ? $classes . ' dy-native-content' : $classes;
	}
}

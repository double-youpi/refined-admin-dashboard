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

	/** Plugin pages learned as native content (see learned_pages()). */
	public const OPTION_LEARNED = 'doubleyoupi_admin_native_screens';

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
		// WPGraphQL IDE 5 (page=graphql-ide): built on @wordpress/components
		// and driven by CSS variables, which _wpgraphql.scss maps onto our
		// tokens (headless test of 21/10/2026).
		'graphql-ide',
		// FluentCart: its own dark mode follows ours (PluginCompat). Learned
		// as "light" while it still followed the system, it must not be
		// forced onto the light canvas any more (24/10/2026).
		'fluent-cart',
		// Antispam Bee: one plain settings form that reads well skinned; on
		// the native canvas its links kept our dark-theme lavender on white
		// (compatibility lab, 24/10/2026).
		'antispam_bee',
		// Plain WordPress forms and tables that the lab found moved to the
		// native canvas although they read well skinned (24/10/2026).
		'menu_editor',        // Admin Menu Editor
		'moove-gdpr_help',    // GDPR Cookie Compliance, help
		'moove-gdpr_licence', // GDPR Cookie Compliance, licence
		'nf-submissions',     // Ninja Forms, submissions list
		'members-settings',   // Members, Settings and Add-Ons tabs (lab, 26/10/2026)
		// LiteSpeed Cache: every screen is skinned; CDN and Image Optimization,
		// learned as light for their white cards, kept LiteSpeed's own dark
		// skin on the native canvas, pale ink on white (lab, 27/10/2026).
		'litespeed',
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
		// Backup plugins read by the dashboard's backup widget: their own
		// white cards and pale headings came out unreadable under our dark
		// ink (compatibility pass of 19/10/2026).
		'backwpup',         // BackWPup (onboarding, backups, settings)
		'duplicator',       // Duplicator
		'wpvivid',          // WPvivid Backup (page=WPvivid…)
		'ai1wm_',           // All-in-One WP Migration
		'backup-migration', // Backup Migration
		'boldgrid-backup',  // Total Upkeep
		'xcloner_',         // XCloner
		// Plugins drawing most of their screen on their own white panels: half
		// dark, half white under our dark theme, their logos unreadable on our
		// ground (translator feedback, 23/10/2026). Measured: 64 to 100 % of
		// their text on white panels. Unknown plugins in the same case are
		// learned (see learned_pages()).
		'limit-login-attempts', // Limit Login Attempts Reloaded
		'wp-google-maps',       // WP Go Maps (maps, settings, styling…)
		'wpfastestcache',       // WP Fastest Cache
		'independent-analytics', // Independent Analytics (all pages)
		// The GraphiQL 1 IDE bundled with WPGraphQL (Ant Design shell): only
		// its editors took our dark theme, its panels stayed white and their
		// titles became unreadable. WPGraphQL IDE 5 replaces it and is skinned
		// on purpose (SKINNED_APP_PREFIXES); this one keeps its own light look.
		'graphiql-ide',
		// Compatibility lab, 23-24/10/2026: screens drawn on the plugin's own
		// cards, banners and colours, unreadable once skinned. The lab's
		// "skin or step aside" review agreed with RAD everywhere else.
		'boldgrid-connect', // Total Upkeep, key card (boldgrid-backup did not cover it)
		'coupons-moved',    // WooCommerce, coupons showcase
		'wpcode',           // WPCode (insert-headers-and-footers)
		'loginizer',        // Loginizer
		'click-to-chat',    // Click to Chat
		'backuply',         // Backuply
		'optin-monster',    // OptinMonster (templates, integrations, TrustPulse)
		'seedprod',         // SeedProd (coming-soon)
		'cfdb7-extensions', // Contact Form CFDB7, extensions showcase (its list reads well skinned)
		'smart-slider3',    // Smart Slider 3
		'updraftplus',      // UpdraftPlus
		'cloudsecurewp',    // CloudSecure WP Security
		'mpsum',            // Easy Updates Manager (its own toggles)
		'cyr-to-lat',       // Cyr To Lat
		'members',          // Members (roles, About…; not its Settings, see SKINNED_APP_PREFIXES)
		'copy-delete-posts', // Copy Delete Posts
		'wps_overview',     // WP Statistics overview
		'formidable-views', // Formidable Views
		'otter_form_record', // Otter Blocks, form records
		'popularfx',        // PopularFX theme options
		'mailpoet',         // MailPoet (its home page, where every page leads until it is set up)

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
		// A screen skinned on purpose wins over a broader prefix
		// (`members` / `members-settings`).
		if ( self::is_skinned_app( $page ) ) {
			return false;
		}

		/** @var string[] $prefixes */
		$prefixes = (array) apply_filters( 'doubleyoupi_native_content_prefixes', self::PAGE_PREFIXES );
		foreach ( $prefixes as $prefix ) {
			$prefix = (string) $prefix;
			if ( '' !== $prefix && str_starts_with( $page, strtolower( $prefix ) ) ) {
				return true;
			}
		}
		return in_array( $page, self::learned_pages(), true );
	}

	/**
	 * Plugin pages learned in the browser (LightIslands.php): when at least half
	 * of a plugin page's text sits on its own white panels, the screen is shown
	 * on the native light canvas, and remembered here so the next visits render
	 * that way from the start, without the switch.
	 *
	 * @return string[] Lower-cased `page` values.
	 */
	public static function learned_pages(): array {
		$pages = get_option( self::OPTION_LEARNED, [] );
		$pages = is_array( $pages ) ? array_values( array_filter( array_map( 'strval', $pages ) ) ) : [];
		// A skinned app learned before it was skinned stays ignored.
		return array_values( array_filter( $pages, static fn( string $p ): bool => ! self::is_skinned_app( $p ) ) );
	}

	/** Whether a `page` value belongs to an app screen we skin on purpose. */
	public static function is_skinned_app( string $page ): bool {
		$prefixes = (array) apply_filters( 'doubleyoupi_native_app_skinned_prefixes', self::SKINNED_APP_PREFIXES );
		foreach ( $prefixes as $prefix ) {
			if ( '' !== (string) $prefix && str_starts_with( strtolower( $page ), strtolower( (string) $prefix ) ) ) {
				return true;
			}
		}
		return false;
	}

	public function register_rest_routes(): void {
		register_rest_route(
			'doubleyoupi/v1',
			'/admin/native-screens',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'learn_page' ],
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => [
					'page' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static fn( $v ): string => strtolower( sanitize_text_field( (string) $v ) ),
						'validate_callback' => static fn( $v ): bool => (bool) preg_match( '/^[a-z0-9._\-]{1,100}$/i', (string) $v ),
					],
				],
			]
		);
	}

	public function learn_page( \WP_REST_Request $request ): \WP_REST_Response {
		$page  = (string) $request->get_param( 'page' );
		$pages = self::learned_pages();
		if ( ! str_starts_with( $page, 'doubleyoupi' ) && ! self::is_skinned_app( $page ) && ! in_array( $page, $pages, true ) ) {
			$pages[] = $page;
			update_option( self::OPTION_LEARNED, array_slice( $pages, -100 ), true );
		}
		return new \WP_REST_Response( [ 'learned' => true ], 200 );
	}

	public function boot(): void {
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_action( 'admin_footer', [ $this, 'print_app_detection' ], 1 );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
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

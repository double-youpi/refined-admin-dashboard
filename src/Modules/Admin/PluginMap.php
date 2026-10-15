<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PluginMap — static classification of WordPress plugins into the 7 rail lines
 * (content / commerce / users / community / site / system / developer)
 * + standalone slot for page builders.
 *
 * Single source of truth. The Rail reads the top-level slug emitted by each
 * plugin in the global $menu, then calls PluginMap::classify( $slug )
 * to decide which line it belongs to.
 *
 * Fallback heuristic for unknown plugins — we guess the category from patterns
 * in the slug; otherwise system catch-all.
 *
 * Public filters:
 *   - doubleyoupi_admin_plugin_line   ($line, $slug)      → override the line for a slug
 *   - doubleyoupi_admin_plugin_map    ($map)              → bulk addition
 *   - doubleyoupi_admin_standalone    ($builders)         → add/remove standalone builders
 *   - doubleyoupi_admin_chip_icon     ($icon_id, $slug)   → custom icon in the chipbar
 */
final class PluginMap {

	// ── Native WordPress menu slugs ─────────────────────────────
	// Always pre-mapped (maximum priority over the heuristic).
	private const NATIVE_MAP = [
		// Dashboard — reached via the brand row, not in the rail.
		'index.php'                       => null,
		// Content
		'edit.php'                        => 'content',  // Posts
		'edit.php?post_type=page'         => 'content',  // Pages
		'upload.php'                      => 'content',  // Media
		// Community
		'edit-comments.php'               => 'community',
		// Users
		'users.php'                       => 'users',
		'profile.php'                     => 'users',
		// Appearance. The native Appearance menu is SPLIT in the reimagined
		// tree (see SPLIT_MENUS): choosing a theme or an extension is a stack
		// decision → System; the design surfaces (Editor, Fonts, Menus,
		// Widgets, theme options) stay on the Appearance line. Raphaël, 15/09/2026.
		'themes.php'                      => 'system',
		'plugins.php'                     => 'system',
		'nav-menus.php'                   => 'site',
		'widgets.php'                     => 'site',
		'site-editor.php'                 => 'site',
		'font-library.php'                => 'site',
		// Classic themes add these under Appearance (Customizer shortcuts):
		// design surfaces, not stack decisions. Unmapped, the heuristic sent
		// them to System and logged them on every screen (14/10/2026).
		'custom-background'               => 'site',
		'custom-header'                   => 'site',
		// System
		'options-general.php'             => 'system',
		'update-core.php'                 => 'system',
		'site-health.php'                 => 'system',
		'options-writing.php'             => 'system',
		'options-reading.php'             => 'system',
		'options-discussion.php'          => 'system',
		'options-permalink.php'           => 'system',
		'options-privacy.php'             => 'system',
		// Developer
		'tools.php'                       => 'developer',
		'import.php'                      => 'developer',
		'export.php'                      => 'developer',
		'theme-editor.php'                => 'developer',  // Theme editor (native Appearance sub-menu)
		'plugin-editor.php'               => 'developer',  // Plugin editor (native Plugins sub-menu)
		// Double Youpi itself — WP top-level, classified as Developer (tool for pros).
		// Present in the Developer chipbar in FIRST position (see ITEM_WEIGHT).
		'doubleyoupi'                     => 'developer',
		// Links — deprecated in WP, re-enabled by some plugins. Forced to null
		// to prevent it from becoming the default link for the line
		// (see Rail::menu_url which takes the first collected item).
		'edit-tags.php?taxonomy=link_category' => null, // ignored
		'link-manager.php'                => null,
	];

	// ── ~100 curated plugins ────────────────────────────────────
	// menu-slug → line mapping. For each line, common slugs observed
	// on the WP.org plugin repo (top 200 most active).
	private const PLUGIN_LINE_MAP = [
		// ── content: custom fields, translation, editorial ──
		// Note: SEO plugins (Yoast, Rank Math, AIOSEO, SEOPress, etc.)
		// are in the DEVELOPER LINE — they are technical tooling for
		// pros who build sites, not raw content production
		// (titles, descriptions, schema → dev-SEO trade).
		// (see user request 2026-05-20)
		// (ACF moved to Developer below — schema rather than content)
		'cptui_main_menu'                 => 'content',  // Custom Post Type UI
		'pods'                            => 'content',
		'meta-box'                        => 'content',
		'toolset-dashboard'               => 'content',
		'mlang'                           => 'content',  // Polylang
		'sitepress-multilingual-cms/menu/languages.php' => 'content', // WPML
		'trp_translation_settings'        => 'content',  // TranslatePress
		'loco'                            => 'content',
		'duplicate-post'                  => 'content',
		'duplicate-page'                  => 'content',
		'pp-content-calendar'             => 'content',  // PublishPress
		'cms-tpv-page-post'               => 'content',  // Editorial Calendar
		'tablepress'                      => 'content',
		'pretty-link'                     => 'content',
		'thirstyaffiliates'               => 'content',
		'broken-link-checker'             => 'content',
		'redirection.php'                 => 'content',  // Redirection (J. Godfrey)
		// ACF (custom fields + groups) — schema structuring tool,
		// Developer rather than Content.
		'edit.php?post_type=acf-field-group' => 'developer',

		// ── commerce: ecommerce, paid membership, LMS, booking ──
		'woocommerce'                     => 'commerce',
		'woocommerce-marketing'           => 'commerce',  // WC Marketing section (top-level)
		'wc-admin'                        => 'commerce',  // WC React admin (fallback slug)
		'wc-admin&path=/analytics/overview' => 'commerce', // WC Analytics
		'wc-admin&path=/marketing'        => 'commerce',  // WC Marketing alt URL
		// WooCommerce CPT top-level: `Products` is a first-level menu
		// (edit.php?post_type=product) → the generic heuristic would place it
		// in `content` (all edit.php?post_type=* → content). Explicit key =
		// takes priority over the heuristic, so it lands in Commerce as expected.
		'edit.php?post_type=product'      => 'commerce',  // Products
		'edit.php?post_type=product_variation' => 'commerce',
		'edit.php?post_type=shop_order'   => 'commerce',  // Orders (legacy CPT)
		'wc-orders'                       => 'commerce',  // Orders (HPOS)
		'edit.php?post_type=shop_coupon'  => 'commerce',  // Coupons
		'edd-payments'                    => 'commerce',  // EDD
		'memberpress'                     => 'commerce',
		'simple-pay'                      => 'commerce',  // WP Simple Pay
		'surecart'                        => 'commerce',
		'rcp-members'                     => 'commerce',  // Restrict Content Pro
		'paid-memberships-pro'            => 'commerce',
		'learndash-lms'                   => 'commerce',
		'lifterlms'                       => 'commerce',
		'tutor'                           => 'commerce',
		'sensei'                          => 'commerce',
		'stm-lms-courses'                 => 'commerce',
		'learn_press'                     => 'commerce',
		'wpbs'                            => 'commerce',
		'bookingpress_dashboard'          => 'commerce',
		'mphb_dashboard'                  => 'commerce',  // MotoPress Hotel
		'bookly-appointments'             => 'commerce',
		'rtb-bookings'                    => 'commerce',  // Restaurant Reservations
		// (wpforo → community: a forum belongs to the community, see community section.)

		// ── users: accounts / roles only ──
		'users-user-switching'            => 'users',
		'members'                         => 'users',
		'user-role-editor'                => 'users',
		'profile-builder'                 => 'users',
		'wp-user-frontend'                => 'users',

		// ── community: comments, forms, newsletter, forum, social ──
		'wpdiscuz'                        => 'community',
		'akismet-key-config'              => 'community',
		'antispam_bee'                    => 'community',
		'wpcf7'                           => 'community',  // Contact Form 7
		'wpforms-overview'                => 'community',
		'gf_edit_forms'                   => 'community',  // Gravity Forms
		'ninja-forms'                     => 'community',
		'forminator'                      => 'community',
		'fluent_forms'                    => 'community',
		'formidable'                      => 'community',
		'evf-forms'                       => 'community',  // Everest Forms
		'mailpoet-newsletters'            => 'community',
		'fluentcrm-admin'                 => 'community',
		'newsletter-main'                 => 'community',
		'brevo-dashboard'                 => 'community',
		'optin-monster-api-settings'      => 'community',
		'mailster'                        => 'community',
		'bp-components'                   => 'community',  // BuddyPress
		'buddyboss-platform'              => 'community',
		'bbpress'                         => 'community',
		'peepso_config'                   => 'community',
		'ultimatemember'                  => 'community',
		'wpforo'                          => 'community',  // Forum → community.

		// ── site: themes / nav / sliders / galleries / blocks ──
		// (page builders → STANDALONE_BUILDERS, see below)
		// Elementor sub-pages that surface at the top level (the standalone slot
		// `elementor` covers Templates, but Elementor also creates a Home,
		// an App, and CPTs for landing pages / popups, etc.).
		'elementor-home'                  => 'site',
		'elementor-app'                   => 'site',
		'elementor-custom-fonts'          => 'site',
		'elementor-custom-icons'          => 'site',
		'edit.php?post_type=elementor_library' => 'site',
		'edit.php?post_type=e-landing-page' => 'site',
		'edit.php?post_type=e-floating-buttons' => 'site',
		'e-form-submissions'              => 'community', // Elementor Pro Forms
		'generateblocks'                  => 'site',
		'spectra'                         => 'site',
		'kadence_blocks'                  => 'site',
		'maxmegamenu'                     => 'site',
		'uberMenu'                        => 'site',
		'revslider'                       => 'site',
		'metaslider'                      => 'site',
		'smart-slider3'                   => 'site',
		'ls-layerslider'                  => 'site',
		'envira-gallery-lite'             => 'site',
		'hello-elementor'                 => 'site',  // Hello Elementor theme pages (fell into System)
		'nextgen-gallery'                 => 'site',
		'modula-lite'                     => 'site',

		// ── system: perf, security, backup, image opt, analytics ──
		'wp-rocket'                       => 'system',
		'litespeed-cache'                 => 'system',
		'litespeed'                       => 'system',  // LiteSpeed Cache 7.x registers `page=litespeed`
		// UpdraftPlus registers its top-level via add_options_page, which yields
		// the full slug `options-general.php?page=updraftplus`. We map it
		// explicitly rather than relying on the heuristic (which does not
		// recognize the word "updraft").
		'options-general.php?page=updraftplus' => 'system',
		'updraftcentral'                  => 'system',
		'udmedia'                         => 'system',
		'w3tc_dashboard'                  => 'system',
		'wpsupercache'                    => 'system',
		'wpfastestcacheoptions'           => 'system',
		'wphb'                            => 'system',  // Hummingbird
		'autoptimize'                     => 'system',
		'wpassetcleanup'                  => 'system',
		'perfmatters'                     => 'system',
		'flying-press'                    => 'system',
		'wp-smush'                        => 'system',
		'wp-shortpixel'                   => 'system',
		'imagify'                         => 'system',
		'ewww-image-optimizer'            => 'system',
		'wordfence'                       => 'system',
		'sucuriscan'                      => 'system',
		'itsec'                           => 'system',  // Solid Security
		'aiowpsec_dashboard'              => 'system',
		'limit-login-attempts-reloaded'   => 'system',
		'wps-hide-login'                  => 'system',
		'hide-my-wp-ghost'                => 'system',
		'updraftplus'                     => 'system',
		'backwpup'                        => 'system',
		'duplicator'                      => 'system',
		'ai1wm_export'                    => 'system',  // All-in-One WP Migration
		'wp-migrate-db'                   => 'system',
		'googlesitekit-dashboard'         => 'system',
		'monsterinsights_settings'        => 'system',
		'monsterinsights_reports'         => 'system',  // top-level slug of MonsterInsights 11.x
		'wp-mail-smtp'                    => 'system',  // WP Mail SMTP (delivery configuration)
		'jetpack'                         => 'system',  // Jetpack (security, performance, stats)
		'exactmetrics_settings'           => 'system',
		'wp-maintenance-mode'             => 'system',

		// ── developer: debug, DB, code snippets, file mgmt ──
		'query-monitor'                   => 'developer',
		'snippets'                        => 'developer',  // Code Snippets
		'wpcode'                          => 'developer',
		'debug-bar'                       => 'developer',
		'health-check'                    => 'developer',
		'wp_file_manager'                 => 'developer',
		'wp-optimize'                     => 'developer',
		'advanced-db-cleaner'             => 'developer',
		// SEO plugins: technical tooling (schema, indexation, sitemaps,
		// meta tags) → dev trade rather than content. Reclassified from
		// 'content' on 2026-05-20.
		'wpseo_dashboard'                 => 'developer',  // Yoast SEO
		'rank-math'                       => 'developer',  // Rank Math
		'aioseo'                          => 'developer',  // All in One SEO
		'seopress-option'                 => 'developer',
		'autodescription-settings'        => 'developer',  // The SEO Framework
		'slim-seo'                        => 'developer',
	];

	// ── URL overrides for top-levels with a null callback ───────
	// Some plugins register an `add_menu_page` with a null callback
	// (the top-level has no content of its own; it is just a container
	// for its sub-menus). WP-default renders "Cannot load {slug}"
	// when clicking directly on the URL `admin.php?page={slug}` without
	// falling back to the first sub-menu.
	//
	// We override the URL returned by Rail::menu_url() to point directly
	// to the useful page for each plugin.
	//
	// Format: WP top-level slug → relative admin URL (without the admin.php prefix).
	public const URL_OVERRIDE = [
		// WC: the `woocommerce` top-level has callback=null. The React
		// WC-admin dashboard lives at `admin.php?page=wc-admin&path=/`.
		'woocommerce' => 'admin.php?page=wc-admin&path=%2F',
	];

	/**
	 * Returns the override URL for a top-level slug, or null if there is
	 * no override. Consumed by Rail::menu_url().
	 */
	public static function url_override( string $slug ): ?string {
		return self::URL_OVERRIDE[ $slug ] ?? null;
	}

	// ── Split native menus ──────────────────────────────────────
	// A native top-level menu whose own page and sub-pages do not belong to
	// the same line. In the reimagined tree the top-level page becomes a chip
	// of its own (its label read from its own sub-menu entry: "Themes", not
	// "Appearance") on the line NATIVE_MAP gives it, and every other sub-page
	// becomes a chip of the children line. The native tree keeps the menu.
	private const SPLIT_MENUS = [
		'themes.php' => 'site', // children line: Editor, Fonts, Menus, Widgets, theme pages
	];

	/** @return string[] Top-level slugs of the split menus. */
	public static function split_menus(): array {
		return array_keys( self::SPLIT_MENUS );
	}

	public static function is_split_menu( string $slug ): bool {
		return isset( self::SPLIT_MENUS[ self::map_key( $slug ) ] );
	}

	/** Line receiving the sub-pages of a split menu. */
	public static function split_children_line( string $slug ): string {
		return self::SPLIT_MENUS[ self::map_key( $slug ) ] ?? 'site';
	}

	// ── Standalone page builders ────────────────────────────────
	// Display a dedicated slot (brand icon + label) ABOVE the 7 lines,
	// separated by a divider. Only builders actively registered at the
	// top level are surfaced.
	private const STANDALONE_BUILDERS = [
		'elementor'              => [ 'icon' => 'i-builder-elementor', 'label' => 'Elementor' ],
		'bricks'                 => [ 'icon' => 'i-builder-bricks',    'label' => 'Bricks' ],
		'et_divi_options'        => [ 'icon' => 'i-builder-divi',      'label' => 'Divi' ],
		'fl-builder'             => [ 'icon' => 'i-builder-beaver',    'label' => 'Beaver Builder' ],
		'brizy-edit'             => [ 'icon' => 'i-builder-brizy',     'label' => 'Brizy' ],
		'ct_dashboard_page'      => [ 'icon' => 'i-builder-oxygen',    'label' => 'Oxygen' ],
		'vc-general'             => [ 'icon' => 'i-builder-wpbakery',  'label' => 'WPBakery' ],
		'vcv-getting-started'    => [ 'icon' => 'i-builder-vc',        'label' => 'Visual Composer' ],
		'seedprod_lite'          => [ 'icon' => 'i-builder-seedprod',  'label' => 'SeedProd' ],
	];

	// ── Builders with an "externally managed" admin UI ──────────
	// Subset of STANDALONE_BUILDERS whose admin pages do NOT use native
	// WP layouts but a proprietary interface (React/SPA). We do NOT apply
	// our chrome to them (chipbar, notices dock, content design-system),
	// and the reorganizer displays "hierarchy managed by the extension"
	// instead of listing their sub-pages. The global rail remains visible
	// for navigation consistency. Extensible via the filter
	// `doubleyoupi_admin_managed_builders`.
	private const EXTERNALLY_MANAGED_BUILDERS = [
		'elementor' => true,
	];

	// ── Custom chip icons for prominent plugins (non-builders) ──
	// When one of these slugs appears in its line's chipbar,
	// the custom icon replaces the standard dot to the left of the label.
	// All `i-plugin-*` icons listed here are defined in Sprite.php.
	private const PLUGIN_CHIP_ICON = [
		'woocommerce'                     => 'i-plugin-woo',
		'wpseo_dashboard'                 => 'i-plugin-yoast',
		'rank-math'                       => 'i-plugin-rankmath',
		'gf_edit_forms'                   => 'i-plugin-gravityforms',
		'wpforms-overview'                => 'i-plugin-wpforms',
		'updraftplus'                     => 'i-plugin-updraftplus',
		'wordfence'                       => 'i-plugin-wordfence',
		'wp-rocket'                       => 'i-plugin-wprocket',
		'litespeed-cache'                 => 'i-plugin-litespeed',
		'litespeed'                       => 'i-plugin-litespeed',
		'learndash-lms'                   => 'i-plugin-learndash',
		'buddyboss-platform'              => 'i-plugin-buddyboss',
		'mailpoet-newsletters'            => 'i-plugin-mailpoet',
		'fluentcrm-admin'                 => 'i-plugin-fluentcrm',
	];

	// ── Standalone themes ───────────────────────────────────────────────
	// Display a dedicated slot (brand icon + label) in the rail JUST ABOVE
	// the builders (and above the Developer line), if the theme is ACTIVE
	// and exposes an admin page to navigate to.
	//
	// KEY = theme slug (matched against `get_template()` which returns the
	//       parent slug, so child themes inherit automatically).
	// menu_slug = admin page to navigate to when the item is clicked.
	//             If the page is not registered in $menu, falls back
	//             to themes.php.
	private const STANDALONE_THEMES = [
		'astra'         => [ 'icon' => 'i-theme-astra',         'label' => 'Astra',         'menu_slug' => 'astra' ],
		'generatepress' => [ 'icon' => 'i-theme-generatepress', 'label' => 'GeneratePress', 'menu_slug' => 'generate-options' ],
		'kadence'       => [ 'icon' => 'i-theme-kadence',       'label' => 'Kadence',       'menu_slug' => 'kadence' ],
		'blocksy'       => [ 'icon' => 'i-theme-blocksy',       'label' => 'Blocksy',       'menu_slug' => 'ct-dashboard' ],
		'oceanwp'       => [ 'icon' => 'i-theme-oceanwp',       'label' => 'OceanWP',       'menu_slug' => 'oceanwp-panel' ],
		'neve'          => [ 'icon' => 'i-theme-neve',          'label' => 'Neve',          'menu_slug' => 'neve-welcome' ],
		'hestia'        => [ 'icon' => 'i-theme-hestia',        'label' => 'Hestia',        'menu_slug' => 'hestia-welcome' ],
		'Avada'         => [ 'icon' => 'i-theme-avada',         'label' => 'Avada',         'menu_slug' => 'avada' ],
		'genesis'       => [ 'icon' => 'i-theme-genesis',       'label' => 'Genesis',       'menu_slug' => 'genesis' ],
		'salient'       => [ 'icon' => 'i-theme-salient',       'label' => 'Salient',       'menu_slug' => 'salient-getting-started' ],
		'dt-the7'       => [ 'icon' => 'i-theme-the7',          'label' => 'The7',          'menu_slug' => 'dt-dashboard' ],
		'betheme'       => [ 'icon' => 'i-theme-betheme',       'label' => 'BeTheme',       'menu_slug' => 'betheme_options' ],
		'bridge'        => [ 'icon' => 'i-theme-bridge',        'label' => 'Bridge',        'menu_slug' => 'qode_dashboard' ],
		'enfold'        => [ 'icon' => 'i-theme-enfold',        'label' => 'Enfold',        'menu_slug' => 'avia' ],
		'Newspaper'     => [ 'icon' => 'i-theme-newspaper',     'label' => 'Newspaper',     'menu_slug' => 'td_theme_panel' ],
		'soledad'       => [ 'icon' => 'i-theme-soledad',       'label' => 'Soledad',       'menu_slug' => 'penci-options' ],
		'jupiterx'      => [ 'icon' => 'i-theme-jupiterx',      'label' => 'Jupiter X',     'menu_slug' => 'jupiterx' ],
		'phlox'         => [ 'icon' => 'i-theme-phlox',         'label' => 'Phlox',         'menu_slug' => 'phlox' ],
	];

	public const LINE_ORDER = [ 'content', 'commerce', 'users', 'community', 'site', 'system', 'developer' ];

	/**
	 * Explicit display order within a line. The LOWER the weight, the earlier
	 * the item appears in the chipbar. Unlisted slugs get a default weight
	 * of 100 (native WP order after these).
	 *
	 * E.g. in Content: Pages → Posts → Projects → Media.
	 * E.g. in Developer: ACF → Tools / Import / Export / Editors.
	 */
	private const ITEM_WEIGHT = [
		// Content
		'edit.php?post_type=page'              => 10,  // Pages
		'edit.php'                             => 20,  // Posts
		// Custom post types (post_type=project etc.) fall to 30 via heuristic below
		'upload.php'                           => 90,  // Media last

		// System — Site Health at the TOP (default chip when entering the System
		// line, user request 2026-05-20), then Settings + sub-options,
		// Updates at the bottom.
		'site-health.php'                      => 5,
		'options-general.php'                  => 10,
		'options-writing.php'                  => 20,
		'options-reading.php'                  => 30,
		'options-discussion.php'               => 40,
		'options-permalink.php'                => 50,
		'options-privacy.php'                  => 60,
		'plugins.php'                          => 70,  // Extensions, then Themes: the stack, before Updates
		'themes.php'                           => 80,
		'update-core.php'                      => 90,

		// Developer — Double Youpi at the TOP, then ACF, then tools/editors
		'doubleyoupi'                          => 0,   // very top
		'edit.php?post_type=acf-field-group'   => 5,
		'tools.php'                            => 10,
		'import.php'                           => 20,
		'export.php'                           => 30,
		'theme-editor.php'                     => 80,  // after tools / import / export
		'plugin-editor.php'                    => 90,
	];

	/**
	 * Returns the weight of an item for sorting in the chipbar. Custom CPTs
	 * (edit.php?post_type=<x>) that are not explicitly weighted fall in the
	 * 30-49 window (between Posts and Media).
	 */
	public static function item_weight( string $slug ): int {
		$slug = self::normalize_slug( $slug );
		if ( isset( self::ITEM_WEIGHT[ $slug ] ) ) {
			return self::ITEM_WEIGHT[ $slug ];
		}
		if ( str_starts_with( $slug, 'edit.php?post_type=' ) ) {
			return 30; // Custom CPTs → after Posts, before Media
		}
		return 100; // default, preserves the relative native WP order
	}

	/**
	 * Classifies a top-level menu slug into a line, 'standalone' (builder),
	 * or null (Dashboard, to be ignored in the rail).
	 *
	 * Returns a string from: 'content', 'commerce', 'users', 'community',
	 * 'site', 'system', 'developer', 'standalone' OR null for Dashboard.
	 *
	 * @return string|null
	 */
	public static function classify( string $menu_slug ): ?string {
		$menu_slug = self::map_key( $menu_slug );
		// NOTE: standalone_owner() (attaching a builder's satellite pages to its
		// column) is NOT applied here, so those pages do not vanish from the live
		// rail while Rail cannot yet absorb them. The column model (snapshot v2 /
		// Rail v2) uses it explicitly upstream. Here, classify stays "source line".
		// 1. Standalone builders (exact top-level) — absolute priority.
		if ( isset( self::STANDALONE_BUILDERS[ $menu_slug ] ) ) {
			$line = 'standalone';
		}
		// 2. Native WP menus (can be null → Dashboard).
		elseif ( array_key_exists( $menu_slug, self::NATIVE_MAP ) ) {
			$line = self::NATIVE_MAP[ $menu_slug ];
		}
		// 3. Curated plugin map.
		elseif ( isset( self::PLUGIN_LINE_MAP[ $menu_slug ] ) ) {
			$line = self::PLUGIN_LINE_MAP[ $menu_slug ];
		}
		// 4. Heuristic on the slug.
		else {
			$line = self::heuristic( $menu_slug );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				self::log_unmapped( $menu_slug, (string) $line );
			}
		}

		/**
		 * Filter: doubleyoupi_admin_plugin_line
		 * Allows overriding the line for a given slug.
		 *
		 * @param string|null $line  The classified line (or null for Dashboard)
		 * @param string      $slug  The menu slug being analyzed
		 */
		return apply_filters( 'doubleyoupi_admin_plugin_line', $line, $menu_slug );
	}

	/** Unknown menu slugs already reported, so each is logged once a day. */
	private const UNMAPPED_LOGGED = 'doubleyoupi_admin_unmapped_logged';

	/**
	 * Debug diagnostic for a slug the maps do not know: ONE line per slug,
	 * then silence for a day. It used to be written on every admin screen,
	 * for every unknown entry (ten unknown plugins, ten lines per click),
	 * drowning the debug.log where a developer looks for real errors.
	 */
	private static function log_unmapped( string $slug, string $line ): void {
		static $seen = null;
		if ( ! function_exists( 'get_transient' ) ) {
			return;
		}
		if ( null === $seen ) {
			$stored = get_transient( self::UNMAPPED_LOGGED );
			$seen   = is_array( $stored ) ? $stored : [];
		}
		if ( isset( $seen[ $slug ] ) ) {
			return;
		}
		$seen[ $slug ] = 1;
		set_transient( self::UNMAPPED_LOGGED, $seen, DAY_IN_SECONDS );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only diagnostic, gated behind WP_DEBUG, once per slug and day.
		error_log( "[doubleyoupi-admin] unmapped plugin slug '{$slug}' -> heuristic returned '{$line}'" );
	}

	/**
	 * Canonical normalization of a menu slug — SINGLE SOURCE OF TRUTH for
	 * an item's identity. Applied at ALL boundaries (snapshot, override storage,
	 * live $menu reading, ScreenDisabler signatures) so that the same page
	 * always has exactly the same key.
	 *
	 * Rule: we reorder the parameters of a real query-string `base?k=v&…`
	 * (deterministic) without touching "page" slugs that embed `&` without `?`
	 * (WC style `wc-admin&path=/…`), nor the case (themes are case-sensitive
	 * via get_template()). Idempotent.
	 */
	/**
	 * Lookup key for the curated maps: the normalised slug, lower-cased.
	 * WordPress menu slugs are case-sensitive in URLs (`page=Wordfence`), but
	 * our maps are written in lower case: without this, Wordfence, LiteSpeed
	 * (`litespeed`) or MonsterInsights fell through to the default line by
	 * accident and lost their chip icon (audit-2, navigation review).
	 */
	private static function map_key( string $slug ): string {
		return strtolower( self::normalize_slug( $slug ) );
	}

	public static function normalize_slug( string $slug ): string {
		// WordPress registers taxonomy sub-menus with an HTML-encoded
		// ampersand (`edit-tags.php?taxonomy=x&amp;post_type=y`): decoded
		// first, or `parse_str` would read a key named `amp;post_type` and
		// the slug would never equal its own screen.
		$slug = trim( str_replace( '&amp;', '&', $slug ) );
		if ( '' === $slug || ! str_contains( $slug, '?' ) ) {
			return $slug;
		}
		[ $base, $query ] = explode( '?', $slug, 2 );
		if ( '' === $query ) {
			return $base;
		}
		parse_str( $query, $params );
		if ( empty( $params ) ) {
			return $base;
		}
		ksort( $params );
		// http_build_query encodes values (path=%2F…) in a stable way:
		// as long as ALL boundaries go through this function, equality holds.
		return $base . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	// ── Satellite pages owned by a standalone builder ────────────────────
	// Top-level slug prefixes that belong to a builder: the page is attached
	// as a sub-page of the builder's column instead of being classified into
	// a line. KEY = prefix, VALUE = owner builder slug.
	private const STANDALONE_OWNED_PREFIX = [
		'elementor'  => 'elementor',
		'e-'         => 'elementor',   // e-landing-page, e-floating-buttons CPT
		'bricks'     => 'bricks',
		'brizy'      => 'brizy-edit',
		'et_'        => 'et_divi_options',
		'et-'        => 'et_divi_options',
		'fl-builder' => 'fl-builder',
		'vcv-'       => 'vcv-getting-started',
		'seedprod'   => 'seedprod_lite',
	];

	// CPTs (edit.php?post_type=…) belonging to a builder.
	private const STANDALONE_OWNED_CPT = [
		'elementor_library' => 'elementor',
		'e-landing-page'    => 'elementor',
		'e-floating-buttons'=> 'elementor',
		'bricks_template'   => 'bricks',
		'brizy_template'    => 'brizy-edit',
	];

	/**
	 * Returns the slug of the standalone builder that "owns" this satellite
	 * admin page, or null. Used by classify() (→ 'standalone') and by Rail
	 * to attach the page as a sub-page of the builder's column.
	 *
	 * Excludes the exact slug of the builder itself (that is the column top,
	 * handled by STANDALONE_BUILDERS).
	 */
	public static function standalone_owner( string $slug ): ?string {
		$slug = self::normalize_slug( $slug );
		if ( '' === $slug || isset( self::STANDALONE_BUILDERS[ $slug ] ) ) {
			return null;
		}
		// CPT builder ?
		if ( str_starts_with( $slug, 'edit.php?post_type=' ) ) {
			$pt = substr( $slug, strlen( 'edit.php?post_type=' ) );
			return self::STANDALONE_OWNED_CPT[ $pt ] ?? null;
		}
		$s = strtolower( $slug );
		foreach ( self::STANDALONE_OWNED_PREFIX as $prefix => $owner ) {
			// The builder must be active (its exact top-level present) for us
			// to absorb its satellites — otherwise a third-party plugin named
			// "elementor-addons" would be incorrectly swallowed.
			if ( ( $s === $prefix || str_starts_with( $s, $prefix ) ) && self::standalone_is_active( $owner ) ) {
				return $owner;
			}
		}
		return null;
	}

	/**
	 * True if the standalone builder $owner is registered at the top level in
	 * the live $menu (i.e. active). Memoized per request.
	 *
	 * @var array<string,bool>|null
	 */
	private static ?array $standalone_active_cache = null;
	private static function standalone_is_active( string $owner ): bool {
		if ( null === self::$standalone_active_cache ) {
			self::$standalone_active_cache = [];
			global $menu;
			if ( is_array( $menu ) ) {
				foreach ( $menu as $entry ) {
					if ( is_array( $entry ) && isset( $entry[2] ) ) {
						self::$standalone_active_cache[ self::normalize_slug( (string) $entry[2] ) ] = true;
					}
				}
			}
		}
		return isset( self::$standalone_active_cache[ $owner ] );
	}

	/**
	 * True if the slug is in one of the curated sources (NATIVE_MAP,
	 * PLUGIN_LINE_MAP or STANDALONE_BUILDERS). Allows NavigationDetector
	 * to isolate truly unknown slugs (which deserve to be surfaced to the admin
	 * in the "Detected" drawer of the Developer editor) from those already
	 * explicitly classified.
	 */
	public static function is_curated( string $slug ): bool {
		$slug = self::map_key( $slug );
		return array_key_exists( $slug, self::NATIVE_MAP )
			|| array_key_exists( $slug, self::PLUGIN_LINE_MAP )
			|| array_key_exists( $slug, self::STANDALONE_BUILDERS )
			|| null !== self::standalone_owner( $slug );
	}

	/**
	 * Public alias for the heuristic — used by NavigationDetector to
	 * suggest a line when displaying detected plugins.
	 */
	public static function guess_line( string $slug ): string {
		return self::heuristic( $slug );
	}

	/**
	 * Strict variant: returns the line IF a precise pattern matches,
	 * otherwise null (without falling through to the `'system'` catch-all).
	 * Distinguishes a CONFIDENT classification (a dedicated pattern matched)
	 * from a lazy default.
	 *
	 * Used by NavigationDetector to decide whether a slug deserves to be
	 * surfaced in the "Detected" drawer: only uncertain classifications
	 * (= no rule matched) appear there. A CPT (`edit.php?post_type=foo`) or
	 * a slug containing a recognized keyword (`-seo`, `-cache`,
	 * `gravity-forms`, etc.) is treated as already correctly classified,
	 * without prompting the admin.
	 */
	public static function guess_line_strict( string $slug ): ?string {
		$slug = self::normalize_slug( $slug );
		$s    = strtolower( $slug );

		// CPT → content (strong and explicit signal).
		if ( str_starts_with( $s, 'edit.php?post_type=' ) ) {
			return 'content';
		}
		// WooCommerce admin pages (convention `?page=wc-…` or the
		// PAYMENTS_MENU_ITEM marker). WC registers top-levels such as "Payments"
		// pointing to `admin.php?from=PAYMENTS_MENU_ITEM&page=wc-settings&…`
		// — without this rule they fell into "Plugin pages" instead of Commerce.
		if ( preg_match( '/[?&]page=(wc-|woocommerce)/', $s ) || str_contains( $s, 'payments_menu_item' ) ) {
			return 'commerce';
		}
		// Known builder / theme prefixes → 'site' (confident classification:
		// not surfaced in "Detected"). Until Rail absorbs these satellites into
		// the builder's column they stay in Site (historical behavior, zero
		// regression). The column model will group them via standalone_owner().
		if ( preg_match( '/^(elementor|bricks|divi|et_|et-|fl-builder|brizy|ct_|vc-|vcv-|seedprod|oxygen|astra|generatepress|kadence|blocksy|oceanwp|neve|hestia|avada|genesis|salient|the7|betheme|bridge|enfold|newspaper|soledad|jupiterx|phlox)(-|_|$)/', $s ) ) {
			return 'site';
		}
		// commerce — prefixes/keywords with unambiguous commercial meaning.
		if ( preg_match( '/^(wc-|woocommerce|edd-|yith-|dokan-)|(^|[-_])(shop|product|order|payment|stripe|paypal|membership|subscription|lms|course|booking|invoice|cart|checkout|coupon|wholesale)([-_]|$)/', $s ) ) {
			return 'commerce';
		}
		// community — forms, newsletters, forums, social. Anchored words
		// (prevents "email-*" or "*-comment-cache" from drifting here).
		if ( preg_match( '/(^|[-_])(form|forms|newsletter|crm|forum|community|social|buddy|peepso|mailpoet|mailster|brevo|sendinblue)([-_]|$)|^(wpcf7|bbpress|bp_|gf_|wpforms)/', $s ) ) {
			return 'community';
		}
		// developer — debug, DB, snippets, technical SEO.
		if ( preg_match( '/(^|[-_])(debug|snippet|snippets|cli|database|developer|inspect|query-monitor)([-_]|$)|(^|[-_])seo([-_]|$)|seo$|file-manager|wp-optimize|db-cleaner/', $s ) ) {
			return 'developer';
		}
		// users — accounts / roles / profiles (anchored).
		if ( preg_match( '/(^|[-_])(user|users|role|roles|profile|account|membership-role)([-_]|$)/', $s ) ) {
			return 'users';
		}
		// system — perf, security, backup, migration, analytics (anchored).
		if ( preg_match( '/(^|[-_])(security|backup|cache|cleaner|optimize|optimiz|analytics|maintenance|firewall|migrate|migration|updraft|wpvivid|duplicator|staging|kinsta|wpengine|cloudflare|sucuri|wordfence)([-_]|$)/', $s ) ) {
			return 'system';
		}
		// content — translation, fields, tables (anchored; generic "publish/field"
		// removed from the strict path to avoid swallowing unknowns).
		if ( preg_match( '/(^|[-_])(polylang|wpml|translate|translatepress|loco|acf|tablepress|redirection)([-_]|$)/', $s ) ) {
			return 'content';
		}
		// site — themes / nav / sliders / galleries. Anchored words (raw "menu/block/
		// theme" removed: too many false positives; see audit).
		if ( preg_match( '/(^|[-_])(slider|gallery|mega-?menu|nav-menu|page-builder|customizer)([-_]|$)/', $s ) ) {
			return 'site';
		}
		return null;
	}

	/**
	 * Guesses the line from patterns in the slug. Catch-all → system.
	 *
	 * Two levels: `guess_line_strict()` (high confidence, controls appearance
	 * in the "Detected" drawer) THEN `loose_guess()` (broad patterns, just
	 * for a reasonable placement), finally 'system'. This separation ensures
	 * that a truly unknown plugin falls to `null` at the strict level (therefore
	 * surfaced to the admin) while still being placed unobtrusively.
	 */
	private static function heuristic( string $slug ): string {
		return self::guess_line_strict( $slug ) ?? self::loose_guess( $slug ) ?? 'system';
	}

	/**
	 * Broad patterns for a default placement (never used to decide detection).
	 * May return null.
	 */
	private static function loose_guess( string $slug ): ?string {
		$s = strtolower( self::normalize_slug( $slug ) );
		if ( preg_match( '/marketing|loyalty|affiliate/', $s ) ) {
			return 'commerce';
		}
		if ( preg_match( '/email|subscribe|chat|comment/', $s ) ) {
			return 'community';
		}
		if ( preg_match( '/theme|builder|widget|block|menu|customiz/', $s ) ) {
			return 'site';
		}
		if ( preg_match( '/field|publish|editorial|content/', $s ) ) {
			return 'content';
		}
		if ( preg_match( '/log|update|import|export|tool/', $s ) ) {
			return 'developer';
		}
		return null;
	}

	/**
	 * Full list of standalone builders (filters applied).
	 *
	 * @return array<string, array{icon: string, label: string}>
	 */
	public static function standalone_builders(): array {
		return apply_filters( 'doubleyoupi_admin_standalone', self::STANDALONE_BUILDERS );
	}

	/**
	 * True if `$slug` is a builder whose admin UI is "externally managed"
	 * (proprietary interface, see EXTERNALLY_MANAGED_BUILDERS). On its screens
	 * we step back: no chipbar, no notices dock, no content design-system,
	 * and the reorganizer does not expose its hierarchy. The global rail
	 * remains visible.
	 *
	 * @param string $slug Top-level slug (will be normalized).
	 */
	public static function is_externally_managed_builder( string $slug ): bool {
		$slug = self::normalize_slug( $slug );
		if ( '' === $slug ) {
			return false;
		}
		/**
		 * Filters the list of builders with an externally managed UI.
		 *
		 * @param array<string,bool> $list map slug => true.
		 */
		$list = apply_filters( 'doubleyoupi_admin_managed_builders', self::EXTERNALLY_MANAGED_BUILDERS );
		return is_array( $list ) && ! empty( $list[ $slug ] );
	}

	/**
	 * True if `$top_level_slug` corresponds to a "branded" top-level — a builder
	 * (STANDALONE_BUILDERS), a prominent plugin (PLUGIN_CHIP_ICON), or the menu
	 * of an active standalone theme. Used to suppress generic injections
	 * (Eyebrow, etc.) on screens where context is carried by the brand chrome
	 * (builder chipbar, custom icon in the rail, etc.).
	 */
	public static function is_branded_top_level( string $top_level_slug ): bool {
		if ( '' === $top_level_slug ) {
			return false;
		}
		if ( isset( self::STANDALONE_BUILDERS[ self::map_key( $top_level_slug ) ] ) ) {
			return true;
		}
		if ( isset( self::PLUGIN_CHIP_ICON[ self::map_key( $top_level_slug ) ] ) ) {
			return true;
		}
		$theme = self::active_theme();
		if ( $theme && isset( $theme['slug'] ) ) {
			$theme_meta = self::STANDALONE_THEMES[ $theme['slug'] ] ?? null;
			if ( $theme_meta && ( $theme_meta['menu_slug'] ?? '' ) === $top_level_slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Full list of standalone themes (filters applied).
	 *
	 * @return array<string, array{icon: string, label: string, menu_slug: string}>
	 */
	public static function standalone_themes(): array {
		return apply_filters( 'doubleyoupi_admin_themes', self::STANDALONE_THEMES );
	}

	/**
	 * Detects the ACTIVE theme and returns its metadata if present in
	 * STANDALONE_THEMES. Otherwise null. Uses `get_template()` (parent slug),
	 * so child themes inherit automatically.
	 *
	 * @return array{slug:string, icon:string, label:string, url:string}|null
	 */
	public static function active_theme(): ?array {
		$map = self::standalone_themes();
		$slug = (string) get_template();
		if ( ! isset( $map[ $slug ] ) ) {
			return null;
		}
		$meta      = $map[ $slug ];
		$menu_slug = (string) ( $meta['menu_slug'] ?? '' );

		// If the admin page is registered, link to it; otherwise fall back
		// to themes.php?theme={slug} (native WP theme details popup).
		$url = '';
		if ( $menu_slug ) {
			global $menu;
			$registered = false;
			if ( is_array( $menu ) ) {
				foreach ( $menu as $entry ) {
					if ( is_array( $entry ) && ( $entry[2] ?? '' ) === $menu_slug ) {
						$registered = true;
						break;
					}
				}
			}
			$url = $registered
				? ( str_contains( $menu_slug, '.php' ) ? admin_url( $menu_slug ) : admin_url( 'admin.php?page=' . rawurlencode( $menu_slug ) ) )
				: admin_url( 'themes.php?theme=' . rawurlencode( $slug ) );
		} else {
			$url = admin_url( 'themes.php?theme=' . rawurlencode( $slug ) );
		}

		return [
			'slug'  => $slug,
			'icon'  => (string) $meta['icon'],
			'label' => (string) $meta['label'],
			'url'   => $url,
		];
	}

	/**
	 * True for WordPress's own top-level screens (NATIVE_MAP): they keep the
	 * neutral dot in the chipbar, only plugin entries show their menu icon.
	 */
	public static function is_native( string $slug ): bool {
		return array_key_exists( self::map_key( $slug ), self::NATIVE_MAP );
	}

	/**
	 * Raw icon declared by a top-level menu entry (`$menu[...][6]`): a
	 * Dashicons class, a data URL / URL, or (7.2) a registered icon name
	 * such as `core/plugins`. '' when unknown. Memoized per request.
	 */
	public static function menu_icon( string $slug ): string {
		static $icons = null;
		if ( null === $icons ) {
			$icons = [];
			global $menu;
			if ( is_array( $menu ) ) {
				foreach ( $menu as $entry ) {
					if ( is_array( $entry ) && isset( $entry[2] ) ) {
						$icons[ self::normalize_slug( (string) $entry[2] ) ] = (string) ( $entry[6] ?? '' );
					}
				}
			}
		}
		return $icons[ self::normalize_slug( $slug ) ] ?? '';
	}

	/**
	 * Refines the owner detected for the feedback button
	 * (`doubleyoupi_filter_feedback_owner`): a builder's satellite page
	 * (Elementor "Templates", Bricks settings…) belongs to that builder even
	 * when its slug does not carry the builder's name.
	 *
	 * @param array $owner  Detected owner {kind, slug, name, version, detected, via}.
	 * @param array $screen Screen context {page, post_type, taxonomy, parent, …}.
	 * @param array $owners Candidates (active plugins, theme, core, self).
	 * @return array
	 */
	public static function refine_feedback_owner( array $owner, array $screen, array $owners ): array {
		if ( ! empty( $owner['detected'] ) && 'fallback' !== ( $owner['via'] ?? '' ) ) {
			return $owner;
		}
		$keys = [];
		if ( '' !== (string) ( $screen['page'] ?? '' ) ) {
			$keys[] = (string) $screen['page'];
		}
		if ( '' !== (string) ( $screen['post_type'] ?? '' ) ) {
			$keys[] = 'edit.php?post_type=' . (string) $screen['post_type'];
		}
		foreach ( $keys as $key ) {
			$builder = self::standalone_owner( $key );
			if ( null === $builder ) {
				continue;
			}
			$hint = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $builder ) ?: $builder );
			foreach ( $owners as $candidate ) {
				if ( 'plugin' !== $candidate['kind'] && 'theme' !== $candidate['kind'] ) {
					continue;
				}
				$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '', (string) $candidate['slug'] ) ?: '' );
				if ( '' !== $slug && ( $slug === $hint || str_starts_with( $slug, $hint ) || str_starts_with( $hint, $slug ) ) ) {
					return [
						'kind'     => $candidate['kind'],
						'slug'     => $candidate['slug'],
						'name'     => $candidate['name'],
						'version'  => $candidate['version'] ?? '',
						'detected' => true,
						'via'      => 'builder',
					];
				}
			}
		}
		return $owner;
	}

	/**
	 * Returns the custom icon ID for a slug, or null if there is none.
	 */
	public static function chip_icon( string $slug ): ?string {
		$icon = self::PLUGIN_CHIP_ICON[ self::map_key( $slug ) ] ?? null;
		return apply_filters( 'doubleyoupi_admin_chip_icon', $icon, $slug );
	}

	/**
	 * Lists the line order (for rail / chipbar rendering).
	 *
	 * @return string[]
	 */
	public static function line_order(): array {
		return self::LINE_ORDER;
	}
}

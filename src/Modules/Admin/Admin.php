<?php
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Core\Build;

use DoubleYoupi\Admin\AdminMenu;
use DoubleYoupi\Core\AbstractModule;
use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 01 — Refined Admin Dashboard
 *
 * Full redesign of the WordPress admin: Stations design system
 * (typography, spacing, palette, transitions), sidebar reorganized into
 * 7 thematic lines (Content, Commerce, Users, Community,
 * Appearance, System, Developer) + standalone slots for page builders.
 *
 * Only three user settings (Accent / Custom logo / Accent-
 * applies-to-lines). Everything else is automatic:
 *   - Plugin classification → lines via PluginMap (~100 curated entries
 *     + heuristic for unknown plugins)
 *   - View Transitions API for persistent chrome + directional content swap
 *   - Theme toggle (dark ↔ light) client-side via localStorage
 *
 * Architecture: the Admin class simply orchestrates the specialized
 * renderers (Rail, Chipbar, Subchipbar, Sprite, Brand, Adminbar,
 * ViewTransitions). Each renderer has a single responsibility.
 */
class Admin extends AbstractModule {

	/** Profile screen: notice that replaces the native colour scheme row. Called with its strings. */
	private const ADMIN_COLOR_NOTICE_JS = <<<'JS'
function (d) {
	// Printed in <head>: document.body is null at that point, so everything
	// waits for DOMContentLoaded (or runs at once if the DOM is ready).
	function run() {
		if (!document.body || !document.body.classList.contains('dy-admin')) return;
		var row = document.querySelector('.user-admin-color-wrap');
		if (!row || document.querySelector('.dy-admin-color-notice-msg')) return;
		var notice = document.createElement('div');
		notice.className = 'notice notice-info inline dy-notice-revealed dy-admin-color-notice-msg';
		notice.style.marginTop = '12px';
		var p = document.createElement('p');
		var strong = document.createElement('strong');
		strong.textContent = d.label + ' :';
		var a = document.createElement('a');
		a.href = d.url;
		a.textContent = d.link;
		p.appendChild(strong);
		p.appendChild(document.createTextNode(' ' + d.text + ' '));
		p.appendChild(a);
		notice.appendChild(p);
		var table = row.closest('table.form-table');
		if (table && table.parentNode) {
			table.parentNode.insertBefore(notice, table.nextSibling);
		}
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
}
JS;

	/** wp-login.php: applies the stored theme before the first paint. */
	private const LOGIN_PRE_PAINT_JS = <<<'JS'
(function () {
	try {
		var t = localStorage.getItem('dy-admin-theme');
		if (t !== 'light' && t !== 'dark') { return; }
		var apply = function () {
			if (!document.body) { return false; }
			document.body.classList.toggle('dy-theme-light', t === 'light');
			return true;
		};
		if (apply()) { return; }
		// <body> not yet parsed: MutationObserver to set the class
		// as soon as it exists (before first paint on most browsers).
		new MutationObserver(function (_, obs) {
			if (apply()) { obs.disconnect(); }
		}).observe(document.documentElement, { childList: true, subtree: true });
	} catch (e) {}
})();
JS;

	/** wp-login.php: the theme toggle button. */
	private const LOGIN_THEME_TOGGLE_JS = <<<'JS'
(function () {
	function onReady(fn) {
		if (document.readyState !== 'loading') { fn(); return; }
		document.addEventListener('DOMContentLoaded', fn);
	}
	onReady(function () {
		var btn = document.querySelector('[data-dy-login-theme-toggle]');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			var isLight = document.body.classList.toggle('dy-theme-light');
			try {
				localStorage.setItem('dy-admin-theme', isLight ? 'light' : 'dark');
			} catch (e) {}
			btn.setAttribute('aria-pressed', isLight ? 'true' : 'false');
		});
	});
})();
JS;


	private ?Rail $rail = null;

	public function id(): string {
		return 'admin';
	}

	public function name(): string {
		return 'Refined Admin Dashboard';
	}

	public function boot(): void {
		// Settings page in the Double Youpi parent menu.
		// Outside apply-gate: an administrator who has disabled the redesign for
		// their own account must still be able to access the settings.
		AdminMenu::add_page(
			static fn(): string => __( 'Refined Admin Dashboard', 'doubleyoupi-refined-admin-dashboard' ),
			static fn(): string => __( 'Admin', 'doubleyoupi-refined-admin-dashboard' ),
			'doubleyoupi-admin',
			[ Settings::instance(), 'render_page' ]
		);

		// Per-user customization (block on profile.php / user-edit.php).
		// Outside apply-gate: profile hooks must remain active even for an
		// opt-out account, otherwise the user could never re-enable the module.
		( new UserCustomization() )->boot();

		// Admin Columns conflict (rewrites list-table columns) → automatic deactivation
		// + explicit notice. Outside apply-gate: this is a site-level decision,
		// independent of any individual user's opt-out from the redesign.
		( new AdminColumnsConflict() )->boot();

		// REST API for the Developer tab (navigation tree editor + per-screen
		// styling toggle). Outside apply-gate: an opted-out admin must still be
		// able to manage the navigation configuration for other users.
		add_action( 'rest_api_init', [ Developer\RestController::class, 'register_routes' ] );

		// Settings profile (export / import / pinned in code). Outside the
		// apply-gate: a pinned profile must hold for every account, and the
		// handlers live on admin-post.php.
		( new Profile() )->boot();

		// First-run card on the Overview + the one-line notice on plugins.php.
		// Outside the apply-gate: the Overview is shared chrome.
		( new Welcome() )->boot();

		// The visual redesign is gated by the per-user apply-gate. The current user
		// is NOT resolvable at `plugins_loaded` (pluggable.php loads after it) →
		// we defer to `init`, which runs before `admin_menu`, `admin_head` and
		// wp-login.php rendering: sub-modules can always register their hooks in time.
		add_action( 'init', [ $this, 'maybe_boot_redesign' ], 0 );
	}

	/**
	 * Boots the visual redesign, conditional on the per-user apply-gate.
	 *
	 * If the current account has disabled the Admin module (per-user mode active),
	 * no assets or visual hooks are registered → 100% native wp-admin.
	 */
	public function maybe_boot_redesign(): void {
		if ( ! UserCustomization::should_apply() ) {
			return;
		}

		// Per-screen styling toggle: if the admin has disabled DY styling on
		// the current sub-page, skip the visual redesign boot entirely.
		// Result: the page remains 100% WP-native (no CSS, no JS,
		// no rail/chipbar/sub-chipbar). Controlled by the JSON option
		// `doubleyoupi_admin_disabled_screens`, manageable from the
		// Developer tab in settings.
		if ( ScreenDisabler::is_current_screen_disabled() ) {
			return;
		}

		// Full-screen apps: the Site Editor (FSE) is a full-screen React SPA
		// with its own side navigation. Injecting our rail + chipbar +
		// notices into it would break its layout entirely (see the Compositions page).
		// We leave it 100% native, just as WP already does for the block editor.
		global $pagenow;
		if ( 'site-editor.php' === $pagenow ) {
			return;
		}

		// Signals that the GLOBAL Admin redesign is taking over this screen: the
		// standalone DyShell skin (Double Youpi pages) defers to this flag
		// to avoid any chrome duplication (see DyShell::is_active()).
		if ( ! defined( 'DOUBLEYOUPI_ADMIN_SKIN_ACTIVE' ) ) {
			define( 'DOUBLEYOUPI_ADMIN_SKIN_ACTIVE', true );
		}

		$settings = Settings::instance();

		// Global assets (admin + login).
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'login_enqueue_scripts', [ $this, 'enqueue_login_assets' ] );

		// Login screen branding (functional without any configuration):
		//   - Logo click target URL → home_url('/')
		//   - Logo text (screen-reader-visible) → site name
		//   - Body class `dy-admin-login` to match SCSS selectors
		//   - Inline style element that injects --dya-accent + --dy-login-logo + the
		//     pre-paint theme script (reads localStorage `dy-admin-theme`)
		//   - Custom logo HTML (uploaded image OR letter fallback) injected
		//     below the `<h1 class="dy-login-brand">` rendered in login_header
		add_filter( 'login_headerurl',  [ $settings, 'login_logo_url' ] );
		add_filter( 'login_headertext', [ $settings, 'login_logo_text' ] );
		add_filter( 'login_body_class', [ $this, 'login_body_class' ] );
		add_action( 'login_head',       [ $this, 'login_head_inline' ], 999 );
		add_action( 'login_header',     [ $this, 'login_brand_block' ] );

		// Specialized renderers — orchestration.
		// Rail must boot BEFORE Chipbar/Subchipbar, as both read the collection
		// built by Rail::collect().
		$this->rail = new Rail();
		$this->rail->boot();

		( new Sprite() )->boot();
		( new Brand( $settings ) )->boot();
		( new Chipbar( $this->rail ) )->boot();
		( new Subchipbar( $this->rail ) )->boot();
		( new Adminbar() )->boot();
		( new Notices() )->boot();
		// One review request after a week of use, inside the notifications
		// centre (see ReviewAsk): booted here so it only exists where the
		// redesign, and so the centre, applies.
		( new ReviewAsk() )->boot();
		( new MimeIcons() )->boot();
		// Capability markers (dy-core-tooltips, dy-core-icons, dy-core-wpds…):
		// what this WordPress can do, detected by function/handle, not version.
		( new CoreCapabilities() )->boot();
		// Feedback button (shared, src/Admin/Feedback): a builder's satellite
		// pages are attributed to the builder (PluginMap knows them).
		add_filter( 'doubleyoupi_filter_feedback_owner', [ PluginMap::class, 'refine_feedback_owner' ], 10, 3 );

		// Builders with externally managed UI (Elementor…): we keep the rail but
		// surgically remove chipbar/notices/content design-system on their
		// screens (see BuilderScreen). Booted AFTER Notices so that the
		// `dy-builder-native` body class and theme sync are in place.
		( new BuilderScreen() )->boot();
		( new LiteSpeedTheme() )->boot();
		( new WpGraphqlIde() )->boot();

		// Plugin screens with self-sufficient UI (Rank Math, Yoast): we keep
		// rail + chipbar but render content on a native WP canvas (see
		// body class `dy-native-content` + _builder-native.scss).
		( new NativeContent() )->boot();

		( new Eyebrow() )->boot();
		( new PostOrdering() )->boot();
		( new Tables\TablesReimagined() )->boot();
		( new Editor\EditorReimagined() )->boot();
		( new Editor\BlockEditorLoading() )->boot();
		( new AcfBanner() )->boot();

		// Automatic adoption of third-party elements (notices, buttons…) via catalogue + JS.
		( new Adoption\Adopter() )->boot();

		// Modular dashboard — sub-module that replaces the native wp-admin/index.php
		// with our 20×12 grid (see docs/design/dashboard-modulable.html).
		( new Dashboard\Dashboard() )->boot();

		// View Transitions only if the user has not disabled them.
		if ( ! defined( 'DOUBLEYOUPI_DISABLE_VIEW_TRANSITIONS' ) || ! DOUBLEYOUPI_DISABLE_VIEW_TRANSITIONS ) {
			( new ViewTransitions() )->boot();
		}

		// Hide the WP-native "Administration Color Scheme" on profile.php
		// and user-edit.php — the DY Admin module drives the accent via Settings.
		// The native `admin_color` setting has no effect (our CSS overrides
		// the WP palette via DY body classes) → we avoid user confusion by
		// removing that row and adding a notice pointing to the correct settings page.
		add_action( 'admin_head-profile.php',   [ $this, 'hide_admin_color_setting' ] );
		add_action( 'admin_head-user-edit.php', [ $this, 'hide_admin_color_setting' ] );

		// Customizer (customize.php) — DY coverage breaks more than it helps
		// (see user feedback about green tint in the preview iframe). We leave
		// that screen fully native without intervention.
	}

	/**
	 * Hides the "Administration Color Scheme" row on profile.php
	 * and injects a notice explaining that the DY Admin module drives the accent.
	 *
	 * We prefer pure CSS (display:none) over a `get_user_metadata` filter —
	 * the filter would also affect the frontend where the user-meta value may
	 * be legitimately read. Here we only want to hide the control.
	 */
	public function hide_admin_color_setting(): void {
		wp_register_style( 'doubleyoupi-admin-color-setting', false, [], DOUBLEYOUPI_VERSION );
		wp_add_inline_style(
			'doubleyoupi-admin-color-setting',
			'body.dy-admin .user-admin-color-wrap,body.dy-admin tr.user-admin-color-wrap,body.dy-admin #color-picker{display:none !important}'
		);
		wp_print_styles( 'doubleyoupi-admin-color-setting' );

		$data = [
			'label' => __( 'Accent color', 'doubleyoupi-refined-admin-dashboard' ),
			'text'  => __( 'The Double Youpï Admin module manages the administration palette.', 'doubleyoupi-refined-admin-dashboard' ),
			'link'  => __( 'Configure →', 'doubleyoupi-refined-admin-dashboard' ),
			'url'   => esc_url_raw( admin_url( 'admin.php?page=doubleyoupi-admin' ) ),
		];
		wp_print_inline_script_tag(
			'(' . self::ADMIN_COLOR_NOTICE_JS . ')(' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ');',
			[ 'id' => 'doubleyoupi-admin-color-notice' ]
		);
	}

	public function enqueue_admin_assets(): void {
		// Bricolage Grotesque (variable font, latin subset, self-hosted).
		// @font-face declared inline via wp_add_inline_style to use
		// Build::url() (absolute URL, reliable regardless of the plugin slug).
		// A single woff2 file ~41 KB covers weights 400-600 (variable axis).
		$this->register_bricolage_font();

		// Core's design tokens (`--wpds-*`, 7.1+): loaded on every screen so
		// the token bridge (_16-wpds-bridge.scss) has real values to remap and
		// any WPDS-based component inherits our theme. Our sheet comes after.
		$style_deps = [];
		if ( CoreCapabilities::has_wpds_tokens() ) {
			wp_enqueue_style( 'wp-theme' );
			$style_deps[] = 'wp-theme';
		}

		$css = Build::dir( __FILE__, 'assets/css/modules/admin/admin.css' );
		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'doubleyoupi-admin',
				Build::url( __FILE__, 'assets/css/modules/admin/admin.css' ),
				$style_deps,
				self::asset_version( $css )
			);
		}

		$js = Build::dir( __FILE__, 'assets/js/modules/admin/admin.js' );
		if ( file_exists( $js ) ) {
			wp_enqueue_script(
				'doubleyoupi-admin',
				Build::url( __FILE__, 'assets/js/modules/admin/admin.js' ),
				[],
				self::asset_version( $js ),
				true
			);

			wp_localize_script(
				'doubleyoupi-admin',
				'doubleyoupiAdminI18n',
				[
					'tableOptions' => [
						'screenOptions' => __( 'Screen options', 'doubleyoupi-refined-admin-dashboard' ),
						'columns'       => __( 'Columns', 'doubleyoupi-refined-admin-dashboard' ),
						'pagination'    => __( 'Pagination', 'doubleyoupi-refined-admin-dashboard' ),
						'itemsPerPage'  => __( 'Items per page', 'doubleyoupi-refined-admin-dashboard' ),
					],
				]
			);
		}

		// ANIMATED collapse/expand of metaboxes (.postbox) — global, all admin pages
		// (editor, dashboard, settings…). Self-contained, no dependencies.
		$pb = Build::dir( __FILE__, 'assets/js/modules/admin/postbox-collapse.js' );
		if ( file_exists( $pb ) ) {
			wp_enqueue_script(
				'doubleyoupi-postbox-collapse',
				Build::url( __FILE__, 'assets/js/modules/admin/postbox-collapse.js' ),
				[],
				self::asset_version( $pb ),
				true
			);
		}

		// profile.php / user-edit.php: pushes SEO metaboxes (Rank Math,
		// Yoast) injected mid-form down to the bottom (see JS).
		global $pagenow;
		if ( in_array( $pagenow, [ 'profile.php', 'user-edit.php' ], true ) ) {
			$reorder = Build::dir( __FILE__, 'assets/js/modules/admin/profile-reorder.js' );
			if ( file_exists( $reorder ) ) {
				wp_enqueue_script(
					'doubleyoupi-admin-profile-reorder',
					Build::url( __FILE__, 'assets/js/modules/admin/profile-reorder.js' ),
					[],
					self::asset_version( $reorder ),
					true
				);
			}
		}
	}

	public function enqueue_login_assets(): void {
		$this->register_bricolage_font();

		$css = Build::dir( __FILE__, 'assets/css/modules/admin/admin.css' );
		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'doubleyoupi-admin-login',
				Build::url( __FILE__, 'assets/css/modules/admin/admin.css' ),
				[],
				self::asset_version( $css )
			);
		}
	}

	/**
	 * Registers the Bricolage Grotesque @font-face declaration (self-hosted
	 * variable font) as an inline stylesheet.
	 *
	 * Why inline rather than a dedicated CSS file: the woff2 path depends on
	 * the plugin install (slug, multisite, custom upload dir).
	 * With the build's own URL injected from PHP, the URL is always correct.
	 *
	 * `font-display: swap` → text renders in system-ui immediately,
	 * then swaps to Bricolage once it is available (no FOIT).
	 * `unicode-range`: Latin only (Latin-1 + Extended Latin); no need for
	 * Cyrillic/Greek/Vietnamese which we do not support.
	 */
	private function register_bricolage_font(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		$font_url = Build::url( __FILE__, 'assets/fonts/bricolage-grotesque/bricolage-grotesque-variable.woff2' );

		// Empty (mock) stylesheet handle for wp_add_inline_style — we do not
		// want a second HTTP request, just a style element in the head.
		// Use `wp_register_style` with `false` as the src.
		wp_register_style( 'doubleyoupi-fonts', false, [], DOUBLEYOUPI_VERSION );
		wp_enqueue_style( 'doubleyoupi-fonts' );

		$css = sprintf(
			'@font-face{font-family:"Bricolage Grotesque";font-style:normal;font-weight:400 600;font-display:swap;src:url(%s) format("woff2-variations"),url(%1$s) format("woff2");unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD,U+0100-024F,U+0259,U+1E00-1EFF,U+2020,U+20A0-20AB,U+20AD-20CF,U+2113,U+2C60-2C7F,U+A720-A7FF;}',
			esc_url( $font_url )
		);
		wp_add_inline_style( 'doubleyoupi-fonts', $css );
	}

	/**
	 * Body class on wp-login.php — without this, `body.dy-admin-login` selectors
	 * in `_login.scss` never match (WP does not run `admin_body_class`
	 * outside the admin).
	 *
	 * @param string[] $classes
	 * @return string[]
	 */
	public function login_body_class( array $classes ): array {
		$classes[] = 'dy-admin-login';
		return $classes;
	}

	/**
	 * Inline style element + pre-paint script in the <head> of wp-login.php:
	 *
	 *   1. CSS custom properties derived from Settings:
	 *      - `--dya-accent` (user-defined color)
	 *      - `--dy-login-logo` (custom image URL OR empty)
	 *
	 *   2. Pre-paint script that reads `localStorage.dy-admin-theme` BEFORE
	 *      the first paint and sets `.dy-theme-light` on <body> if needed.
	 *      Without this, the screen flashes dark (default) before switching to
	 *      light — visible over ~80ms on slow navigation.
	 *
	 *   3. Small on-load JS that hooks the theme toggle button → writes
	 *      `localStorage` + toggles the class.
	 */
	public function login_head_inline(): void {
		$settings = Settings::instance();
		$accent   = sanitize_hex_color( $settings->accent_color() );
		$logo_url = $settings->logo_url();

		$css = ':root{';
		if ( $accent ) {
			$css .= '--dya-accent:' . $accent . ';';
		}
		if ( '' !== $logo_url ) {
			$css .= "--dy-login-logo:url('" . esc_url( $logo_url ) . "');";
		}
		$css .= '}';

		wp_register_style( 'doubleyoupi-login-inline', false, [], DOUBLEYOUPI_VERSION );
		wp_add_inline_style( 'doubleyoupi-login-inline', $css );
		wp_print_styles( 'doubleyoupi-login-inline' );

		wp_print_inline_script_tag( self::LOGIN_PRE_PAINT_JS, [ 'id' => 'doubleyoupi-login-prepaint' ] );
		wp_print_inline_script_tag( self::LOGIN_THEME_TOGGLE_JS, [ 'id' => 'doubleyoupi-login-theme-toggle' ] );
	}

	/**
	 * Renders the DY brand block (mono eyebrow + logo + accent rail) at the top of
	 * <div id="login">. WP fires `login_header` IMMEDIATELY after `<div id="login">`,
	 * so we prepend our markup to the container (the native WP H1
	 * follows and is hidden via CSS).
	 *
	 * Structure:
	 *   - vertical rail (CSS only, position fixed left)
	 *   - mono eyebrow CAPS top-left
	 *   - theme toggle top-right
	 *   - brand hero: logo (image OR letter) + site name
	 *   - signature footer at the bottom
	 */
	public function login_brand_block(): void {
		$settings = Settings::instance();
		$logo     = $settings->login_logo_html();
		$site     = (string) get_bloginfo( 'name' );
		$year     = (string) gmdate( 'Y' );
		?>
<div class="dy-login-rail" aria-hidden="true"></div>
<div class="dy-login-eyebrow">
	<span class="dy-login-eyebrow__dot" aria-hidden="true"></span>
	<span class="dy-login-eyebrow__label"><?php echo esc_html( $site ); ?></span>
	<span class="dy-login-eyebrow__sep" aria-hidden="true">/</span>
	<span class="dy-login-eyebrow__system"><?php esc_html_e( 'Session', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
</div>
<button type="button" class="dy-login-theme-toggle" data-dy-login-theme-toggle aria-label="<?php esc_attr_e( 'Invert theme', 'doubleyoupi-refined-admin-dashboard' ); ?>" aria-pressed="false">
	<svg class="dy-login-theme-toggle__icon dy-login-theme-toggle__icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
	<svg class="dy-login-theme-toggle__icon dy-login-theme-toggle__icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
</button>
<div class="dy-login-brand">
	<div class="dy-login-logo"><?php echo wp_kses( $logo, Kses::ui_tags() ); ?></div>
	<div class="dy-login-title"><?php echo esc_html( $site ); ?></div>
</div>
<footer class="dy-login-signature" aria-hidden="true">
	<span>doubleyoupi</span>
	<span class="dy-login-signature__sep">·</span>
	<span><?php echo esc_html( $year ); ?></span>
	<span class="dy-login-signature__sep">·</span>
	<span><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'local' ); ?></span>
</footer>
		<?php
	}

	/**
	 * Cache-buster for admin assets.
	 *
	 * In dev (`DOUBLEYOUPI_VERSION` ends with `-dev` OR `WP_DEBUG` is active),
	 * use `filemtime()` so that every SCSS recompile or JS edit automatically
	 * busts the browser cache. Otherwise in production,
	 * `DOUBLEYOUPI_VERSION` is sufficient (releases bump the version).
	 *
	 * Why: a static `DOUBLEYOUPI_VERSION` does not change during dev →
	 * recompiles are invisible in the browser without a hard reload.
	 */
	private static function asset_version( string $abs_path ): string {
		$is_dev = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			|| str_ends_with( (string) DOUBLEYOUPI_VERSION, '-dev' );
		if ( $is_dev ) {
			$mtime = @filemtime( $abs_path );
			if ( $mtime ) {
				return (string) $mtime;
			}
		}
		return (string) DOUBLEYOUPI_VERSION;
	}
}

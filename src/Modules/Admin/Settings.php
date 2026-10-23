<?php
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Core\Build;

use DoubleYoupi\Core\AbstractModule;
use DoubleYoupi\Admin\Feedback;
use DoubleYoupi\Admin\Kses;
use DoubleYoupi\Admin\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings management for the Refined Admin Dashboard module.
 *
 * Three user settings — deliberately minimal (curated manifesto):
 *   - Accent color (hex)
 *   - Toggle "Apply accent to all menu lines"
 *   - Custom logo (WordPress image picker)
 *
 * Everything else (WC/Bricks/Yoast/… compat) is auto-detected via the
 * PluginMap. The dark mode toggle lives client-side (localStorage), via the
 * theme toggle in the admin bar — not in the DB.
 */
class Settings extends AbstractModule {

	private const OPTION_ACCENT          = 'doubleyoupi_admin_accent';
	private const OPTION_LOGO_URL        = 'doubleyoupi_admin_logo_url';
	private const OPTION_LOGO_ID         = 'doubleyoupi_admin_logo_id';
	private const OPTION_ACCENT_TO_LINES = 'doubleyoupi_admin_accent_to_lines';
	public const OPTION_NAVIGATION_MODE  = 'doubleyoupi_admin_navigation_mode';
	public const OPTION_RAIL_FLYOUT      = 'doubleyoupi_admin_rail_flyout';

	private const DEFAULT_ACCENT = '#5b3efe';

	public function id(): string {
		return 'admin-settings';
	}

	public function name(): string {
		return 'Refined Admin Dashboard · Settings';
	}

	private const AJAX_ACTION = 'doubleyoupi_admin_save_setting';

	/**
	 * Short key (JS) → WP option name, for the auto-save of the General tab.
	 * `logo` also carries its attachment id (OPTION_LOGO_ID).
	 */
	private const KEY_MAP = [
		'accent'          => self::OPTION_ACCENT,
		'accent_to_lines' => self::OPTION_ACCENT_TO_LINES,
		'logo'            => self::OPTION_LOGO_URL,
		'navigation_mode' => self::OPTION_NAVIGATION_MODE,
		'rail_flyout'     => self::OPTION_RAIL_FLYOUT,
		'per_user'        => UserCustomization::OPTION_PER_USER,
		'hide_feedback'   => Feedback::OPTION_HIDE,
		'settings_owner'  => Access::OPTION_OWNER,
	];

	public function boot(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_save_setting' ] );
	}

	/**
	 * Saves one setting of the General tab as soon as it changes, without a
	 * submit button. The options.php form stays the fallback without
	 * JavaScript. A setting pinned by a code profile is refused here as it is
	 * disabled on the page.
	 */
	public function ajax_save_setting(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( Access::CAP ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( (string) $_POST['key'] ) ) : '';
		if ( ! isset( self::KEY_MAP[ $key ] ) ) {
			wp_send_json_error( [ 'message' => 'unknown_key' ] );
		}
		$option = self::KEY_MAP[ $key ];
		if ( Profile::is_locked( $option ) ) {
			wp_send_json_error( [ 'message' => 'locked' ] );
		}
		$raw = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['value'] ) ) : '';

		switch ( $key ) {
			case 'accent':
				$hex     = sanitize_hex_color( $raw );
				$problem = $hex ? self::accent_problem( $hex ) : __( 'Invalid accent color (expected format: #rrggbb).', 'doubleyoupi-refined-admin-dashboard' );
				if ( null !== $problem ) {
					wp_send_json_error( [ 'message' => $problem ] );
				}
				$value = (string) $hex;
				break;
			case 'logo':
				$id    = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
				$url   = $id ? (string) wp_get_attachment_url( $id ) : '';
				$value = '' !== $url ? $url : esc_url_raw( $raw );
				update_option( self::OPTION_LOGO_ID, '' !== $value ? $id : 0 );
				break;
			case 'navigation_mode':
				$value = self::sanitize_navigation_mode( $raw );
				break;
			case 'rail_flyout':
				$value = self::sanitize_rail_flyout( $raw );
				break;
			case 'settings_owner':
				$value = rest_sanitize_boolean( $raw ) ? get_current_user_id() : 0;
				break;
			default:
				$value = rest_sanitize_boolean( $raw );
		}

		update_option( $option, $value );
		wp_send_json_success( [ 'key' => $key, 'value' => $value ] );
	}

	public function register_settings(): void {
		register_setting( 'doubleyoupi_admin', self::OPTION_ACCENT, [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_accent' ],
			'default'           => self::DEFAULT_ACCENT,
		] );
		register_setting( 'doubleyoupi_admin', self::OPTION_LOGO_URL, [
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		] );
		register_setting( 'doubleyoupi_admin', self::OPTION_LOGO_ID, [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		] );
		register_setting( 'doubleyoupi_admin', self::OPTION_ACCENT_TO_LINES, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );
		register_setting( 'doubleyoupi_admin', self::OPTION_NAVIGATION_MODE, [
			'type'              => 'string',
			'sanitize_callback' => [ self::class, 'sanitize_navigation_mode' ],
			'default'           => 'reimagined',
		] );
		register_setting( 'doubleyoupi_admin', self::OPTION_RAIL_FLYOUT, [
			'type'              => 'string',
			'sanitize_callback' => [ self::class, 'sanitize_rail_flyout' ],
			'default'           => 'one',
		] );
		register_setting( 'doubleyoupi_admin', UserCustomization::OPTION_PER_USER, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );
		register_setting( 'doubleyoupi_admin', Feedback::OPTION_HIDE, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );
		register_setting( 'doubleyoupi_admin', Access::OPTION_OWNER, [
			'type'              => 'integer',
			'sanitize_callback' => [ self::class, 'sanitize_owner' ],
			'default'           => 0,
		] );
	}

	/**
	 * The settings owner: a user id when stored by code, "1" or empty when
	 * posted by the form's checkbox (then: the current user, or nobody).
	 *
	 * @param mixed $value
	 */
	public static function sanitize_owner( $value ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}
		return rest_sanitize_boolean( $value ) ? get_current_user_id() : 0;
	}

	/**
	 * Enqueues the color-picker + media-uploader on the settings page only.
	 * admin-settings.js initializes both pickers.
	 *
	 * The exact hook suffix depends on the parent_title sanitized by WP; for our
	 * parent "Double Youpi" it becomes `double-youpi_page_doubleyoupi-admin`.
	 * We match loosely via the slug suffix to stay robust against a future rename
	 * of the parent label (which would not break our page).
	 *
	 * The Reorganize tab additionally loads admin-developer.{js,css}
	 * for the drag-and-drop tree editor.
	 */
	public function enqueue_settings_assets( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_doubleyoupi-admin' ) ) {
			return;
		}
		$active_tab = self::current_tab();

		// Assets for the General tab (always required when on General).
		if ( 'reorganize' !== $active_tab ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_media();

			// Cache-buster: JS filemtime instead of the static DOUBLEYOUPI_VERSION
			// constant (which does not change between two dev iterations).
			$js_path = Build::dir( __FILE__, 'assets/js/modules/admin/admin-settings.js' );
			$version = file_exists( $js_path ) ? (string) filemtime( $js_path ) : DOUBLEYOUPI_VERSION;
			wp_enqueue_script(
				'doubleyoupi-admin-settings',
				Build::url( __FILE__, 'assets/js/modules/admin/admin-settings.js' ),
				[ 'jquery', 'wp-color-picker' ],
				$version,
				true
			);
			// Cards and groups of the General tab. The card system itself
			// (.dy-mlset__*) comes with admin-overview.css, loaded on every
			// Double Youpï page.
			$css_path = Build::dir( __FILE__, 'assets/css/modules/admin/admin-settings.css' );
			if ( file_exists( $css_path ) ) {
				wp_enqueue_style(
					'doubleyoupi-admin-settings',
					Build::url( __FILE__, 'assets/css/modules/admin/admin-settings.css' ),
					[ 'wp-color-picker' ],
					(string) filemtime( $css_path )
				);
			}
			// Auto-save endpoint, and every string the script shows (the
			// validator messages used to be hard-coded in French in the script).
			wp_localize_script(
				'doubleyoupi-admin-settings',
				'doubleyoupiAdminSettings',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
					'i18n'    => [
						'chooseLogo'  => __( 'Choose a logo', 'doubleyoupi-refined-admin-dashboard' ),
						'useImage'    => __( 'Use this image', 'doubleyoupi-refined-admin-dashboard' ),
						'changeImage' => __( 'Change image', 'doubleyoupi-refined-admin-dashboard' ),
						'chooseImage' => __( 'Choose image', 'doubleyoupi-refined-admin-dashboard' ),
						'format'      => __( 'Invalid accent color (expected format: #rrggbb).', 'doubleyoupi-refined-admin-dashboard' ),
						'tooDark'     => __( 'Color too dark: even strong saturation cannot recover identity here.', 'doubleyoupi-refined-admin-dashboard' ),
						'tooLight'    => __( 'Color too light: even strong saturation cannot recover identity here.', 'doubleyoupi-refined-admin-dashboard' ),
						'grayDark'    => __( 'Gray too dark: without saturation, tinted surfaces will turn muddy.', 'doubleyoupi-refined-admin-dashboard' ),
						'grayLight'   => __( 'Gray too light: without saturation, the accent fades on white backgrounds.', 'doubleyoupi-refined-admin-dashboard' ),
						'error'       => __( 'Something went wrong. Please retry.', 'doubleyoupi-refined-admin-dashboard' ),
					],
				]
			);
			return;
		}

		// Reorganize tab: editor JS + CSS. No SortableJS: the v2 editor has
		// its own drag layer (pointer events), so the vendored library was
		// loaded for nothing on this page, and was not even shipped in the
		// solo builds (14/09/2026).
		$css = Build::dir( __FILE__, 'assets/css/modules/admin/admin-developer.css' );
		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'doubleyoupi-admin-developer',
				Build::url( __FILE__, 'assets/css/modules/admin/admin-developer.css' ),
				[],
				(string) filemtime( $css )
			);
		}
		$js = Build::dir( __FILE__, 'assets/js/modules/admin/admin-developer.js' );
		if ( file_exists( $js ) ) {
			wp_enqueue_script(
				'doubleyoupi-admin-developer',
				Build::url( __FILE__, 'assets/js/modules/admin/admin-developer.js' ),
				[],
				(string) filemtime( $js ),
				true
			);
			// Bootstrap: inlined snapshot + REST URLs + nonce. Avoids a
			// REST round-trip on the first paint.
			$snapshot = Developer\RestController::build_snapshot();
			wp_add_inline_script(
				'doubleyoupi-admin-developer',
				'window.doubleyoupiAdminDeveloper = ' . wp_json_encode( [
					'snapshot'    => $snapshot,
					'restRoot'    => esc_url_raw( rest_url( 'doubleyoupi/v1' ) ),
					'restNonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'        => self::developer_i18n_strings(),
				] ) . ';',
				'before'
			);
		}
	}

	/**
	 * Safe read of the `tab` query-string parameter.
	 *
	 * Valid tabs: 'general' (default), 'developer'.
	 */
	public static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		// Backward-compat: 'developer' was the initial slug before the rename
		// to 'reorganize'. We accept both to avoid breaking any existing links.
		if ( 'developer' === $tab ) {
			$tab = 'reorganize';
		}
		// The Reorganize tab edits the reimagined tree. With the native tree on,
		// that tab is not offered, and a bookmark or an old link lands on
		// General rather than on an editor whose result is nowhere to be seen.
		if ( 'reorganize' === $tab && self::is_native_navigation() ) {
			return 'general';
		}
		return in_array( $tab, [ 'general', 'reorganize' ], true ) ? $tab : 'general';
	}

	/**
	 * i18n strings exposed to the Developer editor JS. All translated server-side;
	 * JS simply calls `t('key')` → pre-translated string.
	 *
	 * @return array<string, string>
	 */
	private static function developer_i18n_strings(): array {
		return [
			// Columns & badges.
			'builder'            => __( 'Builder', 'doubleyoupi-refined-admin-dashboard' ),
			'customGroup'        => __( 'Custom', 'doubleyoupi-refined-admin-dashboard' ),
			'managedByExtension' => __( 'Hierarchy managed by the extension', 'doubleyoupi-refined-admin-dashboard' ),
			'dragColumn'         => __( 'Drag to reorder', 'doubleyoupi-refined-admin-dashboard' ),
			'rename'             => __( 'Rename', 'doubleyoupi-refined-admin-dashboard' ),
			'changeIcon'         => __( 'Change icon', 'doubleyoupi-refined-admin-dashboard' ),
			'deleteGroup'        => __( 'Delete group', 'doubleyoupi-refined-admin-dashboard' ),
			'emptyColumn'        => __( 'Drag items here.', 'doubleyoupi-refined-admin-dashboard' ),
			'hide'               => __( 'Hide', 'doubleyoupi-refined-admin-dashboard' ),
			// Drawers.
			'detected'           => __( 'Plugin pages', 'doubleyoupi-refined-admin-dashboard' ),
			'detectedHelp'       => __( 'These pages aren’t sorted yet. Assign them to a group or hide them.', 'doubleyoupi-refined-admin-dashboard' ),
			'noDetected'         => __( 'No unsorted plugin pages.', 'doubleyoupi-refined-admin-dashboard' ),
			'assign'             => __( 'Assign', 'doubleyoupi-refined-admin-dashboard' ),
			'hidden'             => __( 'Hidden', 'doubleyoupi-refined-admin-dashboard' ),
			'noHidden'           => __( 'Nothing hidden.', 'doubleyoupi-refined-admin-dashboard' ),
			'restore'            => __( 'Restore', 'doubleyoupi-refined-admin-dashboard' ),
			// Styling toggle.
			'stylingOn'          => __( 'Double Youpï styling is ON for this page', 'doubleyoupi-refined-admin-dashboard' ),
			'stylingOff'         => __( 'Double Youpï styling is OFF for this page', 'doubleyoupi-refined-admin-dashboard' ),
			// Group creation / edition.
			'newGroup'           => __( 'New item', 'doubleyoupi-refined-admin-dashboard' ),
			'newGroupPrompt'     => __( 'Name of the new item:', 'doubleyoupi-refined-admin-dashboard' ),
			'newGroupDefault'    => __( 'New item', 'doubleyoupi-refined-admin-dashboard' ),
			'renamePrompt'       => __( 'New name:', 'doubleyoupi-refined-admin-dashboard' ),
			'deleteGroupConfirm' => __( 'Delete this item? Its pages return to their original group.', 'doubleyoupi-refined-admin-dashboard' ),
			'chooseIcon'         => __( 'Choose an icon', 'doubleyoupi-refined-admin-dashboard' ),
			'cancel'             => __( 'Cancel', 'doubleyoupi-refined-admin-dashboard' ),
			// Banner & toasts.
			'unsaved'            => __( 'Unsaved changes', 'doubleyoupi-refined-admin-dashboard' ),
			'discard'            => __( 'Discard', 'doubleyoupi-refined-admin-dashboard' ),
			'reset'              => __( 'Reset to defaults', 'doubleyoupi-refined-admin-dashboard' ),
			'save'               => __( 'Save', 'doubleyoupi-refined-admin-dashboard' ),
			'savedToast'         => __( 'Navigation saved.', 'doubleyoupi-refined-admin-dashboard' ),
			'resetToast'         => __( 'Reset to defaults.', 'doubleyoupi-refined-admin-dashboard' ),
			'errorToast'         => __( 'Something went wrong. Please retry.', 'doubleyoupi-refined-admin-dashboard' ),
			'confirmReset'       => __( 'This will restore the default navigation. Continue?', 'doubleyoupi-refined-admin-dashboard' ),
		];
	}

	// -------------------------------------------------------------------------
	// Getters — consumed by Brand.php for CSS variable injection.
	// -------------------------------------------------------------------------

	public function accent_color(): string {
		$value = (string) get_option( self::OPTION_ACCENT, self::DEFAULT_ACCENT );
		return sanitize_hex_color( $value ) ?: self::DEFAULT_ACCENT;
	}

	/**
	 * Navigation tree: 'reimagined' (the seven curated sections, default) or
	 * 'native' (the WordPress menu, in its own order, drawn by our rail).
	 */
	public function navigation_mode(): string {
		return self::sanitize_navigation_mode( (string) get_option( self::OPTION_NAVIGATION_MODE, 'reimagined' ) );
	}

	public static function is_native_navigation(): bool {
		return 'native' === self::instance()->navigation_mode();
	}

	public static function sanitize_navigation_mode( $value ): string {
		return 'native' === sanitize_key( (string) $value ) ? 'native' : 'reimagined';
	}

	/**
	 * The pages of a section opened next to its rail entry on hover:
	 * 'off', 'one' (the pages, default) or 'two' (the pages and their
	 * sub-pages). One site-level choice, exported with the profile.
	 */
	public static function rail_flyout_mode(): string {
		return self::sanitize_rail_flyout( (string) get_option( self::OPTION_RAIL_FLYOUT, 'one' ) );
	}

	public static function sanitize_rail_flyout( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, [ 'off', 'one', 'two' ], true ) ? $value : 'one';
	}

	/**
	 * Sanitize callback for the accent color setting.
	 *
	 * L + C (lightness + chroma) validation mirroring the JS validator in
	 * admin-settings.js:
	 *   - Hard extremes on L (< 0.10 or > 0.95): reject regardless of chroma —
	 *     no saturation can rescue a near-black or near-white color.
	 *   - Low-chroma colors (C < 0.05, i.e. grayish): soft bounds L < 0.30
	 *     or L > 0.85.
	 *   - Vivid colors (C >= 0.05): accepted across the full range
	 *     [0.10, 0.95] — an electric mint (#00ffa7, L=0.88) or a gold
	 *     (#ffc700, L=0.85) retains its chromatic identity despite its
	 *     high lightness.
	 *
	 * Server-side backup of the JS validator for submissions outside the UI
	 * (curl, devtools, JS disabled).
	 */
	public function sanitize_accent( $value ): string {
		$hex = sanitize_hex_color( (string) $value );
		if ( ! $hex ) {
			add_settings_error(
				self::OPTION_ACCENT,
				'invalid-format',
				__( 'Invalid accent color (expected format: #rrggbb).', 'doubleyoupi-refined-admin-dashboard' )
			);
			return $this->accent_color();
		}

		$problem = self::accent_problem( $hex );
		if ( null !== $problem ) {
			add_settings_error( self::OPTION_ACCENT, 'out-of-range', $problem );
			return $this->accent_color();
		}

		return $hex;
	}

	/**
	 * Why a valid hex color cannot be the accent, or null when it can.
	 * Shared by the form's sanitize callback and the auto-save.
	 */
	public static function accent_problem( string $hex ): ?string {
		[ $l, $c ] = self::oklch_lc( $hex );

		// Chroma-compensated HARD bounds (mirror of admin-settings.js):
		//   L_eff = L_BASE ± min(C, 0.25) * 0.40
		//   → the more saturated the color, the wider the L range tolerates
		//     extremes. An electric mint at L=0.88 but C=0.20 retains its
		//     identity thanks to its chroma.
		$c_bonus = min( $c, 0.25 ) * 0.40;
		if ( $l < 0.45 - $c_bonus ) {
			return __( 'Color too dark: even strong saturation cannot recover identity here.', 'doubleyoupi-refined-admin-dashboard' );
		}
		if ( $l > 0.85 + $c_bonus ) {
			return __( 'Color too light: even strong saturation cannot recover identity here.', 'doubleyoupi-refined-admin-dashboard' );
		}

		// SOFT bounds for grayscale-ish colors (C < 0.05), tighter than the
		// hard bounds: without saturation, contrast collapses.
		if ( $c < 0.05 && $l < 0.55 ) {
			return __( 'Gray too dark: without saturation, tinted surfaces will turn muddy.', 'doubleyoupi-refined-admin-dashboard' );
		}
		if ( $c < 0.05 && $l > 0.78 ) {
			return __( 'Gray too light: without saturation, the accent fades on white backgrounds.', 'doubleyoupi-refined-admin-dashboard' );
		}
		return null;
	}

	/**
	 * Compute OKLCH lightness L AND chroma C for a hex color. Pure-PHP
	 * implementation of the standard sRGB → linear → OKLab → OKLCH chain.
	 *
	 * @return array{0:float,1:float} [L, C] — L in 0–1, C ~0–0.4
	 */
	private static function oklch_lc( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;

		$lin = static fn( $c ) => $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		$r   = $lin( $r );
		$g   = $lin( $g );
		$b   = $lin( $b );

		$l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
		$m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
		$s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

		$cbrt = static fn( $x ) => $x < 0 ? -pow( -$x, 1 / 3 ) : pow( $x, 1 / 3 );

		$l_ = $cbrt( $l );
		$m_ = $cbrt( $m );
		$s_ = $cbrt( $s );

		$L  = 0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_;
		$a  = 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_;
		$b2 = 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_;

		return [ $L, sqrt( $a * $a + $b2 * $b2 ) ];
	}

	public function logo_url(): string {
		return (string) get_option( self::OPTION_LOGO_URL, '' );
	}

	public function logo_id(): int {
		return (int) get_option( self::OPTION_LOGO_ID, 0 );
	}

	public function accent_applies_to_lines(): bool {
		return (bool) get_option( self::OPTION_ACCENT_TO_LINES, false );
	}

	// -------------------------------------------------------------------------
	// Login screen helpers — always active (nothing to configure at this point).
	// -------------------------------------------------------------------------

	public function login_logo_url(): string {
		return home_url( '/' );
	}

	/**
	 * Filter callback for `login_headertext`. Must RETURN the string,
	 * not echo it — otherwise WP outputs the text directly into the HTML stream
	 * (an orphaned "Robot Sensible" at the top of the body, with no wrapper).
	 * The text is used solely as the SR label for the H1 anchor (hidden by our
	 * CSS) — it is invisible.
	 */
	public function login_logo_text( $text = '' ): string {
		unset( $text );
		return esc_html( (string) get_bloginfo( 'name' ) );
	}

	/**
	 * Returns the HTML markup for the logo displayed on wp-login.php.
	 *
	 *   - If the user has uploaded a logo in Settings → Admin → custom image
	 *     (Settings::logo_url()) → rendered as <img>.
	 *   - Otherwise, typographic fallback: first letter of the site name in
	 *     an accent-tinted tile. Deliberately minimal (no generic WP logo)
	 *     so that the DY identity is always present.
	 *
	 * Injected on the login screen via Admin::login_header_logo() (see
	 * login_header hook). The native WP H1 anchor is hidden by CSS.
	 */
	public function login_logo_html(): string {
		$url = $this->logo_url();
		if ( '' !== $url ) {
			return sprintf(
				'<img src="%s" alt="" class="dy-login-logo__img">',
				esc_url( $url )
			);
		}
		$name   = (string) get_bloginfo( 'name' );
		$letter = mb_strtoupper( mb_substr( '' !== $name ? $name : 'Y', 0, 1 ) );
		return sprintf(
			'<span class="dy-login-logo__letter" aria-hidden="true">%s</span>',
			esc_html( $letter )
		);
	}

	// -------------------------------------------------------------------------
	// Settings page.
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( Access::CAP ) ) {
			return;
		}
		$tab     = self::current_tab();
		$base_url = admin_url( 'admin.php?page=doubleyoupi-admin' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Refined Admin Dashboard', 'doubleyoupi-refined-admin-dashboard' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $base_url ); ?>"
					class="nav-tab<?php echo 'general' === $tab ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General', 'doubleyoupi-refined-admin-dashboard' ); ?>
				</a>
				<?php if ( ! self::is_native_navigation() ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'reorganize', $base_url ) ); ?>"
						class="nav-tab<?php echo 'reorganize' === $tab ? ' nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'Reorganize', 'doubleyoupi-refined-admin-dashboard' ); ?>
					</a>
				<?php endif; ?>
			</h2>
			<?php
			if ( 'reorganize' === $tab ) {
				Developer\Renderer::render();
			} else {
				$this->render_tab_general();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Content of the General tab, in four named groups (Brand, Navigation,
	 * Team, Settings profile) drawn with the Double Youpï card system, as on
	 * the media library settings (CLAUDE.md §9.3-bis, revised 30/09/2026).
	 *
	 * Every control saves on its own through AJAX (admin-settings.js). The
	 * options.php form and its submit button stay underneath as the fallback
	 * without JavaScript. The profile import is its own form, so the Settings
	 * profile group lives after the options form, never inside it.
	 *
	 * The ids #dy-brand, #dy-navigation and #dy-profile are the targets of the
	 * first-run card (Welcome.php): keep them.
	 */
	private function render_tab_general(): void {
		$accent          = $this->accent_color();
		$logo_url        = $this->logo_url();
		$logo_id         = $this->logo_id();
		$accent_to_lines = $this->accent_applies_to_lines();
		$per_user        = UserCustomization::is_per_user_mode();
		$nav_mode        = $this->navigation_mode();
		$rail_flyout     = self::rail_flyout_mode();
		$locked          = static fn( string ...$options ): bool => (bool) array_filter( $options, [ Profile::class, 'is_locked' ] );
		$this->render_profile_result();
		// First-run card, once per site, right above the form it describes.
		Welcome::render_card();

		// Une seule introduction à la fois : tant que la carte de première
		// visite est là, elle dit tout ce que cette phrase disait, en mieux.
		// La phrase reprend sa place dès que la carte est écartée.
		if ( ! Welcome::is_pending() ) :
			?>
			<p class="description dy-adset__intro">
				<?php esc_html_e( 'Customize the look of your WordPress admin. Everything else is automatic.', 'doubleyoupi-refined-admin-dashboard' ); ?>
			</p>
		<?php endif; ?>
		<?php if ( null !== Profile::from_code() ) : ?>
			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Some settings are pinned in code.', 'doubleyoupi-refined-admin-dashboard' ); ?></strong>
					<?php esc_html_e( 'A profile is set through DOUBLEYOUPI_ADMIN_PROFILE (or the doubleyoupi_filter_admin_profile filter): the fields it covers are locked below and cannot be changed from this page.', 'doubleyoupi-refined-admin-dashboard' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php
		/**
		 * Curated swatch palette: 12 hand-picked colors, ordered by
		 * OKLCH hue (rainbow red → magenta) for natural visual scanning.
		 * All within the chroma-compensated safe range.
		 *
		 * Names intentionally hard-coded in English (no `__()`):
		 * universal design vocabulary. Translating locally would break
		 * cross-team consistency and risk accented characters in names
		 * that the user wants to avoid.
		 */
		$curated = [
			[ '#e63b6b', 'Cherry' ],
			[ '#ff7d6b', 'Coral' ],
			[ '#f5b896', 'Peach' ],
			[ '#f0d469', 'Butter yellow' ],
			[ '#65d442', 'Lime' ],
			[ '#00e89c', 'Electric mint' ],
			[ '#00c5e8', 'Neon cyan' ],
			[ '#0511ff', 'Electric blue' ],
			[ '#b3b7ff', 'Periwinkle' ],
			[ '#8402ff', 'Vivid violet' ],
			[ '#b878e2', 'Lavender' ],
			[ '#ef02c8', 'Hot magenta' ],
		];
		$accent_locked = $locked( self::OPTION_ACCENT );
		$logo_locked   = $locked( self::OPTION_LOGO_URL, self::OPTION_LOGO_ID );
		?>
		<div class="dy-mlset dy-adset" id="dy-adset">
			<form method="post" action="options.php" class="dy-adset__form">
				<?php settings_fields( 'doubleyoupi_admin' ); ?>

				<section class="dy-mlset__group" id="dy-brand">
					<?php self::group_head( __( 'Brand', 'doubleyoupi-refined-admin-dashboard' ), $locked( self::OPTION_ACCENT, self::OPTION_LOGO_URL, self::OPTION_ACCENT_TO_LINES ) ); ?>
					<div class="dy-mlset__cards">
						<div class="dy-adset__panes dy-adset__panes--brand">
							<div class="dy-adset__cell" data-unit="accent">
								<span class="dy-mlset__icon" aria-hidden="true"><?php self::icon( 'palette' ); ?></span>
								<span class="dy-mlset__body">
									<label class="dy-mlset__name" for="dy_accent"><?php esc_html_e( 'Accent color', 'doubleyoupi-refined-admin-dashboard' ); ?></label>
									<span class="dy-mlset__desc"><?php esc_html_e( 'Primary buttons, links and active states.', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
								</span>
								<div class="dy-adset__extra dy-accent-picker" data-state="valid">
									<div class="dy-accent-swatch-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Recommended colors', 'doubleyoupi-refined-admin-dashboard' ); ?>">
										<?php
										foreach ( $curated as $sw ) :
											[ $hex, $name ] = $sw;
											?>
											<button type="button"
												class="dy-accent-swatch"
												role="radio"
												aria-checked="false"
												data-color="<?php echo esc_attr( $hex ); ?>"
												aria-label="<?php echo esc_attr( $name ); ?>"
												title="<?php echo esc_attr( $name . ' · ' . $hex ); ?>"
												style="--swatch:<?php echo esc_attr( $hex ); ?>"<?php disabled( $accent_locked ); ?>></button>
										<?php endforeach; ?>
									</div>
									<div class="dy-accent-picker__custom">
										<span class="dy-accent-picker__heading"><?php esc_html_e( 'Custom color', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
										<input type="text"
											id="dy_accent"
											class="dy-color-picker"
											data-key="accent"
											name="<?php echo esc_attr( self::OPTION_ACCENT ); ?>"
											value="<?php echo esc_attr( $accent ); ?>"
											data-default-color="<?php echo esc_attr( self::DEFAULT_ACCENT ); ?>"<?php disabled( Profile::is_locked( self::OPTION_ACCENT ) ); ?> />
									</div>
									<div class="dy-accent-feedback" role="alert" hidden>
										<svg class="dy-accent-feedback__icon" width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
											<circle cx="9" cy="9" r="8" fill="currentColor" opacity=".12" />
											<path d="M9 5v4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
											<circle cx="9" cy="12.6" r="1" fill="currentColor" />
										</svg>
										<div class="dy-accent-feedback__body">
											<p class="dy-accent-feedback__title"><?php esc_html_e( 'This color is not accepted', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
											<p class="dy-accent-feedback__text"></p>
											<button type="button" class="dy-accent-suggestion" data-color="" aria-label="<?php esc_attr_e( 'Apply recommended color', 'doubleyoupi-refined-admin-dashboard' ); ?>">
												<span class="dy-accent-suggestion__swatch"></span>
												<span class="dy-accent-suggestion__meta">
													<span class="dy-accent-suggestion__label"><?php esc_html_e( 'Closest recommended color', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
													<span class="dy-accent-suggestion__hex"></span>
												</span>
												<span class="dy-accent-suggestion__cta" aria-hidden="true">
													<?php esc_html_e( 'Apply', 'doubleyoupi-refined-admin-dashboard' ); ?>
													<svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
														<path d="M3 7h8M7.5 3.5L11 7l-3.5 3.5" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
													</svg>
												</span>
											</button>
										</div>
									</div>
								</div>
								<?php self::saved_badge(); ?>
							</div>

							<div class="dy-adset__cell" data-unit="logo">
								<span class="dy-mlset__icon" aria-hidden="true"><?php self::icon( 'image' ); ?></span>
								<span class="dy-mlset__body">
									<span class="dy-mlset__name"><?php esc_html_e( 'Logo', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
									<span class="dy-mlset__desc"><?php esc_html_e( 'Above the rail and on the login screen.', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
								</span>
								<div class="dy-adset__extra dy-logo-field">
									<div id="dy-logo-preview" class="dy-logo-preview">
										<?php if ( $logo_url ) : ?>
											<img src="<?php echo esc_url( $logo_url ); ?>" alt="" />
										<?php else : ?>
											<?php echo wp_kses( Rail::default_logo_svg(), Kses::svg_tags() ); ?>
										<?php endif; ?>
									</div>
									<div class="dy-logo-field__meta">
										<p class="dy-logo-field__buttons">
											<button type="button" class="button" id="dy_logo_pick"<?php disabled( $logo_locked ); ?>>
												<?php
												echo $logo_url
													? esc_html__( 'Change image', 'doubleyoupi-refined-admin-dashboard' )
													: esc_html__( 'Choose image', 'doubleyoupi-refined-admin-dashboard' );
												?>
											</button>
											<button type="button" class="button" id="dy_logo_clear"<?php disabled( ! $logo_url || $logo_locked ); ?>>
												<?php esc_html_e( 'Remove', 'doubleyoupi-refined-admin-dashboard' ); ?>
											</button>
										</p>
										<span class="dy-logo-field__hint"<?php echo $logo_url ? ' hidden' : ''; ?>><?php esc_html_e( 'Without an image, the Double Youpï logo.', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
									</div>
									<input type="hidden"
										name="<?php echo esc_attr( self::OPTION_LOGO_URL ); ?>"
										id="dy_logo_url_input"
										value="<?php echo esc_attr( $logo_url ); ?>" />
									<input type="hidden"
										name="<?php echo esc_attr( self::OPTION_LOGO_ID ); ?>"
										id="dy_logo_id_input"
										value="<?php echo esc_attr( (string) $logo_id ); ?>" />
									<template id="dy-default-logo"><?php echo wp_kses( Rail::default_logo_svg(), Kses::svg_tags() ); ?></template>
								</div>
								<?php self::saved_badge(); ?>
							</div>
						</div>

						<?php
						self::toggle_card( [
							'key'    => 'accent_to_lines',
							'option' => self::OPTION_ACCENT_TO_LINES,
							'on'     => $accent_to_lines,
							'locked' => $locked( self::OPTION_ACCENT_TO_LINES ),
							'name'   => __( 'Accent on every menu line', 'doubleyoupi-refined-admin-dashboard' ),
							'desc'   => __( 'The seven sections of the rail take the accent instead of their own color.', 'doubleyoupi-refined-admin-dashboard' ),
							'icon'   => 'lines',
							'after'  => '<span class="dy-adset__dots" aria-hidden="true">' . str_repeat( '<i></i>', count( PluginMap::LINE_ORDER ) ) . '</span>',
						] );
						?>
					</div>
				</section>

				<section class="dy-mlset__group" id="dy-navigation">
					<?php self::group_head( __( 'Navigation', 'doubleyoupi-refined-admin-dashboard' ), $locked( self::OPTION_NAVIGATION_MODE, self::OPTION_RAIL_FLYOUT ) ); ?>
					<div class="dy-mlset__cards">
						<div class="dy-adset__cell" data-unit="navigation_mode">
							<span class="dy-mlset__icon" aria-hidden="true"><?php self::icon( 'tree' ); ?></span>
							<span class="dy-mlset__body">
								<span class="dy-mlset__name" id="dy-nav-label"><?php esc_html_e( 'Navigation tree', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
							</span>
							<fieldset class="dy-adset__extra"<?php disabled( Profile::is_locked( self::OPTION_NAVIGATION_MODE ) ); ?>>
								<legend class="screen-reader-text"><?php esc_html_e( 'Navigation tree', 'doubleyoupi-refined-admin-dashboard' ); ?></legend>
								<div class="dy-adset__choices">
									<label class="dy-adset__choice">
										<input type="radio" data-key="navigation_mode" name="<?php echo esc_attr( self::OPTION_NAVIGATION_MODE ); ?>" value="reimagined" <?php checked( 'reimagined', $nav_mode ); ?> />
										<span class="dy-adset__schema" aria-hidden="true">
											<?php foreach ( PluginMap::LINE_ORDER as $line ) : ?>
												<i style="--dy-schema-line:var(--dya-line-<?php echo esc_attr( $line ); ?>)"></i>
											<?php endforeach; ?>
										</span>
										<span class="dy-adset__choice-text">
											<span class="dy-adset__choice-name"><?php esc_html_e( 'Reimagined', 'doubleyoupi-refined-admin-dashboard' ); ?> <span class="dy-adset__badge"><?php esc_html_e( 'Recommended', 'doubleyoupi-refined-admin-dashboard' ); ?></span></span>
											<span class="dy-adset__choice-desc"><?php esc_html_e( 'Seven sections, content first.', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
										</span>
									</label>
									<label class="dy-adset__choice">
										<input type="radio" data-key="navigation_mode" name="<?php echo esc_attr( self::OPTION_NAVIGATION_MODE ); ?>" value="native" <?php checked( 'native', $nav_mode ); ?> />
										<span class="dy-adset__schema dy-adset__schema--native" aria-hidden="true"><?php echo wp_kses( str_repeat( '<i></i>', 11 ), [ 'i' => [] ] ); ?></span>
										<span class="dy-adset__choice-text">
											<span class="dy-adset__choice-name"><?php esc_html_e( 'Native WordPress', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
											<span class="dy-adset__choice-desc"><?php esc_html_e( 'The menu in WordPress order, for a team used to it.', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
										</span>
									</label>
								</div>
								<p class="dy-adset__after-choice">
									<?php if ( 'native' !== $nav_mode ) : ?>
										<a href="<?php echo esc_url( add_query_arg( 'tab', 'reorganize', admin_url( 'admin.php?page=doubleyoupi-admin' ) ) ); ?>">
											<?php esc_html_e( 'Reorganize the tree', 'doubleyoupi-refined-admin-dashboard' ); ?> &rarr;
										</a>
									<?php else : ?>
										<?php esc_html_e( 'The Reorganize tab is hidden in native mode. Your layout is kept for when you switch back.', 'doubleyoupi-refined-admin-dashboard' ); ?>
									<?php endif; ?>
								</p>
							</fieldset>
							<?php self::saved_badge(); ?>
						</div>

						<div class="dy-adset__cell dy-adset__cell--inline" data-unit="rail_flyout" id="dy-rail-flyout">
							<span class="dy-mlset__icon" aria-hidden="true"><?php self::icon( 'flyout' ); ?></span>
							<span class="dy-mlset__body">
								<span class="dy-mlset__name" id="dy-flyout-label"><?php esc_html_e( 'Section pages on hover', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
								<span class="dy-mlset__desc"><?php esc_html_e( 'Hover a section of the rail to open its pages next to it. Keyboard: the right arrow opens, Escape closes.', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
							</span>
							<fieldset class="dy-adset__segmented"<?php disabled( Profile::is_locked( self::OPTION_RAIL_FLYOUT ) ); ?>>
								<legend class="screen-reader-text"><?php esc_html_e( 'Section pages on hover', 'doubleyoupi-refined-admin-dashboard' ); ?></legend>
								<?php
								$flyout_choices = [
									'one' => __( 'One level', 'doubleyoupi-refined-admin-dashboard' ),
									'two' => __( 'Two levels', 'doubleyoupi-refined-admin-dashboard' ),
									'off' => __( 'Off', 'doubleyoupi-refined-admin-dashboard' ),
								];
								foreach ( $flyout_choices as $value => $label ) :
									?>
									<label>
										<input type="radio" data-key="rail_flyout" name="<?php echo esc_attr( self::OPTION_RAIL_FLYOUT ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, $rail_flyout ); ?> />
										<span><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<?php self::saved_badge(); ?>
						</div>
					</div>
				</section>

				<section class="dy-mlset__group" id="dy-team">
					<?php self::group_head( __( 'Team', 'doubleyoupi-refined-admin-dashboard' ), $locked( UserCustomization::OPTION_PER_USER, Feedback::OPTION_HIDE ) ); ?>
					<div class="dy-mlset__cards">
						<?php
						self::toggle_card( [
							'key'    => 'per_user',
							'option' => UserCustomization::OPTION_PER_USER,
							'on'     => $per_user,
							'locked' => $locked( UserCustomization::OPTION_PER_USER ),
							'name'   => __( 'Per-user customization', 'doubleyoupi-refined-admin-dashboard' ),
							'desc'   => __( 'Each person can turn the Double Youpï interface off for their own account, from their profile. Your settings stay the default.', 'doubleyoupi-refined-admin-dashboard' ),
							'icon'   => 'team',
						] );
						self::toggle_card( [
							'key'    => 'hide_feedback',
							'option' => Feedback::OPTION_HIDE,
							'on'     => Feedback::hidden(),
							'locked' => $locked( Feedback::OPTION_HIDE ),
							'name'   => __( 'Hide Feedback and review requests', 'doubleyoupi-refined-admin-dashboard' ),
							'desc'   => __( 'No Feedback button, review link or review request, for anyone on this site.', 'doubleyoupi-refined-admin-dashboard' ),
							'icon'   => 'feedback',
						] );
						self::toggle_card( [
							'key'    => 'settings_owner',
							'option' => Access::OPTION_OWNER,
							'on'     => Access::owner_id() > 0 && Access::owner_id() === get_current_user_id(),
							'locked' => defined( Access::UNLOCK ) && constant( Access::UNLOCK ),
							'after'  => defined( Access::UNLOCK ) && constant( Access::UNLOCK )
								? '<span class="dy-mlset__note">' . esc_html__( 'Unlocked in wp-config.php: every administrator can change these settings.', 'doubleyoupi-refined-admin-dashboard' ) . '</span>'
								: '',
							'name'   => __( 'Keep Double Youpï settings to myself', 'doubleyoupi-refined-admin-dashboard' ),
							'desc'   => __( 'Other administrators keep the redesigned admin, without these settings, Feedback or review requests. Locked out? Add define( \'DOUBLEYOUPI_UNLOCK_SETTINGS\', true ); to wp-config.php.', 'doubleyoupi-refined-admin-dashboard' ),
							'icon'   => 'lock',
						] );
						?>
					</div>
				</section>

				<?php // JS-less fallback: when JS is active, auto-save hides this button (.dy-mlset--js, see admin-settings.js). ?>
				<p class="dy-mlset__actions">
					<?php submit_button( __( 'Save changes', 'doubleyoupi-refined-admin-dashboard' ), 'primary', 'submit', false ); ?>
				</p>
			</form>

			<?php // Outside the options form: the import is a form of its own, and forms do not nest. ?>
			<section class="dy-mlset__group" id="dy-profile">
				<?php self::group_head( __( 'Settings profile', 'doubleyoupi-refined-admin-dashboard' ), false ); ?>
				<div class="dy-mlset__cards">
					<div class="dy-adset__panes">
						<div class="dy-adset__cell">
							<span class="dy-mlset__icon" aria-hidden="true"><?php self::icon( 'download' ); ?></span>
							<span class="dy-mlset__body">
								<span class="dy-mlset__name"><?php esc_html_e( 'Export', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
								<span class="dy-mlset__desc"><?php esc_html_e( 'This site’s configuration as one JSON file, to import on the next one.', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
							</span>
							<p class="dy-adset__extra">
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . Profile::ACTION_EXPORT ), Profile::NONCE ) ); ?>">
									<?php esc_html_e( 'Download the profile', 'doubleyoupi-refined-admin-dashboard' ); ?>
								</a>
							</p>
						</div>
						<div class="dy-adset__cell">
							<span class="dy-mlset__icon" aria-hidden="true"><?php self::icon( 'upload' ); ?></span>
							<span class="dy-mlset__body">
								<span class="dy-mlset__name"><?php esc_html_e( 'Import', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
								<span class="dy-mlset__desc"><?php esc_html_e( 'Same checks as this form. The logo keeps its address; the image is not copied.', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
							</span>
							<form class="dy-adset__extra dy-adset__import" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="<?php echo esc_attr( Profile::ACTION_IMPORT ); ?>">
								<?php wp_nonce_field( Profile::NONCE ); ?>
								<input type="file" name="doubleyoupi_profile" accept="application/json,.json" required aria-label="<?php esc_attr_e( 'Import the profile', 'doubleyoupi-refined-admin-dashboard' ); ?>">
								<?php submit_button( __( 'Import the profile', 'doubleyoupi-refined-admin-dashboard' ), 'secondary', 'submit', false ); ?>
							</form>
						</div>
					</div>
					<div class="dy-adset__cell dy-adset__cell--plain">
						<div class="dy-adset__lists">
							<div>
								<p class="dy-adset__list-title"><?php esc_html_e( 'The profile carries', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
								<ul>
									<?php foreach ( Profile::scope_labels() as $label ) : ?>
										<li><?php echo esc_html( $label ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
							<div>
								<p class="dy-adset__list-title"><?php esc_html_e( 'Stays with each site', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
								<ul class="is-muted">
									<li><?php esc_html_e( 'Personal choices: theme, rail width', 'doubleyoupi-refined-admin-dashboard' ); ?></li>
									<li><?php esc_html_e( 'Dashboard layouts', 'doubleyoupi-refined-admin-dashboard' ); ?></li>
								</ul>
							</div>
						</div>
					</div>
					<div class="dy-adset__cell dy-adset__cell--plain">
						<details class="dy-adset__pin">
							<summary><?php esc_html_e( 'Pin the profile in code, on every site', 'doubleyoupi-refined-admin-dashboard' ); ?></summary>
							<code class="dy-adset__code">define( 'DOUBLEYOUPI_ADMIN_PROFILE', __DIR__ . '/doubleyoupi-admin-profile.json' );</code>
							<p class="description"><?php esc_html_e( 'Add to wp-config.php. The settings it covers lock on this page.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
						</details>
					</div>
				</div>
			</section>
		</div>
		<?php
	}

	/** Eyebrow of a group, with the "pinned in code" chip when the profile covers it. */
	private static function group_head( string $label, bool $locked ): void {
		?>
		<div class="dy-adset__head">
			<p class="dy-mlset__eyebrow"><?php echo esc_html( $label ); ?></p>
			<?php if ( $locked ) : ?>
				<span class="dy-adset__lock">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
					<?php esc_html_e( 'Pinned in code', 'doubleyoupi-refined-admin-dashboard' ); ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Transient "Saved" badge, shown by admin-settings.js after an auto-save. */
	private static function saved_badge(): void {
		?>
		<span class="dy-mlset__saved" role="status" aria-hidden="true">
			<svg viewBox="0 0 12 12" class="dy-mlset__saved-icon" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" d="M2.5 6.2 5 8.5l4.5-5"/></svg>
			<?php esc_html_e( 'Saved', 'doubleyoupi-refined-admin-dashboard' ); ?>
		</span>
		<?php
	}

	/**
	 * A toggle row of the card system (same markup as the media library
	 * settings, whose stylesheet draws it). The control is a real checkbox:
	 * auto-saved with JavaScript, posted by the form without.
	 *
	 * @param array<string,mixed> $a
	 */
	private static function toggle_card( array $a ): void {
		$locked = ! empty( $a['locked'] );
		$on     = ! empty( $a['on'] );
		$state  = $locked ? 'blocked' : ( $on ? 'active' : 'default' );
		?>
		<label class="dy-mlset__card" data-state="<?php echo esc_attr( $state ); ?>" data-unit="<?php echo esc_attr( (string) $a['key'] ); ?>">
			<span class="dy-mlset__icon" aria-hidden="true"><?php self::icon( (string) $a['icon'] ); ?></span>
			<span class="dy-mlset__body">
				<span class="dy-mlset__name"><?php echo esc_html( (string) $a['name'] ); ?></span>
				<span class="dy-mlset__desc"><?php echo esc_html( (string) $a['desc'] ); ?></span>
				<?php echo wp_kses( (string) ( $a['after'] ?? '' ), Kses::ui_tags() ); ?>
			</span>
			<input type="checkbox" class="dy-mlset__input"
				data-key="<?php echo esc_attr( (string) $a['key'] ); ?>"
				name="<?php echo esc_attr( (string) $a['option'] ); ?>" value="1"
				<?php checked( $on ); ?> <?php disabled( $locked ); ?>>
			<span class="dy-mlset__switch" aria-hidden="true">
				<span class="dy-mlset__track"><span class="dy-mlset__thumb"></span></span>
			</span>
			<?php self::saved_badge(); ?>
		</label>
		<?php
	}

	/** Inline SVG icon (static, controlled) for a settings card. */
	public static function icon( string $name ): void {
		$icons = [
			'palette'  => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.7-.8 1.7-1.7 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.8-1.7 1.7-1.7h2c3.1 0 5.6-2.5 5.6-5.6C22 6 17.5 2 12 2z"/>',
			'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="m21 15-5-5L6 20"/>',
			'lines'    => '<path d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
			'tree'     => '<path d="M4 5h6M4 12h6M4 19h6M14 5h6M14 12h3M14 19h4"/>',
			'flyout'   => '<rect x="3" y="4" width="6" height="16" rx="1.5"/><path d="M13 7h8M13 11h6M13 15h7"/>',
			'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
			'feedback' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
			'team'     => '<circle cx="9" cy="8" r="3.2"/><path d="M3 19c.8-3.2 3.2-5 6-5s5.2 1.8 6 5"/><circle cx="17" cy="9" r="2.4"/><path d="M16.5 14.2c2.2.2 3.8 1.8 4.5 4.3"/>',
			'download' => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 20h14"/>',
			'upload'   => '<path d="M12 15V4M7.5 8.5 12 4l4.5 4.5M5 20h14"/>',
		];
		$p = $icons[ $name ] ?? '';
		echo wp_kses( '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>', Kses::svg_tags() );
	}

	/**
	 * Outcome of a profile import, read from the redirect query string.
	 */
	private function render_profile_result(): void {
		$state = isset( $_GET['doubleyoupi_profile'] ) ? sanitize_key( (string) wp_unslash( $_GET['doubleyoupi_profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of a redirect outcome, no state change.
		if ( '' === $state ) {
			return;
		}
		$n = isset( $_GET['n'] ) ? absint( wp_unslash( $_GET['n'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$messages = [
			/* translators: %d = number of settings applied */
			'imported' => sprintf( _n( 'Profile imported: %d setting applied.', 'Profile imported: %d settings applied.', $n, 'doubleyoupi-refined-admin-dashboard' ), $n ),
			'nofile'   => __( 'No file received.', 'doubleyoupi-refined-admin-dashboard' ),
			'toolarge' => __( 'This file is too large to be a profile.', 'doubleyoupi-refined-admin-dashboard' ),
			'invalid'  => __( 'This file is not a Double Youpï admin profile.', 'doubleyoupi-refined-admin-dashboard' ),
			'newer'    => __( 'This profile was written by a newer version. Update the plugin first.', 'doubleyoupi-refined-admin-dashboard' ),
		];
		$class = 'imported' === $state ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s inline"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $messages[ $state ] ?? $messages['invalid'] )
		);
	}
}

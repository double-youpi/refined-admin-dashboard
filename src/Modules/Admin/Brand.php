<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brand — injects custom CSS variables into <head>.
 *
 * Three CSS consumers:
 *   --dya-accent          : accent color (primary buttons, links, focus)
 *   --dya-line-content    (and 6 others): section line colors. When
 *                       "Accent on every menu line" is ON, the body gets
 *                       `dy-lines-accent` and _tokens.scss points the seven
 *                       tokens at var(--dya-accent).
 *   --logo-url        : URL of the logo displayed above the rail. When not
 *                       defined, rendering falls back to the default logo
 *                       <img>/<svg> (icon-doubleyoupi.svg) via Rail.php.
 *
 * Rendered inline in <head> (admin + login) to avoid an HTTP roundtrip
 * just for customization. ~200 bytes of CSS.
 */
final class Brand {

	/** Pre-paint theme script; __READY_CLASS__ is the body class proving the real <body> is parsed. */
	private const THEME_PRE_PAINT_JS = <<<'JS'
(function () {
	try {
		var t = localStorage.getItem('dy-admin-theme');
		if (t !== 'light' && t !== 'dark') return;
		var apply = function () {
			if (!document.body) return false;
			if (!document.body.classList.contains('__READY_CLASS__')) return false;
			if (t === 'light') {
				document.body.classList.add('dy-theme-light');
			} else {
				document.body.classList.remove('dy-theme-light');
			}
			return true;
		};
		if (apply()) return;
		var obs = new MutationObserver(function (_, o) {
			if (apply()) o.disconnect();
		});
		obs.observe(document.documentElement, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: ['class'],
		});
	} catch (e) {}
})();
JS;

	private const RAIL_PRE_PAINT_JS = <<<'JS'
(function () {
	// Restores the rail width BEFORE the first paint to avoid the flash
	// of a collapsed rail between the first paint and the moment admin.js
	// reads localStorage and applies the persisted width. Without this,
	// on every cross-document navigation the rail "breathes" for ~100ms.
	//
	// Storage holds 'expanded' / 'collapsed' (state, not pixels). We set
	// `--dya-rail-w-current` to the appropriate CSS token. EXPANDED is the default
	// (fresh install / no stored value) — we only stay collapsed when the user
	// explicitly chose it, or on a narrow viewport (< 900px, mirrors admin.js).
	try {
		var state = localStorage.getItem('dy-admin-rail');
		if (state !== 'collapsed' && window.innerWidth > 900) {
			document.documentElement.style.setProperty('--dya-rail-w-current', 'var(--dya-rail-w-expanded)');
			// Marks the body with data-rail-state so that dependent CSS rules
			// (label opacity, brand text, etc.) apply before the first paint.
			var apply = function () {
				if (!document.body) return false;
				document.body.dataset.railState = 'expanded';
				return true;
			};
			if (!apply()) {
				var obs = new MutationObserver(function (_, o) {
					if (apply()) o.disconnect();
				});
				obs.observe(document.documentElement, { childList: true, subtree: true });
			}
		}
	} catch (e) {}
})();
JS;

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function boot(): void {
		add_action( 'admin_head', [ $this, 'inject_variables' ] );
		add_action( 'login_head', [ $this, 'inject_variables' ] );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		// Pre-paint: applies the dark/light theme BEFORE the first paint
		// to avoid FOUC (dark-mode flash when the user is in light mode).
		// Must run in HEAD before the body is parsed.
		add_action( 'admin_head', [ $this, 'inject_theme_pre_paint' ], 1 );
		add_action( 'login_head', [ $this, 'inject_theme_pre_paint' ], 1 );
	}

	public function inject_theme_pre_paint(): void {
		// Reads localStorage and applies the class as soon as <body> exists.
		//
		// HTML5 TRAP: if another actor emits a non-head element (e.g. an
		// `<svg>`) inside `<head>`, the parser creates an implicit `<body>`
		// WITHOUT attributes; later, when it encounters `<body class="…">`,
		// it refuses to add the `class` attribute because it already exists.
		// Consequence: calling `classList.add()` on the phantom body would
		// permanently block WP classes. We therefore wait until the body
		// carries the `dy-admin` class (injected by our `admin_body_class`
		// filter) before acting — proof that the real `<body class="…">` has
		// been parsed.
		$is_login    = doing_action( 'login_head' ) || did_action( 'login_init' );
		$ready_class = $is_login ? 'login' : 'dy-admin';

		wp_print_inline_script_tag(
			str_replace( '__READY_CLASS__', $ready_class, self::THEME_PRE_PAINT_JS ),
			[ 'id' => 'doubleyoupi-admin-theme-pre-paint' ]
		);
		wp_print_inline_script_tag( self::RAIL_PRE_PAINT_JS, [ 'id' => 'doubleyoupi-admin-rail-pre-paint' ] );
	}

	public function inject_variables(): void {
		$accent          = $this->settings->accent_color();
		$logo_url        = $this->settings->logo_url();

		$css  = ':root,body.dy-admin{';
		$css .= sprintf( '--dya-accent:%s;', $accent );
		$css .= sprintf( '--dya-accent-rgb:%s;', $this->hex_to_rgb( $accent ) );

		if ( $logo_url ) {
			$css .= sprintf( '--logo-url:url("%s");', esc_url( $logo_url ) );
		}

		$css .= '}';

		// The media library (RML, embedded) carries its own accent tokens, set
		// to the Double Youpï violet so that RML alone keeps it. With RAD
		// present, RAD's accent takes over the whole library: the page, its
		// folder panel and the media pickers it dresses (18/10/2026, user
		// feedback). Scoped one class deeper than RML's own declarations.
		// Contract with RML: --accent / --accent-rgb on the library page and its
		// pickers, --dy-accent / --dy-accent-rgb / --dy-accent-hover everywhere.
		// RML derives its tints from these, with the violet as fallback.
		$css .= 'body.dy-admin{--dy-accent:var(--dya-accent);--dy-accent-rgb:var(--dya-accent-rgb);'
			. '--dy-accent-hover:color-mix(in srgb,var(--dya-accent) 86%,#fff);}';
		$css .= 'body.dy-admin.upload-php,body.dy-admin .media-modal.dy-ml-modal{'
			. '--accent:var(--dya-accent);--accent-rgb:var(--dya-accent-rgb);}';

		// CSS assembled from individually sanitized values (sanitize_hex_color for
		// the accent, esc_url for the logo); printed on the spot through the
		// WordPress APIs, after the stylesheets it overrides.
		wp_register_style( 'doubleyoupi-admin-brand-vars', false, [], DOUBLEYOUPI_VERSION );
		wp_add_inline_style( 'doubleyoupi-admin-brand-vars', $css );
		wp_print_styles( 'doubleyoupi-admin-brand-vars' );
	}

	/**
	 * `dy-lines-accent`: the seven section colours become the accent.
	 *
	 * A class rather than variables printed here: the tokens are declared
	 * again by the light theme on `body.dy-theme-light`, which an inline
	 * `:root` override cannot beat, and the settings page previews the
	 * option by toggling this one class. The variables this method used to
	 * print (`--line-*`) had not been read by anything since the tokens took
	 * the `--dya-` prefix, so the option did nothing.
	 */
	public function body_class( string $classes ): string {
		return $this->settings->accent_applies_to_lines() ? $classes . ' dy-lines-accent' : $classes;
	}

	/**
	 * "#1a2b3c" → "26,43,60". For use with rgba(var(--dya-accent-rgb), .x).
	 */
	private function hex_to_rgb( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		// Length alone is not sufficient: "#zzz" becomes 6 chars after expansion
		// but is not hexadecimal → hexdec would return 0 silently,
		// yielding "0,0,0" instead of the expected default accent.
		if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) {
			return '91,62,254'; // fallback to default accent
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return "{$r},{$g},{$b}";
	}
}

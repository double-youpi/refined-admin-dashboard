<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CoreCapabilities — what the running WordPress can do, detected by
 * capability rather than by version number.
 *
 * 7.1.x and 7.2 ship the same packages at different versions, and features
 * land in minors: a version check lies. We ask for the function, the class,
 * the style handle, and expose the answers as body classes so the CSS can
 * key off them too (`dy-core-tooltips`, `dy-core-icons`, `dy-core-wpds`,
 * `dy-core-client-media`). Single place to update when core moves
 * (docs/design/admin-ui/09-veille-wordpress-7x.md, C.1.8).
 */
final class CoreCapabilities {

	/** @var array<string,bool>|null */
	private static ?array $cache = null;

	public function boot(): void {
		add_filter( 'admin_body_class', [ $this, 'body_class' ], 5 );
	}

	/** Core ships its design tokens (`--wpds-*`, handle `wp-theme`, 7.1+). */
	public static function has_wpds_tokens(): bool {
		return self::get( 'wpds' );
	}

	/** Native tooltips and toggletips (`wp_get_tooltip()`, Popover API, 7.1+). */
	public static function has_tooltips(): bool {
		return self::get( 'tooltips' );
	}

	/** Icon API (`wp_get_icon()`, `WP_Icons_Registry`, 7.1+; menu icons `core/xxx` planned for 7.2). */
	public static function has_icon_api(): bool {
		return self::get( 'icons' );
	}

	/** Client-side media processing (`wp_is_client_side_media_processing_enabled()`, 7.1+). */
	public static function has_client_side_media(): bool {
		return self::get( 'client-media' );
	}

	public static function wp_at_least( string $version ): bool {
		return version_compare( (string) get_bloginfo( 'version' ), $version, '>=' );
	}

	/**
	 * Snapshot of the detected capabilities (diagnostics, Developer drawer).
	 *
	 * @return array<string,bool>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = [
				'wpds'         => function_exists( 'wp_style_is' ) && wp_style_is( 'wp-theme', 'registered' ),
				'tooltips'     => function_exists( 'wp_get_tooltip' ),
				'icons'        => function_exists( 'wp_get_icon' ) && class_exists( '\\WP_Icons_Registry' ),
				'client-media' => function_exists( 'wp_is_client_side_media_processing_enabled' ),
			];
		}
		return self::$cache;
	}

	private static function get( string $key ): bool {
		return (bool) ( self::all()[ $key ] ?? false );
	}

	public function body_class( $classes ): string {
		$classes = is_string( $classes ) ? $classes : ''; // another filter may hand back null
		foreach ( self::all() as $key => $on ) {
			if ( $on ) {
				$classes .= ' dy-core-' . $key;
			}
		}
		return $classes;
	}

	/**
	 * Renders a menu icon in any of the three forms WordPress knows, for the
	 * chipbar: a registered icon name (`core/plugins`, `plugin/xxx`, via the
	 * Icon API), a Dashicons class, or an image (data URL / URL). Returns ''
	 * when the icon is generic (`dashicons-admin-generic`…) or unusable, so
	 * the caller keeps its neutral dot.
	 */
	public static function menu_icon_html( string $raw, int $size = 14 ): string {
		$raw = trim( $raw );
		if ( '' === $raw || 'none' === $raw || 'div' === $raw ) {
			return '';
		}
		// 1. Registered icon (7.2 menus, plugins opting in early).
		if ( preg_match( '#^[a-z0-9_-]+/[a-z0-9_-]+$#i', $raw ) ) {
			if ( ! self::has_icon_api() ) {
				return '';
			}
			$svg = (string) wp_get_icon( $raw, [ 'size' => $size, 'class' => 'dy-chip-icon dy-chip-icon--core' ] );
			return $svg;
		}
		// 2. Dashicons class: keep only distinctive glyphs (a generic cog says nothing).
		if ( str_starts_with( $raw, 'dashicons-' ) ) {
			$generic = [ 'dashicons-admin-generic', 'dashicons-admin-plugins', 'dashicons-admin-tools', 'dashicons-admin-settings', 'dashicons-menu', 'dashicons-admin-page', 'dashicons-admin-post' ];
			if ( in_array( $raw, $generic, true ) || ! preg_match( '/^dashicons-[a-z0-9-]+$/', $raw ) ) {
				return '';
			}
			return '<span class="dashicons ' . esc_attr( $raw ) . ' dy-chip-icon dy-chip-icon--dash" aria-hidden="true"></span>';
		}
		// 3. Image: base64 data URL or a real URL (mono-tinted by CSS).
		if ( preg_match( '#^data:image/(?:svg\+xml|png|gif|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $raw ) ) {
			return '<img class="dy-chip-icon dy-chip-icon--img" src="' . esc_attr( $raw ) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" aria-hidden="true">';
		}
		if ( str_starts_with( $raw, 'http://' ) || str_starts_with( $raw, 'https://' ) || str_starts_with( $raw, '/' ) ) {
			$url = esc_url( $raw );
			return '' === $url ? '' : '<img class="dy-chip-icon dy-chip-icon--img" src="' . $url . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" aria-hidden="true">';
		}
		return '';
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ScreenDisabler — disables DY styling on specific sub-pages.
 *
 * Stores in `doubleyoupi_admin_disabled_screens` (JSON array) a list
 * of "sub-page signatures" in a stable canonical form, computed from
 * `$pagenow` + significant query params (`page`, `post_type`,
 * `taxonomy`, `action`). This signature is computable identically from:
 *   (a) the Developer editor (which iterates `$submenu` to build the options)
 *   (b) the admin runtime (which determines the current sub-page)
 *
 * Signature examples:
 *   edit.php                         → "edit.php"
 *   edit.php?post_type=page          → "edit.php?post_type=page"
 *   edit-tags.php?taxonomy=category  → "edit-tags.php?taxonomy=category"
 *   admin.php?page=wpseo_titles      → "admin.php?page=wpseo_titles"
 *
 * When `is_current_screen_disabled()` returns true, Admin::maybe_boot_redesign()
 * skips the full boot (CSS, JS, rail, chipbar, sub-chipbar) → the page remains
 * 100% WP-native.
 *
 * NB: we do not use `get_current_screen()->id` directly because it is
 * not available before `current_screen` (= after `admin_init`), too
 * late to gate `admin_enqueue_scripts`. The URL-based signature is
 * computable as early as `init`.
 */
final class ScreenDisabler {

	public const OPTION_KEY = 'doubleyoupi_admin_disabled_screens';

	/** In-memory cache of the current request evaluation. */
	private static ?bool $current_evaluated = null;

	/**
	 * Returns the list of disabled sub-pages.
	 *
	 * @return string[]
	 */
	public static function get_disabled(): array {
		$raw = get_option( self::OPTION_KEY, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		$out = [];
		foreach ( $decoded as $sig ) {
			$sig = self::sanitize_signature( (string) $sig );
			if ( '' !== $sig ) {
				$out[] = $sig;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Writes the list (with deduplication + sanitization).
	 *
	 * @param string[] $signatures
	 * @return string[] The list as actually persisted.
	 */
	public static function set_disabled( array $signatures ): array {
		$clean = [];
		foreach ( $signatures as $sig ) {
			$sig = self::sanitize_signature( (string) $sig );
			if ( '' !== $sig && ! in_array( $sig, $clean, true ) ) {
				$clean[] = $sig;
			}
		}
		update_option( self::OPTION_KEY, wp_json_encode( $clean ), true ); // read on every admin screen
		self::$current_evaluated = null;
		return $clean;
	}

	/**
	 * Full reset.
	 */
	public static function reset(): void {
		delete_option( self::OPTION_KEY );
		self::$current_evaluated = null;
	}

	/**
	 * Returns true if the current request matches a disabled sub-page.
	 *
	 * Per-request cache: the current signature does not change during
	 * the execution of a single PHP request.
	 */
	public static function is_current_screen_disabled(): bool {
		if ( null !== self::$current_evaluated ) {
			return self::$current_evaluated;
		}
		$current = self::current_signature();
		if ( '' === $current ) {
			self::$current_evaluated = false;
			return false;
		}
		self::$current_evaluated = in_array( $current, self::get_disabled(), true );
		return self::$current_evaluated;
	}

	/**
	 * Computes the canonical signature for the current admin request.
	 *
	 * Algorithm:
	 *   1. Base = $pagenow (e.g. "edit.php", "admin.php", "edit-tags.php")
	 *   2. If $pagenow == "admin.php" → append `?page=…` (the only relevant param)
	 *   3. If $pagenow carries a significant post_type (edit.php / post-new.php)
	 *      → append `?post_type=…`
	 *   4. If $pagenow carries a significant taxonomy (edit-tags.php / term.php)
	 *      → append `?taxonomy=…`
	 *
	 * Returns '' if the request is not inside wp-admin (safety).
	 */
	public static function current_signature(): string {
		global $pagenow;
		if ( ! is_admin() || ! is_string( $pagenow ) || '' === $pagenow ) {
			return '';
		}
		$base = (string) $pagenow;

		// Safe param read (at this point $_GET is already populated by WP).
		$page      = isset( $_GET['page'] )      ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) )      : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		$taxonomy  = isset( $_GET['taxonomy'] )  ? sanitize_text_field( wp_unslash( (string) $_GET['taxonomy'] ) )  : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.

		// admin.php?page=… : keep only the page param
		if ( 'admin.php' === $base ) {
			return '' !== $page ? "admin.php?page={$page}" : 'admin.php';
		}

		// edit.php / post-new.php : may carry post_type
		if ( in_array( $base, [ 'edit.php', 'post-new.php' ], true ) && '' !== $post_type ) {
			return "{$base}?post_type={$post_type}";
		}

		// edit-tags.php / term.php : may carry taxonomy (+ optionally post_type for CPTs)
		if ( in_array( $base, [ 'edit-tags.php', 'term.php' ], true ) && '' !== $taxonomy ) {
			$sig = "{$base}?taxonomy={$taxonomy}";
			if ( '' !== $post_type && 'post' !== $post_type ) {
				$sig .= "&post_type={$post_type}";
			}
			return $sig;
		}

		return $base;
	}

	/**
	 * Computes the canonical signature for a sub-menu slug as it appears
	 * in `$submenu[$parent][i][2]`. Used by the editor to build the
	 * toggleable options on the UI side.
	 *
	 * The WP slug may be:
	 *   - a .php URL (with or without a query string) → decomposed
	 *   - a bare page-slug → resolved via the parent (admin.php?page=… or
	 *     parent.php?page=…)
	 */
	public static function signature_from_submenu_slug( string $slug, string $parent_slug ): string {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return '';
		}
		// Case 1: the slug already carries a .php (native URL)
		if ( str_contains( $slug, '.php' ) ) {
			return self::canonicalize_url_slug( $slug );
		}
		// Case 2: bare page-slug, route through parent
		if ( str_contains( $parent_slug, '.php' ) ) {
			// parent.php?page=slug — the signature collapses to admin.php?page=slug
			// only if parent == admin.php. Otherwise keep the parent?page=… form.
			$parent_sig = self::canonicalize_url_slug( $parent_slug );
			$glue       = str_contains( $parent_sig, '?' ) ? '&' : '?';
			return $parent_sig . $glue . 'page=' . $slug;
		}
		// Case 3: bare parent_slug (e.g. 'woocommerce') → admin.php?page=slug
		return 'admin.php?page=' . $slug;
	}

	/**
	 * Decomposes a slug with a query string and rebuilds it in canonical order
	 * (post_type, taxonomy, page) so that the same URL always produces the same
	 * signature regardless of the source parameter order.
	 */
	private static function canonicalize_url_slug( string $slug ): string {
		[ $path, $query ] = array_pad( explode( '?', $slug, 2 ), 2, '' );
		$path             = (string) $path;
		if ( '' === $query ) {
			return $path;
		}
		parse_str( $query, $params );
		$out = [];
		// Stable order of significant params (consistent with current_signature).
		foreach ( [ 'post_type', 'taxonomy', 'page' ] as $key ) {
			if ( isset( $params[ $key ] ) && '' !== (string) $params[ $key ] ) {
				$out[] = $key . '=' . (string) $params[ $key ];
			}
		}
		if ( empty( $out ) ) {
			return $path;
		}
		return $path . '?' . implode( '&', $out );
	}

	/**
	 * Defensive sanitization for signatures coming from the REST controller
	 * (user payload). Rejects any character outside the URL slug whitelist.
	 */
	private static function sanitize_signature( string $sig ): string {
		$sig = trim( $sig );
		if ( '' === $sig ) {
			return '';
		}
		if ( preg_match( '/[<>"\'\\\\]/', $sig ) ) {
			return '';
		}
		if ( preg_match( '#^(?:javascript|data|vbscript):#i', $sig ) ) {
			return '';
		}
		if ( ! preg_match( '#^[A-Za-z0-9._/?=&%\\-]+$#', $sig ) ) {
			return '';
		}
		return $sig;
	}
}

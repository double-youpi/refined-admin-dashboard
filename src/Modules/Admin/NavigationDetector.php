<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NavigationDetector — continuous capture of plugins not yet mapped.
 *
 * On every admin pageload, Rail::collect() calls `record_unmapped()` for
 * each top-level slug that is neither in PluginMap::NATIVE_MAP, nor in
 * PLUGIN_LINE_MAP, nor in STANDALONE_BUILDERS, nor in user overrides.
 * We maintain a rolling transient (7 days) with label + suggested heuristic,
 * read by the Developer tab to display the "Detected — assign these plugins
 * to a line" drawer.
 *
 * Why a transient and not an option: this data is ephemeral
 * (the list changes with each plugin install/uninstall), no hard persistence
 * needed. If the transient expires, the next pageload regenerates it entirely.
 *
 * Transient format:
 * [
 *   'slug' => [
 *     'label'    => string,  // label shown in WP-admin
 *     'guess'    => string,  // line guessed by PluginMap::guess_line
 *     'seen_at'  => int,     // timestamp of last detection
 *   ],
 *   …
 * ]
 */
final class NavigationDetector {

	public const TRANSIENT_KEY = 'doubleyoupi_admin_unmapped_plugins';
	public const TTL_SECONDS   = 7 * DAY_IN_SECONDS;

	/** In-memory buffer — flushed in a single write via flush(). */
	private static array $pending = [];

	/**
	 * Records an unmapped slug in the in-memory buffer. Idempotent per slug
	 * for the duration of the request. The actual write is deferred to flush()
	 * to avoid one transient round-trip per detected slug.
	 */
	public static function record_unmapped( string $slug, string $label, string $guess ): void {
		$slug  = trim( $slug );
		$label = trim( $label );
		if ( '' === $slug ) {
			return;
		}
		self::$pending[ $slug ] = [
			'label'   => '' !== $label ? $label : $slug,
			'guess'   => $guess,
			'seen_at' => time(),
		];
	}

	/**
	 * Persists the buffer to the transient — a single read + write per
	 * pageload regardless of the number of detected slugs. Call at the end
	 * of Rail::collect() after all top-levels have been filtered.
	 */
	public static function flush(): void {
		if ( empty( self::$pending ) ) {
			return;
		}
		$store           = self::get_all();
		$dirty           = false;
		foreach ( self::$pending as $slug => $meta ) {
			// Skip if already present and up to date (identical label) — avoids
			// a pointless write on every pageload after the first detection.
			if ( isset( $store[ $slug ] ) && ( $store[ $slug ]['label'] ?? '' ) === $meta['label'] ) {
				continue;
			}
			$store[ $slug ] = $meta;
			$dirty          = true;
		}
		self::$pending = [];
		if ( $dirty ) {
			set_transient( self::TRANSIENT_KEY, $store, self::TTL_SECONDS );
		}
	}

	/**
	 * Returns all detected slugs, after filtering entries that have since been
	 * confidently classified (curated, overridden, or matched by a strict
	 * heuristic pattern). Prevents stale entries from persisting in the
	 * "Detected" drawer after a plugin is added to PluginMap or assigned
	 * via overrides.
	 *
	 * @return array<string, array{label:string, guess:string, seen_at:int}>
	 */
	public static function get_all(): array {
		$store = get_transient( self::TRANSIENT_KEY );
		if ( ! is_array( $store ) ) {
			return [];
		}
		$user_classifications = (array) ( NavigationOverrides::get()['classifications'] ?? [] );
		$filtered             = [];
		foreach ( $store as $slug => $meta ) {
			$slug = (string) $slug;
			if ( PluginMap::is_curated( $slug ) ) {
				continue;
			}
			if ( isset( $user_classifications[ $slug ] ) ) {
				continue;
			}
			if ( null !== PluginMap::guess_line_strict( $slug ) ) {
				continue;
			}
			$filtered[ $slug ] = $meta;
		}
		return $filtered;
	}

	/**
	 * Removes a slug from the store. Called after assignment via overrides.
	 */
	public static function clear( string $slug ): void {
		$slug  = trim( $slug );
		$store = self::get_all();
		if ( isset( $store[ $slug ] ) ) {
			unset( $store[ $slug ] );
			if ( empty( $store ) ) {
				delete_transient( self::TRANSIENT_KEY );
			} else {
				set_transient( self::TRANSIENT_KEY, $store, self::TTL_SECONDS );
			}
		}
	}

	/**
	 * Full reset (used by the "Clear detected" button and on a global
	 * overrides reset).
	 */
	public static function reset(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}

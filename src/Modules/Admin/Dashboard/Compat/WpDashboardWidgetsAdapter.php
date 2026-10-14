<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures Dashboard widgets registered via wp_add_dashboard_widget()
 * and classifies them as "WordPress core" or "third-party plugin".
 *
 * The challenge: WP registers its native widgets (At a Glance, Activity,
 * Quick Draft, WP Events) and THEN third-party plugins add theirs, all in the
 * same $wp_meta_boxes array. We capture that array BEFORE DashboardOverride
 * clears it, then classify each entry.
 *
 * Classification strategy (origin detection):
 *   1. Callbacks matching `wp_dashboard_*` or `wp_welcome_panel` → WordPress core
 *   2. Everything else → third-party plugin (better too broad than too narrow)
 */
final class WpDashboardWidgetsAdapter {

	/**
	 * Snapshot captured once at wp_dashboard_setup priority 1.
	 *
	 * @var array<string, array{
	 *   id: string,
	 *   title: string,
	 *   origin: 'wordpress'|'plugin',
	 *   callback: callable|null,
	 *   args: array<string, mixed>
	 * }>
	 */
	private static array $captured = [];

	/**
	 * Captures the current state of dashboard widgets. Must be called from
	 * DashboardOverride::register() BEFORE clearing the meta-boxes.
	 *
	 * Idempotent: a second call overwrites the snapshot (useful for tests).
	 */
	public static function snapshot(): void {
		global $wp_meta_boxes;

		self::$captured = [];

		if ( ! isset( $wp_meta_boxes['dashboard'] ) || ! is_array( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}

		// Structure: $wp_meta_boxes['dashboard'][$context][$priority][$id] = box.
		// $context  = 'normal' | 'side' | 'column3' | 'column4'
		// $priority = 'core' | 'high' | 'default' | 'low'
		foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
			if ( ! is_array( $priorities ) ) {
				continue;
			}
			foreach ( $priorities as $priority => $boxes ) {
				if ( ! is_array( $boxes ) ) {
					continue;
				}
				foreach ( $boxes as $box_id => $box ) {
					if ( ! is_array( $box ) || $box === false ) {
						continue;
					}
					$id = (string) $box_id;
					self::$captured[ $id ] = [
						'id'       => $id,
						'title'    => isset( $box['title'] ) ? wp_strip_all_tags( (string) $box['title'] ) : $id,
						'origin'   => self::detect_origin( $box['callback'] ?? null, $id ),
						'callback' => $box['callback'] ?? null,
						'args'     => isset( $box['args'] ) && is_array( $box['args'] ) ? $box['args'] : [],
					];
				}
			}
		}
	}

	/**
	 * Returns the captured WordPress core widgets, ready for the panel.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_panel_wordpress(): array {
		return self::for_panel_filtered( 'wordpress' );
	}

	/**
	 * Returns the captured third-party plugin widgets, ready for the panel.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_panel_plugins(): array {
		return self::for_panel_filtered( 'plugin' );
	}

	/**
	 * The raw snapshot (for debugging / tests).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function captured(): array {
		return self::$captured;
	}

	/**
	 * Test helper: resets the snapshot. Not exposed in production.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$captured = [];
	}

	/**
	 * Integrates a widget discovered AFTER the initial snapshot (e.g. a plugin
	 * that registers via `add_meta_boxes` or late hooks). Called by
	 * DashboardOverride::late_purge() to ensure the native WP screen lists
	 * everything, without race conditions.
	 *
	 * @param array<string, mixed> $box WP meta-box structure.
	 */
	public static function capture_late( string $id, array $box ): void {
		if ( isset( self::$captured[ $id ] ) ) {
			return;
		}
		self::$captured[ $id ] = [
			'id'       => $id,
			'title'    => isset( $box['title'] ) ? wp_strip_all_tags( (string) $box['title'] ) : $id,
			'origin'   => self::detect_origin( $box['callback'] ?? null, $id ),
			'callback' => $box['callback'] ?? null,
			'args'     => isset( $box['args'] ) && is_array( $box['args'] ) ? $box['args'] : [],
		];
	}

	/**
	 * Builds the panel list, filtered by origin, formatted like native DY
	 * widgets (same keys as WidgetRegistry::to_panel_tiles).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function for_panel_filtered( string $origin ): array {
		$tiles = [];
		foreach ( self::$captured as $box ) {
			if ( ( $box['origin'] ?? 'plugin' ) !== $origin ) {
				continue;
			}
			$tiles[] = [
				'id'                => 'wpnative_' . sanitize_key( (string) $box['id'] ),
				'native_id'         => (string) $box['id'],
				'name'              => (string) $box['title'],
				'description'       => self::describe_origin( $origin ),
				'icon_svg'          => self::origin_icon(),
				'category'          => 'external',
				'origin'            => $origin,
				'is_instanceable'   => false,
				// v4 — the native WP screen lists widgets in a full-width vertical
				// stack; the concept of "slots" does not apply there.
				// The key is still exposed for JS-side consistency.
				'recommended_slots' => 2,
			];
		}
		return $tiles;
	}

	/**
	 * Detects whether a widget comes from WordPress core or a third-party plugin.
	 * Simple heuristic: WP core callbacks follow a known naming convention
	 * (`wp_dashboard_*`, `wp_welcome_panel`).
	 *
	 * @param mixed  $callback Meta-box callback (string | array | Closure).
	 * @param string $box_id   Box ID, used as a secondary hint.
	 * @return 'wordpress'|'plugin'
	 */
	private static function detect_origin( $callback, string $box_id ): string {
		// 1. Heuristic on the ID — WP core widgets have IDs prefixed with
		// 'dashboard_' (e.g. dashboard_right_now, dashboard_activity).
		if ( str_starts_with( $box_id, 'dashboard_' ) || $box_id === 'welcome_panel' ) {
			// Unless the callback does not look like WP core — unlikely, but safer.
			if ( is_string( $callback ) && str_starts_with( $callback, 'wp_dashboard_' ) ) {
				return 'wordpress';
			}
			if ( is_string( $callback ) && $callback === 'wp_welcome_panel' ) {
				return 'wordpress';
			}
			// ID dashboard_* but custom callback: likely a plugin overriding a native
			// widget (rare case).
		}

		// 2. String callback starting with wp_dashboard_ or wp_welcome_panel.
		if ( is_string( $callback ) ) {
			if ( str_starts_with( $callback, 'wp_dashboard_' ) ) {
				return 'wordpress';
			}
			if ( $callback === 'wp_welcome_panel' ) {
				return 'wordpress';
			}
		}

		return 'plugin';
	}

	private static function describe_origin( string $origin ): string {
		if ( $origin === 'wordpress' ) {
			return __( 'Native WordPress widget', 'doubleyoupi-refined-admin-dashboard' );
		}
		return __( 'Widget provided by a third-party plugin', 'doubleyoupi-refined-admin-dashboard' );
	}

	/**
	 * Generic icon for external widgets — a square with a dot ("generic widget").
	 */
	private static function origin_icon(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="12" cy="12" r="2.5" fill="currentColor"/></svg>';
	}
}

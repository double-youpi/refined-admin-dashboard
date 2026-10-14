<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Layout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispositions saved by the administrator.
 *
 * A "disposition" is a complete, named, reusable v4 layout. The plugin ships
 * built-in dispositions (LayoutPresets); the administrator can save additional
 * ones from the current layout via "Save as disposition".
 *
 * Storage: option `doubleyoupi_dashboard_dispositions` (autoload false),
 * associative array `slug => { slug, name, layout }`. JSON-encoded to remain
 * readable in the DB and avoid fragile PHP serialisation.
 */
final class Dispositions {

	public const OPTION_KEY = 'doubleyoupi_dashboard_dispositions';
	public const HIDDEN_KEY = 'doubleyoupi_dashboard_hidden_builtins';
	public const MAX_CUSTOM = 30;

	/**
	 * Does the slug belong to a built-in disposition (LayoutPresets)?
	 * Used to route save/delete operations (override vs. hiding) and to
	 * deduplicate the catalogue (an entry stored under a built-in slug is an
	 * OVERRIDE, not a user disposition to be listed twice).
	 */
	public static function is_builtin( string $slug ): bool {
		$slug = sanitize_key( $slug );
		foreach ( LayoutPresets::list() as $preset ) {
			if ( $preset['slug'] === $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * List of slugs of hidden built-in dispositions (deleted by the admin).
	 *
	 * @return array<int, string>
	 */
	public static function hidden_builtins(): array {
		$raw = get_option( self::HIDDEN_KEY, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		return array_values( array_unique( array_map( 'sanitize_key', array_map( 'strval', $raw ) ) ) );
	}

	/**
	 * Hides a built-in disposition (equivalent to "delete" in the UI, without
	 * touching the code). Reversible via a global reset.
	 */
	public static function hide_builtin( string $slug ): bool {
		$slug = sanitize_key( $slug );
		if ( ! self::is_builtin( $slug ) ) {
			return false;
		}
		$hidden = self::hidden_builtins();
		if ( ! in_array( $slug, $hidden, true ) ) {
			$hidden[] = $slug;
		}
		// A hidden built-in can no longer be assigned → purge its role assignments.
		RoleAssignments::forget_disposition( $slug );
		return (bool) update_option( self::HIDDEN_KEY, $hidden, false );
	}

	/**
	 * All saved custom dispositions.
	 *
	 * @return array<string, array{slug:string, name:string, layout:array<string,mixed>}>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION_KEY, '' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$out = [];
		foreach ( $decoded as $slug => $entry ) {
			if ( ! is_array( $entry ) || ! is_array( $entry['layout'] ?? null ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' ) {
				continue;
			}
			$out[ $slug ] = [
				'slug'   => $slug,
				'name'   => sanitize_text_field( (string) ( $entry['name'] ?? $slug ) ),
				'layout' => Migration::to_v4( $entry['layout'] ),
			];
		}
		return $out;
	}

	/**
	 * Retrieves a custom disposition by slug.
	 *
	 * @return array{slug:string, name:string, layout:array<string,mixed>}|null
	 */
	public static function get( string $slug ): ?array {
		$slug = sanitize_key( $slug );
		$all  = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Retrieves the layout for a disposition by slug (built-in OR custom),
	 * including overrides. Returns null if the slug does not exist. Used by the
	 * resolver to read the disposition assigned to a role on the fly.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function layout_for_slug( string $slug ): ?array {
		$slug = sanitize_key( $slug );
		if ( $slug === '' ) {
			return null;
		}
		// Custom disposition OR override of a built-in (stored under this slug).
		$stored = self::get( $slug );
		if ( $stored && is_array( $stored['layout'] ?? null ) && $stored['layout'] !== [] ) {
			return $stored['layout'];
		}
		// Built-in, not overridden → hard-coded layout.
		if ( self::is_builtin( $slug ) && ! in_array( $slug, self::hidden_builtins(), true ) ) {
			return LayoutPresets::get( $slug );
		}
		return null;
	}

	/**
	 * Saves a disposition from an already-validated layout.
	 *
	 * @param array<string, mixed> $layout Validated v4 layout (see LayoutValidator).
	 * @return array{slug:string, name:string, layout:array<string,mixed>}|\WP_Error
	 */
	public static function save( string $name, array $layout ) {
		$name = trim( sanitize_text_field( $name ) );
		if ( $name === '' ) {
			return new \WP_Error( 'invalid_name', __( 'A disposition name is required.', 'doubleyoupi-refined-admin-dashboard' ) );
		}

		$all = self::all();
		if ( count( $all ) >= self::MAX_CUSTOM ) {
			return new \WP_Error( 'too_many', __( 'Maximum number of saved dispositions reached.', 'doubleyoupi-refined-admin-dashboard' ) );
		}

		$slug = self::unique_slug( $name, $all );

		// The internal preset slug points to itself so the current disposition
		// can be recognised as active after it has been applied.
		$layout['preset'] = $slug;

		$all[ $slug ] = [
			'slug'   => $slug,
			'name'   => $name,
			'layout' => $layout,
		];

		self::persist( $all );

		return $all[ $slug ];
	}

	/**
	 * Overwrites the layout of an existing custom disposition (keeps its name).
	 *
	 * @param array<string, mixed> $layout Validated v4 layout.
	 * @return array{slug:string, name:string, layout:array<string,mixed>}|\WP_Error
	 */
	public static function update( string $slug, array $layout ) {
		$slug = sanitize_key( $slug );
		$all  = self::all();

		// Built-in slug not yet overridden → create an OVERRIDE entry stored under
		// the built-in slug. catalog() will make it take precedence over the
		// hard-coded layout. The name is taken from the built-in preset.
		if ( ! isset( $all[ $slug ] ) ) {
			if ( ! self::is_builtin( $slug ) ) {
				return new \WP_Error( 'unknown_disposition', __( 'Unknown disposition.', 'doubleyoupi-refined-admin-dashboard' ) );
			}
			$preset_name = $slug;
			foreach ( LayoutPresets::list() as $preset ) {
				if ( $preset['slug'] === $slug ) {
					$preset_name = (string) $preset['name'];
					break;
				}
			}
			$all[ $slug ] = [ 'slug' => $slug, 'name' => $preset_name, 'layout' => [] ];
		}

		$layout['preset']        = $slug;
		$all[ $slug ]['layout']  = $layout;
		self::persist( $all );

		return $all[ $slug ];
	}

	public static function delete( string $slug ): bool {
		$slug = sanitize_key( $slug );
		$all  = self::all();
		if ( ! isset( $all[ $slug ] ) ) {
			return false;
		}
		unset( $all[ $slug ] );
		// Clean up role assignments that were pointing to this disposition.
		RoleAssignments::forget_disposition( $slug );
		return self::persist( $all );
	}

	/**
	 * Full catalogue for the UI: built-in + custom dispositions.
	 *
	 * Each entry: { slug, name, description, builtin, layout }.
	 *
	 * @return array<int, array{slug:string, name:string, description:string, builtin:bool, layout:array<string,mixed>}>
	 */
	public static function catalog(): array {
		$out      = [];
		$all      = self::all();
		$hidden   = self::hidden_builtins();

		foreach ( LayoutPresets::list() as $preset ) {
			$slug = $preset['slug'];
			if ( in_array( $slug, $hidden, true ) ) {
				continue; // hidden by the admin → absent from the catalogue.
			}
			// Any override (entry stored under the built-in slug) takes precedence
			// over the hard-coded layout.
			$has_override = isset( $all[ $slug ] );
			$out[] = [
				'slug'        => $slug,
				'name'        => $preset['name'],
				'description' => $has_override ? __( 'Built-in (customized)', 'doubleyoupi-refined-admin-dashboard' ) : $preset['description'],
				'builtin'     => true,
				'customized'  => $has_override,
				'layout'      => $has_override ? $all[ $slug ]['layout'] : LayoutPresets::get( $slug ),
			];
		}

		foreach ( $all as $entry ) {
			// Overrides of built-ins are already merged above: only list here
			// the TRUE user-created dispositions.
			if ( self::is_builtin( $entry['slug'] ) ) {
				continue;
			}
			$out[] = [
				'slug'        => $entry['slug'],
				'name'        => $entry['name'],
				'description' => __( 'Saved disposition', 'doubleyoupi-refined-admin-dashboard' ),
				'builtin'     => false,
				'customized'  => false,
				'layout'      => $entry['layout'],
			];
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $all
	 */
	private static function persist( array $all ): bool {
		$encoded = wp_json_encode( $all );
		if ( $encoded === false ) {
			return false;
		}
		return (bool) update_option( self::OPTION_KEY, $encoded, false );
	}

	/**
	 * @param array<string, mixed> $existing
	 */
	private static function unique_slug( string $name, array $existing ): string {
		$base = sanitize_key( $name );
		if ( $base === '' ) {
			$base = 'disposition';
		}
		// Prefix to avoid any collision with a built-in preset slug.
		$base = 'dyo-' . $base;
		$slug = $base;
		$i    = 2;
		while ( isset( $existing[ $slug ] ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}
		return $slug;
	}
}

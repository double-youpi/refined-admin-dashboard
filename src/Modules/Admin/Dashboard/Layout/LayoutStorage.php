<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Layout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read / write of layouts per user (user_meta) and per role (option).
 *
 * User layouts:
 *   - Stored in user_meta under the key `doubleyoupi_dashboard_layout`
 *   - Serialised as JSON (not PHP serialize — to remain readable in the DB)
 *
 * Per-role layouts:
 *   - Stored as an option (autoload false) under `doubleyoupi_dashboard_role_{slug}`
 *   - Phase 4 — the methods are already in place for phase 1.
 *
 * Note: we store a JSON string rather than a PHP array for two reasons:
 *   1. Direct readability in the DB (SQL export, debugging)
 *   2. Avoids WP serialising meta arrays with serialize() — which breaks on any
 *      PHP class change (safe in v8.1+ with no objects, but still fragile).
 */
final class LayoutStorage {

	public const USER_META_KEY      = 'doubleyoupi_dashboard_layout';
	public const ROLE_OPTION_PREFIX = 'doubleyoupi_dashboard_role_';

	/**
	 * Retrieves the layout for a user. Returns null if not stored.
	 *
	 * Transparent v3→v4 auto-migration: if the payload found in the DB is in
	 * v3 (or older), it is promoted to v4 on read. LayoutResolver will
	 * systematically re-validate it before returning to the caller.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_for_user( int $user_id ): ?array {
		$raw = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		return Migration::to_v4( $decoded );
	}

	/**
	 * Persists a layout for a user. The payload MUST already be validated via
	 * LayoutValidator (this class does not re-validate — that is the caller's
	 * responsibility).
	 *
	 * @param array<string, mixed> $layout
	 * @return bool true if the write succeeded, false otherwise
	 */
	public static function set_for_user( int $user_id, array $layout ): bool {
		$encoded = wp_json_encode( $layout );
		if ( $encoded === false ) {
			return false;
		}
		return (bool) update_user_meta( $user_id, self::USER_META_KEY, wp_slash( $encoded ) );
	}

	/**
	 * Deletes the layout for a user (reverts to the role layout or preset).
	 */
	public static function delete_for_user( int $user_id ): bool {
		return (bool) delete_user_meta( $user_id, self::USER_META_KEY );
	}

	/**
	 * Retrieves the layout assigned to a WP role. Transparent v3→v4 migration.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_for_role( string $role_slug ): ?array {
		$role_slug = sanitize_key( $role_slug );
		if ( $role_slug === '' ) {
			return null;
		}
		$raw = get_option( self::ROLE_OPTION_PREFIX . $role_slug, null );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		return Migration::to_v4( $decoded );
	}

	/**
	 * Persists a layout for a role (Phase 4).
	 *
	 * @param array<string, mixed> $layout
	 */
	public static function set_for_role( string $role_slug, array $layout ): bool {
		$role_slug = sanitize_key( $role_slug );
		if ( $role_slug === '' ) {
			return false;
		}
		$encoded = wp_json_encode( $layout );
		if ( $encoded === false ) {
			return false;
		}
		// autoload false: these options are not auto-loaded on every page load
		// (saves memory when many roles are assigned).
		return (bool) update_option( self::ROLE_OPTION_PREFIX . $role_slug, $encoded, false );
	}

	public static function delete_for_role( string $role_slug ): bool {
		$role_slug = sanitize_key( $role_slug );
		if ( $role_slug === '' ) {
			return false;
		}
		return (bool) delete_option( self::ROLE_OPTION_PREFIX . $role_slug );
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Layout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layout resolver for a given user.
 *
 * Priority cascade (highest to lowest):
 *   1. user_meta — the user's personal layout (if they have customised it)
 *   2. per-role option — disposition assigned to their primary role
 *   3. hard-coded "Standard" preset
 *
 * Applied filters:
 *   - `doubleyoupi_dashboard_resolved_layout` ($layout, $user_id) after resolution.
 *     Allows a third-party plugin to modify the returned disposition
 *     (e.g. force a custom widget, hide a widget by context).
 *
 * The returned layout is always validated via LayoutValidator before return —
 * ensures consumers receive a consistent structure even if a corrupt payload
 * has been left in the DB from a previous version.
 */
final class LayoutResolver {

	/**
	 * Returns the effective layout for the user.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_user( int $user_id ): array {
		$raw = self::raw_for_user( $user_id );

		// Always validate / sanitise before returning.
		$validated = LayoutValidator::validate( $raw );
		if ( is_wp_error( $validated ) ) {
			// Corrupt layout in DB → hard fallback to the standard preset.
			$validated = LayoutPresets::standard();
		}

		/**
		 * Allows modules / plugins to modify the layout after resolution.
		 *
		 * @param array $layout  Resolved and validated layout.
		 * @param int   $user_id User ID.
		 */
		return (array) apply_filters( 'doubleyoupi_dashboard_resolved_layout', $validated, $user_id );
	}

	/**
	 * Retrieves the raw layout according to the cascade, without validation.
	 *
	 * @return array<string, mixed>
	 */
	private static function raw_for_user( int $user_id ): array {
		// 0. A disposition imposed on one of the user's roles wins over their
		// own dashboard (RoleAssignments::ENFORCED_KEY, 11/11/2026).
		$enforced = RoleAssignments::enforced_for_user( $user_id );
		if ( $enforced !== null ) {
			$layout = Dispositions::layout_for_slug( $enforced );
			if ( is_array( $layout ) ) {
				return $layout;
			}
		}

		// 1. user_meta
		$user_layout = LayoutStorage::get_for_user( $user_id );
		if ( is_array( $user_layout ) ) {
			return $user_layout;
		}

		// 2. Disposition of the user's roles: an explicit assignment on ANY role
		// before a heuristic default. We read the layout on the fly from the
		// disposition (no frozen copy) → if the admin updates the disposition,
		// the assigned roles benefit immediately.
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( $user && ! empty( $user->roles ) ) {
			$slug = RoleAssignments::for_roles( (array) $user->roles );
			$role_layout = $slug !== null ? Dispositions::layout_for_slug( $slug ) : null;
			if ( is_array( $role_layout ) ) {
				return $role_layout;
			}
		}

		// 3. standard preset
		return LayoutPresets::standard();
	}
}

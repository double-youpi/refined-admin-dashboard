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
		// 1. user_meta
		$user_layout = LayoutStorage::get_for_user( $user_id );
		if ( is_array( $user_layout ) ) {
			return $user_layout;
		}

		// 2. Disposition assigned to one of the user's roles. We read the layout
		// on the fly from the disposition (no frozen copy) → if the admin updates
		// the disposition, the assigned roles benefit immediately.
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( $user && ! empty( $user->roles ) ) {
			foreach ( (array) $user->roles as $role ) {
				// Explicit assignment by the admin, otherwise the role's heuristic default.
				$assigned = RoleAssignments::effective_for_role( (string) $role );
				if ( $assigned === null ) {
					continue;
				}
				$role_layout = Dispositions::layout_for_slug( $assigned );
				if ( is_array( $role_layout ) ) {
					return $role_layout;
				}
			}
		}

		// 3. standard preset
		return LayoutPresets::standard();
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Layout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assignment of dispositions to WordPress roles.
 *
 * Model: a role has AT MOST one assigned disposition. A disposition can be
 * assigned to multiple roles. Source of truth: option
 * `doubleyoupi_dashboard_role_assignments` = { role_slug => disposition_slug }.
 *
 * At resolution time (LayoutResolver), a user without a personal layout receives
 * the disposition assigned to one of their roles (the layout is read on the fly
 * from the disposition → no frozen copy that goes stale if the disposition changes).
 */
final class RoleAssignments {

	public const OPTION_KEY = 'doubleyoupi_dashboard_role_assignments';

	/**
	 * Roles whose assigned disposition is IMPOSED: it wins over the dashboards
	 * people of that role customized, and they cannot change it (11/11/2026).
	 * Only for an explicit assignment; the accounts that manage RAD
	 * (`doubleyoupi_manage`) are never held by it.
	 */
	public const ENFORCED_KEY = 'doubleyoupi_dashboard_role_enforced';

	/**
	 * Full map of role_slug => disposition_slug (empty entries cleaned out).
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION_KEY, '' );
		if ( is_string( $raw ) && $raw !== '' ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $role => $slug ) {
			$role = sanitize_key( (string) $role );
			$slug = sanitize_key( (string) $slug );
			if ( $role !== '' && $slug !== '' ) {
				$out[ $role ] = $slug;
			}
		}
		return $out;
	}

	/** Disposition slug EXPLICITLY assigned to a role (by the admin), or null. */
	public static function for_role( string $role_slug ): ?string {
		$role_slug = sanitize_key( $role_slug );
		$all       = self::all();
		return $all[ $role_slug ] ?? null;
	}

	/**
	 * DEFAULT disposition relevant for a role, derived from its capabilities
	 * (not from a hard-coded role list → also covers roles added by WooCommerce,
	 * Yoast, Rank Math, etc.):
	 *   - manage_options (admin)         → no default (customisable → Standard)
	 *   - shop management (WC)           → Shop Pro
	 *   - can edit content               → Editor
	 *   - otherwise (read-only)          → no default (→ Standard)
	 *
	 * Filterable via `doubleyoupi_dashboard_default_role_disposition`.
	 */
	public static function default_for_role( string $role_slug ): ?string {
		$role_slug = sanitize_key( $role_slug );
		$role      = $role_slug !== '' ? get_role( $role_slug ) : null;
		$caps      = $role ? (array) $role->capabilities : [];
		$can       = static fn( string $c ): bool => ! empty( $caps[ $c ] );

		$slug = null;
		if ( $can( 'manage_options' ) ) {
			$slug = null; // admin: no imposed default, dashboard is customisable.
		} elseif ( $can( 'manage_woocommerce' ) || $can( 'view_woocommerce_reports' ) || $can( 'edit_shop_orders' ) ) {
			$slug = 'boutique-pro'; // shop manager.
		} elseif ( $can( 'edit_posts' ) ) {
			$slug = 'editor'; // editor / author / contributor, SEO editorial roles…
		}

		/**
		 * Allows adjusting the default disposition for a role (agency/dev use).
		 *
		 * @param string|null          $slug      Disposition slug (or null).
		 * @param string               $role_slug Role being resolved.
		 * @param array<string, bool>  $caps      Role capabilities.
		 */
		$slug = apply_filters( 'doubleyoupi_dashboard_default_role_disposition', $slug, $role_slug, $caps );
		return ( is_string( $slug ) && $slug !== '' ) ? sanitize_key( $slug ) : null;
	}

	/**
	 * EFFECTIVE disposition for a role: the admin's explicit assignment if it
	 * exists, otherwise the heuristic default. Used by the resolver.
	 */
	public static function effective_for_role( string $role_slug ): ?string {
		$explicit = self::for_role( $role_slug );
		return $explicit !== null ? $explicit : self::default_for_role( $role_slug );
	}

	/**
	 * Disposition of a user's roles: an EXPLICIT assignment on any of the roles
	 * wins over a heuristic default. Before 11/11/2026 the first role's default
	 * won over an explicit assignment on the second role.
	 *
	 * @param string[] $roles
	 */
	public static function for_roles( array $roles ): ?string {
		foreach ( $roles as $role ) {
			$explicit = self::for_role( (string) $role );
			if ( $explicit !== null && Dispositions::layout_for_slug( $explicit ) !== null ) {
				return $explicit;
			}
		}
		foreach ( $roles as $role ) {
			$default = self::default_for_role( (string) $role );
			if ( $default !== null && Dispositions::layout_for_slug( $default ) !== null ) {
				return $default;
			}
		}
		return null;
	}

	/** @return string[] Roles whose assignment is imposed. */
	public static function enforced_roles(): array {
		$raw = get_option( self::ENFORCED_KEY, [] );
		$raw = is_array( $raw ) ? $raw : [];
		$map = self::all();
		// Imposition only stands on an explicit assignment.
		return array_values( array_filter(
			array_unique( array_map( static fn( $r ) => sanitize_key( (string) $r ), $raw ) ),
			static fn( $r ) => $r !== '' && isset( $map[ $r ] )
		) );
	}

	/**
	 * The imposed disposition for a user, or null: the first of their roles
	 * with an imposed assignment. Never for the accounts that manage RAD.
	 */
	public static function enforced_for_user( int $user_id ): ?string {
		if ( ! $user_id || user_can( $user_id, \DoubleYoupi\Admin\Access::CAP ) ) {
			return null;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		$enforced = self::enforced_roles();
		foreach ( (array) $user->roles as $role ) {
			if ( in_array( $role, $enforced, true ) ) {
				$slug = self::for_role( (string) $role );
				if ( $slug !== null && Dispositions::layout_for_slug( $slug ) !== null ) {
					return $slug;
				}
			}
		}
		return null;
	}

	/**
	 * Whether the current user may customize their dashboard: the capability,
	 * and no disposition imposed on their role.
	 */
	public static function current_user_can_customize(): bool {
		if ( null !== \DoubleYoupi\Modules\Admin\Preview::role() ) {
			return false; // « View as… » shows the role's dashboard, read-only
		}
		$can = current_user_can( 'doubleyoupi_customize_dashboard' ) || current_user_can( 'manage_options' );
		return $can && self::enforced_for_user( get_current_user_id() ) === null;
	}

	/**
	 * Sets or clears the imposition for the roles of one disposition.
	 *
	 * @param string[] $roles Roles now assigned to $disposition_slug.
	 */
	public static function set_enforced( string $disposition_slug, array $roles, bool $enforce ): void {
		$disposition_slug = sanitize_key( $disposition_slug );
		$map      = self::all();
		$enforced = self::enforced_roles();
		// Drop this disposition's roles, then add back the selected ones if asked.
		$enforced = array_values( array_filter(
			$enforced,
			static fn( $r ) => ( $map[ $r ] ?? null ) !== $disposition_slug
		) );
		if ( $enforce ) {
			foreach ( $roles as $role ) {
				$role = sanitize_key( (string) $role );
				if ( ( $map[ $role ] ?? null ) === $disposition_slug ) {
					$enforced[] = $role;
				}
			}
		}
		update_option( self::ENFORCED_KEY, array_values( array_unique( $enforced ) ), false );
	}

	/**
	 * Clears the dashboards customized by users of these roles: they get the
	 * role's disposition again. Accounts that manage RAD are left alone.
	 *
	 * @param string[] $roles
	 * @return int Number of dashboards cleared.
	 */
	public static function reset_personal( array $roles ): int {
		$roles = array_values( array_filter( array_map( static fn( $r ) => sanitize_key( (string) $r ), $roles ) ) );
		if ( ! $roles ) {
			return 0;
		}
		$ids = get_users( [
			'role__in' => $roles,
			'fields'   => 'ID',
			'meta_key' => LayoutStorage::USER_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off admin action.
		] );
		$n = 0;
		foreach ( $ids as $id ) {
			if ( user_can( (int) $id, \DoubleYoupi\Admin\Access::CAP ) ) {
				continue;
			}
			if ( LayoutStorage::delete_for_user( (int) $id ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Map of heuristic defaults for ALL roles (role => slug). Exposed to the UI
	 * to display the hint "(default: X)" in the assignment modal.
	 *
	 * @return array<string, string>
	 */
	public static function defaults_map(): array {
		$out = [];
		foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
			$d = self::default_for_role( (string) $role );
			if ( $d !== null ) {
				$out[ (string) $role ] = $d;
			}
		}
		return $out;
	}

	/**
	 * Roles to which a disposition is assigned.
	 *
	 * @return array<int, string>
	 */
	public static function roles_for( string $disposition_slug ): array {
		$disposition_slug = sanitize_key( $disposition_slug );
		$roles            = [];
		foreach ( self::all() as $role => $slug ) {
			if ( $slug === $disposition_slug ) {
				$roles[] = $role;
			}
		}
		return $roles;
	}

	/**
	 * (Re)assigns a disposition to an exact set of roles. For each role in
	 * $roles, writes map[role] = $slug (overwriting any other disposition →
	 * conflict handling is done on the UI side). Roles that previously had THIS
	 * disposition but are no longer in $roles are unassigned.
	 *
	 * @param array<int, string> $roles
	 * @return array<string, string> The updated map.
	 */
	public static function assign( string $disposition_slug, array $roles ): array {
		$disposition_slug = sanitize_key( $disposition_slug );
		$valid_roles      = array_keys( wp_roles()->get_names() );
		$roles            = array_values( array_filter(
			array_map( static fn( $r ) => sanitize_key( (string) $r ), $roles ),
			static fn( $r ) => $r !== '' && in_array( $r, $valid_roles, true )
		) );

		$map = self::all();

		// 1. Remove this disposition from roles that are no longer selected.
		foreach ( $map as $role => $slug ) {
			if ( $slug === $disposition_slug && ! in_array( $role, $roles, true ) ) {
				unset( $map[ $role ] );
			}
		}
		// 2. Assign (overwrite) this disposition to the selected roles.
		foreach ( $roles as $role ) {
			$map[ $role ] = $disposition_slug;
		}

		self::persist( $map );
		return $map;
	}

	/**
	 * Removes all assignments pointing to a deleted disposition.
	 * Called when a disposition is deleted or hidden.
	 */
	public static function forget_disposition( string $disposition_slug ): void {
		$disposition_slug = sanitize_key( $disposition_slug );
		$map              = self::all();
		$changed          = false;
		foreach ( $map as $role => $slug ) {
			if ( $slug === $disposition_slug ) {
				unset( $map[ $role ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::persist( $map );
		}
	}

	/** @param array<string, string> $map */
	private static function persist( array $map ): bool {
		$encoded = wp_json_encode( $map );
		if ( $encoded === false ) {
			return false;
		}
		return (bool) update_option( self::OPTION_KEY, $encoded, false );
	}
}

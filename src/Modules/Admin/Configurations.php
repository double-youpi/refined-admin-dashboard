<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Admin\Access;
use DoubleYoupi\Modules\Admin\Dashboard\Layout\Dispositions;
use DoubleYoupi\Modules\Admin\Dashboard\Layout\RoleAssignments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configurations: named setups of the admin, assigned to roles (RAD 1.1.0,
 * decided by Raphaël on 11/11/2026, docs/strategy/PROPOSITION-CONFIGURATIONS-PAR-ROLE-2026-11-11.md).
 *
 * A configuration carries what shapes the admin someone sees: the menu (the
 * Reorganize layout: order, groups, hidden entries), the screens left in
 * native styling, the navigation mode, and a dashboard disposition. The brand
 * (accent, logo) and the Toolkit stay common to the site.
 *
 * How it applies: the three options the plugin already reads everywhere are
 * served through `pre_option_*` with the configuration of the current user's
 * role, so nothing else in the plugin changes. Order:
 *   1. a profile pinned in code (Profile, priority 5) always wins;
 *   2. while Reorganize edits a configuration (« editing » context), that one;
 *   3. while an administrator previews a role (Preview), that role's;
 *   4. the accounts that manage Double Youpï see the site's own setup;
 *   5. otherwise the configuration of the first of the user's roles that has one;
 *   6. none: the site's setup, as before 1.1.0.
 *
 * Hiding a menu entry is not a security measure: WordPress capabilities stay
 * the only barrier; a configuration hides, it never removes a right.
 */
final class Configurations {

	public const OPTION_ITEMS = 'doubleyoupi_admin_configurations';
	public const OPTION_ROLES = 'doubleyoupi_admin_configuration_roles';
	public const MAX          = 20;

	/** URL / REST parameter naming the configuration Reorganize edits. */
	public const EDIT_PARAM = 'dy_config';

	/** Options a configuration carries, served per role. */
	private const SERVED = [
		'navigation'       => NavigationOverrides::OPTION_KEY,
		'disabled_screens' => ScreenDisabler::OPTION_KEY,
		'navigation_mode'  => Settings::OPTION_NAVIGATION_MODE,
	];

	/** Configuration edited in this request (Reorganize), or null. */
	private static ?string $editing = null;

	/** Raw reads of the site's own options (no configuration served). */
	private static bool $bypass = false;

	/** @var string|false|null Resolved slug for the current user (false = none). */
	private static $active = null;

	/** Hooked once: a second capture_edit would store the old value back. */
	private static bool $booted = false;

	public function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		foreach ( self::SERVED as $key => $option ) {
			// Priority 1, before a profile pinned in code (5): the pin is the site's
			// setup, a configuration shapes its own roles over it.
			add_filter( 'pre_option_' . $option, static fn( $pre ) => self::serve( $pre, $key ), 1 );
		}
		// While Reorganize edits a configuration, its saves go to it.
		foreach ( [ 'navigation', 'disabled_screens' ] as $key ) {
			add_filter(
				'pre_update_option_' . self::SERVED[ $key ],
				static fn( $value, $old ) => self::capture_edit( $key, $value, $old ),
				10,
				2
			);
		}
		// Another user, another configuration (user switching, cron, tests).
		add_action( 'set_current_user', [ self::class, 'flush' ] );
		add_action( 'init', [ self::class, 'detect_editing' ], 1 );
		add_action( 'rest_api_init', [ self::class, 'detect_editing' ], 1 );
		add_action( 'admin_post_doubleyoupi_configuration_save', [ self::class, 'handle_save' ] );
		add_action( 'admin_post_doubleyoupi_configuration_delete', [ self::class, 'handle_delete' ] );
	}

	// -------------------------------------------------------------------------
	// Storage
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{slug:string, name:string, navigation:array<string,mixed>, disabled_screens:string[], navigation_mode:string, disposition:string}>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION_ITEMS, [] );
		$raw = is_array( $raw ) ? $raw : [];
		$out = [];
		foreach ( $raw as $slug => $item ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! is_array( $item ) ) {
				continue;
			}
			$out[ $slug ] = self::normalize( $slug, $item );
		}
		return $out;
	}

	public static function get( string $slug ): ?array {
		$all = self::all();
		return $all[ sanitize_key( $slug ) ] ?? null;
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array{slug:string, name:string, navigation:array<string,mixed>, disabled_screens:string[], navigation_mode:string, disposition:string}
	 */
	private static function normalize( string $slug, array $item ): array {
		return [
			'slug'             => $slug,
			'name'             => sanitize_text_field( (string) ( $item['name'] ?? $slug ) ),
			'navigation'       => is_array( $item['navigation'] ?? null ) ? $item['navigation'] : [],
			'disabled_screens' => array_values( array_map( 'strval', is_array( $item['disabled_screens'] ?? null ) ? $item['disabled_screens'] : [] ) ),
			'navigation_mode'  => Settings::sanitize_navigation_mode( (string) ( $item['navigation_mode'] ?? 'reimagined' ) ),
			'disposition'      => sanitize_key( (string) ( $item['disposition'] ?? '' ) ),
		];
	}

	/** @param array<string, array<string,mixed>> $all */
	private static function persist( array $all ): void {
		update_option( self::OPTION_ITEMS, $all, false );
		self::$active = null;
	}

	/**
	 * Saves the site's current setup as a new configuration, or copies one.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create( string $name, string $from = '' ) {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return new \WP_Error( 'invalid_name', __( 'A configuration needs a name.', 'doubleyoupi-refined-admin-dashboard' ) );
		}
		$all = self::all();
		if ( count( $all ) >= self::MAX ) {
			return new \WP_Error( 'too_many', __( 'Maximum number of configurations reached.', 'doubleyoupi-refined-admin-dashboard' ) );
		}
		$source = '' !== $from ? self::get( $from ) : null;
		if ( null === $source ) {
			$source = self::site_setup();
		}
		$base = sanitize_title( $name );
		$base = '' !== $base ? 'dyc-' . $base : 'dyc-config';
		$slug = $base;
		for ( $i = 2; isset( $all[ $slug ] ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		$all[ $slug ] = self::normalize( $slug, array_merge( $source, [ 'name' => $name ] ) );
		self::persist( $all );
		return $all[ $slug ];
	}

	/** The site's own setup, read without any configuration served. */
	public static function site_setup(): array {
		self::$bypass = true;
		NavigationOverrides::flush_cache();
		$setup = [
			'navigation'       => NavigationOverrides::get(),
			'disabled_screens' => ScreenDisabler::get_disabled(),
			'navigation_mode'  => Settings::instance()->navigation_mode(),
			'disposition'      => '',
		];
		self::$bypass = false;
		NavigationOverrides::flush_cache();
		return $setup;
	}

	/**
	 * Updates name, navigation mode and dashboard disposition.
	 *
	 * @param array<string,mixed> $fields
	 */
	public static function update( string $slug, array $fields ): bool {
		$all  = self::all();
		$slug = sanitize_key( $slug );
		if ( ! isset( $all[ $slug ] ) ) {
			return false;
		}
		$item = $all[ $slug ];
		if ( isset( $fields['name'] ) && '' !== trim( (string) $fields['name'] ) ) {
			$item['name'] = (string) $fields['name'];
		}
		if ( isset( $fields['navigation_mode'] ) ) {
			$item['navigation_mode'] = (string) $fields['navigation_mode'];
		}
		if ( array_key_exists( 'disposition', $fields ) ) {
			$d                   = sanitize_key( (string) $fields['disposition'] );
			$item['disposition'] = ( '' === $d || null !== Dispositions::layout_for_slug( $d ) ) ? $d : '';
		}
		$all[ $slug ] = self::normalize( $slug, $item );
		self::persist( $all );
		self::sync_dashboard( $slug );
		return true;
	}

	public static function delete( string $slug ): bool {
		$all  = self::all();
		$slug = sanitize_key( $slug );
		if ( ! isset( $all[ $slug ] ) ) {
			return false;
		}
		unset( $all[ $slug ] );
		self::persist( $all );
		$roles = array_filter( self::roles_map(), static fn( $s ) => $s !== $slug );
		update_option( self::OPTION_ROLES, $roles, false );
		return true;
	}

	/**
	 * Agency kit (profile version 3): every configuration and its roles.
	 *
	 * @return array{items: array<int, array<string,mixed>>, roles: array<string,string>}
	 */
	public static function export(): array {
		return [ 'items' => array_values( self::all() ), 'roles' => self::roles_map() ];
	}

	/**
	 * Replaces the configurations with those of a profile, each value through
	 * the sanitizers of the options it stands for.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function import( array $data ): void {
		$clean = self::clean( $data );
		self::persist( $clean['items'] );
		update_option( self::OPTION_ROLES, $clean['roles'], false );
	}

	/**
	 * The configurations of a profile as stored options, each value through
	 * the sanitizers of the options it stands for. Used by the import and by
	 * a profile pinned in code (Profile::late_value).
	 *
	 * @param array<string,mixed> $data
	 * @return array{items: array<string, array<string,mixed>>, roles: array<string,string>}
	 */
	public static function clean( array $data ): array {
		$all = [];
		foreach ( array_slice( is_array( $data['items'] ?? null ) ? $data['items'] : [], 0, self::MAX ) as $item ) {
			$slug = is_array( $item ) ? sanitize_key( (string) ( $item['slug'] ?? '' ) ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$item['navigation']       = NavigationOverrides::sanitize( is_array( $item['navigation'] ?? null ) ? $item['navigation'] : [] );
			$item['disabled_screens'] = array_values( array_filter( array_map(
				static fn( $sig ) => ScreenDisabler::sanitize_signature( (string) $sig ),
				is_array( $item['disabled_screens'] ?? null ) ? $item['disabled_screens'] : []
			) ) );
			$disposition         = sanitize_key( (string) ( $item['disposition'] ?? '' ) );
			$item['disposition'] = ( '' !== $disposition && null !== Dispositions::layout_for_slug( $disposition ) ) ? $disposition : '';
			$all[ $slug ]        = self::normalize( $slug, $item );
		}
		$valid = array_keys( wp_roles()->get_names() );
		$roles = [];
		foreach ( is_array( $data['roles'] ?? null ) ? $data['roles'] : [] as $role => $slug ) {
			$role = sanitize_key( (string) $role );
			$slug = sanitize_key( (string) $slug );
			if ( in_array( $role, $valid, true ) && isset( $all[ $slug ] ) ) {
				$roles[ $role ] = $slug;
			}
		}
		return [ 'items' => $all, 'roles' => $roles ];
	}

	// -------------------------------------------------------------------------
	// Roles
	// -------------------------------------------------------------------------

	/** @return array<string,string> role => configuration slug. */
	public static function roles_map(): array {
		$raw   = get_option( self::OPTION_ROLES, [] );
		$raw   = is_array( $raw ) ? $raw : [];
		$items = self::all();
		$out   = [];
		foreach ( $raw as $role => $slug ) {
			$role = sanitize_key( (string) $role );
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $role && isset( $items[ $slug ] ) ) {
				$out[ $role ] = $slug;
			}
		}
		return $out;
	}

	/** @return string[] Roles assigned to a configuration. */
	public static function roles_for( string $slug ): array {
		return array_keys( array_filter( self::roles_map(), static fn( $s ) => $s === $slug ) );
	}

	public static function for_role( string $role ): ?string {
		return self::roles_map()[ sanitize_key( $role ) ] ?? null;
	}

	/**
	 * Gives a configuration exactly these roles (a role has at most one).
	 *
	 * @param string[] $roles
	 */
	public static function assign( string $slug, array $roles ): void {
		$slug  = sanitize_key( $slug );
		$valid = array_keys( wp_roles()->get_names() );
		$map   = array_filter( self::roles_map(), static fn( $s ) => $s !== $slug );
		foreach ( $roles as $role ) {
			$role = sanitize_key( (string) $role );
			if ( in_array( $role, $valid, true ) ) {
				$map[ $role ] = $slug;
			}
		}
		update_option( self::OPTION_ROLES, $map, false );
		self::$active = null;
		self::sync_dashboard( $slug );
	}

	/**
	 * The configuration's dashboard disposition becomes its roles' dashboard
	 * (the same assignment as the dashboard's own « Assign to roles »).
	 */
	private static function sync_dashboard( string $slug ): void {
		$item = self::get( $slug );
		if ( null === $item || '' === $item['disposition'] || Profile::is_locked( RoleAssignments::OPTION_KEY ) ) {
			return; // no disposition, or the assignments are pinned in code
		}
		$map = RoleAssignments::all();
		foreach ( self::roles_for( $slug ) as $role ) {
			$map[ $role ] = $item['disposition'];
		}
		update_option( RoleAssignments::OPTION_KEY, (string) wp_json_encode( $map ), false );
	}

	// -------------------------------------------------------------------------
	// Resolution and serving
	// -------------------------------------------------------------------------

	/** Configuration for the current user, or null for the site's setup. */
	public static function active(): ?array {
		// Read before WordPress knows the user (plugins loading): the site's
		// setup, and nothing cached.
		if ( ! did_action( 'init' ) && null === self::$editing ) {
			return null;
		}
		if ( null === self::$active ) {
			self::$active = self::resolve() ?? false;
		}
		return false === self::$active ? null : self::get( (string) self::$active );
	}

	private static function resolve(): ?string {
		if ( null !== self::$editing ) {
			return self::$editing;
		}
		$preview = Preview::role();
		if ( null !== $preview ) {
			return self::for_role( $preview );
		}
		if ( ! is_user_logged_in() || current_user_can( Access::CAP ) ) {
			return null;
		}
		$user = wp_get_current_user();
		foreach ( (array) $user->roles as $role ) {
			$slug = self::for_role( (string) $role );
			if ( null !== $slug ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * @param mixed  $pre
	 * @return mixed
	 */
	private static function serve( $pre, string $key ) {
		if ( false !== $pre || self::$bypass ) {
			return $pre; // a pinned profile wins; raw site reads pass through
		}
		$item = self::active();
		if ( null === $item ) {
			return $pre;
		}
		switch ( $key ) {
			case 'navigation':
				return (string) wp_json_encode( $item['navigation'] );
			case 'disabled_screens':
				return (string) wp_json_encode( $item['disabled_screens'] );
			default:
				return $item['navigation_mode'];
		}
	}

	/**
	 * Reorganize editing a configuration: the save goes to the configuration,
	 * and the site option is left untouched.
	 *
	 * @param mixed $value
	 * @param mixed $old
	 * @return mixed
	 */
	private static function capture_edit( string $key, $value, $old ) {
		if ( null === self::$editing ) {
			return $value;
		}
		$all = self::all();
		if ( ! isset( $all[ self::$editing ] ) ) {
			return $value;
		}
		$decoded                         = is_string( $value ) ? json_decode( $value, true ) : $value;
		$all[ self::$editing ][ $key ]   = is_array( $decoded ) ? $decoded : [];
		self::persist( $all );
		return $old; // unchanged: WordPress skips the write
	}

	/** Reads the configuration being edited from the request (managers only). */
	public static function detect_editing(): void {
		$slug = isset( $_GET[ self::EDIT_PARAM ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::EDIT_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects which configuration Reorganize shows; writes are nonce- and capability-checked by their own handlers.
		if ( '' === $slug || ! current_user_can( Access::CAP ) || null === self::get( $slug ) || self::pinned() ) {
			return;
		}
		self::$editing = $slug;
		self::$active  = null;
		NavigationOverrides::flush_cache();
	}

	/** Forgets the configuration resolved for the current user. */
	public static function flush(): void {
		self::$active = null;
		NavigationOverrides::flush_cache();
	}

	/**
	 * Reorganize's reset while a configuration is edited: empties that part of
	 * the configuration. False when no configuration is edited.
	 */
	public static function reset_edited( string $key ): bool {
		if ( null === self::$editing || ! in_array( $key, [ 'navigation', 'disabled_screens' ], true ) ) {
			return false;
		}
		$all = self::all();
		if ( isset( $all[ self::$editing ] ) ) {
			$all[ self::$editing ][ $key ] = [];
			self::persist( $all );
			NavigationOverrides::flush_cache();
		}
		return true;
	}

	/** Are the configurations pinned in code (DOUBLEYOUPI_ADMIN_PROFILE)? */
	public static function pinned(): bool {
		return Profile::is_locked( self::OPTION_ITEMS );
	}

	public static function editing(): ?string {
		return self::$editing;
	}

	// -------------------------------------------------------------------------
	// Settings forms (admin-post, work without JavaScript)
	// -------------------------------------------------------------------------

	public static function handle_save(): void {
		if ( ! current_user_can( Access::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'doubleyoupi-refined-admin-dashboard' ), 403 );
		}
		check_admin_referer( 'doubleyoupi_configuration' );
		if ( self::pinned() ) {
			wp_safe_redirect( add_query_arg( 'doubleyoupi_config', 'pinned', admin_url( 'admin.php?page=doubleyoupi-admin#dy-configurations' ) ) );
			exit;
		}
		$back = admin_url( 'admin.php?page=doubleyoupi-admin#dy-configurations' );
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( (string) $_POST['slug'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';

		if ( '' === $slug ) {
			$from    = isset( $_POST['from'] ) ? sanitize_key( wp_unslash( (string) $_POST['from'] ) ) : '';
			$created = self::create( $name, $from );
			$code    = is_wp_error( $created ) ? $created->get_error_code() : 'created';
			wp_safe_redirect( add_query_arg( 'doubleyoupi_config', $code, $back ) );
			exit;
		}

		self::update( $slug, [
			'name'            => $name,
			'navigation_mode' => isset( $_POST['navigation_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['navigation_mode'] ) ) : 'reimagined',
			'disposition'     => isset( $_POST['disposition'] ) ? sanitize_key( wp_unslash( (string) $_POST['disposition'] ) ) : '',
		] );
		$roles = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['roles'] ) ) : [];
		self::assign( $slug, $roles );
		wp_safe_redirect( add_query_arg( 'doubleyoupi_config', 'saved', $back ) );
		exit;
	}

	public static function handle_delete(): void {
		if ( ! current_user_can( Access::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'doubleyoupi-refined-admin-dashboard' ), 403 );
		}
		check_admin_referer( 'doubleyoupi_configuration' );
		if ( self::pinned() ) {
			wp_safe_redirect( add_query_arg( 'doubleyoupi_config', 'pinned', admin_url( 'admin.php?page=doubleyoupi-admin#dy-configurations' ) ) );
			exit;
		}
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( (string) $_POST['slug'] ) ) : '';
		self::delete( $slug );
		wp_safe_redirect( add_query_arg( 'doubleyoupi_config', 'deleted', admin_url( 'admin.php?page=doubleyoupi-admin#dy-configurations' ) ) );
		exit;
	}
}

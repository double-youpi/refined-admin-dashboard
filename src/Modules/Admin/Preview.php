<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Admin\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * « View as… »: an administrator previews the admin as a role sees it (RAD
 * 1.1.0, 11/11/2026), without switching accounts.
 *
 * While a role is previewed (kept in the administrator's own meta until they
 * leave), RAD shows that role's configuration (Configurations), its dashboard
 * disposition, the menu entries its capabilities allow, and the notices it
 * would get. A banner says so and offers to leave.
 *
 * What it is not: the administrator's real rights never change. WordPress
 * itself, and other plugins, still treat them as an administrator; a page
 * the role cannot open still opens. The preview shows what RAD shapes.
 */
final class Preview {

	public const META = 'doubleyoupi_preview_role';

	public function boot(): void {
		add_action( 'admin_post_doubleyoupi_preview_start', [ self::class, 'handle_start' ] );
		add_action( 'admin_post_doubleyoupi_preview_stop', [ self::class, 'handle_stop' ] );
		add_action( 'in_admin_header', [ self::class, 'render_banner' ], 1 );
		add_filter( 'admin_body_class', [ self::class, 'body_class' ] );
	}

	/** The role being previewed by the current user, or null. */
	public static function role(): ?string {
		if ( ! function_exists( 'wp_get_current_user' ) || ! did_action( 'init' ) ) {
			return null;
		}
		$user = get_current_user_id();
		if ( ! $user ) {
			return null;
		}
		$role = (string) get_user_meta( $user, self::META, true );
		if ( '' === $role || ! get_role( $role ) || ! current_user_can( Access::CAP ) ) {
			return null;
		}
		return $role;
	}

	/**
	 * Whether the current user can, in the preview: the previewed role's
	 * capability, else the user's own. Used where RAD decides what the menu shows.
	 */
	public static function can( string $cap ): bool {
		$role = self::role();
		if ( null === $role ) {
			return current_user_can( $cap );
		}
		$object = get_role( $role );
		return $object instanceof \WP_Role && $object->has_cap( $cap );
	}

	/** @param string|mixed $classes */
	public static function body_class( $classes ): string {
		$classes = is_string( $classes ) ? $classes : '';
		return null !== self::role() ? $classes . ' dy-previewing' : $classes;
	}

	public static function start_url( string $role ): string {
		return wp_nonce_url(
			add_query_arg( [ 'action' => 'doubleyoupi_preview_start', 'role' => $role ], admin_url( 'admin-post.php' ) ),
			'doubleyoupi_preview'
		);
	}

	public static function handle_start(): void {
		if ( ! current_user_can( Access::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'doubleyoupi-refined-admin-dashboard' ), 403 );
		}
		check_admin_referer( 'doubleyoupi_preview' );
		$role = isset( $_REQUEST['role'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['role'] ) ) : '';
		if ( '' !== $role && get_role( $role ) ) {
			update_user_meta( get_current_user_id(), self::META, $role );
		}
		wp_safe_redirect( admin_url() );
		exit;
	}

	public static function handle_stop(): void {
		check_admin_referer( 'doubleyoupi_preview' );
		delete_user_meta( get_current_user_id(), self::META );
		wp_safe_redirect( admin_url( 'admin.php?page=doubleyoupi-admin#dy-configurations' ) );
		exit;
	}

	public static function render_banner(): void {
		$role = self::role();
		if ( null === $role ) {
			return;
		}
		$names = wp_roles()->get_names();
		$label = translate_user_role( (string) ( $names[ $role ] ?? $role ) );
		$stop  = wp_nonce_url( admin_url( 'admin-post.php?action=doubleyoupi_preview_stop' ), 'doubleyoupi_preview' );
		printf(
			'<div class="dy-preview-banner" role="status"><span class="dy-preview-banner__text">%1$s</span><a class="dy-preview-banner__stop" href="%2$s">%3$s</a></div>',
			esc_html(
				sprintf(
					/* translators: %s: role name. */
					__( 'Preview: the admin as “%s” sees it. Your rights do not change.', 'doubleyoupi-refined-admin-dashboard' ),
					$label
				)
			),
			esc_url( $stop ),
			esc_html__( 'Leave the preview', 'doubleyoupi-refined-admin-dashboard' )
		);
	}
}

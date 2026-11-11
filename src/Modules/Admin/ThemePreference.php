<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The light or dark theme follows the account, not only the browser.
 *
 * The choice lived in the browser alone (`localStorage.dy-admin-theme`, dark
 * by default): a cleared browser, a private window, another browser or
 * another computer brought the dark theme back at every sign-in (Chris Green,
 * 09/11/2026). It is now kept in the user's meta too:
 *   - the server puts `dy-theme-light` on <body> itself, so the page is drawn
 *     in the right theme from the first paint;
 *   - the pre-paint script (Brand.php) and admin.js take the account's value
 *     first and copy it to the browser, which stays a cache (and keeps
 *     serving the sign-in screen, where no account is known yet);
 *   - the toggle saves the choice to the account; a choice made before this
 *     version, found only in the browser, is saved once.
 */
final class ThemePreference {

	public const META = 'doubleyoupi_admin_theme';

	public function boot(): void {
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/** The current user's saved theme: 'light', 'dark', or '' when none. */
	public static function current(): string {
		$user = get_current_user_id();
		if ( ! $user ) {
			return '';
		}
		$theme = (string) get_user_meta( $user, self::META, true );
		return in_array( $theme, [ 'light', 'dark' ], true ) ? $theme : '';
	}

	/** @param string|mixed $classes */
	public function body_class( $classes ): string {
		$classes = is_string( $classes ) ? $classes : '';
		return 'light' === self::current() ? $classes . ' dy-theme-light' : $classes;
	}

	/** Settings handed to admin.js. */
	public static function script_config(): array {
		return [
			'theme' => self::current(),
			'url'   => esc_url_raw( rest_url( 'doubleyoupi/v1/admin/theme' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		];
	}

	public function register_rest_routes(): void {
		register_rest_route(
			'doubleyoupi/v1',
			'/admin/theme',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save' ],
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'args'                => [
					'theme' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'light', 'dark' ],
					],
				],
			]
		);
	}

	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		update_user_meta( get_current_user_id(), self::META, (string) $request->get_param( 'theme' ) );
		return new \WP_REST_Response( [ 'theme' => self::current() ], 200 );
	}
}

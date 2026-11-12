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

	/** User meta: the account has seen the hint pointing at the theme toggle. */
	public const HINT_META = 'doubleyoupi_admin_theme_hint_seen';

	/**
	 * The theme known on the server before the page is drawn: the account's
	 * choice, else the site's default when it is fixed. With the default on
	 * 'system', '' here: the browser decides (Brand.php, admin.js).
	 */
	public static function initial(): string {
		$own = self::current();
		if ( '' !== $own ) {
			return $own;
		}
		$default = Settings::default_theme();
		return 'system' === $default ? '' : $default;
	}

	/** @param string|mixed $classes */
	public function body_class( $classes ): string {
		$classes = is_string( $classes ) ? $classes : '';
		return 'light' === self::initial() ? $classes . ' dy-theme-light' : $classes;
	}

	/**
	 * Show the hint pointing at the theme toggle? Once per account, and only
	 * to someone who has not picked a theme yet.
	 */
	public static function show_hint(): bool {
		$user = get_current_user_id();
		return $user && '' === self::current() && ! get_user_meta( $user, self::HINT_META, true );
	}

	/** Settings handed to admin.js. */
	public static function script_config(): array {
		return [
			'theme'   => self::current(),
			'default' => Settings::default_theme(),
			'hint'    => self::show_hint(),
			'hintText' => __( 'Light or dark: it’s here. Your choice stays on your account.', 'doubleyoupi-refined-admin-dashboard' ),
			'hintOk'  => __( 'Got it', 'doubleyoupi-refined-admin-dashboard' ),
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
						'type' => 'string',
						'enum' => [ 'light', 'dark' ],
					],
					// The hint pointing at the toggle was dismissed.
					'hint'  => [
						'type' => 'boolean',
					],
				],
			]
		);
	}

	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		$user  = get_current_user_id();
		$theme = (string) $request->get_param( 'theme' );
		if ( in_array( $theme, [ 'light', 'dark' ], true ) ) {
			update_user_meta( $user, self::META, $theme );
			update_user_meta( $user, self::HINT_META, 1 ); // they found the toggle
		}
		if ( $request->get_param( 'hint' ) ) {
			update_user_meta( $user, self::HINT_META, 1 );
		}
		return new \WP_REST_Response( [ 'theme' => self::current() ], 200 );
	}
}

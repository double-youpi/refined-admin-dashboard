<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hide login — moves the login URL to a custom slug and returns a 404
 * for `wp-login.php` to logged-out visitors.
 *
 * This is the highest lockout risk item in the audit. It is therefore shipped
 * with a complete SAFETY NET (manifesto: "never trap the user"):
 *
 *   1. Slug field exposed in the toggle card (no hidden setting).
 *   2. Automatic email of the new URL to the admin on activation or slug change.
 *   3. Recovery constant `DOUBLEYOUPI_HIDE_LOGIN_DISABLE`: if defined in
 *      wp-config.php, the feature disables itself and the default login is
 *      restored (recoverable via SFTP, without admin or DB access).
 *   4. Blocked on multisite (network semantics are too fragile).
 *   5. As long as no VALID slug is defined, apply() wires NOTHING — no risk
 *      of accidentally cutting off access.
 *
 * Mechanism (modelled on WPS Hide Login, proven):
 *   - URL filters (`site_url`, `login_url`, `wp_redirect`…): any URL
 *     containing `wp-login.php` is rewritten to `/{slug}/` (password reset
 *     emails, the login form, auth redirects all follow).
 *   - `wp_loaded`: if the request targets the secret slug we serve wp-login.php;
 *     if it targets `wp-login.php` directly and the user is logged out, 404.
 *     admin-ajax / cron / REST / the postpass action remain accessible.
 *
 * Locations: the secret slug lives under home_url() (the public site), while
 * wp-login.php and wp-admin live under site_url() / admin_url(), which differ
 * when WordPress sits in its own subdirectory. Every location is therefore
 * read from those functions, never written as a path.
 */
final class HideLogin extends AbstractFeature {

	private const HIDE_LOGIN_FIELD_GUARD_JS = <<<'JS'
(function () {
	var f = document.querySelector('.dy-toolkit__card[data-feature="hide_login"] .dy-toolkit__field');
	if ( f ) { f.addEventListener('click', function (e) { e.stopPropagation(); }); }
})();
JS;


	/** Option storing the login slug. */
	public const SLUG_OPTION = 'doubleyoupi_toolkit_hide_login_slug';

	/** Name of the POST field (read by on_save via the toolkit form's $_POST). */
	public const SLUG_FIELD = 'doubleyoupi_toolkit_hide_login_slug';

	/** wp-config recovery constant that disables the feature. */
	public const RECOVERY_CONSTANT = 'DOUBLEYOUPI_HIDE_LOGIN_DISABLE';

	/** Reserved structural slugs. */
	private const RESERVED = [ 'wp-admin', 'wp-login', 'admin', 'dashboard', 'wp-content', 'wp-includes', 'wp-json' ];

	public function id(): string {
		return 'hide_login';
	}

	public function label(): string {
		return __( 'Hide login', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function checkbox_label(): string {
		return __( 'Move the login page to a custom URL', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Moves wp-login.php to a custom slug and returns a 404 on the default login for logged-out visitors. Emails you the new URL, and a wp-config recovery constant prevents lockout. Replaces WPS Hide Login.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Key — access / login.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<circle cx="7.5" cy="15.5" r="4.5"/>'
			. '<path d="M10.5 12.5L20 3"/>'
			. '<path d="M16 7l3 3M14 9l2 2"/>'
			. '</svg>';
	}

	public function conflict_plugin_candidates(): array {
		return [
			[ 'file' => 'wps-hide-login/wps-hide-login.php',     'name' => 'WPS Hide Login' ],
			[ 'file' => 'hide-my-wp-ghost/index.php',            'name' => 'Hide My WP Ghost' ],
			[ 'file' => 'better-wp-security/better-wp-security.php', 'name' => 'Solid Security' ],
		];
	}

	/**
	 * Safety net blocking conditions (greys out the toggle).
	 * NB: an empty slug does NOT block here (otherwise the slug field would be hidden) —
	 * apply() is the one that no-ops as long as the slug is not valid.
	 */
	public function environment_block(): ?string {
		if ( defined( self::RECOVERY_CONSTANT ) && constant( self::RECOVERY_CONSTANT ) ) {
			return __( 'Recovery mode is active (DOUBLEYOUPI_HIDE_LOGIN_DISABLE in wp-config.php): the default login is restored.', 'doubleyoupi-refined-admin-dashboard' );
		}
		if ( is_multisite() ) {
			return __( 'Hide login is not supported on multisite networks.', 'doubleyoupi-refined-admin-dashboard' );
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Additional settings in the card (slug field)
	// -------------------------------------------------------------------------

	public function extra_settings_html(): string {
		$slug = self::current_slug();
		$url  = $slug !== '' ? home_url( '/' . $slug . '/' ) : '';

		ob_start();
		?>
		<div class="dy-toolkit__field">
			<span class="dy-toolkit__field-label"><?php esc_html_e( 'Login URL slug', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<input type="text"
			       name="<?php echo esc_attr( self::SLUG_FIELD ); ?>"
			       value="<?php echo esc_attr( $slug ); ?>"
			       class="regular-text"
			       autocomplete="off" spellcheck="false"
			       placeholder="<?php esc_attr_e( 'e.g. my-secret-login', 'doubleyoupi-refined-admin-dashboard' ); ?>" />
			<?php if ( $url !== '' ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: the resulting login URL */
						esc_html__( 'Your login page: %s', 'doubleyoupi-refined-admin-dashboard' ),
						'<code>' . esc_html( $url ) . '</code>'
					);
					?>
				</p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Set a slug to activate: the feature stays off until a valid slug is saved.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php endif; ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: the wp-config recovery line */
					esc_html__( 'Locked out? Add %s to wp-config.php to restore the default login.', 'doubleyoupi-refined-admin-dashboard' ),
					'<code>define( \'DOUBLEYOUPI_HIDE_LOGIN_DISABLE\', true );</code>'
				);
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The entire card is a label: without this, clicking the slug field or its
	 * labels would toggle Hide login. We stop propagation at the field level so
	 * the event never reaches the parent label.
	 */
	public function extra_settings_script(): string {
		return self::HIDE_LOGIN_FIELD_GUARD_JS;
	}

	/**
	 * Persists the slug and notifies the admin on transition (activation or
	 * slug change while the feature is active).
	 *
	 * @param array<string,mixed> $post
	 */
	public function on_save( array $post, bool $was_enabled, bool $now_enabled ): void {
		$raw  = (string) ( $post[ self::SLUG_FIELD ] ?? '' ); // Already unslashed and sanitized by ToolsPage::handle_form().
		$slug = self::sanitize_slug( $raw );

		$previous = self::current_slug();
		update_option( self::SLUG_OPTION, $slug, false );

		if ( $now_enabled && $slug !== '' ) {
			$newly_enabled = ! $was_enabled;
			$slug_changed  = $slug !== $previous;
			if ( $newly_enabled || $slug_changed ) {
				self::notify_admin( $slug );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Slug: validation (pure, testable)
	// -------------------------------------------------------------------------

	public static function current_slug(): string {
		return self::sanitize_slug( (string) get_option( self::SLUG_OPTION, '' ) );
	}

	public static function sanitize_slug( string $raw ): string {
		$slug = sanitize_title( $raw );
		if ( $slug === '' || in_array( $slug, self::RESERVED, true ) ) {
			return '';
		}
		return $slug;
	}

	public static function is_valid_slug( string $slug ): bool {
		return $slug !== '' && self::sanitize_slug( $slug ) === $slug;
	}

	// -------------------------------------------------------------------------
	// URL rewriting (pure, testable)
	// -------------------------------------------------------------------------

	/**
	 * Replaces a WordPress login URL with the slug URL, preserving the
	 * query string (redirect_to, action, key…). No-op if the URL does not
	 * contain wp-login.php or if the slug is empty.
	 */
	public static function swap_login_in_url( string $url, string $slug ): string {
		if ( $slug === '' || strpos( $url, 'wp-login.php' ) === false ) {
			return $url;
		}
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$base  = home_url( '/' . $slug . '/' );
		return $query !== '' ? $base . '?' . $query : $base;
	}

	/**
	 * Request path (without query string, without leading/trailing slashes),
	 * relative to a potential installation subdirectory.
	 */
	public static function relative_request( string $request_path, string $home_path ): string {
		$request = trim( $request_path, '/' );
		$home    = trim( $home_path, '/' );
		if ( $home !== '' && ( $request === $home || strpos( $request, $home . '/' ) === 0 ) ) {
			$request = ltrim( substr( $request, strlen( $home ) ), '/' );
		}
		return $request;
	}

	public static function is_login_request( string $relative, string $slug ): bool {
		return $slug !== '' && $relative === $slug;
	}

	/**
	 * Path of a URL, without leading or trailing slashes ('' for the root).
	 */
	public static function url_path( string $url ): string {
		return trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
	}

	/**
	 * Does the request target the default login page? Both arguments are
	 * paths (slashes ignored); the login path comes from default_login_url(),
	 * so a WordPress installed in a subdirectory is matched too.
	 */
	public static function is_default_login_request( string $request_path, string $login_path ): bool {
		$login = trim( $login_path, '/' );
		return $login !== '' && trim( $request_path, '/' ) === $login;
	}

	/**
	 * The default login URL as WordPress builds it, without our own rewrite:
	 * our `site_url` filter would turn it into the secret slug.
	 */
	public static function default_login_url(): string {
		$priority = has_filter( 'site_url', [ self::class, 'filter_url' ] );
		if ( false !== $priority ) {
			remove_filter( 'site_url', [ self::class, 'filter_url' ], (int) $priority );
		}
		$url = site_url( 'wp-login.php', 'login' );
		if ( false !== $priority ) {
			add_filter( 'site_url', [ self::class, 'filter_url' ], (int) $priority, 1 );
		}
		return $url;
	}

	// -------------------------------------------------------------------------
	// Wiring + interception
	// -------------------------------------------------------------------------

	protected function apply(): void {
		if ( ! self::is_valid_slug( self::current_slug() ) ) {
			// No valid slug → do not touch anything (anti-lockout).
			return;
		}

		foreach ( [ 'site_url', 'network_site_url', 'wp_redirect', 'login_url', 'logout_url', 'lostpassword_url', 'register_url' ] as $filter ) {
			add_filter( $filter, [ self::class, 'filter_url' ], 10, 1 );
		}

		add_action( 'wp_loaded', [ self::class, 'handle_request' ] );
	}

	/**
	 * Callback for the URL filters.
	 *
	 * @param mixed $url
	 */
	public static function filter_url( $url ): string {
		return self::swap_login_in_url( (string) $url, self::current_slug() );
	}

	/**
	 * Request interceptor (wp_loaded hook — pluggable functions are available,
	 * nothing has been sent yet).
	 */
	public static function handle_request(): void {
		// Machine contexts: never interfere.
		if (
			( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
			( defined( 'DOING_CRON' ) && DOING_CRON ) ||
			( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
			( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ||
			( defined( 'WP_CLI' ) && WP_CLI )
		) {
			return;
		}

		$slug = self::current_slug();
		if ( $slug === '' ) {
			return;
		}

		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$relative  = self::relative_request( self::current_path(), $home_path );

		// 1. Request on the secret slug → serve the login page.
		if ( self::is_login_request( $relative, $slug ) ) {
			$action = self::request_action();
			if ( is_user_logged_in() && $action === '' ) {
				// Already logged in and no action (logout, reset…) → redirect to admin.
				wp_safe_redirect( admin_url() );
				exit;
			}
			self::serve_login();
		}

		$request = self::current_path();

		// 2. Direct access to wp-login.php by a logged-out visitor → 404,
		//    except for legitimate open actions (postpass for password-protected posts).
		if (
			self::is_default_login_request( $request, self::url_path( self::default_login_url() ) )
			&& ! is_user_logged_in()
			&& ! self::is_allowed_logged_out_action()
		) {
			self::deny();
		}

		// 3. Access to wp-admin by a logged-out visitor → 404. We do NOT let
		//    WordPress redirect to wp-login (rewritten to the slug), as that would
		//    REVEAL the secret slug in the URL. Public technical endpoints
		//    (admin-ajax, admin-post) are spared.
		if (
			! is_user_logged_in()
			&& self::is_protected_admin_request(
				$request,
				self::url_path( admin_url() ),
				[ self::url_path( admin_url( 'admin-ajax.php' ) ), self::url_path( admin_url( 'admin-post.php' ) ) ]
			)
		) {
			self::deny();
		}
	}

	/**
	 * Does the request target the admin area, excluding the public technical
	 * endpoints? All arguments are paths (slashes ignored), read from
	 * admin_url() by the caller.
	 *
	 * @param string   $request_path Path of the current request.
	 * @param string   $admin_path   Path of admin_url().
	 * @param string[] $public_paths Paths that must stay open to logged-out
	 *                               visitors (admin-ajax.php, admin-post.php).
	 */
	public static function is_protected_admin_request( string $request_path, string $admin_path, array $public_paths ): bool {
		$request = trim( $request_path, '/' );
		$admin   = trim( $admin_path, '/' );
		if ( $admin === '' || ( $request !== $admin && strpos( $request, $admin . '/' ) !== 0 ) ) {
			return false;
		}
		$public = array_map( static fn( $path ): string => trim( (string) $path, '/' ), $public_paths );
		return ! in_array( $request, $public, true );
	}

	private static function current_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return $path;
	}

	private static function request_action(): string {
		if ( isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( (string) wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return '';
	}

	private static function is_allowed_logged_out_action(): bool {
		$action = self::request_action();
		if ( $action === '' && isset( $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$action = sanitize_key( (string) wp_unslash( $_POST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		// postpass: required for password-protected posts.
		return in_array( $action, [ 'postpass' ], true );
	}

	/**
	 * Serves wp-login.php. Called on wp_loaded: wp-settings is complete, so
	 * the require_once of wp-login.php does not re-run the bootstrap (wp-config
	 * and wp-settings are already loaded via require_once).
	 */
	private static function serve_login(): void {
		// wp-login.php is written for file scope: it reads globals that
		// setup_userdata() and the bootstrap define ($user_login, $error…).
		// Required from inside a method, those names would be undefined locals
		// (PHP 8 warnings printed into the login form). Import them explicitly.
		global $pagenow, $error, $interim_login, $action, $user_login, $user_email, $user_ID, $userdata, $user_identity, $user_level, $wp, $wp_query, $wp_rewrite, $wpdb, $wp_locale, $wp_version;
		$pagenow = 'wp-login.php';
		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	private static function deny(): void {
		// Clean 404 that hides the existence of the endpoint.
		status_header( 404 );
		nocache_headers();
		wp_die(
			esc_html__( 'This page does not exist.', 'doubleyoupi-refined-admin-dashboard' ),
			esc_html__( 'Not found', 'doubleyoupi-refined-admin-dashboard' ),
			[ 'response' => 404 ]
		);
	}

	private static function notify_admin( string $slug ): void {
		$to = (string) get_option( 'admin_email' );
		if ( $to === '' ) {
			return;
		}
		$url  = home_url( '/' . $slug . '/' );
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your WordPress login URL changed', 'doubleyoupi-refined-admin-dashboard' ), $site );

		$message = sprintf(
			/* translators: 1: new login URL, 2: default wp-login URL */
			__(
				"The login URL for your site has changed.\n\nNew login URL: %1\$s\n\nThe default %2\$s now returns a 404 to logged-out visitors. Bookmark the new URL.\n\nIf you ever get locked out, add this line to wp-config.php to restore the default login:\ndefine( 'DOUBLEYOUPI_HIDE_LOGIN_DISABLE', true );",
				'doubleyoupi-refined-admin-dashboard'
			),
			$url,
			self::default_login_url()
		);

		wp_mail( $to, $subject, $message );
	}
}

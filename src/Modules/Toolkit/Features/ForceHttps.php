<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forces HTTPS across the entire site.
 *
 * Replaces Really Simple SSL. Minimal approach:
 *   - 301 redirect via template_redirect/admin_init if the request arrives over HTTP
 *   - Rewrite of internal http→https URLs in front-end content (`the_content`)
 *
 * `siteurl`/`home` in the database are NOT touched (that is destructive and out
 * of scope: the user must do it manually in Settings > General, which is explicit).
 * Our role = handle incoming requests, not migrate the configuration.
 *
 * Defensive skip: if the server already enforces HTTPS (wp_parse_url(home_url) == https
 * AND is_ssl()), nothing is intercepted — no redirect loop.
 */
final class ForceHttps extends AbstractFeature {

	public function id(): string {
		return 'force_https';
	}

	public function label(): string {
		return __( 'Force HTTPS', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function checkbox_label(): string {
		return __( 'Redirect HTTP → HTTPS and rewrite internal links', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( '301 redirect on front and admin, rewriting of http→https URLs in the content. Replaces Really Simple SSL. (The WordPress « Site Address » setting is not modified: do it manually.)', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Closed padlock — security, HTTPS.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<rect x="3" y="11" width="18" height="11" rx="2"/>'
			. '<path d="M7 11V7a5 5 0 0 1 10 0v4"/>'
			. '</svg>';
	}

	public function conflict_plugin_candidates(): array {
		return [
			[ 'file' => 'really-simple-ssl/rlrsssl-really-simple-ssl.php', 'name' => 'Really Simple SSL' ],
			[ 'file' => 'really-simple-ssl-pro/really-simple-ssl-pro.php', 'name' => 'Really Simple SSL Pro' ],
		];
	}

	/**
	 * Refuses activation in a local environment to avoid losing access to the
	 * site (the http→https redirect breaks if the local certificate is not configured).
	 *
	 * Can be overridden via the `doubleyoupi_toolkit_force_https_in_local` filter
	 * for setups with a self-signed certificate already trusted by the browser:
	 *   add_filter( 'doubleyoupi_toolkit_force_https_in_local', '__return_true' );
	 */
	public function environment_block(): ?string {
		if ( ! $this->is_local_environment() ) {
			return null;
		}
		if ( apply_filters( 'doubleyoupi_toolkit_force_https_in_local', false ) ) {
			return null;
		}
		return __(
			'Local environment detected: Force HTTPS is blocked here to avoid breaking access to the site (local certificates are usually not configured). To override: add_filter( \'doubleyoupi_toolkit_force_https_in_local\', \'__return_true\' );',
			'doubleyoupi-refined-admin-dashboard'
		);
	}

	/**
	 * Local environment heuristic. Two combined sources:
	 *   1. WP_ENVIRONMENT_TYPE = 'local' or 'development' (official WP 5.5+ source)
	 *   2. Host pattern: .local, .test, .localhost, private IP (RFC 1918), 127.x
	 *
	 * Combined to cover setups (LocalWP, MAMP, Valet, Docker) that do not all
	 * define WP_ENVIRONMENT_TYPE.
	 *
	 * Public for direct testability (host filtered via pre_option_home).
	 */
	public function is_local_environment(): bool {
		// 1. Official WP source.
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$env = wp_get_environment_type();
			if ( in_array( $env, [ 'local', 'development' ], true ) ) {
				return true;
			}
		}

		// 2. Host heuristic.
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strtolower( $host );
		if ( $host === '' ) {
			return false;
		}
		if ( $host === 'localhost' ) {
			return true;
		}
		if ( str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' ) || str_ends_with( $host, '.localhost' ) ) {
			return true;
		}
		// RFC 1918 private IPs + loopback.
		if ( preg_match( '/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host ) ) {
			return true;
		}
		return false;
	}

	protected function apply(): void {
		add_action( 'template_redirect', [ self::class, 'redirect_to_https' ], 1 );
		add_action( 'admin_init',        [ self::class, 'redirect_to_https' ], 1 );
		add_filter( 'the_content',       [ self::class, 'rewrite_internal_http_urls' ], 999 );
	}

	/**
	 * If the request arrives over HTTP, 301 redirect to HTTPS.
	 *
	 * Does nothing if:
	 *   - WP already sees HTTPS (`is_ssl()` is true)
	 *   - REQUEST_URI is absent (CLI, cron…)
	 */
	public static function redirect_to_https(): void {
		if ( is_ssl() ) {
			return;
		}
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		// The host comes from the site settings, never from the request headers.
		$home = wp_parse_url( home_url() );
		if ( empty( $home['host'] ) ) {
			return;
		}
		$host = $home['host'] . ( empty( $home['port'] ) ? '' : ':' . (int) $home['port'] );
		$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		wp_safe_redirect( 'https://' . $host . $uri, 301 );
		exit;
	}

	/**
	 * Rewrites http://{home_host} URLs to https://{home_host} in the content.
	 *
	 * Strictly limited to the current host to avoid breaking external links that are
	 * intentionally HTTP (rare but legitimate case: e.g. an archive citation).
	 */
	public static function rewrite_internal_http_urls( string $content ): string {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		// Only a well-formed host name is ever written into the content.
		if ( ! is_string( $home_host ) || ! preg_match( '/^[A-Za-z0-9.-]+$/', $home_host ) ) {
			return $content;
		}
		$needle = 'http://' . $home_host;
		if ( strpos( $content, $needle ) === false ) {
			return $content;
		}
		return str_replace( $needle, esc_url( 'https://' . $home_host ), $content );
	}
}

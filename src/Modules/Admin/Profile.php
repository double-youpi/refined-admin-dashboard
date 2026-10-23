<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Admin\Feedback;
use DoubleYoupi\Admin\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profile — the site-level setup of Refined Admin Dashboard as one portable
 * JSON document: export it from a finished site, import it on the next one,
 * or pin it in code so nobody changes it by hand.
 *
 * Scope (deliberately tight): the General settings (accent, logo, per-user
 * mode, navigation tree, Feedback and review requests hidden), the navigation overrides and disabled screens, and
 * the Toolkit toggles. Per-user choices (theme, rail width, table modes) and
 * the dashboard layouts stay out: they belong to people or to a site.
 *
 * Pinning in code: `define( 'DOUBLEYOUPI_ADMIN_PROFILE', '/path/to/profile.json' )`
 * (or an array) in wp-config.php, refined by the filter
 * `doubleyoupi_filter_admin_profile`. Every option the document carries is
 * then served through `pre_option_*`, so the whole plugin reads the pinned
 * value, and the settings page shows those fields locked.
 */
final class Profile {

	public const FORMAT  = 'doubleyoupi-admin-profile';
	public const VERSION = 1;

	public const ACTION_EXPORT = 'doubleyoupi_admin_profile_export';
	public const ACTION_IMPORT = 'doubleyoupi_admin_profile_import';
	public const NONCE         = 'doubleyoupi_admin_profile';

	/** Largest accepted upload (bytes): a profile is a few kilobytes. */
	private const MAX_BYTES = 524288;

	/** Section => key => option name. */
	private const MAP = [
		'settings'   => [
			'accent'          => 'doubleyoupi_admin_accent',
			'accent_to_lines' => 'doubleyoupi_admin_accent_to_lines',
			'logo_url'        => 'doubleyoupi_admin_logo_url',
			'per_user'        => 'doubleyoupi_admin_per_user',
			'navigation_mode' => 'doubleyoupi_admin_navigation_mode',
			'rail_flyout'     => 'doubleyoupi_admin_rail_flyout',
			'hide_feedback'   => Feedback::OPTION_HIDE,
		],
		'navigation' => [
			'overrides'        => NavigationOverrides::OPTION_KEY,
			'disabled_screens' => ScreenDisabler::OPTION_KEY,
		],
	];

	/** @var array<string,mixed>|null|false false = not resolved yet */
	private static $code = false;

	public function boot(): void {
		add_action( 'admin_post_' . self::ACTION_EXPORT, [ $this, 'handle_export' ] );
		add_action( 'admin_post_' . self::ACTION_IMPORT, [ $this, 'handle_import' ] );
		$this->register_locks();
	}

	// -------------------------------------------------------------------------
	// Export
	// -------------------------------------------------------------------------

	/**
	 * Current setup as a portable document.
	 *
	 * @return array<string,mixed>
	 */
	public static function export_payload(): array {
		$settings = Settings::instance();
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$toolkit  = [];
		foreach ( self::toolkit_ids() as $id ) {
			$toolkit[ $id ] = (bool) get_option( 'doubleyoupi_toolkit_' . $id, false );
		}
		return [
			'format'     => self::FORMAT,
			'version'    => self::VERSION,
			'generated'  => gmdate( 'c' ),
			'source'     => $host,
			'settings'   => [
				'accent'          => $settings->accent_color(),
				'accent_to_lines' => $settings->accent_applies_to_lines(),
				'logo_url'        => $settings->logo_url(),
				'per_user'        => UserCustomization::is_per_user_mode(),
				'navigation_mode' => $settings->navigation_mode(),
				'rail_flyout'     => Settings::rail_flyout_mode(),
				'hide_feedback'   => Feedback::hidden(),
			],
			'navigation' => [
				'overrides'        => NavigationOverrides::get(),
				'disabled_screens' => ScreenDisabler::get_disabled(),
			],
			'toolkit'    => $toolkit,
		];
	}

	public function handle_export(): void {
		if ( ! current_user_can( Access::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to export these settings.', 'doubleyoupi-refined-admin-dashboard' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$host = preg_replace( '/[^a-z0-9.-]/i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ?: 'site';
		$name = 'doubleyoupi-admin-profile-' . $host . '-' . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo wp_json_encode( self::export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download built by wp_json_encode.
		exit;
	}

	// -------------------------------------------------------------------------
	// Import
	// -------------------------------------------------------------------------

	public function handle_import(): void {
		if ( ! current_user_can( Access::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to import these settings.', 'doubleyoupi-refined-admin-dashboard' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$back = admin_url( 'admin.php?page=doubleyoupi-admin' );
		$file = $_FILES['doubleyoupi_profile'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below, read via the filesystem API.
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'doubleyoupi_profile', 'nofile', $back ) );
			exit;
		}
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
			wp_safe_redirect( add_query_arg( 'doubleyoupi_profile', 'toolarge', $back ) );
			exit;
		}
		$tmp = (string) $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) ) {
			wp_safe_redirect( add_query_arg( 'doubleyoupi_profile', 'nofile', $back ) );
			exit;
		}
		$raw = (string) file_get_contents( $tmp ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local upload, size-capped.
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			wp_safe_redirect( add_query_arg( 'doubleyoupi_profile', 'invalid', $back ) );
			exit;
		}

		$result = self::import( $payload );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'doubleyoupi_profile', $result->get_error_code(), $back ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( [ 'doubleyoupi_profile' => 'imported', 'n' => count( $result['applied'] ) ], $back ) );
		exit;
	}

	/**
	 * Applies a document through the same sanitizers as the settings forms.
	 *
	 * @param array<string,mixed> $payload
	 * @return array{applied:string[],ignored:string[]}|\WP_Error
	 */
	public static function import( array $payload ) {
		if ( ( $payload['format'] ?? '' ) !== self::FORMAT ) {
			return new \WP_Error( 'invalid', 'Not a Double Youpi admin profile.' );
		}
		if ( (int) ( $payload['version'] ?? 0 ) > self::VERSION ) {
			return new \WP_Error( 'newer', 'Profile written by a newer version.' );
		}
		$applied = [];
		$ignored = [];
		$s       = is_array( $payload['settings'] ?? null ) ? $payload['settings'] : [];
		$set     = Settings::instance();

		if ( array_key_exists( 'accent', $s ) ) {
			$accent = sanitize_hex_color( (string) $s['accent'] );
			if ( $accent ) {
				update_option( 'doubleyoupi_admin_accent', $set->sanitize_accent( $accent ) );
				$applied[] = 'accent';
			} else {
				$ignored[] = 'accent';
			}
		}
		if ( array_key_exists( 'accent_to_lines', $s ) ) {
			update_option( 'doubleyoupi_admin_accent_to_lines', ! empty( $s['accent_to_lines'] ) );
			$applied[] = 'accent_to_lines';
		}
		if ( array_key_exists( 'logo_url', $s ) ) {
			$url = esc_url_raw( (string) $s['logo_url'] );
			update_option( 'doubleyoupi_admin_logo_url', $url );
			// The attachment id belongs to the source site: never carried over.
			update_option( 'doubleyoupi_admin_logo_id', 0 );
			$applied[] = 'logo_url';
		}
		if ( array_key_exists( 'per_user', $s ) ) {
			update_option( UserCustomization::OPTION_PER_USER, ! empty( $s['per_user'] ) );
			$applied[] = 'per_user';
		}
		if ( array_key_exists( 'navigation_mode', $s ) ) {
			update_option( 'doubleyoupi_admin_navigation_mode', Settings::sanitize_navigation_mode( (string) $s['navigation_mode'] ) );
			$applied[] = 'navigation_mode';
		}
		if ( array_key_exists( 'rail_flyout', $s ) ) {
			update_option( 'doubleyoupi_admin_rail_flyout', Settings::sanitize_rail_flyout( (string) $s['rail_flyout'] ) );
			$applied[] = 'rail_flyout';
		}
		if ( array_key_exists( 'hide_feedback', $s ) ) {
			update_option( Feedback::OPTION_HIDE, ! empty( $s['hide_feedback'] ) );
			$applied[] = 'hide_feedback';
		}

		$nav = is_array( $payload['navigation'] ?? null ) ? $payload['navigation'] : [];
		if ( is_array( $nav['overrides'] ?? null ) ) {
			$r = NavigationOverrides::set( $nav['overrides'] );
			if ( is_wp_error( $r ) ) {
				$ignored[] = 'navigation.overrides';
			} else {
				$applied[] = 'navigation.overrides';
			}
		}
		if ( is_array( $nav['disabled_screens'] ?? null ) ) {
			ScreenDisabler::set_disabled( array_map( 'strval', $nav['disabled_screens'] ) );
			$applied[] = 'navigation.disabled_screens';
		}

		$known = self::toolkit_ids();
		foreach ( ( is_array( $payload['toolkit'] ?? null ) ? $payload['toolkit'] : [] ) as $id => $on ) {
			$id = sanitize_key( (string) $id );
			if ( in_array( $id, $known, true ) ) {
				update_option( 'doubleyoupi_toolkit_' . $id, ! empty( $on ) );
				$applied[] = 'toolkit.' . $id;
			} else {
				$ignored[] = 'toolkit.' . $id;
			}
		}

		/**
		 * Fires after a profile has been imported.
		 *
		 * @param array<string,mixed> $payload The document.
		 * @param string[]            $applied Keys applied.
		 */
		do_action( 'doubleyoupi_admin_profile_imported', $payload, $applied );

		return [ 'applied' => $applied, 'ignored' => $ignored ];
	}

	// -------------------------------------------------------------------------
	// Pinned in code
	// -------------------------------------------------------------------------

	/**
	 * Profile pinned by `DOUBLEYOUPI_ADMIN_PROFILE` (array, or path to a JSON
	 * file) and the `doubleyoupi_filter_admin_profile` filter. Null when none.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function from_code(): ?array {
		if ( false !== self::$code ) {
			return self::$code;
		}
		$raw = null;
		if ( defined( 'DOUBLEYOUPI_ADMIN_PROFILE' ) ) {
			$c = DOUBLEYOUPI_ADMIN_PROFILE;
			if ( is_array( $c ) ) {
				$raw = $c;
			} elseif ( is_string( $c ) && '' !== $c && is_readable( $c ) ) {
				$decoded = json_decode( (string) file_get_contents( $c ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file named in wp-config.
				$raw     = is_array( $decoded ) ? $decoded : null;
			}
		}
		/**
		 * Lets code provide or amend the pinned profile.
		 *
		 * @param array<string,mixed>|null $raw The profile, or null.
		 */
		$raw = apply_filters( 'doubleyoupi_filter_admin_profile', $raw );
		self::$code = is_array( $raw ) && ( $raw['format'] ?? self::FORMAT ) === self::FORMAT ? $raw : null;
		return self::$code;
	}

	/** Is this option served by the pinned profile? */
	public static function is_locked( string $option ): bool {
		return array_key_exists( $option, self::locked_values() );
	}

	/**
	 * Option name => stored value, for every key the pinned profile carries.
	 *
	 * @return array<string,mixed>
	 */
	public static function locked_values(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = [];
		$p     = self::from_code();
		if ( null === $p ) {
			return $cache;
		}
		$s = is_array( $p['settings'] ?? null ) ? $p['settings'] : [];
		if ( array_key_exists( 'accent', $s ) ) {
			$hex = sanitize_hex_color( (string) $s['accent'] );
			if ( $hex ) {
				$cache['doubleyoupi_admin_accent'] = $hex;
			}
		}
		if ( array_key_exists( 'accent_to_lines', $s ) ) {
			$cache['doubleyoupi_admin_accent_to_lines'] = ! empty( $s['accent_to_lines'] ) ? '1' : '0';
		}
		if ( array_key_exists( 'logo_url', $s ) ) {
			$cache['doubleyoupi_admin_logo_url'] = esc_url_raw( (string) $s['logo_url'] );
		}
		if ( array_key_exists( 'per_user', $s ) ) {
			$cache[ UserCustomization::OPTION_PER_USER ] = ! empty( $s['per_user'] ) ? '1' : '0';
		}
		if ( array_key_exists( 'navigation_mode', $s ) ) {
			$cache['doubleyoupi_admin_navigation_mode'] = Settings::sanitize_navigation_mode( (string) $s['navigation_mode'] );
		}
		if ( array_key_exists( 'rail_flyout', $s ) ) {
			$cache['doubleyoupi_admin_rail_flyout'] = Settings::sanitize_rail_flyout( (string) $s['rail_flyout'] );
		}
		if ( array_key_exists( 'hide_feedback', $s ) ) {
			$cache[ Feedback::OPTION_HIDE ] = ! empty( $s['hide_feedback'] ) ? '1' : '0';
		}
		$nav = is_array( $p['navigation'] ?? null ) ? $p['navigation'] : [];
		if ( is_array( $nav['overrides'] ?? null ) ) {
			$cache[ NavigationOverrides::OPTION_KEY ] = wp_json_encode( NavigationOverrides::sanitize( $nav['overrides'] ) );
		}
		if ( is_array( $nav['disabled_screens'] ?? null ) ) {
			$clean = array_values( array_filter( array_map( static fn( $v ): string => sanitize_text_field( (string) $v ), $nav['disabled_screens'] ) ) );
			$cache[ ScreenDisabler::OPTION_KEY ] = wp_json_encode( $clean );
		}
		$known = self::toolkit_ids();
		foreach ( ( is_array( $p['toolkit'] ?? null ) ? $p['toolkit'] : [] ) as $id => $on ) {
			$id = sanitize_key( (string) $id );
			if ( in_array( $id, $known, true ) ) {
				$cache[ 'doubleyoupi_toolkit_' . $id ] = ! empty( $on ) ? '1' : '0';
			}
		}
		return $cache;
	}

	/** `pre_option_*` for each pinned key: the whole plugin reads the pin. */
	private function register_locks(): void {
		foreach ( self::locked_values() as $option => $value ) {
			add_filter( 'pre_option_' . $option, static fn() => $value, 5 );
		}
	}

	/** @return string[] Toolkit feature ids known to this build. */
	private static function toolkit_ids(): array {
		if ( ! class_exists( '\\DoubleYoupi\\Modules\\Toolkit\\Settings' ) ) {
			return [];
		}
		$ids = [];
		foreach ( \DoubleYoupi\Modules\Toolkit\Settings::features() as $feature ) {
			if ( is_object( $feature ) && method_exists( $feature, 'id' ) ) {
				$ids[] = (string) $feature->id();
			}
		}
		return $ids;
	}

	/** Human labels of the keys a profile may carry (settings page). */
	public static function scope_labels(): array {
		return [
			__( 'Accent color and logo', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Per-user customization', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Hide Feedback and review requests', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Navigation tree (reimagined or native), section pages on hover, and the Reorganize layout', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Screens left in native styling', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Toolkit toggles', 'doubleyoupi-refined-admin-dashboard' ),
		];
	}
}

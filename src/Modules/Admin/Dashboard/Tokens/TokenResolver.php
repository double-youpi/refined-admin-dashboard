<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Tokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolver for tokens such as {{user.firstname}}, {{site.name}}, etc., used
 * in the text of Heading and Paragraph widgets.
 *
 * Strategy: simple str_replace. No regex, no complex parsing — the token list
 * is closed (see AVAILABLE), no arbitrary expressions are supported.
 * Security: tokens are replaced BEFORE HTML escaping by the calling widget,
 * so if a user has a malicious display_name like script tag, it is caught
 * downstream by wp_kses or esc_html.
 */
final class TokenResolver {

	/**
	 * Closed list of supported tokens. Documented for the help UI and used as
	 * the source of truth for rendering.
	 */
	public const AVAILABLE = [
		'{{user.firstname}}'  => 'First name of the logged-in user',
		'{{user.name}}'       => 'Full name (display_name) of the user',
		'{{site.name}}'       => 'Site name (blogname)',
		'{{site.url}}'        => 'Home URL of the site',
		'{{date.greeting}}'   => 'Hello / Good evening depending on the time',
		'{{date.day}}'        => 'Day of the week + date (e.g. Friday 18 May)',
		'{{date.now}}'        => 'Current time (HH:MM)',
		'{{updates.summary}}' => 'Summary of available updates',
	];

	/**
	 * Replaces tokens in a string. If $user_id is null, uses the current user.
	 * If no user is logged in (CLI / test context), user.* tokens return an
	 * empty string.
	 */
	public static function resolve( string $text, ?int $user_id = null ): string {
		if ( $text === '' ) {
			return '';
		}

		// Short-circuit: if no token is present, skip the work
		// (common case when the user has no tokens in their text).
		if ( strpos( $text, '{{' ) === false ) {
			return $text;
		}

		$values = self::values( $user_id );
		return strtr( $text, $values );
	}

	/**
	 * Builds the token → value map for the given user.
	 *
	 * @return array<string, string>
	 */
	private static function values( ?int $user_id ): array {
		$user_id ??= get_current_user_id();
		$user     = $user_id ? get_userdata( $user_id ) : false;

		return [
			'{{user.firstname}}'  => $user ? self::user_firstname( $user ) : '',
			'{{user.name}}'       => $user ? (string) $user->display_name : '',
			'{{site.name}}'       => (string) get_bloginfo( 'name' ),
			'{{site.url}}'        => (string) home_url(),
			'{{date.greeting}}'   => self::greeting(),
			'{{date.day}}'        => self::day_label(),
			'{{date.now}}'        => self::time_label(),
			'{{updates.summary}}' => self::updates_summary(),
		];
	}

	/**
	 * First name: uses first_name if set, otherwise the first word of display_name.
	 * Falls back to the login if nothing else is usable.
	 *
	 * @param \WP_User $user
	 */
	private static function user_firstname( \WP_User $user ): string {
		$first = (string) ( $user->first_name ?? '' );
		if ( $first !== '' ) {
			return $first;
		}
		$display = trim( (string) $user->display_name );
		if ( $display !== '' ) {
			$parts = preg_split( '/\s+/', $display );
			return $parts[0] ?? $display;
		}
		return (string) $user->user_login;
	}

	/**
	 * "Hello" or "Good evening" based on the current WP time (with timezone).
	 * Morning window: 5:00–18:00.
	 */
	private static function greeting(): string {
		$hour = (int) wp_date( 'G' );
		if ( $hour >= 5 && $hour < 18 ) {
			return __( 'Hello', 'doubleyoupi-refined-admin-dashboard' );
		}
		return __( 'Good evening', 'doubleyoupi-refined-admin-dashboard' );
	}

	private static function day_label(): string {
		// `wp_date` honours the WP locale + timezone.
		return (string) wp_date( 'l j F' );
	}

	private static function time_label(): string {
		return (string) wp_date( 'H:i' );
	}

	/**
	 * Update summary: "Everything is up to date." or "N plugins have updates."
	 * Combines plugins + themes + core into a readable message.
	 */
	private static function updates_summary(): string {
		// If not in admin context, wp_get_update_data may not be loaded — require it.
		if ( ! function_exists( 'wp_get_update_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$data    = wp_get_update_data();
		$plugins = (int) ( $data['counts']['plugins'] ?? 0 );
		$themes  = (int) ( $data['counts']['themes'] ?? 0 );
		$core    = (int) ( $data['counts']['wordpress'] ?? 0 );
		$total   = $plugins + $themes + $core;

		if ( $total === 0 ) {
			return __( 'Everything is up to date.', 'doubleyoupi-refined-admin-dashboard' );
		}

		// Most common case: only plugin updates.
		if ( $plugins > 0 && $themes === 0 && $core === 0 ) {
			return sprintf(
				/* translators: %d: number of plugins with available updates */
				_n( '%d plugin has an update.', '%d plugins have updates.', $plugins, 'doubleyoupi-refined-admin-dashboard' ),
				$plugins
			);
		}

		// Otherwise, generic summary.
		return sprintf(
			/* translators: %d: total number of updates available */
			_n( '%d update is available.', '%d updates are available.', $total, 'doubleyoupi-refined-admin-dashboard' ),
			$total
		);
	}
}

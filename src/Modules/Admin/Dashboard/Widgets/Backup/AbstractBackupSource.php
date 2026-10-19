<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared plumbing for backup sources: activity detection and safe reads.
 */
abstract class AbstractBackupSource implements BackupSource {

	/**
	 * Regular expressions matched against the directory of each active plugin.
	 * A directory rather than a basename: paid editions live in their own
	 * directory (`backwpup-pro`, `duplicator-pro`) and a directory can be
	 * renamed on install (same approach as RML's MediaManagerConflict).
	 *
	 * @return string[]
	 */
	abstract protected function plugin_dirs(): array;

	public function is_active(): bool {
		$active = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}
		foreach ( $active as $basename ) {
			$dir = strtok( (string) $basename, '/' );
			foreach ( $this->plugin_dirs() as $pattern ) {
				if ( is_string( $dir ) && preg_match( $pattern, $dir ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Option read that tolerates any stored type. */
	protected static function option( string $name ): mixed {
		return get_option( $name, null );
	}

	/** Same, for options a plugin keeps network-wide on multisite. */
	protected static function site_option( string $name ): mixed {
		return is_multisite() ? get_site_option( $name, null ) : get_option( $name, null );
	}

	/** A positive integer timestamp in seconds, or 0. Milliseconds are scaled down. */
	protected static function timestamp( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}
		$ts = (int) $value;
		if ( $ts > 100000000000 ) {
			$ts = (int) floor( $ts / 1000 );
		}
		return $ts > 0 ? $ts : 0;
	}

	/** Destination label for a backup kept on the site's own server. */
	protected static function local_label(): string {
		return _x( 'Local', 'backup destination', 'doubleyoupi-refined-admin-dashboard' );
	}

	/** Next run of a WP-Cron hook, 0 if none is scheduled. */
	protected static function next_cron( string $hook, array $args = [] ): int {
		$next = wp_next_scheduled( $hook, $args );
		return is_int( $next ) ? $next : 0;
	}

	/** A positive size in bytes, or null. */
	protected static function bytes( mixed $value ): ?int {
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}
}

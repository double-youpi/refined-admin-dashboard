<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\Sources;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\AbstractBackupSource;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backup Migration (BackupBliss), read from 2.1.7.
 *
 * No backup record in the database: like the plugin itself, the newest
 * `.zip` / `.tar` / `.gz` in `<STORAGE::LOCAL::PATH>/backups` is the last
 * finished backup (a failed one leaves no archive). A `.running` file touched
 * in the last 65 seconds means a backup is under way (its own rule). Its
 * per-backup manifests are not read: they also hold database credentials.
 */
final class BackupMigration extends AbstractBackupSource {

	public function id(): string {
		return 'backup-migration';
	}

	public function label(): string {
		return 'Backup Migration';
	}

	protected function plugin_dirs(): array {
		return [ '#^backup-backup#' ];
	}

	public function admin_url(): string {
		return is_multisite() ? network_admin_url( 'admin.php?page=backup-migration' ) : admin_url( 'admin.php?page=backup-migration' );
	}

	public function last(): ?BackupSnapshot {
		$settings = self::option( 'bmi_settings' );
		if ( is_string( $settings ) ) {
			$settings = json_decode( $settings, true );
		}
		$root = is_array( $settings ) ? (string) ( $settings['STORAGE::LOCAL::PATH'] ?? '' ) : '';
		$dir  = '' !== $root ? trailingslashit( $root ) . 'backups/' : '';
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return null;
		}
		$next = is_array( $settings ) && true === ( $settings['CRON:ENABLED'] ?? false ) ? self::next_cron( 'bmi_do_backup_right_now' ) : 0;

		$running = $dir . '.running';
		if ( is_file( $running ) && (int) filemtime( $running ) >= time() - 65 ) {
			return new BackupSnapshot( 0, BackupSnapshot::STATUS_RUNNING, null, '', $next );
		}

		$newest = '';
		$mtime  = 0;
		// One pattern per extension: GLOB_BRACE is missing on some systems (musl).
		$files = array_merge( (array) glob( $dir . '*.zip' ), (array) glob( $dir . '*.tar' ), (array) glob( $dir . '*.gz' ) );
		foreach ( $files as $file ) {
			$time = is_file( (string) $file ) ? (int) filemtime( (string) $file ) : 0;
			if ( $time > $mtime ) {
				$mtime  = $time;
				$newest = (string) $file;
			}
		}
		$failed = $this->failed_run( $dir, $mtime );
		if ( $failed ) {
			return new BackupSnapshot( $failed, BackupSnapshot::STATUS_FAILED, null, '', $next );
		}
		if ( '' === $newest ) {
			return null;
		}
		return new BackupSnapshot( $mtime, BackupSnapshot::STATUS_SUCCESS, self::bytes( filesize( $newest ) ), self::local_label(), $next );
	}

	/**
	 * A failed run leaves no archive, only its log: `latest.<suffix>.log` ends
	 * with an END-CODE line, #001 for a success and anything else for an
	 * error (#002) or an abort (#003, #004). Seen on a real backup that ran
	 * out of execution time, 19/10/2026. Returns the end time of a failed run
	 * newer than the newest archive, or 0.
	 */
	private function failed_run( string $dir, int $after ): int {
		foreach ( (array) glob( $dir . 'latest.*.log' ) as $log ) {
			$log  = (string) $log;
			$time = is_file( $log ) ? (int) filemtime( $log ) : 0;
			if ( $time <= $after ) {
				continue;
			}
			$size = (int) filesize( $log );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the tail of a local log file of another plugin; WP_Filesystem only reads whole files.
			$tail = (string) file_get_contents( $log, false, null, max( 0, $size - 512 ) );
			if ( preg_match_all( '/\[END-CODE\][^\n]*(#\d{3})/', $tail, $codes ) && '#001' !== end( $codes[1] ) ) {
				return $time;
			}
		}
		return 0;
	}
}

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
		if ( '' === $newest ) {
			return null;
		}
		return new BackupSnapshot( $mtime, BackupSnapshot::STATUS_SUCCESS, self::bytes( filesize( $newest ) ), self::local_label(), $next );
	}
}

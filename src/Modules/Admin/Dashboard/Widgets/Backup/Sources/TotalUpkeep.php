<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\Sources;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\AbstractBackupSource;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Total Upkeep (BoldGrid), read from 1.17.
 *
 * - `boldgrid_backup_latest_backup` (blog option): written only after a
 *   successful archive, so it always describes the last success
 *   (`lastmodunix` = archive mtime, `filesize` in bytes).
 * - Running: `boldgrid_backup_settings['in_progress']` (site option, start
 *   time) while `boldgrid_backup_in_progress_data['success']` is not a bool.
 * - Failed: `success === false` with a `start_time` newer than the last success.
 * - Remote copies: `settings['remote'][id]['enabled']`; next run on the
 *   `boldgrid_backup_wp_cron_backup` hook.
 */
final class TotalUpkeep extends AbstractBackupSource {

	/** Known remote storage ids → product names. */
	private const REMOTES = [
		'ftp'          => 'FTP/SFTP',
		'amazon_s3'    => 'Amazon S3',
		'google_drive' => 'Google Drive',
		'dreamobjects' => 'DreamObjects',
	];

	public function id(): string {
		return 'total-upkeep';
	}

	public function label(): string {
		return 'Total Upkeep';
	}

	protected function plugin_dirs(): array {
		return [ '#^boldgrid-backup#' ];
	}

	public function admin_url(): string {
		return admin_url( 'admin.php?page=boldgrid-backup' );
	}

	public function last(): ?BackupSnapshot {
		$settings = self::site_option( 'boldgrid_backup_settings' );
		$settings = is_array( $settings ) ? $settings : [];
		$progress = self::option( 'boldgrid_backup_in_progress_data' );
		$progress = is_array( $progress ) ? $progress : [];
		$latest   = self::option( 'boldgrid_backup_latest_backup' );
		$latest   = is_array( $latest ) ? $latest : [];
		$next     = self::next_cron( 'boldgrid_backup_wp_cron_backup' );

		$started = self::timestamp( $settings['in_progress'] ?? 0 );
		if ( $started && ! is_bool( $progress['success'] ?? null ) ) {
			return new BackupSnapshot( $started, BackupSnapshot::STATUS_RUNNING, null, '', $next );
		}

		$last_ok   = self::timestamp( $latest['lastmodunix'] ?? 0 ) ?: self::timestamp( $latest['timestamp'] ?? 0 );
		$attempted = self::timestamp( $progress['start_time'] ?? 0 );
		if ( false === ( $progress['success'] ?? null ) && $attempted > $last_ok ) {
			return new BackupSnapshot( $attempted, BackupSnapshot::STATUS_FAILED, null, '', $next );
		}

		if ( ! $last_ok ) {
			return null;
		}
		return new BackupSnapshot(
			$last_ok,
			BackupSnapshot::STATUS_SUCCESS,
			self::bytes( $latest['filesize'] ?? null ),
			$this->destination( $settings ),
			$next
		);
	}

	/** @param array<string, mixed> $settings */
	private function destination( array $settings ): string {
		$names = [];
		foreach ( (array) ( $settings['remote'] ?? [] ) as $id => $remote ) {
			if ( 'local' !== $id && is_array( $remote ) && true === ( $remote['enabled'] ?? false ) ) {
				$names[] = self::REMOTES[ $id ] ?? ucwords( str_replace( [ '_', '-' ], ' ', (string) $id ) );
			}
		}
		return $names ? implode( ', ', $names ) : self::local_label();
	}
}

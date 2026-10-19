<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\Sources;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\AbstractBackupSource;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UpdraftPlus (free and Premium, same code base), read from 1.26.
 *
 * - `updraft_last_backup`: written when a job ends. `backup_time` is when the
 *   job started (UpdraftPlus keeps no finish time), `success` is 1 or 0, and
 *   `backup_array` carries one `<entity>[n]-size` in bytes per archive.
 * - `updraft_backup_history[backup_time]['service']`: where it was sent.
 * - Running: an `updraft_backup_resume` event waiting in WP-Cron.
 * - Next: the `updraft_backup` and `updraft_backup_database` hooks.
 * Premium Multisite keeps its options network-wide, hence the site option
 * first on multisite.
 */
final class UpdraftPlus extends AbstractBackupSource {

	/** UpdraftPlus storage method ids → product names. */
	private const SERVICES = [
		'updraftvault' => 'UpdraftVault',
		'dropbox'      => 'Dropbox',
		's3'           => 'Amazon S3',
		'cloudfiles'   => 'Rackspace Cloud Files',
		'googledrive'  => 'Google Drive',
		'onedrive'     => 'OneDrive',
		'ftp'          => 'FTP',
		'azure'        => 'Azure',
		'sftp'         => 'SFTP',
		'googlecloud'  => 'Google Cloud',
		'backblaze'    => 'Backblaze',
		'webdav'       => 'WebDAV',
		's3generic'    => 'S3',
		'pcloud'       => 'pCloud',
		'openstack'    => 'OpenStack',
		'dreamobjects' => 'DreamObjects',
		'email'        => 'Email',
	];

	public function id(): string {
		return 'updraftplus';
	}

	public function label(): string {
		return 'UpdraftPlus';
	}

	protected function plugin_dirs(): array {
		return [ '#^updraftplus#' ];
	}

	public function admin_url(): string {
		return admin_url( 'options-general.php?page=updraftplus' );
	}

	public function last(): ?BackupSnapshot {
		$next = min( array_filter( [
			self::next_cron( 'updraft_backup' ),
			self::next_cron( 'updraft_backup_database' ),
		] ) ?: [ 0 ] );

		if ( $this->is_running() ) {
			return new BackupSnapshot( 0, BackupSnapshot::STATUS_RUNNING, null, '', $next );
		}

		$last = self::read( 'updraft_last_backup' );
		$time = self::timestamp( $last['backup_time'] ?? 0 );
		if ( ! $time ) {
			return null;
		}

		$size = 0;
		foreach ( (array) ( $last['backup_array'] ?? [] ) as $key => $value ) {
			if ( is_string( $key ) && str_ends_with( $key, '-size' ) && is_numeric( $value ) ) {
				$size += (int) $value;
			}
		}

		return new BackupSnapshot(
			$time,
			1 === (int) ( $last['success'] ?? 0 ) ? BackupSnapshot::STATUS_SUCCESS : BackupSnapshot::STATUS_FAILED,
			$size > 0 ? $size : null,
			$this->destination( $time ),
			$next
		);
	}

	/** @return array<string, mixed> */
	private static function read( string $option ): array {
		$value = is_multisite() ? get_site_option( $option, null ) : null;
		$value = is_array( $value ) ? $value : self::option( $option );
		return is_array( $value ) ? $value : [];
	}

	private function is_running(): bool {
		$cron = _get_cron_array();
		foreach ( is_array( $cron ) ? $cron : [] as $events ) {
			if ( is_array( $events ) && isset( $events['updraft_backup_resume'] ) ) {
				return true;
			}
		}
		return false;
	}

	private function destination( int $time ): string {
		$history = self::read( 'updraft_backup_history' );
		$service = $history[ $time ]['service'] ?? 'none';
		$names   = [];
		foreach ( (array) $service as $id ) {
			if ( is_string( $id ) && '' !== $id && 'none' !== $id ) {
				$names[] = self::SERVICES[ $id ] ?? $id;
			}
		}
		return $names ? implode( ', ', array_unique( $names ) ) : self::local_label();
	}
}

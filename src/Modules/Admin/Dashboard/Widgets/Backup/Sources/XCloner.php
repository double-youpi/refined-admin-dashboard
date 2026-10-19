<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\Sources;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\AbstractBackupSource;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XCloner, read from 4.8.
 *
 * XCloner stores no backup record: like its own "latest backup", the newest
 * archive in its store path (`xcloner_store_path`) is the last backup, its
 * mtime the date and its size the size. Parts of a multipart backup are
 * skipped (the `.csv` index stands for them). The status is not stored.
 */
final class XCloner extends AbstractBackupSource {

	private const EXTENSIONS = [ 'tar', 'tgz', 'gz', 'csv', 'encrypted', 'decrypted' ];

	public function id(): string {
		return 'xcloner';
	}

	public function label(): string {
		return 'XCloner';
	}

	protected function plugin_dirs(): array {
		return [ '#^xcloner-backup-and-restore#' ];
	}

	public function admin_url(): string {
		return admin_url( 'admin.php?page=xcloner_manage_backups_page' );
	}

	public function last(): ?BackupSnapshot {
		$dir = self::option( 'xcloner_store_path' );
		if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ) {
			return null;
		}
		$newest = null;
		$mtime  = 0;
		foreach ( (array) glob( trailingslashit( $dir ) . 'backup_*' ) as $file ) {
			$name = basename( (string) $file );
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::EXTENSIONS, true ) || str_contains( $name, '-part' ) || ! is_file( (string) $file ) ) {
				continue;
			}
			$time = (int) filemtime( (string) $file );
			if ( $time > $mtime ) {
				$mtime  = $time;
				$newest = (string) $file;
			}
		}
		if ( null === $newest ) {
			return null;
		}
		$size = 'csv' === strtolower( pathinfo( $newest, PATHINFO_EXTENSION ) ) ? null : self::bytes( filesize( $newest ) );
		return new BackupSnapshot( $mtime, BackupSnapshot::STATUS_UNKNOWN, $size, self::local_label() );
	}
}

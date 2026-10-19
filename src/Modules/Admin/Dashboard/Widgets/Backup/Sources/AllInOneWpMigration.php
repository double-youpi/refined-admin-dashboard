<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\Sources;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\AbstractBackupSource;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All-in-One WP Migration, read from 7.x.
 *
 * No backup record: an export is built elsewhere and only moved into the
 * backups folder once complete, so the newest `.wpress` there is the last
 * finished backup (mtime, size). `ai1wm_status` is not used: it is shared with
 * imports and never cleared, so it would report a stale "in progress".
 * The folder is the plugin's own constant; its resolver function is avoided
 * because it can delete an option.
 */
final class AllInOneWpMigration extends AbstractBackupSource {

	public function id(): string {
		return 'all-in-one-wp-migration';
	}

	public function label(): string {
		return 'All-in-One WP Migration';
	}

	protected function plugin_dirs(): array {
		return [ '#^all-in-one-wp-migration$#' ];
	}

	public function admin_url(): string {
		return admin_url( 'admin.php?page=ai1wm_backups' );
	}

	public function last(): ?BackupSnapshot {
		$dir = defined( 'AI1WM_BACKUPS_PATH' ) ? (string) constant( 'AI1WM_BACKUPS_PATH' ) : WP_CONTENT_DIR . '/ai1wm-backups';
		if ( ! is_dir( $dir ) ) {
			return null;
		}
		$dir    = trailingslashit( $dir );
		$files  = array_merge( (array) glob( $dir . '*.wpress' ), (array) glob( $dir . '*/*.wpress' ) );
		$newest = '';
		$mtime  = 0;
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
		return new BackupSnapshot( $mtime, BackupSnapshot::STATUS_SUCCESS, self::bytes( filesize( $newest ) ), self::local_label() );
	}
}

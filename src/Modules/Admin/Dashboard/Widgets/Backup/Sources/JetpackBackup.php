<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\Sources;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\AbstractBackupSource;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jetpack VaultPress Backup (standalone plugin).
 *
 * Backups live on WordPress.com: the site keeps no date or size. The one local
 * trace is My Jetpack's cached check, `my-jetpack-backup-status`: `no_errors`,
 * or an error array when the last backup failed (often absent). The main
 * Jetpack plugin alone is not a source: it does not mean a backup plan.
 */
final class JetpackBackup extends AbstractBackupSource {

	public function id(): string {
		return 'jetpack-backup';
	}

	public function label(): string {
		return 'Jetpack Backup';
	}

	protected function plugin_dirs(): array {
		return [ '#^jetpack-backup#' ];
	}

	public function admin_url(): string {
		return admin_url( 'admin.php?page=jetpack-backup' );
	}

	public function last(): ?BackupSnapshot {
		$check  = get_transient( 'my-jetpack-backup-status' );
		$status = BackupSnapshot::STATUS_UNKNOWN;
		if ( 'no_errors' === $check ) {
			$status = BackupSnapshot::STATUS_SUCCESS;
		} elseif ( is_array( $check ) && 'error' === ( $check['type'] ?? '' ) ) {
			$status = BackupSnapshot::STATUS_FAILED;
		}
		return new BackupSnapshot( 0, $status, null, 'Jetpack Cloud', 0, true );
	}
}

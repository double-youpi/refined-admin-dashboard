<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\Sources;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\AbstractBackupSource;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BlogVault.
 *
 * Backups run on BlogVault's servers; the site stores no backup record. Its
 * `lastbackuptime` is a sync time ("Last Synced At" in its own screen), not a
 * backup time, so it is deliberately not shown as one.
 */
final class BlogVault extends AbstractBackupSource {

	public function id(): string {
		return 'blogvault';
	}

	public function label(): string {
		return 'BlogVault';
	}

	protected function plugin_dirs(): array {
		return [ '#^blogvault-real-time-backup#' ];
	}

	public function admin_url(): string {
		return is_multisite() ? network_admin_url( 'admin.php?page=bvbackup' ) : admin_url( 'admin.php?page=bvbackup' );
	}

	public function last(): ?BackupSnapshot {
		return new BackupSnapshot( 0, BackupSnapshot::STATUS_UNKNOWN, null, 'BlogVault Cloud', 0, true );
	}
}

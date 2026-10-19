<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The last backup a backup plugin knows about, in one shape for every plugin.
 *
 * `finished_at` is a Unix timestamp in seconds (0 when the plugin keeps no
 * date); `size` is in bytes, null when the plugin does not record it.
 * `in_cloud` marks services that keep their backups on their own servers
 * (Jetpack, BlogVault): the site only knows that they run, sometimes whether
 * the last one failed, never when it finished.
 */
final class BackupSnapshot {

	public const STATUS_SUCCESS = 'success';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_RUNNING = 'running';
	public const STATUS_UNKNOWN = 'unknown';

	public function __construct(
		public readonly int $finished_at,
		public readonly string $status = self::STATUS_UNKNOWN,
		public readonly ?int $size = null,
		public readonly string $destination = '',
		public readonly int $next_at = 0,
		public readonly bool $in_cloud = false
	) {}

	public function is_failed(): bool {
		return self::STATUS_FAILED === $this->status;
	}
}

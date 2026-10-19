<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\Sources;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\AbstractBackupSource;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPvivid Backup (free; Pro is an add-on beside it), read from 0.9.
 *
 * - `wpvivid_last_msg`: the last task, in WPvivid's own table
 *   `{base_prefix}wpvivid_options` (older sites: `wp_options`). WPvivid's
 *   getter would create that table, so it is read directly. `status.str`
 *   `completed` = success, `error` / `cancel` = failed; `run_time` is the
 *   last status update, close to the end.
 * - `wpvivid_backup_list[id]`: files with their sizes, and remote copies.
 * - Running: a `running` / `wait_resume` / `no_responds` task in
 *   `wpvivid_task_list` (`ready` leftovers are ignored).
 */
final class WpVivid extends AbstractBackupSource {

	/** WPvivid remote types → product names. */
	private const REMOTES = [
		'ftp'          => 'FTP',
		'sftp'         => 'SFTP',
		'amazons3'     => 'Amazon S3',
		's3compat'     => 'S3',
		'googledrive'  => 'Google Drive',
		'dropbox'      => 'Dropbox',
		'onedrive'     => 'OneDrive',
		'send_to_site' => 'WPvivid',
	];

	public function id(): string {
		return 'wpvivid';
	}

	public function label(): string {
		return 'WPvivid';
	}

	protected function plugin_dirs(): array {
		return [ '#^wpvivid-backuprestore#' ];
	}

	public function admin_url(): string {
		return is_multisite() ? network_admin_url( 'admin.php?page=WPvivid' ) : admin_url( 'admin.php?page=WPvivid' );
	}

	public function last(): ?BackupSnapshot {
		$next = self::next_cron( 'wpvivid_main_schedule_event' );
		foreach ( (array) self::option( 'wpvivid_task_list' ) as $task ) {
			$str = is_array( $task ) ? (string) ( $task['status']['str'] ?? '' ) : '';
			if ( in_array( $str, [ 'running', 'wait_resume', 'no_responds' ], true ) ) {
				return new BackupSnapshot( 0, BackupSnapshot::STATUS_RUNNING, null, '', $next );
			}
		}

		$msg = $this->last_message();
		$id  = (string) ( $msg['id'] ?? '' );
		$ts  = self::timestamp( $msg['status']['run_time'] ?? 0 ) ?: self::timestamp( $msg['status']['start_time'] ?? 0 );
		if ( '' === $id || ! $ts ) {
			return null;
		}
		$str    = (string) ( $msg['status']['str'] ?? '' );
		// A successful run saves its message while the task still reads
		// "running" (WPvivid 0.9.136, seen on a real backup on 19/10/2026), and
		// WPvivid's own report counts it as "Succeeded". No task is running any
		// more (checked above), so it finished.
		$status = match ( $str ) {
			'completed', 'running' => BackupSnapshot::STATUS_SUCCESS,
			'error', 'cancel'      => BackupSnapshot::STATUS_FAILED,
			default                => BackupSnapshot::STATUS_UNKNOWN,
		};

		$list  = self::option( 'wpvivid_backup_list' );
		$entry = is_array( $list ) && is_array( $list[ $id ] ?? null ) ? $list[ $id ] : [];
		$size  = 0;
		foreach ( (array) ( $entry['backup']['files'] ?? [] ) as $file ) {
			$size += is_array( $file ) && is_numeric( $file['size'] ?? null ) ? (int) $file['size'] : 0;
		}
		return new BackupSnapshot( $ts, $status, $size > 0 ? $size : null, $entry ? $this->destination( $entry ) : '', $next );
	}

	/** @return array<string, mixed> */
	private function last_message(): array {
		global $wpdb;
		$table = $wpdb->base_prefix . 'wpvivid_options';
		$value = null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only, another plugin's table, once per dashboard render.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only, another plugin's table, once per dashboard render.
			$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $table, 'wpvivid_last_msg' ) );
			$value = null !== $value ? maybe_unserialize( (string) $value ) : null;
		}
		if ( ! is_array( $value ) ) {
			$value = self::option( 'wpvivid_last_msg' );
		}
		return is_array( $value ) ? $value : [];
	}

	/** @param array<string, mixed> $entry */
	private function destination( array $entry ): string {
		$names = [];
		foreach ( (array) ( $entry['remote'] ?? [] ) as $remote ) {
			$type = is_array( $remote ) ? (string) ( $remote['type'] ?? '' ) : '';
			if ( '' !== $type ) {
				$names[] = self::REMOTES[ $type ] ?? $type;
			}
		}
		if ( ! $names || 0 !== (int) ( $entry['save_local'] ?? 1 ) ) {
			array_unshift( $names, self::local_label() );
		}
		return implode( ', ', array_unique( $names ) );
	}
}

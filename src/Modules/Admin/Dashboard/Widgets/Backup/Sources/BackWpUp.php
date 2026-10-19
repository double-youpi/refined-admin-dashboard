<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\Sources;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\AbstractBackupSource;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BackWPup 5.x (free and Pro, same code base).
 *
 * - `{prefix}bwpup_backups` (main site): one row per destination per run,
 *   grouped by log file. `created` = still running, all `completed` =
 *   success, anything else = failed; `modified` (UTC) ≈ the end of the step.
 * - Size: the `backwpup_backupfilesize` meta tag of the run's log file.
 * - Without the table (older 5.x): the jobs' `lastrun` (site local time,
 *   converted) plus `lastruntime`, status unknown.
 * - Next: `backwpup_cron` per WP-Cron job.
 */
final class BackWpUp extends AbstractBackupSource {

	/** BackWPup destination ids → product names (FOLDER is local). */
	private const DESTINATIONS = [
		'EMAIL'     => 'Email',
		'FTP'       => 'FTP',
		'DROPBOX'   => 'Dropbox',
		'S3'        => 'Amazon S3',
		'MSAZURE'   => 'Azure',
		'RSC'       => 'Rackspace',
		'SUGARSYNC' => 'SugarSync',
		'GDRIVE'    => 'Google Drive',
		'HIDRIVE'   => 'HiDrive',
		'ONEDRIVE'  => 'OneDrive',
		'GLACIER'   => 'Amazon Glacier',
	];

	public function id(): string {
		return 'backwpup';
	}

	public function label(): string {
		return 'BackWPup';
	}

	protected function plugin_dirs(): array {
		return [ '#^backwpup#' ];
	}

	public function admin_url(): string {
		return network_admin_url( 'admin.php?page=backwpupbackups' );
	}

	public function last(): ?BackupSnapshot {
		$jobs = self::site_option( 'backwpup_jobs' );
		$jobs = is_array( $jobs ) ? $jobs : [];
		$next = $this->next_run( $jobs );
		$rows = $this->latest_rows();
		if ( $rows ) {
			return $this->from_rows( $rows, $next );
		}

		// Older 5.x: the jobs' own last run.
		$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		$best   = null;
		foreach ( $jobs as $job ) {
			$start = is_array( $job ) ? self::timestamp( $job['lastrun'] ?? 0 ) : 0;
			if ( $start && ( null === $best || $start > $best['start'] ) ) {
				$best = [ 'start' => $start, 'job' => $job ];
			}
		}
		if ( null === $best ) {
			return null;
		}
		$end = $best['start'] - $offset + (int) ( $best['job']['lastruntime'] ?? 0 );
		return new BackupSnapshot( max( 0, $end ), BackupSnapshot::STATUS_UNKNOWN, $this->log_size( (string) ( $best['job']['logfile'] ?? '' ) ), '', $next );
	}

	/**
	 * Rows of the most recent run.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function latest_rows(): array {
		global $wpdb;
		$table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'bwpup_backups';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only, another plugin's table, once per dashboard render.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only, another plugin's table, once per dashboard render.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, 20 ), ARRAY_A );
		if ( ! is_array( $rows ) || ! $rows ) {
			return [];
		}
		$key = static fn( array $row ): string => '' !== (string) ( $row['logfile'] ?? '' )
			? (string) $row['logfile']
			: ( $row['job_id'] ?? '' ) . '|' . ( $row['submitted_at'] ?? '' );
		$run = $key( $rows[0] );
		return array_values( array_filter( $rows, static fn( array $row ): bool => $key( $row ) === $run ) );
	}

	/** @param array<int, array<string, string>> $rows */
	private function from_rows( array $rows, int $next ): BackupSnapshot {
		$statuses = array_map( static fn( array $row ): string => (string) ( $row['status'] ?? '' ), $rows );
		if ( in_array( 'created', $statuses, true ) ) {
			return new BackupSnapshot( 0, BackupSnapshot::STATUS_RUNNING, null, '', $next );
		}
		$ended = 0;
		$names = [];
		foreach ( $rows as $row ) {
			$ended = max( $ended, (int) strtotime( (string) ( $row['modified'] ?? '' ) ) );
			if ( 'completed' === ( $row['status'] ?? '' ) ) {
				$dest    = strtoupper( (string) ( $row['destination'] ?? '' ) );
				$names[] = 'FOLDER' === $dest ? self::local_label() : ( self::DESTINATIONS[ $dest ] ?? $dest );
			}
		}
		// The copy kept on the site first, as for the other sources.
		usort( $names, static fn( string $a, string $b ): int => ( self::local_label() === $b ) <=> ( self::local_label() === $a ) );
		$ok = count( array_filter( $statuses, static fn( string $s ): bool => 'completed' === $s ) ) === count( $statuses );
		return new BackupSnapshot(
			max( 0, $ended ),
			$ok ? BackupSnapshot::STATUS_SUCCESS : BackupSnapshot::STATUS_FAILED,
			$this->log_size( (string) ( $rows[0]['logfile'] ?? '' ) ),
			implode( ', ', array_unique( $names ) ),
			$next
		);
	}

	/** Archive size from the run's log header, null when unreadable. */
	private function log_size( string $logfile ): ?int {
		if ( '' === $logfile || str_ends_with( $logfile, '.gz' ) || ! is_readable( $logfile ) ) {
			return null;
		}
		$meta = get_meta_tags( $logfile );
		return is_array( $meta ) ? self::bytes( $meta['backwpup_backupfilesize'] ?? null ) : null;
	}

	/** @param array<int|string, mixed> $jobs */
	private function next_run( array $jobs ): int {
		$next = 0;
		foreach ( $jobs as $id => $job ) {
			if ( is_array( $job ) && 'wpcron' === ( $job['activetype'] ?? '' ) ) {
				$at   = self::next_cron( 'backwpup_cron', [ 'arg' => (int) ( $job['jobid'] ?? $id ) ] );
				$next = $at && ( ! $next || $at < $next ) ? $at : $next;
			}
		}
		return $next;
	}
}

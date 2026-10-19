<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\Sources;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\AbstractBackupSource;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicator 5.x (Lite; Pro shares the code base).
 *
 * Backups live in its own network-wide table `{base_prefix}duplicator_backups`:
 * `status` 100 = complete, 0 to 99 = running, below 0 = failed or cancelled;
 * `created` is the start time in UTC; temporary records are flagged
 * `TEMPORARY`. In the `package` JSON, `state_times["100"]["start"]` is when
 * the build completed and `Archive.Size` the archive size in bytes. Remote
 * copies are named from its `duplicator_entities` storage records.
 * One read per request, kept in the object cache.
 */
final class Duplicator extends AbstractBackupSource {

	public function id(): string {
		return 'duplicator';
	}

	public function label(): string {
		return 'Duplicator';
	}

	protected function plugin_dirs(): array {
		return [ '#^duplicator(-pro)?$#' ];
	}

	public function admin_url(): string {
		return is_multisite() ? network_admin_url( 'admin.php?page=duplicator' ) : admin_url( 'admin.php?page=duplicator' );
	}

	public function last(): ?BackupSnapshot {
		$row = $this->latest_row();
		if ( null === $row ) {
			return null;
		}
		$status  = (int) $row['status'];
		$created = (int) strtotime( (string) $row['created'] . ' UTC' );

		if ( $status >= 0 && $status < 100 ) {
			return new BackupSnapshot( 0, BackupSnapshot::STATUS_RUNNING );
		}
		if ( $status < 0 ) {
			return new BackupSnapshot( max( 0, $created ), BackupSnapshot::STATUS_FAILED );
		}

		$package  = json_decode( (string) $row['package'], true );
		$package  = is_array( $package ) ? $package : [];
		$finished = self::timestamp( $package['state_times']['100']['start'] ?? 0 ) ?: max( 0, $created );
		$flags    = explode( ',', (string) $row['flags'] );

		return new BackupSnapshot(
			$finished,
			BackupSnapshot::STATUS_SUCCESS,
			self::bytes( $package['Archive']['Size'] ?? null ),
			$this->destination( $package, $flags )
		);
	}

	/** @return array{status:string, flags:string, created:string, package:string}|null */
	private function latest_row(): ?array {
		$cached = wp_cache_get( 'duplicator_latest', 'doubleyoupi_backup' );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}
		global $wpdb;
		$table = $wpdb->base_prefix . 'duplicator_backups';
		$row   = null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only, another plugin's table, cached just above.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only, another plugin's table, cached just above.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT status, flags, created, package FROM %i WHERE FIND_IN_SET(%s, flags) = 0 ORDER BY created DESC LIMIT 1',
					$table,
					'TEMPORARY'
				),
				ARRAY_A
			);
		}
		$row = is_array( $row ) ? $row : null;
		wp_cache_set( 'duplicator_latest', $row ?? 0, 'doubleyoupi_backup' );
		return $row;
	}

	/**
	 * @param array<string, mixed> $package
	 * @param string[]             $flags
	 */
	private function destination( array $package, array $flags ): string {
		$names = [];
		if ( in_array( 'HAVE_REMOTE', $flags, true ) ) {
			$ids = [];
			foreach ( (array) ( $package['upload_infos'] ?? [] ) as $info ) {
				if ( is_array( $info ) && ! empty( $info['copied_archive'] ) && empty( $info['failed'] ) && empty( $info['cancelled'] ) ) {
					$ids[] = (int) ( $info['storage_id'] ?? 0 );
				}
			}
			$names = $this->storage_names( array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
		}
		if ( in_array( 'HAVE_LOCAL', $flags, true ) ) {
			array_unshift( $names, self::local_label() );
		}
		return implode( ', ', array_unique( $names ) );
	}

	/**
	 * @param int[] $ids
	 * @return string[]
	 */
	private function storage_names( array $ids ): array {
		if ( ! $ids ) {
			return [];
		}
		global $wpdb;
		$table = $wpdb->base_prefix . 'duplicator_entities';
		// All storage records (a handful), filtered here: no dynamic IN list.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only, another plugin's table, once per dashboard render.
		$all  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, data FROM %i WHERE type = %s', $table, 'Storage_Entity' ), ARRAY_A );
		$rows = [];
		foreach ( is_array( $all ) ? $all : [] as $row ) {
			if ( in_array( (int) ( $row['id'] ?? 0 ), $ids, true ) ) {
				$rows[] = (string) ( $row['data'] ?? '' );
			}
		}
		$names = [];
		foreach ( (array) $rows as $data ) {
			$decoded = json_decode( (string) $data, true );
			if ( is_array( $decoded ) && ! empty( $decoded['name'] ) ) {
				$names[] = sanitize_text_field( (string) $decoded['name'] );
			}
		}
		return $names;
	}
}

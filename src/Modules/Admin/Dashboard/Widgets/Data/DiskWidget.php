<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Disk space" widget — server disk usage + media library + database size.
 *
 * Data is cached for 1 hour via transient to avoid walking the uploads
 * directory on every page load (expensive on sites with large media libraries).
 */
final class DiskWidget extends AbstractDataWidget {

	private const CACHE_KEY = 'doubleyoupi_dashboard_disk_data';
	private const CACHE_TTL = HOUR_IN_SECONDS;

	public function id(): string {
		return 'disk';
	}

	public function required_cap(): string {
		return 'manage_options';
	}

	public function name(): string {
		return __( 'Disk space', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Server disk space, media library size, DB size. Cached 1h to avoid walking on each pageload.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Hard drive — horizontal rectangle with indicator.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="11" x2="22" y2="11"/><circle cx="18" cy="15" r="1.2"/><line x1="6" y1="15" x2="13" y2="15"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$total = self::safe_disk_total();
		$free  = self::safe_disk_free();
		$used  = $total > 0 ? $total - $free : 0;
		$pct   = $total > 0 ? (int) round( $used / $total * 100 ) : 0;

		$data = [
			'total_bytes' => $total,
			'used_bytes'  => $used,
			'free_bytes'  => $free,
			'used_pct'    => $pct,
			'media_bytes' => self::media_size(),
			'db_bytes'    => self::db_size(),
		];

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * disk_total_space may throw or be disabled. Wrapped for safety.
	 */
	private static function safe_disk_total(): int {
		try {
			$value = @disk_total_space( ABSPATH );
			return $value !== false ? (int) $value : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	private static function safe_disk_free(): int {
		try {
			$value = @disk_free_space( ABSPATH );
			return $value !== false ? (int) $value : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Walk wp_upload_dir() to compute the size. Expensive — hence the cache.
	 * Capped at 50 000 visited files to avoid timeouts on enormous media
	 * libraries (at which point the user has other problems to worry about).
	 */
	private static function media_size(): int {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) || ! is_dir( $uploads['basedir'] ) ) {
			return 0;
		}

		$total = 0;
		$count = 0;
		$iter  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $uploads['basedir'], \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iter as $file ) {
			if ( $file->isFile() ) {
				$total += (int) $file->getSize();
				$count++;
				if ( $count > 50000 ) {
					break;
				}
			}
		}

		return $total;
	}

	private static function db_size(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared information_schema stat with no WP table equivalent; the widget caches the result via transient (see CACHE_KEY).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = %s",
				DB_NAME
			)
		);
		if ( empty( $rows ) ) {
			return 0;
		}
		return (int) ( $rows[0]->size ?? 0 );
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		$pct = (int) ( $data['used_pct'] ?? 0 );
		$state = $pct > 95 ? 'bad' : ( $pct > 80 ? 'warn' : 'ok' );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Disk', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
		</div>
		<div class="dy-dashboard__card-body">
			<div class="dy-dashboard__row dy-dashboard__row--baseline">
				<span class="dy-dashboard__big-num dy-dashboard__big-num--<?php echo esc_attr( $state ); ?>">
					<?php echo esc_html( (string) $pct ); ?><span class="dy-dashboard__big-num-unit">%</span>
				</span>
				<?php if ( ! empty( $data['total_bytes'] ) ) : ?>
					<span class="dy-dashboard__row-k"><?php echo esc_html( size_format( (int) ( $data['used_bytes'] ?? 0 ), 0 ) . ' / ' . size_format( (int) $data['total_bytes'], 0 ) ); ?></span>
				<?php endif; ?>
			</div>
			<div class="dy-dashboard__progress">
				<div class="dy-dashboard__progress-bar dy-dashboard__progress-bar--<?php echo esc_attr( $state ); ?>" style="width: <?php echo esc_attr( (string) max( 1, $pct ) ); ?>%;"></div>
			</div>
			<?php if ( ! empty( $data['media_bytes'] ) || ! empty( $data['db_bytes'] ) ) : ?>
				<div class="dy-dashboard__sub">
					<span><?php esc_html_e( 'media', 'doubleyoupi-refined-admin-dashboard' ); ?> <?php echo esc_html( size_format( (int) ( $data['media_bytes'] ?? 0 ), 0 ) ?: '—' ); ?></span>
					<span><?php esc_html_e( 'db', 'doubleyoupi-refined-admin-dashboard' ); ?> <?php echo esc_html( size_format( (int) ( $data['db_bytes'] ?? 0 ), 0 ) ?: '—' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

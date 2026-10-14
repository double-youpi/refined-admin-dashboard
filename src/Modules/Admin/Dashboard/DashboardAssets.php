<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard;

use DoubleYoupi\Core\Build;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues Dashboard assets. Restricted to the `index.php` (Dashboard) screen
 * to avoid polluting other admin pages.
 *
 * CSS : `assets/css/modules/admin/dashboard.css` (entry-point `dashboard.scss`)
 * JS  : `assets/js/modules/admin/dashboard/dashboard.js`
 */
final class DashboardAssets {

	public static function enqueue( string $hook ): void {
		// The WP Dashboard is on the `index.php` hook.
		if ( $hook !== 'index.php' ) {
			return;
		}

		$css_rel = 'assets/css/modules/admin/dashboard.css';
		$css_abs = Build::dir( __FILE__, $css_rel );
		if ( file_exists( $css_abs ) ) {
			wp_enqueue_style(
				'doubleyoupi-dashboard',
				Build::url( __FILE__, $css_rel ),
				[],
				self::asset_version( $css_abs )
			);
		}

		$js_rel = 'assets/js/modules/admin/dashboard/dashboard.js';
		$js_abs = Build::dir( __FILE__, $js_rel );
		if ( file_exists( $js_abs ) ) {
			wp_enqueue_script(
				'doubleyoupi-dashboard',
				Build::url( __FILE__, $js_rel ),
				[],
				self::asset_version( $js_abs ),
				true
			);
		}
	}

	/**
	 * Cache-buster: filemtime in dev, static version in production.
	 * Same logic as Admin::asset_version for consistency.
	 */
	private static function asset_version( string $abs_path ): string {
		$is_dev = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			|| str_ends_with( (string) DOUBLEYOUPI_VERSION, '-dev' );
		if ( $is_dev ) {
			$mtime = @filemtime( $abs_path );
			if ( $mtime ) {
				return (string) $mtime;
			}
		}
		return (string) DOUBLEYOUPI_VERSION;
	}
}

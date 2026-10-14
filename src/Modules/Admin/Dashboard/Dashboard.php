<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\WidgetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard sub-module of the Admin module.
 *
 * Not a top-level AbstractModule: it is instantiated and booted from
 * Admin::boot(). If the Admin module is disabled, this sub-module does not run.
 *
 * Responsibilities:
 *   - Bootstrap the WidgetRegistry (10 native DY widgets)
 *   - Override wp_dashboard_setup (DashboardOverride)
 *   - Enqueue CSS/JS on the Dashboard screen (DashboardAssets)
 *   - REST API endpoints (RestController)
 *
 * Idempotent: boot() can be called multiple times without side effects
 * (add_action/add_filter calls are guarded by an internal flag).
 */
final class Dashboard {

	private bool $booted = false;

	/**
	 * True if the current admin screen is the Dashboard (index.php), meaning it
	 * is driven by our override.
	 *
	 * Exposed as static so that other renderers in the Admin module can step aside
	 * on this screen (see CLAUDE.md §2 "detect and step aside"): the Dashboard
	 * relocates ALL WP notices into its "WordPress native" screen (the last one in
	 * the carousel) via `dashboard.js`. The global notices dock would duplicate
	 * them and conflict (race on the same `.notice` nodes) — hence it is suppressed
	 * here.
	 *
	 * We rely on `$pagenow` (populated very early, available from `init`) with a
	 * fallback to `get_current_screen()->base` for late hooks.
	 */
	public static function is_current_screen(): bool {
		if ( isset( $GLOBALS['pagenow'] ) && 'index.php' === $GLOBALS['pagenow'] ) {
			return true;
		}
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen instanceof \WP_Screen && 'dashboard' === $screen->base ) {
				return true;
			}
		}
		return false;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Bootstrap the registry — then emits `doubleyoupi_dashboard_widgets_registered`.
		WidgetRegistry::instance()->bootstrap();

		// Override wp_dashboard_setup: VERY LOW priority (PHP_INT_MAX) to run AFTER
		// WP core AND plugins have registered their widgets via this hook.
		// This captures the full state (for the native WP screen list) and THEN
		// clears it to take over rendering. At priority 1 we were clearing an empty
		// array, and WP/plugins would re-populate right after.
		add_action( 'wp_dashboard_setup', [ DashboardOverride::class, 'register' ], PHP_INT_MAX );

		// Assets on the index.php (Dashboard) screen only.
		add_action( 'admin_enqueue_scripts', [ DashboardAssets::class, 'enqueue' ] );

		// REST API.
		add_action( 'rest_api_init', [ RestController::class, 'register_routes' ] );
	}
}

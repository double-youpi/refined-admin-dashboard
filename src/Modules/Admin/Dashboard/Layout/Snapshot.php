<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Layout;

use DoubleYoupi\Modules\Admin\Dashboard\Compat\WpDashboardWidgetsAdapter;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\WidgetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the full snapshot (layout + data + catalog) in a single pass.
 *
 * This is the key piece for performance: instead of N REST calls (one per
 * widget), the JS makes ONE call at page load that returns everything.
 *
 * The catalog is structured in 3 categories:
 *   - 'doubleyoupi' : native Double Youpi widgets
 *   - 'wordpress'   : WordPress core widgets captured via WpDashboardWidgetsAdapter
 *   - 'plugins'     : widgets added by third-party plugins (Yoast, WC, Bricks…)
 */
final class Snapshot {

	/**
	 * Construit le snapshot pour un utilisateur.
	 *
	 * @return array{
	 *   layout: array<string, mixed>,
	 *   widget_data: array<string, array<string, mixed>>,
	 *   catalog: array{
	 *     doubleyoupi: array<int, array<string, mixed>>,
	 *     wordpress: array<int, array<string, mixed>>,
	 *     plugins: array<int, array<string, mixed>>
	 *   },
	 *   presets: array<int, array<string, mixed>>
	 * }
	 */
	/**
	 * Logs a widget failure (WP_DEBUG only), for the site owner's error log.
	 */
	public static function log_failure( string $widget_id, \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only diagnostic, gated behind WP_DEBUG.
			error_log( sprintf( '[doubleyoupi-admin] dashboard widget "%s" failed: %s in %s:%d', $widget_id, $e->getMessage(), $e->getFile(), $e->getLine() ) );
		}
	}

	public static function build( int $user_id ): array {
		$layout   = LayoutResolver::for_user( $user_id );
		$registry = WidgetRegistry::instance();

		$widget_data = [];
		foreach ( $layout['screens'] as $screen ) {
			foreach ( $screen['widgets'] as $widget ) {
				$instance = $registry->get( $widget['id'] );
				if ( ! $instance ) {
					continue;
				}
				$key    = $widget['instance'] ?? $widget['id'];
				$config = $widget['config'] ?? [];
				// A widget that throws (a plugin API that changed, unexpected
				// data) must not white-screen the whole dashboard: it renders
				// as a "could not load" card and the rest stays up.
				try {
					$widget_data[ $key ] = $instance->get_data( $config );
				} catch ( \Throwable $e ) {
					$widget_data[ $key ] = [ 'available' => false, 'error' => true ];
					self::log_failure( (string) $widget['id'], $e );
				}
			}
		}

		// Add palette: we only expose OUR Double Youpi widgets (styled and
		// consistent). WordPress/plugin widgets are not relevant for a simple,
		// minimal dashboard — they are either redundant with our dedicated widgets
		// or promotional (Rank Math, Elementor, Yoast, WC…).
		// They remain visible on the last screen ("native"), which renders them
		// independently via WpDashboardWidgetsAdapter::captured().
		// Re-enable via the filter below if needed.
		$expose_external = (bool) apply_filters( 'doubleyoupi_dashboard_palette_external_widgets', false );

		// Split WooCommerce tiles (group=woocommerce) into their own palette
		// section, distinct from Double Youpi widgets.
		$all_dy = $registry->to_panel_tiles();
		$dy_tiles = [];
		$wc_tiles = [];
		foreach ( $all_dy as $tile ) {
			if ( ( $tile['group'] ?? 'doubleyoupi' ) === 'woocommerce' ) {
				$wc_tiles[] = $tile;
			} else {
				$dy_tiles[] = $tile;
			}
		}

		$catalog = [
			'doubleyoupi' => $dy_tiles,
			'woocommerce' => $wc_tiles,
			'wordpress'   => $expose_external ? WpDashboardWidgetsAdapter::for_panel_wordpress() : [],
			'plugins'     => $expose_external ? WpDashboardWidgetsAdapter::for_panel_plugins() : [],
		];
		$catalog = self::flag_validated( $catalog );

		return [
			'layout'      => $layout,
			'widget_data' => $widget_data,
			'catalog'     => $catalog,
			'presets'      => LayoutPresets::list(),
			'dispositions' => Dispositions::catalog(),
			// Disposition → role assignments (for the "Assign to role(s)" menu
			// and UI-side conflict detection).
			'role_assignments' => RoleAssignments::all(),
			// Heuristic defaults per role (role => slug) — displayed as the hint
			// "(default: X)" in the assignment modal.
			'role_defaults'    => RoleAssignments::defaults_map(),
		];
	}

	/**
	 * Allowlist of visually "validated" widgets (properly styled and verified).
	 * Others appear greyed out in the panel (but remain addable during development).
	 * Promote a widget by adding it here once its design is finalised.
	 *
	 * Filterable via `doubleyoupi_dashboard_validated_widgets`.
	 *
	 * @return array<int, string> Validated widget IDs.
	 */
	public static function validated_ids(): array {
		$ids = [
			// All Double Youpi widgets are styled and verified.
			'site_state',
			'todo',
			'activity',
			'modules',
			'backup',
			'disk',
			'quick_actions',
			'shortcuts',
			'note',
			'site_health',
			'static_site',
			'toolkit',
			'create_button',
			'create_hub',
			'admin_link',
			// WooCommerce widgets (styled and verified).
			'wc_orders',
			'wc_revenue',
			'wc_activity',
			'wc_low_stock',
			'wc_sales_trend',
			'wc_top_products',
		];
		/** @var array<int, string> $ids */
		$ids = (array) apply_filters( 'doubleyoupi_dashboard_validated_widgets', $ids );
		return array_values( array_unique( array_map( 'strval', $ids ) ) );
	}

	/**
	 * Adds the `validated` (bool) flag to each tile in each group.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $catalog
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function flag_validated( array $catalog ): array {
		$validated = self::validated_ids();
		foreach ( $catalog as $group => $tiles ) {
			foreach ( $tiles as $i => $tile ) {
				$id = (string) ( $tile['id'] ?? '' );
				$catalog[ $group ][ $i ]['validated'] = in_array( $id, $validated, true );
			}
		}
		return $catalog;
	}
}

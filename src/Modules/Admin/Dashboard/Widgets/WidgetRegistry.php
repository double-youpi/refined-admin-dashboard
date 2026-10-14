<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runtime registry of available widgets.
 *
 * Singleton bootstrapped by Dashboard::boot() — once the registry is populated,
 * the `doubleyoupi_dashboard_widgets_registered` hook fires so third-party
 * plugins can add their own widgets via `register()`.
 *
 * Why singleton instead of explicit dependency injection: the registry is
 * consulted from multiple places (override, REST controller, snapshot) and
 * threading it as a dependency everywhere adds complexity for no real benefit.
 */
final class WidgetRegistry {

	private static ?WidgetRegistry $instance = null;

	/** @var array<string, WidgetInterface> */
	private array $widgets = [];

	private function __construct() {}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Resets the registry — for tests that need to start from a clean state only.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Registers the native Double Youpi widgets. Called once from Dashboard::boot().
	 * Fires the extension action hook afterwards.
	 */
	public function bootstrap(): void {
		// Content widgets — historically instanceable. v4: no longer placed as slots
		// (heading/paragraph have become screen metadata) but remain registered
		// for migration from v3.
		$this->register( new Content\HeadingWidget() );
		$this->register( new Content\ParagraphWidget() );

		// Data widgets — ordered by estimated usage frequency.
		// Default width: 2 slots (see AbstractWidget::recommended_slots).
		// Exceptions: CreateButton and AdminLink return 1 slot.
		$this->register( new Data\SiteStateWidget() );
		$this->register( new Data\ActivityWidget() );
		$this->register( new Data\TodoWidget() );
		$this->register( new Data\ModulesWidget() );
		$this->register( new Data\QuickActionsWidget() );
		$this->register( new Data\BackupWidget() );
		$this->register( new Data\DiskWidget() );
		$this->register( new Data\ShortcutsWidget() );

		// v4 — new widgets.
		$this->register( new Data\NoteWidget() );
		$this->register( new Data\SiteHealthWidget() );
		$this->register( new Data\StaticSiteWidget() );
		$this->register( new Data\ToolkitWidget() );
		$this->register( new Data\CreateButtonWidget() );
		$this->register( new Data\CreateHubWidget() );
		$this->register( new Data\AdminLinkWidget() );

		// WooCommerce widgets — "detect and step aside": registered ONLY if
		// WooCommerce is active. Otherwise they appear neither in the palette
		// nor at render time (no dead tile).
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) ) {
			$this->register( new Data\WcOrdersWidget() );
			$this->register( new Data\WcRevenueWidget() );
			$this->register( new Data\WcActivityWidget() );
			$this->register( new Data\WcLowStockWidget() );
			$this->register( new Data\WcSalesTrendWidget() );
			$this->register( new Data\WcTopProductsWidget() );
		}

		/**
		 * Hook for modules / plugins that want to add their own widgets to the
		 * Dashboard catalogue. Example:
		 *
		 *   add_action( 'doubleyoupi_dashboard_widgets_registered', function ( $registry ) {
		 *       $registry->register( new MyCustomWidget() );
		 *   } );
		 */
		do_action( 'doubleyoupi_dashboard_widgets_registered', $this );
	}

	public function register( WidgetInterface $widget ): void {
		$this->widgets[ $widget->id() ] = $widget;
	}

	public function get( string $id ): ?WidgetInterface {
		return $this->widgets[ $id ] ?? null;
	}

	public function has( string $id ): bool {
		return isset( $this->widgets[ $id ] );
	}

	/** @return array<string, WidgetInterface> */
	public function all(): array {
		return $this->widgets;
	}

	/**
	 * Filters by category ('data' or 'content').
	 * @return array<string, WidgetInterface>
	 */
	public function by_category( string $category ): array {
		return array_filter(
			$this->widgets,
			static fn( WidgetInterface $w ) => $w->category() === $category
		);
	}

	/**
	 * Lightweight representation for the JS payload (palette panel).
	 *
	 * v4 — Content widgets (heading, paragraph) are no longer exposed in the
	 * palette since they have become screen metadata (title + paragraph
	 * configurable per screen from the Customize panel).
	 * Only data widgets appear in the drag-and-drop palette.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function to_panel_tiles(): array {
		// Allowlist of "Double Youpi suite" widgets that deserve the brand logo
		// appended to their name in the palette. The logo is rendered by JS via
		// `tile.brand_logo_svg` — left empty for all other widgets.
		$branded_ids = [ 'modules', 'toolkit' ];
		$logo_svg    = AbstractWidget::dy_brand_logo_svg();
		// WooCommerce widgets: grouped under their own section in the palette
		// (id prefix `wc_`).
		$wc_prefix = 'wc_';

		$tiles = [];
		foreach ( $this->widgets as $widget ) {
			if ( $widget->category() === 'content' ) {
				continue;
			}
			// Role gating: do not offer in the palette a widget the current user
			// does not have the capability to display.
			if ( method_exists( $widget, 'is_visible_to_current_user' ) && ! $widget->is_visible_to_current_user() ) {
				continue;
			}
			$id = $widget->id();
			$tiles[] = [
				'id'               => $id,
				'name'             => $widget->palette_name(),
				'description'      => $widget->description(),
				'icon_svg'         => $widget->icon_svg(),
				'category'         => $widget->category(),
				'group'            => str_starts_with( $id, $wc_prefix ) ? 'woocommerce' : 'doubleyoupi',
				'is_instanceable'  => $widget->is_instanceable(),
				'recommended_slots' => $widget->recommended_slots(),
				'config_schema'    => $widget->config_schema(),
				'brand_logo_svg'   => in_array( $id, $branded_ids, true ) ? $logo_svg : '',
			];
		}

		// Display order in the palette: from simplest (atomic building blocks)
		// to most specialised. Double Youpi "suite" widgets (Modules, Toolkit)
		// come last, then WooCommerce widgets (Tier 1 before Tier 2).
		$order = [
			'note', 'create_button', 'create_hub', 'admin_link', 'shortcuts', 'quick_actions',
			'site_state', 'todo', 'activity', 'disk', 'backup', 'site_health',
			'static_site', 'modules', 'toolkit',
			'wc_revenue', 'wc_sales_trend', 'wc_orders', 'wc_activity', 'wc_top_products', 'wc_low_stock',
		];
		$rank = static function ( string $id ) use ( $order ): int {
			$i = array_search( $id, $order, true );
			return $i === false ? count( $order ) : (int) $i;
		};
		usort( $tiles, static fn( $a, $b ) => $rank( (string) $a['id'] ) <=> $rank( (string) $b['id'] ) );

		return $tiles;
	}
}

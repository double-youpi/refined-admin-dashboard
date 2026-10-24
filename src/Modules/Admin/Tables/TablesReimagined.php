<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Tables;

use DoubleYoupi\Core\Build;

use DoubleYoupi\Modules\Admin\AdminColumnsConflict;
use DoubleYoupi\Modules\Admin\BuilderScreen;
use DoubleYoupi\Modules\Admin\ScreenDisabler;
use DoubleYoupi\Modules\Admin\PostOrdering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TablesReimagined — UX/UI rewrite of native wp-list-tables.
 *
 * The reimagined rendering is built CLIENT-SIDE (tables-reimagined.js) by
 * reading the DOM that WordPress has already produced: the native table stays
 * in the DOM (source of truth, hidden via CSS) and a "Native view" button
 * lets the user revert to the WP rendering styled by _tables.scss. This way,
 * without any hardcoded list, we inherit ALL row-actions (native + third-party
 * plugins via post_row_actions / page_row_actions / user_row_actions /
 * {taxonomy}_row_actions): a new plugin appears automatically.
 *
 * PHP responsibilities (this file):
 *   - Detect target screens: edit.php (post types), users.php,
 *     edit-tags.php (taxonomies), plugins.php (installed plugins),
 *     admin.php generic/orders listings.
 *   - Set the body class `dy-tables` + `dy-tables-reimagined|native` according
 *     to the per-user preference (JSON meta `doubleyoupi_table_modes`).
 *   - Enqueue JS + localize the `doubleyoupiTables` config (REST, nonce, screen,
 *     hierarchy map, chrome i18n).
 *   - REST endpoints: save the mode + options (native per-page +
 *     native hidden columns). Reorder reuses PostOrdering.
 *   - Default items/page = 50 (see CLAUDE.md: fewer settings, opinionated
 *     decisions) via native filters, respecting any user-defined value.
 *
 * Consistent with the manifesto (§2): no per-page setting (auto-detection),
 * detect & step back (native toggle), visually demonstrable.
 */
final class TablesReimagined {

	public const REST_NAMESPACE = 'doubleyoupi/v1';
	public const ROUTE_MODE     = '/admin/table/mode';
	public const ROUTE_OPTIONS  = '/admin/table/options';

	/** User meta: map { signature => 'native'|'reimagined' }. */
	public const META_MODES = 'doubleyoupi_table_modes';

	private const DEFAULT_PER_PAGE = 50;

	/** In-memory cache of the screen detection for the current request. */
	private ?array $screen_cache = null;
	private bool $screen_resolved = false;

	public function boot(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// New columns on a list (an extension was installed): dot on the gear
		// for the first visit + a notice in the notices centre. Never a modal.
		( new ColumnWatch() )->boot();

		// Default items/page = 50 on list tables, unless the user has saved a
		// value via screen options (which we respect).
		add_filter( 'edit_posts_per_page', [ $this, 'default_posts_per_page' ], 10, 2 );
		add_filter( 'users_per_page', [ $this, 'default_users_per_page' ], 10, 1 );
		add_filter( 'edit_tags_per_page', [ $this, 'default_tags_per_page' ], 10, 2 );
	}

	// =====================================================================
	//  Target screen detection
	// =====================================================================

	/**
	 * Describes the current screen if it is a target list-table, otherwise null.
	 *
	 * @return array{kind:string,base:string,postType:?string,taxonomy:?string,hierarchical:bool,orderable:bool,screenId:string,perPageOption:string,hiddenColumnsMeta:string}|null
	 */
	private function target_screen(): ?array {
		if ( $this->screen_resolved ) {
			return $this->screen_cache;
		}
		$screen = $this->detect_screen();

		// Admin Columns manages these lists: we leave them to it (CLAUDE.md §2).
		if ( null !== $screen && in_array( $screen['kind'], AdminColumnsConflict::MANAGED_KINDS, true ) && AdminColumnsConflict::steps_aside() ) {
			$screen = null;
		}

		$this->screen_resolved = true;
		$this->screen_cache    = $screen;
		return $screen;
	}

	/**
	 * Raw detection behind target_screen(), before any step-aside rule.
	 *
	 * @return array{kind:string,base:string,postType:?string,taxonomy:?string,hierarchical:bool,orderable:bool,screenId:string,perPageOption:string,hiddenColumnsMeta:string}|null
	 */
	private function detect_screen(): ?array {
		$this->screen_cache = null;

		// Builder-managed screens keep their native list tables (CLAUDE.md §2).
		if ( BuilderScreen::is_managed() ) {
			return null;
		}

		if ( ! is_admin() ) {
			return null;
		}
		global $pagenow;
		$base = is_string( $pagenow ) ? $pagenow : '';

		// A plugin screen hung under a native menu (`edit.php?post_type=x&page=y`,
		// `users.php?page=y`…) shares the file name of a list screen but holds no
		// list table at all: settings, tools, an options page. Treating it as a
		// list put our body classes and our table CSS on a form, which broke its
		// layout (audit of the FSE bench, 13/09/2026). The `page` parameter is
		// the reliable signal: WordPress only sets it on plugin screens.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		$plugin_screen = isset( $_GET['page'] ) && '' !== sanitize_key( (string) wp_unslash( $_GET['page'] ) );
		if ( $plugin_screen && in_array( $base, [ 'edit.php', 'users.php', 'edit-tags.php', 'upload.php' ], true ) ) {
			return null;
		}

		// edit.php → post list (post/page/CPT). We exclude post-new.php / post.php.
		if ( 'edit.php' === $base ) {
			$pt = isset( $_GET['post_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
			$pt_obj = get_post_type_object( $pt );
			if ( ! $pt_obj instanceof \WP_Post_Type || ! $pt_obj->show_ui ) {
				return null;
			}
			$this->screen_cache = [
				'kind'              => 'post',
				'base'              => 'edit.php',
				'postType'          => $pt,
				'taxonomy'          => null,
				'hierarchical'      => (bool) $pt_obj->hierarchical,
				'orderable'         => post_type_supports( $pt, 'page-attributes' ),
				'screenId'          => 'edit-' . $pt,
				'perPageOption'     => 'edit_' . $pt . '_per_page',
				'hiddenColumnsMeta' => 'manageedit-' . $pt . 'columnshidden',
			];
			return $this->screen_cache;
		}

		// users.php → user list.
		if ( 'users.php' === $base ) {
			$this->screen_cache = [
				'kind'              => 'user',
				'base'              => 'users.php',
				'postType'          => null,
				'taxonomy'          => null,
				'hierarchical'      => false,
				'orderable'         => false,
				'screenId'          => 'users',
				'perPageOption'     => 'users_per_page',
				'hiddenColumnsMeta' => 'manageuserscolumnshidden',
			];
			return $this->screen_cache;
		}

		// edit-tags.php → taxonomy term list.
		if ( 'edit-tags.php' === $base ) {
			$tax = isset( $_GET['taxonomy'] ) ? sanitize_key( (string) wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
			if ( '' === $tax || ! taxonomy_exists( $tax ) ) {
				return null;
			}
			$tax_obj = get_taxonomy( $tax );
			$this->screen_cache = [
				'kind'              => 'term',
				'base'              => 'edit-tags.php',
				'postType'          => null,
				'taxonomy'          => $tax,
				'hierarchical'      => $tax_obj instanceof \WP_Taxonomy ? (bool) $tax_obj->hierarchical : false,
				'orderable'         => false,
				'screenId'          => 'edit-' . $tax,
				'perPageOption'     => 'edit_' . $tax . '_per_page',
				'hiddenColumnsMeta' => 'manageedit-' . $tax . 'columnshidden',
			];
			return $this->screen_cache;
		}

		// plugins.php → installed plugins list (kind 'plugins'). One plugin =
		// one row: the native update row folds into a version chip, active
		// plugins are grouped first, actions (update / settings / activate /
		// details / delete) live in the hover drawer. Updates, auto-update
		// toggles and bulk actions are DELEGATED to the native controls
		// (wp.updates keeps its AJAX flow, nonces and credentials modal).
		// The network screen keeps its native table.
		if ( 'plugins.php' === $base && ! is_network_admin() ) {
			$this->screen_cache = [
				'kind'              => 'plugins',
				'base'              => 'plugins.php',
				'postType'          => null,
				'taxonomy'          => null,
				'hierarchical'      => false,
				'orderable'         => false,
				'screenId'          => 'plugins',
				// No per-page option: the plugins screen lists everything on one
				// page by default (999) and we do not add a setting for it.
				'perPageOption'     => '',
				'hiddenColumnsMeta' => 'managepluginscolumnshidden',
			];
			return $this->screen_cache;
		}

		// admin.php → third-party LISTING screens that expose a `.wp-list-table`
		// without following WP column conventions (no `.column-*`, no check-column).
		// Rendered in GENERIC mode (see tables-reimagined.js: <thead> labels +
		// cells mapped by index).
		// Covered here: Loco Translate (translation table for a bundle =
		// loco-plugin/loco-theme &action=view; language list = loco-lang).
		if ( 'admin.php' === $base ) {
			$page   = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
			$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
			$is_loco_listing =
				( in_array( $page, [ 'loco-plugin', 'loco-theme' ], true ) && 'view' === $action )
				|| ( 'loco-lang' === $page && '' === $action );

			// WooCommerce — HPOS order list (admin.php?page=wc-orders).
			// Dedicated "commerce" UX (kind 'orders'): order # + customer primary,
			// status pill, total highlighted, preview/edit actions.
			if ( 'wc-orders' === $page && '' === $action ) {
				$this->screen_cache = [
					'kind'              => 'orders',
					'base'              => 'admin.php',
					'postType'          => null,
					'taxonomy'          => null,
					'hierarchical'      => false,
					'orderable'         => false,
					'screenId'          => 'orders-wc',
					'perPageOption'     => '',
					'hiddenColumnsMeta' => '',
				];
				return $this->screen_cache;
			}

			/**
			 * Filter : doubleyoupi_generic_table_screen
			 * Allows enabling the generic reimagined rendering on other screens
			 * `admin.php?page=…` exposant une wp-list-table tierce.
			 *
			 * @param bool   $match  True if the current screen is a generic list.
			 * @param string $page   Slug `?page=`.
			 * @param string $action Slug `?action=`.
			 */
			$is_generic = (bool) apply_filters( 'doubleyoupi_generic_table_screen', $is_loco_listing, $page, $action );

			if ( $is_generic ) {
				$this->screen_cache = [
					'kind'              => 'generic',
					'base'              => 'admin.php',
					'postType'          => null,
					'taxonomy'          => null,
					'hierarchical'      => false,
					'orderable'         => false,
					'screenId'          => 'generic-' . ( '' !== $page ? $page : 'admin' ),
					'perPageOption'     => '',
					'hiddenColumnsMeta' => '',
				];
				return $this->screen_cache;
			}
		}

		return null;
	}

	// =====================================================================
	//  Body class + mode persistance
	// =====================================================================

	public function body_class( string $classes ): string {
		$screen = $this->target_screen();
		if ( null === $screen ) {
			return $classes;
		}
		$mode = $this->get_mode( ScreenDisabler::current_signature() );
		return $classes . ' dy-tables dy-tables-' . $mode . ' dy-tables-kind-' . $screen['kind'];
	}

	/**
	 * Reads the mode ('reimagined' by default) for a screen signature.
	 */
	private function get_mode( string $signature ): string {
		$map = $this->read_modes();
		$mode = $map[ $signature ] ?? 'reimagined';
		return 'native' === $mode ? 'native' : 'reimagined';
	}

	/** @return array<string,string> */
	private function read_modes(): array {
		$raw = get_user_meta( get_current_user_id(), self::META_MODES, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return [];
	}

	// =====================================================================
	//  Assets
	// =====================================================================

	public function enqueue( string $hook ): void {
		$screen = $this->target_screen();
		if ( null === $screen ) {
			return;
		}
		$js = Build::dir( __FILE__, 'assets/js/modules/admin/tables-reimagined.js' );
		if ( ! file_exists( $js ) ) {
			return;
		}

		wp_enqueue_script(
			'doubleyoupi-tables-reimagined',
			Build::url( __FILE__, 'assets/js/modules/admin/tables-reimagined.js' ),
			[ 'wp-api-fetch' ],
			$this->asset_version( $js ),
			true
		);

		$signature = ScreenDisabler::current_signature();

		$config = [
				'rest'           => [
					'mode'    => esc_url_raw( rest_url( self::REST_NAMESPACE . self::ROUTE_MODE ) ),
					'options' => esc_url_raw( rest_url( self::REST_NAMESPACE . self::ROUTE_OPTIONS ) ),
					'reorder' => esc_url_raw( rest_url( PostOrdering::REST_NAMESPACE . PostOrdering::REST_ROUTE ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				],
				'signature'      => $signature,
				'mode'           => $this->get_mode( $signature ),
				'defaultPerPage' => self::DEFAULT_PER_PAGE,
				'perPage'        => $this->effective_per_page( $screen ),
				'screen'         => [
					'kind'              => $screen['kind'],
					'base'              => $screen['base'],
					'postType'          => $screen['postType'],
					'taxonomy'          => $screen['taxonomy'],
					'hierarchical'      => $screen['hierarchical'],
					'orderable'         => $screen['orderable'],
					'screenId'          => $screen['screenId'],
					'perPageOption'     => $screen['perPageOption'],
					'hiddenColumnsMeta' => $screen['hiddenColumnsMeta'],
				],
				'hierarchy'      => $this->hierarchy_map( $screen ),
				'i18n'           => $this->i18n_strings(),
		];

		/**
		 * Lets satellites of the list (ColumnWatch) enrich the JS config.
		 *
		 * @param array<string,mixed> $config Config localised as `doubleyoupiTables`.
		 * @param array<string,mixed> $screen Detected screen.
		 */
		$config = apply_filters( 'doubleyoupi_filter_tables_config', $config, $screen );

		wp_localize_script( 'doubleyoupi-tables-reimagined', 'doubleyoupiTables', $config );
	}

	/**
	 * Effective items per page (user screen option if set, otherwise the default 50)
	 * — this is a configurable limit, not a count of displayed rows.
	 */
	private function effective_per_page( array $screen ): int {
		$opt = (int) get_user_option( $screen['perPageOption'] );
		return $opt > 0 ? $opt : self::DEFAULT_PER_PAGE;
	}

	/**
	 * Map { ID => { parent, depth } } for hierarchical post types,
	 * otherwise null. Used for tree indentation + drag with parent context.
	 * Built via a lightweight `id=>parent` query.
	 *
	 * @param array $screen
	 * @return array<int,array{parent:int,depth:int}>|null
	 */
	private function hierarchy_map( array $screen ): ?array {
		if ( 'post' !== $screen['kind'] || ! $screen['hierarchical'] || null === $screen['postType'] ) {
			return null;
		}
		$pairs = get_posts(
			[
				'post_type'      => $screen['postType'],
				'post_status'    => 'any',
				'posts_per_page' => 2000,            // safety cap: very large sites → we cap
				'fields'         => 'id=>parent',
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			]
		);
		if ( ! is_array( $pairs ) || empty( $pairs ) ) {
			return null;
		}

		$parent_of = [];
		foreach ( $pairs as $id => $parent ) {
			$parent_of[ (int) $id ] = (int) $parent;
		}

		$map   = [];
		$depth = static function ( int $id ) use ( $parent_of ): int {
			$d    = 0;
			$seen = [];
			$cur  = $id;
			while ( isset( $parent_of[ $cur ] ) && $parent_of[ $cur ] > 0 ) {
				if ( isset( $seen[ $cur ] ) ) {
					break; // anti-cycle
				}
				$seen[ $cur ] = true;
				$cur          = $parent_of[ $cur ];
				$d++;
				if ( $d > 30 ) {
					break;
				}
			}
			return $d;
		};
		foreach ( $parent_of as $id => $parent ) {
			$map[ $id ] = [ 'parent' => $parent, 'depth' => $depth( $id ) ];
		}
		return $map;
	}

	/**
	 * Chrome strings (action labels come from the native DOM, already translated
	 * by WP/plugins — we therefore translate ONLY the chrome).
	 *
	 * @return array<string,array<string,string>>
	 */
	private function i18n_strings(): array {
		return [
			'statuses' => [
				'publish' => __( 'Published', 'doubleyoupi-refined-admin-dashboard' ),
				'future'  => __( 'Scheduled', 'doubleyoupi-refined-admin-dashboard' ),
				'draft'   => __( 'Draft', 'doubleyoupi-refined-admin-dashboard' ),
				'pending' => __( 'Pending', 'doubleyoupi-refined-admin-dashboard' ),
				'private' => __( 'Private', 'doubleyoupi-refined-admin-dashboard' ),
				'trash'   => __( 'Trash', 'doubleyoupi-refined-admin-dashboard' ),
			],
			'labels'   => [
				'nativeView'        => __( 'Native view', 'doubleyoupi-refined-admin-dashboard' ),
				'redesignedView'    => __( 'Redesigned view', 'doubleyoupi-refined-admin-dashboard' ),
				'selectAll'         => __( 'Select all', 'doubleyoupi-refined-admin-dashboard' ),
				'switchToNative'    => __( 'Switch to native view', 'doubleyoupi-refined-admin-dashboard' ),
				'switchToReimagined'=> __( 'Switch to redesigned view', 'doubleyoupi-refined-admin-dashboard' ),
				'screenOptions'     => __( 'Screen options', 'doubleyoupi-refined-admin-dashboard' ),
				'columns'           => __( 'Columns', 'doubleyoupi-refined-admin-dashboard' ),
				/* translators: %d = number of columns shown */
				'colsCount'         => __( '%d columns', 'doubleyoupi-refined-admin-dashboard' ),
				/* translators: %d = recommended maximum number of columns */
				'colsAdvice'        => __( '%d recommended max for readability', 'doubleyoupi-refined-admin-dashboard' ),
				'pagination'        => __( 'Pagination', 'doubleyoupi-refined-admin-dashboard' ),
				'itemsPerPage'      => __( 'Items per page', 'doubleyoupi-refined-admin-dashboard' ),
				'expand'            => __( 'Expand', 'doubleyoupi-refined-admin-dashboard' ),
				'collapse'          => __( 'Collapse', 'doubleyoupi-refined-admin-dashboard' ),
				'moveUp'            => __( 'Move up', 'doubleyoupi-refined-admin-dashboard' ),
				'moveDown'          => __( 'Move down', 'doubleyoupi-refined-admin-dashboard' ),
				'reorder'           => __( 'Drag to reorder', 'doubleyoupi-refined-admin-dashboard' ),
				'moreActions'       => __( 'More actions', 'doubleyoupi-refined-admin-dashboard' ),
				'pluginAction'      => __( 'Plugin action', 'doubleyoupi-refined-admin-dashboard' ),
				'actions'           => __( 'Actions', 'doubleyoupi-refined-admin-dashboard' ),
				'filters'           => __( 'Filters', 'doubleyoupi-refined-admin-dashboard' ),
				'resetAll'          => __( 'Reset all', 'doubleyoupi-refined-admin-dashboard' ),
				'selected'          => __( 'selected', 'doubleyoupi-refined-admin-dashboard' ),
				'apply'             => __( 'Apply', 'doubleyoupi-refined-admin-dashboard' ),
				'add'               => __( 'Add New', 'doubleyoupi-refined-admin-dashboard' ),
				'search'            => __( 'Search…', 'doubleyoupi-refined-admin-dashboard' ),
				'all'               => __( 'All', 'doubleyoupi-refined-admin-dashboard' ),
				'reorderFailed'     => __( 'Reorder failed. Please refresh.', 'doubleyoupi-refined-admin-dashboard' ),
				'home'              => __( 'Home', 'doubleyoupi-refined-admin-dashboard' ),
				// Orders (HPOS) row actions.
				'preview'           => __( 'Preview', 'doubleyoupi-refined-admin-dashboard' ),
				'edit'              => __( 'Edit', 'doubleyoupi-refined-admin-dashboard' ),
				// Quick Edit inline.
				'title'             => __( 'Title', 'doubleyoupi-refined-admin-dashboard' ),
				'slug'              => __( 'Slug', 'doubleyoupi-refined-admin-dashboard' ),
				'status'            => __( 'Status', 'doubleyoupi-refined-admin-dashboard' ),
				'date'              => __( 'Date', 'doubleyoupi-refined-admin-dashboard' ),
				// Icon-only native heading (the comments bubble) needs a word.
				'comments'          => __( 'Comments', 'doubleyoupi-refined-admin-dashboard' ),
				/* translators: %s = column names added since the last visit */
				'newColumns'        => __( 'New columns: %s', 'doubleyoupi-refined-admin-dashboard' ),
				'cancel'            => __( 'Cancel', 'doubleyoupi-refined-admin-dashboard' ),
				'save'              => __( 'Save', 'doubleyoupi-refined-admin-dashboard' ),
				// Column sort (3-state cycle).
				'sortBy'            => __( 'Sort', 'doubleyoupi-refined-admin-dashboard' ),
				'sortAsc'           => __( 'Sorted ascending', 'doubleyoupi-refined-admin-dashboard' ),
				'sortDesc'          => __( 'Sorted descending', 'doubleyoupi-refined-admin-dashboard' ),
				// Plugins list (plugins.php). Row action labels come from the
				// native DOM; only the chrome is translated here.
				'update'            => __( 'Update', 'doubleyoupi-refined-admin-dashboard' ),
				'updating'          => __( 'Updating…', 'doubleyoupi-refined-admin-dashboard' ),
				'updated'           => __( 'Updated', 'doubleyoupi-refined-admin-dashboard' ),
				'updateFailed'      => __( 'Update failed', 'doubleyoupi-refined-admin-dashboard' ),
				'updateAvailable'   => __( 'Update available', 'doubleyoupi-refined-admin-dashboard' ),
				'updatesOne'        => __( '1 update available', 'doubleyoupi-refined-admin-dashboard' ),
				/* translators: %d = number of plugins with an update available */
				'updatesMany'       => __( '%d updates available', 'doubleyoupi-refined-admin-dashboard' ),
				'updateAll'         => __( 'Update all', 'doubleyoupi-refined-admin-dashboard' ),
				'autoUpdates'       => __( 'Auto-updates', 'doubleyoupi-refined-admin-dashboard' ),
				'more'              => __( 'More', 'doubleyoupi-refined-admin-dashboard' ),
				'noMatch'           => __( 'No plugin matches your search.', 'doubleyoupi-refined-admin-dashboard' ),
				'activeGroup'       => _x( 'Active', 'plugins list group', 'doubleyoupi-refined-admin-dashboard' ),
				'inactiveGroup'     => _x( 'Inactive', 'plugins list group', 'doubleyoupi-refined-admin-dashboard' ),
				'inactiveOne'       => _x( 'Inactive', 'one plugin', 'doubleyoupi-refined-admin-dashboard' ),
			],
		];
	}

	// =====================================================================
	//  Items per page = 50 (default, respecting the user's choice)
	// =====================================================================

	public function default_posts_per_page( $per_page, $post_type = 'post' ) {
		$opt = (int) get_user_option( 'edit_' . sanitize_key( (string) $post_type ) . '_per_page' );
		return $opt > 0 ? $per_page : self::DEFAULT_PER_PAGE;
	}

	public function default_users_per_page( $per_page ) {
		$opt = (int) get_user_option( 'users_per_page' );
		return $opt > 0 ? $per_page : self::DEFAULT_PER_PAGE;
	}

	public function default_tags_per_page( $per_page, $taxonomy = '' ) {
		$opt = (int) get_user_option( 'edit_' . sanitize_key( (string) $taxonomy ) . '_per_page' );
		return $opt > 0 ? $per_page : self::DEFAULT_PER_PAGE;
	}

	// =====================================================================
	//  REST — mode + options
	// =====================================================================

	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_MODE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_set_mode' ],
				'permission_callback' => [ $this, 'rest_permission' ],
				'args'                => [
					'signature' => [ 'required' => true, 'type' => 'string' ],
					'mode'      => [ 'required' => true, 'type' => 'string' ],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_OPTIONS,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_set_options' ],
				'permission_callback' => [ $this, 'rest_permission' ],
				'args'                => [
					'perPage'           => [ 'required' => false, 'type' => 'integer' ],
					'perPageOption'     => [ 'required' => false, 'type' => 'string' ],
					'hiddenColumns'     => [ 'required' => false, 'type' => 'array' ],
					'hiddenColumnsMeta' => [ 'required' => false, 'type' => 'string' ],
				],
			]
		);
	}

	/**
	 * Any logged-in user who can access the admin: they only modify
	 * THEIR OWN preferences (current user_meta / user_option).
	 */
	public function rest_permission(): bool {
		return current_user_can( 'read' );
	}

	public function handle_set_mode( \WP_REST_Request $request ): \WP_REST_Response {
		$signature = sanitize_text_field( (string) $request->get_param( 'signature' ) );
		$mode      = sanitize_key( (string) $request->get_param( 'mode' ) );
		$mode      = 'native' === $mode ? 'native' : 'reimagined';

		if ( '' === $signature || ! preg_match( '#^[A-Za-z0-9._/?=&%\-]+$#', $signature ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'code' => 'invalid_signature' ], 400 );
		}

		$map               = $this->read_modes();
		unset( $map[ $signature ] );
		$map[ $signature ] = $mode;
		// One entry per screen visited: keep the 200 most recent, never an unbounded map.
		$map = array_slice( $map, -200, null, true );
		update_user_meta( get_current_user_id(), self::META_MODES, wp_slash( wp_json_encode( $map ) ) );

		return new \WP_REST_Response( [ 'success' => true, 'mode' => $mode ], 200 );
	}

	public function handle_set_options( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$done    = [];

		// Items per page → native user option (key validated by regex).
		$per_page = (int) $request->get_param( 'perPage' );
		$option   = sanitize_text_field( (string) $request->get_param( 'perPageOption' ) );
		if ( $per_page > 0 && preg_match( '#^[a-z0-9_\-]+_per_page$#', $option ) ) {
			$per_page = max( 1, min( 999, $per_page ) );
			update_user_option( $user_id, $option, $per_page );
			$done['perPage'] = $per_page;
		}

		// Hidden columns → native WP meta `manage{screen}columnshidden`.
		$hidden_raw  = $request->get_param( 'hiddenColumns' );
		$hidden_meta = sanitize_text_field( (string) $request->get_param( 'hiddenColumnsMeta' ) );
		if ( is_array( $hidden_raw ) && preg_match( '#^manage[a-z0-9_\-]+columnshidden$#', $hidden_meta ) ) {
			$hidden = [];
			foreach ( $hidden_raw as $col ) {
				$col = sanitize_html_class( (string) $col );
				if ( '' !== $col ) {
					$hidden[] = $col;
				}
			}
			update_user_option( $user_id, $hidden_meta, array_values( array_unique( $hidden ) ) );
			$done['hiddenColumns'] = $hidden;
		}

		return new \WP_REST_Response( [ 'success' => true, 'applied' => $done ], 200 );
	}

	// =====================================================================
	//  Util
	// =====================================================================

	private function asset_version( string $abs_path ): string {
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

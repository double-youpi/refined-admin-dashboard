<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Core\Build;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PostOrdering — row drag & drop in WP-list-tables.
 *
 * Active on all post types that declare `supports => 'page-attributes'`
 * (pages + hierarchical CPTs + any opt-in CPT). The native WP `menu_order`
 * field can then be controlled directly from `edit.php` instead of the
 * "open each post → edit the Order field → save" flow.
 *
 * Three strategic invariants (see CLAUDE.md §2):
 *   1. No per-page setting — auto-detection via post_type_supports().
 *   2. Detect and step back — bail out if Intuitive Custom Post Order,
 *      Simple Page Ordering, Post Types Order, or NestedPages is active.
 *   3. Visually demonstrable — visible handle column, blue drop indicator,
 *      animation during drag.
 *
 * Behaviors:
 *   - Default sort forced to menu_order ASC on eligible screens
 *     (but we honour any `orderby` explicitly clicked by the user).
 *   - posts_per_page bumped to 200 by default to minimize pagination
 *     (drag is intra-page only).
 *   - Horizontal drag = parent change (hierarchical post types only).
 *     Threshold ~28px per level, calculated client-side.
 *
 * REST: POST /doubleyoupi/v1/admin/post-order — per-post-ID capability check,
 * cross-post-type validation, updates via wp_update_post() to trigger standard
 * WP hooks.
 */
final class PostOrdering {

	public const REST_NAMESPACE = 'doubleyoupi/v1';
	public const REST_ROUTE     = '/admin/post-order';
	public const FILTER_TYPES   = 'doubleyoupi_admin_post_ordering_post_types';
	public const FILTER_PERPAGE = 'doubleyoupi_admin_post_ordering_per_page';

	/**
	 * Competing plugins — if any is active, we step back entirely.
	 * Slug => human label (for logging and any future notice).
	 */
	private const COMPETING_PLUGINS = [
		'intuitive-custom-post-order/hicpo.php'        => 'Intuitive Custom Post Order',
		'simple-page-ordering/simple-page-ordering.php' => 'Simple Page Ordering',
		'post-types-order/post-types-order.php'         => 'Post Types Order',
		'nestedpages/nestedpages.php'                   => 'NestedPages',
	];

	private const DEFAULT_PER_PAGE = 50;

	public function boot(): void {
		// Bail silently if a competing plugin already handles this.
		if ( null !== $this->competing_plugin_active() ) {
			return;
		}

		add_action( 'pre_get_posts', [ $this, 'pre_get_posts' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Columns must be registered for each eligible post type BEFORE
		// `WP_List_Table` requests the list — so as early as `admin_init`.
		add_action( 'admin_init', [ $this, 'register_columns' ] );
	}

	// -------------------------------------------------------------------------
	// Competing plugin detection.
	// -------------------------------------------------------------------------

	/**
	 * Returns the label of the first active competing plugin, or null if none.
	 * Uses is_plugin_active() which requires wp-admin/includes/plugin.php.
	 */
	public function competing_plugin_active(): ?string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( self::COMPETING_PLUGINS as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				return $label;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Eligible post types.
	// -------------------------------------------------------------------------

	/**
	 * Post types with `supports => page-attributes`. Filterable.
	 *
	 * Consistent with the native WP criterion: only these post types expose
	 * a `menu_order` field in the editor, giving us a clear opt-in mechanism.
	 *
	 * @return string[] List of post type slugs.
	 */
	public function applicable_post_types(): array {
		$candidates = get_post_types( [ 'show_ui' => true ], 'names' );
		$result     = [];
		foreach ( $candidates as $slug ) {
			if ( post_type_supports( $slug, 'page-attributes' ) ) {
				$result[] = $slug;
			}
		}

		/**
		 * Filters the list of post types for which drag-ordering is active.
		 *
		 * @param string[] $result Default computed list.
		 */
		$filtered = apply_filters( self::FILTER_TYPES, $result ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- hook name is a doubleyoupi_-prefixed class constant.
		return is_array( $filtered ) ? array_values( array_unique( array_map( 'strval', $filtered ) ) ) : [];
	}

	/**
	 * Detects the post type of the current screen — or null if not an edit.php
	 * screen for an eligible post type.
	 */
	private function current_post_type(): ?string {
		if ( ! is_admin() ) {
			return null;
		}
		// $pagenow is not reliable at admin_enqueue_scripts time (not yet populated
		// for REST/AJAX requests), so we read $_SERVER directly.
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( 'edit.php' !== $script ) {
			return null;
		}
		$pt = isset( $_GET['post_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		return in_array( $pt, $this->applicable_post_types(), true ) ? $pt : null;
	}

	/**
	 * True if the user has not requested an explicit orderby other than menu_order.
	 */
	private function default_sort_active(): bool {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( (string) wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		$order   = isset( $_GET['order'] )   ? strtoupper( sanitize_key( (string) wp_unslash( $_GET['order'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		if ( '' === $orderby ) {
			return true; // no explicit sort → we are on the default sort (which we will force to menu_order).
		}
		return 'menu_order' === $orderby && ( '' === $order || 'ASC' === $order );
	}

	// -------------------------------------------------------------------------
	// pre_get_posts — sort + per_page override.
	// -------------------------------------------------------------------------

	public function pre_get_posts( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() ) {
			return;
		}
		$pt = $this->current_post_type();
		if ( null === $pt ) {
			return;
		}
		// Force the default sort only if the user has not clicked a column header.
		if ( ! isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
			$q->set( 'orderby', [ 'menu_order' => 'ASC', 'title' => 'ASC' ] );
			$q->set( 'order', 'ASC' );
		}
		// Bump posts_per_page only if the user has no custom value,
		// whether via the URL (?posts_per_page=) or via the native screen option
		// (`edit_{$pt}_per_page`, written by the reimagined screen-options cog).
		// We therefore honour a user choice even on orderable post types.
		$user_per_page = isset( $_GET['posts_per_page'] ) ? (int) $_GET['posts_per_page'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		if ( $user_per_page <= 0 ) {
			$user_per_page = (int) get_user_option( 'edit_' . $pt . '_per_page' );
		}
		if ( $user_per_page <= 0 ) {
			$default = (int) apply_filters( self::FILTER_PERPAGE, self::DEFAULT_PER_PAGE, $pt ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- hook name is a doubleyoupi_-prefixed class constant.
			if ( $default > 0 ) {
				$q->set( 'posts_per_page', $default );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Handle column.
	// -------------------------------------------------------------------------

	public function register_columns(): void {
		foreach ( $this->applicable_post_types() as $pt ) {
			add_filter( "manage_edit-{$pt}_columns", [ $this, 'inject_column' ] );
			add_filter( "manage_{$pt}_posts_columns", [ $this, 'inject_column' ] );
			add_action( "manage_{$pt}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
		}
	}

	/**
	 * Inserts the `dy-order-handle` column at the front (before `cb`).
	 */
	public function inject_column( $columns ): array {
		if ( ! is_array( $columns ) ) {
			return [];
		}
		$handle = [
			'dy-order-handle' => '<span class="screen-reader-text">' . esc_html__( 'Reorder', 'doubleyoupi-refined-admin-dashboard' ) . '</span>',
		];
		return $handle + $columns;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'dy-order-handle' !== $column ) {
			return;
		}
		// The button carries aria-label + class; the SVG <use> points to the
		// admin Sprite icon. tabindex=-1: drag is pointer-driven, not keyboard
		// (a future keyboard upgrade will add aria-grabbed + arrow keys).
		echo '<button type="button" class="dy-order-handle" aria-label="' . esc_attr__( 'Drag to reorder', 'doubleyoupi-refined-admin-dashboard' ) . '" tabindex="-1" data-dy-post="' . esc_attr( (string) $post_id ) . '">';
		echo '<svg width="14" height="14" aria-hidden="true" focusable="false"><use href="#i-grip"></use></svg>';
		echo '</button>';
	}

	// -------------------------------------------------------------------------
	// Assets.
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$pt = $this->current_post_type();
		if ( null === $pt ) {
			return;
		}

		$pt_obj       = get_post_type_object( $pt );
		$hierarchical = $pt_obj instanceof \WP_Post_Type ? (bool) $pt_obj->hierarchical : false;
		$can_edit     = $pt_obj instanceof \WP_Post_Type ? current_user_can( $pt_obj->cap->edit_posts ) : false;

		$js = Build::dir( __FILE__, 'assets/js/modules/admin/post-ordering.js' );
		if ( ! file_exists( $js ) ) {
			return;
		}

		wp_enqueue_script(
			'doubleyoupi-admin-post-ordering',
			Build::url( __FILE__, 'assets/js/modules/admin/post-ordering.js' ),
			[ 'wp-api-fetch' ],
			DOUBLEYOUPI_VERSION,
			true
		);

		wp_localize_script(
			'doubleyoupi-admin-post-ordering',
			'doubleyoupiPostOrdering',
			[
				'restRoot'     => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'postType'     => $pt,
				'hierarchical' => $hierarchical,
				'defaultSort'  => $this->default_sort_active(),
				'canEdit'      => $can_edit,
				'indentStep'   => 28,
				'i18n'         => [
					'saveFailed' => __( 'Reorder failed. Please refresh.', 'doubleyoupi-refined-admin-dashboard' ),
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// REST endpoint.
	// -------------------------------------------------------------------------

	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_reorder' ],
				'permission_callback' => [ $this, 'rest_permission' ],
				'args'                => [
					'post_type' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'items'     => [
						'required' => true,
						'type'     => 'array',
					],
				],
			]
		);
	}

	/**
	 * Capability check at the endpoint level: the user must be able to edit
	 * the post type in general. The per-ID fine-grained check happens in handle_reorder().
	 */
	public function rest_permission( \WP_REST_Request $request ) {
		$pt     = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$pt_obj = get_post_type_object( $pt );
		if ( ! $pt_obj instanceof \WP_Post_Type ) {
			return new \WP_Error( 'doubleyoupi_invalid_post_type', __( 'Unknown post type.', 'doubleyoupi-refined-admin-dashboard' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $pt, $this->applicable_post_types(), true ) ) {
			return new \WP_Error( 'doubleyoupi_post_type_not_orderable', __( 'This post type is not orderable.', 'doubleyoupi-refined-admin-dashboard' ), [ 'status' => 403 ] );
		}
		if ( ! current_user_can( $pt_obj->cap->edit_posts ) ) {
			return new \WP_Error( 'doubleyoupi_forbidden', __( 'You cannot edit this post type.', 'doubleyoupi-refined-admin-dashboard' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Applies the new order. Response:
	 *   { success: bool, updated: int[], errors: array<{ id:int, code:string }> }
	 */
	public function handle_reorder( \WP_REST_Request $request ) {
		$pt    = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$items = (array) $request->get_param( 'items' );

		if ( empty( $items ) ) {
			return new \WP_REST_Response( [ 'success' => true, 'updated' => [], 'errors' => [] ], 200 );
		}

		$pt_obj       = get_post_type_object( $pt );
		$hierarchical = $pt_obj instanceof \WP_Post_Type ? (bool) $pt_obj->hierarchical : false;

		$updated = [];
		$errors  = [];

		foreach ( $items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id     = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$order  = isset( $row['order'] ) ? (int) $row['order'] : 0;
			$parent = $hierarchical && isset( $row['parent'] ) ? (int) $row['parent'] : null;

			if ( $id <= 0 ) {
				$errors[] = [ 'id' => $id, 'code' => 'invalid_id' ];
				continue;
			}

			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				$errors[] = [ 'id' => $id, 'code' => 'not_found' ];
				continue;
			}
			if ( $post->post_type !== $pt ) {
				$errors[] = [ 'id' => $id, 'code' => 'cross_post_type' ];
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$errors[] = [ 'id' => $id, 'code' => 'forbidden' ];
				continue;
			}

			$update = [
				'ID'         => $id,
				'menu_order' => $order,
			];
			if ( null !== $parent ) {
				// Prevent a cycle: a post cannot be its own parent.
				if ( $parent === $id ) {
					$errors[] = [ 'id' => $id, 'code' => 'self_parent' ];
					continue;
				}
				$update['post_parent'] = max( 0, $parent );
			}

			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				$errors[] = [ 'id' => $id, 'code' => 'update_failed' ];
				continue;
			}
			$updated[] = $id;
		}

		return new \WP_REST_Response(
			[
				'success' => empty( $errors ),
				'updated' => $updated,
				'errors'  => $errors,
			],
			200
		);
	}
}

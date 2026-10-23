<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard;

use DoubleYoupi\Modules\Admin\Dashboard\Layout\Dispositions;
use DoubleYoupi\Modules\Admin\Dashboard\Layout\LayoutStorage;
use DoubleYoupi\Modules\Admin\Dashboard\Layout\LayoutValidator;
use DoubleYoupi\Modules\Admin\Dashboard\Layout\Snapshot;
use DoubleYoupi\Modules\Admin\Dashboard\Renderer;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\WidgetRegistry;
use DoubleYoupi\Admin\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API for the modular v4 Dashboard.
 *
 * Endpoints:
 *   GET    /doubleyoupi/v1/dashboard/snapshot      → full snapshot (layout + data + catalog + presets)
 *   POST   /doubleyoupi/v1/dashboard/layout        → saves the user layout (v4 payload expected)
 *   DELETE /doubleyoupi/v1/dashboard/layout        → reset to the standard preset
 *   POST   /doubleyoupi/v1/dashboard/widget/render → renders a single widget (drag&drop without reload)
 *
 * Security: all endpoints require a logged-in user. The capability
 * `doubleyoupi_customize_dashboard` (or fallback manage_options) is required
 * for mutations.
 *
 * Back-compat: a v3 payload sent via POST /layout is automatically migrated
 * to v4 by LayoutValidator before validation (see Layout\Migration).
 */
final class RestController {

	public const NAMESPACE = 'doubleyoupi/v1';
	public const REST_BASE = 'dashboard';

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/snapshot',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'get_snapshot' ],
				'permission_callback' => [ self::class, 'can_read' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/layout',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'save_layout' ],
					'permission_callback' => [ self::class, 'can_customize' ],
					'args'                => [
						'layout' => [
							'required' => true,
							'type'     => 'object',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'reset_layout' ],
					'permission_callback' => [ self::class, 'can_customize' ],
				],
			]
		);

		// Dispositions saved by the administrator (manage_options).
		//   POST   /dashboard/disposition  → saves the current layout under a name
		//   DELETE /dashboard/disposition  → deletes a custom disposition
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/disposition',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'save_disposition' ],
					'permission_callback' => [ self::class, 'can_manage' ],
					'args'                => [
						'name'   => [ 'type' => 'string', 'default' => '' ],
						'slug'   => [ 'type' => 'string', 'default' => '' ],
						'layout' => [ 'required' => true, 'type' => 'object' ],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'delete_disposition' ],
					'permission_callback' => [ self::class, 'can_manage' ],
					'args'                => [
						'slug' => [ 'required' => true, 'type' => 'string' ],
					],
				],
			]
		);

		// Assignment of a disposition to roles (administrator).
		//   POST /dashboard/disposition/assign  { slug, roles: [...] }
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/disposition/assign',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'assign_disposition' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'slug'  => [ 'required' => true, 'type' => 'string' ],
					'roles' => [ 'type' => 'array', 'default' => [] ],
				],
			]
		);

		// Layout restoration (Undo/Redo): validates, saves, AND returns the full
		// server-rendered screen HTML. Allows the history to re-render the dashboard
		// WITHOUT reloading the page (see History.rerender).
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/restore',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'restore_layout' ],
				'permission_callback' => [ self::class, 'can_customize' ],
				'args'                => [
					'layout' => [
						'required' => true,
						'type'     => 'object',
					],
				],
			]
		);

		// Server-side rendering of a single widget — used after adding one from
		// the Customize panel to avoid a full page reload.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/widget/render',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'render_widget' ],
				// Only the Customize panel adds widgets.
				'permission_callback' => [ self::class, 'can_customize' ],
				'args'                => [
					'id'     => [ 'required' => true, 'type' => 'string' ],
					'config' => [ 'type' => 'object', 'default' => [] ],
				],
			]
		);
	}

	/**
	 * Read: any logged-in user who can view the dashboard.
	 */
	public static function can_read( \WP_REST_Request $request ): bool {
		unset( $request );
		return is_user_logged_in() && current_user_can( 'read' );
	}

	/**
	 * Write (customize the dashboard, save the layout).
	 *
	 * Permission model (validated):
	 *   - CUSTOMIZE: administrators by default (manage_options). An admin can
	 *     delegate to a trusted role by granting the capability
	 *     `doubleyoupi_customize_dashboard` (optional escape hatch).
	 *   - MANAGE dispositions / assign to roles / lock: strict admin
	 *     (manage_options, see can_manage).
	 *   - NON-ADMIN without the cap: READ-ONLY dashboard — receives the
	 *     disposition assigned to their role (RoleAssignments) or the default,
	 *     and their widgets are filtered by capability (required_cap). The
	 *     "Customize" button is not even rendered (Renderer::render_customize_button).
	 */
	public static function can_customize( \WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'doubleyoupi_customize_dashboard' )
			|| current_user_can( 'manage_options' );
	}

	/**
	 * Management of shared dispositions: reserved for administrators.
	 */
	public static function can_manage( \WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( Access::CAP );
	}

	public static function get_snapshot( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$user_id = get_current_user_id();
		return rest_ensure_response( Snapshot::build( $user_id ) );
	}

	public static function save_layout( \WP_REST_Request $request ) {
		$payload = $request->get_param( 'layout' );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'invalid_payload',
				__( 'The layout must be an object.', 'doubleyoupi-refined-admin-dashboard' ),
				[ 'status' => 400 ]
			);
		}

		$validated = LayoutValidator::validate( $payload );
		if ( is_wp_error( $validated ) ) {
			return new \WP_Error(
				$validated->get_error_code(),
				$validated->get_error_message(),
				[ 'status' => 400 ]
			);
		}

		$user_id = get_current_user_id();
		$ok      = LayoutStorage::set_for_user( $user_id, $validated );

		return rest_ensure_response( [
			'saved'  => $ok,
			'layout' => $validated,
		] );
	}

	public static function reset_layout( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$user_id = get_current_user_id();
		LayoutStorage::delete_for_user( $user_id );
		// Return a fresh snapshot (which will fall back to the standard preset).
		return rest_ensure_response( Snapshot::build( $user_id ) );
	}

	/**
	 * Restores a layout (Undo/Redo): validates, persists for the user, then
	 * returns the full server-rendered screen HTML + widget_data, so the client
	 * can re-render the dashboard without a page reload.
	 *
	 * Response:
	 *   - html        : string (markup from `render_layout`, already escaped)
	 *   - layout      : array  (validated layout)
	 *   - widget_data : array  (data used during rendering)
	 */
	public static function restore_layout( \WP_REST_Request $request ) {
		$payload = $request->get_param( 'layout' );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'invalid_payload', __( 'The layout must be an object.', 'doubleyoupi-refined-admin-dashboard' ), [ 'status' => 400 ] );
		}

		$validated = LayoutValidator::validate( $payload );
		if ( is_wp_error( $validated ) ) {
			return new \WP_Error( $validated->get_error_code(), $validated->get_error_message(), [ 'status' => 400 ] );
		}

		$user_id = get_current_user_id();
		LayoutStorage::set_for_user( $user_id, $validated );

		// widget_data: same logic as Snapshot::build, restricted to the widgets
		// actually present in the restored layout.
		$registry    = WidgetRegistry::instance();
		$widget_data = [];
		foreach ( $validated['screens'] as $screen ) {
			foreach ( (array) ( $screen['widgets'] ?? [] ) as $widget ) {
				$instance = $registry->get( $widget['id'] ?? '' );
				if ( ! $instance ) {
					continue;
				}
				$key                 = $widget['instance'] ?? $widget['id'];
				$config              = $instance->validate_config( $widget['config'] ?? [] );
				$widget_data[ $key ] = $instance->get_data( $config );
			}
		}

		$html = Renderer::render_layout( $validated, $widget_data );

		return rest_ensure_response( [
			'html'        => $html,
			'layout'      => $validated,
			'widget_data' => $widget_data,
		] );
	}

	/**
	 * Saves the current layout as a named (reusable) disposition.
	 * The layout is validated before storage.
	 */
	public static function save_disposition( \WP_REST_Request $request ) {
		$name    = (string) $request->get_param( 'name' );
		$slug    = sanitize_key( (string) $request->get_param( 'slug' ) );
		$payload = $request->get_param( 'layout' );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'invalid_payload', __( 'The layout must be an object.', 'doubleyoupi-refined-admin-dashboard' ), [ 'status' => 400 ] );
		}

		$validated = LayoutValidator::validate( $payload );
		if ( is_wp_error( $validated ) ) {
			return new \WP_Error( $validated->get_error_code(), $validated->get_error_message(), [ 'status' => 400 ] );
		}

		// Slug provided → overwrite an existing disposition; otherwise, create new.
		$saved = $slug !== ''
			? Dispositions::update( $slug, $validated )
			: Dispositions::save( $name, $validated );

		if ( is_wp_error( $saved ) ) {
			return new \WP_Error( $saved->get_error_code(), $saved->get_error_message(), [ 'status' => 400 ] );
		}

		return rest_ensure_response( [
			'saved'        => true,
			'disposition'  => $saved,
			'dispositions' => Dispositions::catalog(),
		] );
	}

	/**
	 * Assigns a disposition to a set of roles. The client handles conflict
	 * confirmation (a role already assigned elsewhere) BEFORE calling; server-side
	 * we simply overwrite (one role = one disposition).
	 *
	 * Response: { assignments: {role=>slug}, roles_for_slug: [...] }
	 */
	public static function assign_disposition( \WP_REST_Request $request ) {
		$slug  = sanitize_key( (string) $request->get_param( 'slug' ) );
		$roles = (array) $request->get_param( 'roles' );

		if ( $slug === '' || \DoubleYoupi\Modules\Admin\Dashboard\Layout\Dispositions::layout_for_slug( $slug ) === null ) {
			return new \WP_Error( 'unknown_disposition', __( 'Unknown disposition.', 'doubleyoupi-refined-admin-dashboard' ), [ 'status' => 400 ] );
		}

		$map = \DoubleYoupi\Modules\Admin\Dashboard\Layout\RoleAssignments::assign( $slug, $roles );

		return rest_ensure_response( [
			'assigned'       => true,
			'assignments'    => $map,
			'roles_for_slug' => \DoubleYoupi\Modules\Admin\Dashboard\Layout\RoleAssignments::roles_for( $slug ),
		] );
	}

	/**
	 * Deletes a saved custom disposition.
	 */
	public static function delete_disposition( \WP_REST_Request $request ): \WP_REST_Response {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		// Built-in disposition → hide it (no code deletion);
		// user disposition → real deletion. See Dispositions::hide_builtin.
		$ok = Dispositions::is_builtin( $slug )
			? Dispositions::hide_builtin( $slug )
			: Dispositions::delete( $slug );
		return rest_ensure_response( [
			'deleted'      => $ok,
			'dispositions' => Dispositions::catalog(),
			// Deletion also purges role assignments → return the updated map so
			// the UI can re-synchronise.
			'assignments'  => \DoubleYoupi\Modules\Admin\Dashboard\Layout\RoleAssignments::all(),
		] );
	}

	/**
	 * Renders a single widget server-side (HTML + data). Allows the Customize
	 * panel to add a widget without reloading the page.
	 *
	 * Payload:
	 *   - id     : string (registered widget ID)
	 *   - config : array  (initial config, validated by the widget itself)
	 *
	 * Response:
	 *   - html   : string (rendered HTML, already escaped)
	 *   - data   : array  (data used during rendering)
	 *   - config : array  (sanitised config)
	 */
	public static function render_widget( \WP_REST_Request $request ) {
		$id = sanitize_key( (string) $request->get_param( 'id' ) );
		if ( $id === '' ) {
			return new \WP_Error(
				'invalid_id',
				__( 'Widget ID required.', 'doubleyoupi-refined-admin-dashboard' ),
				[ 'status' => 400 ]
			);
		}

		$instance = WidgetRegistry::instance()->get( $id );
		if ( ! $instance ) {
			return new \WP_Error(
				'unknown_widget',
				// translators: %s is the widget identifier that could not be found.
				sprintf( __( 'Unknown widget « %s ».', 'doubleyoupi-refined-admin-dashboard' ), $id ),
				[ 'status' => 404 ]
			);
		}

		// Same rule as the dashboard itself: a widget reserved to a capability
		// (WooCommerce revenue, site health…) is never rendered for anyone else.
		if ( ! method_exists( $instance, 'is_visible_to_current_user' ) || ! $instance->is_visible_to_current_user() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to see this widget.', 'doubleyoupi-refined-admin-dashboard' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		$raw_config = $request->get_param( 'config' );
		$raw_config = is_array( $raw_config ) ? $raw_config : [];
		$config     = $instance->validate_config( $raw_config );
		try {
			$data = $instance->get_data( $config );
			$html = $instance->render_html( $config, $data );
		} catch ( \Throwable $e ) {
			Snapshot::log_failure( $instance->id(), $e );
			return new \WP_Error( 'doubleyoupi_widget_failed', __( 'This widget could not load.', 'doubleyoupi-refined-admin-dashboard' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'html'   => $html,
			'data'   => $data,
			'config' => $config,
		] );
	}
}

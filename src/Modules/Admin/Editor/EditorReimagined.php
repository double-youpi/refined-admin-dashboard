<?php
/**
 * EditorReimagined — UX/UI redesign of the CLASSIC editing screen
 * (post.php?action=edit & post-new.php), direction "Atelier".
 *
 * Principle (same as TablesReimagined): we do NOT touch the DOM server-side.
 * We set a body class, relocate the title / permalink / actions into a sticky
 * command bar via JS, transform the sidebar column into a collapsible inspector,
 * and skin the editor + ACF fields in CSS. The "native / redesigned" toggle
 * is persisted per user.
 *
 * IMPORTANT: activates ONLY on the classic editor (TinyMCE), never on
 * Gutenberg (left to its own UX), nor on ACF field-group screens.
 *
 * @package DoubleYoupi\Modules\Admin\Editor
 */

namespace DoubleYoupi\Modules\Admin\Editor;

use DoubleYoupi\Core\Build;

defined( 'ABSPATH' ) || exit;

final class EditorReimagined {

	public const REST_NAMESPACE  = 'doubleyoupi/v1';
	public const ROUTE_MODE      = '/admin/editor/mode';
	public const ROUTE_INSPECTOR = '/admin/editor/inspector';

	/** User meta: map { signature => 'native'|'reimagined' }. */
	public const META_MODES     = 'doubleyoupi_editor_modes';
	/** User meta: 'open' | 'collapsed' (global inspector preference). */
	public const META_INSPECTOR = 'doubleyoupi_editor_inspector';

	private ?array $screen_cache = null;
	private bool $screen_resolved = false;

	public function boot(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_action( 'edit_form_top', [ $this, 'print_skeleton' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Loading skeleton, printed at the top of form#post so it is painted with
	 * the first frame (the redesigned form stays transparent until the editor
	 * is ready, see "Loading" in _editor-reimagined.scss). Decorative only:
	 * hidden from assistive tech, inert, and `display:none` without JS.
	 */
	public function print_skeleton(): void {
		$screen = $this->target_screen();
		if ( null === $screen || 'reimagined' !== $this->get_mode( $screen['signature'] ) ) {
			return;
		}
		$has_editor = post_type_supports( $screen['postType'], 'editor' );
		$html       = '<div class="dy-ed-skel' . ( $has_editor ? '' : ' dy-ed-skel--no-editor' ) . '" aria-hidden="true" inert>'
			. '<div class="dy-ed-skel__bar"><i></i><i></i><i></i></div>'
			. '<div class="dy-ed-skel__grid">'
			. '<div class="dy-ed-skel__card"><div class="dy-ed-skel__tools"></div><div class="dy-ed-skel__lines">'
			. str_repeat( '<i></i>', $has_editor ? 10 : 4 )
			. '</div></div>'
			. '<div class="dy-ed-skel__card dy-ed-skel__side">' . str_repeat( '<i></i>', 7 ) . '</div>'
			. '</div></div>';
		echo wp_kses(
			$html,
			[
				'div' => [
					'class'       => true,
					'aria-hidden' => true,
					'inert'       => true,
				],
				'i'   => [],
			]
		);
	}

	// =====================================================================
	//  Target screen detection
	// =====================================================================

	/**
	 * @return array{postType:string,hierarchical:bool,supportsThumbnail:bool,isNew:bool,signature:string}|null
	 */
	private function target_screen(): ?array {
		if ( $this->screen_resolved ) {
			return $this->screen_cache;
		}
		$this->screen_resolved = true;
		$this->screen_cache    = null;

		if ( ! is_admin() ) {
			return null;
		}
		global $pagenow;
		$base = is_string( $pagenow ) ? $pagenow : '';
		if ( 'post.php' !== $base && 'post-new.php' !== $base ) {
			return null;
		}

		// The current screen must exist and be the classic editor (not the block editor).
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base ) {
			return null;
		}
		// Gutenberg → step back (its own UX). Robust detection.
		if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return null;
		}

		$pt = (string) $screen->post_type;
		if ( '' === $pt ) {
			$pt = isset( $_GET['post_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		}
		$pt_obj = get_post_type_object( $pt );
		if ( ! $pt_obj instanceof \WP_Post_Type || ! $pt_obj->show_ui ) {
			return null;
		}
		// Exclude ACF technical screens (field group / post) — not content.
		if ( 0 === strpos( $pt, 'acf-' ) ) {
			return null;
		}

		$this->screen_cache = [
			'postType'          => $pt,
			'hierarchical'      => (bool) $pt_obj->hierarchical,
			'supportsThumbnail' => post_type_supports( $pt, 'thumbnail' ),
			'isNew'             => ( 'post-new.php' === $base ),
			'signature'         => 'post:' . $pt,
		];
		return $this->screen_cache;
	}

	// =====================================================================
	//  Body class + persistance
	// =====================================================================

	public function body_class( string $classes ): string {
		$screen = $this->target_screen();
		if ( null === $screen ) {
			return $classes;
		}
		$mode = $this->get_mode( $screen['signature'] );
		$classes .= ' dy-editor dy-editor-' . $mode;
		if ( 'reimagined' === $mode && 'collapsed' === $this->get_inspector_state() ) {
			$classes .= ' dy-ed-inspector-collapsed';
		}
		return $classes;
	}

	private function get_mode( string $signature ): string {
		$map  = $this->read_modes();
		$mode = $map[ $signature ] ?? 'reimagined';
		return 'native' === $mode ? 'native' : 'reimagined';
	}

	private function get_inspector_state(): string {
		$raw = get_user_meta( get_current_user_id(), self::META_INSPECTOR, true );
		return 'collapsed' === $raw ? 'collapsed' : 'open';
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
		$js = Build::dir( __FILE__, 'assets/js/modules/admin/editor-reimagined.js' );
		if ( ! file_exists( $js ) ) {
			return;
		}
		wp_enqueue_script(
			'doubleyoupi-editor-reimagined',
			Build::url( __FILE__, 'assets/js/modules/admin/editor-reimagined.js' ),
			[ 'wp-api-fetch' ],
			$this->asset_version( $js ),
			true
		);
		wp_localize_script(
			'doubleyoupi-editor-reimagined',
			'doubleyoupiEditor',
			[
				'rest'      => [
					'mode'      => esc_url_raw( rest_url( self::REST_NAMESPACE . self::ROUTE_MODE ) ),
					'inspector' => esc_url_raw( rest_url( self::REST_NAMESPACE . self::ROUTE_INSPECTOR ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
				],
				'signature' => $screen['signature'],
				'mode'      => $this->get_mode( $screen['signature'] ),
				'inspector' => $this->get_inspector_state(),
				'screen'    => [
					'postType'          => $screen['postType'],
					'hierarchical'      => $screen['hierarchical'],
					'supportsThumbnail' => $screen['supportsThumbnail'],
					'isNew'             => $screen['isNew'],
				],
				'i18n'      => $this->i18n_strings(),
			]
		);
	}

	/** @return array<string,mixed> */
	private function i18n_strings(): array {
		return [
			'nativeView'     => __( 'Native view', 'doubleyoupi-refined-admin-dashboard' ),
			'redesignedView' => __( 'Redesigned view', 'doubleyoupi-refined-admin-dashboard' ),
			'settings'       => __( 'Settings', 'doubleyoupi-refined-admin-dashboard' ),
			'publishOptions' => __( 'Publishing options', 'doubleyoupi-refined-admin-dashboard' ),
			'collapse'       => __( 'Collapse panel', 'doubleyoupi-refined-admin-dashboard' ),
			'expand'         => __( 'Expand panel', 'doubleyoupi-refined-admin-dashboard' ),
			'permalink'      => __( 'Permalink', 'doubleyoupi-refined-admin-dashboard' ),
			'titlePlaceholder' => __( 'Add a title', 'doubleyoupi-refined-admin-dashboard' ),
			'switchToNative'    => __( 'Switch to native view', 'doubleyoupi-refined-admin-dashboard' ),
			'switchToReimagined' => __( 'Switch to redesigned view', 'doubleyoupi-refined-admin-dashboard' ),
			'statuses'       => [
				'publish' => __( 'Published', 'doubleyoupi-refined-admin-dashboard' ),
				'future'  => __( 'Scheduled', 'doubleyoupi-refined-admin-dashboard' ),
				'draft'   => __( 'Draft', 'doubleyoupi-refined-admin-dashboard' ),
				'pending' => __( 'Pending', 'doubleyoupi-refined-admin-dashboard' ),
				'private' => __( 'Private', 'doubleyoupi-refined-admin-dashboard' ),
				'auto-draft' => __( 'Draft', 'doubleyoupi-refined-admin-dashboard' ),
			],
			'sections'       => [
				// fallback labels (the visible title remains that of the WP metabox)
				'publish'  => __( 'Publishing options', 'doubleyoupi-refined-admin-dashboard' ),
			],
		];
	}

	// =====================================================================
	//  REST
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
			self::ROUTE_INSPECTOR,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_set_inspector' ],
				'permission_callback' => [ $this, 'rest_permission' ],
				'args'                => [
					'state' => [ 'required' => true, 'type' => 'string' ],
				],
			]
		);
	}

	public function rest_permission(): bool {
		return current_user_can( 'read' );
	}

	public function handle_set_mode( \WP_REST_Request $request ): \WP_REST_Response {
		$signature = sanitize_text_field( (string) $request->get_param( 'signature' ) );
		$mode      = sanitize_key( (string) $request->get_param( 'mode' ) );
		$mode      = 'native' === $mode ? 'native' : 'reimagined';
		if ( '' === $signature || ! preg_match( '#^[A-Za-z0-9._:/?=&%\-]+$#', $signature ) ) {
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

	public function handle_set_inspector( \WP_REST_Request $request ): \WP_REST_Response {
		$state = sanitize_key( (string) $request->get_param( 'state' ) );
		$state = 'collapsed' === $state ? 'collapsed' : 'open';
		update_user_meta( get_current_user_id(), self::META_INSPECTOR, $state );
		return new \WP_REST_Response( [ 'success' => true, 'state' => $state ], 200 );
	}

	// =====================================================================
	//  Utilitaires
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

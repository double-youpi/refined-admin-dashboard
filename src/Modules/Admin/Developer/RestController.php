<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Developer;

use DoubleYoupi\Modules\Admin\ColumnModel;
use DoubleYoupi\Modules\Admin\NavigationDetector;
use DoubleYoupi\Modules\Admin\NavigationOverrides;
use DoubleYoupi\Modules\Admin\PluginMap;
use DoubleYoupi\Modules\Admin\Rail;
use DoubleYoupi\Modules\Admin\ScreenDisabler;
use DoubleYoupi\Modules\Admin\Sprite;
use DoubleYoupi\Admin\Access;
// ScreenDisabler kept: used by save/reset_disabled_screens below.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API for the "Developer" tab of the DY Admin settings.
 *
 * Endpoints (namespace `doubleyoupi/v1`):
 *
 *   GET    /admin/navigation/snapshot      → live tree + overrides + detected + disabled
 *   POST   /admin/navigation/overrides     → saves the overrides object
 *   DELETE /admin/navigation/overrides     → resets to defaults
 *   POST   /admin/screens/disabled         → saves the list of sub-pages
 *                                            where DY styling is OFF
 *   DELETE /admin/screens/disabled         → reset (all pages re-styled)
 *
 * Security: `manage_options` required on all endpoints (advanced admin feature).
 * No custom capability — pure admin assumed.
 *
 * The snapshot returned by GET contains EVERYTHING the editor needs to operate
 * (no secondary calls): the live Rail collection, the subchipbar for each chip,
 * the list of detected plugins, the list of toggleable sub-pages with their
 * current state.
 */
final class RestController {

	public const NAMESPACE = 'doubleyoupi/v1';

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/navigation/snapshot',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'get_snapshot' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/navigation/overrides',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'save_overrides' ],
					'permission_callback' => [ self::class, 'can_manage' ],
					'args'                => [
						'overrides' => [ 'required' => true, 'type' => 'object' ],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'reset_overrides' ],
					'permission_callback' => [ self::class, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/screens/disabled',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'save_disabled_screens' ],
					'permission_callback' => [ self::class, 'can_manage' ],
					'args'                => [
						'signatures' => [ 'required' => true, 'type' => 'array' ],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'reset_disabled_screens' ],
					'permission_callback' => [ self::class, 'can_manage' ],
				],
			]
		);
	}

	public static function can_manage( \WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( Access::CAP );
	}

	// -------------------------------------------------------------------------
	// Snapshot — complete payload for the editor.
	// -------------------------------------------------------------------------

	public static function get_snapshot( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		return rest_ensure_response( self::build_snapshot() );
	}

	/**
	 * Builds the snapshot payload. Public method so it can also be inlined
	 * into the page server-side (avoids a REST round-trip on the editor's first
	 * paint).
	 *
	 * @return array<string, mixed>
	 */
	public static function build_snapshot(): array {
		// The rail must be collected to populate $menu/$submenu + NavigationDetector,
		// but the tree comes from ColumnModel (shared source of truth).
		$rail = new Rail();
		$rail->collect();
		ColumnModel::flush_cache();

		// Always the reimagined tree: that is what the editor edits, even
		// while the site shows the native tree (the layout applies again
		// as soon as the site switches back).
		$columns = ColumnModel::columns( false );

		// Also rebuilds the legacy `lines` format (eventual compat) from the
		// line/custom columns — the v2 editor consumes `columns`.
		$overrides = NavigationOverrides::get();

		// Hidden: chips + hidden sub-pages, enriched with a readable label.
		$hidden_chips = [];
		foreach ( (array) $overrides['hidden'] as $slug ) {
			$slug          = (string) $slug;
			$meta          = self::lookup_any_label( $slug, $columns );
			$hidden_chips[] = [ 'slug' => $slug, 'label' => $meta ];
		}
		$hidden_subs = [];
		foreach ( (array) $overrides['subitem_hidden'] as $key ) {
			$key = (string) $key;
			if ( ! str_contains( $key, '::' ) ) {
				continue;
			}
			[ $parent, $child ] = explode( '::', $key, 2 );
			$hidden_subs[] = [ 'key' => $key, 'parent' => $parent, 'child' => $child ];
		}

		// Detected: unknown plugins captured by NavigationDetector.
		$detected = [];
		foreach ( NavigationDetector::get_all() as $slug => $meta ) {
			$detected[] = [
				'slug'  => (string) $slug,
				'label' => (string) ( $meta['label'] ?? $slug ),
				'guess' => (string) ( $meta['guess'] ?? 'system' ),
			];
		}

		// Classification targets (lines + custom groups) with their label,
		// for the "assign" menu in the Detected drawer and the selects.
		$targets = [];
		foreach ( $columns as $col ) {
			if ( in_array( $col['kind'], [ 'line', 'custom' ], true ) ) {
				$targets[] = [ 'id' => $col['id'], 'label' => $col['label'] ];
			}
		}

		return [
			'version'           => NavigationOverrides::VERSION,
			'columns'           => $columns,
			'targets'           => $targets,
			'icon_choices'      => Sprite::pickable_icons(),
			'line_labels'       => ColumnModel::line_labels(),
			'hidden'            => [ 'chips' => $hidden_chips, 'subitems' => $hidden_subs ],
			'detected'          => $detected,
			// The rail separator: where it sits now, and whether the editor chose it.
			'divider_before'    => ColumnModel::divider_before( $columns ) ?? 'end',
			'divider_override'  => NavigationOverrides::divider_before(),
			'disabled_screens'  => ScreenDisabler::get_disabled(),
			'overrides'         => $overrides,
			'available_lines'   => PluginMap::line_order(),
		];
	}

	/**
	 * Looks up a readable label for a slug among the columns (chips/subs),
	 * falling back to the slug itself. Used to display hidden items.
	 *
	 * @param array<int,array<string,mixed>> $columns
	 */
	private static function lookup_any_label( string $slug, array $columns ): string {
		foreach ( $columns as $col ) {
			foreach ( (array) ( $col['chips'] ?? [] ) as $chip ) {
				if ( ( $chip['slug'] ?? '' ) === $slug ) {
					return (string) $chip['label'];
				}
				foreach ( (array) ( $chip['subitems'] ?? [] ) as $si ) {
					if ( ( $si['slug'] ?? '' ) === $slug ) {
						return (string) $si['label'];
					}
				}
			}
		}
		return $slug;
	}

	// -------------------------------------------------------------------------
	// Mutations.
	// -------------------------------------------------------------------------

	public static function save_overrides( \WP_REST_Request $request ) {
		$payload = $request->get_param( 'overrides' );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'invalid_payload',
				__( 'The overrides must be an object.', 'doubleyoupi-refined-admin-dashboard' ),
				[ 'status' => 400 ]
			);
		}
		$saved = NavigationOverrides::set( $payload );
		// If some `classifications` match "detected" slugs, remove them from the
		// Detected drawer — the user has now sorted them, no need to surface them.
		if ( is_array( $saved ) && ! empty( $saved['classifications'] ) ) {
			foreach ( array_keys( $saved['classifications'] ) as $slug ) {
				NavigationDetector::clear( (string) $slug );
			}
		}
		return rest_ensure_response( [
			'saved'    => true,
			'snapshot' => self::build_snapshot(),
		] );
	}

	public static function reset_overrides( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		NavigationOverrides::reset();
		// Also reset detected to start from a blank slate.
		NavigationDetector::reset();
		return rest_ensure_response( [
			'reset'    => true,
			'snapshot' => self::build_snapshot(),
		] );
	}

	public static function save_disabled_screens( \WP_REST_Request $request ) {
		$payload = $request->get_param( 'signatures' );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'invalid_payload',
				__( 'Signatures must be an array.', 'doubleyoupi-refined-admin-dashboard' ),
				[ 'status' => 400 ]
			);
		}
		$saved = ScreenDisabler::set_disabled( $payload );
		return rest_ensure_response( [
			'saved'      => true,
			'signatures' => $saved,
		] );
	}

	public static function reset_disabled_screens( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		ScreenDisabler::reset();
		return rest_ensure_response( [ 'reset' => true ] );
	}
}

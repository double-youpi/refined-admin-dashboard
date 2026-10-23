<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ColumnModel — SINGLE source of truth for the navigation tree in
 * "columns", shared by:
 *   - the live rail (Rail::collect / markup_menu)
 *   - the Reorganize editor snapshot (Developer\RestController)
 *
 * A "column" = a top-level group in the rail. Three kinds:
 *   - line      : one of the 7 curated lines (content, commerce, …)
 *   - standalone: a builder (Elementor, Bricks…) or the active theme
 *   - custom    : a group created by the user (id `dyg_…`)
 *
 * Each column carries its `chips` (top-level items), each chip carries
 * its `subitems` (sub-pages). User overrides (NavigationOverrides) are
 * applied here, once, so the live rail and the editor always stay in sync.
 *
 * Column format:
 * [
 *   'id'        => 'content' | 'elementor' | 'dyg_xxx',
 *   'kind'      => 'line' | 'standalone' | 'custom',
 *   'label'     => string,
 *   'icon'      => string (sprite symbol id),
 *   'removable' => bool,   // only custom columns are removable
 *   'url'       => string, // first destination (for the live rail)
 *   'chips'     => array<chip>,
 * ]
 *
 * Chip format:
 * [
 *   'slug' => string, 'parent_slug' => string, 'label' => string,
 *   'url' => string, 'weight' => int, 'icon' => ?string,
 *   'is_curated' => bool, 'subitems' => array<subitem>,
 * ]
 *
 * Subitem format:
 * [ 'slug','label','url','signature','styling_disabled' ]
 */
final class ColumnModel {

	/** @var array<int, array<string,mixed>>|null */
	private static ?array $cache = null;

	/** @var array<int, array<string,mixed>>|null Native tree (see native_columns). */
	private static ?array $cache_native = null;

	public static function flush_cache(): void {
		self::$cache        = null;
		self::$cache_native = null;
	}

	/**
	 * Builds (and memoizes) the ordered list of columns.
	 *
	 * @param bool|null $native Force the native WordPress tree (true) or the
	 *                          reimagined one (false); null follows the site
	 *                          setting. The Reorganize editor always asks for
	 *                          the reimagined tree: that is what it edits.
	 * @return array<int, array<string,mixed>>
	 */
	public static function columns( ?bool $native = null ): array {
		$native ??= Settings::is_native_navigation();
		if ( $native ) {
			if ( null === self::$cache_native ) {
				self::$cache_native = self::native_columns();
			}
			return self::$cache_native;
		}
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$overrides     = NavigationOverrides::get();
		$classify_over = (array) ( $overrides['classifications'] ?? [] );
		$demoted       = NavigationOverrides::demoted();
		$promoted      = NavigationOverrides::promoted();
		$custom_groups = NavigationOverrides::custom_groups();

		// 1) Index the live $menu.
		global $menu, $submenu;
		$builders_meta = PluginMap::standalone_builders();

		// Line + custom columns: map id => chips[].
		$line_chips   = array_fill_keys( PluginMap::line_order(), [] );
		foreach ( array_keys( $custom_groups ) as $gid ) {
			$line_chips[ $gid ] = [];
		}

		// Standalone columns: builder/theme id => { meta, chips[] }.
		$standalone_cols = []; // id => ['kind'=>'standalone','label','icon','url','chips'=>[]]

		// Detect active builders + active theme (sources of standalone columns).
		$active_builders = []; // slug => meta.
		if ( is_array( $menu ) ) {
			foreach ( $menu as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
					continue;
				}
				$mslug = PluginMap::normalize_slug( (string) $entry[2] );
				if ( isset( $builders_meta[ $mslug ] ) ) {
					$active_builders[ $mslug ] = $builders_meta[ $mslug ];
				}
			}
		}
		$active_theme = PluginMap::active_theme();

		// 2) Walk $menu: classify each top-level entry as a chip in its column.
		if ( is_array( $menu ) ) {
			foreach ( $menu as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
					continue;
				}
				$slug  = PluginMap::normalize_slug( (string) $entry[2] );
				$label = self::strip_label( (string) ( $entry[0] ?? '' ) );

				if ( '' === $label && ! isset( $active_builders[ $slug ] ) ) {
					continue;
				}
				// Hidden / demoted: not a top-level chip.
				if ( NavigationOverrides::is_chip_hidden( $slug ) ) {
					continue;
				}
				if ( isset( $demoted[ $slug ] ) ) {
					continue;
				}
				// What this user may not open stays out of the rail.
				$open_url = self::top_url_for_user( $entry, $slug );
				if ( null === $open_url ) {
					continue;
				}

				// Satellite page of an active builder → chip in the builder column.
				$owner = PluginMap::standalone_owner( $slug );
				if ( null !== $owner && isset( $active_builders[ $owner ] ) ) {
					self::ensure_standalone_col( $standalone_cols, $owner, $active_builders[ $owner ] );
					$standalone_cols[ $owner ]['chips'][] = self::make_chip( $slug, $label, $open_url );
					continue;
				}

				// The builder itself → header of its standalone column (not a chip).
				if ( isset( $active_builders[ $slug ] ) ) {
					self::ensure_standalone_col( $standalone_cols, $slug, $active_builders[ $slug ] );
					// Its sub-pages become chips in the column.
					foreach ( self::submenu_children( $slug ) as $child ) {
						$standalone_cols[ $slug ]['chips'][] = self::make_chip( $child['slug'], $child['label'], $child['url'] );
					}
					continue;
				}

				// Split native menu (Appearance): its own page is a chip of the
				// line it maps to (Themes → System) and each sub-page a chip of
				// the children line (Editor, Fonts, Menus, Widgets, theme pages
				// → Appearance). Reimagined tree only; native keeps the menu.
				if ( PluginMap::is_split_menu( $slug ) ) {
					self::inject_split_menu( $line_chips, $slug, $label );
					continue;
				}

				$classification = PluginMap::classify( $slug );
				if ( null === $classification ) {
					continue; // Dashboard.
				}
				// Classification override (may target a custom group).
				if ( 'standalone' !== $classification ) {
					$classification = NavigationOverrides::apply_to_line( $slug, $classification );
				}
				if ( 'standalone' === $classification ) {
					// Known builder but not active as a top-level entry: ignore.
					continue;
				}
				if ( ! isset( $line_chips[ $classification ] ) ) {
					$line_chips[ $classification ] = [];
				}
				$line_chips[ $classification ][] = self::make_chip( $slug, $label, $open_url );
			}
		}

		// 3) Native promotions (Site Health, Updates, editors) + user promotions.
		self::inject_native_promotions( $line_chips );
		self::inject_user_promotions( $line_chips, $promoted );

		// 4) Active theme → standalone column.
		if ( $active_theme ) {
			$tid = 'theme:' . $active_theme['slug'];
			$standalone_cols[ $tid ] = [
				'kind'  => 'standalone',
				'label' => (string) $active_theme['label'],
				'icon'  => (string) $active_theme['icon'],
				'url'   => (string) $active_theme['url'],
				'chips' => [],
			];
		}

		// 5) Sort chips by weight within each line/custom column.
		foreach ( $line_chips as $id => $chips ) {
			usort( $line_chips[ $id ], static fn( $a, $b ) => ( $a['weight'] ?? 100 ) <=> ( $b['weight'] ?? 100 ) );
		}

		// 6) Assemble columns in the desired order.
		$columns = self::assemble_ordered(
			$overrides,
			$line_chips,
			$standalone_cols,
			$custom_groups,
			$active_theme ? 'theme:' . $active_theme['slug'] : null
		);

		self::$cache = $columns;
		return $columns;
	}

	/**
	 * The WordPress menu as WordPress orders it, one column per top-level
	 * entry: its sub-menu becomes the chips (the chipbar), separators stay
	 * separators. Must run once core has finished with `$menu` (capability
	 * filtering, position sort, `menu_order`): from `admin_head` onwards,
	 * never on `admin_menu`. Nothing from the overrides applies here: the
	 * whole point is the tree people already know.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private static function native_columns(): array {
		global $menu, $submenu;
		$out = [];
		if ( ! is_array( $menu ) ) {
			return $out;
		}
		foreach ( $menu as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$classes = (string) ( $entry[4] ?? '' );
			if ( str_contains( $classes, 'wp-menu-separator' ) ) {
				$out[] = [
					'id'        => 'sep-' . count( $out ),
					'kind'      => 'separator',
					'label'     => '',
					'icon'      => '',
					'icon_html' => '',
					'removable' => false,
					'url'       => '',
					'chips'     => [],
				];
				continue;
			}
			$raw = (string) ( $entry[2] ?? '' );
			if ( '' === $raw ) {
				continue;
			}
			$label = self::strip_label( (string) ( $entry[0] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$id    = PluginMap::normalize_slug( $raw );
			$chips = [];
			if ( is_array( $submenu ) && ! empty( $submenu[ $raw ] ) && is_array( $submenu[ $raw ] ) ) {
				foreach ( $submenu[ $raw ] as $sub ) {
					if ( ! is_array( $sub ) || empty( $sub[2] ) ) {
						continue;
					}
					$sub_label = self::strip_label( (string) ( $sub[0] ?? '' ) );
					if ( '' === $sub_label ) {
						continue;
					}
					$chips[] = [
						'slug'        => PluginMap::normalize_slug( (string) $sub[2] ),
						'parent_slug' => $id,
						'label'       => $sub_label,
						'url'         => self::submenu_url( (string) $sub[2], $raw ),
						'weight'      => 100,
						'icon'        => null,
						'is_curated'  => false,
						'native_col'  => '',
						'subitems'    => [],
					];
				}
			}
			$out[] = [
				'id'        => $id,
				'kind'      => 'native',
				'label'     => $label,
				'icon'      => '',
				'icon_html' => self::native_icon_html( (string) ( $entry[6] ?? '' ) ),
				'removable' => false,
				'url'       => $chips ? $chips[0]['url'] : self::menu_url( $raw ),
				'chips'     => $chips,
			];
		}
		return $out;
	}

	/**
	 * The icon markup WordPress itself prints for a menu entry
	 * (wp-admin/menu-header.php): Dashicon class, base64 SVG as background,
	 * plain image, or nothing. Our rail styles all four forms.
	 */
	private static function native_icon_html( string $icon ): string {
		$img   = '';
		$style = '';
		$class = ' dashicons-before';
		if ( '' !== $icon ) {
			$img = '<img src="' . esc_url( $icon ) . '" alt="" />';
			if ( 'none' === $icon || 'div' === $icon ) {
				$img = '<br />';
			} elseif ( str_starts_with( $icon, 'data:image/svg+xml;base64,' ) ) {
				$img   = '<br />';
				$style = ' style="background-image:url(\'' . esc_attr( $icon ) . '\')"';
				$class = ' svg';
			} elseif ( str_starts_with( $icon, 'dashicons-' ) ) {
				$img   = '<br />';
				$class = ' dashicons-before ' . sanitize_html_class( $icon );
			} elseif ( function_exists( 'wp_get_icon' ) && preg_match( '#^[a-z0-9_-]+/[a-z0-9_-]+$#i', $icon ) ) {
				$svg = (string) wp_get_icon( $icon, [ 'size' => 18 ] );
				if ( '' !== $svg ) {
					$img   = $svg;
					$class = ' dy-icon-api';
				}
			}
		}
		return '<div class="wp-menu-image' . $class . '"' . $style . ' aria-hidden="true">' . $img . '</div>';
	}

	/**
	 * Final column order: user column_order if present, otherwise
	 * the default "rail mirror" order:
	 *   content, commerce, users, community, site, system,
	 *   [theme], [builders], [custom groups], developer.
	 *
	 * @param array<string,mixed>                 $overrides
	 * @param array<string,array>                 $line_chips
	 * @param array<string,array>                 $standalone_cols
	 * @param array<string,array{label:string,icon:string}> $custom_groups
	 * @return array<int,array<string,mixed>>
	 */
	private static function assemble_ordered(
		array $overrides,
		array $line_chips,
		array $standalone_cols,
		array $custom_groups,
		?string $theme_id
	): array {
		$lines      = PluginMap::line_order();
		$line_set   = array_flip( $lines );
		$standalone = array_keys( $standalone_cols );
		$customs    = array_keys( $custom_groups );

		// Default order.
		$default = [];
		foreach ( $lines as $l ) {
			if ( 'developer' === $l ) {
				continue; // developer goes last
			}
			$default[] = $l;
		}
		if ( $theme_id && isset( $standalone_cols[ $theme_id ] ) ) {
			$default[] = $theme_id;
		}
		foreach ( $standalone as $sid ) {
			if ( $sid !== $theme_id ) {
				$default[] = $sid;
			}
		}
		foreach ( $customs as $cid ) {
			$default[] = $cid;
		}
		$default[] = 'developer';

		// User order: honour column_order then fill in any missing ids.
		$user_order = NavigationOverrides::column_order();
		$ordered_ids = [];
		if ( $user_order ) {
			foreach ( $user_order as $cid ) {
				if ( ! in_array( $cid, $ordered_ids, true ) ) {
					$ordered_ids[] = $cid;
				}
			}
		}
		// Append any known id not yet included (in default order).
		foreach ( $default as $cid ) {
			if ( ! in_array( $cid, $ordered_ids, true ) ) {
				$ordered_ids[] = $cid;
			}
		}

		$line_labels = self::line_labels();
		$columns     = [];
		foreach ( $ordered_ids as $id ) {
			// line
			if ( isset( $line_set[ $id ] ) ) {
				$chips = $line_chips[ $id ] ?? [];
				$def_label = $line_labels[ $id ] ?? ucfirst( $id );
				$def_icon  = 'i-line-' . $id;
				$columns[] = [
					'id'            => $id,
					'kind'          => 'line',
					'label'         => NavigationOverrides::group_label( $id, $def_label ),
					'icon'          => NavigationOverrides::group_icon( $id, $def_icon ),
					'default_label' => $def_label,
					'default_icon'  => $def_icon,
					'removable'     => false,
					'url'           => $chips[0]['url'] ?? '',
					'chips'         => $chips,
				];
				continue;
			}
			// standalone
			if ( isset( $standalone_cols[ $id ] ) ) {
				$col = $standalone_cols[ $id ];
				$columns[] = [
					'id'        => $id,
					'kind'      => 'standalone',
					'label'     => NavigationOverrides::group_label( $id, $col['label'] ),
					'icon'      => NavigationOverrides::group_icon( $id, $col['icon'] ),
					'removable' => false,
					// Builder with externally managed UI (Elementor…): the reorganizer
					// shows "hierarchy managed by the extension" and does not expose
					// its sub-pages. The live rail keeps its chips (the chipbar
					// is hidden on those screens regardless).
					'managed'   => PluginMap::is_externally_managed_builder( $id ),
					'url'       => $col['url'] ?? ( $col['chips'][0]['url'] ?? '' ),
					'chips'     => $col['chips'],
				];
				continue;
			}
			// custom
			if ( isset( $custom_groups[ $id ] ) ) {
				$chips = $line_chips[ $id ] ?? [];
				$columns[] = [
					'id'            => $id,
					'kind'          => 'custom',
					'label'         => NavigationOverrides::group_label( $id, $custom_groups[ $id ]['label'] ),
					'icon'          => NavigationOverrides::group_icon( $id, $custom_groups[ $id ]['icon'] ),
					'default_label' => $custom_groups[ $id ]['label'],
					'default_icon'  => $custom_groups[ $id ]['icon'],
					'removable'     => true,
					'url'           => $chips[0]['url'] ?? '',
					'chips'         => $chips,
				];
				continue;
			}
			// Unknown id (disappeared standalone, deleted group) → ignored.
		}
		return $columns;
	}

	// -----------------------------------------------------------------
	// Chip / subitem construction.
	// -----------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	/**
	 * Whether the current user may open a menu entry. The rail reads the menu
	 * on `admin_menu`, before WordPress removes what the user cannot open, so
	 * it applies the same rule itself (23/10/2026: authors saw Settings,
	 * Plugins and other doors that answered "not allowed").
	 *
	 * @param array<int,mixed> $entry
	 */
	private static function user_can_open( array $entry ): bool {
		$cap = (string) ( $entry[1] ?? '' );
		return '' === $cap || current_user_can( $cap );
	}

	/**
	 * Where a top-level entry leads for the current user: its own page when
	 * allowed, else its first allowed sub-page (as WordPress does), else null.
	 *
	 * @param array<int,mixed> $entry
	 */
	private static function top_url_for_user( array $entry, string $slug ): ?string {
		if ( self::user_can_open( $entry ) ) {
			return self::menu_url( $slug );
		}
		global $submenu;
		foreach ( (array) ( $submenu[ (string) $entry[2] ] ?? [] ) as $child ) {
			if ( is_array( $child ) && ! empty( $child[2] ) && self::user_can_open( $child ) ) {
				return self::submenu_url( PluginMap::normalize_slug( (string) $child[2] ), (string) $entry[2] );
			}
		}
		return null;
	}

	private static function make_chip( string $slug, string $label, string $url ): array {
		// "Native" column (classification WITHOUT user override) — used by the
		// editor to store a classification only when the chip has actually been
		// moved out of its original column.
		$native = PluginMap::classify( $slug );
		if ( null === $native || 'standalone' === $native ) {
			$native = '';
		}
		return [
			'slug'        => $slug,
			'parent_slug' => $slug,
			'label'       => '' !== $label ? $label : $slug,
			'url'         => $url,
			'weight'      => NavigationOverrides::apply_to_weight( $slug, PluginMap::item_weight( $slug ) ),
			'icon'        => PluginMap::chip_icon( $slug ),
			'is_curated'  => PluginMap::is_curated( $slug ),
			'native_col'  => $native,
			'subitems'    => self::subitems_for( $slug ),
		];
	}

	/**
	 * Sub-pages of a chip: reads `$submenu[$parent]`, filters noise, applies
	 * subitem_hidden + subitem_order + injects chips demoted to this parent.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function subitems_for( string $parent_slug ): array {
		global $submenu;
		$out      = [];
		$disabled = ScreenDisabler::get_disabled();
		$reparent = NavigationOverrides::subitem_reparent();

		$skip_starts = [
			'post-new.php', 'user-new.php', 'media-new.php', 'link-add.php',
			'customize.php', 'plugin-install.php', 'theme-install.php',
			'theme-editor.php', 'plugin-editor.php',
		];

		if ( is_array( $submenu ) && ! empty( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry[2] ) || ! self::user_can_open( $entry ) ) {
					continue;
				}
				$slug  = PluginMap::normalize_slug( (string) $entry[2] );
				$label = self::strip_label( (string) ( $entry[0] ?? '' ) );
				if ( '' === $label ) {
					continue;
				}
				$skip = false;
				foreach ( $skip_starts as $needle ) {
					if ( str_starts_with( $slug, $needle ) ) {
						$skip = true;
						break;
					}
				}
				if ( $skip ) {
					continue;
				}
				if ( NavigationOverrides::is_subitem_hidden( $parent_slug, $slug ) ) {
					continue;
				}
				// Re-parented elsewhere → removed from its native parent.
				if ( isset( $reparent[ $slug ] ) && $reparent[ $slug ] !== $parent_slug ) {
					continue;
				}
				$sig = ScreenDisabler::signature_from_submenu_slug( $slug, $parent_slug );
				$out[] = [
					'slug'             => $slug,
					'label'            => $label,
					'url'              => self::submenu_url( $slug, $parent_slug ),
					'signature'        => $sig,
					'styling_disabled' => '' !== $sig && in_array( $sig, $disabled, true ),
				];
			}
		}

		// Sub-pages re-parented TO this parent → materialized here (they
		// natively live under a different parent in $submenu).
		foreach ( $reparent as $child_slug => $new_parent ) {
			if ( $new_parent !== $parent_slug ) {
				continue;
			}
			if ( NavigationOverrides::is_subitem_hidden( $parent_slug, $child_slug ) ) {
				continue;
			}
			$found = self::find_in_submenu( $child_slug );
			if ( null === $found ) {
				continue;
			}
			$sig = ScreenDisabler::signature_from_submenu_slug( $child_slug, $parent_slug );
			$out[] = [
				'slug'             => $child_slug,
				'label'            => $found['label'],
				'url'              => $found['url'],
				'signature'        => $sig,
				'styling_disabled' => '' !== $sig && in_array( $sig, $disabled, true ),
			];
		}

		// Chips demoted to this parent → injected as synthetic sub-pages.
		foreach ( NavigationOverrides::demoted() as $chip_slug => $target ) {
			[ , $target_parent ] = explode( '::', $target, 2 );
			if ( PluginMap::normalize_slug( $target_parent ) !== $parent_slug ) {
				continue;
			}
			if ( NavigationOverrides::is_chip_hidden( $chip_slug ) ) {
				continue;
			}
			$meta = self::lookup_menu_meta( $chip_slug );
			if ( null === $meta ) {
				continue;
			}
			$sig = ScreenDisabler::signature_from_submenu_slug( $chip_slug, $parent_slug );
			$out[] = [
				'slug'             => $chip_slug,
				'label'            => $meta['label'],
				'url'              => $meta['url'],
				'signature'        => $sig,
				'styling_disabled' => '' !== $sig && in_array( $sig, $disabled, true ),
			];
		}

		// User-defined reordering.
		$order = NavigationOverrides::subitem_order_for( $parent_slug );
		if ( $order && $out ) {
			$by = [];
			foreach ( $out as $it ) {
				$by[ $it['slug'] ] = $it;
			}
			$re = [];
			foreach ( $order as $s ) {
				$s = PluginMap::normalize_slug( $s );
				if ( isset( $by[ $s ] ) ) {
					$re[] = $by[ $s ];
					unset( $by[ $s ] );
				}
			}
			foreach ( $by as $rest ) {
				$re[] = $rest;
			}
			$out = $re;
		}

		return $out;
	}

	/**
	 * @return array<int,array{slug:string,label:string,url:string}>
	 */
	private static function submenu_children( string $parent_slug ): array {
		global $submenu;
		if ( ! is_array( $submenu ) || empty( $submenu[ $parent_slug ] ) ) {
			return [];
		}
		$skip_starts = [ 'post-new.php', 'plugin-install.php', 'theme-install.php' ];
		$out = [];
		foreach ( $submenu[ $parent_slug ] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}
			if ( ! self::user_can_open( $entry ) ) {
				continue;
			}
			$slug  = PluginMap::normalize_slug( (string) $entry[2] );
			$label = self::strip_label( (string) ( $entry[0] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$skip = false;
			foreach ( $skip_starts as $needle ) {
				if ( str_starts_with( $slug, $needle ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}
			$out[] = [ 'slug' => $slug, 'label' => $label, 'url' => self::submenu_url( $slug, $parent_slug ) ];
		}
		return $out;
	}

	// -----------------------------------------------------------------
	// Promotions.
	// -----------------------------------------------------------------

	/**
	 * @param array<string,array> $line_chips
	 */
	private static function inject_native_promotions( array &$line_chips ): void {
		$promote = [
			[ 'slug' => 'theme-editor.php',  'label' => __( 'Theme Editor',  'doubleyoupi-refined-admin-dashboard' ), 'line' => 'developer', 'cap' => 'edit_themes' ],
			[ 'slug' => 'plugin-editor.php', 'label' => __( 'Plugin Editor', 'doubleyoupi-refined-admin-dashboard' ), 'line' => 'developer', 'cap' => 'edit_plugins' ],
			[ 'slug' => 'site-health.php',   'label' => self::submenu_label( 'site-health.php', __( 'Site Health', 'doubleyoupi-refined-admin-dashboard' ) ), 'line' => 'system', 'cap' => 'view_site_health_checks' ],
			[ 'slug' => 'update-core.php',   'label' => self::submenu_label( 'update-core.php', __( 'Updates', 'doubleyoupi-refined-admin-dashboard' ) ),     'line' => 'system', 'cap' => 'update_core' ],
		];
		foreach ( $promote as $p ) {
			if ( ! current_user_can( $p['cap'] ) ) {
				continue;
			}
			if ( NavigationOverrides::is_chip_hidden( $p['slug'] ) ) {
				continue;
			}
			// Apply any classification override (e.g. moved to another column).
			$line = NavigationOverrides::apply_to_line( $p['slug'], $p['line'] );
			if ( ! isset( $line_chips[ $line ] ) ) {
				$line_chips[ $line ] = [];
			}
			if ( self::has_chip( $line_chips[ $line ], $p['slug'] ) ) {
				continue;
			}
			$line_chips[ $line ][] = self::make_chip( $p['slug'], $p['label'], admin_url( $p['slug'] ) );
		}
	}

	/**
	 * Explodes a split native menu (PluginMap::SPLIT_MENUS) into chips: the
	 * parent page itself, labelled after its own sub-menu entry and without
	 * sub-pages, goes to the parent's line; every other sub-page becomes a
	 * chip of the children line. `subitems_for()` already applies the user's
	 * hidden / re-parented / re-ordered sub-pages; hidden chips and column
	 * overrides apply to each resulting chip like to any other.
	 *
	 * @param array<string,array> $line_chips
	 */
	private static function inject_split_menu( array &$line_chips, string $parent_slug, string $top_label ): void {
		$parent_line = PluginMap::classify( $parent_slug );
		if ( null !== $parent_line && 'standalone' !== $parent_line ) {
			$line = NavigationOverrides::apply_to_line( $parent_slug, $parent_line );
			if ( ! isset( $line_chips[ $line ] ) ) {
				$line_chips[ $line ] = [];
			}
			if ( ! self::has_chip( $line_chips[ $line ], $parent_slug ) ) {
				$chip                  = self::make_chip( $parent_slug, self::submenu_label( $parent_slug, $top_label ), self::menu_url( $parent_slug ) );
				$chip['subitems']      = [];
				$line_chips[ $line ][] = $chip;
			}
		}
		$children_line = PluginMap::split_children_line( $parent_slug );
		foreach ( self::subitems_for( $parent_slug ) as $child ) {
			$child_slug = (string) $child['slug'];
			if ( $child_slug === $parent_slug || NavigationOverrides::is_chip_hidden( $child_slug ) ) {
				continue;
			}
			$line = NavigationOverrides::apply_to_line( $child_slug, $children_line );
			if ( ! isset( $line_chips[ $line ] ) ) {
				$line_chips[ $line ] = [];
			}
			if ( self::has_chip( $line_chips[ $line ], $child_slug ) ) {
				continue;
			}
			$line_chips[ $line ][] = self::make_chip( $child_slug, (string) $child['label'], (string) $child['url'] );
		}
	}

	/**
	 * @param array<string,array>  $line_chips
	 * @param array<string,string> $promoted
	 */
	private static function inject_user_promotions( array &$line_chips, array $promoted ): void {
		foreach ( $promoted as $slug => $target ) {
			if ( NavigationOverrides::is_chip_hidden( $slug ) ) {
				continue;
			}
			if ( ! isset( $line_chips[ $target ] ) ) {
				$line_chips[ $target ] = [];
			}
			if ( self::has_chip( $line_chips[ $target ], $slug ) ) {
				continue;
			}
			$found = self::find_in_submenu( $slug );
			if ( null === $found ) {
				continue;
			}
			if ( '' !== $found['cap'] && ! current_user_can( $found['cap'] ) ) {
				continue;
			}
			$line_chips[ $target ][] = self::make_chip( $slug, $found['label'], $found['url'] );
		}
	}

	// -----------------------------------------------------------------
	// Internal helpers.
	// -----------------------------------------------------------------

	/**
	 * @param array<string,array> $cols
	 * @param array{icon:string,label:string} $meta
	 */
	private static function ensure_standalone_col( array &$cols, string $id, array $meta ): void {
		if ( isset( $cols[ $id ] ) ) {
			return;
		}
		$cols[ $id ] = [
			'kind'  => 'standalone',
			'label' => (string) ( $meta['label'] ?? $id ),
			'icon'  => (string) ( $meta['icon'] ?? 'i-pick-layout' ),
			'url'   => self::menu_url( $id ),
			'chips' => [],
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $chips
	 */
	private static function has_chip( array $chips, string $slug ): bool {
		foreach ( $chips as $c ) {
			if ( ( $c['slug'] ?? '' ) === $slug ) {
				return true;
			}
		}
		return false;
	}

	private static function menu_url( string $slug ): string {
		$override = PluginMap::url_override( $slug );
		if ( null !== $override ) {
			return admin_url( $override );
		}
		// Mirror wp-admin/menu-header.php: a top-level entry that owns
		// sub-menus links to its FIRST sub-menu. The parent slug itself is
		// often not a page (WooCommerce « Marketing » = `woocommerce-marketing`
		// whose first child is `admin.php?page=wc-admin&path=/marketing`;
		// requesting the parent slug yields a WP « not allowed » error).
		global $submenu;
		if ( is_array( $submenu ) && ! empty( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
			$first      = reset( $submenu[ $slug ] );
			$first_slug = is_array( $first ) ? (string) ( $first[2] ?? '' ) : '';
			if ( '' !== $first_slug && $first_slug !== $slug ) {
				return self::submenu_url( $first_slug, $slug );
			}
		}
		if ( str_contains( $slug, '.php' ) ) {
			return self::screen_url( $slug );
		}
		return self::page_url( $slug );
	}

	/**
	 * `page=` argument of a plugin page, built the way WordPress builds it in
	 * its own menu (menu-header.php): the slug as registered, not encoded. A
	 * slug may carry arguments of its own: Limit Login Attempts registers
	 * `limit-login-attempts&tab=settings`, and encoding it sent every tab of
	 * the rail and the bars to the plugin's default one (translator feedback,
	 * 23/10/2026). Callers output the URL through esc_url().
	 */
	public static function page_arg( string $slug ): string {
		return 'page=' . $slug;
	}

	/**
	 * URL of a menu entry registered as a plugin page. A slug that is already
	 * a full address (Independent Analytics' « Support Forum » points to
	 * wordpress.org) is used as is, as WordPress does; it used to become
	 * `admin.php?page=https://…` and WordPress answered « Cannot load »
	 * (translator feedback, 23/10/2026).
	 *
	 * @param string $prefix What comes before `page=`: `admin.php?` or a
	 *                       parent screen with its glue (`edit.php?…&`).
	 */
	public static function page_url( string $slug, string $prefix = 'admin.php?' ): string {
		if ( preg_match( '#^https?://#i', $slug ) ) {
			return $slug;
		}
		return admin_url( $prefix . self::page_arg( $slug ) );
	}

	/**
	 * URL of a menu entry whose slug is a screen (`tools.php`, `edit.php?…`),
	 * or already a full address, kept as is (see page_url()).
	 */
	public static function screen_url( string $slug ): string {
		return preg_match( '#^https?://#i', $slug ) ? $slug : admin_url( $slug );
	}

	private static function submenu_url( string $slug, string $parent_slug ): string {
		if ( str_contains( $slug, '.php' ) || str_contains( $slug, '?' ) ) {
			return self::screen_url( $slug );
		}
		if ( str_contains( $parent_slug, '.php' ) ) {
			$glue = str_contains( $parent_slug, '?' ) ? '&' : '?';
			return self::page_url( $slug, $parent_slug . $glue );
		}
		return self::page_url( $slug );
	}

	/**
	 * @return array{label:string,url:string}|null
	 */
	private static function lookup_menu_meta( string $slug ): ?array {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return null;
		}
		foreach ( $menu as $entry ) {
			if ( ! is_array( $entry ) || PluginMap::normalize_slug( (string) ( $entry[2] ?? '' ) ) !== $slug ) {
				continue;
			}
			$label = self::strip_label( (string) ( $entry[0] ?? '' ) );
			return [
				'label' => '' !== $label ? $label : $slug,
				'url'   => self::menu_url( $slug ),
			];
		}
		return null;
	}

	/**
	 * @return array{label:string,url:string,cap:string}|null
	 */
	private static function find_in_submenu( string $slug ): ?array {
		global $submenu;
		if ( ! is_array( $submenu ) ) {
			return null;
		}
		foreach ( $submenu as $parent => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $entry ) {
				if ( ! is_array( $entry ) || PluginMap::normalize_slug( (string) ( $entry[2] ?? '' ) ) !== $slug ) {
					continue;
				}
				$label = self::strip_label( (string) ( $entry[0] ?? '' ) );
				return [
					'label' => '' !== $label ? $label : $slug,
					'url'   => self::submenu_url( $slug, (string) $parent ),
					'cap'   => (string) ( $entry[1] ?? '' ),
				];
			}
		}
		return null;
	}

	private static function submenu_label( string $slug, string $fallback ): string {
		global $submenu;
		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $items ) {
				if ( ! is_array( $items ) ) {
					continue;
				}
				foreach ( $items as $entry ) {
					if ( is_array( $entry ) && PluginMap::normalize_slug( (string) ( $entry[2] ?? '' ) ) === $slug ) {
						$label = self::strip_label( (string) ( $entry[0] ?? '' ) );
						if ( '' !== $label ) {
							return $label;
						}
					}
				}
			}
		}
		return $fallback;
	}

	/**
	 * Menu label without its count badges (`<span class="update-plugins">…`).
	 * A plugin that wraps its whole label in a span (Backup Migration:
	 * `<span id="bmi-menu">Backup Migration</span>`) kept nothing and its
	 * entry vanished from the rail (19/10/2026): the text is kept then.
	 */
	public static function strip_label( string $raw ): string {
		$clean = trim( wp_strip_all_tags( preg_replace( '/<span[^>]*>.*?<\/span>/s', '', $raw ) ?? $raw ) );
		return '' !== $clean ? $clean : trim( wp_strip_all_tags( $raw ) );
	}

	/**
	 * @return array<string,string>
	 */
	public static function line_labels(): array {
		return [
			'content'   => __( 'Content',   'doubleyoupi-refined-admin-dashboard' ),
			'commerce'  => __( 'Commerce',  'doubleyoupi-refined-admin-dashboard' ),
			'users'     => __( 'Users',     'doubleyoupi-refined-admin-dashboard' ),
			'community' => __( 'Community', 'doubleyoupi-refined-admin-dashboard' ),
			'site'      => __( 'Appearance', 'doubleyoupi-refined-admin-dashboard' ),
			'system'    => __( 'System',    'doubleyoupi-refined-admin-dashboard' ),
			'developer' => __( 'Developer', 'doubleyoupi-refined-admin-dashboard' ),
		];
	}
}

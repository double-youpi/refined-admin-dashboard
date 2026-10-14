<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Core\Build;
use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rail — rendering of the Admin module's lateral sidebar.
 *
 * Strategy: WordPress places `#adminmenuwrap` as a direct child of `#wpwrap`
 * (via menu-header.php) — right where the design system CSS grid layout needs
 * it. We CANNOT inject our rail via `in_admin_header` because that hook fires
 * INSIDE `#wpcontent`, so our markup would land in the content column, outside
 * the rail grid cell.
 *
 * Solution: we emit the rail HTML inside a `<template>` in `<head>` + an inline
 * script that:
 *   1. Attempts immediate injection (if `#adminmenuwrap` is already in the DOM —
 *      unlikely as the script runs before body parsing)
 *   2. Otherwise installs a MutationObserver that detects `#adminmenuwrap`
 *      appearing and replaces its innerHTML with our template
 *   3. `DOMContentLoaded` fallback just in case
 *
 * The native `#adminmenuwrap` keeps its position in the CSS grid (assigned
 * `grid-area: rail` by _layout.scss). Our content fills this container,
 * preserving the SCSS scope `body.dy-admin #adminmenuwrap`.
 *
 * The initial `visibility: hidden` on `#adminmenuwrap` (restored to visible
 * after injection) prevents the native menu from flashing during parsing.
 */
final class Rail {

	/** Hides the native menu while the page parses (see output_rail_template()). */
	private const PRE_PAINT_CSS = 'body.js #adminmenuwrap{visibility:hidden}body.js #adminmenuwrap.dy-admin-ready{visibility:visible}';

	/** Moves the rail template into #adminmenuwrap as soon as it exists. */
	private const INJECTOR_JS = <<<'JS'
(function () {
	// Idempotent: injection occurs EXACTLY ONCE. Without this guard,
	// MutationObserver fires the first inject (creating handle/toggle nodes),
	// then admin.js attaches its listeners to those nodes, then DOMContentLoaded
	// fires inject() again, wiping wrap.innerHTML and replacing it with new
	// handle/toggle nodes — detaching admin.js listeners from the live DOM.
	// Result: toggle/handle/dblclick/keyboard no longer respond.
	function inject() {
		var wrap = document.getElementById('adminmenuwrap');
		var tpl  = document.getElementById('dy-admin-rail-tpl');
		if (!wrap || !tpl) return false;
		if (wrap.classList.contains('dy-admin-ready')) return true; // already done
		// Keep WP's native menu in the DOM, hidden, instead of wiping it:
		// third-party scripts walk it to mark their current sub-page
		// (Jetpack's wpNavMenuClassChange reads `#toplevel_page_jetpack
		// .wp-submenu` and crashed its whole React app when absent).
		// The native <ul> is renamed so our rail keeps the `#adminmenu` id.
		var keep = document.createElement('div');
		keep.id = 'dy-native-adminmenu';
		keep.hidden = true;
		keep.setAttribute('aria-hidden', 'true');
		keep.style.display = 'none';
		while (wrap.firstChild) keep.appendChild(wrap.firstChild);
		var nativeUl = keep.querySelector('#adminmenu');
		if (nativeUl) nativeUl.id = 'adminmenu-native';
		wrap.appendChild(tpl.content.cloneNode(true));
		wrap.appendChild(keep);
		wrap.classList.add('dy-admin-ready');
		return true;
	}
	if (inject()) return;
	var obs = new MutationObserver(function (_, o) {
		if (inject()) o.disconnect();
	});
	obs.observe(document.documentElement, { childList: true, subtree: true });
	document.addEventListener('DOMContentLoaded', function () {
		if (inject()) obs.disconnect();
	});
})();
JS;


	/**
	 * @var array{
	 *   builders: array<int, array{slug:string, icon:string, label:string, url:string}>,
	 *   lines:    array<string, array<int, array{slug:string, label:string, url:string, parent_slug:string}>>,
	 * }|null
	 */
	private ?array $collected = null;

	/**
	 * Ordered columns (ColumnModel). Source of truth for the v2 rail rendering.
	 *
	 * @var array<int, array<string,mixed>>|null
	 */
	private ?array $columns = null;

	public function boot(): void {
		add_action( 'admin_menu', [ $this, 'collect' ], 99999 );
		add_action( 'admin_head', [ $this, 'output_rail_template' ], 999 );
		add_action( 'admin_footer', [ $this, 'output_drag_aids' ], 1 );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
	}

	/**
	 * 3 helper elements floated at body-level that `admin.js` section 4
	 * (resizable rail) queries during drag :
	 *   - `.dy-rail-ghost`           : vertical line tracking the cursor x
	 *   - `.dy-rail-snap-marker[data-target=collapsed]` : indicator at the
	 *     collapsed snap position, lights up when the drag will commit to it
	 *   - `.dy-rail-snap-marker[data-target=expanded]`  : same for expanded
	 *
	 * In the mockup these are inserted by `_shell.js` at boot. In production
	 * we attach them on `admin_footer` so they're guaranteed to be in the
	 * DOM by the time the user can interact with the rail. They're
	 * invisible by default (CSS controls opacity); only visible during a
	 * drag gesture initiated from `.dy-rail-handle`.
	 */
	public function output_drag_aids(): void {
		echo '<div class="dy-rail-ghost" aria-hidden="true"></div>';
		echo '<div class="dy-rail-snap-marker" data-target="collapsed" aria-hidden="true"></div>';
		echo '<div class="dy-rail-snap-marker" data-target="expanded" aria-hidden="true"></div>';
	}

	public function collect(): void {
		global $menu;

		// Detection of unknown plugins (editor's "Detected" drawer).
		// Iterate the live $menu to record slugs that no rule can classify
		// confidently — independent of rendering.
		$overrides_classifications = (array) ( NavigationOverrides::get()['classifications'] ?? [] );
		if ( is_array( $menu ) ) {
			foreach ( $menu as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
					continue;
				}
				$slug  = PluginMap::normalize_slug( (string) $entry[2] );
				$label = $this->strip_label( (string) ( $entry[0] ?? '' ) );
				if ( '' === $label ) {
					continue;
				}
				if (
					! PluginMap::is_curated( $slug )
					&& ! isset( $overrides_classifications[ $slug ] )
					&& null === PluginMap::guess_line_strict( $slug )
				) {
					NavigationDetector::record_unmapped( $slug, $label, PluginMap::guess_line( $slug ) );
				}
			}
		}

		// Native tree: `$menu` is not final on `admin_menu` (core filters by
		// capability and sorts afterwards). The columns are built lazily at
		// render time from the finished menu; nothing to collect here.
		if ( Settings::is_native_navigation() ) {
			$this->columns   = null;
			$this->collected = [ 'builders' => [], 'lines' => [] ];
			NavigationDetector::flush();
			return;
		}

		// Tree: shared source of truth (ColumnModel) — applies column_order,
		// custom groups, labels/icons, promotions, demotions, attachment of
		// builder satellite pages, etc.
		ColumnModel::flush_cache();
		$this->columns = ColumnModel::columns();

		// Legacy `builders` format (consumed by markup_menu for compat) +
		// `lines` keyed by column id (consumed by Chipbar::lines()).
		$builders = [];
		$lines    = [];
		foreach ( $this->columns as $col ) {
			$lines[ $col['id'] ] = $col['chips'];
			if ( 'standalone' === $col['kind'] ) {
				$builders[] = [
					'slug'  => $col['id'],
					'icon'  => $col['icon'],
					'label' => $col['label'],
					'url'   => $col['url'],
				];
			}
		}
		$this->collected = compact( 'builders', 'lines' );

		// Persists the accumulated detections (single write per page load).
		NavigationDetector::flush();
	}

	/**
	 * Looks up a slug in `$submenu` (all branches) and returns its resolved
	 * metadata (label, url, cap). Used to promote sub-pages to top-level chips.
	 *
	 * @return array{label:string, url:string, cap:string, parent:string}|null
	 */
	private function find_in_submenu( string $slug ): ?array {
		global $submenu;
		if ( ! is_array( $submenu ) ) {
			return null;
		}
		foreach ( $submenu as $parent => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $entry ) {
				if ( ! is_array( $entry ) || ( $entry[2] ?? '' ) !== $slug ) {
					continue;
				}
				$label = $this->strip_label( (string) ( $entry[0] ?? '' ) );
				$cap   = (string) ( $entry[1] ?? '' );
				// URL resolution identical to Subchipbar.
				$parent_str = (string) $parent;
				if ( str_contains( $slug, '.php' ) || str_contains( $slug, '?' ) ) {
					$url = admin_url( $slug );
				} elseif ( str_contains( $parent_str, '.php' ) ) {
					$glue = str_contains( $parent_str, '?' ) ? '&' : '?';
					$url  = admin_url( $parent_str . $glue . 'page=' . rawurlencode( $slug ) );
				} else {
					$url = admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
				}
				return [
					'label'  => '' !== $label ? $label : $slug,
					'url'    => $url,
					'cap'    => $cap,
					'parent' => $parent_str,
				];
			}
		}
		return null;
	}

	/**
	 * Looks up the label of a slug in `$submenu` (all branches), returning the
	 * native WP translation if found — otherwise `$fallback` (expected in English,
	 * displayed as-is).
	 *
	 * Used to promote sub-menus to chipbar items while keeping their native
	 * localized label (i18n-free).
	 */
	private function lookup_submenu_label( string $slug, string $fallback ): string {
		global $submenu;
		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $items ) {
				if ( ! is_array( $items ) ) {
					continue;
				}
				foreach ( $items as $entry ) {
					if ( is_array( $entry ) && ( $entry[2] ?? '' ) === $slug ) {
						$label = $this->strip_label( (string) ( $entry[0] ?? '' ) );
						if ( '' !== $label ) {
							return $label;
						}
					}
				}
			}
		}
		return $fallback;
	}

	private function menu_url( string $slug ): string {
		// Check for an override (top-level with null callback like
		// WooCommerce — `admin.php?page=woocommerce` returns WP 404).
		$override = PluginMap::url_override( $slug );
		if ( null !== $override ) {
			return admin_url( $override );
		}
		if ( str_contains( $slug, '.php' ) ) {
			return admin_url( $slug );
		}
		return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
	}

	private function strip_label( string $raw ): string {
		$clean = preg_replace( '/<span[^>]*>.*?<\/span>/s', '', $raw ) ?? $raw;
		return trim( wp_strip_all_tags( $clean ) );
	}

	public function active_line(): ?string {
		// Resolves via ColumnModel (therefore respecting overrides:
		// reclassification, custom groups). Returns null if the active column
		// is a standalone slot (builder/theme) — in that case active_builder_slug()
		// drives the chipbar.
		$id = $this->active_column_id();
		if ( null === $id ) {
			return null;
		}
		foreach ( ColumnModel::columns() as $col ) {
			if ( $col['id'] === $id ) {
				return ( 'standalone' === $col['kind'] ) ? null : $id;
			}
		}
		return null;
	}

	/**
	 * Slug of the current SUB-menu entry, the way wp-admin/menu-header.php
	 * decides which sub-item is current: `$submenu_file` when the screen set
	 * it, else the plugin page, else the script itself. Normalised like every
	 * slug we compare. Used by the chipbar in native-tree mode.
	 */
	public static function current_submenu_slug(): string {
		global $submenu_file, $plugin_page, $self, $pagenow, $typenow;
		if ( isset( $submenu_file ) && '' !== (string) $submenu_file ) {
			return PluginMap::normalize_slug( (string) $submenu_file );
		}
		if ( isset( $plugin_page ) && '' !== (string) $plugin_page ) {
			return PluginMap::normalize_slug( (string) $plugin_page );
		}
		$script = isset( $self ) && '' !== (string) $self ? (string) $self : (string) $pagenow;
		if ( 'edit.php' === $script && $typenow && 'post' !== $typenow ) {
			$script .= '?post_type=' . $typenow;
		}
		return PluginMap::normalize_slug( $script );
	}

	/**
	 * Id of the COLUMN (ColumnModel) that contains the current top-level slug.
	 * Respects overrides (reclassification, custom groups, satellites absorbed
	 * by a builder). Used to highlight the correct column in the live rail —
	 * the old `active_line()` (raw classify) ignored overrides and highlighted
	 * the wrong line.
	 */
	public function active_column_id(): ?string {
		$slug = self::resolve_top_level_slug();
		if ( ! $slug ) {
			return null;
		}
		$slug = PluginMap::normalize_slug( $slug );
		foreach ( ColumnModel::columns() as $col ) {
			if ( 'native' === ( $col['kind'] ?? '' ) && ( $col['id'] ?? '' ) === $slug ) {
				return (string) $col['id'];
			}
		}
		foreach ( ColumnModel::columns() as $col ) {
			foreach ( ( $col['chips'] ?? [] ) as $chip ) {
				if ( ( $chip['slug'] ?? '' ) === $slug ) {
					return (string) $col['id'];
				}
				foreach ( ( $chip['subitems'] ?? [] ) as $si ) {
					if ( ( $si['slug'] ?? '' ) === $slug ) {
						return (string) $col['id'];
					}
				}
			}
		}
		return null;
	}

	public function active_builder_slug(): ?string {
		if ( Settings::is_native_navigation() ) {
			return null;
		}
		$slug = self::resolve_top_level_slug();
		if ( ! $slug ) {
			return null;
		}
		$builders = PluginMap::standalone_builders();
		return isset( $builders[ $slug ] ) ? $slug : null;
	}

	/**
	 * Resolves the top-level PARENT slug for the current page.
	 *
	 * For WP sub-pages (`?page=foo-subpage` under the top-level `foo`),
	 * returns 'foo' instead of 'foo-subpage'. Critical for:
	 *   - mapping Double Youpi > Overview (slug `doubleyoupi-overview`)
	 *     to the 'doubleyoupi' chip in the Developer chipbar
	 *   - preventing the heuristic from falling through to catch-all=system for
	 *     custom module slugs
	 *   - attaching a CPT registered as a sub-menu of another top-level
	 *     (e.g. `bricks_template` under `bricks`) to its true parent instead
	 *     of falling back to the 'content' line via heuristic
	 *
	 * Algorithm:
	 *   1. Determine a "candidate" slug from `$plugin_page`,
	 *      `$parent_file`, or `$pagenow` (in order of reliability).
	 *   2. If the candidate exists as a top-level in `$menu`, return it.
	 *   3. Otherwise search the candidate in `$submenu[*]` — if found as a
	 *      sub-menu of a top-level, return that parent. This is the critical
	 *      case for `edit.php?post_type=bricks_template` which Bricks registers
	 *      via `add_submenu_page('bricks', …, 'edit.php?post_type=…')`.
	 *   4. Fallback: the candidate as-is.
	 */
	public static function resolve_top_level_slug(): ?string {
		global $parent_file, $plugin_page, $pagenow, $menu, $submenu, $typenow;

		// Native WP sub-menus promoted to chipbar items by Rail::collect().
		// WP registers them as sub-menus of another top-level (themes.php,
		// plugins.php, tools.php, index.php) → `$parent_file` returns the
		// parent and the classification lands on the wrong line. We short-circuit
		// by returning `$pagenow` (= the real screen slug) so that `classify()`
		// sees the correct mapping.
		//   - theme-editor.php / plugin-editor.php → 'developer'
		//   - site-health.php / update-core.php    → 'system'
		if ( in_array( $pagenow, [ 'theme-editor.php', 'plugin-editor.php', 'site-health.php', 'update-core.php' ], true ) ) {
			return (string) $pagenow;
		}

		// Split menus (PluginMap::SPLIT_MENUS, Appearance): in the reimagined
		// tree every sub-page is a chip of its own, so the screen resolves to
		// ITS slug, not to the parent (else the Editor would light the System
		// column, where the Themes chip lives). The native tree keeps the menu.
		if ( ! Settings::is_native_navigation() ) {
			foreach ( PluginMap::split_menus() as $split ) {
				if ( (string) $parent_file !== $split && (string) $pagenow !== $split ) {
					continue;
				}
				$own = $plugin_page ? (string) $plugin_page : (string) $pagenow;
				if ( $own === $split ) {
					return $split;
				}
				foreach ( (array) ( $submenu[ $split ] ?? [] ) as $item ) {
					if ( is_array( $item ) && ( $item[2] ?? '' ) === $own ) {
						return $own;
					}
				}
			}
		}

		// Build candidates to test in order of reliability.
		// For edit.php?post_type=X, we explicitly build the slug with the query
		// string because `$parent_file` may be bare 'edit.php' if the
		// menu-header.php hook has not yet resolved the post type.
		$candidates = [];
		if ( $plugin_page ) {
			$candidates[] = (string) $plugin_page;
		}
		if ( 'edit.php' === $pagenow && $typenow ) {
			$candidates[] = 'edit.php?post_type=' . $typenow;
		}
		if ( $parent_file ) {
			$candidates[] = (string) $parent_file;
		}
		if ( $pagenow ) {
			$candidates[] = (string) $pagenow;
		}

		foreach ( $candidates as $slug ) {
			// (a) Direct top-level?
			if ( is_array( $menu ) ) {
				foreach ( $menu as $entry ) {
					if ( is_array( $entry ) && ( $entry[2] ?? '' ) === $slug ) {
						return $slug;
					}
				}
			}
			// (b) Sub-menu of a top-level?
			if ( is_array( $submenu ) ) {
				foreach ( $submenu as $parent => $items ) {
					if ( ! is_array( $items ) ) {
						continue;
					}
					foreach ( $items as $item ) {
						if ( is_array( $item ) && ( $item[2] ?? '' ) === $slug ) {
							return (string) $parent;
						}
					}
				}
			}
		}

		// Fallback: first non-empty candidate.
		return $candidates[0] ?? null;
	}

	/**
	 * Outputs the <template> containing the rail + the injection script.
	 * Emitted in `<head>` at priority 999 (very late, after all print_styles
	 * and before body parsing begins).
	 */
	public function output_rail_template(): void {
		if ( null === $this->collected ) {
			$this->collect();
		}
		if ( Settings::is_native_navigation() ) {
			$this->columns = ColumnModel::columns();
		}
		$active_id      = $this->active_column_id();
		$active_builder = $this->active_builder_slug();

		$rail_html = $this->markup_brand()
			. $this->markup_menu( $active_id, $active_builder )
			. $this->markup_handles();
		// Hide the native menu during parsing to prevent a flash. Kept
		// conditional on body.js: if JS is disabled, the native menu remains
		// visible (graceful fallback). WP automatically swaps no-js → js.
		wp_register_style( 'doubleyoupi-admin-rail-pre-paint', false, [], DOUBLEYOUPI_VERSION );
		wp_add_inline_style( 'doubleyoupi-admin-rail-pre-paint', self::PRE_PAINT_CSS );
		wp_print_styles( 'doubleyoupi-admin-rail-pre-paint' );

		printf(
			'<template id="dy-admin-rail-tpl">%s</template>' . "\n",
			wp_kses( $rail_html, Kses::ui_tags() )
		);
		wp_print_inline_script_tag( self::INJECTOR_JS, [ 'id' => 'doubleyoupi-admin-rail-injector' ] );
	}

	private function markup_brand(): string {
		$settings   = Settings::instance();
		$logo_url   = $settings->logo_url();
		$dashboard  = esc_url( admin_url( 'index.php' ) );
		$home_label = esc_attr__( 'Dashboard', 'doubleyoupi-refined-admin-dashboard' );
		$brand_name = esc_html__( 'Admin', 'doubleyoupi-refined-admin-dashboard' );

		$inner = $logo_url
			? '<img src="' . esc_url( $logo_url ) . '" alt="" />'
			: self::default_logo_svg();

		// Concatenation (not heredoc: PluginCheck.CodeAnalysis.Heredoc.NotAllowed).
		// Values escaped at insertion; $inner is markup built with esc_url above.
		return '<a class="dy-rail-brand" href="' . esc_url( $dashboard ) . '"'
			. ' aria-label="' . esc_attr( $home_label ) . '" title="' . esc_attr( $home_label ) . '">'
			. '<span class="dy-rail-logo" aria-hidden="true">' . $inner . '</span>'
			. '<span class="dy-rail-brand-name">' . esc_html( $brand_name ) . '</span>'
			. '</a>';
	}

	public static function default_logo_svg(): string {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$path = Build::dir( __FILE__, 'assets/img/icon-doubleyoupi.svg' );
		if ( file_exists( $path ) ) {
			$svg = (string) file_get_contents( $path );
			$svg = preg_replace( '/\sfill="#[0-9a-fA-F]+"/', ' fill="currentColor"', $svg );
			$cache = $svg ?: 'Y';
		} else {
			$cache = 'Y';
		}
		return $cache;
	}

	private function markup_menu( ?string $active_id, ?string $active_builder ): string {
		// Current chip, to mark it in the flyouts (native: the current sub-menu
		// entry; reimagined: the resolved screen slug, a chip slug).
		$current = Settings::is_native_navigation()
			? self::current_submenu_slug()
			: PluginMap::normalize_slug( (string) self::resolve_top_level_slug() );
		// Use id="adminmenu" so the existing rail SCSS (body.dy-admin
		// #adminmenu li.menu-top { ... }) matches our markup. We replace
		// the native menu entirely via JS injection.
		//
		// COLUMN-DRIVEN RENDERING (ColumnModel) — same source of truth as the
		// Reorganize editor. Consequence: column order (column_order), custom
		// groups, and renamed labels/icons take effect in the live rail.
		$out     = '<ul id="adminmenu">';
		$columns = $this->columns ?? [];

		// Bottom zone: insert a flex spacer before the first "tool" column
		// (standalone builder/theme, custom group, or Developer) so that
		// content/commerce/users/… stay at the top and tools anchor at the
		// bottom — without breaking the order chosen by the user.
		$spacer_before = null;
		$native_tree   = Settings::is_native_navigation();
		foreach ( $columns as $col ) {
			if ( $native_tree ) {
				break; // the native order is the order: no bottom zone
			}
			if ( 'standalone' === $col['kind'] || 'custom' === $col['kind'] || 'developer' === $col['id'] ) {
				$spacer_before = (string) $col['id'];
				break;
			}
		}

		$spacer_emitted = false;
		foreach ( $columns as $col ) {
			$id    = (string) $col['id'];
			$kind  = (string) $col['kind'];
			$chips = $col['chips'] ?? [];

			// Native tree: one entry per top-level WordPress menu item, with
			// the icon WordPress itself declares; separators stay separators.
			if ( 'separator' === $kind ) {
				$out .= '<li class="dy-rail-divider" aria-hidden="true"></li>';
				continue;
			}
			if ( 'native' === $kind ) {
				$nurl = (string) ( $col['url'] ?? '' );
				if ( '' === $nurl ) {
					continue;
				}
				$ncls = 'menu-top is-native';
				if ( null !== $active_id && $id === $active_id ) {
					$ncls .= ' current is-active';
				}
				$out .= '<li class="' . esc_attr( $ncls ) . '"><a href="' . esc_url( $nurl ) . '" class="menu-top">'
					. (string) ( $col['icon_html'] ?? '' ) // built by ColumnModel with esc_url/esc_attr/sanitize_html_class
					. '<div class="wp-menu-name">' . esc_html( (string) $col['label'] ) . '</div></a>'
					. $this->markup_flyout( (string) $col['label'], (array) ( $col['chips'] ?? [] ), $current )
					. '</li>';
				continue;
			}

			// Empty line/custom columns: nothing to point to → don't clutter the
			// rail (they reappear as soon as they have a chip). Standalone columns
			// always display (the builder is the destination, even without sub-pages).
			if ( 'standalone' !== $kind && empty( $chips ) ) {
				continue;
			}

			if ( ! $spacer_emitted && $id === $spacer_before ) {
				$out           .= '<li class="dy-rail-divider is-spacer" aria-hidden="true"></li>';
				$spacer_emitted = true;
			}

			$url = (string) ( $col['url'] ?? ( $chips[0]['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}

			$cls = 'menu-top';
			if ( 'line' === $kind ) {
				$cls .= ' line-' . $id;
			} elseif ( 'custom' === $kind ) {
				$cls .= ' is-custom';
			} elseif ( str_starts_with( $id, 'theme:' ) ) {
				$cls .= ' is-theme';
			} else {
				$cls .= ' is-builder builder-' . $id;
			}
			if ( ( null !== $active_id && $id === $active_id ) || ( null !== $active_builder && $id === $active_builder ) ) {
				$cls .= ' current is-active';
			}

			$out .= sprintf(
				'<li class="%1$s"><a href="%2$s" class="menu-top"><div class="wp-menu-image" aria-hidden="true"><svg width="18" height="18"><use href="#%3$s"/></svg></div><div class="wp-menu-name">%4$s</div></a>%5$s</li>',
				esc_attr( $cls ),
				esc_url( $url ),
				esc_attr( (string) $col['icon'] ),
				esc_html( (string) $col['label'] ),
				$this->markup_flyout( (string) $col['label'], (array) $chips, $current )
			);
		}

		$out .= '</ul>';
		return $out;
	}

	/**
	 * The pages of a section, opened next to its rail entry on hover
	 * (admin.js, section 5bis): a card listing the chips of the column, and
	 * their sub-pages when the setting asks for two levels. Rendered inside
	 * the entry and moved to <body> by the script (#adminmenuwrap scrolls).
	 * Every dynamic part is escaped; the icon comes from Chipbar::chip_icon_html().
	 *
	 * @param array<int,array<string,mixed>> $chips
	 */
	private function markup_flyout( string $label, array $chips, string $current ): string {
		$mode = Settings::rail_flyout_mode();
		if ( 'off' === $mode || empty( $chips ) ) {
			return '';
		}
		$out = '<div class="dy-rail-fly" role="group" aria-label="' . esc_attr( $label ) . '" hidden>'
			. '<div class="dy-rail-fly__head">' . esc_html( $label ) . '</div><ul class="dy-rail-fly__list">';
		foreach ( $chips as $chip ) {
			$slug = (string) ( $chip['slug'] ?? '' );
			$url  = (string) ( $chip['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$cls  = 'dy-rail-fly__item' . ( '' !== $current && $slug === $current ? ' is-current' : '' );
			$out .= '<li><a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">'
				. Chipbar::chip_icon_html( $slug )
				. '<span class="dy-rail-fly__label">' . esc_html( (string) ( $chip['label'] ?? $slug ) ) . '</span></a>';
			if ( 'two' === $mode && ! empty( $chip['subitems'] ) && is_array( $chip['subitems'] ) ) {
				$out .= '<ul class="dy-rail-fly__sub">';
				foreach ( $chip['subitems'] as $sub ) {
					$sub_url = (string) ( $sub['url'] ?? '' );
					if ( '' === $sub_url ) {
						continue;
					}
					$out .= '<li><a class="dy-rail-fly__subitem" href="' . esc_url( $sub_url ) . '">' . esc_html( (string) ( $sub['label'] ?? '' ) ) . '</a></li>';
				}
				$out .= '</ul>';
			}
			$out .= '</li>';
		}
		return $out . '</ul></div>';
	}

	/**
	 * Resize handle + toggle button — consumed by admin.js section 4.
	 */
	private function markup_handles(): string {
		$resize_label = esc_attr__( 'Resize navigation', 'doubleyoupi-refined-admin-dashboard' );
		$toggle_label = esc_attr__( 'Toggle navigation width', 'doubleyoupi-refined-admin-dashboard' );
		// Concatenation (not heredoc: PluginCheck.CodeAnalysis.Heredoc.NotAllowed).
		// $resize_label / $toggle_label already escaped via esc_attr__ above.
		return '<button type="button" class="dy-rail-handle" role="separator" aria-orientation="vertical" aria-label="' . $resize_label . '"></button>'
			. '<button type="button" class="dy-rail-toggle" aria-label="' . $toggle_label . '" title="' . $toggle_label . '">'
			. '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
			. '</button>';
	}

	/**
	 * Kind ('line' | 'standalone' | 'custom') de la colonne active, ou null si
	 * aucune colonne ne contient le slug courant.
	 */
	private function active_column_kind(): ?string {
		$id = $this->active_column_id();
		if ( null === $id ) {
			return null;
		}
		foreach ( ColumnModel::columns() as $col ) {
			if ( $col['id'] === $id ) {
				return (string) $col['kind'];
			}
		}
		return null;
	}

	public function body_class( string $classes ): string {
		$classes .= ' dy-admin';

		// Native tree: no section colours; the accent drives the chrome.
		if ( Settings::is_native_navigation() ) {
			return $classes . ' dy-line-default dy-nav-native';
		}

		// Standalone builders (Bricks, Elementor…) and custom reorganizer
		// groups have no dedicated line color. Rather than letting their
		// accented elements (chipbar, eyebrow, title cursor, filters) fall
		// back to the `content` line color, we set the `dy-line-default`
		// sentinel, which maps `--dya-line-color` onto the default accent
		// (see _layout.scss).
		$kind = $this->active_column_kind();
		if ( 'standalone' === $kind || 'custom' === $kind ) {
			$classes .= ' dy-line-default';
			return $classes;
		}

		$active = $this->active_line();
		if ( $active ) {
			$classes .= ' dy-line-' . $active;
		}
		return $classes;
	}

	/**
	 * @return array<string, array<int, array{slug:string, label:string, url:string, parent_slug:string}>>
	 */
	public function lines(): array {
		if ( null === $this->collected ) {
			$this->collect();
		}
		if ( Settings::is_native_navigation() ) {
			$lines = [];
			foreach ( ColumnModel::columns() as $col ) {
				if ( 'native' === ( $col['kind'] ?? '' ) ) {
					$lines[ (string) $col['id'] ] = $col['chips'] ?? [];
				}
			}
			return $lines;
		}
		return $this->collected['lines'] ?? [];
	}

	/**
	 * @return array<int, array{slug:string, icon:string, label:string, url:string}>
	 */
	public function builders(): array {
		if ( null === $this->collected ) {
			$this->collect();
		}
		return $this->collected['builders'] ?? [];
	}
}

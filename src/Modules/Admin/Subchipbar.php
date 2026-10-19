<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subchipbar — taxonomy / sub-tab bar below the main chipbar.
 *
 * For the current top-level (e.g. Posts), displays its useful WP sub-pages
 * (Categories, Tags), or the significant tabs from plugins (e.g. Yoast
 * settings tabs, WooCommerce sub-views).
 *
 * For each current chip (parent chipbar), reads `$submenu[$parent_slug]`
 * and builds the list. Filters:
 *   - skip "Add New" / "Editor" / "Customize" which duplicate the H1 actions
 *   - skip if fewer than 2 sub-items (nothing to show as sub-tabs)
 */
final class Subchipbar {

	private Rail $rail;

	public function __construct( Rail $rail ) {
		$this->rail = $rail;
	}

	public function boot(): void {
		add_action( 'in_admin_header', [ $this, 'render' ], 7 );
	}

	public function render(): void {
		// Native tree: WordPress has two levels only, both drawn by the rail
		// and the chipbar; a third bar would repeat the chipbar.
		if ( Settings::is_native_navigation() ) {
			echo '<nav class="dy-subchipbar" id="dy-admin-subchipbar" hidden aria-hidden="true"></nav>';
			return;
		}
		$active_line = $this->rail->active_line();
		if ( ! $active_line ) {
			echo '<nav class="dy-subchipbar" id="dy-admin-subchipbar" hidden aria-hidden="true"></nav>';
			return;
		}

		$parent_slug = $this->current_parent_slug();

		// Split menu (Appearance): its pages are chips of their own, nothing
		// lives under them, and the Themes chip must not list the whole menu.
		if ( PluginMap::is_split_menu( $parent_slug ) ) {
			echo '<nav class="dy-subchipbar" id="dy-admin-subchipbar" hidden aria-hidden="true"></nav>';
			return;
		}

		$subitems    = $this->collect_submenu_items( $parent_slug );

		if ( count( $subitems ) < 2 ) {
			echo '<nav class="dy-subchipbar" id="dy-admin-subchipbar" hidden aria-hidden="true"></nav>';
			return;
		}

		$current_url = $this->current_url();

		printf(
			'<nav class="dy-subchipbar" id="dy-admin-subchipbar" data-line="line-%s" aria-label="%s">',
			esc_attr( $active_line ),
			esc_attr__( 'Sub-section navigation', 'doubleyoupi-refined-admin-dashboard' )
		);
		echo '<div class="dy-subchipbar-inner">';

		foreach ( $subitems as $item ) {
			$is_current = $this->is_current_subitem( $item, $current_url ) ? ' current' : '';
			echo '<a class="' . esc_attr( 'dy-subchip' . $is_current ) . '" href="' . esc_url( $item['url'] ) . '"><span class="dot" aria-hidden="true"></span>' . esc_html( $item['label'] ) . '</a>';
		}

		echo '</div></nav>';
	}

	private function current_parent_slug(): string {
		return Rail::resolve_top_level_slug() ?? '';
	}

	private function current_url(): string {
		// Reconstruct the URL with query string the user is currently on.
		$qs = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
		$base = admin_url( basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ) );
		return $qs ? $base . '?' . $qs : $base;
	}

	/**
	 * Lists the sub-items of the current parent — filters out noise.
	 *
	 * Filtering by SLUG (i18n-agnostic) rather than by label:
	 *   - skip `post-new.php` and `post-new.php?post_type=X` (Add New, redundant
	 *     with the .page-title-action button next to the H1)
	 *   - skip slug exactly equal to the parent (first sub-item duplicating the
	 *     parent, e.g. "All Posts" for edit.php — already where we are)
	 *   - skip customize.php / theme-editor.php / plugin-editor.php
	 *
	 * @return array<int, array{label:string, url:string, slug:string}>
	 */
	private function collect_submenu_items( string $parent_slug ): array {
		global $submenu;
		if ( ! is_array( $submenu ) || empty( $submenu[ $parent_slug ] ) ) {
			return [];
		}

		// Slug prefixes (handle query-string variants like
		// `customize.php?return=…`, `post-new.php?post_type=page`, etc.)
		// and exacts to skip — duplicates of header buttons or non-section
		// pages (Customizer, Theme/Plugin Editor, Add New variants).
		$skip_slug_starts = [
			'post-new.php',
			'user-new.php',
			'media-new.php',
			'link-add.php',
			'customize.php',    // Customizer (may have ?return= query)
			'plugin-install.php',
			'theme-install.php',
			'theme-editor.php',
			'plugin-editor.php',
		];
		$skip_slug_exact  = [];

		$out = [];
		foreach ( $submenu[ $parent_slug ] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}
			$slug  = (string) $entry[2];
			$label = $this->strip_label( (string) ( $entry[0] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			// NB: we keep the sub-item whose slug equals the parent.
			// For `themes.php` this yields "Themes" as the first chip, equivalent
			// to "All Posts" for edit.php. This is intentional — the user should
			// be able to navigate back to the section home from the subchipbar
			// (e.g. from Patterns back to Themes).
			// Skip all Add New / post-new variants.
			$skip = false;
			foreach ( $skip_slug_starts as $needle ) {
				if ( str_starts_with( $slug, $needle ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip || in_array( $slug, $skip_slug_exact, true ) ) {
				continue;
			}

			// User override: sub-item hidden.
			if ( NavigationOverrides::is_subitem_hidden( $parent_slug, $slug ) ) {
				continue;
			}

			$out[] = [
				'label' => $label,
				'slug'  => $slug,
				'url'   => $this->resolve_submenu_url( $slug, $parent_slug ),
			];
		}

		// Inject demoted chips (chip → sub-page). For each chip whose
		// target parent is the current item, look up its metadata in the
		// global $menu and add it as a synthetic sub-item.
		foreach ( NavigationOverrides::demoted() as $chip_slug => $target ) {
			[ $line, $target_parent ] = explode( '::', $target, 2 );
			unset( $line );
			if ( $target_parent !== $parent_slug ) {
				continue;
			}
			if ( NavigationOverrides::is_chip_hidden( $chip_slug ) ) {
				continue;
			}
			$meta = $this->lookup_menu_meta( $chip_slug );
			if ( null === $meta ) {
				continue;
			}
			$out[] = [
				'label' => $meta['label'],
				'slug'  => $chip_slug,
				'url'   => $meta['url'],
			];
		}

		// Reorder by user override (subitem_order). The listed slugs come
		// first in the provided order; the rest follow.
		$custom_order = NavigationOverrides::subitem_order_for( $parent_slug );
		if ( null !== $custom_order && ! empty( $out ) ) {
			$by_slug = [];
			foreach ( $out as $item ) {
				$by_slug[ $item['slug'] ] = $item;
			}
			$reordered = [];
			foreach ( $custom_order as $ordered_slug ) {
				if ( isset( $by_slug[ $ordered_slug ] ) ) {
					$reordered[] = $by_slug[ $ordered_slug ];
					unset( $by_slug[ $ordered_slug ] );
				}
			}
			foreach ( $by_slug as $remaining ) {
				$reordered[] = $remaining;
			}
			$out = $reordered;
		}

		return $out;
	}

	/**
	 * Looks up a slug in the global `$menu` (top-level) and resolves its
	 * label + URL. Used to materialize a demoted chip as a sub-item.
	 *
	 * @return array{label:string, url:string}|null
	 */
	private function lookup_menu_meta( string $slug ): ?array {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return null;
		}
		foreach ( $menu as $entry ) {
			if ( ! is_array( $entry ) || ( $entry[2] ?? '' ) !== $slug ) {
				continue;
			}
			$label = $this->strip_label( (string) ( $entry[0] ?? '' ) );
			$url   = str_contains( $slug, '.php' )
				? admin_url( $slug )
				: admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
			return [
				'label' => '' !== $label ? $label : $slug,
				'url'   => $url,
			];
		}
		return null;
	}

	private function resolve_submenu_url( string $slug, string $parent_slug ): string {
		// Taxonomy sub-menus carry `&amp;` in their slug (wp-admin/menu.php):
		// decoded, or the URL keeps a literal `&amp;` and the current-item
		// comparison reads a `amp;post_type` parameter ("Secteurs" never
		// current on its own screen, audit du banc, 14/09/2026).
		$slug = str_replace( '&amp;', '&', $slug );
		// If the slug contains .php or a query string, it is a standalone URL.
		if ( str_contains( $slug, '.php' ) || str_contains( $slug, '?' ) ) {
			return admin_url( $slug );
		}
		// Otherwise it is a sub-slug ?page=…&parent=…
		// WP routes automatically via parent_slug for .php parents.
		// Dynamic glue: if parent_slug already contains ? (e.g. ACF
		// `edit.php?post_type=acf-field-group`), continue with &. Otherwise
		// open the query string. Without this, we would emit
		// `edit.php?post_type=acf-field-group?page=acf-tools` (double ?)
		// which breaks with "Invalid post type" on the WP side.
		if ( str_contains( $parent_slug, '.php' ) ) {
			$glue = str_contains( $parent_slug, '?' ) ? '&' : '?';
			return admin_url( $parent_slug . $glue . 'page=' . rawurlencode( $slug ) );
		}
		return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
	}

	private function strip_label( string $raw ): string {
		return ColumnModel::strip_label( $raw );
	}

	private function is_current_subitem( array $item, string $current_url ): bool {
		// Case 1 — `admin.php?page=…` sub-page: compare the exact `page` param.
		// Why: `strpos($url, $slug)` matched as a substring, so the slug
		// `bricks` would declare itself current on `?page=bricks-settings`. Result:
		// the first sub-item (Getting started) stayed active everywhere in Bricks.
		// How to apply: if the sub-item URL carries a `page=`, switch to exact
		// match; otherwise compare the basename + key params (post_type,
		// taxonomy) for native WP screens (edit.php, edit-tags.php…).
		$item_page    = $this->extract_page_param( $item['url'] );
		$current_page = $this->extract_page_param( $current_url );

		if ( $item_page !== null ) {
			return $item_page === $current_page;
		}

		// The current URL is on `admin.php?page=…` but the sub-item is a
		// standalone .php — not current.
		if ( $current_page !== null ) {
			return false;
		}

		// Case 2 — native .php pages: compare basename + post_type / taxonomy.
		$item_path    = basename( (string) wp_parse_url( $item['url'], PHP_URL_PATH ) );
		$current_path = basename( (string) wp_parse_url( $current_url, PHP_URL_PATH ) );
		if ( $item_path !== $current_path ) {
			return false;
		}

		parse_str( (string) wp_parse_url( $item['url'],    PHP_URL_QUERY ), $item_qs );
		parse_str( (string) wp_parse_url( $current_url,    PHP_URL_QUERY ), $current_qs );
		foreach ( [ 'post_type', 'taxonomy' ] as $key ) {
			$iv = isset( $item_qs[ $key ] )    ? (string) $item_qs[ $key ]    : '';
			$cv = isset( $current_qs[ $key ] ) ? (string) $current_qs[ $key ] : '';
			if ( $iv !== $cv ) {
				return false;
			}
		}
		return true;
	}

	private function extract_page_param( string $url ): ?string {
		$qs = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $qs ) || '' === $qs ) {
			return null;
		}
		parse_str( $qs, $params );
		return isset( $params['page'] ) ? (string) $params['page'] : null;
	}
}

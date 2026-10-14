<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chipbar — horizontal navigation bar below the admin bar.
 *
 * For the active rail line, renders one chip per top-level item
 * collected by Rail::lines(). The current chip (matching the current
 * $parent_file) is marked .current and receives the dy-track underline
 * via existing CSS.
 *
 * Read-only from the collection built by Rail — no globals re-read here,
 * avoids the double load.
 */
final class Chipbar {

	private Rail $rail;

	public function __construct( Rail $rail ) {
		$this->rail = $rail;
	}

	public function boot(): void {
		add_action( 'in_admin_header', [ $this, 'render' ], 6 );
	}

	public function render(): void {
		// Builder with externally managed UI (Elementor…): it drives its own
		// internal nav; our chipbar would be redundant and overflow into its interface.
		// We step back (see BuilderScreen / CLAUDE.md §2).
		if ( BuilderScreen::is_managed() ) {
			echo '<nav class="dy-chipbar" id="dy-admin-chipbar" hidden aria-hidden="true"></nav>';
			return;
		}

		// Native tree: the chipbar is the sub-menu of the current top-level
		// entry, exactly as WordPress lists it.
		if ( Settings::is_native_navigation() ) {
			$this->render_native();
			return;
		}

		$active_line    = $this->rail->active_line();
		$active_builder = $this->rail->active_builder_slug();

		// STANDALONE BUILDER case (e.g. Bricks) — no active "line", but
		// the builder often has its own sub-pages that we expose in the
		// chipbar to allow navigation within the tool.
		if ( $active_builder ) {
			$builder_items = $this->collect_builder_subpages( $active_builder );
			if ( ! empty( $builder_items ) ) {
				$this->render_builder_chipbar( $active_builder, $builder_items );
				return;
			}
			// No sub-pages → hide the chipbar.
			echo '<nav class="dy-chipbar" id="dy-admin-chipbar" hidden aria-hidden="true"></nav>';
			return;
		}

		if ( ! $active_line ) {
			// Dashboard — no chipbar.
			echo '<nav class="dy-chipbar" id="dy-admin-chipbar" hidden aria-hidden="true"></nav>';
			return;
		}

		$lines = $this->rail->lines();
		$items = $lines[ $active_line ] ?? [];

		if ( empty( $items ) ) {
			echo '<nav class="dy-chipbar" id="dy-admin-chipbar" hidden aria-hidden="true"></nav>';
			return;
		}

		$current_slug = $this->current_parent_slug();

		printf(
			'<nav class="dy-chipbar" id="dy-admin-chipbar" data-line="line-%s" aria-label="%s">',
			esc_attr( $active_line ),
			esc_attr__( 'Section navigation', 'doubleyoupi-refined-admin-dashboard' )
		);
		echo '<div class="dy-chipbar-inner">';

		foreach ( $items as $item ) {
			$is_current = ( $item['slug'] === $current_slug ) ? ' current' : '';
			$icon_html  = self::chip_icon_html( $item['slug'] );

			echo '<a class="' . esc_attr( 'dy-chip' . $is_current ) . '" href="' . esc_url( $item['url'] ) . '">' . wp_kses( $icon_html, Kses::ui_tags() ) . esc_html( $item['label'] ) . '</a>';
		}

		// Underline track — positioned by the JS.
		echo '<span class="dy-track" aria-hidden="true"></span>';
		echo '</div></nav>';
	}

	/**
	 * Native-tree chipbar: the WordPress sub-menu of the current top-level
	 * entry (chips collected by ColumnModel::native_columns), the current one
	 * resolved the way menu-header.php does it.
	 */
	private function render_native(): void {
		// The dashboard takes the whole screen (our modular grid) and has no
		// chipbar in the reimagined tree either: WordPress only puts "Home"
		// and "Updates" under it, which the rail already shows. Native keeps
		// the same rule (Raphaël, 14/09/2026). Its sub-pages, update-core.php
		// among them, still get the bar when you are on them.
		if ( Dashboard\Dashboard::is_current_screen() ) {
			echo '<nav class="dy-chipbar" id="dy-admin-chipbar" hidden aria-hidden="true"></nav>';
			return;
		}

		$id    = $this->rail->active_column_id();
		$lines = $this->rail->lines();
		$items = ( null !== $id && isset( $lines[ $id ] ) ) ? $lines[ $id ] : [];
		if ( empty( $items ) ) {
			echo '<nav class="dy-chipbar" id="dy-admin-chipbar" hidden aria-hidden="true"></nav>';
			return;
		}
		$current = Rail::current_submenu_slug();
		printf(
			'<nav class="dy-chipbar dy-chipbar-native" id="dy-admin-chipbar" data-line="native" aria-label="%s">',
			esc_attr__( 'Section navigation', 'doubleyoupi-refined-admin-dashboard' )
		);
		echo '<div class="dy-chipbar-inner">';
		foreach ( $items as $item ) {
			$is_current = ( ( $item['slug'] ?? '' ) === $current ) ? ' current' : '';
			echo '<a class="' . esc_attr( 'dy-chip' . $is_current ) . '" href="' . esc_url( (string) $item['url'] ) . '"><span class="dot" aria-hidden="true"></span>' . esc_html( (string) $item['label'] ) . '</a>';
		}
		echo '<span class="dy-track" aria-hidden="true"></span>';
		echo '</div></nav>';
	}

	/**
	 * Infers the current top-level slug to mark the active chip.
	 * Delegated to Rail::resolve_top_level_slug() which handles sub-page cases
	 * (Double Youpi > Overview → 'doubleyoupi', etc.).
	 */
	private function current_parent_slug(): string {
		return Rail::resolve_top_level_slug() ?? '';
	}

	/**
	 * Icon of a chip: the curated sprite symbol; else the plugin's own menu
	 * icon (registered icon name via the Icon API, distinctive Dashicon, or
	 * image; ready for the 7.2 `core/xxx` menu icons); else the neutral dot.
	 * Shared by the chipbar and the rail flyout. Author-controlled markup
	 * whose only variable is escaped.
	 */
	public static function chip_icon_html( string $slug ): string {
		$custom_icon = PluginMap::chip_icon( $slug );
		if ( $custom_icon ) {
			return '<svg class="dy-chip-icon" width="14" height="14" aria-hidden="true"><use href="#' . esc_attr( $custom_icon ) . '"/></svg>';
		}
		$icon_html = PluginMap::is_native( $slug ) ? '' : CoreCapabilities::menu_icon_html( PluginMap::menu_icon( $slug ) );
		return '' === $icon_html ? '<span class="dot" aria-hidden="true"></span>' : $icon_html;
	}

	/**
	 * For a standalone builder (Bricks, Elementor…), collects its sub-pages
	 * directly from $submenu[$builder_slug]. Returns a list compatible
	 * with the main render foreach (label + url + slug).
	 *
	 * @return array<int, array{slug:string, label:string, url:string}>
	 */
	private function collect_builder_subpages( string $builder_slug ): array {
		global $submenu;
		if ( ! is_array( $submenu ) || empty( $submenu[ $builder_slug ] ) ) {
			return [];
		}
		$skip_starts = [ 'post-new.php', 'plugin-install.php', 'theme-install.php' ];
		$out = [];
		foreach ( $submenu[ $builder_slug ] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}
			$slug  = (string) $entry[2];
			$label = trim( wp_strip_all_tags( preg_replace( '/<span[^>]*>.*?<\/span>/s', '', (string) ( $entry[0] ?? '' ) ) ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$skip = false;
			foreach ( $skip_starts as $needle ) {
				if ( str_starts_with( $slug, $needle ) ) { $skip = true; break; }
			}
			if ( $skip ) continue;

			$url = str_contains( $slug, '.php' )
				? admin_url( $slug )
				: admin_url( 'admin.php?page=' . rawurlencode( $slug ) );

			$out[] = [ 'slug' => $slug, 'label' => $label, 'url' => $url ];
		}
		return $out;
	}

	/**
	 * Specific render for a standalone builder — uses the builder slug
	 * as `data-line` so it can be colorized via CSS (e.g. line-bricks).
	 *
	 * @param array<int, array{slug:string, label:string, url:string}> $items
	 */
	private function render_builder_chipbar( string $builder_slug, array $items ): void {
		$current_url = $this->current_url();
		printf(
			'<nav class="dy-chipbar dy-chipbar-builder" id="dy-admin-chipbar" data-line="builder-%s" aria-label="%s">',
			esc_attr( $builder_slug ),
			esc_attr__( 'Section navigation', 'doubleyoupi-refined-admin-dashboard' )
		);
		echo '<div class="dy-chipbar-inner">';
		foreach ( $items as $item ) {
			$is_current = $this->is_chip_current( $item, $current_url ) ? ' current' : '';
			echo '<a class="' . esc_attr( 'dy-chip' . $is_current ) . '" href="' . esc_url( $item['url'] ) . '"><span class="dot" aria-hidden="true"></span>' . esc_html( $item['label'] ) . '</a>';
		}
		echo '<span class="dy-track" aria-hidden="true"></span>';
		echo '</div></nav>';
	}

	/**
	 * Current admin URL (script + query), reconstructed from $_SERVER.
	 */
	private function current_url(): string {
		$qs   = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
		$base = admin_url( basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ) );
		return $qs ? $base . '?' . $qs : $base;
	}

	/**
	 * Determines whether a builder chip matches the current page.
	 *
	 * Why: the old comparison `$item['slug'] === $current_parent_slug`
	 * (resolved via `Rail::resolve_top_level_slug()`) always returned the
	 * builder's top-level slug ("bricks"), so the "Getting started" chip
	 * (slug=`bricks`) stayed `.current` on all sub-pages (`bricks-settings`, etc.).
	 *
	 * How to apply: compare the exact `page=` query param between the chip URL
	 * and the current URL; for standalone .php sub-pages (Templates →
	 * `edit.php?post_type=bricks_template`), compare basename + post_type.
	 */
	private function is_chip_current( array $item, string $current_url ): bool {
		$item_page    = $this->extract_page_param( $item['url'] );
		$current_page = $this->extract_page_param( $current_url );

		if ( $item_page !== null ) {
			return $item_page === $current_page;
		}
		if ( $current_page !== null ) {
			return false;
		}
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

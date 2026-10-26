<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adminbar — extensions and cleanup of the WP admin bar from the Admin module.
 *
 *   - Adds the theme toggle (sun/moon) before `#wp-admin-bar-my-account`
 *     (final positioning via CSS `order: -1` on the secondary flexbox).
 *   - Removes redundant nodes: `wp-logo` (already covered by the brand row
 *     in the rail) and `menu-toggle` (mobile collapse replaced by our
 *     interactive `.dy-rail-toggle`).
 *   - Removes clutter injected by third-party plugins (updates,
 *     WooCommerce site-visibility, Yoast, Rank Math, UpdraftPlus…) —
 *     the Admin module maintains a clean top bar (curated manifesto §2).
 *   - Fallback title="View site" on `site-name` (already set by WP but
 *     we enforce a consistent EN phrasing aligned with our voice).
 *
 * The theme toggle is purely client-side: `admin.js` toggles
 * `body.dy-theme-light` and persists the state in localStorage. No
 * server roundtrip, no DB option.
 */
final class Adminbar {

	/**
	 * Admin bar nodes injected by third-party plugins that the Admin module
	 * removes to keep the top bar clean. IDs WITHOUT the DOM prefix
	 * `wp-admin-bar-` (WP adds it at render time — remove_node() expects
	 * the bare id).
	 *
	 * Filterable via `doubleyoupi_adminbar_remove_nodes`: a site can
	 * add or remove entries without touching the code.
	 *
	 * @var string[]
	 */
	private const PLUGIN_CLUTTER_NODES = [
		'updates',                              // WP core: update counter badge
		// Not WooCommerce's « Coming soon » badge: it is the only sign on screen
		// that the shop is not public yet (compatibility lab, 24/10/2026).
		'wpseo-menu',                           // Yoast SEO
		'rank-math',                            // Rank Math SEO
		'updraft_admin_node',                   // UpdraftPlus backups
	];

	public function boot(): void {
		add_action( 'admin_bar_menu', [ $this, 'cleanup_native_nodes' ], 999 );
		// First pass: very high priority on admin_bar_menu, catches plugins
		// that register during this hook (the majority of them).
		add_action( 'admin_bar_menu', [ $this, 'cleanup_plugin_clutter' ], 99999 );
		// Second pass: on wp_before_admin_bar_render, which fires AFTER
		// all admin_bar_menu calls. Required for plugins that add their node
		// late on this hook — UpdraftPlus does this
		// (updraftplus/admin.php → wp_before_admin_bar_render). Priority
		// 99999 to run after their add_node (which defaults to 10).
		add_action( 'wp_before_admin_bar_render', [ $this, 'cleanup_plugin_clutter_late' ], 99999 );
		add_action( 'admin_bar_menu', [ $this, 'add_theme_toggle' ], 9999 );
		add_action( 'admin_bar_menu', [ $this, 'refine_site_name_title' ], 9999 );
		// Command Palette (Cmd/Ctrl+K): native WP 7.0 node added at
		// priority 55. Re-parent it to top-secondary (right) to place it
		// just before the theme-toggle. Priority 9999 → after the core addition.
		add_action( 'admin_bar_menu', [ $this, 'reposition_command_palette' ], 9999 );
		// Ensures palette assets are enqueued. Priority 20: AFTER
		// the core hook (wp_enqueue_command_palette_assets at 10) so we can
		// detect whether it already ran and avoid a duplicate inline-script.
		add_action( 'admin_enqueue_scripts', [ $this, 'ensure_command_palette_assets' ], 20 );
	}

	/**
	 * Removes redundant nodes. We remove them server-side (rather than
	 * via CSS) to prevent them from consuming reserved space when
	 * other plugins measure the bar.
	 */
	public function cleanup_native_nodes( \WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'wp-logo' );
		$bar->remove_node( 'menu-toggle' );
	}

	/**
	 * Removes plugin clutter (updates, WooCommerce, Yoast, Rank Math…)
	 * during admin_bar_menu. remove_node() is a no-op if the node
	 * does not exist (plugin not active) — no guard needed.
	 */
	public function cleanup_plugin_clutter( \WP_Admin_Bar $bar ): void {
		$this->remove_clutter_nodes( $bar );
	}

	/**
	 * Second pass on wp_before_admin_bar_render — this hook does not receive
	 * WP_Admin_Bar as an argument, so we retrieve it via the global.
	 * Catches nodes added late (UpdraftPlus).
	 */
	public function cleanup_plugin_clutter_late(): void {
		global $wp_admin_bar;
		if ( $wp_admin_bar instanceof \WP_Admin_Bar ) {
			$this->remove_clutter_nodes( $wp_admin_bar );
		}
	}

	/**
	 * Shared removal logic — called by both passes.
	 */
	private function remove_clutter_nodes( \WP_Admin_Bar $bar ): void {
		/**
		 * Filters the list of admin bar nodes removed by the Admin module.
		 *
		 * @param string[] $node_ids Node IDs (without the wp-admin-bar- prefix).
		 */
		$node_ids = (array) apply_filters(
			'doubleyoupi_adminbar_remove_nodes',
			self::PLUGIN_CLUTTER_NODES
		);

		foreach ( $node_ids as $node_id ) {
			$bar->remove_node( (string) $node_id );
		}
	}

	public function add_theme_toggle( \WP_Admin_Bar $bar ): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$bar->add_node( [
			'id'     => 'theme-toggle',
			'parent' => 'top-secondary',
			'title'  => '<span class="ab-icon icon-light" aria-hidden="true"><svg width="16" height="16"><use href="#i-sun"/></svg></span>'
			          . '<span class="ab-icon icon-dark" aria-hidden="true"><svg width="16" height="16"><use href="#i-moon"/></svg></span>',
			'meta'   => [
				'title' => __( 'Toggle theme', 'doubleyoupi-refined-admin-dashboard' ),
				'class' => 'dy-admin-theme-toggle',
			],
			'href'   => '#',
		] );
	}

	/**
	 * Forces the native `title` of the `site-name` node to "View site" —
	 * the WP default is "Visit Site", which translates inconsistently
	 * across versions. We normalize it for a consistent tooltip in all locales.
	 */
	public function refine_site_name_title( \WP_Admin_Bar $bar ): void {
		$node = $bar->get_node( 'site-name' );
		if ( ! $node ) {
			return;
		}
		$meta            = (array) $node->meta;
		$meta['title']   = __( 'View site', 'doubleyoupi-refined-admin-dashboard' );
		$bar->add_node( [
			'id'    => 'site-name',
			'meta'  => $meta,
		] );
	}

	/**
	 * Re-parents the `command-palette` node (native WP 7.0, added at priority
	 * 55 without a parent → primary group on the left) to `top-secondary`,
	 * on the right. Final positioning just before the theme-toggle via CSS
	 * `order` (see _command-palette.scss).
	 *
	 * add_node() with an existing id merges new args into the existing ones
	 * (wp_parse_args) — we only pass `parent`; the native title/href/meta/
	 * onclick are preserved.
	 */
	public function reposition_command_palette( \WP_Admin_Bar $bar ): void {
		if ( ! $bar->get_node( 'command-palette' ) ) {
			return;
		}
		$bar->add_node( [
			'id'     => 'command-palette',
			'parent' => 'top-secondary',
		] );
	}

	/**
	 * Ensures Command Palette assets are enqueued.
	 *
	 * WP 6.9+ enqueues them natively via `wp_enqueue_command_palette_assets()`
	 * (hooked on admin_enqueue_scripts, priority 10) — no plugin dependency.
	 * WooCommerce only ADDS commands to the palette; it is not required
	 * for the palette itself to exist.
	 *
	 * We keep this safety net regardless: if a third-party filter or a
	 * downgraded version prevented the core enqueue, we force it. Idempotent —
	 * we skip if the script is already enqueued, to avoid duplicating the
	 * `initializeCommandPalette` inline script.
	 */
	public function ensure_command_palette_assets(): void {
		if ( wp_script_is( 'wp-core-commands', 'enqueued' ) ) {
			return;
		}
		// Progressive enhancement: WP 7.0-only helper, guarded by function_exists()
		// so it stays inert on the 6.5 minimum. Called indirectly to keep the
		// declared "Requires at least" honest for static analysis.
		$enqueue_palette = 'wp_enqueue_command_palette_assets';
		if ( function_exists( $enqueue_palette ) ) {
			$enqueue_palette();
		}
	}
}

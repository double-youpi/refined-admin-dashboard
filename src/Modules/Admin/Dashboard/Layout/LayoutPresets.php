<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Layout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hard-coded v4 dispositions.
 *
 * Each preset is a complete v4 layout ready to pass through LayoutValidator.
 * Four general-purpose presets (Standard, Editor, Administrator, Minimal) plus
 * two WooCommerce dispositions (Shop, Shop Pro) that only appear when
 * WooCommerce is active (see self::list()).
 *
 * Design priority: the opening screen serves the #1 action of the profile.
 * For a content editor / general admin → create content and reach actions fast.
 * The "Create" and "What's next?" screens from the Editor preset are factored
 * out and reused by Standard / Minimal / Shop for consistency.
 *
 * Slot constraint: 6 per screen (data widget = 2 slots, create_button = 1 slot,
 * create_hub = 2 slots).
 */
final class LayoutPresets {

	/** Common layout wrapper (avoids repeating the root fields). */
	private static function wrap( string $preset, array $screens ): array {
		return [
			'version'          => 4,
			'schema'           => 'doubleyoupi.dashboard.v4',
			'preset'           => $preset,
			'active_screen'    => 0,
			'lock'             => false,
			'role_target'      => null,
			'wp_native_screen' => [ 'enabled' => true, 'hidden_widgets' => [] ],
			'screens'          => $screens,
		];
	}

	// ── Reusable screens ─────────────────────────────────────────────────

	/**
	 * "Create" screen: wide creation hub (one large button per content type,
	 * including custom post types) + a free-form note. The editor's first reflex.
	 *
	 * @return array<string, mixed>
	 */
	private static function screen_create( string $id, string $title, string $title_em, string $paragraph = '' ): array {
		return [
			'id'                => $id,
			'title'             => $title,
			'title_em'          => $title_em,
			'paragraph'         => $paragraph,
			'paragraph_enabled' => $paragraph !== '',
			'accent_intensity'  => 'auto',
			'widgets'           => [
				[ 'id' => 'create_hub', 'slots' => 4, 'config' => [] ],
				[ 'id' => 'note',       'slots' => 2, 'instance' => 'note-create', 'config' => [] ],
			],
		];
	}

	/**
	 * "What's next?" screen: to-do + recent activity. Tracking work in progress.
	 *
	 * @return array<string, mixed>
	 */
	private static function screen_flow( string $id, string $title, string $title_em, string $paragraph = '' ): array {
		return [
			'id'                => $id,
			'title'             => $title,
			'title_em'          => $title_em,
			'paragraph'         => $paragraph,
			'paragraph_enabled' => $paragraph !== '',
			'accent_intensity'  => 'auto',
			'widgets'           => [
				[ 'id' => 'todo',     'slots' => 2, 'config' => [] ],
				[ 'id' => 'activity', 'slots' => 2, 'config' => [] ],
			],
		];
	}

	/**
	 * WooCommerce "Shop" screen: create a product + 7-day sales + pending orders.
	 * Shared by the Shop and Shop Pro presets.
	 *
	 * @return array<string, mixed>
	 */
	private static function screen_shop( string $paragraph = '' ): array {
		return [
			'id'                => 'scr-boutique',
			'title'             => __( 'Today, we\'re on a roll', 'doubleyoupi-refined-admin-dashboard' ),
			'title_em'          => '',
			'paragraph'         => $paragraph,
			'paragraph_enabled' => $paragraph !== '',
			'accent_intensity'  => 'auto',
			'widgets'           => [
				[ 'id' => 'wc_orders',      'slots' => 2, 'config' => [] ],
				[ 'id' => 'create_button', 'slots' => 1, 'instance' => 'cb-product', 'config' => [ 'post_type' => 'product', 'label' => '' ] ],
				[ 'id' => 'wc_sales_trend', 'slots' => 2, 'config' => [] ],
			],
		];
	}

	// ── Dispositions ─────────────────────────────────────────────────────

	/**
	 * "Standard" — the Double Youpi default. Three screens: start the day
	 * (create + actions), follow the thread (to-do / activity / note), then
	 * glance under the hood (modules + DY tools + backup).
	 *
	 * @return array<string, mixed>
	 */
	public static function standard(): array {
		return self::wrap( 'standard', [
			self::screen_create(
				'scr-jour',
				'{{date.greeting}} {{user.firstname}}',
				'{{user.firstname}}',
				/* translators: **{{site.name}}** is replaced by the site name (bold). */
				__( 'What are we making on **{{site.name}}** today?', 'doubleyoupi-refined-admin-dashboard' )
			),
			self::screen_flow( 'scr-fil', __( 'Picking up the thread', 'doubleyoupi-refined-admin-dashboard' ), '' ),
			[
				'id'                => 'scr-machines',
				'title'             => __( 'The engine room', 'doubleyoupi-refined-admin-dashboard' ),
				'title_em'          => '',
				'paragraph'         => '',
				'paragraph_enabled' => false,
				'accent_intensity'  => 'auto',
				'widgets'           => [
					[ 'id' => 'modules', 'slots' => 2, 'config' => [] ],
					[ 'id' => 'toolkit', 'slots' => 2, 'config' => [] ],
					[ 'id' => 'backup',   'slots' => 2, 'config' => [] ],
				],
			],
		] );
	}

	/**
	 * "Editor" — focus on content creation. Two screens: create + quick access,
	 * then follow-up (to-do / activity / note).
	 *
	 * @return array<string, mixed>
	 */
	public static function editor(): array {
		return self::wrap( 'editor', [
			self::screen_create(
				'scr-claviers',
				__( 'Keyboards at the ready', 'doubleyoupi-refined-admin-dashboard' ),
				'',
				__( 'Blank page? Never heard of it.', 'doubleyoupi-refined-admin-dashboard' )
			),
			self::screen_flow( 'scr-ensuite', __( 'What\'s next?', 'doubleyoupi-refined-admin-dashboard' ), '' ),
		] );
	}

	/**
	 * "Administrator" — technical health and maintenance. A "health" screen
	 * (state + WP health + disk) then a "maintenance" screen (modules + backup +
	 * static site).
	 *
	 * @return array<string, mixed>
	 */
	public static function admin_site(): array {
		return self::wrap( 'admin-site', [
			[
				'id'                => 'scr-sante',
				'title'             => __( 'All in order?', 'doubleyoupi-refined-admin-dashboard' ),
				'title_em'          => '',
				'paragraph'         => '',
				'paragraph_enabled' => false,
				'accent_intensity'  => 'auto',
				'widgets'           => [
					[ 'id' => 'site_state',  'slots' => 2, 'config' => [] ],
					[ 'id' => 'site_health', 'slots' => 2, 'config' => [] ],
					[ 'id' => 'disk',        'slots' => 2, 'config' => [] ],
				],
			],
			[
				'id'                => 'scr-maintenance',
				'title'             => __( 'The engine room', 'doubleyoupi-refined-admin-dashboard' ),
				'title_em'          => '',
				'paragraph'         => '',
				'paragraph_enabled' => false,
				'accent_intensity'  => 'auto',
				'widgets'           => [
					[ 'id' => 'modules',     'slots' => 2, 'config' => [] ],
					[ 'id' => 'backup',      'slots' => 2, 'config' => [] ],
					[ 'id' => 'static_site', 'slots' => 2, 'config' => [] ],
				],
			],
		] );
	}

	/**
	 * "Minimal" — just the Editor's "Create" screen and nothing else.
	 * For those who want to create and leave.
	 *
	 * @return array<string, mixed>
	 */
	public static function minimal(): array {
		return self::wrap( 'minimal', [
			self::screen_create(
				'scr-essentiel',
				__( 'Shall we?', 'doubleyoupi-refined-admin-dashboard' ),
				'',
				''
			),
		] );
	}

	/**
	 * WooCommerce "Shop" (simple) — the shop screen, then the Editor flow
	 * (create + follow-up). Only appears when WC is active.
	 *
	 * @return array<string, mixed>
	 */
	public static function shop(): array {
		return self::wrap( 'boutique', [
			self::screen_shop( __( 'Sales and orders, without leaving the dashboard.', 'doubleyoupi-refined-admin-dashboard' ) ),
			self::screen_create( 'scr-claviers', __( 'Keyboards at the ready', 'doubleyoupi-refined-admin-dashboard' ), '', __( 'Blank page? Never heard of it.', 'doubleyoupi-refined-admin-dashboard' ) ),
			self::screen_flow( 'scr-ensuite', __( 'What\'s next?', 'doubleyoupi-refined-admin-dashboard' ), '' ),
		] );
	}

	/**
	 * WooCommerce "Shop Pro" (advanced) — shop screen + a "cash drawer" screen
	 * (monthly revenue + activity + stock), then the Editor flow. WC required.
	 *
	 * @return array<string, mixed>
	 */
	public static function shop_pro(): array {
		return self::wrap( 'boutique-pro', [
			self::screen_shop( __( 'Sales and orders, without leaving the dashboard.', 'doubleyoupi-refined-admin-dashboard' ) ),
			[
				'id'                => 'scr-caisse',
				'title'             => __( 'The cash drawer', 'doubleyoupi-refined-admin-dashboard' ),
				'title_em'          => '',
				'paragraph'         => __( 'What comes in, what moves, what\'s running low.', 'doubleyoupi-refined-admin-dashboard' ),
				'paragraph_enabled' => true,
				'accent_intensity'  => 'auto',
				'widgets'           => [
					[ 'id' => 'wc_revenue',   'slots' => 2, 'config' => [] ],
					[ 'id' => 'wc_activity',  'slots' => 2, 'config' => [] ],
					[ 'id' => 'wc_low_stock', 'slots' => 2, 'config' => [] ],
				],
			],
			self::screen_create( 'scr-claviers', __( 'Keyboards at the ready', 'doubleyoupi-refined-admin-dashboard' ), '', __( 'Blank page? Never heard of it.', 'doubleyoupi-refined-admin-dashboard' ) ),
			self::screen_flow( 'scr-ensuite', __( 'What\'s next?', 'doubleyoupi-refined-admin-dashboard' ), '' ),
		] );
	}

	/** Is WooCommerce active? (The Shop dispositions depend on it.) */
	private static function wc_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' );
	}

	/**
	 * Returns a preset by its slug, or Standard if unknown.
	 *
	 * @return array<string, mixed>
	 */
	public static function get( string $slug ): array {
		return match ( $slug ) {
			'editor'       => self::editor(),
			'admin-site'   => self::admin_site(),
			'minimal'      => self::minimal(),
			'boutique'     => self::shop(),
			'boutique-pro' => self::shop_pro(),
			default        => self::standard(),
		};
	}

	/**
	 * List of available presets for the panel UI. WooCommerce dispositions are
	 * only listed when WooCommerce is active.
	 *
	 * @return array<int, array{slug:string, name:string, description:string}>
	 */
	public static function list(): array {
		$presets = [
			[ 'slug' => 'standard',   'name' => __( 'Standard',      'doubleyoupi-refined-admin-dashboard' ), 'description' => __( 'Recommended by Double Youpï',      'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'slug' => 'editor',     'name' => __( 'Editor',        'doubleyoupi-refined-admin-dashboard' ), 'description' => __( 'Focus on content creation',        'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'slug' => 'admin-site', 'name' => __( 'Administrator', 'doubleyoupi-refined-admin-dashboard' ), 'description' => __( 'Technical health and maintenance', 'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'slug' => 'minimal',    'name' => __( 'Minimal',       'doubleyoupi-refined-admin-dashboard' ), 'description' => __( 'Just create and go',               'doubleyoupi-refined-admin-dashboard' ) ],
		];

		if ( self::wc_active() ) {
			$presets[] = [ 'slug' => 'boutique',     'name' => __( 'Shop',     'doubleyoupi-refined-admin-dashboard' ), 'description' => __( 'Your shop, then the editor flow', 'doubleyoupi-refined-admin-dashboard' ) ];
			$presets[] = [ 'slug' => 'boutique-pro', 'name' => __( 'Shop Pro', 'doubleyoupi-refined-admin-dashboard' ), 'description' => __( 'Sales in depth, plus the editor flow', 'doubleyoupi-refined-admin-dashboard' ) ];
		}

		return $presets;
	}
}

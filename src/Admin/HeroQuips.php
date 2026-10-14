<?php declare(strict_types=1);
namespace DoubleYoupi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hero quips for the Overview page (solo mode).
 *
 * In place of the "X / Y active modules" counter — which is meaningless when
 * only one module is bundled — a random "mock boast" quip is shown in the accent color.
 *
 * Two pools:
 *   - generic()  : work for ANY standalone plugin (or the suite).
 *   - specific() : specific to a given module (reference its function).
 * Each future standalone plugin adds its quips to the specific() map.
 *
 * Translatable strings; French translations are provided via build/i18n/extras
 * (other locales fall back to English).
 */
final class HeroQuips {

	/**
	 * Full pool for a given module (generic + specific quips).
	 *
	 * @return string[]
	 */
	public static function for_module( string $module_id ): array {
		return array_merge( self::generic(), self::specific( $module_id ) );
	}

	/**
	 * A random quip for the given module, or '' if the pool is empty.
	 */
	public static function random( string $module_id ): string {
		$pool = self::for_module( $module_id );
		if ( empty( $pool ) ) {
			return '';
		}
		return (string) $pool[ array_rand( $pool ) ];
	}

	/**
	 * Generic quips (reusable by any standalone plugin).
	 *
	 * @return string[]
	 */
	public static function generic(): array {
		return [
			__( "Objectively the best plugin you've installed in the last five minutes.", 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Built different. We have a certificate and everything.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Zero shortcodes were harmed in the making of this plugin.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Awarded Plugin of the Year by a panel of one.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Refined enough to make your theme nervous.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'We benchmarked it against perfection. Perfection lost.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Your other plugins are taking this personally.', 'doubleyoupi-refined-admin-dashboard' ),
			__( "Currently winning an argument it wasn't invited to.", 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Suspiciously good for something this free.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'The bar was high. We brought a ladder.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Compared to nothing, it is literally the best.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Powered by talent and a concerning amount of confidence.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Reviews are in: we wrote them ourselves.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Finally, a plugin that matches your impeccable taste.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Great taste. You installed this, after all.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Two icons in one admin: you and this plugin.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'People with taste end up here. Welcome.', 'doubleyoupi-refined-admin-dashboard' ),
			__( 'Smart move installing this. We expected nothing less from you.', 'doubleyoupi-refined-admin-dashboard' ),
		];
	}

	/**
	 * Module-specific quips (reference its function).
	 *
	 * @return string[]
	 */
	private static function specific( string $module_id ): array {
		$map = [
			'media-library' => [
				__( "Probably the finest media library you'll configure all day.", 'doubleyoupi-refined-admin-dashboard' ),
				__( 'The plugin your media library brags about.', 'doubleyoupi-refined-admin-dashboard' ),
				__( "Your media library finally has a personality. It's smug.", 'doubleyoupi-refined-admin-dashboard' ),
			],
		];
		return $map[ $module_id ] ?? [];
	}
}

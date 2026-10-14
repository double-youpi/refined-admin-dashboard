<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wrapper for conflicting-plugin detection.
 *
 * Encapsulates is_plugin_active() for two reasons:
 *   1. Lazy require of wp-admin/includes/plugin.php (the function is not
 *      loaded by default on the frontend, but the overview is admin-only so OK).
 *   2. Mockability in tests via the doubleyoupi_toolkit_plugin_active filter.
 *      The filter is transparent by default, but lets tests simulate a
 *      conflict without having to activate/deactivate real plugins.
 */
final class Detection {

	/**
	 * Is the plugin active on the site?
	 *
	 * @param string $plugin_file Basename relative to the plugins directory,
	 *                            e.g. 'classic-editor/classic-editor.php'.
	 */
	public static function is_plugin_active( string $plugin_file ): bool {
		// Load the WP function if not already available (auto-loaded in admin, but not on the frontend).
		if ( ! function_exists( 'is_plugin_active' ) ) {
			$plugin_php = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( ! is_readable( $plugin_php ) ) {
				return false;
			}
			require_once $plugin_php;
		}

		$active = is_plugin_active( $plugin_file );

		/**
		 * Allows forcing the active/inactive state of a plugin for tests
		 * or custom overrides.
		 *
		 * @param bool   $active      State detected by WordPress.
		 * @param string $plugin_file Basename of the plugin being tested.
		 */
		return (bool) apply_filters( 'doubleyoupi_toolkit_plugin_active', $active, $plugin_file );
	}
}

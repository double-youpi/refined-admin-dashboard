<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit;

use DoubleYoupi\Modules\Toolkit\Features\AbstractFeature;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of Toolkit features + WordPress options.
 *
 * Serves two roles:
 *   1. Single source of truth for the feature list (factory). The overview and
 *      the form handler iterate over it.
 *   2. Registering options in WP via register_setting() to benefit from
 *      auto-sanitization on write. Each feature persists its value in a
 *      dedicated option `doubleyoupi_toolkit_{id}` (consistent with the
 *      Admin module convention).
 *
 * Not an AbstractModule itself: it is an internal service of the Toolkit module.
 */
final class Settings {

	/** @var AbstractFeature[]|null */
	private static ?array $features = null;

	/**
	 * Ordered list of features. The order = display order in the Overview section.
	 * Arranged from most "editor/content"-oriented to most "infrastructure"-oriented
	 * (decreasing user familiarity).
	 *
	 * @return AbstractFeature[]
	 */
	public static function features(): array {
		if ( self::$features === null ) {
			self::$features = [
				new Features\ClassicEditor(),
				new Features\DisableComments(),
				new Features\DuplicatePosts(),
				new Features\IntuitiveSave(),
				new Features\ForceHttps(),
				new Features\SafeSvg(),
				new Features\DisableEmailVerification(),
				new Features\DisableUpdateEmails(),
				new Features\LocalAvatars(),
				new Features\HideLogin(),
			];
		}
		return self::$features;
	}

	/**
	 * Returns a feature by its id, or null.
	 */
	public static function find( string $id ): ?AbstractFeature {
		foreach ( self::features() as $feature ) {
			if ( $feature->id() === $id ) {
				return $feature;
			}
		}
		return null;
	}

	/**
	 * Registers register_setting() calls for all features.
	 * Must be called on the admin_init hook.
	 *
	 * The group name "doubleyoupi_toolkit" is not actually used for rendering
	 * (we do not use options.php — we have our own handler in OverviewSection),
	 * but grouping helps readability on the WP options side.
	 */
	public static function register(): void {
		foreach ( self::features() as $feature ) {
			register_setting(
				'doubleyoupi_toolkit',
				$feature->option_key(),
				[
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => false,
				]
			);
		}
	}

	/**
	 * Resets the feature cache (useful in tests).
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$features = null;
	}
}

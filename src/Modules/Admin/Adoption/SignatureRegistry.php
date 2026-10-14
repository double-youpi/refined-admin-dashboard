<?php
declare(strict_types=1);

namespace DoubleYoupi\Modules\Admin\Adoption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHP catalogue of CSS selectors → DS roles for the adopter JS.
 *
 * Keys may contain multiple selectors shared by different plugins.
 * Filterable: `doubleyoupi_admin_adopt_signatures`.
 */
final class SignatureRegistry {

	private const FILTER = 'doubleyoupi_admin_adopt_signatures';

	/**
	 * @return array<string, list<string>> role => list of CSS selectors
	 */
	public static function all(): array {
		$signatures = self::merge_maps(
			self::elementor_signatures_if_active(),
			self::rank_math_signatures_if_active(),
			self::acf_signatures_if_active(),
			self::yoast_signatures_if_active(),
			self::bricks_signatures_if_active()
		);

		foreach ( array_keys( $signatures ) as $role ) {
			/** @var list<string>|mixed $vals */
			$vals                  = $signatures[ $role ];
			$vals                  = is_array( $vals ) ? $vals : array();
			$signatures[ $role ] = array_values(
				array_unique(
					array_filter(
						array_map( 'strval', $vals )
					)
				)
			);
		}

		/** @var array<string, list<string>> $filtered */
		$filtered = apply_filters( self::FILTER, $signatures ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- hook name is a doubleyoupi_-prefixed class constant.

		return array_filter(
			$filtered,
			static function ( $selectors ): bool {
				return is_array( $selectors ) && $selectors !== array();
			}
		);
	}

	/**
	 * @param array<string, list<string>> ...$maps Maps to merge (concatenated per role).
	 *
	 * @return array<string, list<string>>
	 */
	private static function merge_maps( array ...$maps ): array {
		$out = array();
		foreach ( $maps as $map ) {
			foreach ( $map as $role => $selectors ) {
				if ( ! isset( $out[ $role ] ) ) {
					$out[ $role ] = array();
				}
				foreach ( $selectors as $selector ) {
					$out[ $role ][] = $selector;
				}
			}
		}

		return $out;
	}

	/**
	 * Elementor — admin notices, media library CTA, buttons.
	 *
	 * @return array<string, list<string>>
	 */
	private static function elementor_signatures_if_active(): array {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return array();
		}

		return array(
			'toolbar-widget' => array( '#e-image-ai-media-library' ),
			'notice-dismiss' => array( '.e-notice__dismiss' ),
			'notice-title'   => array( '.e-notice__content > h3' ),
			'notice-cta'     => array( '.e-notice--cta.e-notice' ),
			'notice'         => array( '.e-notice:not(.e-notice--cta)' ),
			'button-primary' => array(
				'.e-notice .e-button--cta',
				'#e-image-ai-media-library a',
				'#e-image-ai-media-library button',
			),
			'button'         => array(
				'.e-button:not(.e-button--cta)',
				'.elementor-plugins-gopro',
			),
			'card'           => array( '.e-card' ),
			'badge'          => array( '.e-status-tag' ),
			// "Deactivate Elementor" modal (plugins.php).
			'field'          => array(
				'.elementor-deactivate-feedback-dialog-input',
				'.elementor-feedback-text',
			),
		);
	}

	/**
	 * Rank Math — buttons and common surfaces.
	 *
	 * @return array<string, list<string>>
	 */
	private static function rank_math_signatures_if_active(): array {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return array();
		}

		return array(
			'notice'         => array( '.rank-math-notice' ),
			'button-primary' => array(
				'.rank-math-btn-primary',
				'.rank-math-button.button-primary',
				'.rank-math-bulk-generate-button',
			),
			'button'         => array(
				'.rank-math-btn:not(.rank-math-btn-primary)',
				'.rank-math-button:not(.button-primary)',
				// Cell action links in edit.php.
				'.rank-math-column-save-all',
				'.rank-math-column-cancel-all',
				'.rank-math-column-save',
				'.rank-math-column-cancel',
			),
			'card'           => array( '.rank-math-box' ),
			'badge'          => array( '.rank-math-new-badge' ),
			'field'          => array( '.rank-math-column-value' ),
		);
	}

	/**
	 * ACF — admin notices.
	 *
	 * @return array<string, list<string>>
	 */
	private static function acf_signatures_if_active(): array {
		if ( ! function_exists( 'acf_get_setting' ) ) {
			return array();
		}

		return array(
			'notice' => array( '.acf-admin-notice' ),
		);
	}

	/**
	 * Yoast SEO — score pills in post lists + tooltip.
	 *
	 * @return array<string, list<string>>
	 */
	private static function yoast_signatures_if_active(): array {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return array();
		}

		return array(
			'pill' => array(
				'.yoast-column-seo-score',
				'.yoast-column-readability',
				'.yoast-linked-to',
			),
		);
	}

	/**
	 * Bricks — inline actions in edit.php / users.php.
	 *
	 * @return array<string, list<string>>
	 */
	private static function bricks_signatures_if_active(): array {
		if ( ! defined( 'BRICKS_VERSION' ) && ! defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ) {
			return array();
		}

		return array(
			'button' => array( '.bricks-duplicate' ),
		);
	}
}

<?php
declare(strict_types=1);

namespace DoubleYoupi\Modules\Admin\Adoption;

use DoubleYoupi\Core\Build;

use DoubleYoupi\Modules\Admin\BuilderScreen;
use DoubleYoupi\Modules\Admin\NativeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the adopter JS and injects the filtered registry (window.doubleyoupiAdoptRegistry).
 */
final class Adopter {

	public function boot(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	public function enqueue( string $hook_suffix ): void {
		unset( $hook_suffix );

		// Builders with externalized UI (Elementor…): we do NOT adopt their elements
		// (notices, buttons…) into our design system — their interface stays
		// native. Without this guard, `[data-dy-adopt]` would restyle their
		// `.e-notice` with our tokens (tinted background, rounded corners).
		if ( BuilderScreen::is_managed() ) {
			return;
		}

		// "Native content" screens (Rank Math, Yoast): we step back here too —
		// no adoption of their cards/buttons/notices into our DS.
		if ( NativeContent::is_current() ) {
			return;
		}

		$registry = SignatureRegistry::all();
		if ( $registry === array() ) {
			return;
		}

		$path_abs = Build::dir( __FILE__, 'assets/js/modules/admin/adoption/adopter.js' );
		if ( ! is_readable( $path_abs ) ) {
			return;
		}

		$handle = 'doubleyoupi-admin-adoption';
		$ver    = self::asset_version( $path_abs );

		$deps = wp_script_is( 'doubleyoupi-admin', 'registered' ) ? array( 'doubleyoupi-admin' ) : array();

		wp_register_script(
			$handle,
			Build::url( __FILE__, 'assets/js/modules/admin/adoption/adopter.js' ),
			$deps,
			$ver,
			true
		);

		wp_add_inline_script(
			$handle,
			sprintf(
				'window.doubleyoupiAdoptRegistry = %s;',
				wp_json_encode( $registry, JSON_HEX_TAG | JSON_HEX_AMP )
			),
			'before'
		);

		wp_enqueue_script( $handle );
	}

	/**
	 * Cache-buster aligned with {@see Admin::asset_version} (reflection-free duplicate).
	 */
	private static function asset_version( string $abs_path ): string {
		$is_dev = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			|| str_ends_with( (string) DOUBLEYOUPI_VERSION, '-dev' );
		if ( $is_dev ) {
			$mtime = @filemtime( $abs_path );
			if ( $mtime ) {
				return (string) $mtime;
			}
		}

		return (string) DOUBLEYOUPI_VERSION;
	}
}

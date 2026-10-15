<?php
/**
 * BlockEditorLoading — a controlled load for the block editor screen.
 *
 * RAD only dresses the frame of the block editor (_gutenberg.scss). Between
 * the first paint and the moment React has mounted the editor, loaded the
 * canvas iframe and rendered the blocks, the screen went through four
 * states (empty dark page, header + empty canvas, white canvas, content).
 * We set a body class SERVER-SIDE so CSS can draw the editor frame from the
 * first paint and keep React's skeleton transparent; a small script removes
 * it when the editor store is ready and the canvas holds its blocks.
 *
 * @package DoubleYoupi\Modules\Admin\Editor
 */

namespace DoubleYoupi\Modules\Admin\Editor;

use DoubleYoupi\Core\Build;

defined( 'ABSPATH' ) || exit;

final class BlockEditorLoading {

	public function boot(): void {
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/** True on post.php / post-new.php when the block editor is used. */
	private function is_block_editor_screen(): bool {
		global $pagenow;
		if ( 'post.php' !== $pagenow && 'post-new.php' !== $pagenow ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen instanceof \WP_Screen && $screen->is_block_editor();
	}

	/**
	 * `dy-be-loading` (+ `dy-be-no-sidebar` when the user closed the settings
	 * sidebar, read from the persisted editor preferences, so the frame drawn
	 * while loading matches the one React will mount).
	 */
	public function body_class( string $classes ): string {
		if ( ! $this->is_block_editor_screen() ) {
			return $classes;
		}
		$classes .= ' dy-be-loading';

		// Stored per site (blog prefix): get_user_option() reads the right key.
		$prefs = get_user_option( 'persisted_preferences' );
		if ( is_array( $prefs ) && isset( $prefs['core']['isComplementaryAreaVisible'] ) && ! $prefs['core']['isComplementaryAreaVisible'] ) {
			$classes .= ' dy-be-no-sidebar';
		}
		return $classes;
	}

	public function enqueue(): void {
		if ( ! $this->is_block_editor_screen() ) {
			return;
		}
		$js = Build::dir( __FILE__, 'assets/js/modules/admin/block-editor-loading.js' );
		if ( ! file_exists( $js ) ) {
			return;
		}
		wp_enqueue_script(
			'doubleyoupi-block-editor-loading',
			Build::url( __FILE__, 'assets/js/modules/admin/block-editor-loading.js' ),
			[ 'wp-data', 'wp-dom-ready' ],
			(string) DOUBLEYOUPI_VERSION,
			true
		);
	}
}

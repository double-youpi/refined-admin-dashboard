<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

use DoubleYoupi\Core\Build;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cmd+S (Mac) / Ctrl+S (PC) triggers the primary save button on WordPress
 * admin screens.
 *
 * Target: anything with a `submit_button()` or a primary button inside a
 * `<form>` — Settings, term editing, user profile, custom options page,
 * and Classic Editor (TinyMCE) via the native widget API.
 *
 * Skip: block editor (Gutenberg) — WP 5.0+ already has the native `editor/save`
 * shortcut; we avoid double-saving and conflict with the keyboard-shortcuts
 * registry from the wp-keyboard-shortcuts package.
 *
 * No conflicting plugin to detect: no widely installed micro-plugin occupies
 * this niche (existing admin shortcut plugins target different actions:
 * navigation, bulk actions).
 */
final class IntuitiveSave extends AbstractFeature {

	public const SCRIPT_HANDLE = 'doubleyoupi-toolkit-intuitive-save';

	public function id(): string {
		return 'intuitive_save';
	}

	public function label(): string {
		return __( 'Intuitive Save/Publish', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function checkbox_label(): string {
		return __( 'Cmd+S (Mac) / Ctrl+S (PC) triggers the page save button', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'On admin pages with a form to save (Settings, profile, term edit, Classic Editor), Cmd+S triggers the primary button. Gutenberg is untouched (it already has its native shortcut). Behavior similar to Bricks.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Stylized floppy disk — universal "save" affordance.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>'
			. '<polyline points="17 21 17 13 7 13 7 21"/>'
			. '<polyline points="7 3 7 8 15 8"/>'
			. '</svg>';
	}

	public function conflict_plugin_candidates(): array {
		// No widely installed equivalent plugin to intercept.
		return [];
	}

	protected function apply(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_script' ] );
	}

	/**
	 * Enqueues the script on all admin pages EXCEPT the block editor
	 * (which has its own native Cmd+S). No point enqueuing what will be
	 * skipped on the JS side — saves a network round-trip.
	 */
	public static function enqueue_script(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			Build::url( __FILE__, 'assets/js/modules/toolkit/intuitive-save.js' ),
			[],
			DOUBLEYOUPI_VERSION,
			true
		);
	}
}

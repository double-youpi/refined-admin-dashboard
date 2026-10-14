<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disables Gutenberg on all post types and on the widget editor.
 *
 * Replaces the Classic Editor (official) and Disable Gutenberg plugins. We
 * simply filter the two WP gates: `use_block_editor_for_post` and
 * `use_block_editor_for_post_type`. The fallback to TinyMCE is automatic
 * (WordPress provides the native fallback).
 *
 * The frontend CSS `wp-block-library` is NOT touched here: that is a separate
 * concern (performance), not the role of this toggle which only affects the editor.
 */
final class ClassicEditor extends AbstractFeature {

	public function id(): string {
		return 'classic_editor';
	}

	public function label(): string {
		return __( 'Classic Editor', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function checkbox_label(): string {
		return __( 'Disable the block editor (Gutenberg)', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Forces TinyMCE on all posts/pages/CPTs and disables the block widget editor. Replaces Classic Editor / Disable Gutenberg.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Pen + text line — classic editor metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M12 20h9"/>'
			. '<path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>'
			. '</svg>';
	}

	public function conflict_plugin_candidates(): array {
		return [
			[ 'file' => 'classic-editor/classic-editor.php',     'name' => 'Classic Editor' ],
			[ 'file' => 'disable-gutenberg/disable-gutenberg.php', 'name' => 'Disable Gutenberg' ],
		];
	}

	protected function apply(): void {
		add_filter( 'use_block_editor_for_post',       '__return_false', 100 );
		add_filter( 'use_block_editor_for_post_type',  '__return_false', 100 );
		add_filter( 'use_widgets_block_editor',        '__return_false', 100 );
		add_filter( 'gutenberg_use_widgets_block_editor', '__return_false', 100 );
	}
}

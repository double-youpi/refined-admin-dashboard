<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disables Gutenberg on all post types and on the widget editor, except the
 * post types that only exist in blocks (BLOCK_ONLY_POST_TYPES, filterable).
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

	/**
	 * Post types whose edit screen only exists in the block editor: their
	 * content is blocks by definition. Forced into TinyMCE, Modern Fields'
	 * field group builder opened as an empty post, and « Update » there could
	 * wipe the fields (03/11/2026). `mf_block` comes with Modern Fields Pro.
	 */
	private const BLOCK_ONLY_POST_TYPES = [
		'wp_block',
		'mf_field_group',
		'mf_block',
	];

	protected function apply(): void {
		add_filter( 'use_block_editor_for_post',       [ self::class, 'filter_post' ], 100, 2 );
		add_filter( 'use_block_editor_for_post_type',  [ self::class, 'filter_post_type' ], 100, 2 );
		add_filter( 'use_widgets_block_editor',        '__return_false', 100 );
		add_filter( 'gutenberg_use_widgets_block_editor', '__return_false', 100 );
	}

	/**
	 * @param bool          $use  Whether WordPress would use the block editor.
	 * @param \WP_Post|mixed $post The post being edited.
	 */
	public static function filter_post( $use, $post ): bool {
		$type = $post instanceof \WP_Post ? $post->post_type : '';
		return self::keeps_block_editor( $type ) ? (bool) $use : false;
	}

	/**
	 * @param bool   $use       Whether WordPress would use the block editor.
	 * @param string $post_type The post type.
	 */
	public static function filter_post_type( $use, $post_type ): bool {
		return self::keeps_block_editor( (string) $post_type ) ? (bool) $use : false;
	}

	/**
	 * Whether a post type keeps the block editor although the tool is on.
	 */
	public static function keeps_block_editor( string $post_type ): bool {
		/**
		 * Post types that only work in the block editor and keep it when the
		 * Classic Editor tool is on.
		 *
		 * @param string[] $post_types Post type names.
		 */
		$types = (array) apply_filters( 'doubleyoupi_filter_classic_editor_block_only_post_types', self::BLOCK_ONLY_POST_TYPES );
		return $post_type !== '' && in_array( $post_type, $types, true );
	}
}

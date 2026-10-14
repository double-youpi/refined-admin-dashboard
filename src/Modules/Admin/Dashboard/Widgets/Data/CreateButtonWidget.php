<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Create button" widget — a single large button to create a new post
 * of the configured type. 1 slot recommended.
 *
 * Instanceable: multiple buttons can be placed for different types
 * (article + page + project on the same row).
 */
final class CreateButtonWidget extends AbstractDataWidget {

	public function id(): string {
		return 'create_button';
	}

	public function required_cap(): string {
		return 'edit_posts';
	}

	public function name(): string {
		return __( 'Create button', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function palette_name(): string {
		return __( 'Create (one type)', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Single-action button to create a new post of the configured type.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
	}

	public function is_instanceable(): bool {
		return true;
	}

	public function recommended_slots(): int {
		return 1;
	}

	public function default_config(): array {
		return [
			'post_type' => 'post',
			'label'     => '',
		];
	}

	/**
	 * Content types excluded from the selector (not relevant for a quick
	 * create button): media, plus technical types from builders such as Bricks
	 * (templates, floating elements, etc.). Filterable.
	 *
	 * @return array<int, string>
	 */
	private function excluded_post_types(): array {
		$excluded = [ 'attachment' ];
		/** @var array<int, string> $excluded */
		return (array) apply_filters( 'doubleyoupi_dashboard_create_button_excluded', $excluded );
	}

	public function config_schema(): array {
		$excluded = $this->excluded_post_types();
		$options  = [];
		foreach ( get_post_types( [ 'public' => true, 'show_ui' => true ], 'objects' ) as $pt ) {
			$name = (string) $pt->name;
			// Exclude media + "technical" builder post types: templates,
			// libraries and floating elements — not editorial content
			// relevant for a "create" button.
			if (
				in_array( $name, $excluded, true )
				|| strpos( $name, 'bricks' ) === 0
				|| strpos( $name, 'elementor' ) === 0
				|| strpos( $name, 'template' ) !== false
				|| strpos( $name, 'library' ) !== false
				|| strpos( $name, 'floating' ) !== false
			) {
				continue;
			}
			$options[] = [
				'value' => $name,
				'label' => isset( $pt->labels->singular_name ) ? (string) $pt->labels->singular_name : $name,
			];
		}
		return [
			[ 'type' => 'info', 'text' => __( 'A button that immediately creates a new entry of the chosen content type.', 'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'key' => 'post_type', 'type' => 'select', 'label' => __( 'Post type', 'doubleyoupi-refined-admin-dashboard' ), 'options' => $options ],
			[ 'key' => 'label', 'type' => 'text', 'label' => __( 'Label (optional)', 'doubleyoupi-refined-admin-dashboard' ), 'placeholder' => __( 'New …', 'doubleyoupi-refined-admin-dashboard' ) ],
		];
	}

	public function validate_config( array $config ): array {
		$type = isset( $config['post_type'] ) ? sanitize_key( (string) $config['post_type'] ) : 'post';
		// Verify the post type exists and is public; fall back to 'post' otherwise.
		if ( ! post_type_exists( $type ) ) {
			$type = 'post';
		}
		return [
			'post_type' => $type,
			'label'     => sanitize_text_field( (string) ( $config['label'] ?? '' ) ),
		];
	}

	public function get_data( array $config ): array {
		$type = (string) ( $config['post_type'] ?? 'post' );
		$obj  = get_post_type_object( $type );
		$singular = $obj && isset( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : ucfirst( $type );
		// Default label = WordPress native "Add new …" label, already translated
		// and properly inflected per post type ("Add new post", "Add new page",
		// etc.). Falls back to "New %s" if absent.
		$default_label = $obj && ! empty( $obj->labels->add_new_item )
			? (string) $obj->labels->add_new_item
			// translators: %s is the singular post-type name (e.g. "Post", "Page").
			: sprintf( __( 'New %s', 'doubleyoupi-refined-admin-dashboard' ), $singular );
		return [
			'singular'      => $singular,
			'default_label' => $default_label,
			'icon'          => self::dashicon_for( $type, $obj ),
			'add_url'       => admin_url( 'post-new.php' . ( $type !== 'post' ? '?post_type=' . $type : '' ) ),
		];
	}

	/**
	 * Default dashicon for the content type: reuses the post type's
	 * `menu_icon` if it is a dashicon, otherwise a lookup map for common
	 * types, otherwise a generic icon.
	 */
	private static function dashicon_for( string $type, ?\WP_Post_Type $obj ): string {
		if ( $obj && is_string( $obj->menu_icon ) && strpos( $obj->menu_icon, 'dashicons-' ) === 0 ) {
			return substr( $obj->menu_icon, strlen( 'dashicons-' ) );
		}
		$map = [
			'post'    => 'admin-post',
			'page'    => 'admin-page',
			'product' => 'cart',
		];
		return $map[ $type ] ?? 'plus';
	}

	public function render_html( array $config, array $data ): string {
		$label = (string) ( $config['label'] ?? '' );
		if ( $label === '' ) {
			$label = (string) ( $data['default_label'] ?? __( 'Create', 'doubleyoupi-refined-admin-dashboard' ) );
		}
		$icon = (string) ( $data['icon'] ?? 'plus' );

		ob_start();
		?>
		<a class="dy-dashboard__create-btn" href="<?php echo esc_url( (string) ( $data['add_url'] ?? '#' ) ); ?>">
			<span class="dy-dashboard__create-btn-icon" aria-hidden="true">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
				<span class="dy-dashboard__create-btn-plus">
					<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="6" x2="12" y2="18"/><line x1="6" y1="12" x2="18" y2="12"/></svg>
				</span>
			</span>
			<span class="dy-dashboard__create-btn-label"><?php echo esc_html( $label ); ?></span>
		</a>
		<?php
		return (string) ob_get_clean();
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Create" hub widget — one creation tile PER public content type,
 * generated AUTOMATICALLY. This includes custom post types defined by
 * the site (a new CPT appears on its own, zero per-page config → manifesto).
 *
 * Each tile leads to the type's native "add new" screen and is only
 * shown when the current user has the capability to create that type.
 * Technical types (media, builder templates) are excluded as in CreateButtonWidget.
 *
 * Singleton: only one creation hub makes sense on a screen.
 */
final class CreateHubWidget extends AbstractDataWidget {

	public function id(): string {
		return 'create_hub';
	}

	public function required_cap(): string {
		return 'edit_posts';
	}

	public function name(): string {
		return __( 'Create', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function palette_name(): string {
		return __( 'Create (all types)', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function recommended_slots(): int {
		// Wide (4 slots = 2/3 of the row): aligns several large create
		// buttons on a single line.
		return 4;
	}

	public function description(): string {
		return __( 'One create button per content type, generated automatically, including your custom post types.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Square with + (create) surrounded by small dots = "multiple types".
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="13" height="13" rx="2"/><line x1="9.5" y1="7" x2="9.5" y2="12"/><line x1="7" y1="9.5" x2="12" y2="9.5"/><path d="M16 20h4"/><path d="M18 18v4"/></svg>';
	}

	/**
	 * Content types excluded from the hub (not relevant for a quick create
	 * button). Filterable; shares the same spirit as CreateButtonWidget.
	 *
	 * @return array<int, string>
	 */
	private function excluded_post_types(): array {
		$excluded = [ 'attachment' ];
		/** @var array<int, string> $excluded */
		return (array) apply_filters( 'doubleyoupi_dashboard_create_hub_excluded', $excluded );
	}

	/**
	 * Resolved list of content types the current user can create.
	 *
	 * @return array<int, array{type:string, label:string, icon:string, url:string}>
	 */
	private function creatable_types(): array {
		$excluded = $this->excluded_post_types();
		$out      = [];

		foreach ( get_post_types( [ 'public' => true, 'show_ui' => true ], 'objects' ) as $pt ) {
			$name = (string) $pt->name;

			// Exclusions: media + "technical" builder types (templates,
			// libraries, floating elements) — not editorial content.
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

			// Required creation capability (create_posts, else edit_posts).
			$cap = isset( $pt->cap->create_posts ) ? (string) $pt->cap->create_posts : (string) ( $pt->cap->edit_posts ?? 'edit_posts' );
			if ( ! current_user_can( $cap ) ) {
				continue;
			}

			$singular = isset( $pt->labels->singular_name ) ? (string) $pt->labels->singular_name : ucfirst( $name );
			$out[] = [
				'type'  => $name,
				'label' => $singular,
				'icon'  => self::dashicon_for( $name, $pt ),
				'url'   => admin_url( 'post-new.php' . ( $name !== 'post' ? '?post_type=' . $name : '' ) ),
			];
		}

		return $out;
	}

	/**
	 * Dashicon for a content type: the post type's menu_icon if it is
	 * a dashicon, otherwise a lookup map, otherwise a generic icon.
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

	public function get_data( array $config ): array {
		unset( $config );
		return [ 'types' => $this->creatable_types() ];
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );
		$types = is_array( $data['types'] ?? null ) ? $data['types'] : [];

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Create', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php if ( ! empty( $types ) ) : ?>
				<span class="dy-dashboard__card-meta"><?php echo esc_html( (string) count( $types ) ); ?></span>
			<?php endif; ?>
		</div>
		<div class="dy-dashboard__card-body">
			<?php if ( empty( $types ) ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No content type available to create.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else : ?>
				<div class="dy-dashboard__create-grid">
					<?php foreach ( $types as $t ) : ?>
						<a class="dy-dashboard__create-tile" href="<?php echo esc_url( (string) $t['url'] ); ?>">
							<span class="dy-dashboard__create-tile-icon" aria-hidden="true">
								<span class="dashicons dashicons-<?php echo esc_attr( (string) $t['icon'] ); ?>"></span>
								<span class="dy-dashboard__create-tile-plus">
									<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="6" x2="12" y2="18"/><line x1="6" y1="12" x2="18" y2="12"/></svg>
								</span>
							</span>
							<span class="dy-dashboard__create-tile-label"><?php echo esc_html( (string) $t['label'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

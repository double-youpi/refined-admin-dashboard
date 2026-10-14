<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Shortcuts" widget — configurable bookmarks to frequently used admin
 * pages per site (Bricks templates, Yoast settings, snippets, etc.).
 *
 * Instanceable: multiple shortcut widgets can be placed for categorised
 * groups ("Builder", "SEO", "Security").
 */
final class ShortcutsWidget extends AbstractDataWidget {

	public function id(): string {
		return 'shortcuts';
	}

	public function name(): string {
		return __( 'Shortcuts', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Configurable bookmarks to frequently used admin pages. Multiple instances possible to categorize.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// External link — arrow leaving a square.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>';
	}

	public function is_instanceable(): bool {
		return true;
	}

	public function default_config(): array {
		return [
			'items' => [ 'posts', 'media', 'settings' ],
		];
	}

	/**
	 * Catalogue of available admin destinations. Each entry:
	 * label + URL (admin-relative) + dashicon. Single source of truth
	 * for the config dropdown, validation, and rendering.
	 *
	 * @return array<string, array{label:string, url:string, icon:string}>
	 */
	public static function shortcuts_catalog(): array {
		return [
			'posts'      => [ 'label' => __( 'Posts', 'doubleyoupi-refined-admin-dashboard' ),       'url' => 'edit.php',                     'icon' => 'admin-post' ],
			'pages'      => [ 'label' => __( 'Pages', 'doubleyoupi-refined-admin-dashboard' ),       'url' => 'edit.php?post_type=page',      'icon' => 'admin-page' ],
			'media'      => [ 'label' => __( 'Media', 'doubleyoupi-refined-admin-dashboard' ),       'url' => 'upload.php',                   'icon' => 'admin-media' ],
			'comments'   => [ 'label' => __( 'Comments', 'doubleyoupi-refined-admin-dashboard' ),    'url' => 'edit-comments.php',            'icon' => 'admin-comments' ],
			'menus'      => [ 'label' => __( 'Menus', 'doubleyoupi-refined-admin-dashboard' ),       'url' => 'nav-menus.php',                'icon' => 'menu' ],
			'themes'     => [ 'label' => __( 'Themes', 'doubleyoupi-refined-admin-dashboard' ),      'url' => 'themes.php',                   'icon' => 'admin-appearance' ],
			'widgets'    => [ 'label' => __( 'Widgets', 'doubleyoupi-refined-admin-dashboard' ),     'url' => 'widgets.php',                  'icon' => 'screenoptions' ],
			'plugins'    => [ 'label' => __( 'Plugins', 'doubleyoupi-refined-admin-dashboard' ),     'url' => 'plugins.php',                  'icon' => 'admin-plugins' ],
			'users'      => [ 'label' => __( 'Users', 'doubleyoupi-refined-admin-dashboard' ),       'url' => 'users.php',                    'icon' => 'admin-users' ],
			'tools'      => [ 'label' => __( 'Tools', 'doubleyoupi-refined-admin-dashboard' ),       'url' => 'tools.php',                    'icon' => 'admin-tools' ],
			'settings'   => [ 'label' => __( 'Settings', 'doubleyoupi-refined-admin-dashboard' ),    'url' => 'options-general.php',          'icon' => 'admin-settings' ],
			'permalinks' => [ 'label' => __( 'Permalinks', 'doubleyoupi-refined-admin-dashboard' ),  'url' => 'options-permalink.php',        'icon' => 'admin-links' ],
			'customize'  => [ 'label' => __( 'Customizer', 'doubleyoupi-refined-admin-dashboard' ),  'url' => 'customize.php',                'icon' => 'admin-customizer' ],
			'site_health' => [ 'label' => __( 'Site Health', 'doubleyoupi-refined-admin-dashboard' ), 'url' => 'site-health.php',             'icon' => 'heart' ],
		];
	}

	public function config_schema(): array {
		$options = [];
		foreach ( self::shortcuts_catalog() as $key => $def ) {
			$options[] = [ 'value' => (string) $key, 'label' => (string) $def['label'] ];
		}
		return [
			[ 'type' => 'info', 'text' => __( 'Bookmarks to the admin pages you visit most. Add one from the dropdown.', 'doubleyoupi-refined-admin-dashboard' ) ],
			[
				'key'       => 'items',
				'type'      => 'picklist',
				'label'     => __( 'Shortcuts', 'doubleyoupi-refined-admin-dashboard' ),
				'max'       => 8,
				'add_label' => __( 'Add a shortcut', 'doubleyoupi-refined-admin-dashboard' ),
				'options'   => $options,
			],
		];
	}

	public function validate_config( array $config ): array {
		$available = array_keys( self::shortcuts_catalog() );
		$items     = is_array( $config['items'] ?? null ) ? $config['items'] : $this->default_config()['items'];
		$items     = array_values( array_unique( array_filter(
			array_map( static fn( $i ) => sanitize_key( (string) $i ), $items ),
			static fn( $i ) => in_array( $i, $available, true )
		) ) );
		return [ 'items' => array_slice( $items, 0, 8 ) ];
	}

	public function get_data( array $config ): array {
		$catalog  = self::shortcuts_catalog();
		$resolved = [];
		foreach ( (array) ( $config['items'] ?? [] ) as $key ) {
			$key = (string) $key;
			if ( ! isset( $catalog[ $key ] ) ) {
				continue;
			}
			$def = $catalog[ $key ];
			$url = (string) $def['url'];
			if ( $url !== '' && ! preg_match( '#^https?://#', $url ) ) {
				$url = admin_url( ltrim( $url, '/' ) );
			}
			$resolved[] = [
				'label' => (string) $def['label'],
				'url'   => $url,
				'icon'  => (string) $def['icon'],
			];
		}
		return [ 'links' => $resolved ];
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );
		$links = is_array( $data['links'] ?? null ) ? $data['links'] : [];

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Shortcuts', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
		</div>
		<div class="dy-dashboard__card-body dy-dashboard__shortcuts">
			<?php if ( empty( $links ) ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No shortcut configured.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else : ?>
				<?php foreach ( $links as $link ) : ?>
					<a class="dy-dashboard__shortcut" href="<?php echo esc_url( (string) $link['url'] ); ?>">
						<?php if ( ! empty( $link['icon'] ) ) : ?>
							<span class="dashicons dashicons-<?php echo esc_attr( (string) $link['icon'] ); ?>" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="dy-dashboard__shortcut-label"><?php echo esc_html( (string) $link['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Core\ModuleRegistry;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Quick actions" widget — configurable buttons for frequent admin actions
 * (new post, clear cache, etc.).
 *
 * Instanceable: multiple action sets can coexist for distinct workflows
 * (editing vs maintenance, for example).
 */
final class QuickActionsWidget extends AbstractDataWidget {

	public function id(): string {
		return 'quick_actions';
	}

	public function name(): string {
		return __( 'Quick actions', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Configurable buttons for frequent admin actions. Multiple instances possible for distinct workflows.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Lightning bolt — speed metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
	}

	public function category(): string {
		return 'data';
	}

	public function is_instanceable(): bool {
		// Instanceable despite its "data" role — purely configurable,
		// no system state is read.
		return true;
	}

	public function default_config(): array {
		// Only actions this build can perform (no "Clear cache" without the
		// Performance module).
		return [
			'actions' => array_values( array_intersect( [ 'new_post', 'new_page', 'new_media', 'cache_flush' ], array_keys( self::action_catalog() ) ) ),
		];
	}

	public function config_schema(): array {
		$options = [];
		foreach ( self::action_catalog() as $key => $def ) {
			// Role-dependent list: only offer actions for which the current
			// user has the capability (an editor does not see "Clear cache",
			// "+ User", etc.).
			if ( ! current_user_can( (string) $def['cap'] ) ) {
				continue;
			}
			$options[] = [ 'value' => (string) $key, 'label' => (string) $def['label'] ];
		}
		return [
			[ 'type' => 'info', 'text' => __( 'Quick-access buttons for the admin actions you run most often. Add one from the dropdown.', 'doubleyoupi-refined-admin-dashboard' ) ],
			[
				'key'        => 'actions',
				'type'       => 'picklist',
				'label'      => __( 'Actions', 'doubleyoupi-refined-admin-dashboard' ),
				'max'        => 6,
				'add_label'  => __( 'Add an action', 'doubleyoupi-refined-admin-dashboard' ),
				'options'    => $options,
			],
		];
	}

	public function validate_config( array $config ): array {
		$available = array_keys( self::action_catalog() );
		$actions   = is_array( $config['actions'] ?? null ) ? $config['actions'] : $this->default_config()['actions'];
		$actions   = array_filter(
			array_map(
				static fn( $a ) => sanitize_key( (string) $a ),
				$actions
			),
			static fn( $a ) => in_array( $a, $available, true )
		);
		// Cap at 6 actions to avoid visual clutter.
		$actions = array_slice( array_values( array_unique( $actions ) ), 0, 6 );
		return [ 'actions' => $actions ];
	}

	/**
	 * Catalogue of supported actions. Single source of truth for validation
	 * and rendering. Each entry: label + admin URL + required capability.
	 *
	 * @return array<string, array{label:string, url:string, cap:string}>
	 */
	public static function action_catalog(): array {
		$catalog = [
			'new_post'        => [
				'label' => __( '+ Post', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'post-new.php' ),
				'cap'   => 'edit_posts',
			],
			'new_page'        => [
				'label' => __( '+ Page', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'post-new.php?post_type=page' ),
				'cap'   => 'edit_pages',
			],
			'new_media'       => [
				'label' => __( '+ Media', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'media-new.php' ),
				'cap'   => 'upload_files',
			],
			'plugins_update'  => [
				'label' => __( 'Update plugins', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'plugins.php?plugin_status=upgrade' ),
				'cap'   => 'update_plugins',
			],
			'menus_edit'      => [
				'label' => __( 'Menus', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'nav-menus.php' ),
				'cap'   => 'edit_theme_options',
			],
			'new_user'        => [
				'label' => __( '+ User', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'user-new.php' ),
				'cap'   => 'create_users',
			],
			'comments'        => [
				'label' => __( 'Comments', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'edit-comments.php' ),
				'cap'   => 'moderate_comments',
			],
			'themes'          => [
				'label' => __( 'Themes', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'themes.php' ),
				'cap'   => 'switch_themes',
			],
			'customize'       => [
				'label' => __( 'Customizer', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'customize.php' ),
				'cap'   => 'customize',
			],
			'widgets'         => [
				'label' => __( 'Widgets', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'widgets.php' ),
				'cap'   => 'edit_theme_options',
			],
			'site_health'     => [
				'label' => __( 'Site Health', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'site-health.php' ),
				'cap'   => 'view_site_health_checks',
			],
			'settings'        => [
				'label' => __( 'Settings', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'options-general.php' ),
				'cap'   => 'manage_options',
			],
			'permalinks'      => [
				'label' => __( 'Permalinks', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'options-permalink.php' ),
				'cap'   => 'manage_options',
			],
		];
		// "Clear cache" belongs to the Performance module: offered only when
		// it ships (Refined Admin Dashboard alone has no cache to clear).
		if ( ModuleRegistry::has( 'performance' ) ) {
			$catalog['cache_flush'] = [
				'label' => __( 'Clear cache', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => admin_url( 'admin.php?page=doubleyoupi-performance#cache' ),
				'cap'   => 'manage_options',
			];
		}
		return $catalog;
	}

	public function get_data( array $config ): array {
		$catalog = self::action_catalog();
		$resolved = [];
		foreach ( (array) ( $config['actions'] ?? [] ) as $key ) {
			$key = (string) $key;
			if ( isset( $catalog[ $key ] ) && current_user_can( $catalog[ $key ]['cap'] ) ) {
				$resolved[] = [
					'key'   => $key,
					'label' => $catalog[ $key ]['label'],
					'url'   => $catalog[ $key ]['url'],
				];
			}
		}
		return [ 'actions' => $resolved ];
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );
		$actions = is_array( $data['actions'] ?? null ) ? $data['actions'] : [];

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Quick actions', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
		</div>
		<div class="dy-dashboard__card-body dy-dashboard__actions">
			<?php if ( empty( $actions ) ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No action. Edit the widget config.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else : ?>
				<?php foreach ( $actions as $action ) : ?>
					<a class="dy-dashboard__btn-ghost" href="<?php echo esc_url( (string) $action['url'] ); ?>">
						<?php echo esc_html( (string) $action['label'] ); ?>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

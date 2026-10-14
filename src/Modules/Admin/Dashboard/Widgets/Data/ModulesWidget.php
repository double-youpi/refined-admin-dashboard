<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Core\ModuleCatalog;
use DoubleYoupi\Core\ModuleSettings;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;
use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Double Youpi "Modules" widget — horizontal strip showing the state of
 * the suite's modules (enabled / disabled / coming soon).
 */
final class ModulesWidget extends AbstractDataWidget {

	public function id(): string {
		return 'modules';
	}

	public function required_cap(): string {
		return 'manage_options';
	}

	public function name(): string {
		// The Double Youpi brand is signed by an inline SVG logo injected
		// after the text (see dy_brand_logo_svg on AbstractWidget + the
		// `brand_logo_svg` hook exposed by WidgetRegistry::to_panel_tiles).
		return __( 'Modules', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Status of the suite modules: active, disabled, in progress.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// 4 rounded rectangles — modules metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );

		$modules = [];
		foreach ( ModuleCatalog::all() as $module ) {
			$is_available = ( $module['status'] ?? '' ) === ModuleCatalog::STATUS_AVAILABLE;
			$is_enabled   = $is_available && ModuleSettings::is_enabled( $module['id'] );
			$modules[] = [
				'id'      => (string) $module['id'],
				'name'    => (string) $module['name'],
				'state'   => ! $is_available ? 'soon' : ( $is_enabled ? 'on' : 'off' ),
				'url'     => $module['settings_url'] ?? null,
			];
		}

		$active = array_filter( $modules, static fn( $m ) => $m['state'] === 'on' );

		// Hide "coming soon" modules (in development) and sort:
		// active ones first, then inactive.
		$visible = array_values( array_filter( $modules, static fn( $m ) => $m['state'] !== 'soon' ) );
		usort( $visible, static fn( $a, $b ) => ( ( $b['state'] === 'on' ) <=> ( $a['state'] === 'on' ) ) );

		return [
			'modules'      => $visible,
			'active_count' => count( $active ),
			'total'        => count( $visible ),
			'settings_url' => admin_url( 'admin.php?page=doubleyoupi' ),
		];
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		$modules      = is_array( $data['modules'] ?? null ) ? $data['modules'] : [];
		$active_count = (int) ( $data['active_count'] ?? 0 );
		$total        = (int) ( $data['total'] ?? count( $modules ) );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title">
				<?php esc_html_e( 'Modules', 'doubleyoupi-refined-admin-dashboard' ); ?>
				<?php echo wp_kses( self::dy_brand_logo_svg(), Kses::svg_tags() ); ?>
			</span>
			<span class="dy-dashboard__pill dy-dashboard__pill--accent">
				<?php
				printf(
					/* translators: 1: active count, 2: total */
					esc_html__( '%1$d / %2$d', 'doubleyoupi-refined-admin-dashboard' ),
					(int) $active_count,
					(int) $total
				);
				?>
			</span>
		</div>
		<div class="dy-dashboard__card-body dy-dashboard__badges">
			<?php foreach ( $modules as $m ) :
				$on    = (string) $m['state'] === 'on';
				$state = $on ? __( 'Active', 'doubleyoupi-refined-admin-dashboard' ) : __( 'Off', 'doubleyoupi-refined-admin-dashboard' );
				?>
				<span class="dy-dashboard__badge<?php echo $on ? ' is-on' : ''; ?>" title="<?php echo esc_attr( $m['name'] . ' · ' . $state ); ?>">
					<span class="dy-dashboard__badge-pip" aria-hidden="true"></span>
					<span class="dy-dashboard__badge-label"><?php echo esc_html( (string) $m['name'] ); ?></span>
				</span>
			<?php endforeach; ?>
		</div>
		<?php if ( ! empty( $data['settings_url'] ) ) : ?>
			<?php echo wp_kses( self::dy_foot_link( (string) $data['settings_url'], __( 'Settings', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() ); ?>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}
}

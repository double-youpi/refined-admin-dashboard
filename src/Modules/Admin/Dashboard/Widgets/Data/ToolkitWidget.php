<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;
use DoubleYoupi\Modules\Toolkit\Settings as ToolkitSettings;
use DoubleYoupi\Admin\Kses;
use DoubleYoupi\Admin\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Toolkit" widget — state of the quick toggles (Disable Comments, Classic
 * Editor, safe SVG, forced HTTPS, etc.).
 *
 * These are NOT modules to activate: they are always-available switches.
 * Their state is read directly via Toolkit\Settings::features()
 * (pure registry, no side effects), independently of the module boot.
 */
final class ToolkitWidget extends AbstractDataWidget {

	public function id(): string {
		return 'toolkit';
	}

	public function required_cap(): string {
		return Access::CAP;
	}

	public function name(): string {
		// Same as ModulesWidget: the Double Youpi brand is signed by the
		// SVG logo injected after the text.
		return __( 'Toolkit', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'State of the quick toggles (Disable Comments, Classic Editor, etc.).', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.7 6.3a3.5 3.5 0 0 0-4.9 4.9L3 18l3 3 6.8-6.8a3.5 3.5 0 0 0 4.9-4.9l-2.4 2.4-1.6-1.6 2.4-2.4z"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );

		if ( ! class_exists( ToolkitSettings::class ) ) {
			return [ 'available' => false, 'enabled_count' => 0, 'total' => 0, 'items' => [] ];
		}

		$items = [];
		foreach ( ToolkitSettings::features() as $feature ) {
			$items[] = [
				'id'      => $feature->id(),
				'name'    => $feature->label(),
				'enabled' => $feature->is_enabled(),
				'blocked' => $feature->is_externally_blocked(),
			];
		}

		// Sort: enabled first, then the rest (registry order preserved).
		usort( $items, static fn( $a, $b ) => ( $b['enabled'] <=> $a['enabled'] ) );

		$enabled = array_filter( $items, static fn( $i ) => $i['enabled'] );

		return [
			'available'     => true,
			'enabled_count' => count( $enabled ),
			'total'         => count( $items ),
			'items'         => $items,
			// The Tools page (Toolkit's ToolsPage::PAGE_SLUG, named here rather
			// than imported: a module does not load another module's classes).
			'settings_url'  => admin_url( 'admin.php?page=doubleyoupi-tools' ),
		];
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		$items     = is_array( $data['items'] ?? null ) ? $data['items'] : [];
		$available = ! empty( $data['available'] );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title">
				<?php esc_html_e( 'Toolkit', 'doubleyoupi-refined-admin-dashboard' ); ?>
				<?php echo wp_kses( self::dy_brand_logo_svg(), Kses::svg_tags() ); ?>
			</span>
			<?php if ( $available && $items ) : ?>
				<span class="dy-dashboard__pill dy-dashboard__pill--accent">
					<?php
					printf(
						/* translators: 1: enabled count, 2: total */
						esc_html__( '%1$d / %2$d', 'doubleyoupi-refined-admin-dashboard' ),
						(int) $data['enabled_count'],
						(int) $data['total']
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<div class="dy-dashboard__card-body dy-dashboard__badges">
			<?php if ( ! $available || ! $items ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No toggle available.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else : ?>
				<?php foreach ( $items as $item ) :
					$on   = ! empty( $item['enabled'] );
					$name = (string) ( $item['name'] ?? $item['id'] ?? '' );
					?>
					<span class="dy-dashboard__badge<?php echo $on ? ' is-on' : ''; ?>" title="<?php echo esc_attr( $name ); ?>">
						<span class="dy-dashboard__badge-pip" aria-hidden="true"></span>
						<span class="dy-dashboard__badge-label"><?php echo esc_html( $name ); ?></span>
					</span>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php if ( ! empty( $data['settings_url'] ) ) : ?>
			<?php echo wp_kses( self::dy_foot_link( (string) $data['settings_url'], __( 'Settings', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() ); ?>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}
}

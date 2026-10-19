<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;
use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Site status" widget — overall health at a glance.
 * WP/PHP versions, SSL, plugins awaiting update.
 */
final class SiteStateWidget extends AbstractDataWidget {

	public function id(): string {
		return 'site_state';
	}

	public function required_cap(): string {
		return 'update_core';
	}

	public function name(): string {
		return __( 'Site status', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'WordPress and PHP versions, SSL, pending updates.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Pulse line — ECG spike, health metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );

		if ( ! function_exists( 'wp_get_update_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates = wp_get_update_data();

		return [
			'wp_version'       => get_bloginfo( 'version' ),
			'php_version'      => PHP_VERSION,
			'is_ssl'           => is_ssl(),
			'plugins_outdated' => (int) ( $updates['counts']['plugins'] ?? 0 ),
			'themes_outdated'  => (int) ( $updates['counts']['themes'] ?? 0 ),
			'core_outdated'    => (int) ( $updates['counts']['wordpress'] ?? 0 ),
		];
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		$wp     = isset( $data['wp_version'] ) ? (string) $data['wp_version'] : '?';
		$php    = isset( $data['php_version'] ) ? (string) $data['php_version'] : '?';
		$ssl    = ! empty( $data['is_ssl'] );
		$plugins = (int) ( $data['plugins_outdated'] ?? 0 );
		$themes  = (int) ( $data['themes_outdated'] ?? 0 );
		$core    = (int) ( $data['core_outdated'] ?? 0 );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Site status', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php if ( $plugins + $themes + $core > 0 ) : ?>
				<span class="dy-dashboard__pill dy-dashboard__pill--warn">
					<?php echo esc_html( (string) ( $plugins + $themes + $core ) ); ?> <?php esc_html_e( 'upd.', 'doubleyoupi-refined-admin-dashboard' ); ?>
				</span>
			<?php else : ?>
				<span class="dy-dashboard__pill dy-dashboard__pill--ok"><?php esc_html_e( 'up to date', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="dy-dashboard__card-body">
			<div class="dy-dashboard__row">
				<span class="dy-dashboard__dot dy-dashboard__dot--<?php echo $core > 0 ? 'warn' : 'ok'; ?>"></span>
				<span class="dy-dashboard__row-v">WP <?php echo esc_html( $wp ); ?></span>
				<?php if ( $core > 0 ) : ?>
					<span class="dy-dashboard__row-k"><?php esc_html_e( 'Update available', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="dy-dashboard__row">
				<span class="dy-dashboard__dot dy-dashboard__dot--<?php echo version_compare( $php, '8.1', '<' ) ? 'warn' : 'ok'; ?>"></span>
				<span class="dy-dashboard__row-v">PHP <?php echo esc_html( $php ); ?></span>
			</div>
			<div class="dy-dashboard__row">
				<span class="dy-dashboard__dot dy-dashboard__dot--<?php echo $ssl ? 'ok' : 'warn'; ?>"></span>
				<span class="dy-dashboard__row-v"><?php echo $ssl ? esc_html__( 'SSL active', 'doubleyoupi-refined-admin-dashboard' ) : esc_html__( 'SSL absent', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			</div>
			<?php if ( $plugins > 0 ) : ?>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__dot dy-dashboard__dot--warn"></span>
					<span class="dy-dashboard__row-v">
						<?php
						printf(
							/* translators: %d: plugin update count */
							esc_html( _n( '%d plugin to update', '%d plugins to update', $plugins, 'doubleyoupi-refined-admin-dashboard' ) ),
							(int) $plugins
						);
						?>
					</span>
				</div>
			<?php endif; ?>
		</div>
		<?php
		// Link to the WordPress Updates screen (consistent with the content:
		// WP/PHP versions, plugins/themes to update).
		echo wp_kses( self::dy_foot_link( admin_url( 'update-core.php' ), __( 'Updates', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() );
		return (string) ob_get_clean();
	}
}

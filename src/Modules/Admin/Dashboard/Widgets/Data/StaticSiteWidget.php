<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;
use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Static site" widget — state of the Static Site module (active mode,
 * last export, generated page count). Reuses filters exposed by
 * `src/Modules/StaticSite/`.
 *
 * If the module is not loaded, displays a neutral state + link to the
 * admin page to activate it.
 */
final class StaticSiteWidget extends AbstractDataWidget {

	public function id(): string {
		return 'static_site';
	}

	public function required_cap(): string {
		return 'manage_options';
	}

	public function name(): string {
		return __( 'Static site', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Static site mode, page count, last export.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Static globe with lines — "frozen site" metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a13 13 0 0 1 0 18"/><path d="M12 3a13 13 0 0 0 0 18"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );

		/**
		 * The StaticSite module exposes its state via this filter. Expected format:
		 *   [
		 *     'mode'        => 'dynamic'|'fallback'|'strict',
		 *     'pages_count' => int,
		 *     'last_export' => int|null  (UNIX timestamp),
		 *     'last_status' => 'ok'|'fail'|null,
		 *   ]
		 */
		$snapshot = apply_filters( 'doubleyoupi_static_site_snapshot', null );

		if ( ! is_array( $snapshot ) ) {
			return [ 'has_data' => false ];
		}

		return [
			'has_data'    => true,
			'mode'        => (string) ( $snapshot['mode'] ?? 'dynamic' ),
			'pages_count' => (int) ( $snapshot['pages_count'] ?? 0 ),
			'last_export' => isset( $snapshot['last_export'] ) ? (int) $snapshot['last_export'] : null,
			'last_status' => isset( $snapshot['last_status'] ) ? (string) $snapshot['last_status'] : null,
		];
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		ob_start();
		?>
		<?php
		// Human-readable mode label (the raw key 'fallback'/'strict'/'dynamic'
		// means nothing to users). Badge logic:
		//   strict   → ok     (goal achieved, maximum security)
		//   fallback → warn   (transition, hybrid mode — pulsing dot)
		//   dynamic  → danger + static: red (static disabled = WP attack surface
		//              exposed, signalled accordingly) BUT fixed dot —
		//              it is a stable state, not a flashing alert.
		$mode      = (string) ( $data['mode'] ?? 'dynamic' );
		$mode_label = match ( $mode ) {
			'strict'   => __( 'Static', 'doubleyoupi-refined-admin-dashboard' ),
			'fallback' => __( 'Hybrid', 'doubleyoupi-refined-admin-dashboard' ),
			default    => __( 'Disabled', 'doubleyoupi-refined-admin-dashboard' ),
		};
		$mode_pill_class = match ( $mode ) {
			'strict'   => 'dy-dashboard__pill dy-dashboard__pill--ok',
			'fallback' => 'dy-dashboard__pill dy-dashboard__pill--warn',
			default    => 'dy-dashboard__pill dy-dashboard__pill--danger dy-dashboard__pill--static',
		};
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Static site', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php if ( ! empty( $data['has_data'] ) ) : ?>
				<span class="<?php echo esc_attr( $mode_pill_class ); ?>">
					<?php echo esc_html( $mode_label ); ?>
				</span>
			<?php endif; ?>
		</div>
		<?php $static_settings_url = admin_url( 'admin.php?page=doubleyoupi-static' ); ?>
		<div class="dy-dashboard__card-body">
			<?php if ( empty( $data['has_data'] ) ) : ?>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__row-v"><?php esc_html_e( 'Static Site module not active.', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
				</div>
			<?php else : ?>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__dot dy-dashboard__dot--ok"></span>
					<span class="dy-dashboard__row-v">
						<?php
						printf(
							/* translators: %d: page count */
							esc_html( _n( '%d page generated', '%d pages generated', (int) $data['pages_count'], 'doubleyoupi-refined-admin-dashboard' ) ),
							(int) $data['pages_count']
						);
						?>
					</span>
				</div>
				<?php if ( ! empty( $data['last_export'] ) ) : ?>
					<div class="dy-dashboard__row">
						<span class="dy-dashboard__dot dy-dashboard__dot--<?php echo ( $data['last_status'] ?? 'ok' ) === 'ok' ? 'ok' : 'warn'; ?>"></span>
						<span class="dy-dashboard__row-v">
							<?php
							printf(
								/* translators: %s: human-readable time diff */
								esc_html__( 'Last export: %s ago', 'doubleyoupi-refined-admin-dashboard' ),
								esc_html( human_time_diff( (int) $data['last_export'], time() ) )
							);
							?>
						</span>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php echo wp_kses( self::dy_foot_link( $static_settings_url, __( 'Configure', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() ); ?>
		<?php
		return (string) ob_get_clean();
	}
}

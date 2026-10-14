<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;
use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "WordPress health" widget — aggregates WP_Site_Health results (core).
 *
 * Reads the value stored by WP in `health-check-site-status-result` or
 * recomputes on the fly if absent. No background HTTP call — only reads
 * what is already stored as an option (computed by the native WP cron).
 */
final class SiteHealthWidget extends AbstractDataWidget {

	public function id(): string {
		return 'site_health';
	}

	public function required_cap(): string {
		return 'view_site_health_checks';
	}

	public function name(): string {
		return __( 'WordPress health', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Summary of WP_Site_Health tests: critical, recommended, passed.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Plus-shield — WP "health" metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2L4 6v6c0 5 3.5 8.5 8 10 4.5-1.5 8-5 8-10V6l-8-4z"/><line x1="12" y1="8" x2="12" y2="14"/><line x1="9" y1="11" x2="15" y2="11"/></svg>';
	}

	public const CACHE_KEY = 'doubleyoupi_dashboard_site_health';

	public function get_data( array $config ): array {
		unset( $config );

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$counts = $this->compute_direct_tests();

		// Bonus: if WP has stored a complete result (Site Health screen visited,
		// which includes async tests), take the higher values.
		$stored = get_option( 'health-check-site-status-result', null );
		if ( is_string( $stored ) ) {
			$stored = json_decode( $stored, true );
		}
		if ( is_array( $stored ) ) {
			foreach ( [ 'good', 'recommended', 'critical' ] as $k ) {
				$counts[ $k ] = max( $counts[ $k ], (int) ( $stored[ $k ] ?? 0 ) );
			}
		}

		$critical    = (int) $counts['critical'];
		$recommended = (int) $counts['recommended'];
		$good        = (int) $counts['good'];

		$status = 'good';
		if ( $critical > 0 ) {
			$status = 'critical';
		} elseif ( $recommended > 0 ) {
			$status = 'recommended';
		}

		$data = [
			'good'        => $good,
			'recommended' => $recommended,
			'critical'    => $critical,
			'status'      => $status,
			'has_data'    => ( $good + $recommended + $critical ) > 0,
		];

		set_transient( self::CACHE_KEY, $data, HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Runs WP_Site_Health "direct" tests server-side (fast, no HTTP calls)
	 * and counts statuses. Independent of a prior visit to the Site Health screen.
	 *
	 * @return array{good:int, recommended:int, critical:int}
	 */
	private function compute_direct_tests(): array {
		$counts = [ 'good' => 0, 'recommended' => 0, 'critical' => 0 ];

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			$file = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return $counts;
		}

		$health = \WP_Site_Health::get_instance();
		$tests  = $health->get_tests();
		$direct = is_array( $tests['direct'] ?? null ) ? $tests['direct'] : [];

		foreach ( $direct as $test ) {
			$callback = $test['test'] ?? null;
			$result   = null;
			try {
				if ( is_string( $callback ) && method_exists( $health, 'get_test_' . $callback ) ) {
					$result = call_user_func( [ $health, 'get_test_' . $callback ] );
				} elseif ( is_callable( $callback ) ) {
					$result = call_user_func( $callback );
				}
			} catch ( \Throwable $e ) {
				continue; // a failing test must not break the widget.
			}
			if ( is_array( $result ) && isset( $result['status'] ) && isset( $counts[ $result['status'] ] ) ) {
				++$counts[ $result['status'] ];
			}
		}

		return $counts;
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );
		$status = (string) ( $data['status'] ?? 'unknown' );
		$has    = ! empty( $data['has_data'] );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'WordPress health', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php if ( $has ) : ?>
				<span class="dy-dashboard__pill dy-dashboard__pill--<?php echo esc_attr( $status === 'good' ? 'ok' : ( $status === 'critical' ? 'danger' : 'warn' ) ); ?>">
					<?php
					if ( $status === 'good' ) {
						esc_html_e( 'healthy', 'doubleyoupi-refined-admin-dashboard' );
					} elseif ( $status === 'critical' ) {
						esc_html_e( 'critical', 'doubleyoupi-refined-admin-dashboard' );
					} else {
						esc_html_e( 'attention', 'doubleyoupi-refined-admin-dashboard' );
					}
					?>
				</span>
			<?php endif; ?>
		</div>
		<div class="dy-dashboard__card-body">
			<?php if ( ! $has ) : ?>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__row-v"><?php esc_html_e( 'Run Site Health to populate this widget.', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
				</div>
			<?php else : ?>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__dot dy-dashboard__dot--<?php echo (int) $data['critical'] > 0 ? 'warn' : 'ok'; ?>"></span>
					<span class="dy-dashboard__row-v">
						<?php
						printf(
							/* translators: %d: count */
							esc_html( _n( '%d critical issue', '%d critical issues', (int) $data['critical'], 'doubleyoupi-refined-admin-dashboard' ) ),
							(int) $data['critical']
						);
						?>
					</span>
				</div>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__dot dy-dashboard__dot--<?php echo (int) $data['recommended'] > 0 ? 'warn' : 'ok'; ?>"></span>
					<span class="dy-dashboard__row-v">
						<?php
						printf(
							/* translators: %d: count */
							esc_html( _n( '%d recommended improvement', '%d recommended improvements', (int) $data['recommended'], 'doubleyoupi-refined-admin-dashboard' ) ),
							(int) $data['recommended']
						);
						?>
					</span>
				</div>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__dot dy-dashboard__dot--ok"></span>
					<span class="dy-dashboard__row-v">
						<?php
						printf(
							/* translators: %d: count */
							esc_html( _n( '%d test passed', '%d tests passed', (int) $data['good'], 'doubleyoupi-refined-admin-dashboard' ) ),
							(int) $data['good']
						);
						?>
					</span>
				</div>
			<?php endif; ?>
		</div>
		<?php
		echo wp_kses( self::dy_foot_link( admin_url( 'site-health.php' ), __( 'Site Health', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() );
		return (string) ob_get_clean();
	}
}

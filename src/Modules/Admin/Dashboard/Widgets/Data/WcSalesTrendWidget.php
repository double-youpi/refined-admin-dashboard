<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce "Sales (7 days)" widget.
 *
 * A day-by-day revenue sparkline over the last 7 days, plus the period
 * total and order count. The area curve follows the dashboard gradient
 * aesthetic and the screen's accent colour.
 *
 * Cached 15 min.
 */
final class WcSalesTrendWidget extends AbstractWcWidget {

	private const CACHE_KEY = 'doubleyoupi_dashboard_wc_sales7';
	private const CACHE_TTL = 900;
	private const DAYS      = 7;

	public function id(): string {
		return 'wc_sales_trend';
	}

	public function required_cap(): string {
		return 'manage_woocommerce';
	}

	public function name(): string {
		return __( 'Sales (7 days)', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Daily revenue sparkline over the last 7 days, plus total and order count.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Bar chart + trend line.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l3-4 3 2 4-6"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );
		if ( ! self::wc_available() ) {
			return [ 'available' => false ];
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$tz    = wp_timezone();
		$today = new \DateTimeImmutable( 'today', $tz );

		$series = [];
		$labels = [];
		$total  = 0.0;
		$orders_count = 0;

		for ( $i = self::DAYS - 1; $i >= 0; $i-- ) {
			$day_start = $today->modify( "-{$i} days" )->setTime( 0, 0, 0 );
			$day_end   = $day_start->setTime( 23, 59, 59 );

			$orders = self::query_orders( [
				'status'       => self::paid_statuses(),
				'date_created' => $day_start->getTimestamp() . '...' . $day_end->getTimestamp(),
				'limit'        => -1,
				'return'       => 'objects',
			] );

			$day_sum = 0.0;
			if ( is_array( $orders ) ) {
				$orders_count += count( $orders );
				foreach ( $orders as $order ) {
					$day_sum += (float) $order->get_total();
				}
			}
			$series[] = round( $day_sum, 2 );
			$labels[] = wp_date( 'D', $day_start->getTimestamp() );
			$total   += $day_sum;
		}

		$best = ! empty( $series ) ? max( $series ) : 0.0;

		$data = [
			'available'    => true,
			'series'       => $series,
			'labels'       => $labels,
			'total'        => $total,
			'orders_count' => $orders_count,
			'best_day'     => $best,
		];

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		if ( empty( $data['available'] ) ) {
			return '<div class="dy-dashboard__card-h"><span class="dy-dashboard__card-title">'
				. esc_html__( 'Sales (7 days)', 'doubleyoupi-refined-admin-dashboard' ) . '</span></div>'
				. '<div class="dy-dashboard__card-body"><p class="dy-dashboard__empty">'
				. esc_html__( 'WooCommerce not active.', 'doubleyoupi-refined-admin-dashboard' ) . '</p></div>';
		}

		$series = is_array( $data['series'] ?? null ) ? array_map( 'floatval', $data['series'] ) : [];
		$total  = (float) ( $data['total'] ?? 0.0 );
		$count  = (int) ( $data['orders_count'] ?? 0 );
		$has_sales = array_sum( $series ) > 0;

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Sales (7 days)', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<span class="dy-dashboard__card-meta">7j</span>
		</div>
		<div class="dy-dashboard__card-body">
			<div class="dy-dashboard__row dy-dashboard__row--baseline">
				<span class="dy-dashboard__big-num dy-dashboard__big-num--money"><?php echo esc_html( self::money_compact( $total ) ); ?></span>
				<span class="dy-dashboard__row-k"><?php echo esc_html( sprintf( /* translators: %d: order count */ _n( '%d order', '%d orders', $count, 'doubleyoupi-refined-admin-dashboard' ), $count ) ); ?></span>
			</div>
			<div class="dy-dashboard__spark-wrap<?php echo $has_sales ? '' : ' is-empty'; ?>">
				<?php
				echo wp_kses( self::sparkline_svg( $series ), Kses::svg_tags() );
				?>
			</div>
		</div>
		<?php
		echo wp_kses( self::dy_foot_link( self::wc_admin_url( '&path=/analytics/orders' ), __( 'Analytics', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() );
		return (string) ob_get_clean();
	}
}

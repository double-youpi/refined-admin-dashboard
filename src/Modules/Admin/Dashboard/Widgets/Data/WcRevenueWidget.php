<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce "Sales (month)" widget.
 *
 * The king KPI: revenue collected in the current month, with a percentage
 * delta vs the same number of elapsed days in the previous month (for an
 * honest comparison early in the month). Sums the total of orders at paid
 * statuses (processing/completed + custom paid statuses).
 *
 * Cached 15 min via transient: summing orders can be expensive on a large
 * shop, and revenue does not need to be accurate to the second.
 */
final class WcRevenueWidget extends AbstractWcWidget {

	private const CACHE_KEY = 'doubleyoupi_dashboard_wc_revenue';
	private const CACHE_TTL = 900; // 15 minutes

	public function id(): string {
		return 'wc_revenue';
	}

	public function required_cap(): string {
		return 'manage_woocommerce';
	}

	public function name(): string {
		return __( 'Sales (month)', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Revenue collected this month vs last month (same days elapsed). Cached 15 min.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Upward trend line — revenue growth metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 17 9 11 13 15 21 7"/><polyline points="15 7 21 7 21 13"/></svg>';
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

		$tz  = wp_timezone();
		$now = new \DateTimeImmutable( 'now', $tz );

		$month_start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		$prev_start  = $month_start->modify( '-1 month' );
		// "Same day" comparison: identical portion of the previous month.
		$prev_cutoff = $prev_start->modify( '+' . ( (int) $now->format( 'j' ) ) . ' days -1 second' );

		$current = self::revenue_between( $month_start, $now );
		$prev    = self::revenue_between( $prev_start, $prev_cutoff );

		$data = [
			'available' => true,
			'current'   => $current,
			'previous'  => $prev,
			'delta'     => self::delta( $current, $prev ),
			'month'     => wp_date( 'F', $month_start->getTimestamp() ),
			'symbol'    => self::currency_symbol(),
		];

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	private static function revenue_between( \DateTimeInterface $from, \DateTimeInterface $to ): float {
		$orders = self::query_orders( [
			'status'       => self::paid_statuses(),
			'date_created' => $from->getTimestamp() . '...' . $to->getTimestamp(),
			'limit'        => -1,
			'return'       => 'objects',
		] );
		$sum = 0.0;
		if ( is_array( $orders ) ) {
			foreach ( $orders as $order ) {
				$sum += (float) $order->get_total();
			}
		}
		return $sum;
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		if ( empty( $data['available'] ) ) {
			return '<div class="dy-dashboard__card-h"><span class="dy-dashboard__card-title">'
				. esc_html__( 'Sales (month)', 'doubleyoupi-refined-admin-dashboard' ) . '</span></div>'
				. '<div class="dy-dashboard__card-body"><p class="dy-dashboard__empty">'
				. esc_html__( 'WooCommerce not active.', 'doubleyoupi-refined-admin-dashboard' ) . '</p></div>';
		}

		$current = (float) ( $data['current'] ?? 0.0 );
		$delta   = is_array( $data['delta'] ?? null ) ? $data['delta'] : [ 'dir' => 'flat', 'has_ref' => false, 'pct' => 0.0 ];
		$month   = (string) ( $data['month'] ?? '' );

		$dir = (string) ( $delta['dir'] ?? 'flat' );
		$arrow = $dir === 'up' ? '↑' : ( $dir === 'down' ? '↓' : '→' );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Sales (month)', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<span class="dy-dashboard__card-meta"><?php echo esc_html( $month ); ?></span>
		</div>
		<div class="dy-dashboard__card-body">
			<div class="dy-dashboard__row dy-dashboard__row--baseline">
				<span class="dy-dashboard__big-num dy-dashboard__big-num--money"><?php echo esc_html( self::money_compact( $current ) ); ?></span>
			</div>
			<?php if ( ! empty( $delta['has_ref'] ) ) : ?>
				<div class="dy-dashboard__delta dy-dashboard__delta--<?php echo esc_attr( $dir ); ?>">
					<span class="dy-dashboard__delta-arrow" aria-hidden="true"><?php echo esc_html( $arrow ); ?></span>
					<span class="dy-dashboard__delta-pct"><?php echo esc_html( number_format_i18n( (float) ( $delta['pct'] ?? 0 ), 1 ) ); ?>%</span>
					<span class="dy-dashboard__delta-ctx"><?php esc_html_e( 'vs last month', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
				</div>
			<?php else : ?>
				<div class="dy-dashboard__delta dy-dashboard__delta--flat">
					<span class="dy-dashboard__delta-ctx"><?php esc_html_e( 'first sales of the period', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
		echo wp_kses( self::dy_foot_link( self::wc_admin_url( '&path=/analytics/revenue' ), __( 'Analytics', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() );
		return (string) ob_get_clean();
	}
}

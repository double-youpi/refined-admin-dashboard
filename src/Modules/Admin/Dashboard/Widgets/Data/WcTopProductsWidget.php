<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce "Top products" widget.
 *
 * Best sellers in the current month (by units sold), with a proportion bar
 * relative to the top product — instant read of the ranking hierarchy.
 *
 * Aggregates order_items from paid orders this month. Cached 30 min
 * (the top list changes slowly).
 */
final class WcTopProductsWidget extends AbstractWcWidget {

	private const CACHE_KEY = 'doubleyoupi_dashboard_wc_topproducts';
	private const CACHE_TTL = 1800;
	private const LIMIT      = 5;

	public function id(): string {
		return 'wc_top_products';
	}

	public function required_cap(): string {
		return 'manage_woocommerce';
	}

	public function name(): string {
		return __( 'Top products', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Best sellers this month by units sold. Cached 30 min.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Stylised medal / trophy.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="9" r="6"/><path d="M9 14.5l-2 7 5-3 5 3-2-7"/><path d="M12 6v3l2 1"/></svg>';
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

		$tz          = wp_timezone();
		$now         = new \DateTimeImmutable( 'now', $tz );
		$month_start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		$orders = self::query_orders( [
			'status'       => self::paid_statuses(),
			'date_created' => '>=' . $month_start->getTimestamp(),
			'limit'        => -1,
			'return'       => 'objects',
		] );

		$tally = []; // product_id => [ name, qty ]
		if ( is_array( $orders ) ) {
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					$pid = (int) $item->get_product_id();
					if ( $pid <= 0 ) {
						continue;
					}
					if ( ! isset( $tally[ $pid ] ) ) {
						$tally[ $pid ] = [ 'name' => $item->get_name(), 'qty' => 0 ];
					}
					$tally[ $pid ]['qty'] += (int) $item->get_quantity();
				}
			}
		}

		uasort( $tally, static fn( $a, $b ) => $b['qty'] <=> $a['qty'] );
		$top = array_slice( $tally, 0, self::LIMIT, true );

		$items = [];
		$max   = 0;
		foreach ( $top as $pid => $row ) {
			$max = max( $max, (int) $row['qty'] );
		}
		foreach ( $top as $pid => $row ) {
			$items[] = [
				'name' => (string) $row['name'],
				'qty'  => (int) $row['qty'],
				'pct'  => $max > 0 ? (int) round( (int) $row['qty'] / $max * 100 ) : 0,
				'url'  => get_edit_post_link( $pid, 'raw' ),
			];
		}

		$data = [
			'available' => true,
			'items'     => $items,
			'month'     => wp_date( 'F', $month_start->getTimestamp() ),
		];

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		if ( empty( $data['available'] ) ) {
			return '<div class="dy-dashboard__card-h"><span class="dy-dashboard__card-title">'
				. esc_html__( 'Top products', 'doubleyoupi-refined-admin-dashboard' ) . '</span></div>'
				. '<div class="dy-dashboard__card-body"><p class="dy-dashboard__empty">'
				. esc_html__( 'WooCommerce not active.', 'doubleyoupi-refined-admin-dashboard' ) . '</p></div>';
		}

		$items = is_array( $data['items'] ?? null ) ? $data['items'] : [];
		$month = (string) ( $data['month'] ?? '' );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Top products', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<span class="dy-dashboard__card-meta"><?php echo esc_html( $month ); ?></span>
		</div>
		<div class="dy-dashboard__card-body">
			<?php if ( empty( $items ) ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No sales this month yet.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else : ?>
				<ol class="dy-dashboard__rank">
					<?php foreach ( $items as $idx => $it ) : ?>
						<li class="dy-dashboard__rank-item" style="--dy-rank-pct: <?php echo esc_attr( (string) max( 4, (int) $it['pct'] ) ); ?>%;">
							<span class="dy-dashboard__rank-pos"><?php echo esc_html( (string) ( $idx + 1 ) ); ?></span>
							<a class="dy-dashboard__rank-name" href="<?php echo esc_url( (string) $it['url'] ); ?>"><?php echo esc_html( (string) $it['name'] ); ?></a>
							<span class="dy-dashboard__rank-qty"><?php echo esc_html( sprintf( /* translators: %d: units sold */ _n( '%d sold', '%d sold', (int) $it['qty'], 'doubleyoupi-refined-admin-dashboard' ), (int) $it['qty'] ) ); ?></span>
							<span class="dy-dashboard__rank-bar" aria-hidden="true"></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
		echo wp_kses( self::dy_foot_link( self::wc_admin_url( '&path=/analytics/products' ), __( 'Analytics', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() );
		return (string) ob_get_clean();
	}
}

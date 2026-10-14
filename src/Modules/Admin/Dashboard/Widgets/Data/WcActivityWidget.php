<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce "Shop activity" widget.
 *
 * Stream of the latest orders: number, amount, colour-coded status badge,
 * and relative time. "What is happening right now" sales view, distinct
 * from the editorial activity (posts) of the generic Activity widget.
 */
final class WcActivityWidget extends AbstractWcWidget {

	public function id(): string {
		return 'wc_activity';
	}

	public function required_cap(): string {
		return 'manage_woocommerce';
	}

	public function name(): string {
		return __( 'Shop activity', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Latest orders: number, amount, status and time.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Receipt / cash-register ticket.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 2v20l2-1.5L8 22l2-1.5L12 22l2-1.5L16 22l2-1.5L20 22V2l-2 1.5L16 2l-2 1.5L12 2l-2 1.5L8 2 6 3.5 4 2z"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="14" y2="12"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );
		if ( ! self::wc_available() ) {
			return [ 'available' => false ];
		}

		$orders = self::query_orders( [
			'limit'   => 6,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		] );

		$items = [];
		if ( is_array( $orders ) ) {
			foreach ( $orders as $order ) {
				$created = $order->get_date_created();
				$items[] = [
					'id'      => $order->get_id(),
					'number'  => (string) $order->get_order_number(),
					'total'   => self::money( (float) $order->get_total() ),
					'status'  => (string) $order->get_status(),
					'status_label' => function_exists( 'wc_get_order_status_name' )
						? wc_get_order_status_name( $order->get_status() )
						: ucfirst( $order->get_status() ),
					'ts'      => $created ? (int) $created->getTimestamp() : 0,
					'url'     => $order->get_edit_order_url(),
				];
			}
		}

		return [ 'available' => true, 'items' => $items ];
	}

	/** Design system colour tone for a given WC order status. */
	private static function status_tone( string $status ): string {
		switch ( $status ) {
			case 'completed':  return 'ok';
			case 'processing': return 'accent';
			case 'on-hold':
			case 'pending':    return 'warn';
			case 'cancelled':
			case 'failed':
			case 'refunded':   return 'danger';
			default:           return '';
		}
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		if ( empty( $data['available'] ) ) {
			return '<div class="dy-dashboard__card-h"><span class="dy-dashboard__card-title">'
				. esc_html__( 'Shop activity', 'doubleyoupi-refined-admin-dashboard' ) . '</span></div>'
				. '<div class="dy-dashboard__card-body"><p class="dy-dashboard__empty">'
				. esc_html__( 'WooCommerce not active.', 'doubleyoupi-refined-admin-dashboard' ) . '</p></div>';
		}

		$items = is_array( $data['items'] ?? null ) ? $data['items'] : [];

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Shop activity', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
		</div>
		<div class="dy-dashboard__card-body">
			<?php if ( empty( $items ) ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No orders yet.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else : ?>
				<ul class="dy-dashboard__list dy-dashboard__list--orders">
					<?php foreach ( $items as $item ) : ?>
						<li class="dy-dashboard__list-item dy-dashboard__order-row">
							<span class="dy-dashboard__dot dy-dashboard__dot--<?php echo esc_attr( self::status_tone( (string) $item['status'] ) ?: 'mute' ); ?>" title="<?php echo esc_attr( (string) $item['status_label'] ); ?>"></span>
							<a class="dy-dashboard__order-num" href="<?php echo esc_url( (string) $item['url'] ); ?>">#<?php echo esc_html( (string) $item['number'] ); ?></a>
							<span class="dy-dashboard__order-total"><?php echo esc_html( (string) $item['total'] ); ?></span>
							<span class="dy-dashboard__order-time"><?php echo esc_html( self::human_time( (int) $item['ts'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		echo wp_kses( self::dy_foot_link( self::orders_url(), __( 'All orders', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() );
		return (string) ob_get_clean();
	}

	private static function human_time( int $ts ): string {
		if ( $ts <= 0 ) {
			return '—';
		}
		$diff = time() - $ts;
		if ( $diff < 0 ) {
			$diff = 0;
		}
		if ( $diff < HOUR_IN_SECONDS ) {
			$mins = max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) );
			/* translators: %d: minutes */
			return sprintf( __( '%d min', 'doubleyoupi-refined-admin-dashboard' ), $mins );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			$hrs = max( 1, (int) round( $diff / HOUR_IN_SECONDS ) );
			/* translators: %d: hours */
			return sprintf( __( '%d h', 'doubleyoupi-refined-admin-dashboard' ), $hrs );
		}
		$days = max( 1, (int) round( $diff / DAY_IN_SECONDS ) );
		/* translators: %d: days */
		return sprintf( __( '%d d', 'doubleyoupi-refined-admin-dashboard' ), $days );
	}
}

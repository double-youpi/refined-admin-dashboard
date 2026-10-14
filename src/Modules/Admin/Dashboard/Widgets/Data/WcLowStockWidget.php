<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce "Low stock" widget.
 *
 * Prevents lost sales: counts products with low stock (below the WooCommerce
 * threshold) and out-of-stock products, with a preview of the first affected
 * products and a link to the filtered list. Uses the native WC stock reports
 * (wc_get_low_stock_amount / data store) to respect per-product thresholds.
 *
 * Cached 15 min (stock queries can be expensive).
 */
final class WcLowStockWidget extends AbstractWcWidget {

	private const CACHE_KEY = 'doubleyoupi_dashboard_wc_lowstock';
	private const CACHE_TTL = 900;

	public function id(): string {
		return 'wc_low_stock';
	}

	public function required_cap(): string {
		return 'manage_woocommerce';
	}

	public function name(): string {
		return __( 'Low stock', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Products running low or out of stock, with a quick preview. Cached 15 min.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Stacked boxes with alert indicator.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8V5a1 1 0 0 0-.5-.87l-7-4a1 1 0 0 0-1 0l-7 4A1 1 0 0 0 5 5v3"/><path d="M3 13v6a1 1 0 0 0 .5.87l7 4a1 1 0 0 0 1 0"/><line x1="18" y1="13" x2="18" y2="17"/><line x1="18" y1="21" x2="18.01" y2="21"/></svg>';
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

		$low_threshold = function_exists( 'wc_get_low_stock_amount' ) ? null : 2;

		// Stock-managed products, sorted by quantity ascending.
		$low_ids = wc_get_products( [
			'status'        => 'publish',
			'manage_stock'  => true,
			'stock_status'  => 'instock',
			'limit'         => 50,
			'orderby'       => 'meta_value_num',
			'meta_key'      => '_stock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'         => 'ASC',
			'return'        => 'objects',
		] );

		$low_items = [];
		$low_count = 0;
		if ( is_array( $low_ids ) ) {
			foreach ( $low_ids as $product ) {
				$qty = $product->get_stock_quantity();
				if ( $qty === null ) {
					continue;
				}
				$threshold = function_exists( 'wc_get_low_stock_amount' )
					? (int) wc_get_low_stock_amount( $product )
					: (int) $low_threshold;
				if ( (int) $qty <= $threshold && (int) $qty > 0 ) {
					$low_count++;
					if ( count( $low_items ) < 4 ) {
						$low_items[] = [
							'name' => $product->get_name(),
							'qty'  => (int) $qty,
							'url'  => get_edit_post_link( $product->get_id(), 'raw' ),
						];
					}
				}
			}
		}

		// Out-of-stock products (stock_status outofstock).
		$out_ids = wc_get_products( [
			'status'       => 'publish',
			'stock_status' => 'outofstock',
			'limit'        => -1,
			'return'       => 'ids',
		] );
		$out_count = is_array( $out_ids ) ? count( $out_ids ) : 0;

		$data = [
			'available' => true,
			'low_count' => $low_count,
			'out_count' => $out_count,
			'low_items' => $low_items,
		];

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		if ( empty( $data['available'] ) ) {
			return '<div class="dy-dashboard__card-h"><span class="dy-dashboard__card-title">'
				. esc_html__( 'Low stock', 'doubleyoupi-refined-admin-dashboard' ) . '</span></div>'
				. '<div class="dy-dashboard__card-body"><p class="dy-dashboard__empty">'
				. esc_html__( 'WooCommerce not active.', 'doubleyoupi-refined-admin-dashboard' ) . '</p></div>';
		}

		$low   = (int) ( $data['low_count'] ?? 0 );
		$out   = (int) ( $data['out_count'] ?? 0 );
		$items = is_array( $data['low_items'] ?? null ) ? $data['low_items'] : [];
		$all_ok = ( $low + $out ) === 0;

		$products_url = admin_url( 'edit.php?post_type=product' );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Low stock', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php if ( $out > 0 ) : ?>
				<span class="dy-dashboard__pill dy-dashboard__pill--danger"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d out', '%d out', $out, 'doubleyoupi-refined-admin-dashboard' ), $out ) ); ?></span>
			<?php elseif ( $low > 0 ) : ?>
				<span class="dy-dashboard__pill dy-dashboard__pill--warn"><?php esc_html_e( 'watch', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php else : ?>
				<span class="dy-dashboard__pill dy-dashboard__pill--ok"><?php esc_html_e( 'healthy', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="dy-dashboard__card-body">
			<?php if ( $all_ok ) : ?>
				<div class="dy-dashboard__row dy-dashboard__row--baseline">
					<span class="dy-dashboard__big-num dy-dashboard__big-num--ok">0</span>
					<span class="dy-dashboard__row-k"><?php esc_html_e( 'stock alerts', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
				</div>
				<p class="dy-dashboard__empty dy-dashboard__empty--soft"><?php esc_html_e( 'All products well stocked.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else : ?>
				<div class="dy-dashboard__wc-breakdown dy-dashboard__wc-breakdown--top">
					<span class="dy-dashboard__wc-seg">
						<span class="dy-dashboard__dot dy-dashboard__dot--warn"></span>
						<span class="dy-dashboard__wc-seg-n"><?php echo esc_html( (string) $low ); ?></span>
						<span class="dy-dashboard__wc-seg-l"><?php esc_html_e( 'low', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
					</span>
					<span class="dy-dashboard__wc-seg">
						<span class="dy-dashboard__dot dy-dashboard__dot--danger"></span>
						<span class="dy-dashboard__wc-seg-n"><?php echo esc_html( (string) $out ); ?></span>
						<span class="dy-dashboard__wc-seg-l"><?php esc_html_e( 'out', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
					</span>
				</div>
				<?php if ( ! empty( $items ) ) : ?>
					<ul class="dy-dashboard__list dy-dashboard__list--compact">
						<?php foreach ( $items as $it ) : ?>
							<li class="dy-dashboard__list-item">
								<a class="dy-dashboard__list-label" href="<?php echo esc_url( (string) $it['url'] ); ?>"><?php echo esc_html( (string) $it['name'] ); ?></a>
								<span class="dy-dashboard__stock-qty"><?php echo esc_html( sprintf( /* translators: %d: remaining units */ _n( '%d left', '%d left', (int) $it['qty'], 'doubleyoupi-refined-admin-dashboard' ), (int) $it['qty'] ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		echo wp_kses( self::dy_foot_link( $products_url, __( 'Manage products', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() );
		return (string) ob_get_clean();
	}
}

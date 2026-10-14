<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce "Orders to process" widget.
 *
 * The #1 action for a shop manager in the morning: how many orders need
 * attention. Counts "processing" (to ship) and "on-hold" (awaiting payment
 * or validation) statuses, with a per-status breakdown and direct links to
 * the filtered order list.
 */
final class WcOrdersWidget extends AbstractWcWidget {

	public function id(): string {
		return 'wc_orders';
	}

	public function required_cap(): string {
		return 'manage_woocommerce';
	}

	public function name(): string {
		return __( 'Orders to process', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Orders awaiting action: processing (to ship) and on-hold.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Parcel / box — shipping metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );
		if ( ! self::wc_available() ) {
			return [ 'available' => false ];
		}

		$processing = self::count_status( 'processing' );
		$on_hold    = self::count_status( 'on-hold' );
		$pending    = self::count_status( 'pending' );

		return [
			'available'  => true,
			'processing' => $processing,
			'on_hold'    => $on_hold,
			'pending'    => $pending,
			'total'      => $processing + $on_hold + $pending,
		];
	}

	private static function count_status( string $status ): int {
		$ids = self::query_orders( [
			'status' => $status,
			'limit'  => -1,
			'return' => 'ids',
		] );
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		if ( empty( $data['available'] ) ) {
			return '<div class="dy-dashboard__card-h"><span class="dy-dashboard__card-title">'
				. esc_html__( 'Orders to process', 'doubleyoupi-refined-admin-dashboard' ) . '</span></div>'
				. '<div class="dy-dashboard__card-body"><p class="dy-dashboard__empty">'
				. esc_html__( 'WooCommerce not active.', 'doubleyoupi-refined-admin-dashboard' ) . '</p></div>';
		}

		$processing = (int) ( $data['processing'] ?? 0 );
		$on_hold    = (int) ( $data['on_hold'] ?? 0 );
		$pending    = (int) ( $data['pending'] ?? 0 );
		$total      = (int) ( $data['total'] ?? 0 );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Orders to process', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php if ( $total > 0 ) : ?>
				<span class="dy-dashboard__pill dy-dashboard__pill--accent dy-dashboard__pill--pulse"><?php esc_html_e( 'to do', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php else : ?>
				<span class="dy-dashboard__pill dy-dashboard__pill--ok"><?php esc_html_e( 'clear', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="dy-dashboard__card-body">
			<div class="dy-dashboard__row dy-dashboard__row--baseline">
				<span class="dy-dashboard__big-num <?php echo $total > 0 ? 'dy-dashboard__big-num--accent' : ''; ?>"><?php echo esc_html( (string) $total ); ?></span>
				<span class="dy-dashboard__row-k"><?php echo esc_html( _n( 'order waiting', 'orders waiting', $total, 'doubleyoupi-refined-admin-dashboard' ) ); ?></span>
			</div>
			<?php if ( $total > 0 ) : ?>
				<div class="dy-dashboard__wc-breakdown">
					<?php if ( $processing > 0 ) : ?>
						<a class="dy-dashboard__wc-seg" href="<?php echo esc_url( self::orders_url( 'processing' ) ); ?>">
							<span class="dy-dashboard__dot dy-dashboard__dot--accent"></span>
							<span class="dy-dashboard__wc-seg-n"><?php echo esc_html( (string) $processing ); ?></span>
							<span class="dy-dashboard__wc-seg-l"><?php esc_html_e( 'to ship', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
						</a>
					<?php endif; ?>
					<?php if ( $on_hold > 0 ) : ?>
						<a class="dy-dashboard__wc-seg" href="<?php echo esc_url( self::orders_url( 'on-hold' ) ); ?>">
							<span class="dy-dashboard__dot dy-dashboard__dot--warn"></span>
							<span class="dy-dashboard__wc-seg-n"><?php echo esc_html( (string) $on_hold ); ?></span>
							<span class="dy-dashboard__wc-seg-l"><?php esc_html_e( 'on hold', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
						</a>
					<?php endif; ?>
					<?php if ( $pending > 0 ) : ?>
						<a class="dy-dashboard__wc-seg" href="<?php echo esc_url( self::orders_url( 'pending' ) ); ?>">
							<span class="dy-dashboard__dot"></span>
							<span class="dy-dashboard__wc-seg-n"><?php echo esc_html( (string) $pending ); ?></span>
							<span class="dy-dashboard__wc-seg-l"><?php esc_html_e( 'pending pay', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
						</a>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<p class="dy-dashboard__empty dy-dashboard__empty--soft"><?php esc_html_e( 'Nothing waiting. All caught up.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		echo wp_kses( self::dy_foot_link( self::orders_url(), __( 'All orders', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() );
		return (string) ob_get_clean();
	}
}

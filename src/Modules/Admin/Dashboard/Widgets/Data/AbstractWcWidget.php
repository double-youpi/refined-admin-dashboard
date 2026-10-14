<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for WooCommerce dashboard widgets (Dashboard v4).
 *
 * "Detect and step aside" manifesto: these widgets are only registered when
 * WooCommerce is active (see WidgetRegistry::bootstrap). This base provides:
 *   - WC availability detection,
 *   - amount formatting (symbol + shop decimals, no HTML),
 *   - percentage delta calculation between two periods,
 *   - an SVG sparkline generator (smooth area curve, anti-CLS),
 *   - a reusable WC admin URL helper.
 *
 * "Paid" order statuses: relies on wc_get_is_paid_statuses()
 * (processing, completed, and any custom status marked as paid), so that
 * revenue figures reflect money actually collected.
 */
abstract class AbstractWcWidget extends AbstractDataWidget {

	public function category(): string {
		return 'data';
	}

	/** Is WooCommerce available and loaded? */
	protected static function wc_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' );
	}

	/**
	 * wc_get_orders() restricted to real orders. Under HPOS the default
	 * `type` is `shop_order` AND `shop_order_refund`: refund objects have no
	 * order number (fatal on get_order_number()), no items to count as sales,
	 * and negative totals. Every widget query goes through here so that
	 * "orders" always means orders.
	 *
	 * @param array<string,mixed> $args wc_get_orders() arguments.
	 * @return array<int,mixed> Orders (objects or ids depending on `return`).
	 */
	protected static function query_orders( array $args ): array {
		$args['type'] = 'shop_order';
		$result       = wc_get_orders( $args );
		if ( ! is_array( $result ) ) {
			return [];
		}
		if ( 'ids' === ( $args['return'] ?? 'objects' ) ) {
			return $result;
		}
		return array_values( array_filter( $result, static fn( $order ): bool => $order instanceof \WC_Order ) );
	}

	/**
	 * Statuses considered "paid" (collected) for revenue calculations.
	 * wc_get_is_paid_statuses returns keys WITHOUT the "wc-" prefix
	 * (e.g. processing, completed); wc_get_orders accepts both forms,
	 * so we normalise by prepending the prefix when missing (not via
	 * ltrim on characters, which would eat the "c" of "completed").
	 *
	 * @return array<int,string>
	 */
	protected static function paid_statuses(): array {
		$raw = function_exists( 'wc_get_is_paid_statuses' )
			? wc_get_is_paid_statuses()
			: [ 'processing', 'completed' ];
		return array_map(
			static function ( $s ) {
				$s = (string) $s;
				return str_starts_with( $s, 'wc-' ) ? $s : 'wc-' . $s;
			},
			$raw
		);
	}

	/**
	 * Amount formatted according to shop settings (symbol, decimals,
	 * separators) but WITHOUT HTML markup — plain text is returned,
	 * styling comes from the design system. E.g. 1 234,50 €.
	 */
	protected static function money( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			// wc_price returns HTML (<span class=...>) — decode + strip.
			$html = wc_price( $amount, [ 'decimals' => self::price_decimals() ] );
			$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
			return trim( $text );
		}
		return number_format_i18n( $amount, 2 );
	}

	/**
	 * "Compact" amount for large figures (monthly revenue): no decimals
	 * when the amount is integer-ish, currency symbol preserved.
	 * Keeps the big-number readable without cluttering it.
	 */
	protected static function money_compact( float $amount ): string {
		$decimals = ( fmod( $amount, 1.0 ) === 0.0 ) ? 0 : self::price_decimals();
		if ( function_exists( 'wc_price' ) ) {
			$html = wc_price( $amount, [ 'decimals' => $decimals ] );
			return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) );
		}
		return number_format_i18n( $amount, $decimals );
	}

	protected static function price_decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
	}

	/** Current shop currency symbol (plain text). */
	protected static function currency_symbol(): string {
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		}
		return '';
	}

	/**
	 * Percentage delta between a current value and a reference value.
	 * Returns a display-ready array:
	 *   [ 'pct' => 12.4, 'dir' => 'up'|'down'|'flat', 'has_ref' => bool ].
	 * When the reference is 0, a reliable percentage cannot be computed →
	 * has_ref false (the UI will show "new" rather than +∞%).
	 */
	protected static function delta( float $current, float $previous ): array {
		if ( $previous <= 0.0 ) {
			return [
				'pct'     => 0.0,
				'dir'     => $current > 0 ? 'up' : 'flat',
				'has_ref' => false,
			];
		}
		$pct = ( $current - $previous ) / $previous * 100.0;
		$dir = $pct > 0.5 ? 'up' : ( $pct < -0.5 ? 'down' : 'flat' );
		return [
			'pct'     => round( abs( $pct ), 1 ),
			'dir'     => $dir,
			'has_ref' => true,
		];
	}

	/**
	 * Generates an SVG sparkline (area + line) from a series of values.
	 * viewBox 0 0 100 32, preserveAspectRatio="none" → stretches horizontally
	 * without distorting perception (fixed height). Anti-CLS: fixed intrinsic
	 * dimensions. Colour follows currentColor (the card's accent colour).
	 *
	 * The last point carries a small marker (circle) to anchor the eye on
	 * "today". CSS classes allow draw animation.
	 *
	 * @param array<int,float> $values
	 */
	protected static function sparkline_svg( array $values ): string {
		$n = count( $values );
		if ( $n < 2 ) {
			// Not enough points → flat line at centre (avoids an empty SVG).
			$values = [ 0.0, 0.0 ];
			$n      = 2;
		}
		$max = max( $values );
		$min = min( $values );
		$span = ( $max - $min ) > 0 ? ( $max - $min ) : 1.0;

		$w = 100.0;
		$h = 32.0;
		$pad = 3.0; // vertical padding so the stroke does not touch the edges

		$pts = [];
		foreach ( array_values( $values ) as $i => $v ) {
			$x = $n > 1 ? ( $i / ( $n - 1 ) ) * $w : 0.0;
			$norm = ( (float) $v - $min ) / $span;       // 0..1
			$y = $h - $pad - $norm * ( $h - 2 * $pad );    // inverted (low y = high value)
			$pts[] = [ round( $x, 2 ), round( $y, 2 ) ];
		}

		// Line (polyline) + closed area (polygon down to baseline).
		$line_d = '';
		foreach ( $pts as $i => $p ) {
			$line_d .= ( $i === 0 ? 'M' : 'L' ) . $p[0] . ' ' . $p[1] . ' ';
		}
		$area_d = $line_d . 'L' . $pts[ $n - 1 ][0] . ' ' . $h . ' L' . $pts[0][0] . ' ' . $h . ' Z';

		$last = $pts[ $n - 1 ];
		$uid  = 'dy-spark-' . substr( md5( implode( ',', array_map( 'strval', $values ) ) ), 0, 6 );

		ob_start();
		?>
		<svg class="dy-dashboard__spark" viewBox="0 0 100 32" preserveAspectRatio="none" width="100%" height="40" aria-hidden="true" focusable="false">
			<defs>
				<linearGradient id="<?php echo esc_attr( $uid ); ?>" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0%" stop-color="currentColor" stop-opacity="0.28"/>
					<stop offset="100%" stop-color="currentColor" stop-opacity="0"/>
				</linearGradient>
			</defs>
			<path class="dy-dashboard__spark-area" d="<?php echo esc_attr( trim( $area_d ) ); ?>" fill="url(#<?php echo esc_attr( $uid ); ?>)"/>
			<path class="dy-dashboard__spark-line" d="<?php echo esc_attr( trim( $line_d ) ); ?>" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
			<circle class="dy-dashboard__spark-dot" cx="<?php echo esc_attr( (string) $last[0] ); ?>" cy="<?php echo esc_attr( (string) $last[1] ); ?>" r="2.1" fill="currentColor"/>
		</svg>
		<?php
		return (string) ob_get_clean();
	}

	/** URL to a WooCommerce admin page (analytics/orders/products). */
	protected static function wc_admin_url( string $path = '' ): string {
		return admin_url( 'admin.php?page=wc-admin' . ( $path !== '' ? $path : '' ) );
	}

	/** URL to the orders list filtered by status (HPOS-aware). */
	protected static function orders_url( string $status = '' ): string {
		// HPOS: page=wc-orders; legacy: edit.php?post_type=shop_order.
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			$url = admin_url( 'admin.php?page=wc-orders' );
			return $status !== '' ? add_query_arg( 'status', $status, $url ) : $url;
		}
		$url = admin_url( 'edit.php?post_type=shop_order' );
		return $status !== '' ? add_query_arg( 'post_status', 'wc-' . $status, $url ) : $url;
	}
}

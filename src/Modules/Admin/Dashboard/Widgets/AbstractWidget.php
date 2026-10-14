<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets;

use DoubleYoupi\Core\Build;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for all v4 widgets. Sensible defaults: not instanceable,
 * 2 recommended slots, empty config.
 */
abstract class AbstractWidget implements WidgetInterface {

	public function description(): string {
		return '';
	}

	/**
	 * Label displayed in the PALETTE of the Customize panel (tiles to place).
	 * Defaults to name(). Overridden when a more descriptive name helps
	 * distinguish between two similar widgets (e.g. "Create — all types" vs
	 * "Create — one type"), without changing the header rendered on the
	 * dashboard (name()).
	 */
	public function palette_name(): string {
		return $this->name();
	}

	/**
	 * Minimum capability required to DISPLAY this widget. Empty = no requirement
	 * (any user with dashboard access). Used to hide widgets that expose
	 * information beyond a role's scope (e.g. disk space, modules, technical
	 * health → manage_options; shop reports → manage_woocommerce).
	 *
	 * Filter applied at 3 levels: palette (cannot place an inaccessible widget),
	 * validation (removed from a saved layout or applied preset), and therefore
	 * rendering (the layout is always validated before rendering).
	 */
	public function required_cap(): string {
		return '';
	}

	/**
	 * Helper: can the current user see this widget? True if no capability is
	 * required, otherwise current_user_can(cap). Globally filterable.
	 */
	final public function is_visible_to_current_user(): bool {
		$cap = $this->required_cap();
		$visible = ( $cap === '' ) || current_user_can( $cap );
		/** @var bool $visible */
		return (bool) apply_filters( 'doubleyoupi_dashboard_widget_visible', $visible, $this->id(), $cap );
	}

	public function is_instanceable(): bool {
		return false;
	}

	public function recommended_slots(): int {
		return 2;
	}

	public function default_config(): array {
		return [];
	}

	public function config_schema(): array {
		return [];
	}

	public function validate_config( array $config ): array {
		return $config;
	}

	public function get_data( array $config ): array {
		unset( $config );
		return [];
	}

	/**
	 * Inline SVG markup of the Double Youpi logo (short icon, without the wordmark).
	 * Can be used from any widget to sign a title — the colour badge aligns via
	 * `fill="currentColor"` (follows the adjacent text colour).
	 *
	 * Read once from disk then memoised in static. If the file is absent (extreme
	 * case: corrupted installation), returns an empty string so the title still
	 * renders without the logo.
	 */
	public static function dy_brand_logo_svg(): string {
		static $svg = null;
		if ( $svg !== null ) {
			return $svg;
		}
		$path = Build::dir( __FILE__, 'assets/img/icon-doubleyoupi.svg' );
		if ( ! is_readable( $path ) ) {
			$svg = '';
			return $svg;
		}
		$raw = (string) file_get_contents( $path );
		// 1) The source file has a hard-coded fill="#fff" — switch it to
		//    currentColor so the logo inherits the adjacent text colour.
		// 2) Add our class and focusable="false" (IE/older Edge versions would
		//    otherwise include the icon in the tab order).
		$raw = preg_replace(
			'/<svg\b/',
			'<svg class="dy-dashboard__brand-logo" aria-hidden="true" focusable="false"',
			$raw,
			1
		);
		$raw = str_replace(
			[ 'fill="#fff"', 'fill="#ffffff"', 'fill="white"' ],
			'fill="currentColor"',
			(string) $raw
		);
		$svg = (string) $raw;
		return $svg;
	}

	/**
	 * Markup for a "foot" link (bottom of a card, e.g. Settings / Configure).
	 * Includes the double-arrow wrapper for the SCSS animation
	 * (one arrow exits to the right while a second enters from the left, on loop).
	 *
	 * Everything is escaped internally: $url via esc_url(), $label via esc_html().
	 */
	protected static function dy_foot_link( string $url, string $label ): string {
		$arrow_svg = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
		return sprintf(
			'<a class="dy-dashboard__card-foot-link" href="%1$s">'
				. '<span class="dy-dashboard__card-foot-link-text">%2$s</span>'
				. '<span class="dy-dashboard__card-foot-link-arrow" aria-hidden="true">%3$s%3$s</span>'
				. '</a>',
			esc_url( $url ),
			esc_html( $label ),
			$arrow_svg
		);
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Content;

use DoubleYoupi\Modules\Admin\Dashboard\Tokens\TokenResolver;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractContentWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Heading widget: a configurable title, frameless.
 *
 * Layout primitive — paired with Paragraph to structure the Dashboard like
 * an editorial document. Supports tokens such as {{user.firstname}},
 * {{site.name}}, etc.
 *
 * Supported levels: h2, h3, h4 (not h1 — reserved for the admin shell).
 */
final class HeadingWidget extends AbstractContentWidget {

	private const ALLOWED_LEVELS    = [ 'h2', 'h3', 'h4' ];
	private const ALLOWED_ALIGNS    = [ 'left', 'center', 'right' ];
	private const ALLOWED_EM_COLORS = [ 'accent', 'green', 'orange', 'none' ];

	public function id(): string {
		return 'heading';
	}

	public function name(): string {
		return __( 'Heading', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'A configurable title, frameless, to structure the Dashboard.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Minimalist H-bar: two verticals joined by a horizontal.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4v16M18 4v16M6 12h12"/></svg>';
	}

	public function default_config(): array {
		return [
			'text'     => '{{date.greeting}} {{user.firstname}}',
			'level'    => 'h2',
			'align'    => 'left',
			'em_color' => 'accent',
		];
	}

	public function validate_config( array $config ): array {
		$defaults = $this->default_config();
		$text     = isset( $config['text'] ) ? (string) $config['text'] : $defaults['text'];

		// Allow <em> and <strong> in the text (for the coloured italic that is
		// the visual signature of the Heading). Everything else is stripped.
		$text = wp_kses(
			$text,
			[
				'em'     => [],
				'strong' => [],
			]
		);

		$level    = in_array( $config['level'] ?? '', self::ALLOWED_LEVELS, true ) ? $config['level'] : $defaults['level'];
		$align    = in_array( $config['align'] ?? '', self::ALLOWED_ALIGNS, true ) ? $config['align'] : $defaults['align'];
		$em_color = in_array( $config['em_color'] ?? '', self::ALLOWED_EM_COLORS, true ) ? $config['em_color'] : $defaults['em_color'];

		return [
			'text'     => $text,
			'level'    => $level,
			'align'    => $align,
			'em_color' => $em_color,
		];
	}

	public function get_data( array $config ): array {
		$text = isset( $config['text'] ) ? (string) $config['text'] : '';
		return [
			'rendered_text' => TokenResolver::resolve( $text ),
		];
	}

	public function render_html( array $config, array $data ): string {
		$level    = $config['level'] ?? 'h2';
		$align    = $config['align'] ?? 'left';
		$em_color = $config['em_color'] ?? 'accent';
		$rendered = isset( $data['rendered_text'] ) ? (string) $data['rendered_text'] : (string) ( $config['text'] ?? '' );

		// rendered_text may contain permitted <em>/<strong>. Escape everything else
		// but preserve those two tags via wp_kses (sufficient — they pass WP's
		// default allowlist).
		$safe_html = wp_kses(
			$rendered,
			[
				'em'     => [],
				'strong' => [],
			]
		);

		return sprintf(
			'<%1$s class="dy-dashboard__heading dy-dashboard__heading--%2$s dy-dashboard__heading--em-%3$s">%4$s</%1$s>',
			esc_attr( $level ),
			esc_attr( $align ),
			esc_attr( $em_color ),
			$safe_html
		);
	}
}

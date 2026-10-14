<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Content;

use DoubleYoupi\Modules\Admin\Dashboard\Tokens\TokenResolver;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractContentWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paragraph widget: a configurable text block, frameless.
 *
 * Complement to Heading for layout. Supports the same tokens.
 * Light Markdown: **bold**, *italic*, [link](url), `code` inline.
 * No lists, no blocks — this is a paragraph, not a rich editor.
 *
 * Supported styles: 'body' (default), 'lede' (slightly larger, intro),
 * 'muted' (secondary greyed-out text), 'italic' (signature italic).
 */
final class ParagraphWidget extends AbstractContentWidget {

	private const ALLOWED_STYLES = [ 'body', 'lede', 'muted', 'italic' ];
	private const ALLOWED_ALIGNS = [ 'left', 'center', 'right' ];

	public function id(): string {
		return 'paragraph';
	}

	public function name(): string {
		return __( 'Paragraph', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'A configurable paragraph (light Markdown), frameless, to explain and contextualize.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Three text lines (the third shorter) — "paragraph" affordance.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="11" x2="20" y2="11"/><line x1="4" y1="16" x2="14" y2="16"/></svg>';
	}

	public function default_config(): array {
		return [
			'text'  => 'Tu gères **{{site.name}}**. {{updates.summary}}',
			'style' => 'body',
			'align' => 'left',
		];
	}

	public function validate_config( array $config ): array {
		$defaults = $this->default_config();
		$text     = isset( $config['text'] ) ? (string) $config['text'] : $defaults['text'];

		// Store the raw text (Markdown). No wp_kses here — Markdown uses `**` and `*`
		// which are not HTML. Conversion to HTML happens in render_html with strict
		// escaping.
		$style = in_array( $config['style'] ?? '', self::ALLOWED_STYLES, true ) ? $config['style'] : $defaults['style'];
		$align = in_array( $config['align'] ?? '', self::ALLOWED_ALIGNS, true ) ? $config['align'] : $defaults['align'];

		return [
			'text'  => $text,
			'style' => $style,
			'align' => $align,
		];
	}

	public function get_data( array $config ): array {
		$text = isset( $config['text'] ) ? (string) $config['text'] : '';
		return [
			'rendered_text' => TokenResolver::resolve( $text ),
		];
	}

	public function render_html( array $config, array $data ): string {
		$style    = $config['style'] ?? 'body';
		$align    = $config['align'] ?? 'left';
		$rendered = isset( $data['rendered_text'] ) ? (string) $data['rendered_text'] : (string) ( $config['text'] ?? '' );

		$html = self::markdown_to_html( $rendered );

		return sprintf(
			'<p class="dy-dashboard__paragraph dy-dashboard__paragraph--%1$s dy-dashboard__paragraph--%2$s">%3$s</p>',
			esc_attr( $style ),
			esc_attr( $align ),
			$html
		);
	}

	/**
	 * Light Markdown → HTML conversion. Escapes all HTML first, then applies
	 * Markdown transforms on the escaped text.
	 *
	 * Defensive pattern: arbitrary HTML is NOT allowed in the Markdown input.
	 * Anything that looks like HTML is escaped. Markdown then re-injects only
	 * the safe tags `<strong>`, `<em>`, `<a>`, `<code>`.
	 */
	private static function markdown_to_html( string $text ): string {
		$out = esc_html( $text );

		// **bold** — non-greedy
		$out = preg_replace( '/\*\*([^*]+?)\*\*/', '<strong>$1</strong>', $out ) ?? $out;

		// *italic* — match only when not inside **
		$out = preg_replace( '/(?<!\*)\*([^*\n]+?)\*(?!\*)/', '<em>$1</em>', $out ) ?? $out;

		// [label](url) — URL validated to prevent `javascript:` injection.
		$out = preg_replace_callback(
			'/\[([^\]\n]+)\]\(([^)\n]+)\)/',
			static function ( array $m ) {
				$url = esc_url( $m[2] );
				if ( $url === '' ) {
					return esc_html( $m[1] );
				}
				return '<a href="' . $url . '">' . $m[1] . '</a>';
			},
			$out
		) ?? $out;

		// `code` inline.
		$out = preg_replace( '/`([^`\n]+?)`/', '<code>$1</code>', $out ) ?? $out;

		return $out;
	}
}

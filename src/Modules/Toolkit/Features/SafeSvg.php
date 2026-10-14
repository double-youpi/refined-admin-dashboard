<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allows SVG uploads with sanitization at upload time.
 *
 * Replaces Safe SVG / SVG Support. Sanitizer built on DOMDocument rather than
 * bundling enshrined/svg-sanitize: sufficient for the toolkit scope (simple
 * toggle), with zero added Composer dependencies.
 *
 * Sanitization strategy:
 *   - Strip all dangerous elements: script, foreignObject, iframe,
 *     embed, object, link, meta, style (external style), handler,
 *     <set>, <animate*> (can carry onload equivalents)
 *   - Strip all `on*` attributes (onclick, onmouseover, etc.)
 *   - Strip `href`/`xlink:href` attributes that start with `javascript:`,
 *     `data:` (except `data:image/*`), or are not clean absolute/relative URLs
 *   - Strip processing instructions and DOCTYPE to prevent XXE
 *
 * Known limitation: does not cover 100% of hostile SVG vectors as
 * exhaustively as a dedicated library. This is a utility toggle, not a
 * "zero-trust" security layer. Documented as such in the description.
 */
final class SafeSvg extends AbstractFeature {

	public function id(): string {
		return 'safe_svg';
	}

	public function label(): string {
		return __( 'SVG upload (sanitized)', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function checkbox_label(): string {
		return __( 'Allow uploading SVG files, with sanitization', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Enables SVG upload in the media library and passes every file through a DOMDocument sanitizer (strips <script>, on* attributes, href javascript:). Replaces Safe SVG.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Shield — SVG sanitizer security.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'
			. '<path d="M9 12l2 2 4-4"/>'
			. '</svg>';
	}

	public function conflict_plugin_candidates(): array {
		return [
			[ 'file' => 'safe-svg/safe-svg.php',     'name' => 'Safe SVG' ],
			[ 'file' => 'svg-support/svg-support.php', 'name' => 'SVG Support' ],
		];
	}

	protected function apply(): void {
		add_filter( 'upload_mimes',                [ self::class, 'allow_svg_mime' ] );
		add_filter( 'wp_check_filetype_and_ext',   [ self::class, 'fix_svg_mime_check' ], 10, 4 );
		add_filter( 'wp_handle_upload_prefilter',  [ self::class, 'sanitize_on_upload' ] );
	}

	/**
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public static function allow_svg_mime( array $mimes ): array {
		// Restriction: only users with upload_files can upload.
		// WP already applies this capability on upload_mimes — no need to re-check here.
		// .svg only: a compressed .svgz could not go through the sanitizer.
		$mimes['svg'] = 'image/svg+xml';
		return $mimes;
	}

	/**
	 * Workaround for a WP bug: `wp_check_filetype_and_ext` returns false for SVG
	 * because the `finfo` lib reports `text/plain` or `application/xml` depending on the OS.
	 * We force the correct ext/type pair when the extension is .svg.
	 *
	 * @param array{ext:string|false, type:string|false, proper_filename:string|false} $data
	 * @return array{ext:string|false, type:string|false, proper_filename:string|false}
	 */
	public static function fix_svg_mime_check( array $data, string $file, string $filename, $mimes ): array {
		unset( $file, $mimes );
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( $ext === 'svg' ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	}

	/**
	 * Upload prefilter hook: sanitizes the SVG content before the file is moved
	 * into wp-content/uploads. If sanitization fails (malformed XML or non-SVG
	 * content), the upload is rejected with a clear error message.
	 *
	 * @param array{name:string, type:string, tmp_name:string, error:int, size:int} $file
	 * @return array{name:string, type:string, tmp_name:string, error:int, size:int}
	 */
	public static function sanitize_on_upload( array $file ): array {
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( $ext !== 'svg' ) {
			return $file;
		}
		if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			$file['error'] = __( 'SVG: temporary file unreadable.', 'doubleyoupi-refined-admin-dashboard' );
			return $file;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = (string) file_get_contents( $file['tmp_name'] );
		$clean = self::sanitize( $raw );

		if ( $clean === null ) {
			$file['error'] = __( 'SVG: file rejected (non-SVG content or malformed).', 'doubleyoupi-refined-admin-dashboard' );
			return $file;
		}

		// Rewrite the sanitized temporary file. file_put_contents on tmp_name is safe here:
		// PHP has already allocated the file in the temp area via $_FILES, we just replace its content.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents
		if ( file_put_contents( $file['tmp_name'], $clean ) === false ) {
			$file['error'] = __( 'SVG: unable to write the sanitized file.', 'doubleyoupi-refined-admin-dashboard' );
		}

		return $file;
	}

	/**
	 * Sanitizes an SVG string. Returns null if the content is not valid SVG
	 * or if a parsing error occurs.
	 *
	 * Public for direct testability.
	 */
	public static function sanitize( string $svg ): ?string {
		$svg = trim( $svg );
		if ( $svg === '' ) {
			return null;
		}

		// Strip BOM, processing instructions, and DOCTYPE to prevent XXE.
		// The DOCTYPE can include nested entities via `[<!ENTITY …>]` — the
		// regex must absorb this block before the final `>`, otherwise some pass through.
		$svg = preg_replace( '/^\xEF\xBB\xBF/', '', $svg ) ?? $svg;
		$svg = preg_replace( '/<\?xml[^>]*\?>/i', '', $svg ) ?? $svg;
		$svg = preg_replace( '/<!DOCTYPE[^\[>]*(\[[^\]]*\])?\s*>/is', '', $svg ) ?? $svg;

		// PHP 8.1+: libxml_disable_entity_loader() is deprecated and a no-op (external
		// entities are disabled by default since 8.0). LIBXML_NONET is sufficient
		// to block any network resolution.
		$prev_use = libxml_use_internal_errors( true );

		$dom = new \DOMDocument( '1.0', 'UTF-8' );
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput       = false;

		$loaded = $dom->loadXML(
			$svg,
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $prev_use );

		if ( ! $loaded || ! $dom->documentElement ) {
			return null;
		}
		if ( strtolower( $dom->documentElement->nodeName ) !== 'svg' ) {
			return null;
		}

		self::clean_node( $dom->documentElement );

		$out = $dom->saveXML( $dom->documentElement );
		return is_string( $out ) ? $out : null;
	}

	/**
	 * Recursive cleanup: sanitizes attributes on the current node, removes
	 * dangerous children, recurses into remaining children.
	 */
	private static function clean_node( \DOMElement $node ): void {
		// 1. Clean own attributes (the root <svg> element benefits from this too).
		self::clean_attributes( $node );

		static $forbidden_elements = [
			'script', 'foreignobject', 'iframe', 'embed', 'object', 'link', 'meta',
			'style', 'handler', 'set', 'animate', 'animatemotion', 'animatetransform',
			'animatecolor', 'audio', 'video', 'use', // <use> can reference external http resources via xlink:href
		];

		// 2. Iterate over a frozen snapshot of children (some are removed during iteration).
		$children = [];
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			if ( $child instanceof \DOMElement ) {
				$tag = strtolower( $child->nodeName );
				if ( in_array( $tag, $forbidden_elements, true ) ) {
					$node->removeChild( $child );
					continue;
				}
				self::clean_node( $child );
			}
		}
	}

	private static function clean_attributes( \DOMElement $node ): void {
		// Snapshot before modification.
		$attrs = [];
		if ( $node->hasAttributes() ) {
			foreach ( $node->attributes as $attr ) {
				$attrs[] = $attr->nodeName;
			}
		}

		foreach ( $attrs as $name ) {
			$lower = strtolower( $name );

			// Event attributes: on* + SMIL handlers.
			if ( str_starts_with( $lower, 'on' ) ) {
				$node->removeAttribute( $name );
				continue;
			}

			// href / xlink:href with dangerous scheme.
			if ( $lower === 'href' || $lower === 'xlink:href' ) {
				$value = trim( strtolower( (string) $node->getAttribute( $name ) ) );
				if ( str_starts_with( $value, 'javascript:' ) ) {
					$node->removeAttribute( $name );
					continue;
				}
				// data: allowed only for image/*.
				if ( str_starts_with( $value, 'data:' ) && ! str_starts_with( $value, 'data:image/' ) ) {
					$node->removeAttribute( $name );
					continue;
				}
			}

			// `style` can contain url(javascript:…) — strip it.
			if ( $lower === 'style' ) {
				$value = (string) $node->getAttribute( $name );
				if ( preg_match( '/(javascript|expression|behaviou?r)\s*:/i', $value ) ) {
					$node->removeAttribute( $name );
				}
			}
		}
	}
}

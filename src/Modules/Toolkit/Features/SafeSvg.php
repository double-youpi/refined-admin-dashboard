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
 * Sanitization strategy (allow-list since RAD 1.0.25, security report of
 * 29/10/2026: `<s:script>` with a namespace prefix and a `javascript:` link
 * split by a tab went through the former deny-list):
 *   - elements are kept by local name, in the SVG namespace only; anything
 *     else (script, foreignObject, style, animation, an XHTML element) goes
 *     with its content, as do comments and processing instructions;
 *   - attributes are kept from a list (plus `aria-*`, `data-*`, `xlink:href`,
 *     `xml:space`, `xml:lang`), never an `on*` handler nor another namespace;
 *   - links are judged once whitespace and control characters are removed:
 *     internal `#id` references, http(s)/mailto/relative for `<a>`, raster
 *     `data:` images for `<image>`; `url()` values internal only;
 *   - DOCTYPE and processing instructions are stripped before parsing (XXE).
 * Uploading SVG is limited to administrators by default (can_upload_svg).
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
		add_action( 'admin_init',                  [ self::class, 'recheck_existing' ] );
	}

	/** Option recording that the SVGs already in the library were re-checked. */
	public const OPTION_RECHECKED = 'doubleyoupi_toolkit_safe_svg_rechecked';

	/**
	 * Who may upload SVG files. An SVG is a document, not a picture: only
	 * trusted accounts, administrators by default (security report of
	 * 29/10/2026). Filterable: `doubleyoupi_filter_svg_upload_capability`
	 * (for example `upload_files` to open it to every account that uploads).
	 */
	public static function can_upload_svg(): bool {
		$cap = (string) apply_filters( 'doubleyoupi_filter_svg_upload_capability', 'manage_options' );
		return '' !== $cap && current_user_can( $cap );
	}

	/**
	 * Re-checks, once, the SVGs uploaded before the allow-list sanitizer
	 * (RAD 1.0.25): a file carrying a dangerous construct is rewritten with
	 * its sanitized version; a clean file is left as it is. By batches of
	 * 200 on administrators' visits, until done.
	 */
	public static function recheck_existing(): void {
		if ( get_option( self::OPTION_RECHECKED ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$done  = (int) get_transient( 'doubleyoupi_safe_svg_recheck_offset' );
		$posts = get_posts(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/svg+xml',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'offset'         => $done,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			]
		);
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		foreach ( $posts as $id ) {
			$path = get_attached_file( (int) $id );
			if ( ! $path || ! $wp_filesystem || ! $wp_filesystem->exists( $path ) ) {
				continue;
			}
			$raw = (string) $wp_filesystem->get_contents( $path );
			if ( self::is_dangerous( $raw ) ) {
				$clean = self::sanitize( $raw );
				if ( null !== $clean ) {
					$wp_filesystem->put_contents( $path, $clean, FS_CHMOD_FILE );
				}
			}
		}
		if ( count( $posts ) < 200 ) {
			update_option( self::OPTION_RECHECKED, gmdate( 'c' ), false );
			delete_transient( 'doubleyoupi_safe_svg_recheck_offset' );
			return;
		}
		set_transient( 'doubleyoupi_safe_svg_recheck_offset', $done + 200, DAY_IN_SECONDS );
	}

	/**
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public static function allow_svg_mime( array $mimes ): array {
		// Trusted accounts only (can_upload_svg). .svg only: a compressed .svgz
		// could not go through the sanitizer.
		if ( self::can_upload_svg() ) {
			$mimes['svg'] = 'image/svg+xml';
		}
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
		// Never for an account that may not upload SVG: forcing the type would
		// let the file through although the type is not allowed for it.
		if ( $ext === 'svg' && self::can_upload_svg() ) {
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
		$root = $dom->documentElement;
		if ( 'svg' !== strtolower( (string) $root->localName ) || ( null !== $root->namespaceURI && self::NS_SVG !== $root->namespaceURI ) ) {
			return null;
		}

		self::clean_node( $root );

		$out = $dom->saveXML( $root );
		return is_string( $out ) ? $out : null;
	}

	/**
	 * Whether an SVG carries anything the sanitizer would strip as dangerous
	 * (script-like elements in any namespace, event handlers, unsafe links).
	 * Used to re-check files uploaded before 1.0.25 without touching clean ones.
	 */
	public static function is_dangerous( string $svg ): bool {
		$clean = self::sanitize( $svg );
		if ( null === $clean ) {
			return false; // not an SVG WordPress would display as one
		}
		$text = strtolower( html_entity_decode( $svg, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return (bool) preg_match( '/<(?:[\w-]+:)?(?:script|foreignobject|iframe|embed|object|handler|set|animate)\b|[\s:]on[a-z]+\s*=|@import/', $text )
			|| (bool) preg_match( '/(?:java|vb)script:|data:text/', self::normalize( $svg ) );
	}

	private const NS_SVG   = 'http://www.w3.org/2000/svg';
	private const NS_XLINK = 'http://www.w3.org/1999/xlink';
	private const NS_XML   = 'http://www.w3.org/XML/1998/namespace';

	/**
	 * Elements kept, by local name, in the SVG namespace only. Anything else
	 * (script, foreignObject, style, animation, an element of another
	 * namespace such as XHTML) is removed with its content.
	 */
	private const ALLOWED_ELEMENTS = [
		'svg', 'g', 'defs', 'desc', 'title', 'symbol', 'use', 'switch', 'view',
		'a', 'image', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		'text', 'tspan', 'textpath',
		'lineargradient', 'radialgradient', 'stop', 'pattern', 'clippath', 'mask', 'marker',
		'filter', 'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite', 'feconvolvematrix',
		'fediffuselighting', 'fedisplacementmap', 'fedistantlight', 'fedropshadow', 'feflood',
		'fefunca', 'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur', 'feimage', 'femerge',
		'femergenode', 'femorphology', 'feoffset', 'fepointlight', 'fespecularlighting',
		'fespotlight', 'fetile', 'feturbulence',
	];

	/**
	 * Attributes kept (no namespace), lower-cased. `xlink:href`, `xml:space`
	 * and `xml:lang` are added by namespace; `aria-*` and `data-*` by prefix.
	 * Every `on*` handler, and any attribute of another namespace, goes.
	 */
	private const ALLOWED_ATTRIBUTES = [
		'id', 'class', 'style', 'lang', 'role', 'tabindex', 'href', 'target', 'title',
		'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr', 'dx', 'dy',
		'width', 'height', 'd', 'points', 'pathlength', 'transform', 'viewbox', 'preserveaspectratio',
		'version', 'baseprofile', 'xmlns',
		'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap',
		'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity',
		'color', 'display', 'visibility', 'overflow', 'clip', 'clip-path', 'clip-rule', 'clippathunits',
		'mask', 'maskunits', 'maskcontentunits', 'filter', 'filterunits', 'primitiveunits',
		'marker-start', 'marker-mid', 'marker-end', 'markerwidth', 'markerheight', 'markerunits',
		'refx', 'refy', 'orient', 'vector-effect', 'shape-rendering', 'color-interpolation',
		'color-interpolation-filters', 'image-rendering', 'text-rendering', 'paint-order', 'mix-blend-mode',
		'isolation',
		'font-family', 'font-size', 'font-size-adjust', 'font-stretch', 'font-style', 'font-variant',
		'font-weight', 'text-anchor', 'dominant-baseline', 'alignment-baseline', 'baseline-shift',
		'letter-spacing', 'word-spacing', 'text-decoration', 'writing-mode', 'direction', 'unicode-bidi',
		'lengthadjust', 'textlength', 'rotate', 'startoffset', 'method', 'spacing', 'side',
		'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'spreadmethod',
		'patternunits', 'patterncontentunits', 'patterntransform',
		'in', 'in2', 'result', 'mode', 'operator', 'k1', 'k2', 'k3', 'k4', 'type', 'values',
		'tablevalues', 'slope', 'intercept', 'amplitude', 'exponent', 'stddeviation', 'edgemode',
		'order', 'kernelmatrix', 'divisor', 'bias', 'targetx', 'targety', 'preservealpha',
		'surfacescale', 'diffuseconstant', 'specularconstant', 'specularexponent', 'kernelunitlength',
		'scale', 'xchannelselector', 'ychannelselector', 'azimuth', 'elevation', 'z', 'pointsatx',
		'pointsaty', 'pointsatz', 'limitingconeangle', 'flood-color', 'flood-opacity', 'lighting-color',
		'radius', 'basefrequency', 'numoctaves', 'seed', 'stitchtiles', 'requiredfeatures',
		'requiredextensions', 'systemlanguage', 'externalresourcesrequired', 'enable-background',
	];

	/**
	 * Lower-cased value with whitespace and control characters removed, the
	 * form browsers resolve a URL scheme from (`jav&#x09;ascript:` is
	 * `javascript:` once the tab is ignored).
	 */
	private static function normalize( string $value ): string {
		return strtolower( (string) preg_replace( '/[\x00-\x20\x7f]+/', '', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * A link is kept when it points inside the file (`#id`), and for `<a>`
	 * also to an http(s), mailto or relative address. Images (`<image>`,
	 * `<feImage>`) may also embed a raster `data:` image.
	 */
	private static function is_safe_link( string $value, string $element ): bool {
		$v = self::normalize( $value );
		if ( '' === $v || str_starts_with( $v, '#' ) ) {
			return true;
		}
		if ( in_array( $element, [ 'image', 'feimage' ], true ) ) {
			return (bool) preg_match( '#^data:image/(png|jpe?g|gif|webp);#', $v );
		}
		if ( 'a' === $element ) {
			// A scheme is what comes before the first « : » if no « / ? # » precedes it.
			if ( ! preg_match( '#^([a-z][a-z0-9+.-]*):#', $v, $m ) ) {
				return true; // relative address
			}
			return in_array( $m[1], [ 'http', 'https', 'mailto' ], true );
		}
		return false; // <use> and the rest: internal references only
	}

	/**
	 * Recursive cleanup: sanitizes the node's attributes, removes children
	 * that are not allowed (with their content), recurses into the others.
	 * Comments and processing instructions go too.
	 */
	private static function clean_node( \DOMElement $node ): void {
		self::clean_attributes( $node );

		$children = [];
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			if ( $child instanceof \DOMElement ) {
				$local = strtolower( (string) $child->localName );
				$ns    = $child->namespaceURI;
				if ( ( null !== $ns && self::NS_SVG !== $ns ) || ! in_array( $local, self::ALLOWED_ELEMENTS, true ) ) {
					$node->removeChild( $child );
					continue;
				}
				self::clean_node( $child );
				continue;
			}
			if ( $child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction ) {
				$node->removeChild( $child );
			}
		}
	}

	private static function clean_attributes( \DOMElement $node ): void {
		$element = strtolower( (string) $node->localName );
		$attrs   = [];
		if ( $node->hasAttributes() ) {
			foreach ( $node->attributes as $attr ) {
				$attrs[] = $attr;
			}
		}

		foreach ( $attrs as $attr ) {
			$local = strtolower( (string) $attr->localName );
			$ns    = $attr->namespaceURI;

			$allowed = false;
			if ( null === $ns || '' === $ns ) {
				$allowed = in_array( $local, self::ALLOWED_ATTRIBUTES, true )
					|| str_starts_with( $local, 'aria-' ) || str_starts_with( $local, 'data-' );
			} elseif ( self::NS_XLINK === $ns ) {
				$allowed = in_array( $local, [ 'href', 'title' ], true );
			} elseif ( self::NS_XML === $ns ) {
				$allowed = in_array( $local, [ 'space', 'lang' ], true );
			}
			if ( ! $allowed || str_starts_with( $local, 'on' ) ) {
				$node->removeAttributeNode( $attr );
				continue;
			}

			if ( 'href' === $local && ! self::is_safe_link( (string) $attr->value, $element ) ) {
				$node->removeAttributeNode( $attr );
				continue;
			}

			// Inline styles and presentation values may hold url(): internal
			// references only, and nothing script-like.
			$value = self::normalize( (string) $attr->value );
			if ( preg_match( '/(?:java|vb)script:|expression\(|behaviou?r:|@import|-moz-binding/', $value )
				|| ( str_contains( $value, 'url(' ) && preg_match( '/url\((?![\'"]?#)/', $value ) ) ) {
				$node->removeAttributeNode( $attr );
			}
		}
	}
}

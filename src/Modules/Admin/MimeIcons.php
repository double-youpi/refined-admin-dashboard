<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Core\Build;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MimeIcons — the file-type icons of the media library, redrawn.
 *
 * WordPress illustrates every attachment without a thumbnail (audio, video,
 * PDF, archives…) with the 48×64 PNG of `wp-includes/images/media`, chosen
 * by `wp_mime_type_icon()`. Scaled into our 60 px tiles they come out
 * blurred and squashed. The core `wp_mime_type_icon` filter lets us hand
 * back our own vector icons (assets/images/mime): the type is read from the
 * basename core resolved (audio, video, document…), PDF gets its own icon
 * from the MIME type. Icons from another plugin, or a type we do not draw,
 * are left untouched. Covers the list table, the grid and the media modal
 * (`wp_prepare_attachment_for_js()` goes through the same function).
 */
final class MimeIcons {

	/** Types drawn in assets/images/mime, named after core's icon basenames. */
	private const TYPES = [ 'archive', 'audio', 'code', 'default', 'document', 'interactive', 'spreadsheet', 'text', 'video' ];

	public function boot(): void {
		// Replace the icon SET, not each URL. WordPress resolves an icon by
		// scanning `icon_dir` and, further on (media.php), reads the file back
		// as `icon_dir . '/' . basename( $url )` to measure it. Rewriting only
		// the URL left that second lookup in core's folder, where our names do
		// not exist: two warnings per PDF on the media screen. Pointing both
		// the directory and its URI at ours keeps the two halves together.
		add_filter( 'icon_dir', [ self::class, 'icon_dir' ] );
		add_filter( 'icon_dir_uri', [ self::class, 'icon_dir_uri' ] );
		// PDF still needs a word: core maps application/pdf to `document`.
		add_filter( 'wp_mime_type_icon', [ $this, 'icon' ], 10, 3 );
	}

	/** Absolute path of our icon set, no trailing slash (core concatenates '/'). */
	public static function icon_dir(): string {
		return untrailingslashit( Build::dir( __FILE__, 'assets/images/mime' ) );
	}

	/** Its URI, same shape. */
	public static function icon_dir_uri(): string {
		return untrailingslashit( Build::url( __FILE__, 'assets/images/mime' ) );
	}

	/**
	 * @param mixed  $icon    Icon URL resolved by core (false when none).
	 * @param string $mime    MIME type of the attachment.
	 * @param int    $post_id Attachment ID (0 when called with a MIME type).
	 * @return mixed
	 */
	public function icon( $icon, $mime, $post_id ) {
		if ( ! is_string( $icon ) || '' === $icon ) {
			return $icon;
		}
		$mime = (string) $mime;
		if ( '' === $mime && $post_id ) {
			$mime = (string) get_post_mime_type( (int) $post_id );
		}
		// Everything else already comes from our set, resolved by core through
		// the two filters above.
		return 'application/pdf' === $mime ? self::url( 'pdf' ) : $icon;
	}

	private static function url( string $type ): string {
		return Build::url( __FILE__, 'assets/images/mime/' ) . $type . '.svg';
	}
}

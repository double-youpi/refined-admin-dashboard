<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

use DoubleYoupi\Core\Build;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local avatars — complete replacement for Simple Local Avatars, with NO
 * calls to Gravatar whatsoever.
 *
 * Two contributions:
 *   1. Avatar upload field on the user profile (stored locally as a media
 *      library attachment, referenced in user meta).
 *   2. Zero requests to gravatar.com: every avatar is served locally. Users
 *      without an uploaded avatar receive a locally generated default
 *      (initials on a deterministic colored background, as a SVG data-URI).
 *
 * GDPR argument: Gravatar sends the hashed email + visitor IP to Automattic's
 * servers on every avatar display. Here, no data leaks at all — useful for
 * European clients (see the FR/DE/ES/IT locales of the plugin).
 *
 * Mechanism: we filter `get_avatar_data` (which runs AFTER the core computes
 * the Gravatar URL) to systematically rewrite the URL to a local source —
 * the computed Gravatar URL is therefore never requested.
 */
final class LocalAvatars extends AbstractFeature {

	private const LOCAL_AVATAR_REPOSITION_JS = <<<'JS'
(function () {
	var block = document.getElementById('dy-local-avatar-block');
	if ( ! block ) { return; }
	var form = block.closest('form');
	if ( ! form ) { return; }
	var perso = document.getElementById('dy-admin-user-perso');
	if ( perso && perso.closest('form') === form ) {
		perso.after( block );
	} else if ( form.firstChild ) {
		form.insertBefore( block, form.firstChild );
	}
	// Our local avatar REPLACES the native Gravatar portrait. The WordPress
	// "Profile picture" row (tr.user-profile-picture) was therefore duplicating
	// it — we remove it (it has no submitted field, only the Gravatar preview
	// + a link to gravatar.com).
	var coreRow = form.querySelector('tr.user-profile-picture');
	if ( coreRow ) { coreRow.parentNode.removeChild( coreRow ); }
})();
JS;


	/** User meta key storing the attachment ID of the local avatar. */
	public const META_KEY = 'doubleyoupi_local_avatar';

	/** Script handle for the profile field media uploader. */
	public const SCRIPT_HANDLE = 'doubleyoupi-toolkit-local-avatars';

	private const NONCE_ACTION = 'doubleyoupi_local_avatar_save';
	private const NONCE_FIELD  = 'doubleyoupi_local_avatar_nonce';

	public function id(): string {
		return 'local_avatars';
	}

	public function label(): string {
		return __( 'Local avatars', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function checkbox_label(): string {
		return __( 'Per-user avatar upload, with zero Gravatar requests', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Adds an avatar upload field to user profiles and serves every avatar locally: no request ever reaches gravatar.com (GDPR-friendly). Users without an uploaded avatar get a generated initials avatar. Replaces Simple Local Avatars.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Portrait in a circle — user avatar.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<circle cx="12" cy="12" r="10"/>'
			. '<circle cx="12" cy="10" r="3"/>'
			. '<path d="M6.5 19a6 6 0 0 1 11 0"/>'
			. '</svg>';
	}

	public function conflict_plugin_candidates(): array {
		return [
			[ 'file' => 'simple-local-avatars/simple-local-avatars.php', 'name' => 'Simple Local Avatars' ],
			[ 'file' => 'avatar-privacy/avatar-privacy.php',             'name' => 'Avatar Privacy' ],
			[ 'file' => 'wp-user-avatar/wp-user-avatar.php',             'name' => 'ProfilePress (WP User Avatar)' ],
			[ 'file' => 'one-user-avatar/one-user-avatar.php',           'name' => 'One User Avatar' ],
		];
	}

	protected function apply(): void {
		// pre_get_avatar: we build the <img> tag ourselves (esc_url would break
		// our SVG data-URIs). get_avatar_data: for direct consumers.
		add_filter( 'pre_get_avatar',  [ self::class, 'pre_get_avatar_html' ], 10, 3 );
		add_filter( 'get_avatar_data', [ self::class, 'filter_avatar_data' ], 10, 2 );

		add_action( 'show_user_profile', [ self::class, 'render_field' ] );
		add_action( 'edit_user_profile', [ self::class, 'render_field' ] );
		add_action( 'personal_options_update',   [ self::class, 'save_field' ] );
		add_action( 'edit_user_profile_update',  [ self::class, 'save_field' ] );

		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
	}

	// -------------------------------------------------------------------------
	// Avatar serving
	//
	// Two complementary hooks:
	//   - `pre_get_avatar`: we build the <img> tag ourselves and short-circuit
	//     get_avatar(). ESSENTIAL because get_avatar() passes the URL through
	//     esc_url(), which STRIPS data-URIs (the SVG default would become an
	//     empty src → the browser would fall back to the Gravatar srcset =
	//     broken image). We therefore escape the data-URI with esc_attr.
	//   - `get_avatar_data`: for consumers that call get_avatar_data() directly
	//     (REST API, block editor…). We rewrite url + url2x there.
	// -------------------------------------------------------------------------

	/**
	 * Builds the avatar <img> tag and short-circuits get_avatar().
	 *
	 * @param string|null $avatar      Pre-computed HTML (null by default).
	 * @param mixed       $id_or_email Identity.
	 * @param array<string,mixed> $args Args from get_avatar().
	 * @return string
	 */
	public static function pre_get_avatar_html( $avatar, $id_or_email, $args ): string {
		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		if ( $size <= 0 ) {
			$size = 96;
		}

		[ $src, $src2x ] = self::resolve_avatar_src( $id_or_email, $size );

		$classes = [ 'avatar', 'avatar-' . $size, 'photo' ];
		if ( ! empty( $args['class'] ) ) {
			$classes = array_merge( $classes, (array) $args['class'] );
		}

		$alt          = isset( $args['alt'] ) ? (string) $args['alt'] : '';
		$extra_attr   = isset( $args['extra_attr'] ) ? (string) $args['extra_attr'] : '';
		$loading_attr = ! empty( $args['loading'] ) ? sprintf( " loading='%s'", esc_attr( (string) $args['loading'] ) ) : '';

		return sprintf(
			"<img alt='%s' src='%s' srcset='%s 2x' class='%s' height='%d' width='%d' decoding='async'%s %s/>",
			esc_attr( $alt ),
			self::escape_avatar_src( $src ),
			self::escape_avatar_src( $src2x ),
			esc_attr( implode( ' ', $classes ) ),
			$size,
			$size,
			$loading_attr,
			$extra_attr // already built/escaped by the core.
		);
	}

	/**
	 * Rewrites url + url2x for direct consumers of get_avatar_data().
	 *
	 * @param array<string,mixed> $args
	 * @param mixed               $id_or_email
	 * @return array<string,mixed>
	 */
	public static function filter_avatar_data( array $args, $id_or_email ): array {
		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		if ( $size <= 0 ) {
			$size = 96;
		}

		[ $url, $url2x ]      = self::resolve_avatar_src( $id_or_email, $size );
		$args['url']          = $url;
		// CRITICAL: without rewriting url2x, the 2x srcset would remain on gravatar.com.
		$args['url2x']        = $url2x;
		$args['found_avatar'] = true;
		return $args;
	}

	/**
	 * Resolves the avatar source [src 1x, src 2x] for an identity: uploaded
	 * avatar (attachment URL) or locally generated default (SVG data-URI).
	 * NEVER contacts Gravatar.
	 *
	 * @param mixed $id_or_email
	 * @return array{0:string,1:string}
	 */
	public static function resolve_avatar_src( $id_or_email, int $size ): array {
		$size = max( 1, $size );
		$user = self::resolve_user( $id_or_email );

		if ( $user instanceof \WP_User ) {
			$attachment_id = (int) get_user_meta( $user->ID, self::META_KEY, true );
			if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
				$url   = wp_get_attachment_image_url( $attachment_id, [ $size, $size ] );
				$url2x = wp_get_attachment_image_url( $attachment_id, [ $size * 2, $size * 2 ] );
				if ( is_string( $url ) && $url !== '' ) {
					return [ $url, ( is_string( $url2x ) && $url2x !== '' ) ? $url2x : $url ];
				}
			}
			$seed = $user->display_name !== '' ? $user->display_name : (string) $user->user_email;
		} else {
			$seed = self::seed_from_id_or_email( $id_or_email );
		}

		// Vector SVG: identical at @1x and @2x.
		$default = self::local_default_svg( $seed, $size );
		return [ $default, $default ];
	}

	/**
	 * Escapes an avatar source for a src/srcset attribute. esc_url()
	 * STRIPS data-URIs (protocol not allowed) — we therefore use esc_attr()
	 * for our SVG defaults, and esc_url() for http(s) attachment URLs.
	 */
	public static function escape_avatar_src( string $src ): string {
		return esc_url( $src, [ 'http', 'https', 'data' ] );
	}

	/**
	 * Resolves the identity passed to get_avatar() to a WP_User if possible.
	 *
	 * @param mixed $id_or_email
	 */
	public static function resolve_user( $id_or_email ): ?\WP_User {
		$user = null;

		if ( $id_or_email instanceof \WP_User ) {
			$user = $id_or_email;
		} elseif ( $id_or_email instanceof \WP_Post ) {
			$user = get_user_by( 'id', (int) $id_or_email->post_author );
		} elseif ( $id_or_email instanceof \WP_Comment ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				$user = get_user_by( 'id', (int) $id_or_email->user_id );
			} elseif ( ! empty( $id_or_email->comment_author_email ) ) {
				$user = get_user_by( 'email', (string) $id_or_email->comment_author_email );
			}
		} elseif ( is_numeric( $id_or_email ) ) {
			$user = get_user_by( 'id', (int) $id_or_email );
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
		}

		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * Extracts a human seed (for initials + color) from an identity that could
	 * not be resolved to a user (anonymous comment, raw email…).
	 *
	 * @param mixed $id_or_email
	 */
	public static function seed_from_id_or_email( $id_or_email ): string {
		if ( $id_or_email instanceof \WP_Comment ) {
			if ( ! empty( $id_or_email->comment_author_email ) ) {
				return (string) $id_or_email->comment_author_email;
			}
			if ( ! empty( $id_or_email->comment_author ) ) {
				return (string) $id_or_email->comment_author;
			}
			return 'user';
		}
		if ( is_string( $id_or_email ) && $id_or_email !== '' ) {
			return $id_or_email;
		}
		if ( is_numeric( $id_or_email ) ) {
			return 'user-' . (int) $id_or_email;
		}
		return 'user';
	}

	// -------------------------------------------------------------------------
	// Local default generation (pure, unit-testable)
	// -------------------------------------------------------------------------

	/**
	 * Builds a default SVG avatar (initials on a deterministic colored background),
	 * encoded as a base64 data-URI. No external dependencies.
	 */
	public static function local_default_svg( string $seed, int $size ): string {
		$size     = max( 1, $size );
		$initials = self::initials_from( $seed );
		$hue      = self::hue_from( $seed );
		$bg       = sprintf( 'hsl(%d,52%%,52%%)', $hue );
		$font     = round( $size * 0.42, 2 );

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" role="img">'
			. '<rect width="100%" height="100%" fill="' . $bg . '"/>'
			. '<text x="50%" y="50%" dy="0.35em" text-anchor="middle" '
			. 'font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif" '
			. 'font-size="' . $font . '" font-weight="600" fill="#ffffff">'
			. htmlspecialchars( $initials, ENT_QUOTES, 'UTF-8' )
			. '</text></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * 1 to 2 uppercase initials derived from a seed (name or email).
	 */
	public static function initials_from( string $seed ): string {
		$seed = trim( $seed );
		if ( $seed === '' ) {
			return '?';
		}
		// For an email address, keep only the local part.
		$at = strpos( $seed, '@' );
		if ( $at !== false ) {
			$seed = substr( $seed, 0, $at );
		}
		$parts = preg_split( '/[\s._\-]+/u', $seed, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) || count( $parts ) === 0 ) {
			return mb_strtoupper( mb_substr( $seed, 0, 1 ) );
		}
		if ( count( $parts ) === 1 ) {
			return mb_strtoupper( mb_substr( $parts[0], 0, 2 ) );
		}
		return mb_strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 ) );
	}

	/**
	 * Deterministic HSL hue (0-359) derived from the seed.
	 */
	public static function hue_from( string $seed ): int {
		return (int) ( crc32( strtolower( trim( $seed ) ) ) % 360 );
	}

	// -------------------------------------------------------------------------
	// User profile field
	// -------------------------------------------------------------------------

	public static function render_field( \WP_User $user ): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			// Without upload capability, do not display the media picker.
			return;
		}

		$attachment_id = (int) get_user_meta( $user->ID, self::META_KEY, true );
		$preview_url   = '';
		if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
			$preview_url = (string) wp_get_attachment_image_url( $attachment_id, [ 96, 96 ] );
		}
		// Preview always visible: when no upload exists, show the locally
		// generated default avatar (the same one served on the site).
		if ( $preview_url === '' ) {
			$seed        = $user->display_name !== '' ? $user->display_name : (string) $user->user_email;
			$preview_url = self::local_default_svg( $seed, 96 );
		}
		?>
		<div id="dy-local-avatar-block">
		<h2><?php esc_html_e( 'Local avatar', 'doubleyoupi-refined-admin-dashboard' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr class="doubleyoupi-local-avatar-row">
				<th scope="row"><?php esc_html_e( 'Avatar', 'doubleyoupi-refined-admin-dashboard' ); ?></th>
				<td>
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<div class="doubleyoupi-local-avatar">
						<img src="<?php echo esc_url( $preview_url, [ 'http', 'https', 'data' ] ); ?>"
						     alt=""
						     class="doubleyoupi-local-avatar__preview"
						     width="96" height="96"
						     style="border-radius:50%;object-fit:cover;" />
						<input type="hidden"
						       name="<?php echo esc_attr( self::META_KEY ); ?>"
						       class="doubleyoupi-local-avatar__id"
						       value="<?php echo esc_attr( (string) $attachment_id ); ?>" />
						<p>
							<button type="button" class="button doubleyoupi-local-avatar__choose">
								<?php esc_html_e( 'Choose image', 'doubleyoupi-refined-admin-dashboard' ); ?>
							</button>
							<button type="button" class="button doubleyoupi-local-avatar__remove" style="<?php echo $attachment_id > 0 ? '' : 'display:none;'; ?>">
								<?php esc_html_e( 'Remove', 'doubleyoupi-refined-admin-dashboard' ); ?>
							</button>
						</p>
						<p class="description">
							<?php esc_html_e( 'Stored locally in the media library. No avatar uses an automatically generated one. Gravatar is never contacted.', 'doubleyoupi-refined-admin-dashboard' ); ?>
						</p>
					</div>
				</td>
			</tr>
		</table>
		</div>
		<?php
		// WordPress does not expose a hook at the TOP of the profile form. We
		// therefore render the block at the bottom (show_user_profile / edit_user_profile)
		// and then reposition it at the top of the <form>, right after the
		// "Interface customization" block (#dy-admin-user-perso) from the Admin
		// module if it is present.
		wp_print_inline_script_tag( self::LOCAL_AVATAR_REPOSITION_JS, [ 'id' => 'doubleyoupi-local-avatar-reposition' ] );
	}

	public static function save_field( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$attachment_id = isset( $_POST[ self::META_KEY ] ) ? absint( wp_unslash( $_POST[ self::META_KEY ] ) ) : 0;

		if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
			update_user_meta( $user_id, self::META_KEY, $attachment_id );
		} else {
			delete_user_meta( $user_id, self::META_KEY );
		}
	}

	public static function enqueue_assets( string $hook ): void {
		if ( $hook !== 'profile.php' && $hook !== 'user-edit.php' ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			Build::url( __FILE__, 'assets/js/modules/toolkit/local-avatars.js' ),
			[ 'jquery' ],
			DOUBLEYOUPI_VERSION,
			true
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'doubleyoupiLocalAvatars',
			[
				'title'  => __( 'Select an avatar', 'doubleyoupi-refined-admin-dashboard' ),
				'button' => __( 'Use as avatar', 'doubleyoupi-refined-admin-dashboard' ),
			]
		);
	}
}

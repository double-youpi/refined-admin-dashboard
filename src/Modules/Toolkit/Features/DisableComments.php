<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Globally disables WordPress comments.
 *
 * Replaces the Disable Comments plugin. Everything is applied in "globally
 * disabled" mode:
 *   - Removes `comments` and `trackbacks` support from all post types
 *   - Filters `comments_open` / `pings_open` to false
 *   - Systematically empties the comments array returned to templates
 *   - Removes the "Comments" item from the admin menu
 *   - Removes the bubble icon from the admin bar
 *   - Blocks POST to wp-comments-post.php with a clean 403
 *
 * No "per post type" option: this follows the philosophy of the most widely
 * used Disable Comments plugin (mode "everywhere"), and is consistent with
 * manifesto § 2.1 (no per-page settings).
 */
final class DisableComments extends AbstractFeature {

	public function id(): string {
		return 'disable_comments';
	}

	public function label(): string {
		return __( 'Disable comments', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function checkbox_label(): string {
		return __( 'Cut comments and trackbacks everywhere', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Removes comment support from all post types, hides the Comments menu and admin bar icon, blocks POST to wp-comments-post.php. Replaces Disable Comments.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Crossed-out comment bubble.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>'
			. '<line x1="4" y1="4" x2="20" y2="20"/>'
			. '</svg>';
	}

	public function conflict_plugin_candidates(): array {
		return [
			[ 'file' => 'disable-comments/disable-comments.php', 'name' => 'Disable Comments' ],
		];
	}

	protected function apply(): void {
		// Remove comments/trackbacks support from all post types — on init at priority 100
		// to run after all CPT registrations (Bricks, WC, third-party plugins).
		add_action( 'init', [ self::class, 'remove_post_type_support_all' ], 100 );

		// Short-circuit the gates in case a theme checks them.
		add_filter( 'comments_open', '__return_false', 100 );
		add_filter( 'pings_open',    '__return_false', 100 );

		// Templates: if someone calls get_comments() / comments_template().
		add_filter( 'comments_array',    [ self::class, 'empty_comments_array' ], 100, 2 );
		add_filter( 'get_comments_number', '__return_zero', 100 );

		// Admin UI cleanup.
		add_action( 'admin_menu',                  [ self::class, 'remove_comments_menu' ] );
		add_action( 'wp_before_admin_bar_render',  [ self::class, 'remove_admin_bar_node' ] );

		// Block the comments POST endpoint.
		add_action( 'init', [ self::class, 'block_comment_post_endpoint' ] );
	}

	public static function remove_post_type_support_all(): void {
		foreach ( get_post_types() as $post_type ) {
			if ( post_type_supports( $post_type, 'comments' ) ) {
				remove_post_type_support( $post_type, 'comments' );
			}
			if ( post_type_supports( $post_type, 'trackbacks' ) ) {
				remove_post_type_support( $post_type, 'trackbacks' );
			}
		}
	}

	/**
	 * @param array<int, mixed> $comments
	 * @return array<int, mixed>
	 */
	public static function empty_comments_array( array $comments, int $post_id ): array {
		unset( $post_id );
		unset( $comments );
		return [];
	}

	public static function remove_comments_menu(): void {
		remove_menu_page( 'edit-comments.php' );
		// Discussion settings sub-menu: keep it — the WP "comments" option remains accessible
		// in case the admin wants to tweak it, but our `comments_open` filter short-circuits.
	}

	public static function remove_admin_bar_node(): void {
		global $wp_admin_bar;
		if ( $wp_admin_bar instanceof \WP_Admin_Bar ) {
			$wp_admin_bar->remove_node( 'comments' );
		}
	}

	public static function block_comment_post_endpoint(): void {
		// Only fires on an actual POST to wp-comments-post.php.
		// `REQUEST_METHOD` and `SCRIPT_NAME` come from PHP — no nonce here, that is not
		// the point (we block all POSTs, legitimate or not, since comments are off).
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$script = isset( $_SERVER['SCRIPT_NAME'] )    ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) )              : '';
		if ( $method === 'POST' && str_ends_with( $script, '/wp-comments-post.php' ) ) {
			wp_die(
				esc_html__( 'Comments are disabled on this site.', 'doubleyoupi-refined-admin-dashboard' ),
				'',
				[ 'response' => 403 ]
			);
		}
	}
}

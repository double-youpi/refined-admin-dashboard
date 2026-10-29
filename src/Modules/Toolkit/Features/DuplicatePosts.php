<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Duplicate" action to post and page list tables.
 *
 * Replaces Yoast Duplicate Post / Duplicate Page. The handler clones the post,
 * meta, and taxonomies (terms) as a draft. Comments are not cloned (see
 * DisableComments — and duplicating comments does not make sense to users anyway).
 *
 * Security: per-post nonce + `edit_posts` capability check (and `edit_post`
 * on the source post to confirm the user has access).
 */
final class DuplicatePosts extends AbstractFeature {

	public const ACTION = 'doubleyoupi_toolkit_duplicate_post';
	public const NONCE  = '_doubleyoupi_toolkit_duplicate';

	public function id(): string {
		return 'duplicate_posts';
	}

	public function label(): string {
		return __( 'Duplicate a post/page', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function checkbox_label(): string {
		return __( 'Enable a « Duplicate » link in the admin lists', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Adds a « Duplicate » link in the Posts/Pages/CPT lists. Clones the content, meta and taxonomies as a draft. Replaces Yoast Duplicate Post.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Two overlapping rectangles — duplication metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<rect x="9" y="9" width="13" height="13" rx="2"/>'
			. '<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>'
			. '</svg>';
	}

	public function conflict_plugin_candidates(): array {
		return [
			[ 'file' => 'duplicate-post/duplicate-post.php', 'name' => 'Duplicate Post (Yoast)' ],
			[ 'file' => 'duplicate-page/duplicatepage.php',  'name' => 'Duplicate Page' ],
		];
	}

	protected function apply(): void {
		// Priority 999: ensures we run AFTER any plugin/theme that has already
		// added a "Duplicate" link (notably Bricks, which injects the
		// `bricks-duplicate-post` key at priority 10). add_row_action() then removes
		// it to avoid displaying two links side by side — our version is more
		// comprehensive (all CPTs, meta, terms), so we intentionally take precedence.
		add_filter( 'post_row_actions', [ self::class, 'add_row_action' ], 999, 2 );
		add_filter( 'page_row_actions', [ self::class, 'add_row_action' ], 999, 2 );

		// admin_action_{action_name} handler — wired automatically by admin.php.
		add_action( 'admin_action_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Row-action keys for "Duplicate" from other tools, removed by add_row_action()
	 * when we add our own. Bricks is the concrete case: its theme adds the
	 * `bricks-duplicate-post` key. This list is extensible if other builders/themes
	 * do the same in the future.
	 */
	private const SUPERSEDED_ACTION_KEYS = [
		'bricks-duplicate-post',
	];

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function add_row_action( array $actions, \WP_Post $post ): array {
		// Remove "Duplicate" links from other tools to avoid duplicates.
		foreach ( self::SUPERSEDED_ACTION_KEYS as $key ) {
			unset( $actions[ $key ] );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			add_query_arg(
				[
					'action' => self::ACTION,
					'post'   => $post->ID,
				],
				admin_url( 'admin.php' )
			),
			self::ACTION . '_' . $post->ID,
			self::NONCE
		);
		$label   = esc_html_x( 'Duplicate', 'post row action: copy this post as a draft', 'doubleyoupi-refined-admin-dashboard' );
		$title   = esc_attr__( 'Duplicate this content as a draft', 'doubleyoupi-refined-admin-dashboard' );
		$actions['doubleyoupi_duplicate'] =
			'<a href="' . esc_url( $url ) . '" title="' . $title . '" rel="permalink">' . $label . '</a>';
		return $actions;
	}

	public static function handle(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $post_id === 0 ) {
			wp_die( esc_html__( 'Post ID missing.', 'doubleyoupi-refined-admin-dashboard' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( self::ACTION . '_' . $post_id, self::NONCE );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Access denied.', 'doubleyoupi-refined-admin-dashboard' ), '', [ 'response' => 403 ] );
		}

		$source = get_post( $post_id );
		if ( ! $source instanceof \WP_Post ) {
			wp_die( esc_html__( 'Source post not found.', 'doubleyoupi-refined-admin-dashboard' ), '', [ 'response' => 404 ] );
		}

		// Duplicating creates a post: the user must be allowed to create this type.
		$type = get_post_type_object( $source->post_type );
		if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
			wp_die( esc_html__( 'Access denied.', 'doubleyoupi-refined-admin-dashboard' ), '', [ 'response' => 403 ] );
		}

		$new_id = self::duplicate( $source );
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ), '', [ 'response' => 500 ] );
		}

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
		exit;
	}

	/**
	 * Clones a post. Public for direct testability without going through admin_action.
	 *
	 * @return int|\WP_Error ID of the new post, or WP_Error.
	 */
	public static function duplicate( \WP_Post $source ) {
		$data = [
			'post_author'    => (int) get_current_user_id() ?: $source->post_author,
			'post_content'   => $source->post_content,
			'post_excerpt'   => $source->post_excerpt,
			'post_status'    => 'draft',
			'post_type'      => $source->post_type,
			'post_title'     => $source->post_title . ' ' . __( '(Copy)', 'doubleyoupi-refined-admin-dashboard' ),
			'post_parent'    => $source->post_parent,
			'menu_order'     => $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'post_password'  => $source->post_password,
		];

		$new_id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Meta — wp_insert_post does not copy them, we must do it manually.
		$meta = get_post_meta( $source->ID );
		foreach ( $meta as $key => $values ) {
			// Skip protected meta or meta tied to the WP lifecycle.
			if ( in_array( $key, [ '_edit_lock', '_edit_last', '_wp_old_slug' ], true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				// add_post_meta() unslashes what it gets: slashed first, or the
				// JSON of Elementor and Bricks lost its backslashes (« \/ »,
				// « \" ») and the copy broke (review of 29/10/2026).
				add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}

		// Taxonomies.
		$taxonomies = get_object_taxonomies( $source->post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $source->ID, $taxonomy, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				wp_set_object_terms( $new_id, array_map( 'intval', $terms ), $taxonomy );
			}
		}

		return $new_id;
	}
}

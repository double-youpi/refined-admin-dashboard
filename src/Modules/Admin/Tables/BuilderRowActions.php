<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists of posts, pages and custom post types: content made with a page
 * builder opens in the builder.
 *
 * On a page built with Bricks, Elementor, Divi, Beaver Builder, Brizy or
 * Visual Composer (each read from its own code), « Edit » opens an editor that
 * holds little of the page; the useful action is the builder's. When the post
 * really is built with one (its own marker, not only the builder being
 * active: Bricks offers « Edit with Bricks » on every post), its action comes
 * first and is the primary one, the row title opens the builder too, and
 * WordPress's « Edit » becomes « Edit in WordPress », last. Posts without a
 * builder are left as they are. Decided by Raphaël, 04/11/2026.
 *
 * Works in both views: the native table reads the reordered actions, the
 * redesigned one (tables-reimagined.js) takes `data-dy-primary` as the
 * primary flag.
 */
final class BuilderRowActions {

	/** Our row filters run after the builders' (10) and DuplicatePosts (999). */
	private const PRIORITY = 1000;

	public function boot(): void {
		add_filter( 'post_row_actions', [ self::class, 'filter_actions' ], self::PRIORITY, 2 );
		add_filter( 'page_row_actions', [ self::class, 'filter_actions' ], self::PRIORITY, 2 );
		// The row title: only on the list screen and its Quick Edit refresh,
		// where get_edit_post_link() draws the title. Elsewhere (admin bar,
		// « Edit » links of other screens) it keeps leading to WordPress.
		add_action( 'load-edit.php', [ self::class, 'hook_title_link' ] );
		if ( wp_doing_ajax() && isset( $_POST['action'] ) && 'inline-save' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only routing, WordPress checks the nonce.
			self::hook_title_link();
		}
	}

	public static function hook_title_link(): void {
		add_filter( 'get_edit_post_link', [ self::class, 'filter_title_link' ], self::PRIORITY, 2 );
	}

	/**
	 * Known builders: the row action key, whether a post is built with it,
	 * its edit URL. Extend with `doubleyoupi_filter_builder_row_actions`.
	 *
	 * @return array<string, array{action: string, built: callable, url: callable}>
	 */
	public static function builders(): array {
		$builders = [
			'bricks'    => [
				'action' => 'edit_with_bricks',
				'built'  => static fn( \WP_Post $p ): bool => 'bricks_template' === $p->post_type
					|| 'bricks' === get_post_meta( $p->ID, '_bricks_editor_mode', true ),
				'url'    => static function ( \WP_Post $p ): string {
					if ( ! class_exists( '\Bricks\Helpers' ) ) {
						return '';
					}
					if ( class_exists( '\Bricks\Capabilities' ) && ! \Bricks\Capabilities::current_user_can_use_builder( $p->ID ) ) {
						return '';
					}
					return (string) \Bricks\Helpers::get_builder_edit_link( $p->ID );
				},
			],
			'elementor' => [
				'action' => 'edit_with_elementor',
				'built'  => static fn( \WP_Post $p ): bool => 'elementor_library' === $p->post_type
					|| 'builder' === get_post_meta( $p->ID, '_elementor_edit_mode', true ),
				'url'    => static function ( \WP_Post $p ): string {
					if ( ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance->documents ) ) {
						return '';
					}
					$document = \Elementor\Plugin::$instance->documents->get( $p->ID );
					if ( ! $document || ! $document->is_editable_by_current_user() ) {
						return '';
					}
					return (string) $document->get_edit_url();
				},
			],
			// Divi 4 (theme and plugin): « Edit With Divi » on every post of a
			// builder post type, marked by `_et_pb_use_builder`.
			'divi'      => [
				'action' => 'divi',
				'built'  => static fn( \WP_Post $p ): bool => 'et_pb_layout' === $p->post_type
					|| ( function_exists( 'et_pb_is_pagebuilder_used' ) ? (bool) et_pb_is_pagebuilder_used( $p->ID ) : 'on' === get_post_meta( $p->ID, '_et_pb_use_builder', true ) ),
				'url'    => static function ( \WP_Post $p ): string {
					if ( ! function_exists( 'et_fb_get_vb_url' ) ) {
						return '';
					}
					$base = 'et_pb_layout' === $p->post_type ? (string) get_edit_post_link( $p->ID, 'raw' ) : (string) get_permalink( $p->ID );
					return (string) et_fb_get_vb_url( $base );
				},
			],
			// Beaver Builder (Lite and Pro): « Beaver Builder » on every post,
			// marked by `_fl_builder_enabled`; its own templates always are.
			'beaver'    => [
				'action' => 'fl-builder',
				'built'  => static fn( \WP_Post $p ): bool => 'fl-builder-template' === $p->post_type
					|| (bool) get_post_meta( $p->ID, '_fl_builder_enabled', true ),
				'url'    => static fn( \WP_Post $p ): string => class_exists( '\FLBuilderModel' ) ? (string) \FLBuilderModel::get_edit_url( $p->ID ) : '',
			],
			// Brizy: its action only shows on content built with it.
			'brizy'     => [
				'action' => 'brizy-edit',
				'built'  => static function ( \WP_Post $p ): bool {
					try {
						return class_exists( '\Brizy_Editor_Entity' ) && (bool) \Brizy_Editor_Entity::isBrizyEnabled( $p->ID );
					} catch ( \Throwable $e ) {
						return false;
					}
				},
				'url'    => static function ( \WP_Post $p ): string {
					try {
						return class_exists( '\Brizy_Editor_Entity' ) ? (string) \Brizy_Editor_Entity::getEditUrl( $p->ID ) : '';
					} catch ( \Throwable $e ) {
						return '';
					}
				},
			],
			// Visual Composer Website Builder: action on every post, content
			// kept in `vcv-pageContent`.
			'visualcomposer' => [
				'action' => 'edit_vc5',
				'built'  => static fn( \WP_Post $p ): bool => '' !== (string) get_post_meta( $p->ID, 'vcv-pageContent', true ),
				'url'    => static fn( \WP_Post $p ): string => defined( 'VCV_VERSION' )
					? add_query_arg( [ 'vcv-action' => 'frontend', 'vcv-source-id' => $p->ID ], admin_url( 'post.php?post=' . $p->ID . '&action=edit' ) )
					: '',
			],
		];
		/**
		 * Builders whose row action leads on built content.
		 *
		 * @param array<string, array{action: string, built: callable, url: callable}> $builders
		 */
		return (array) apply_filters( 'doubleyoupi_filter_builder_row_actions', $builders );
	}

	/**
	 * The builder a post is built with, among those whose action is offered.
	 *
	 * @param array<string, string>|null $actions Row actions, or null when unknown (title link).
	 * @return array{action: string, built: callable, url: callable}|null
	 */
	private static function builder_for( \WP_Post $post, ?array $actions ): ?array {
		foreach ( self::builders() as $builder ) {
			if ( ! is_array( $builder ) || empty( $builder['action'] ) || ! is_callable( $builder['built'] ?? null ) ) {
				continue;
			}
			if ( null !== $actions && ! isset( $actions[ $builder['action'] ] ) ) {
				continue;
			}
			if ( ( $builder['built'] )( $post ) ) {
				return $builder;
			}
		}
		return null;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function filter_actions( $actions, $post ): array {
		$actions = (array) $actions;
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			return $actions;
		}
		$builder = self::builder_for( $post, $actions );
		if ( null === $builder ) {
			return $actions;
		}

		$key  = $builder['action'];
		$lead = self::flag( (string) $actions[ $key ], '1' );
		unset( $actions[ $key ] );
		$actions = [ $key => $lead ] + $actions;

		if ( isset( $actions['edit'] ) ) {
			unset( $actions['edit'] );
			$title           = _draft_or_post_title( $post );
			$actions['edit'] = sprintf(
				'<a href="%1$s" aria-label="%2$s" data-dy-primary="0">%3$s</a>',
				esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ),
				/* translators: %s: post title. */
				esc_attr( sprintf( __( 'Edit “%s” in WordPress', 'doubleyoupi-refined-admin-dashboard' ), $title ) ),
				esc_html__( 'Edit in WordPress', 'doubleyoupi-refined-admin-dashboard' )
			);
		}
		return $actions;
	}

	/**
	 * The row title opens the builder for built content.
	 *
	 * @param string|null $link
	 * @param int         $post_id
	 */
	public static function filter_title_link( $link, $post_id ) {
		// Builders build their URL from get_edit_post_link() too (Divi's
		// library, Visual Composer): inside our own call, the plain link.
		static $busy = false;
		if ( $busy ) {
			return $link;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! is_string( $link ) || '' === $link ) {
			return $link;
		}
		$builder = self::builder_for( $post, null );
		if ( null === $builder || ! is_callable( $builder['url'] ?? null ) ) {
			return $link;
		}
		$busy = true;
		try {
			$url = (string) ( $builder['url'] )( $post );
		} finally {
			$busy = false;
		}
		return '' !== $url ? $url : $link;
	}

	/**
	 * Where to edit a post: its builder when it is built with one and the
	 * builder lets the current user in, else the WordPress editor. With the
	 * builder's key ('' for the WordPress editor). Used by the dashboard's
	 * Edit widget (RAD 1.2.0).
	 *
	 * @return array{url: string, builder: string}
	 */
	public static function edit_target( \WP_Post $post ): array {
		$plain = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
		if ( 'trash' === $post->post_status ) {
			return [ 'url' => $plain, 'builder' => '' ];
		}
		foreach ( self::builders() as $key => $builder ) {
			if ( ! is_array( $builder ) || ! is_callable( $builder['built'] ?? null ) || ! is_callable( $builder['url'] ?? null ) ) {
				continue;
			}
			if ( ! ( $builder['built'] )( $post ) ) {
				continue;
			}
			$url = (string) ( $builder['url'] )( $post );
			return '' !== $url ? [ 'url' => $url, 'builder' => (string) $key ] : [ 'url' => $plain, 'builder' => '' ];
		}
		return [ 'url' => $plain, 'builder' => '' ];
	}

	/**
	 * Adds `data-dy-primary` to the action's first link.
	 */
	private static function flag( string $html, string $value ): string {
		if ( str_contains( $html, 'data-dy-primary' ) ) {
			return $html;
		}
		return (string) preg_replace( '/<a\b/i', '<a data-dy-primary="' . $value . '"', $html, 1 );
	}
}

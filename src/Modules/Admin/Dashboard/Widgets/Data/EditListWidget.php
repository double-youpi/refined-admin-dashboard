<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Admin\Kses;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;
use DoubleYoupi\Modules\Admin\Tables\BuilderRowActions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Edit" widget (RAD 1.2.0, 12/11/2026), the counterpart of Create: a short
 * list of content to open in its editor, and the way to the full list.
 *
 * One setting besides the content type, three choices:
 *   - recent: the last modified entries of the type (default);
 *   - drafts: drafts and pending entries, what is left to finish;
 *   - picked: entries the administrator chose ("Home", "Contact"…), any type.
 *
 * Each person sees only what they may edit (WP_Query `perm => editable`, then
 * `edit_post`): one disposition can be imposed on several roles. Content built
 * with Bricks, Elementor, Divi… opens in its builder, as in the lists.
 * Instanceable: one widget per type or per set of picked pages.
 */
final class EditListWidget extends AbstractDataWidget {

	/** Rows shown in the recent and drafts modes. */
	private const ROWS = 6;

	/** Most entries that can be picked. */
	private const MAX_PICKS = 12;

	/** Builder key => name shown on the row (proper nouns, not translated). */
	private const BUILDER_NAMES = [
		'bricks'         => 'Bricks',
		'elementor'      => 'Elementor',
		'divi'           => 'Divi',
		'beaver'         => 'Beaver Builder',
		'brizy'          => 'Brizy',
		'visualcomposer' => 'Visual Composer',
	];

	public function id(): string {
		return 'edit_list';
	}

	public function required_cap(): string {
		return 'edit_posts';
	}

	public function name(): string {
		return __( 'Edit', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function palette_name(): string {
		return __( 'Edit (one type)', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'A short list of content to open in its editor: the latest, the drafts, or the pages you choose.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
	}

	public function is_instanceable(): bool {
		return true;
	}

	public function default_config(): array {
		return [
			'post_type' => 'page',
			'mode'      => 'recent',
			'picks'     => [],
			'title'     => '',
		];
	}

	public function config_schema(): array {
		$types = [];
		$picks = [];
		foreach ( CreateButtonWidget::editorial_post_types() as $name => $pt ) {
			$singular = isset( $pt->labels->singular_name ) ? (string) $pt->labels->singular_name : $name;
			$types[]  = [ 'value' => $name, 'label' => $singular ];
			$posts    = get_posts( [
				'post_type'      => $name,
				'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'perm'           => 'editable',
				'no_found_rows'  => true,
			] );
			foreach ( $posts as $post ) {
				$picks[] = [
					'value' => (string) $post->ID,
					/* translators: 1: content type (e.g. "Page"), 2: title. */
					'label' => sprintf( __( '%1$s: %2$s', 'doubleyoupi-refined-admin-dashboard' ), $singular, self::title( $post ) ),
				];
			}
		}
		return [
			[ 'type' => 'info', 'text' => __( 'Each person only sees what they may edit. Content built with a page builder opens in it.', 'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'key' => 'post_type', 'type' => 'select', 'label' => __( 'Post type', 'doubleyoupi-refined-admin-dashboard' ), 'options' => $types ],
			[ 'key' => 'mode', 'type' => 'select', 'label' => __( 'Show', 'doubleyoupi-refined-admin-dashboard' ), 'options' => [
				[ 'value' => 'recent', 'label' => __( 'The latest changed', 'doubleyoupi-refined-admin-dashboard' ) ],
				[ 'value' => 'drafts', 'label' => __( 'Drafts to finish', 'doubleyoupi-refined-admin-dashboard' ) ],
				[ 'value' => 'picked', 'label' => __( 'The content I choose', 'doubleyoupi-refined-admin-dashboard' ) ],
			] ],
			[ 'key' => 'picks', 'type' => 'picklist', 'label' => __( 'Chosen content (for “The content I choose”)', 'doubleyoupi-refined-admin-dashboard' ), 'options' => $picks, 'max' => self::MAX_PICKS ],
			[ 'key' => 'title', 'type' => 'text', 'label' => __( 'Title (optional)', 'doubleyoupi-refined-admin-dashboard' ) ],
		];
	}

	public function validate_config( array $config ): array {
		$type = sanitize_key( (string) ( $config['post_type'] ?? 'page' ) );
		if ( ! post_type_exists( $type ) ) {
			$type = 'page';
		}
		$mode  = (string) ( $config['mode'] ?? 'recent' );
		$picks = [];
		foreach ( (array) ( $config['picks'] ?? [] ) as $id ) {
			$id = absint( $id );
			if ( $id > 0 && ! in_array( $id, $picks, true ) ) {
				$picks[] = $id;
			}
		}
		return [
			'post_type' => $type,
			'mode'      => in_array( $mode, [ 'recent', 'drafts', 'picked' ], true ) ? $mode : 'recent',
			'picks'     => array_slice( $picks, 0, self::MAX_PICKS ),
			'title'     => sanitize_text_field( (string) ( $config['title'] ?? '' ) ),
		];
	}

	public function get_data( array $config ): array {
		$c    = $this->validate_config( $config );
		$type = get_post_type_object( $c['post_type'] );

		if ( 'picked' === $c['mode'] ) {
			$posts = $c['picks'] ? get_posts( [
				'post__in'       => $c['picks'],
				'orderby'        => 'post__in',
				'post_type'      => 'any',
				'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
				'posts_per_page' => self::MAX_PICKS,
				'no_found_rows'  => true,
			] ) : [];
		} else {
			$posts = get_posts( [
				'post_type'      => $c['post_type'],
				'post_status'    => 'drafts' === $c['mode'] ? [ 'draft', 'pending' ] : [ 'publish', 'draft', 'pending', 'future', 'private' ],
				'posts_per_page' => self::ROWS,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'perm'           => 'editable',
				'no_found_rows'  => true,
			] );
		}

		$items = [];
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$target  = BuilderRowActions::edit_target( $post );
			$items[] = [
				'title'   => self::title( $post ),
				'url'     => $target['url'],
				'builder' => self::BUILDER_NAMES[ $target['builder'] ] ?? ( '' !== $target['builder'] ? ucfirst( $target['builder'] ) : '' ),
				'status'  => 'publish' === $post->post_status ? '' : (string) ( get_post_status_object( $post->post_status )->label ?? $post->post_status ),
				// Local time when the GMT one was never set (some drafts).
				'ts'      => (int) ( get_post_modified_time( 'U', true, $post ) ?: get_post_modified_time( 'U', false, $post ) ),
			];
		}

		$list_url = '';
		if ( 'picked' !== $c['mode'] && $type && current_user_can( (string) $type->cap->edit_posts ) ) {
			$list_url = admin_url( 'edit.php?post_type=' . $c['post_type'] . ( 'drafts' === $c['mode'] ? '&post_status=draft' : '' ) );
		}

		return [
			'items'     => $items,
			'plural'    => $type ? (string) $type->labels->name : $c['post_type'],
			'all_label' => $type ? (string) $type->labels->all_items : __( 'All', 'doubleyoupi-refined-admin-dashboard' ),
			'list_url'  => $list_url,
		];
	}

	public function render_html( array $config, array $data ): string {
		$c     = $this->validate_config( $config );
		$items = is_array( $data['items'] ?? null ) ? $data['items'] : [];
		$title = $c['title'];
		if ( '' === $title ) {
			$title = 'picked' === $c['mode'] ? __( 'Your content', 'doubleyoupi-refined-admin-dashboard' )
				: ( 'drafts' === $c['mode'] ? __( 'Drafts to finish', 'doubleyoupi-refined-admin-dashboard' ) : (string) ( $data['plural'] ?? '' ) );
		}

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php echo esc_html( $title ); ?></span>
		</div>
		<div class="dy-dashboard__card-body dy-dashboard__edit">
			<?php if ( ! $items ) : ?>
				<p class="dy-dashboard__empty">
					<?php
					if ( 'picked' === $c['mode'] ) {
						esc_html_e( 'No content chosen yet. Choose it from Customize.', 'doubleyoupi-refined-admin-dashboard' );
					} elseif ( 'drafts' === $c['mode'] ) {
						esc_html_e( 'No draft to finish.', 'doubleyoupi-refined-admin-dashboard' );
					} else {
						esc_html_e( 'Nothing to edit yet.', 'doubleyoupi-refined-admin-dashboard' );
					}
					?>
				</p>
			<?php else : ?>
				<ul class="dy-dashboard__edit-list">
					<?php foreach ( $items as $item ) : ?>
						<li>
							<a class="dy-dashboard__edit-row" href="<?php echo esc_url( (string) $item['url'] ); ?>">
								<span class="dy-dashboard__edit-title"><?php echo esc_html( (string) $item['title'] ); ?></span>
								<span class="dy-dashboard__edit-meta">
									<?php if ( '' !== (string) $item['status'] ) : ?>
										<span class="dy-dashboard__edit-tag"><?php echo esc_html( (string) $item['status'] ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== (string) $item['builder'] ) : ?>
										<span class="dy-dashboard__edit-tag dy-dashboard__edit-tag--builder"><?php echo esc_html( (string) $item['builder'] ); ?></span>
									<?php endif; ?>
									<span class="dy-dashboard__edit-time"><?php echo esc_html( self::ago( (int) $item['ts'] ) ); ?></span>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		if ( '' !== (string) ( $data['list_url'] ?? '' ) ) {
			echo wp_kses( self::dy_foot_link( (string) $data['list_url'], (string) $data['all_label'] ), Kses::ui_tags() );
		}
		return (string) ob_get_clean();
	}

	private static function title( \WP_Post $post ): string {
		$title = (string) get_the_title( $post );
		/* translators: %d: post ID, for content without a title. */
		return '' !== $title ? $title : sprintf( __( '#%d (untitled)', 'doubleyoupi-refined-admin-dashboard' ), $post->ID );
	}

	private static function ago( int $ts ): string {
		if ( $ts <= 0 ) {
			return '';
		}
		/* translators: %s: a time span, e.g. "3 days". */
		return sprintf( __( '%s ago', 'doubleyoupi-refined-admin-dashboard' ), human_time_diff( $ts, time() ) );
	}
}

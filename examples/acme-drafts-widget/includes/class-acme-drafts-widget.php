<?php
/**
 * "Drafts waiting": the current user's most recent drafts.
 *
 * Loaded only from the doubleyoupi_dashboard_widgets_registered callback,
 * once Refined Admin Dashboard has declared AbstractDataWidget.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

final class Acme_Drafts_Widget extends AbstractDataWidget {

	/** Stored in saved layouts: prefix it with your plugin slug and never change it. */
	public function id(): string {
		return 'acme_drafts';
	}

	/** Title of the card and of the palette tile. */
	public function name(): string {
		return __( 'Drafts waiting', 'acme-drafts-widget' );
	}

	/** Shown under the name in the palette. */
	public function description(): string {
		return __( 'Your most recent drafts, one click from the editor.', 'acme-drafts-widget' );
	}

	/** 24 × 24 line icon, stroke only: it takes the colour of the interface. */
	public function icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/></svg>';
	}

	/** Who may see the widget, in the palette and on the dashboard. Empty: everyone with dashboard access. */
	public function required_cap(): string {
		return 'edit_posts';
	}

	/** Width when first placed: 1 or 2 slots of a six-slot row. */
	public function recommended_slots(): int {
		return 2;
	}

	public function default_config(): array {
		return [ 'count' => '5' ];
	}

	/** Settings shown when the user selects the widget in customize mode. */
	public function config_schema(): array {
		return [
			[
				'key'     => 'count',
				'type'    => 'select',
				'label'   => __( 'Drafts shown', 'acme-drafts-widget' ),
				'options' => [
					[ 'value' => '3', 'label' => '3' ],
					[ 'value' => '5', 'label' => '5' ],
					[ 'value' => '8', 'label' => '8' ],
				],
			],
		];
	}

	/** Always return a clean array: never throw, never return a WP_Error. */
	public function validate_config( array $config ): array {
		$count = (string) ( $config['count'] ?? '5' );
		return [ 'count' => in_array( $count, [ '3', '5', '8' ], true ) ? $count : '5' ];
	}

	/** Server side, once per page load. Keep it fast; cache anything heavy. */
	public function get_data( array $config ): array {
		$posts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'author'         => get_current_user_id(),
				'posts_per_page' => (int) $config['count'],
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);
		$items = [];
		foreach ( $posts as $post ) {
			$items[] = [
				'title' => get_the_title( $post ) ?: __( '(no title)', 'acme-drafts-widget' ),
				'url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
				'when'  => human_time_diff( (int) get_post_modified_time( 'U', true, $post ), time() ),
			];
		}
		return [ 'items' => $items ];
	}

	/** Escaped HTML, built with the dashboard's card classes. No <script> or <style>: they are stripped. */
	public function render_html( array $config, array $data ): string {
		$items = (array) ( $data['items'] ?? [] );
		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php echo esc_html( $this->name() ); ?></span>
			<span class="dy-dashboard__pill"><?php echo esc_html( (string) count( $items ) ); ?></span>
		</div>
		<div class="dy-dashboard__card-body">
			<?php if ( ! $items ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No drafts. Nice.', 'acme-drafts-widget' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $items as $item ) : ?>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__dot dy-dashboard__dot--accent"></span>
					<a class="dy-dashboard__row-v" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
					<span class="dy-dashboard__row-k"><?php echo esc_html( $item['when'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		echo self::dy_foot_link( admin_url( 'edit.php?post_status=draft&post_type=post' ), __( 'All drafts', 'acme-drafts-widget' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper.
		return (string) ob_get_clean();
	}
}

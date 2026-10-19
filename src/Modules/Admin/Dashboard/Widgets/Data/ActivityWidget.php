<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Recent activity" widget — unified stream for the last 24 hours.
 *
 * Phase 1: published posts only. Other sources (plugin updates,
 * admin logins, uploaded media) are ready to wire in but disabled
 * by default (the hook relies on events not yet all available).
 */
final class ActivityWidget extends AbstractDataWidget {

	/** Lists drafts and scheduled posts from every author: editors and up only (they may edit others' posts). */
	public function required_cap(): string {
		return 'edit_others_posts';
	}

	public function id(): string {
		return 'activity';
	}

	public function name(): string {
		return __( 'Recent activity', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Posts and pages published or updated in the last 7 days.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Bulleted list of lines — timeline metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1.5"/><circle cx="4" cy="12" r="1.5"/><circle cx="4" cy="18" r="1.5"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );

		$items = $this->collect_recent_posts();

		// Sort descending by timestamp and cap at 8 entries.
		usort( $items, static fn( $a, $b ) => $b['ts'] - $a['ts'] );
		$items = array_slice( $items, 0, 8 );

		return [
			'items' => $items,
		];
	}

	/**
	 * Retrieves posts published or modified within the last 7 days
	 * (wide window to have content even on low-activity sites).
	 *
	 * @return array<int, array{ts:int, label:string, kind:string}>
	 */
	private function collect_recent_posts(): array {
		$query = new \WP_Query( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => [ 'publish', 'future', 'draft' ],
			'posts_per_page' => 8,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'date_query'     => [
				[ 'after' => '-7 days' ],
				'column' => 'post_modified_gmt',
			],
			'no_found_rows'  => true,
			'fields'         => 'all',
		] );

		$items = [];
		foreach ( $query->posts as $post ) {
			$ts    = (int) get_post_modified_time( 'U', true, $post );
			$title = (string) get_the_title( $post );
			$items[] = [
				'ts'    => $ts,
				// translators: %d is the post ID (shown when the post has no title).
				'label' => $title !== '' ? $title : sprintf( __( '#%d (untitled)', 'doubleyoupi-refined-admin-dashboard' ), $post->ID ),
				'kind'  => $post->post_status === 'publish' ? 'publish' : 'edit',
			];
		}
		return $items;
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		$items = is_array( $data['items'] ?? null ) ? $data['items'] : [];

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Recent activity', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<span class="dy-dashboard__card-meta">7j</span>
		</div>
		<div class="dy-dashboard__card-body">
			<?php if ( empty( $items ) ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No recent activity.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else : ?>
				<ul class="dy-dashboard__list">
					<?php foreach ( $items as $item ) : ?>
						<li class="dy-dashboard__list-item">
							<span class="dy-dashboard__list-time"><?php echo esc_html( self::human_time( (int) $item['ts'] ) ); ?></span>
							<span class="dy-dashboard__list-label"><?php echo esc_html( (string) $item['label'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function human_time( int $ts ): string {
		if ( $ts <= 0 ) {
			return '—';
		}
		$diff = time() - $ts;
		if ( $diff < HOUR_IN_SECONDS ) {
			$mins = max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) );
			// translators: %d is a number of minutes.
			return sprintf( __( '%d min ago', 'doubleyoupi-refined-admin-dashboard' ), $mins );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			$hrs = max( 1, (int) round( $diff / HOUR_IN_SECONDS ) );
			// translators: %d is a number of hours.
			return sprintf( __( '%d h ago', 'doubleyoupi-refined-admin-dashboard' ), $hrs );
		}
		$days = max( 1, (int) round( $diff / DAY_IN_SECONDS ) );
		// translators: %d is a number of days.
		return sprintf( __( '%d d ago', 'doubleyoupi-refined-admin-dashboard' ), $days );
	}
}

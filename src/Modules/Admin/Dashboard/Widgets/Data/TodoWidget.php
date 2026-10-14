<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "To handle" widget — drafts, scheduled posts, comments in moderation,
 * plugins awaiting update. Prominent counts with direct links to
 * the filtered admin lists.
 */
final class TodoWidget extends AbstractDataWidget {

	public function id(): string {
		return 'todo';
	}

	public function required_cap(): string {
		return 'edit_posts';
	}

	public function name(): string {
		return __( 'To handle', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Drafts, scheduled posts, comments in moderation, plugins awaiting update.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Checklist: square with checkmark.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );

		$drafts    = (int) wp_count_posts( 'post' )->draft;
		$drafts   += (int) wp_count_posts( 'page' )->draft;
		$scheduled = (int) wp_count_posts( 'post' )->future + (int) wp_count_posts( 'page' )->future;
		$pending_comments = (int) get_comments( [ 'count' => true, 'status' => 'hold' ] );

		if ( ! function_exists( 'wp_get_update_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$updates = wp_get_update_data();
		$pending_updates = (int) ( $updates['counts']['plugins'] ?? 0 )
			+ (int) ( $updates['counts']['themes'] ?? 0 )
			+ (int) ( $updates['counts']['wordpress'] ?? 0 );

		return [
			'drafts'           => $drafts,
			'scheduled'        => $scheduled,
			'pending_comments' => $pending_comments,
			'pending_updates'  => $pending_updates,
		];
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		$rows = [
			[ __( 'Drafts',       'doubleyoupi-refined-admin-dashboard' ), (int) ( $data['drafts'] ?? 0 ),           admin_url( 'edit.php?post_status=draft&post_type=post' ) ],
			[ __( 'Scheduled',       'doubleyoupi-refined-admin-dashboard' ), (int) ( $data['scheduled'] ?? 0 ),        admin_url( 'edit.php?post_status=future&post_type=post' ) ],
			[ __( 'In moderation',    'doubleyoupi-refined-admin-dashboard' ), (int) ( $data['pending_comments'] ?? 0 ), admin_url( 'edit-comments.php?comment_status=moderated' ) ],
			[ __( 'Updates',     'doubleyoupi-refined-admin-dashboard' ), (int) ( $data['pending_updates'] ?? 0 ),  admin_url( 'update-core.php' ) ],
		];

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'To handle', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
		</div>
		<div class="dy-dashboard__card-body">
			<?php foreach ( $rows as [ $label, $count, $url ] ) : ?>
				<div class="dy-dashboard__row">
					<a class="dy-dashboard__row-k" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
					<span class="dy-dashboard__row-v dy-dashboard__row-v--num"><?php echo esc_html( (string) $count ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

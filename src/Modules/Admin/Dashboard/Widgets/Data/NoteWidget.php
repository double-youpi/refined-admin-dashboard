<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Note" widget — a free-text block persisted in user_meta.
 *
 * Instanceable: multiple notes can be placed for categorisation
 * ("Today's tasks", "Maintenance notes", etc.).
 *
 * Persistence is handled server-side via the widget config (saved
 * in the layout) — no dedicated endpoint needed.
 */
final class NoteWidget extends AbstractDataWidget {

	public function id(): string {
		return 'note';
	}

	public function name(): string {
		return __( 'Note', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'A free text note saved on the dashboard. Multiple instances supported.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>';
	}

	public function is_instanceable(): bool {
		return true;
	}

	public function default_config(): array {
		return [
			'title' => __( 'My note', 'doubleyoupi-refined-admin-dashboard' ),
			'body'  => '',
		];
	}

	// The Note is NOT configurable via the Customize panel: it is edited
	// directly in place by clicking on it (title + body). Empty schema →
	// not managed by the config panel.

	public function validate_config( array $config ): array {
		return [
			'title' => sanitize_text_field( (string) ( $config['title'] ?? '' ) ),
			'body'  => wp_kses_post( (string) ( $config['body'] ?? '' ) ),
		];
	}

	public function render_html( array $config, array $data ): string {
		unset( $data );
		$title = (string) ( $config['title'] ?? '' );
		$body  = (string) ( $config['body'] ?? '' );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php echo esc_html( $title !== '' ? $title : __( 'Note', 'doubleyoupi-refined-admin-dashboard' ) ); ?></span>
		</div>
		<div class="dy-dashboard__card-body dy-dashboard__note-body">
			<?php if ( trim( $body ) === '' ) : ?>
				<p class="dy-dashboard__note-empty"><?php esc_html_e( 'Click Customize to edit this note.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else : ?>
				<div class="dy-dashboard__note-content"><?php echo wp_kses_post( wpautop( $body ) ); ?></div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

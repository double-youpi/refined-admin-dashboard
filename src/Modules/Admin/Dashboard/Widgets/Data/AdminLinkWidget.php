<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Admin link" widget — a single link button to an admin page.
 * 1 slot recommended; instanceable to allow multiple shortcuts.
 *
 * Differs from ShortcutsWidget: Shortcuts displays a compact list of
 * links, whereas here a single large, prominent link occupies the slot.
 */
final class AdminLinkWidget extends AbstractDataWidget {

	public function id(): string {
		return 'admin_link';
	}

	public function name(): string {
		return __( 'Admin link', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Single shortcut button to an admin page.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>';
	}

	public function is_instanceable(): bool {
		return true;
	}

	public function recommended_slots(): int {
		return 1;
	}

	public function default_config(): array {
		return [
			'label'   => '',
			'url'     => admin_url( 'options-general.php' ),
			'new_tab' => false,
		];
	}

	public function config_schema(): array {
		return [
			[ 'type' => 'info', 'text' => __( 'A shortcut button to a specific admin (or external) page.', 'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'key' => 'label', 'type' => 'text', 'label' => __( 'Label', 'doubleyoupi-refined-admin-dashboard' ), 'placeholder' => __( 'Open', 'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'key' => 'url', 'type' => 'url', 'label' => __( 'URL', 'doubleyoupi-refined-admin-dashboard' ), 'placeholder' => admin_url( 'options-general.php' ) ],
			[ 'key' => 'new_tab', 'type' => 'checkbox', 'label' => __( 'Open in a new tab', 'doubleyoupi-refined-admin-dashboard' ) ],
		];
	}

	public function validate_config( array $config ): array {
		return [
			'label'   => sanitize_text_field( (string) ( $config['label'] ?? '' ) ),
			'url'     => esc_url_raw( (string) ( $config['url'] ?? '' ) ),
			'new_tab' => ! empty( $config['new_tab'] ),
		];
	}

	public function render_html( array $config, array $data ): string {
		unset( $data );
		$label   = (string) ( $config['label'] ?? '' );
		$url     = (string) ( $config['url'] ?? '' );
		$new_tab = ! empty( $config['new_tab'] );
		if ( $label === '' ) {
			$label = __( 'Open', 'doubleyoupi-refined-admin-dashboard' );
		}

		ob_start();
		?>
		<a class="dy-dashboard__admin-link" href="<?php echo esc_url( $url ); ?>"<?php echo $new_tab ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
			<span class="dy-dashboard__admin-link-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
			</span>
			<span class="dy-dashboard__admin-link-label"><?php echo esc_html( $label ); ?></span>
		</a>
		<?php
		return (string) ob_get_clean();
	}
}

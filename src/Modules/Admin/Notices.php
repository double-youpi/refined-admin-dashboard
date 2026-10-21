<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Core\Build;
use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notices — centralizes ALL admin notices (WP core + third-party plugins,
 * current and future) into a "Notifications" badge with a counter, styled
 * like an e-commerce cart indicator.
 *
 * Instead of cluttering the top of every page, notices are intercepted
 * client-side (DOM capture + MutationObserver for late injections)
 * and moved into a centered, scrollable popup as wide as the content
 * container (`.wrap`). A live counter is shown in the dock anchored to
 * the right of the chipbar.
 *
 * Special case (product decision): contextual action feedback —
 * success/error immediately after a form submit ("Settings saved", validation
 * errors) — ALSO triggers an ephemeral micro-toast to confirm the action
 * without reintroducing a permanent banner at the top of the page.
 *
 * Responsibilities:
 *   - PHP: renders the dock (badge) in the header + the `<dialog>` shell in
 *     the footer, enqueues the JS and provides i18n strings.
 *   - JS (`notices.js`): capture, counter, open/close, passthrough of each
 *     plugin's native dismiss, toast classification.
 *
 * Fully automatic — no settings (curated manifesto §2). Inert outside the
 * apply-gate: the class is only booted from `Admin::maybe_boot_redesign`.
 */
final class Notices {

	/**
	 * Screens where the notices centre must be suppressed entirely:
	 *   - builders with externally managed UI (Elementor…) that handle their own admin;
	 *   - the enhanced Dashboard, which relocates notices itself into its
	 *     "native WordPress" screen (last in the carousel). Without this, the dock
	 *     and the dashboard would compete for the same `.notice` nodes → a ghost
	 *     badge with an empty popup.
	 */
	private static function is_suppressed(): bool {
		return BuilderScreen::is_managed() || Dashboard\Dashboard::is_current_screen();
	}

	public function boot(): void {
		// Dock (badge) — priority 5: just before the Chipbar (6) to anchor
		// the badge in the same header bar, to the right of the content column.
		add_action( 'in_admin_header', [ $this, 'render_dock' ], 5 );

		// `<dialog>` shell — in the footer to live outside the scrollable
		// `#wpcontent` container (showModal() projects it into the top layer,
		// so DOM position has no visual impact). Captured notices are moved
		// into it by the JS.
		add_action( 'admin_footer', [ $this, 'render_modal' ] );

		// Assets — priority 21: after the adopter (20) and after the
		// `doubleyoupi-admin` handle is registered (which we depend on for load order).
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ], 21 );
	}

	/**
	 * Persistent dock: "Notifications" button + counter, anchored to the right
	 * of the chipbar (or top-right of the content area on chipbar-less screens,
	 * e.g. the dashboard). The actual counter is set by the JS after capture;
	 * we render `data-count="0"` to avoid any flash.
	 */
	public function render_dock(): void {
		// Screens that manage notices themselves (externalized builder, enhanced
		// Dashboard): suppress entirely, no dock.
		if ( self::is_suppressed() ) {
			return;
		}
		// Inline bell glyph (self-contained — no dependency on the sprite, which
		// may be conditionally injected depending on the screen).
		$bell = '<svg class="dy-notice-trigger__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';

		printf(
			'<div class="dy-notice-dock" id="dy-notice-dock" data-empty="true">'
			. '<div class="dy-notice-dock__inner">'
			. '<button type="button" class="dy-notice-trigger" id="dy-notice-trigger"'
			. ' aria-haspopup="dialog" aria-controls="dy-notices-modal" aria-expanded="false"'
			. ' data-count="0" title="%1$s">'
			. '%2$s'
			. '<span class="dy-notice-trigger__label">%3$s</span>'
			. '<span class="dy-notice-trigger__count">0</span>'
			. '</button>'
			. '</div>'
			. '</div>',
			esc_attr__( 'Notifications', 'doubleyoupi-refined-admin-dashboard' ),
			wp_kses( $bell, Kses::svg_tags() ),
			esc_html__( 'Notifications', 'doubleyoupi-refined-admin-dashboard' )
		);
	}

	/**
	 * Modal shell. The native `<dialog>` provides backdrop, focus-trap and
	 * Esc-to-close for free. The `#dy-notices-list` list receives notices
	 * moved by the JS; the empty-state is shown while it is empty.
	 * The toast-host holds the ephemeral micro-toasts.
	 */
	public function render_modal(): void {
		if ( self::is_suppressed() ) {
			return;
		}
		?>
<dialog class="dy-notices-modal" id="dy-notices-modal" aria-labelledby="dy-notices-modal-title">
	<div class="dy-notices-modal__chrome">
		<header class="dy-notices-modal__head">
			<div class="dy-notices-modal__heading">
				<h2 class="dy-notices-modal__title" id="dy-notices-modal-title"><?php esc_html_e( 'Notifications', 'doubleyoupi-refined-admin-dashboard' ); ?></h2>
			</div>
			<div class="dy-notices-modal__actions">
				<span class="dy-notices-modal__counter" id="dy-notices-modal-counter" aria-hidden="true">0</span>
				<button type="button" class="dy-notices-modal__clear" id="dy-notices-clear" hidden><?php esc_html_e( 'Dismiss all', 'doubleyoupi-refined-admin-dashboard' ); ?></button>
				<button type="button" class="dy-notices-modal__close" id="dy-notices-close" aria-label="<?php esc_attr_e( 'Close', 'doubleyoupi-refined-admin-dashboard' ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
				</button>
			</div>
		</header>
		<div class="dy-notices-modal__body">
			<div class="dy-notices-modal__list" id="dy-notices-list" role="region" aria-label="<?php esc_attr_e( 'Captured notifications', 'doubleyoupi-refined-admin-dashboard' ); ?>"></div>
			<div class="dy-notices-modal__empty" id="dy-notices-empty">
				<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
				<p class="dy-notices-modal__empty-title"><?php esc_html_e( 'You are all caught up', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
				<p class="dy-notices-modal__empty-sub"><?php esc_html_e( 'Notifications from WordPress and plugins land here instead of cluttering the top of the page.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			</div>
		</div>
	</div>
</dialog>
<div class="dy-notice-toast-host" id="dy-notice-toast-host" aria-live="polite" aria-atomic="false"></div>
		<?php
	}

	public function enqueue(): void {
		if ( self::is_suppressed() ) {
			return;
		}
		$path_abs = Build::dir( __FILE__, 'assets/js/modules/admin/notices.js' );
		if ( ! is_readable( $path_abs ) ) {
			return;
		}

		$handle = 'doubleyoupi-admin-notices';
		$deps   = wp_script_is( 'doubleyoupi-admin', 'registered' ) ? [ 'doubleyoupi-admin' ] : [];

		wp_enqueue_script(
			$handle,
			Build::url( __FILE__, 'assets/js/modules/admin/notices.js' ),
			$deps,
			self::asset_version( $path_abs ),
			true
		);

		wp_localize_script(
			$handle,
			'doubleyoupiNotices',
			[
				'i18n' => [
					'title'      => __( 'Notifications', 'doubleyoupi-refined-admin-dashboard' ),
					/* translators: %d is the number of notifications. */
					'countLabel' => __( '%d notifications', 'doubleyoupi-refined-admin-dashboard' ),
					'one'        => __( '1 notification', 'doubleyoupi-refined-admin-dashboard' ),
					'empty'      => __( 'No notifications', 'doubleyoupi-refined-admin-dashboard' ),
					'dismissed'  => __( 'Notification dismissed', 'doubleyoupi-refined-admin-dashboard' ),
				],
			]
		);
	}

	/**
	 * Cache-buster aligned with {@see Admin::asset_version}.
	 */
	private static function asset_version( string $abs_path ): string {
		$is_dev = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			|| str_ends_with( (string) DOUBLEYOUPI_VERSION, '-dev' );
		if ( $is_dev ) {
			$mtime = @filemtime( $abs_path );
			if ( $mtime ) {
				return (string) $mtime;
			}
		}
		return (string) DOUBLEYOUPI_VERSION;
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ViewTransitions — production wiring for the cross-document View Transitions API.
 *
 * The @view-transition CSS rule is compiled into admin.css (partial
 * _view-transitions.scss). On the PHP side, we only need to:
 *
 *   1. Output the pre-paint script BEFORE the first paint (admin_head hook
 *      priority 0) — it reads sessionStorage and sets html[data-nav-dir] so
 *      that directional animations start correctly.
 *
 *   2. EXCLUDE certain screens from transitions because they have their own
 *      iframe-based chrome: Customizer, Site Editor, Block Editor.
 *      On these screens we skip the pre-paint script AND inject a CSS override
 *      that disables `@view-transition`.
 *
 * The direction sniff (click handler) lives on the JS side in admin.js — no
 * additional PHP is needed. The handoff for the arrival wipe
 * (sessionStorage `dy-cursor-arrival` set by admin.js before window.location.href)
 * is read by `prepaint_script()`.
 */
final class ViewTransitions {

	public function boot(): void {
		add_action( 'admin_head', [ $this, 'output' ], 0 );
	}

	public function output(): void {
		// Printed on the spot through the WordPress APIs (register + inline +
		// print), because the order matters: the rule must sit in <head> before
		// the first paint, and the pre-paint script right after it.
		wp_register_style( 'doubleyoupi-admin-view-transitions', false, [], DOUBLEYOUPI_VERSION );

		if ( $this->is_excluded_screen() ) {
			// Disable the @view-transition rule on this particular screen.
			wp_add_inline_style( 'doubleyoupi-admin-view-transitions', '@view-transition{navigation:none}' );
			wp_print_styles( 'doubleyoupi-admin-view-transitions' );
			return;
		}

		// Inline opt-in — Chrome requires the @view-transition rule to be
		// present in the CSSOM AT NAVIGATION COMMIT TIME. If it lives only
		// in admin.css loaded via <link>, timing depends on the CSS fetch.
		// For reliability, we duplicate it INLINE at priority 0 in <head>,
		// just before the prepaint script. Same rule, declared twice, no side effects.
		wp_add_inline_style( 'doubleyoupi-admin-view-transitions', '@view-transition{navigation:auto}' );
		wp_print_styles( 'doubleyoupi-admin-view-transitions' );
		wp_print_inline_script_tag( $this->prepaint_script(), [ 'id' => 'doubleyoupi-admin-vt-prepaint' ] );
	}

	/**
	 * Screens that MUST be excluded from transitions (alternative chrome).
	 */
	private function is_excluded_screen(): bool {
		global $pagenow;

		// Customizer and Site Editor — iframe-based, alternative chrome.
		if ( in_array( $pagenow, [ 'customize.php', 'site-editor.php', 'theme-editor.php', 'plugin-editor.php' ], true ) ) {
			return true;
		}

		// Block editor — post.php and post-new.php when the post type uses Gutenberg.
		if ( in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
				return true;
			}
		}

		return false;
	}

	private function prepaint_script(): string {
		return <<<'JS'
(function () {
	try {
		var raw = sessionStorage.getItem('dy-nav-dir');
		if (raw) {
			var d = JSON.parse(raw);
			if (d && d.dir && (Date.now() - d.t) < 1500) {
				document.documentElement.dataset.navDir = d.dir;
			}
			sessionStorage.removeItem('dy-nav-dir');
		}

		// Wipe handoff phase 4: if the outgoing page has finished its
		// wipe-out and stored `dy-cursor-arrival`, immediately set
		// `data-dy-cursor="wipe-in"` BEFORE the first paint so that:
		//   - the H1 arrives with its covering eraser (invisible title)
		//   - the bar arrives at the left
		//   - the content arrives pre-blurred
		// Then the `dy-cursor-wipe-in` animation (CSS, 300ms) plays on
		// its own to reveal the title + content. After 300+50ms we switch
		// to "reveal" (150ms transition so the bar fades back to opacity 0.5),
		// then clean up the attribute so the blinking resumes naturally.
		var cursorArrivalRaw = sessionStorage.getItem('dy-cursor-arrival');
		if (cursorArrivalRaw) {
			var cursorArrival = JSON.parse(cursorArrivalRaw);
			if (cursorArrival && (Date.now() - cursorArrival.t) < 2000) {
				document.documentElement.dataset.dyCursor = 'wipe-in';
				// After the wipe-in animation has finished (~300ms),
				// swap to "reveal" for the opacity transition toward
				// the blinking state.
				setTimeout(function () {
					document.documentElement.dataset.dyCursor = 'reveal';
					setTimeout(function () {
						delete document.documentElement.dataset.dyCursor;
					}, 150);
				}, 320);
			}
			sessionStorage.removeItem('dy-cursor-arrival');
		}

		// Pre-position the .dy-track before the first paint so that the
		// cross-document VT (view-transition-name: dy-vt-chip-track)
		// interpolates from the position clicked on the PREVIOUS PAGE to
		// the position of the current chip ON THE NEW PAGE — instead of
		// starting from translateX(0) then snapping via admin.js after load.
		var trackRaw = sessionStorage.getItem('dy-track-target');
		if (trackRaw) {
			var t = JSON.parse(trackRaw);
			if (t && (Date.now() - t.t) < 1500) {
				var selector = t.kind === 'sub'
					? 'body.dy-admin .dy-subchipbar .dy-track'
					: 'body.dy-admin .dy-chipbar .dy-track';
				var s = document.createElement('style');
				s.id = 'dy-track-prepos';
				// The selector !important is sufficient: the initial value at first
				// paint is already translateX(x) — no CSS transition fires because
				// the value has not "changed" in the :before/:after sense.
				// JS can then call move() with a transition.
				s.textContent = selector + '{transform:translateX(' + t.x + 'px) !important;width:' + t.w + 'px !important;}';
				document.head.appendChild(s);
				// Once the DOM is ready, remove the frozen style so that
				// admin.js (which sets inline styles) can drive the track
				// freely with native CSS transitions.
				document.addEventListener('DOMContentLoaded', function () {
					var n = document.getElementById('dy-track-prepos');
					if (n) n.remove();
				}, { once: true });
			}
			sessionStorage.removeItem('dy-track-target');
		}
	} catch (e) {}
})();
JS;
	}
}

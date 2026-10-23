<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EdgeBleed: brings back plugin layouts that slide under the rail.
 *
 * WordPress leaves 20 px of padding left of the content (#wpcontent), and
 * many plugins stretch into it with `margin-left: -20px` to touch the menu's
 * edge. Our layout has no such padding, so those blocks slid 20 px under the
 * rail and lost their left edge (Independent Analytics: logo and sidebar cut;
 * translator feedback, 23/10/2026).
 *
 * The script finds the top-level blocks of the content that start left of
 * it through a negative margin, and marks them `dy-bleed`; their margin and
 * width are reset so they fill the content exactly (_layout.scss). Nothing
 * else moves.
 */
final class EdgeBleed {

	private const JS = <<<'JS'
(function () {
	var root = document.getElementById('wpbody-content');
	if (!root || !document.body.classList.contains('dy-admin')) { return; }
	function check() {
		var edge = root.getBoundingClientRect().left;
		var blocks = root.querySelectorAll(':scope > *, :scope > .wrap > *');
		for (var i = 0; i < blocks.length; i++) {
			var el = blocks[i];
			if (el.classList.contains('dy-bleed')) { continue; }
			var r = el.getBoundingClientRect();
			if (r.width < 100 || r.left >= edge - 1) { continue; }
			if (parseFloat(getComputedStyle(el).marginLeft) < 0) { el.classList.add('dy-bleed'); }
		}
	}
	check();
	window.addEventListener('load', check);
	new MutationObserver(function () { window.requestAnimationFrame(check); }).observe(root, { childList: true });
})();
JS;

	public function boot(): void {
		add_action( 'admin_footer', [ $this, 'print_script' ], 3 );
	}

	public function print_script(): void {
		wp_print_inline_script_tag( self::JS, [ 'id' => 'doubleyoupi-edge-bleed' ] );
	}
}

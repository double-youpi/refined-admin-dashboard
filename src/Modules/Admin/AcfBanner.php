<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AcfBanner — manages the two ACF chrome bars (`.acf-admin-toolbar` + `.acf-headerbar`)
 * on ACF screens (field groups / post types / taxonomies / options / tools / updates).
 *
 * ACF renders TWO chrome surfaces above the H1:
 *   1. `.acf-admin-toolbar` : full-width bar with ACF Pro logo + WP Engine logo
 *      + tabs (Groups/Types/Taxonomies/More). The tabs duplicate our subchipbar.
 *   2. `.acf-headerbar` : sub-bar with the page title + "Add" button.
 *      The title duplicates our H1; the button duplicates
 *      the native WP `.page-title-action` (which ACF hides via its own CSS).
 *
 * Strategy:
 *   - We EXTRACT `.acf-logo` and `.acf-nav-wpengine-logo` from the toolbar and
 *     reinsert them in a `.dy-acf-logos` container floated right, aligned with the H1.
 *   - We HIDE both bars entirely via CSS (see _wp-production.scss).
 *   - We do NOT clone the ACF button: a CSS override `display: inline-flex
 *     !important` (see _layout.scss) forces the native WP `.page-title-action`
 *     to stay visible, better styled + correct i18n.
 *
 * Why JS instead of PHP: ACF renders its bars via PHP hooks that we cannot
 * control without coupling to its API. JS at DOMContentLoaded is robust as long
 * as the selectors `.acf-admin-toolbar`, `.acf-logo` and
 * `.acf-nav-wpengine-logo` remain stable (confirmed stable on ACF 6.x).
 */
final class AcfBanner {

	private const ACF_BANNER_RELOCATE_JS = <<<'JS'
(function () {
	if (!document.body.classList.contains('dy-admin')) return;
	function relocate() {
		// Logos live in `.acf-admin-toolbar` (NOT in `.acf-headerbar`).
		// Fallback to document-level query for new ACF screens where the markup changes.
		var toolbar   = document.querySelector('.acf-admin-toolbar');
		var headerbar = document.querySelector('.acf-headerbar');
		if (!toolbar && !headerbar) return;

		var logoAcf      = (toolbar && toolbar.querySelector('.acf-logo'))
			|| document.querySelector('.acf-admin-toolbar .acf-logo, .acf-headerbar .acf-logo');
		var logoWpengine = (toolbar && toolbar.querySelector('.acf-nav-wpengine-logo'))
			|| document.querySelector('.acf-admin-toolbar .acf-nav-wpengine-logo, .acf-headerbar .acf-nav-wpengine-logo');

		var wrap = document.querySelector('.wrap');
		var h1   = wrap && wrap.querySelector('h1');
		if (!wrap || !h1) return;

		// Skip if already relocated (admin_footer may fire twice in AJAX context).
		if (wrap.querySelector('.dy-acf-logos')) return;

		// Logos floated right, aligned with the H1 via absolute positioning.
		if (logoAcf || logoWpengine) {
			var logos = document.createElement('div');
			logos.className = 'dy-acf-logos';
			if (logoAcf)      { logos.appendChild(logoAcf.cloneNode(true)); }
			if (logoWpengine) { logos.appendChild(logoWpengine.cloneNode(true)); }
			h1.parentNode.insertBefore(logos, h1.nextSibling);
		}

		// Mark elements for CSS (the `display: none` rules target the original
		// classes — the `dy-acf-headerbar-relocated` class is legacy, kept for compatibility).
		if (toolbar)   { toolbar.classList.add('dy-acf-toolbar-relocated'); }
		if (headerbar) { headerbar.classList.add('dy-acf-headerbar-relocated'); }
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', relocate);
	} else {
		relocate();
	}
})();
JS;


	public function boot(): void {
		add_action( 'admin_footer', [ $this, 'output_relocate_script' ] );
	}

	public function output_relocate_script(): void {
		wp_print_inline_script_tag( self::ACF_BANNER_RELOCATE_JS, [ 'id' => 'doubleyoupi-acf-banner-relocate' ] );
	}
}

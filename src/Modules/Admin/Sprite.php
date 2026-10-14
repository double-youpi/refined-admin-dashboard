<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sprite — inline SVG icon library for the Admin module.
 *
 * Output as a hidden <svg> at the start of <body> containing
 * <symbol id="i-xxx"> elements referenced by <svg><use href="#i-xxx"/></svg>
 * throughout the rail / chipbar / adminbar.
 *
 * All icons use stroke="currentColor" to inherit the color of their
 * container (line color, accent, etc.).
 *
 * Families:
 *   - Generic UI (i-wp, i-update, i-comment, i-plus, i-sun, i-moon)
 *   - Lines (7): i-line-content/commerce/users/community/site/system/developer
 *   - Builders (9): i-builder-* (Elementor, Bricks, Divi, Beaver, Brizy, Oxygen,
 *     WPBakery, Visual Composer, SeedProd)
 *   - Themes (18): i-theme-* (Astra, GeneratePress, Kadence, Blocksy, OceanWP,
 *     Neve, Hestia, Avada, Genesis, Salient, The7, BeTheme, Bridge, Enfold,
 *     Newspaper, Soledad, JupiterX, Phlox)
 *   - Plugins (13): i-plugin-* (Woo, Yoast, RankMath, GravityForms, WPForms,
 *     UpdraftPlus, Wordfence, WPRocket, LiteSpeed, LearnDash, BuddyBoss,
 *     MailPoet, FluentCRM)
 *
 * Design conventions:
 *   - viewBox 0 0 24 24
 *   - stroke="currentColor", fill="none"
 *   - stroke-width 1.4–1.6 depending on desired visual density
 *   - Builders + Themes: rect 18×18 rx=3 or rx=4 (tile-like)
 *   - Plugins: freeform glyph, no frame, for visual distinction
 */
final class Sprite {

	public function boot(): void {
		// IMPORTANT: do NOT inject the SVG sprite into `<head>`. The HTML5
		// parser would leave "in head" mode upon seeing `<svg>` (which is
		// not a valid head element), would create an implicit `<body>` WITHOUT
		// attributes, and would later silently ignore the attributes of the
		// real `<body class="...">` (see "in body" rules → start tag body:
		// "for each attribute, add only if not already present"). Result:
		// WP classes lost.
		//
		// We therefore emit the sprite inside the body via `in_admin_header`
		// priority 1 — early enough for the `<use href="#i-..."/>` elements
		// in chip-bars / admin bar / rail to resolve at paint time (SVG <use>
		// elements re-resolve when a matching symbol arrives in the DOM).
		add_action( 'in_admin_header', [ $this, 'inject' ], 1 );
	}

	public function inject(): void {
		echo wp_kses( $this->markup(), Kses::svg_tags() );
	}

	public function markup(): string {
		return <<<'SVG'
<svg width="0" height="0" style="position:absolute" aria-hidden="true" id="doubleyoupi-admin-sprite"><defs>
<!-- ── UI ────────────────────────────────────────────────── -->
<symbol id="i-wp" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9.5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M3 12 L8 22 L12 6 L13 22 L21 12" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></symbol>
<symbol id="i-update" viewBox="0 0 24 24"><path d="M3 12a9 9 0 0 1 15.5-6.3M21 12a9 9 0 0 1-15.5 6.3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M18 3v4h-4M6 21v-4h4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-comment" viewBox="0 0 24 24"><path d="M4 5h16v11H10l-5 4v-4H4z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="i-moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></symbol>
<symbol id="i-grip" viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.3" fill="currentColor"/><circle cx="15" cy="6" r="1.3" fill="currentColor"/><circle cx="9" cy="12" r="1.3" fill="currentColor"/><circle cx="15" cy="12" r="1.3" fill="currentColor"/><circle cx="9" cy="18" r="1.3" fill="currentColor"/><circle cx="15" cy="18" r="1.3" fill="currentColor"/></symbol>
<!-- ── Lines (7) ─────────────────────────────────────────── -->
<symbol id="i-line-content" viewBox="0 0 24 24"><path d="M5 4h10l4 4v12H5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M15 4v4h4M8 12h8M8 16h5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="i-line-commerce" viewBox="0 0 24 24"><path d="M4 5h2l1.5 11H19l1.5-8H8" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="9.5" cy="20" r="1.3" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="17" cy="20" r="1.3" fill="none" stroke="currentColor" stroke-width="1.6"/></symbol>
<symbol id="i-line-users" viewBox="0 0 24 24"><circle cx="12" cy="9" r="3.2" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M5 20a7 7 0 0 1 14 0" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="i-line-community" viewBox="0 0 24 24"><circle cx="12" cy="6" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="6" cy="16" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="18" cy="16" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M10 7.5l-2.5 6M14 7.5l2.5 6M8.5 16h7" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
<symbol id="i-line-site" viewBox="0 0 24 24"><path d="M4 7l8-3 8 3v3l-8 3-8-3z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M4 13l8 3 8-3M4 17l8 3 8-3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></symbol>
<symbol id="i-line-system" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="i-line-developer" viewBox="0 0 24 24"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 5l-4 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<!-- ── Builders (9) ──────────────────────────────────────── -->
<symbol id="i-builder-elementor" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9 8v8M13 8h2M13 12h2M13 16h2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="i-builder-bricks" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9 7v10h3.5a2.5 2.5 0 0 0 0-5H9M9 12h3a2.5 2.5 0 0 0 0-5H9" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-builder-divi" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9 7h3a5 5 0 0 1 0 10H9z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></symbol>
<symbol id="i-builder-beaver" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 8l2 8 2-6 2 6 2-8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-builder-brizy" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9 7v10h3a3 3 0 0 0 0-6 2.5 2.5 0 0 0 0-4z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-builder-oxygen" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.6"/></symbol>
<symbol id="i-builder-wpbakery" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M7 8l2 8 1.5-5L12 16l1.5-5L15 16l2-8" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></symbol>
<symbol id="i-builder-vc" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M7 8l3 8 3-8M16 9a3 3 0 1 0 0 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-builder-seedprod" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v10M9 10l3-3 3 3M9 14l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<!-- ── Themes (18) — rect rx=4 to distinguish from builders ── -->
<symbol id="i-theme-astra" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 17l4-10 4 10M9.5 14h5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/></symbol>
<symbol id="i-theme-generatepress" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M16 9a5 5 0 1 0 0 6h-5v-3h5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-theme-kadence" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 7v10M8 12l5-5M8 12l5 5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></symbol>
<symbol id="i-theme-blocksy" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="7" y="7" width="4" height="4" fill="none" stroke="currentColor" stroke-width="1.4"/><rect x="13" y="7" width="4" height="4" fill="none" stroke="currentColor" stroke-width="1.4"/><rect x="7" y="13" width="10" height="4" fill="none" stroke="currentColor" stroke-width="1.4"/></symbol>
<symbol id="i-theme-oceanwp" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="16" cy="9" r="1.8" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M5 16c2 0 2-2 4-2s2 2 4 2 2-2 4-2 2 2 2 2" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
<symbol id="i-theme-neve" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 17V7l8 10V7" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-theme-hestia" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 7c0 3 4 2 4 5s-2 5-4 5-4-2-4-5c0-2 2-2 2-4 1 1 2 1 2-1z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-theme-avada" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 6.5l5 5.5-5 5.5-5-5.5z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-theme-genesis" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M10 12c-2 0-3 1-3 2.5s1 2.5 2.5 2.5 3-1.5 5-3 3.5-3 5-3 2.5 1 2.5 2.5-1 2.5-3 2.5" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></symbol>
<symbol id="i-theme-salient" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M6 17l4-7 3 4 4-6 3 9z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-theme-the7" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 8h9l-4 9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-theme-betheme" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 17V7h3a2.5 2.5 0 0 1 0 5H8M8 12h4a2.5 2.5 0 0 1 0 5H8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-theme-bridge" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M5 15c3.5 0 3.5-5 7-5s3.5 5 7 5M7 15v3M12 14v4M17 15v3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
<symbol id="i-theme-enfold" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9 7v10M9 7h7M9 12h5M9 17h7" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></symbol>
<symbol id="i-theme-newspaper" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="6" y="8" width="12" height="9" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M8 11h4M8 13h4M8 15h4" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><rect x="13.5" y="10.5" width="3" height="4" fill="none" stroke="currentColor" stroke-width="1.2"/></symbol>
<symbol id="i-theme-soledad" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9 7v10l3-2 3 2V7z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-theme-jupiterx" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="3.5" fill="none" stroke="currentColor" stroke-width="1.5"/><ellipse cx="12" cy="12" rx="6.5" ry="2" fill="none" stroke="currentColor" stroke-width="1.3" transform="rotate(-20 12 12)"/></symbol>
<symbol id="i-theme-phlox" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="1.4" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M12 6.5c-1.8 1.8-1.8 3.5 0 4.5 1.8-1 1.8-2.7 0-4.5zM12 17.5c-1.8-1.8-1.8-3.5 0-4.5 1.8 1 1.8 2.7 0 4.5zM6.5 12c1.8-1.8 3.5-1.8 4.5 0-1 1.8-2.7 1.8-4.5 0zM17.5 12c-1.8-1.8-3.5-1.8-4.5 0 1 1.8 2.7 1.8 4.5 0z" fill="none" stroke="currentColor" stroke-width="1.3"/></symbol>
<!-- ── Plugins (13) — no frame, freeform glyph ────────────── -->
<symbol id="i-plugin-woo" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M5.5 9.5l1.5 5 2-4 2 4 1.5-5M14 9.5c0 3 2 5 4 5M16 12.5h3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-plugin-yoast" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M8 7l4 7 4-7M12 14v3M9 18l2 2 4-4" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-plugin-rankmath" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M8 17V7h3.5a2.5 2.5 0 0 1 0 5H8M11 12l3 5M13 9l4-4M14 5h3v3" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-plugin-gravityforms" viewBox="0 0 24 24"><path d="M6 4h12v16H6z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M9 8h6M9 12h6M9 16h4" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
<symbol id="i-plugin-wpforms" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="8" y="8" width="8" height="2.5" fill="none" stroke="currentColor" stroke-width="1.3"/><rect x="8" y="13" width="8" height="2.5" fill="none" stroke="currentColor" stroke-width="1.3"/></symbol>
<symbol id="i-plugin-updraftplus" viewBox="0 0 24 24"><path d="M7 16a4 4 0 0 1 1-7.9 5 5 0 0 1 9.5 0A3.5 3.5 0 0 1 17 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 20V11M8.5 14L12 11l3.5 3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-plugin-wordfence" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-4 8-8 9-4-1-8-4-8-9V6z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M9 12l2 2 4-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-plugin-wprocket" viewBox="0 0 24 24"><path d="M12 3c5 5 5 10 2 13l-2 3-2-3c-3-3-3-8 2-13z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="12" cy="10" r="1.5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M9 17l-2 3M15 17l2 3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
<symbol id="i-plugin-litespeed" viewBox="0 0 24 24"><path d="M13 3L5 14h5l-2 7 9-11h-5z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-plugin-learndash" viewBox="0 0 24 24"><path d="M3 9l9-4 9 4-9 4z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 11v5c0 2 5 3 5 3s5-1 5-3v-5M21 9v6" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
<symbol id="i-plugin-buddyboss" viewBox="0 0 24 24"><circle cx="8" cy="9" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="16" cy="9" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M3 19a5 5 0 0 1 10 0M11 19a5 5 0 0 1 10 0" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
<symbol id="i-plugin-mailpoet" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M3 8l9 6 9-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-plugin-fluentcrm" viewBox="0 0 24 24"><path d="M4 5h16l-6 7v7l-4-2v-5z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<!-- ── Editor UI (reorganizer) ──────────────────────────────── -->
<symbol id="i-edit" viewBox="0 0 24 24"><path d="M4 20h4l10-10-4-4L4 16z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="i-palette" viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 0 18c1.4 0 2-1 2-1.8 0-.6-.4-1-.4-1.6 0-.7.6-1.2 1.3-1.2H16a5 5 0 0 0 5-5c0-4.4-4-8.4-9-8.4z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="7.5" cy="11" r="1.1" fill="currentColor"/><circle cx="10" cy="7.5" r="1.1" fill="currentColor"/><circle cx="14.5" cy="7.5" r="1.1" fill="currentColor"/><circle cx="17" cy="11" r="1.1" fill="currentColor"/></symbol>
<symbol id="i-eye-off" viewBox="0 0 24 24"><path d="M4 12s3-6 8-6c1.7 0 3.2.7 4.4 1.6M20 12s-3 6-8 6c-1.7 0-3.2-.7-4.4-1.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="12" cy="12" r="2.2" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M4 4l16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="i-restore" viewBox="0 0 24 24"><path d="M5 12a7 7 0 1 1 2 5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M5 8v4h4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12l4 4 10-10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
<symbol id="i-chevron" viewBox="0 0 24 24"><path d="M8 5l8 7-8 7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<!-- ── Pickable group icons (curated DY set, i-pick-*) ────────── -->
<symbol id="i-pick-folder" viewBox="0 0 24 24"><path d="M4 7a2 2 0 0 1 2-2h3l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-star" viewBox="0 0 24 24"><path d="M12 4l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 9.7l5.4-.8z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-heart" viewBox="0 0 24 24"><path d="M12 20s-7-4.3-7-9.3A3.7 3.7 0 0 1 12 7a3.7 3.7 0 0 1 7 3.7c0 5-7 9.3-7 9.3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-bolt" viewBox="0 0 24 24"><path d="M13 3L5 14h5l-1 7 9-12h-6z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-flag" viewBox="0 0 24 24"><path d="M6 21V4M6 5h11l-2 3 2 3H6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-tag" viewBox="0 0 24 24"><path d="M4 4h7l9 9-7 7-9-9z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="8" cy="8" r="1.3" fill="currentColor"/></symbol>
<symbol id="i-pick-bookmark" viewBox="0 0 24 24"><path d="M6 4h12v16l-6-4-6 4z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-bell" viewBox="0 0 24 24"><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 19a2 2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></symbol>
<symbol id="i-pick-megaphone" viewBox="0 0 24 24"><path d="M4 10v4l3 1v-6zM7 9l9-4v14l-9-4M7 15l1 5h2l-1-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-rocket" viewBox="0 0 24 24"><path d="M12 3c4 3 5 7 4 11l-2 3h-4l-2-3c-1-4 0-8 4-11z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="12" cy="9" r="1.5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M8 18l-2 3M16 18l2 3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
<symbol id="i-pick-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></symbol>
<symbol id="i-pick-wrench" viewBox="0 0 24 24"><path d="M15 4a4 4 0 0 0-5 5l-6 6 3 3 6-6a4 4 0 0 0 5-5l-2.5 2.5-2.5-.5-.5-2.5z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-shield" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-4 8-8 9-4-1-8-4-8-9V6z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-lock" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 10V7a4 4 0 0 1 8 0v3" fill="none" stroke="currentColor" stroke-width="1.6"/></symbol>
<symbol id="i-pick-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18" fill="none" stroke="currentColor" stroke-width="1.3"/></symbol>
<symbol id="i-pick-chart" viewBox="0 0 24 24"><path d="M4 20V4M4 20h16M8 16v-4M12 16V8M16 16v-7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="i-pick-cart" viewBox="0 0 24 24"><path d="M4 5h2l1.5 11H19l1.5-8H8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="9.5" cy="20" r="1.3" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="17" cy="20" r="1.3" fill="none" stroke="currentColor" stroke-width="1.5"/></symbol>
<symbol id="i-pick-bag" viewBox="0 0 24 24"><path d="M6 8h12l-1 12H7zM9 8V6a3 3 0 0 1 6 0v2" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-users" viewBox="0 0 24 24"><circle cx="9" cy="9" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M3 19a6 6 0 0 1 12 0M16 7a3 3 0 0 1 0 6M15 19a6 6 0 0 1 6-2" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
<symbol id="i-pick-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.2" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M5 20a7 7 0 0 1 14 0" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></symbol>
<symbol id="i-pick-chat" viewBox="0 0 24 24"><path d="M4 5h16v10H10l-4 3v-3H4z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-mail" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M3 8l9 6 9-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-doc" viewBox="0 0 24 24"><path d="M6 3h8l4 4v14H6z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M14 3v4h4M9 13h6M9 16h6" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
<symbol id="i-pick-image" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="9" cy="10" r="1.5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M5 17l5-4 3 3 3-3 3 3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-layout" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M4 9h16M9 9v11" fill="none" stroke="currentColor" stroke-width="1.4"/></symbol>
<symbol id="i-pick-grid" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="13" y="4" width="7" height="7" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="4" y="13" width="7" height="7" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="13" y="13" width="7" height="7" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/></symbol>
<symbol id="i-pick-brush" viewBox="0 0 24 24"><path d="M14 4l6 6-7 7-3-3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 14c-3 0-4 2-4 4-2 0-3-.5-3-.5 1 2 3 3 5 3 2.5 0 4-1.7 4-4z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-code" viewBox="0 0 24 24"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 5l-4 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-terminal" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M7 10l3 2-3 2M13 14h4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-database" viewBox="0 0 24 24"><ellipse cx="12" cy="6" rx="7" ry="2.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M5 6v12c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5V6M5 12c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5" fill="none" stroke="currentColor" stroke-width="1.5"/></symbol>
<symbol id="i-pick-gauge" viewBox="0 0 24 24"><path d="M4 15a8 8 0 0 1 16 0" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M12 15l4-3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="12" cy="15" r="1.3" fill="currentColor"/></symbol>
<symbol id="i-pick-cloud" viewBox="0 0 24 24"><path d="M7 18a4 4 0 0 1 .5-8 5 5 0 0 1 9.5 1.2A3.4 3.4 0 0 1 16.5 18z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-box" viewBox="0 0 24 24"><path d="M12 3l8 4v10l-8 4-8-4V7z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M4 7l8 4 8-4M12 11v10" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-key" viewBox="0 0 24 24"><circle cx="8" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M11 11l8 8M16 16l2-2M18 18l2-2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="i-pick-pin" viewBox="0 0 24 24"><path d="M12 21s7-6 7-11a7 7 0 0 0-14 0c0 5 7 11 7 11z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="12" cy="10" r="2.4" fill="none" stroke="currentColor" stroke-width="1.5"/></symbol>
<symbol id="i-pick-calendar" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M4 9h16M8 3v4M16 3v4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></symbol>
<symbol id="i-pick-puzzle" viewBox="0 0 24 24"><path d="M10 4a2 2 0 0 1 4 0c0 1 .5 1.5 1.5 1.5H19v3.5c0 1 .5 1.5 1.5 1.5a2 2 0 0 1 0 4c-1 0-1.5.5-1.5 1.5V20h-3.5c-1 0-1.5.5-1.5 1.5a2 2 0 0 1-4 0c0-1-.5-1.5-1.5-1.5H5v-3.5C5 15.5 4.5 15 3.5 15a2 2 0 0 1 0-4c1 0 1.5-.5 1.5-1.5V6h3.5C9.5 6 10 5.5 10 4.5z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></symbol>
<symbol id="i-pick-sparkle" viewBox="0 0 24 24"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M18 16l.8 2.2L21 19l-2.2.8L18 22l-.8-2.2L15 19l2.2-.8z" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></symbol>
</defs></svg>
SVG;
	}

	/**
	 * Ordered list of icons available in the editor picker (groups).
	 * Curated Double Youpi set `i-pick-*`. Consumed by RestController to
	 * populate the picker on the JS side. All of these icons exist in markup().
	 *
	 * @return string[]
	 */
	public static function pickable_icons(): array {
		return [
			'i-pick-folder', 'i-pick-star', 'i-pick-heart', 'i-pick-bolt',
			'i-pick-flag', 'i-pick-tag', 'i-pick-bookmark', 'i-pick-bell',
			'i-pick-megaphone', 'i-pick-rocket', 'i-pick-gear', 'i-pick-wrench',
			'i-pick-shield', 'i-pick-lock', 'i-pick-globe', 'i-pick-chart',
			'i-pick-cart', 'i-pick-bag', 'i-pick-users', 'i-pick-user',
			'i-pick-chat', 'i-pick-mail', 'i-pick-doc', 'i-pick-image',
			'i-pick-layout', 'i-pick-grid', 'i-pick-brush', 'i-pick-code',
			'i-pick-terminal', 'i-pick-database', 'i-pick-gauge', 'i-pick-cloud',
			'i-pick-box', 'i-pick-key', 'i-pick-pin', 'i-pick-calendar',
			'i-pick-puzzle', 'i-pick-sparkle',
		];
	}
}

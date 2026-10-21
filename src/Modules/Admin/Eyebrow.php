<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eyebrow — `.dy-page-sub` subtitle injected below each admin H1.
 *
 * Displays an accented dot (color derived from the active nav line) followed
 * by CONTEXTUAL information for the current page:
 *   - Content lists (posts, media, comments…) → counts + status
 *   - Single edit → status + last modified
 *   - Settings, tools, technical screens → dot only (no text)
 *
 * Rendering is driven by `compute_eyebrow_text()`, which returns an empty
 * string for pages where no dynamic information is relevant. On the JS side,
 * an empty string injects only the dot — useful as a visual section marker
 * without textual noise.
 *
 * The dot automatically takes the active nav line color via
 * `--dya-line-color` (set by `body.dy-line-*` in `_layout.scss`).
 */
final class Eyebrow {

	private const ADMIN_EYEBROW_INJECT_JS = <<<'JS'
(function () {
	function inject() {
		var h1 = document.querySelector('.wrap > h1.wp-heading-inline, .wrap > h1');
		if (!h1) return false;
		// Don't double-inject.
		var sibling = h1.nextElementSibling;
		while (sibling && sibling.classList && sibling.classList.contains('page-title-action')) {
			sibling = sibling.nextElementSibling;
		}
		if (sibling && sibling.classList && sibling.classList.contains('dy-page-sub')) return true;
		// Build the eyebrow.
		var sub = document.createElement('div');
		sub.className = 'dy-page-sub';
		sub.innerHTML = __DOUBLEYOUPI_DATA__;
		// Insert AFTER the last .page-title-action sibling (if any), else after h1.
		var anchor = h1;
		var next = h1.nextElementSibling;
		while (next && next.classList && next.classList.contains('page-title-action')) {
			anchor = next;
			next = next.nextElementSibling;
		}
		anchor.parentNode.insertBefore(sub, anchor.nextSibling);
		return true;
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', inject);
	} else {
		inject();
	}
})();
JS;

	private const ADMIN_EYEBROW_LIVE_MODULES_JS = <<<'JS'
(function () {
	var template = __DOUBLEYOUPI_DATA__;
	function update() {
		var sub = document.querySelector('.dy-page-sub');
		if (!sub) return;
		var boxes = document.querySelectorAll('.dy-overview__card input[type="checkbox"]');
		var enabled = 0;
		boxes.forEach(function (b) { if (b.checked) enabled++; });
		var text = template.replace('%2$d', enabled);
		var dot = sub.querySelector('.dot');
		sub.textContent = '';
		if (dot) sub.appendChild(dot);
		sub.appendChild(document.createTextNode(text));
		// Also reflects on the submit button to signal unpersisted changes.
		var submit = document.querySelector('.dy-overview__actions input[type="submit"]');
		if (submit) submit.classList.toggle('is-dirty', sub.dataset.dyServerEnabled !== String(enabled));
	}
	function init() {
		var sub = document.querySelector('.dy-page-sub');
		if (!sub) { setTimeout(init, 50); return; }
		// Memorise the initial server-side state to detect unpersisted changes.
		var boxes = document.querySelectorAll('.dy-overview__card input[type="checkbox"]');
		var serverEnabled = 0;
		boxes.forEach(function (b) { if (b.checked) serverEnabled++; });
		sub.dataset.dyServerEnabled = String(serverEnabled);
		document.addEventListener('change', function (e) {
			if (e.target && e.target.matches && e.target.matches('.dy-overview__card input[type="checkbox"]')) {
				update();
			}
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
JS;


	public function boot(): void {
		add_action( 'admin_footer', [ $this, 'inject' ], 1 );
	}

	public function inject(): void {
		// Skip excluded screens (block editor, Customizer, Site Editor)
		// where the chrome differs and injection would be cosmetically wrong.
		global $pagenow, $plugin_page;
		if ( in_array( $pagenow, [ 'customize.php', 'site-editor.php', 'theme-editor.php', 'plugin-editor.php' ], true ) ) {
			return;
		}
		// Skip System sub-pages: options-*, update-core, site-health.
		// These screens have no relevant dynamic information below the H1, and
		// the "System" category in the subchipbar already provides section context.
		if ( in_array( $pagenow, [
			'options-general.php',
			'options-writing.php',
			'options-reading.php',
			'options-discussion.php',
			'options-permalink.php',
			'options-privacy.php',
			'update-core.php',
			'site-health.php',
		], true ) ) {
			return;
		}

		// Skip Developer > Tools and its native WP sub-pages (Importer /
		// Exporter / Theme Editor / Plugin Editor). theme-editor.php /
		// plugin-editor.php are already skipped above due to a different chrome;
		// we add tools.php / import.php / export.php here.
		// Also skip `profile.php` (current user profile edit) —
		// a USER edit page has no useful dynamic meta below the H1
		// (no "x users · y roles" — it is about just that one user).
		//
		// `media-new.php` is also skipped: it is the upload screen, purely
		// transactional; the H1 + drop zone are self-sufficient, and the
		// eyebrow would add visual noise with no relevant dynamic information.
		//
		// `nav-menus.php`, `themes.php`, `options-media.php`: heavily customised
		// native WP screens (drag-and-drop UI, browse cards, global settings)
		// where the eyebrow carries no useful metadata and would visually
		// compete with the existing WP chrome.
		if ( in_array( $pagenow, [
			'tools.php',
			'import.php',
			'export.php',
			'profile.php',
			'media-new.php',
			'nav-menus.php',
			'themes.php',
			'options-media.php',
		], true ) ) {
			return;
		}

		// Skip plugin sub-pages mounted under `edit.php`
		// (`edit.php?post_type=…&page=X`) — e.g., ACF Tools (`acf-tools`),
		// Settings/Updates (`acf-settings-updates`). These are NOT content lists:
		// `text_for_post_list()` would still compute a counter (e.g., group count),
		// producing an orphaned eyebrow. The presence of `?page=` on `edit.php`
		// always indicates a third-party tools page.
		if ( 'edit.php' === $pagenow && ! empty( $plugin_page ) ) {
			return;
		}

		// Skip Double Youpi sub-pages (`?page=doubleyoupi-{module}`). We
		// KEEP the home page `?page=doubleyoupi` (exact slug = parent)
		// because its Eyebrow carries the available/enabled module counter.
		if ( is_string( $plugin_page )
			&& str_starts_with( $plugin_page, 'doubleyoupi-' )
		) {
			return;
		}

		// Skip branded top-levels — builders (Bricks, Elementor, Divi…),
		// prominent plugins (Woo, Yoast, Rank Math…), and active standalone
		// themes (Astra, GeneratePress…). Why: these screens already carry their
		// own title and navigation (builder chipbar, internal tab-bar); a generic
		// Eyebrow would add visual noise with no relevant contextual information.
		// How: the top-level is resolved via Rail::resolve_top_level_slug(), which
		// returns the current menu_slug for ALL sub-pages of a top-level.
		// Exception: the WC orders list (admin.php?page=wc-orders) receives the
		// eyebrow despite the branded WooCommerce top-level — it delivers the full
		// "list" experience (redesigned table + counter), on a par with native CPTs.
		// Cross-screen consistency is the goal.
		$is_wc_orders_list = ( 'wc-orders' === $plugin_page );
		$top_level = Rail::resolve_top_level_slug();
		if ( $top_level && PluginMap::is_branded_top_level( $top_level ) && ! $is_wc_orders_list ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		$text = $this->compute_eyebrow_text();

		// Inject `.dy-page-sub` ONLY on screens that produce a real contextual
		// subtitle. With no text, nothing is emitted: this avoids an orphaned
		// dot on all unrecognised third-party pages (ACF tools/settings, Loco, etc.).
		// → the eyebrow only appears where it is meaningful.
		if ( '' === $text ) {
			return;
		}

		$html = '<span class="dot" aria-hidden="true"></span>' . esc_html( $text );

		/**
		 * Filter: doubleyoupi_eyebrow_html
		 * Full override of the inner markup of `.dy-page-sub`.
		 *
		 * @param string $html      Default HTML (dot + contextual text)
		 * @param string $pagenow   Native WP slug of the current page
		 * @param string $text_only Computed contextual text (no markup)
		 */
		$html = wp_kses_post( (string) apply_filters( 'doubleyoupi_eyebrow_html', $html, $pagenow, $text ) );
		wp_print_inline_script_tag( str_replace( '__DOUBLEYOUPI_DATA__', (string) wp_json_encode( $html, JSON_HEX_TAG | JSON_HEX_AMP ), self::ADMIN_EYEBROW_INJECT_JS ), [ 'id' => 'doubleyoupi-admin-eyebrow-inject' ] );
			// ── Live counter update on the DY home page ──────────────────
			// Emit the supplementary script only when on `?page=doubleyoupi`.
			// It listens for checkbox toggles in the module grid and regenerates
			// the Eyebrow text locally — the user sees the counter update without
			// a reload; the actual commit happens on form submit (handle_form() persists).
			if ( 'doubleyoupi' === $plugin_page ) {
				[ $available_count, ] = $this->dy_module_counts();
				// Format with 1 placeholder for the enabled fraction: the total
				// available is fixed server-side (what the user sees in the
				// grid = what the catalog exposes), so singular/plural is chosen
				// once and only `%d` is interpolated from then on.
				$tpl = $available_count <= 1
					// translators: %1$d is the number of available modules, %2$d the number enabled.
					? __( '%1$d module available · %2$d enabled', 'doubleyoupi-refined-admin-dashboard' )
					// translators: %1$d is the number of available modules, %2$d the number enabled.
					: __( '%1$d modules available · %2$d enabled', 'doubleyoupi-refined-admin-dashboard' );
				$tpl_resolved = str_replace( '%1$d', (string) $available_count, $tpl );
				wp_print_inline_script_tag( str_replace( '__DOUBLEYOUPI_DATA__', (string) wp_json_encode( $tpl_resolved, JSON_HEX_TAG | JSON_HEX_AMP ), self::ADMIN_EYEBROW_LIVE_MODULES_JS ), [ 'id' => 'doubleyoupi-admin-eyebrow-live-modules' ] );
			}
	}

	/**
	 * Computes the contextual text to display in the eyebrow for the current page.
	 * Returns '' for pages where no dynamic information is relevant
	 * (settings, tools, custom plugin pages) — in that case only the accented dot is injected.
	 */
	private function compute_eyebrow_text(): string {
		global $pagenow, $typenow, $plugin_page;

		// ── Double Youpi home page (?page=doubleyoupi) ─────────────────
		// Shows "X modules available · Y enabled" and exposes a <span>
		// `data-dy-modules-count` that the live-update JS (see inject()) targets
		// to reflect client-side toggle state without a reload.
		if ( 'doubleyoupi' === $plugin_page ) {
			return $this->text_for_doubleyoupi_overview();
		}

		// ── WooCommerce orders (HPOS: admin.php?page=wc-orders) ──────
		if ( 'wc-orders' === $plugin_page ) {
			// Editing / creating an order → order-specific eyebrow
			// (status + date), not the global list counter.
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
			if ( 'edit' === $action || 'new' === $action ) {
				return $this->text_for_order_edit();
			}
			return $this->text_for_orders();
		}

		// ── Post/page/CPT list (edit.php?post_type=…) ──────────────
		if ( 'edit.php' === $pagenow ) {
			return $this->text_for_post_list( $typenow ?: 'post' );
		}

		// ── Media library (upload.php) ──────────────────────────────
		if ( 'upload.php' === $pagenow ) {
			return $this->text_for_media();
		}

		// ── Taxonomies (edit-tags.php?taxonomy=…) — categories, tags, custom ──
		// Content > [Post Type] > [Taxonomy]. Shows "%d term(s)" or
		// "No term" if the taxonomy is empty.
		if ( 'edit-tags.php' === $pagenow || 'term.php' === $pagenow ) {
			$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( (string) $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
			if ( '' !== $taxonomy ) {
				return $this->text_for_taxonomy( $taxonomy );
			}
		}

		// ── Comments ─────────────────────────────────────────────────
		if ( 'edit-comments.php' === $pagenow ) {
			return $this->text_for_comments();
		}

		// ── Users ────────────────────────────────────────────────────
		if ( 'users.php' === $pagenow ) {
			return $this->text_for_users();
		}

		// ── Plugins ──────────────────────────────────────────────────
		if ( 'plugins.php' === $pagenow ) {
			return $this->text_for_plugins();
		}

		// ── Themes ───────────────────────────────────────────────────
		if ( 'themes.php' === $pagenow ) {
			return $this->text_for_themes();
		}

		// ── Post/page/CPT edit (post.php / post-new.php) ────────────
		if ( in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {
			return $this->text_for_post_edit();
		}

		// ── Dashboard ────────────────────────────────────────────────
		if ( 'index.php' === $pagenow ) {
			return $this->text_for_dashboard();
		}

		// Fallback: dot only (empty text).
		return '';
	}

	private function text_for_post_list( string $post_type ): string {
		$counts = wp_count_posts( $post_type );
		if ( ! $counts ) {
			return '';
		}
		$total = (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 )
			+ (int) ( $counts->pending ?? 0 ) + (int) ( $counts->future ?? 0 )
			+ (int) ( $counts->private ?? 0 );
		if ( $total === 0 ) {
			return __( 'No item', 'doubleyoupi-refined-admin-dashboard' );
		}
		$publish = (int) ( $counts->publish ?? 0 );
		$draft   = (int) ( $counts->draft ?? 0 );

		// "4 pages · 3 published, 1 draft"
		$parts = [];
		if ( $publish > 0 ) {
			$parts[] = sprintf(
				// translators: %d is the number of published items.
				_n( '%d published', '%d published', $publish, 'doubleyoupi-refined-admin-dashboard' ),
				$publish
			);
		}
		if ( $draft > 0 ) {
			$parts[] = sprintf(
				// translators: %d is the number of drafts.
				_n( '%d draft', '%d drafts', $draft, 'doubleyoupi-refined-admin-dashboard' ),
				$draft
			);
		}
		$detail = $parts ? ' · ' . implode( ', ', $parts ) : '';

		return sprintf(
			// translators: %1$d is the item count, %2$s an optional breakdown suffix (e.g. " · 3 published, 1 draft").
			_n( '%1$d item%2$s', '%1$d items%2$s', $total, 'doubleyoupi-refined-admin-dashboard' ),
			$total,
			$detail
		);
	}

	/**
	 * WooCommerce orders (HPOS). Mirror of text_for_post_list:
	 * "14 orders · 5 completed, 3 processing". Total = sum of real statuses
	 * (excluding checkout drafts and trash).
	 */
	private function text_for_orders(): string {
		if ( ! function_exists( 'wc_get_order_statuses' ) || ! function_exists( 'wc_orders_count' ) ) {
			return '';
		}
		$total = 0;
		$completed  = 0;
		$processing = 0;
		foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
			if ( 'wc-checkout-draft' === $status ) {
				continue; // Checkout draft, not a real order.
			}
			$n      = (int) wc_orders_count( $status );
			$total += $n;
			if ( 'wc-completed' === $status ) {
				$completed = $n;
			}
			if ( 'wc-processing' === $status ) {
				$processing = $n;
			}
		}
		if ( $total === 0 ) {
			return __( 'No order', 'doubleyoupi-refined-admin-dashboard' );
		}

		// "14 orders · 5 completed, 3 processing"
		$parts = [];
		if ( $completed > 0 ) {
			$parts[] = sprintf(
				// translators: %d is the number of completed orders.
				_n( '%d completed', '%d completed', $completed, 'doubleyoupi-refined-admin-dashboard' ),
				$completed
			);
		}
		if ( $processing > 0 ) {
			$parts[] = sprintf(
				// translators: %d is the number of processing orders.
				_n( '%d processing', '%d processing', $processing, 'doubleyoupi-refined-admin-dashboard' ),
				$processing
			);
		}
		$detail = $parts ? ' · ' . implode( ', ', $parts ) : '';

		return sprintf(
			// translators: %1$d is the order count, %2$s an optional breakdown suffix (e.g. " · 2 completed, 1 processing").
			_n( '%1$d order%2$s', '%1$d orders%2$s', $total, 'doubleyoupi-refined-admin-dashboard' ),
			$total,
			$detail
		);
	}

	/**
	 * WC order edit screen: "Status · creation date". No new i18n strings
	 * (status label provided by WC, date formatted via wp_date).
	 */
	private function text_for_order_edit(): string {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
		if ( ! $id || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		$status_label = function_exists( 'wc_get_order_status_name' )
			? wc_get_order_status_name( $order->get_status() )
			: $order->get_status();
		$created = $order->get_date_created();
		if ( $created ) {
			$date_str = wp_date( (string) get_option( 'date_format' ), $created->getTimestamp() );
			return sprintf( '%s · %s', $status_label, $date_str );
		}
		return (string) $status_label;
	}

	private function text_for_media(): string {
		$counts = (array) wp_count_attachments();
		// `wp_count_attachments` returns $counts['image/jpeg'] => 4, etc.
		// Sum all valid MIME types and ignore `trash`.
		$total = 0;
		foreach ( $counts as $key => $count ) {
			if ( 'trash' === $key ) continue;
			$total += (int) $count;
		}
		if ( $total === 0 ) {
			return __( 'No media', 'doubleyoupi-refined-admin-dashboard' );
		}

		// Optional: total upload size (can be slow — kept simple for now).
		return sprintf(
			// translators: %d is the number of media items.
			_n( '%d media', '%d media', $total, 'doubleyoupi-refined-admin-dashboard' ),
			$total
		);
	}

	/**
	 * Term counter for Content > [Post Type] > [Taxonomy] pages
	 * (e.g., Categories, Tags, custom CPT taxonomies).
	 *
	 * `wp_count_terms()` returns the total respecting `hide_empty` per WP
	 * convention — we pass `hide_empty: false` to also count unused terms
	 * (otherwise a freshly created taxonomy with 5 terms but 0 assigned posts
	 * would show zero).
	 */
	private function text_for_taxonomy( string $taxonomy ): string {
		$count = wp_count_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		] );
		if ( is_wp_error( $count ) ) {
			return '';
		}
		$total = (int) $count;
		if ( 0 === $total ) {
			return __( 'No term', 'doubleyoupi-refined-admin-dashboard' );
		}
		return sprintf(
			// translators: %d is the number of terms.
			_n( '%d term', '%d terms', $total, 'doubleyoupi-refined-admin-dashboard' ),
			$total
		);
	}

	private function text_for_comments(): string {
		$counts = wp_count_comments();
		$pending  = (int) ( $counts->moderated ?? 0 );
		$approved = (int) ( $counts->approved ?? 0 );
		if ( $pending > 0 ) {
			return sprintf(
				// translators: %1$d is the number of pending comments, %2$d the number approved.
				_n( '%1$d pending · %2$d approved', '%1$d pending · %2$d approved', $approved, 'doubleyoupi-refined-admin-dashboard' ),
				$pending, $approved
			);
		}
		if ( $approved > 0 ) {
			return sprintf(
				// translators: %d is the number of approved comments.
				_n( '%d approved comment', '%d approved comments', $approved, 'doubleyoupi-refined-admin-dashboard' ),
				$approved
			);
		}
		return __( 'No comments', 'doubleyoupi-refined-admin-dashboard' );
	}

	private function text_for_users(): string {
		$counts = count_users();
		$total  = (int) ( $counts['total_users'] ?? 0 );
		$roles  = isset( $counts['avail_roles'] ) ? count( array_filter( (array) $counts['avail_roles'] ) ) : 0;
		if ( $total === 0 ) {
			return __( 'No users', 'doubleyoupi-refined-admin-dashboard' );
		}
		if ( $roles > 1 ) {
			return sprintf(
				// translators: %1$d is the number of users, %2$d the number of distinct roles.
				_n(
					'%1$d user · %2$d role',
					'%1$d users · %2$d roles',
					$total,
					'doubleyoupi-refined-admin-dashboard'
				),
				$total, $roles
			);
		}
		return sprintf(
			// translators: %d is the number of users.
			_n( '%d user', '%d users', $total, 'doubleyoupi-refined-admin-dashboard' ),
			$total
		);
	}

	private function text_for_plugins(): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// The same numbers as the list below it (multisite, 21/10/2026): in the
		// network admin, what is active for the network; on a site, what is
		// active there, network-wide plugins included when WordPress shows
		// them (super admins, filter `show_network_active_plugins`).
		$all     = get_plugins();
		$network = is_network_admin();
		if ( ! $network && is_multisite() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
			$show_network_active = (bool) apply_filters( 'show_network_active_plugins', current_user_can( 'manage_network_plugins' ) );
			if ( ! $show_network_active ) {
				$all = array_filter( $all, static fn( string $file ): bool => ! is_plugin_active_for_network( $file ), ARRAY_FILTER_USE_KEY );
			}
		}
		$total = count( $all );
		$nact  = count(
			array_filter(
				array_keys( $all ),
				static fn( string $file ): bool => $network ? is_plugin_active_for_network( $file ) : is_plugin_active( $file )
			)
		);

		if ( $total === 0 ) {
			return __( 'No extension', 'doubleyoupi-refined-admin-dashboard' );
		}

		return sprintf(
			// translators: %1$d is the number of active plugins, %2$d the number installed.
			_n(
				'%1$d active out of %2$d installed',
				'%1$d active out of %2$d installed',
				$nact,
				'doubleyoupi-refined-admin-dashboard'
			),
			$nact, $total
		);
	}

	private function text_for_themes(): string {
		$themes = wp_get_themes();
		$active = wp_get_theme();
		$count  = count( $themes );
		return sprintf(
			// translators: %1$s is the active theme name, %2$d the total number of installed themes.
			_n(
				'%1$s active · %2$d theme installed',
				'%1$s active · %2$d themes installed',
				$count,
				'doubleyoupi-refined-admin-dashboard'
			),
			$active->get( 'Name' ),
			$count
		);
	}

	private function text_for_post_edit(): string {
		global $post;
		if ( ! $post || ! $post->ID ) {
			// New post — not enough information yet.
			return '';
		}
		$status_obj = get_post_status_object( $post->post_status );
		$status_lbl = $status_obj ? $status_obj->label : '';
		$modified_gmt = (string) $post->post_modified_gmt;
		// Auto-drafts carry the zero date "0000-00-00 00:00:00": strtotime()
		// turns it into a negative timestamp and the eyebrow read
		// "Modified 2028 years ago" (audit-2). Treat it as "never".
		$modified = ( '' !== $modified_gmt && 0 !== strpos( $modified_gmt, '0000-00-00' ) )
			? (int) strtotime( $modified_gmt . ' UTC' )
			: 0;
		if ( $modified <= 0 ) {
			return (string) $status_lbl;
		}
		$diff = human_time_diff( $modified, time() );
		// translators: %1$s is the post status label, %2$s a human-readable time difference (e.g. "3 hours").
		return sprintf( __( '%1$s · Modified %2$s ago', 'doubleyoupi-refined-admin-dashboard' ), $status_lbl, $diff );
	}

	/**
	 * Eyebrow text for the Double Youpi home page.
	 *
	 * Format: "X modules available · Y enabled". Both counters are wrapped in
	 * `<span data-dy-modules-{available|enabled}>` elements so the Overview JS
	 * can update them live on each module checkbox toggle, without a page reload.
	 *
	 * Why: the Eyebrow renders an escaped string via `esc_html()` in inject(),
	 * so HTML cannot be injected directly through that path. We filter the final
	 * HTML via `doubleyoupi_eyebrow_html` to reintroduce the target `<span>`
	 * elements (see apply_filters in inject()).
	 */
	private function text_for_doubleyoupi_overview(): string {
		[ $available, $enabled ] = $this->dy_module_counts();
		return sprintf(
			/* translators: %1$d = available modules, %2$d = enabled modules */
			_n(
				'%1$d module available · %2$d enabled',
				'%1$d modules available · %2$d enabled',
				$available,
				'doubleyoupi-refined-admin-dashboard'
			),
			$available,
			$enabled
		);
	}

	/**
	 * @return array{0:int, 1:int} [available, enabled]
	 */
	private function dy_module_counts(): array {
		if ( ! class_exists( \DoubleYoupi\Core\ModuleCatalog::class )
			|| ! class_exists( \DoubleYoupi\Core\ModuleSettings::class )
		) {
			return [ 0, 0 ];
		}
		$ids       = \DoubleYoupi\Core\ModuleCatalog::available_ids();
		$available = count( $ids );
		$enabled   = 0;
		foreach ( $ids as $id ) {
			if ( \DoubleYoupi\Core\ModuleSettings::is_enabled( $id ) ) {
				$enabled++;
			}
		}
		return [ $available, $enabled ];
	}

	private function text_for_dashboard(): string {
		// No stable "last login" API in WP core without a plugin.
		// Show published post count + approved comments as a global activity indicator.
		$posts    = wp_count_posts( 'post' );
		$pages    = wp_count_posts( 'page' );
		$comments = wp_count_comments();
		$published = (int) ( $posts->publish ?? 0 ) + (int) ( $pages->publish ?? 0 );
		$approved  = (int) ( $comments->approved ?? 0 );

		if ( $published === 0 && $approved === 0 ) {
			return '';
		}
		return sprintf(
			// translators: %1$d is the number of publications, %2$d the number of approved comments.
			__( '%1$d publication · %2$d approved comment', 'doubleyoupi-refined-admin-dashboard' ),
			$published,
			$approved
		);
	}
}

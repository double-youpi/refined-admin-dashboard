<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Developer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderer for the Developer tab — emits only the HTML shell; content is
 * populated by admin-developer.js from the server-inlined snapshot.
 *
 * Why an empty shell instead of server-side rendering: the view is
 * inherently interactive (drag-and-drop with staging, cross-column selection,
 * collapsible drawers) — doing it in PHP would require a full re-render on
 * every client mutation. The server snapshot avoids a REST round-trip on first
 * paint; everything else is driven by JS.
 *
 * CLAUDE.md § 9.3-bis compliance: we stay INSIDE `.wrap` (opened by
 * Settings::render_page), use native WP classes (`.notice`) for fallbacks,
 * and the interactive content lives in a div with its own class
 * (`.dy-dev-editor`) styled via dedicated CSS — without breaking the DY
 * design system applied globally to the admin.
 */
final class Renderer {

	public static function render(): void {
		self::render_target();
		?>
		<p class="description" style="margin-top:14px;">
			<?php esc_html_e( 'Visually reorganize your admin navigation: drag chips between lines, hide items, promote sub-pages to top-level, and toggle Double Youpï styling per sub-page.', 'doubleyoupi-refined-admin-dashboard' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'The “Bottom of the rail” line splits the rail in two: drag it between groups to choose what sits at the bottom.', 'doubleyoupi-refined-admin-dashboard' ); ?>
			<a href="#dy-dev-detected"><?php esc_html_e( 'Plugin pages not sorted yet are listed below the board.', 'doubleyoupi-refined-admin-dashboard' ); ?></a>
		</p>

		<noscript>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'The navigation editor requires JavaScript. Please enable it in your browser to use this tab.', 'doubleyoupi-refined-admin-dashboard' ); ?>
				</p>
			</div>
		</noscript>

		<div class="dy-dev-editor" id="dy-dev-editor" data-state="loading">
			<div class="dy-dev-editor__loading">
				<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
				<?php esc_html_e( 'Loading editor…', 'doubleyoupi-refined-admin-dashboard' ); ?>
			</div>

			<!--
				JS injects the full structure here: 3 columns (rail /
				chipbar / subchipbar) or responsive accordion, Detected
				+ Hidden drawers, sticky-bottom Unsaved changes banner.
				See admin-developer.js for the rendering logic.
			-->
		</div>
		<?php
	}

	/**
	 * « You are editing: » the site's menu or a configuration (Configurations,
	 * RAD 1.1.0). A plain GET form: choosing reloads the editor on that target.
	 */
	private static function render_target(): void {
		$current = (string) \DoubleYoupi\Modules\Admin\Configurations::editing();
		// What is pinned in code (DOUBLEYOUPI_ADMIN_PROFILE) cannot be saved here:
		// say so before the editor, which would otherwise refuse each save.
		$pinned = \DoubleYoupi\Modules\Admin\Configurations::pinned()
			? __( 'The configurations by role are pinned in code (DOUBLEYOUPI_ADMIN_PROFILE): they can be seen, not edited, here.', 'doubleyoupi-refined-admin-dashboard' )
			: '';
		if ( '' === $current && \DoubleYoupi\Modules\Admin\Profile::is_locked( \DoubleYoupi\Modules\Admin\NavigationOverrides::OPTION_KEY ) ) {
			$pinned = trim( __( 'The site’s menu is pinned in code (DOUBLEYOUPI_ADMIN_PROFILE): changes made here are not saved.', 'doubleyoupi-refined-admin-dashboard' ) . ' ' . $pinned );
		}
		if ( '' !== $pinned ) {
			printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html( $pinned ) );
		}
		$items = \DoubleYoupi\Modules\Admin\Configurations::pinned() ? [] : \DoubleYoupi\Modules\Admin\Configurations::all();
		if ( ! $items ) {
			return;
		}
		?>
		<form class="dy-dev-target" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="doubleyoupi-admin" />
			<input type="hidden" name="tab" value="reorganize" />
			<label for="dy-dev-target"><?php esc_html_e( 'You are editing:', 'doubleyoupi-refined-admin-dashboard' ); ?></label>
			<select id="dy-dev-target" name="<?php echo esc_attr( \DoubleYoupi\Modules\Admin\Configurations::EDIT_PARAM ); ?>">
				<option value=""><?php esc_html_e( 'The site’s menu (administrators and roles without a configuration)', 'doubleyoupi-refined-admin-dashboard' ); ?></option>
				<?php foreach ( $items as $slug => $item ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $current ); ?>><?php echo esc_html( $item['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button dy-dev-target__open"><?php esc_html_e( 'Open', 'doubleyoupi-refined-admin-dashboard' ); ?></button>
		</form>
		<?php
	}
}

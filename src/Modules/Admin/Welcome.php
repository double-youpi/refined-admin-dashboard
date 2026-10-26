<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Core\ModuleSettings;
use DoubleYoupi\Admin\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Welcome — the first-run card at the top of the Settings page.
 *
 * No wizard, no modal: the opinionated defaults are applied the moment the
 * plugin is on. This card, shown once per site until dismissed, says what
 * changed and points to the three site-level choices worth a look:
 * navigation tree, colour and logo, and the profile export for people who
 * set up many sites. The three are deliberately parallel, each an anchor to
 * a section of the form below. (30/09/2026: the third used to be « Dark or
 * light », which had nothing to set and so carried no link, while the
 * profile sat outside the grid as a fourth call to action under a heading
 * announcing three.) A one-line notice on the plugins screen, where
 * activation lands, links here.
 */
final class Welcome {

	private const WELCOME_SHORTCUTS_JS = <<<'JS'
// Shortcuts: a smooth scroll to the group, then a brief glow on it.
// A plain anchor jump was invisible or misleading here: the page
// scrolls inside #wpcontent under the fixed admin bar, the Brand group
// sits right below this card (the jump barely moved it), and the
// profile group is too low to ever reach the top.
(function () {
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	function scroller(el) {
		for (var p = el.parentElement; p && p !== document.body; p = p.parentElement) {
			var oy = getComputedStyle(p).overflowY;
			if ((oy === 'auto' || oy === 'scroll') && p.scrollHeight > p.clientHeight) { return p; }
		}
		return null;
	}
	document.querySelectorAll('#dy-welcome .dy-overview__welcome-item').forEach(function (link) {
		link.addEventListener('click', function (e) {
			var target = document.querySelector(link.getAttribute('href'));
			if (!target) { return; }
			e.preventDefault();
			var bar = document.getElementById('wpadminbar');
			var gap = (bar ? bar.getBoundingClientRect().bottom : 0) + 20;
			var box = scroller(target);
			var behavior = reduce ? 'auto' : 'smooth';
			if (box) {
				var top = box.scrollTop + target.getBoundingClientRect().top - Math.max(box.getBoundingClientRect().top, gap);
				box.scrollTo({ top: top, behavior: behavior });
			} else {
				window.scrollTo({ top: window.scrollY + target.getBoundingClientRect().top - gap, behavior: behavior });
			}
			history.replaceState(null, '', link.getAttribute('href'));
			target.setAttribute('tabindex', '-1');
			target.focus({ preventScroll: true });
			target.classList.remove('is-targeted');
			void target.offsetWidth;
			target.classList.add('is-targeted');
			setTimeout(function () { target.classList.remove('is-targeted'); }, 1800);
		});
	});
})();
(function () {
	var form = document.querySelector('#dy-welcome .dy-overview__welcome-dismiss');
	if (!form || !window.fetch) return;
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var card = document.getElementById('dy-welcome');
		// `form.action` is shadowed by the hidden input named "action".
		fetch(form.getAttribute('action') + '?ajax=1', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
			.catch(function () {});
		if (card) { card.classList.add('is-leaving'); setTimeout(function () { card.remove(); }, 260); }
	});
})();
JS;


	public const OPTION_SEEN    = 'doubleyoupi_admin_welcome_seen';
	public const ACTION_DISMISS = 'doubleyoupi_admin_welcome_dismiss';
	private const NONCE         = 'doubleyoupi_admin_welcome';

	public function boot(): void {
		add_action( 'admin_post_' . self::ACTION_DISMISS, [ $this, 'handle_dismiss' ] );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	/** Settings page (General tab). */
	public static function settings_url( string $fragment = '' ): string {
		return admin_url( 'admin.php?page=doubleyoupi-admin' ) . ( '' !== $fragment ? '#' . $fragment : '' );
	}

	public static function is_pending(): bool {
		if ( get_option( self::OPTION_SEEN ) ) {
			return false;
		}
		return ! class_exists( '\\DoubleYoupi\\Core\\ModuleSettings' ) || ModuleSettings::is_enabled( 'admin' );
	}

	private static function dismiss_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ), self::NONCE );
	}

	public function handle_dismiss(): void {
		if ( ! current_user_can( Access::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'doubleyoupi-refined-admin-dashboard' ), 403 );
		}
		check_admin_referer( self::NONCE );
		update_option( self::OPTION_SEEN, gmdate( 'c' ), true ); // read on every admin screen
		if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) || ( isset( $_GET['ajax'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['ajax'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
			wp_send_json_success();
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : self::settings_url() );
		exit;
	}

	/** Plugins screen only (where activation lands), until dismissed. */
	public function render_notice(): void {
		global $pagenow;
		if ( 'plugins.php' !== $pagenow || ! self::is_pending() || ! current_user_can( Access::CAP ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info is-dismissible dy-welcome-notice"><p><strong>%1$s</strong> <a href="%2$s">%3$s</a> · <a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'Refined Admin Dashboard is on.', 'doubleyoupi-refined-admin-dashboard' ),
			esc_url( self::settings_url() ),
			esc_html__( 'See what changed', 'doubleyoupi-refined-admin-dashboard' ),
			esc_url( self::dismiss_url() ),
			esc_html__( 'Dismiss', 'doubleyoupi-refined-admin-dashboard' )
		);
	}

	/**
	 * Card at the top of the General tab (called by Settings). The links
	 * are anchors on the same page: the form it describes is right below.
	 */
	public static function render_card(): void {
		if ( ! self::is_pending() || ! current_user_can( Access::CAP ) ) {
			return;
		}
		// In the order of the groups below, with their icons: each card is a
		// shortcut to the group it names (Settings::render_tab_general).
		$items = [
			[
				'title' => __( 'Colour and logo', 'doubleyoupi-refined-admin-dashboard' ),
				'text'  => __( 'One accent for buttons and active states, your logo above the rail and on the login screen.', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => '#dy-brand',
				'cta'   => __( 'Set the brand', 'doubleyoupi-refined-admin-dashboard' ),
				'icon'  => 'palette',
			],
			[
				'title' => __( 'Navigation tree', 'doubleyoupi-refined-admin-dashboard' ),
				'text'  => __( 'Seven sections instead of the WordPress menu. Prefer the native order for a team trained on it? One switch.', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => '#dy-navigation',
				'cta'   => __( 'Choose the tree', 'doubleyoupi-refined-admin-dashboard' ),
				'icon'  => 'tree',
			],
			[
				'title' => __( 'Settings profile', 'doubleyoupi-refined-admin-dashboard' ),
				'text'  => __( 'Setting up several sites? Export this configuration and import it on the next one.', 'doubleyoupi-refined-admin-dashboard' ),
				'url'   => '#dy-profile',
				'cta'   => __( 'Export the profile', 'doubleyoupi-refined-admin-dashboard' ),
				'icon'  => 'download',
			],
		];
		?>
		<section class="dy-overview__welcome" id="dy-welcome" aria-labelledby="dy-welcome-title">
			<div class="dy-overview__welcome-head">
				<div class="dy-overview__welcome-lead">
					<p class="dy-overview__welcome-eyebrow"><?php esc_html_e( 'Refined Admin Dashboard is on', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
					<h2 class="dy-overview__welcome-title" id="dy-welcome-title"><?php esc_html_e( 'Here is what changed', 'doubleyoupi-refined-admin-dashboard' ); ?></h2>
				</div>
				<form class="dy-overview__welcome-dismiss" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DISMISS ); ?>">
					<?php wp_nonce_field( self::NONCE ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Got it', 'doubleyoupi-refined-admin-dashboard' ); ?></button>
				</form>
				<p class="dy-overview__welcome-text"><?php esc_html_e( 'The menu became a rail with seven sections, lists and the editor were redrawn, notices moved into one place, and the dashboard is modular. Each person picks a light or dark theme from the top bar, and everything else is automatic.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			</div>
			<div class="dy-overview__welcome-grid">
				<?php foreach ( $items as $it ) : ?>
					<a class="dy-overview__welcome-item" href="<?php echo esc_url( $it['url'] ); ?>">
						<span class="dy-overview__welcome-item-icon" aria-hidden="true"><?php Settings::icon( $it['icon'] ); ?></span>
						<span class="dy-overview__welcome-item-title"><?php echo esc_html( $it['title'] ); ?></span>
						<span class="dy-overview__welcome-item-text"><?php echo esc_html( $it['text'] ); ?></span>
						<span class="dy-overview__welcome-link"><?php echo esc_html( $it['cta'] ); ?> &darr;</span>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
		<?php wp_print_inline_script_tag( self::WELCOME_SHORTCUTS_JS, [ 'id' => 'doubleyoupi-welcome-shortcuts' ] ); ?>
		<?php
	}
}

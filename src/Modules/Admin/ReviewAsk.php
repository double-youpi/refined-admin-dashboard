<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

use DoubleYoupi\Admin\Feedback;
use DoubleYoupi\Admin\ScreenOwner;
use DoubleYoupi\Admin\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ReviewAsk — one review request, after a week of real use (19/10/2026).
 *
 * Decided by Raphaël from the distribution's brief
 * (diffusion-doubleyoupi/entrees/prompt-instance-extensions-demande-avis-2026-10-19.md):
 *
 * - For administrators (`manage_options`), never in the network admin.
 * - Shown once 7 days have passed since an administrator first opened the
 *   redesigned admin AND the admin was opened on at least 3 distinct days.
 * - At most twice in the life of the site: "Later" (or the close cross, the
 *   first time) brings it back once, 30 days later, without "Later"; "Leave
 *   a review", "No thanks" (or the cross, the second time) end it for good.
 * - In the notifications centre (a plain `notice-info`, captured there),
 *   only on WordPress's and Double Youpï's own screens: never on another
 *   plugin's or a theme's screen, the dashboard, the editors or the update
 *   screens. Nothing is offered in exchange, nothing leaves the site.
 * - Only in builds that have a WordPress.org review page
 *   (Feedback::review_url()); the suite shows nothing and counts nothing.
 * - Never when the site hides Feedback and review requests, or when the
 *   `doubleyoupi_filter_review_ask_enabled` filter says no
 *   (Feedback::review_ask_enabled(), 23/10/2026).
 *
 * One site option (not autoloaded), written at most once a day by the
 * tracking. The decision itself is pure (`decide()`), unit-tested.
 */
final class ReviewAsk {

	public const OPTION = 'doubleyoupi_admin_review_ask';
	public const ACTION = 'doubleyoupi_admin_review_ask';
	private const NONCE = 'doubleyoupi_admin_review_ask';

	public const WAIT_DAYS   = 7;
	public const MIN_DAYS    = 3;
	public const SNOOZE_DAYS = 30;

	/** decide() results. */
	public const HIDE        = 'hide';
	public const SHOW        = 'show';
	public const SHOW_LAST   = 'show_last';

	/** Screens that never carry the request, whoever owns them. */
	private const EXCLUDED_PAGES = [
		'index.php',
		'post.php',
		'post-new.php',
		'site-editor.php',
		'update.php',
		'update-core.php',
		'customize.php',
	];

	private const ASK_JS = <<<'JS'
(function () {
	if (!window.fetch) { return; }
	function send(answer, form) {
		var data = new FormData(form);
		data.set('answer', answer);
		fetch(form.getAttribute('action') + '?ajax=1', { method: 'POST', body: data, credentials: 'same-origin', keepalive: true }).catch(function () {});
	}
	function leave(note) {
		note.classList.add('is-leaving');
		setTimeout(function () { note.remove(); }, 200);
	}
	document.addEventListener('click', function (e) {
		var note = e.target.closest && e.target.closest('.dy-review-ask');
		if (!note) { return; }
		var form = note.querySelector('form');
		if (!form) { return; }
		// The review link opens its tab normally; the answer is recorded alongside.
		var link = e.target.closest('[data-dy-review-answer="review"]');
		if (link) { send('review', form); leave(note); return; }
		var button = e.target.closest('button[data-dy-review-answer]');
		if (button) { e.preventDefault(); send(button.getAttribute('data-dy-review-answer'), form); leave(note); return; }
		// WordPress's own close cross (also clicked by the centre's "Dismiss all").
		if (e.target.closest('.notice-dismiss')) { send('dismiss', form); }
	});
})();
JS;

	public function boot(): void {
		add_action( 'admin_init', [ $this, 'track' ] );
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	// =====================================================================
	//  Pure logic
	// =====================================================================

	/**
	 * What to show, from the stored state, the time and the screen.
	 *
	 * @param array<string, mixed> $state   Stored option (see default_state()).
	 * @param int                  $now     Unix time.
	 * @param array<string, mixed> $context {review_url, welcome_pending, owner_kind, pagenow, network, managed_elsewhere}
	 */
	public static function decide( array $state, int $now, array $context ): string {
		if ( '' === (string) ( $context['review_url'] ?? '' ) || ! empty( $context['welcome_pending'] ) ) {
			return self::HIDE;
		}
		if ( ! empty( $context['network'] ) || ! empty( $context['managed_elsewhere'] ) ) {
			return self::HIDE;
		}
		if ( ! in_array( (string) ( $context['owner_kind'] ?? '' ), [ 'core', 'doubleyoupi' ], true ) ) {
			return self::HIDE;
		}
		if ( in_array( (string) ( $context['pagenow'] ?? '' ), self::EXCLUDED_PAGES, true ) ) {
			return self::HIDE;
		}

		$state = array_merge( self::default_state(), $state );
		switch ( $state['state'] ) {
			case 'closed':
				return self::HIDE;
			case 'snoozed':
				return $now >= (int) $state['snoozed_until'] ? self::SHOW_LAST : self::HIDE;
		}
		$first = (int) $state['first_seen'];
		$days  = array_unique( (array) $state['days'] );
		if ( ! $first || $now < $first + self::WAIT_DAYS * DAY_IN_SECONDS || count( $days ) < self::MIN_DAYS ) {
			return self::HIDE;
		}
		return self::SHOW;
	}

	/**
	 * The state after one answer. The cross counts as "Later" the first time
	 * and as "No thanks" once the request has already come back.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	public static function answer( array $state, string $answer, int $now ): array {
		$state = array_merge( self::default_state(), $state );
		$again = 'snoozed' === $state['state'];
		if ( 'dismiss' === $answer ) {
			$answer = $again ? 'no' : 'later';
		}
		if ( 'later' === $answer && ! $again ) {
			$state['state']         = 'snoozed';
			$state['snoozed_until'] = $now + self::SNOOZE_DAYS * DAY_IN_SECONDS;
		} else {
			$state['state'] = 'closed';
			$answer         = in_array( $answer, [ 'review', 'no' ], true ) ? $answer : 'no';
		}
		$state['answer']      = $answer;
		$state['answered_at'] = $now;
		return $state;
	}

	/**
	 * The state after a day of use: first_seen is set once, the day is added
	 * (site time zone), at most WAIT_DAYS days are kept.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	public static function touch( array $state, int $now, string $day ): array {
		$state = array_merge( self::default_state(), $state );
		if ( ! (int) $state['first_seen'] ) {
			$state['first_seen'] = $now;
		}
		$days = array_values( array_unique( array_merge( (array) $state['days'], [ $day ] ) ) );
		sort( $days );
		$state['days'] = array_slice( $days, -self::WAIT_DAYS );
		return $state;
	}

	/** @return array<string, mixed> */
	public static function default_state(): array {
		return [
			'first_seen'    => 0,
			'days'          => [],
			'state'         => 'waiting',
			'snoozed_until' => 0,
			'answer'        => '',
			'answered_at'   => 0,
		];
	}

	// =====================================================================
	//  WordPress side
	// =====================================================================

	/** @return array<string, mixed> */
	private static function state(): array {
		$state = get_option( self::OPTION, [] );
		return is_array( $state ) ? array_merge( self::default_state(), $state ) : self::default_state();
	}

	private static function save( array $state ): void {
		update_option( self::OPTION, $state, true ); // read on every admin screen
	}

	/** Counts the day: administrators, real admin page loads only, one write a day at most. */
	public function track(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! current_user_can( Access::CAP ) || '' === Feedback::review_url() || ! Feedback::review_ask_enabled() ) {
			return;
		}
		$state = self::state();
		if ( 'waiting' !== $state['state'] ) {
			return;
		}
		$day = wp_date( 'Y-m-d' );
		if ( (int) $state['first_seen'] && in_array( $day, (array) $state['days'], true ) ) {
			return;
		}
		self::save( self::touch( $state, time(), (string) $day ) );
	}

	/** @return array<string, mixed> */
	private static function context(): array {
		global $pagenow;
		$owner = ScreenOwner::resolve()['owner'];
		return [
			'review_url'        => Feedback::review_url(),
			'welcome_pending'   => Welcome::is_pending(),
			'owner_kind'        => (string) ( $owner['kind'] ?? '' ),
			'pagenow'           => (string) $pagenow,
			'network'           => is_network_admin(),
			'managed_elsewhere' => BuilderScreen::is_managed() || Dashboard\Dashboard::is_current_screen(),
		];
	}

	public function render(): void {
		if ( ! current_user_can( Access::CAP ) || ! Feedback::review_ask_enabled() ) {
			return;
		}
		$verdict = self::decide( self::state(), time(), self::context() );
		if ( self::HIDE === $verdict ) {
			return;
		}
		?>
<div class="notice notice-info is-dismissible dy-review-ask">
	<p><?php esc_html_e( 'One week with Refined Admin Dashboard. If it makes your days in the admin a little better, a review on WordPress.org is how other people find it. (One sentence is plenty.)', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
	<form class="dy-review-ask__actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
		<?php wp_nonce_field( self::NONCE ); ?>
		<a class="button button-primary" href="<?php echo esc_url( Feedback::review_url() ); ?>" target="_blank" rel="noopener noreferrer" data-dy-review-answer="review"><?php esc_html_e( 'Leave a review', 'doubleyoupi-refined-admin-dashboard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'doubleyoupi-refined-admin-dashboard' ); ?></span></a>
		<?php if ( self::SHOW === $verdict ) : ?>
			<button type="submit" class="button" name="answer" value="later" data-dy-review-answer="later"><?php echo esc_html( _x( 'Later', 'review request', 'doubleyoupi-refined-admin-dashboard' ) ); ?></button>
		<?php endif; ?>
		<button type="submit" class="button" name="answer" value="no" data-dy-review-answer="no"><?php echo esc_html( _x( 'No thanks', 'review request', 'doubleyoupi-refined-admin-dashboard' ) ); ?></button>
	</form>
</div>
		<?php
		wp_print_inline_script_tag( self::ASK_JS, [ 'id' => 'doubleyoupi-review-ask' ] );
	}

	/** admin-post.php: records the answer (JSON for fetch, redirect otherwise). */
	public function handle(): void {
		if ( ! current_user_can( Access::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'doubleyoupi-refined-admin-dashboard' ), 403 );
		}
		check_admin_referer( self::NONCE );
		$answer = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( (string) $_POST['answer'] ) ) : '';
		if ( in_array( $answer, [ 'review', 'later', 'no', 'dismiss' ], true ) ) {
			self::save( self::answer( self::state(), $answer, time() ) );
		}
		if ( isset( $_GET['ajax'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['ajax'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
			wp_send_json_success();
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}
}

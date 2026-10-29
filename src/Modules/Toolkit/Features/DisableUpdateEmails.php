<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suppresses auto-update RESULT emails for plugins and themes.
 *
 * Context: when an agency manages dozens of sites, WordPress sends an email
 * for every successful plugin/theme auto-update — spamming the admin inbox
 * with zero actionable information.
 *
 * Opinionated choice (manifesto §2 "editor decisions"): only SUCCESS emails
 * are suppressed. Failure and mixed emails always pass through:
 * the admin must be informed when an update breaks something.
 *
 * Core emails (`auto_core_update_send_email`) are NOT touched: a WordPress
 * core security update always deserves its notification.
 *
 * Replaces micro-plugins such as "Companion Auto Update" / "Easy Updates
 * Manager" that are used solely to silence these emails.
 */
final class DisableUpdateEmails extends AbstractFeature {

	public function id(): string {
		return 'disable_update_emails';
	}

	public function label(): string {
		return __( 'Disable update emails', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function checkbox_label(): string {
		return __( 'Mute auto-update success emails for plugins and themes', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Stops the inbox spam from successful plugin/theme auto-updates. Failure emails are kept, and core update emails are untouched. Replaces Companion Auto Update / Easy Updates Manager.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Crossed-out envelope — email notification disabled.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/>'
			. '<path d="M22 7l-10 6L2 7"/>'
			. '<line x1="3" y1="3" x2="21" y2="21"/>'
			. '</svg>';
	}

	public function conflict_plugin_candidates(): array {
		return [
			[ 'file' => 'stops-core-theme-and-plugin-updates/stops-core-theme-and-plugin-updates.php', 'name' => 'Easy Updates Manager' ],
			[ 'file' => 'companion-auto-update/companion-auto-update.php',                              'name' => 'Companion Auto Update' ],
		];
	}

	protected function apply(): void {
		add_filter( 'auto_plugin_update_send_email', [ self::class, 'maybe_suppress' ], 10, 2 );
		add_filter( 'auto_theme_update_send_email',  [ self::class, 'maybe_suppress' ], 10, 2 );
	}

	/**
	 * Decides whether the auto-update result email should be sent.
	 *
	 * WordPress passes the list of update results (objects with `->result`),
	 * not a type string: the former `string $type` signature threw a
	 * TypeError at the end of every automatic plugin or theme update, so no
	 * email left at all, failures included (review of 29/10/2026). The email
	 * is muted only when every result is a success; a failure, an empty or
	 * unexpected value keeps WordPress's decision.
	 *
	 * @param bool  $send           Current value (true by default on the WP side).
	 * @param mixed $update_results The results of the plugin or theme updates.
	 */
	public static function maybe_suppress( $send, $update_results = null ): bool {
		if ( ! is_array( $update_results ) || array() === $update_results ) {
			return (bool) $send;
		}
		foreach ( $update_results as $result ) {
			if ( ! is_object( $result ) || ! isset( $result->result ) || true !== $result->result ) {
				return (bool) $send;
			}
		}
		return false;
	}
}

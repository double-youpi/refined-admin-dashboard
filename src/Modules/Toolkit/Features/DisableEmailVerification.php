<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disables the interruption screen "Is the administration email address still
 * correct?" that WordPress displays periodically at login (since WP 5.3,
 * approximately every 6 months).
 *
 * This is one of the most disliked annoyances for professionals: a full-screen
 * prompt that interrupts login and confuses non-technical clients.
 *
 * Mechanism: the `admin_email_check_interval` filter controls the verification
 * interval. WordPress skips the screen entirely when the interval is 0.
 * A single filter, zero configuration.
 *
 * Replaces "Make Disable Admin Email Verification Prompt" and similar plugins.
 */
final class DisableEmailVerification extends AbstractFeature {

	public function id(): string {
		return 'disable_email_verification';
	}

	public function label(): string {
		return __( 'Disable email verification', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function checkbox_label(): string {
		return __( 'Stop the periodic « Is this admin email correct? » screen', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Removes the full-screen admin email verification prompt WordPress shows at login every few months. Replaces Make Disable Admin Email Verification Prompt.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Envelope + cross — email verification cancelled.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M4 4h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/>'
			. '<path d="M22 7l-10 6L2 7"/>'
			. '<path d="M15 19l5 5M20 19l-5 5"/>'
			. '</svg>';
	}

	public function conflict_plugin_candidates(): array {
		return [
			[ 'file' => 'make-disable-admin-email-verification-prompt/make-disable-admin-email-verification-prompt.php', 'name' => 'Make Disable Admin Email Verification Prompt' ],
			[ 'file' => 'admin-site-enhancements/admin-site-enhancements.php',                                           'name' => 'Admin and Site Enhancements (ASE)' ],
		];
	}

	protected function apply(): void {
		// Interval set to 0 ⇒ WordPress never triggers the verification screen.
		add_filter( 'admin_email_check_interval', '__return_zero' );
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UserCustomization — per-user customization of the Admin module.
 *
 * Two levels:
 *   1. A global master toggle (set by the administrator in the Admin options
 *      page): `doubleyoupi_admin_per_user`. While it is OFF (default), the
 *      redesign applies globally to everyone — same as before this feature was
 *      introduced (no regression).
 *   2. When the master toggle is ON, the redesign becomes opt-out PER ACCOUNT
 *      via the user meta `doubleyoupi_admin_user_enabled` (default = enabled):
 *        - each user can disable the module on their profile.php;
 *        - the administrator can do so for each user from the user edit page
 *          (user-edit.php).
 *
 * `should_apply()` is the apply-gate consumed by Admin::maybe_boot_redesign()
 * to decide whether the current session receives the redesign or the native wp-admin.
 */
final class UserCustomization {

	private const ADMIN_USER_PERSO_REPOSITION_JS = <<<'JS'
(function () {
	var block = document.getElementById('dy-admin-user-perso');
	if ( ! block ) { return; }
	var form = block.closest('form');
	if ( form && form.firstChild ) {
		form.insertBefore( block, form.firstChild );
	}
})();
JS;


	/** Global master toggle (option, group `doubleyoupi_admin`). */
	public const OPTION_PER_USER = 'doubleyoupi_admin_per_user';

	/** Per-account preference: '' (absent) ⇒ enabled, '1' ⇒ enabled, '0' ⇒ disabled. */
	public const USER_META_KEY = 'doubleyoupi_admin_user_enabled';

	private const NONCE_ACTION = 'doubleyoupi_admin_user_perso';
	private const NONCE_NAME   = '_doubleyoupi_admin_user_perso_nonce';

	private const FIELD = 'doubleyoupi_admin_user_enabled';

	public function boot(): void {
		// Settings block: profile.php (current account) + user-edit.php (admin).
		add_action( 'show_user_profile', [ $this, 'render_profile_fields' ] );
		add_action( 'edit_user_profile', [ $this, 'render_profile_fields' ] );

		// Save: personal_options_update (profile.php) + edit_user_profile_update (user-edit.php).
		add_action( 'personal_options_update',  [ $this, 'save_profile_fields' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_profile_fields' ] );
	}

	// -------------------------------------------------------------------------
	// State — read by Admin (apply-gate) and Settings (toggle rendering).
	// -------------------------------------------------------------------------

	/** Is the "per-user customization" mode enabled globally? */
	public static function is_per_user_mode(): bool {
		return (bool) get_option( self::OPTION_PER_USER, false );
	}

	/** Is the Admin module enabled for this account? (default: yes). */
	public static function is_enabled_for_user( int $user_id ): bool {
		$value = (string) get_user_meta( $user_id, self::USER_META_KEY, true );
		// Absent meta ⇒ default value = enabled (opt-out model).
		return '' === $value || '1' === $value;
	}

	public static function set_enabled_for_user( int $user_id, bool $enabled ): void {
		update_user_meta( $user_id, self::USER_META_KEY, $enabled ? '1' : '0' );
	}

	/**
	 * Apply-gate: should the current session receive the Admin redesign?
	 *
	 *   - per-user mode OFF ⇒ true (global redesign, historic behavior);
	 *   - not logged in (e.g. wp-login.php) ⇒ true (login branding is shared,
	 *     not tied to any account);
	 *   - otherwise ⇒ account preference (default enabled).
	 */
	public static function should_apply( ?int $user_id = null ): bool {
		if ( ! self::is_per_user_mode() ) {
			return true;
		}
		$user_id ??= get_current_user_id();
		if ( ! $user_id ) {
			return true;
		}
		return self::is_enabled_for_user( $user_id );
	}

	// -------------------------------------------------------------------------
	// Rendering + saving the profile block (native WP markup — CLAUDE.md §9.3-bis).
	// -------------------------------------------------------------------------

	public function render_profile_fields( \WP_User $user ): void {
		// The block only makes sense when per-user mode is globally enabled.
		if ( ! self::is_per_user_mode() ) {
			return;
		}
		// The current user must be able to edit this account (their own profile,
		// or another account if they are an admin via user-edit.php).
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$enabled = self::is_enabled_for_user( $user->ID );
		?>
		<div id="dy-admin-user-perso" class="dy-admin-user-perso">
			<h2><?php esc_html_e( 'Interface customization', 'doubleyoupi-refined-admin-dashboard' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Double Youpï admin interface', 'doubleyoupi-refined-admin-dashboard' ); ?></th>
					<td>
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<label>
							<input type="checkbox"
								name="<?php echo esc_attr( self::FIELD ); ?>"
								value="1"
								<?php checked( $enabled ); ?> />
							<?php esc_html_e( 'Enable the Double Youpï admin interface for this account', 'doubleyoupi-refined-admin-dashboard' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When disabled, this account uses the standard WordPress admin. The administrator settings (accent color, logo) still apply when enabled.', 'doubleyoupi-refined-admin-dashboard' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>
		<?php
		// WordPress exposes no hook BEFORE the "Personal Options" block of the
		// profile form (the first one, `personal_options`, is already inside that
		// table). We therefore render the block at the bottom of the form via
		// show_user_profile / edit_user_profile, then reposition it at the top of
		// the <form> on the client side.
		//
		// The script is inline + self-contained (no dependency on redesign assets)
		// because the block must also display for an opted-out account.
		// Immediate synchronous execution: the block above is already in the DOM
		// and form.firstChild (the <h2> "Personal Options") has already been parsed,
		// so the move happens before the first paint (no visual jump).
		wp_print_inline_script_tag( self::ADMIN_USER_PERSO_REPOSITION_JS, [ 'id' => 'doubleyoupi-admin-user-perso-reposition' ] );
	}

	public function save_profile_fields( int $user_id ): void {
		if ( ! self::is_per_user_mode() ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		self::set_enabled_for_user( $user_id, isset( $_POST[ self::FIELD ] ) );
	}
}

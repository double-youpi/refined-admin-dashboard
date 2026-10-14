<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit;

use DoubleYoupi\Admin\AdminMenu;
use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page "Double Youpi > Tools": the Toolkit toggles, on their own sub-page
 * (no longer in the Overview). As the Toolkit is bundled with Refined Admin
 * Dashboard, this page only appears when the Admin module is active
 * (see Toolkit::is_available()).
 *
 * Standalone form: its own admin_post action, nonce, and notice transient.
 *
 * UI: native WordPress classes (CLAUDE.md §9.3-bis) — `.wrap`, `.form-table`,
 * `.button`, `.notice`. The card visual is handled by `_toolkit.scss`
 * (context `.dy-tools`).
 */
final class ToolsPage {

	public const PAGE_SLUG = 'doubleyoupi-tools';

	private const FORM_ACTION      = 'doubleyoupi_toolkit_toggle';
	private const NONCE_NAME       = '_doubleyoupi_toolkit_nonce';
	private const FIELD_PREFIX     = 'doubleyoupi_toolkit_';
	private const NOTICE_TRANSIENT = 'doubleyoupi_toolkit_notice';

	/**
	 * Registers the sub-page and the form handler. Called from Toolkit::boot().
	 */
	public static function register(): void {
		AdminMenu::add_page(
			static fn(): string => __( 'Double Youpï · Tools', 'doubleyoupi-refined-admin-dashboard' ),
			static fn(): string => __( 'Tools', 'doubleyoupi-refined-admin-dashboard' ),
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
		add_action( 'admin_post_' . self::FORM_ACTION, [ self::class, 'handle_form' ] );
	}

	public static function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$features = Settings::features();
		$notice   = get_transient( self::NOTICE_TRANSIENT );
		if ( $notice ) {
			delete_transient( self::NOTICE_TRANSIENT );
		}
		?>
		<div class="wrap dy-tools">
			<h1><?php esc_html_e( 'Tools', 'doubleyoupi-refined-admin-dashboard' ); ?></h1>

			<?php // Admin notice placement target (full width). ?>
			<hr class="wp-header-end">

			<section class="dy-toolkit">
				<p class="dy-toolkit__intro">
					<?php esc_html_e( 'Toggles that replace the common micro-plugins: Classic Editor, Disable Comments, Duplicate Post, Really Simple SSL, Safe SVG. If one of these plugins is already active, we hand control over to it.', 'doubleyoupi-refined-admin-dashboard' ); ?>
				</p>

				<?php if ( $notice ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dy-toolkit__form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::FORM_ACTION ); ?>">
					<?php wp_nonce_field( self::FORM_ACTION, self::NONCE_NAME ); ?>

					<div class="dy-toolkit__grid">
						<?php
						$i = 0;
						foreach ( $features as $feature ) :
							self::render_card( $feature, $i );
							$i++;
						endforeach;
						?>
					</div>

					<div class="dy-toolkit__actions">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save the tools', 'doubleyoupi-refined-admin-dashboard' ); ?>
						</button>
					</div>
				</form>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders an individual card (one feature = one horizontal card).
	 *
	 * State managed via `data-state`:
	 *   - default        : not enabled, editable
	 *   - active         : enabled, editable
	 *   - blocked-plugin : plugin conflict detected → toggle locked
	 *   - blocked-env    : local environment etc. → toggle locked
	 */
	private static function render_card( Features\AbstractFeature $feature, int $index ): void {
		$conflict    = $feature->active_conflict();
		$env_block   = $feature->environment_block();
		$is_enabled  = $feature->is_enabled();
		$is_blocked  = $conflict !== null || $env_block !== null;
		$field_name  = self::FIELD_PREFIX . $feature->id();

		$state = 'default';
		if ( $is_blocked ) {
			$state = $conflict !== null ? 'blocked-plugin' : 'blocked-env';
		} elseif ( $is_enabled ) {
			$state = 'active';
		}

		$state_label = match ( $state ) {
			'active'         => __( 'Enabled', 'doubleyoupi-refined-admin-dashboard' ),
			'blocked-plugin' => __( 'Delegated', 'doubleyoupi-refined-admin-dashboard' ),
			'blocked-env'    => __( 'Blocked', 'doubleyoupi-refined-admin-dashboard' ),
			default          => __( 'Disabled', 'doubleyoupi-refined-admin-dashboard' ),
		};

		?>
		<label class="dy-toolkit__card"
		       data-state="<?php echo esc_attr( $state ); ?>"
		       data-feature="<?php echo esc_attr( $feature->id() ); ?>"
		       style="--dya-card-i: <?php echo (int) $index; ?>;">

			<input type="checkbox"
			       class="dy-toolkit__input"
			       name="<?php echo esc_attr( $field_name ); ?>"
			       value="1"
			       <?php checked( $is_enabled && ! $is_blocked ); ?>
			       <?php disabled( $is_blocked ); ?> />

			<span class="dy-toolkit__icon" aria-hidden="true">
				<?php
				echo wp_kses( $feature->icon_svg(), Kses::svg_tags() );
				?>
			</span>

			<div class="dy-toolkit__body">
				<h3 class="dy-toolkit__name"><?php echo esc_html( $feature->label() ); ?></h3>
				<p class="dy-toolkit__desc"><?php echo esc_html( $feature->description() ); ?></p>
				<?php
				$extra = $feature->extra_settings_html();
				if ( $extra !== '' ) {
					echo wp_kses( $extra, Kses::ui_tags() );
					$script = $feature->extra_settings_script();
					if ( '' !== $script ) {
						wp_print_inline_script_tag( $script, [ 'id' => 'doubleyoupi-toolkit-' . sanitize_key( $feature->id() ) . '-script' ] );
					}
				}
				?>
			</div>

			<span class="dy-toolkit__switch">
				<span class="dy-toolkit__switch-track" aria-hidden="true">
					<span class="dy-toolkit__switch-thumb"></span>
				</span>
			</span>

			<span class="dy-toolkit__status">
				<span class="dy-toolkit__led" aria-hidden="true"></span>
				<span class="dy-toolkit__status-label"><?php echo esc_html( $state_label ); ?></span>
			</span>

			<?php if ( $conflict !== null ) : ?>
				<div class="dy-toolkit__block" role="note">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="dy-toolkit__block-icon">
						<rect x="3" y="11" width="18" height="11" rx="2"/>
						<path d="M7 11V7a5 5 0 0 1 10 0v4"/>
					</svg>
					<span>
						<?php
						printf(
							/* translators: %s: name of the detected third-party plugin */
							esc_html__( '%s detected, we hand control over to it.', 'doubleyoupi-refined-admin-dashboard' ),
							esc_html( $conflict['name'] )
						);
						?>
					</span>
				</div>
			<?php endif; ?>

			<?php if ( $env_block !== null ) : ?>
				<div class="dy-toolkit__block dy-toolkit__block--env" role="note">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="dy-toolkit__block-icon">
						<path d="M12 9v4M12 17h.01"/>
						<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
					</svg>
					<span><?php echo esc_html( $env_block ); ?></span>
				</div>
			<?php endif; ?>

		</label>
		<?php
	}

	/**
	 * POST handler for the tools form. Persists the state of each feature
	 * based on the presence/absence of its checkbox in $_POST.
	 *
	 * Security: `manage_options` capability + nonce. Only writes for known
	 * features (see Settings::features()).
	 */
	public static function handle_form(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Access denied.', 'doubleyoupi-refined-admin-dashboard' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::FORM_ACTION, self::NONCE_NAME );

		// Only our own fields, unslashed and sanitized once, here.
		$posted = [];
		foreach ( array_keys( $_POST ) as $key ) {
			$key = (string) $key;
			if ( str_starts_with( $key, self::FIELD_PREFIX ) && isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
				$posted[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
			}
		}
		$posted_keys   = array_keys( $posted );
		$count_changes = 0;

		foreach ( Settings::features() as $feature ) {
			if ( $feature->is_externally_blocked() ) {
				continue;
			}
			$field_name = self::FIELD_PREFIX . $feature->id();
			$enabled    = in_array( $field_name, $posted_keys, true );
			$current    = $feature->is_enabled();

			$feature->on_save( $posted, $current, $enabled );

			if ( $current !== $enabled ) {
				update_option( $feature->option_key(), $enabled, false );
				$count_changes++;
			}
		}

		set_transient(
			self::NOTICE_TRANSIENT,
			$count_changes > 0
				? __( 'Tools updated. Reload the page to see the effects.', 'doubleyoupi-refined-admin-dashboard' )
				: __( 'No changes to save.', 'doubleyoupi-refined-admin-dashboard' ),
			30
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AdminColumnsConflict — Admin Columns (codepress-admin-columns) rewrites the
 * columns of `wp-list-table`, which our reimagined tables view
 * (TablesReimagined) also redraws.
 *
 * Product rule (CLAUDE.md §2, "detect and step aside"): when Admin Columns is
 * active, Refined Admin Dashboard leaves the lists it manages alone (native
 * layout, no reimagined view) and says so once, in a notice. Admin Columns is
 * NEVER deactivated on our own: the notice offers a button, and only a click by
 * someone allowed to manage plugins deactivates it (WordPress.org review,
 * 12/10/2026). Forced cohabitation: filter
 * `doubleyoupi_filter_admin_columns_step_aside` (return false).
 */
final class AdminColumnsConflict {

	/** Paths of the Admin Columns plugins (free + Pro). */
	private const PLUGINS = [
		'codepress-admin-columns/codepress-admin-columns.php',
		'admin-columns-pro/admin-columns-pro.php',
	];

	/** Table kinds Admin Columns manages (see TablesReimagined::target_screen()). */
	public const MANAGED_KINDS = [ 'post', 'user', 'term', 'orders' ];

	/** Global dismissal of the notice (option, autoload off). */
	private const DISMISSED_FLAG = 'doubleyoupi_admin_columns_notice_dismissed';

	private const ACTION_ARG   = 'doubleyoupi_ac_action';
	private const ACTION_NONCE = 'doubleyoupi_ac_action';

	/** Short-lived per-user confirmation after a requested deactivation. */
	private const DONE_TRANSIENT = 'doubleyoupi_ac_deactivated_';

	public function boot(): void {
		add_action( 'admin_init', [ $this, 'maybe_handle_action' ] );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	/**
	 * Active Admin Columns plugin files (free and/or Pro).
	 *
	 * @return string[]
	 */
	private static function active_plugins(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array_values( array_filter( self::PLUGINS, 'is_plugin_active' ) );
	}

	/**
	 * True when Admin Columns is active and we step aside on the lists it manages.
	 */
	public static function steps_aside(): bool {
		if ( empty( self::active_plugins() ) ) {
			return false;
		}
		/**
		 * Filter: doubleyoupi_filter_admin_columns_step_aside
		 * Return false to keep the reimagined view on lists even with Admin Columns.
		 *
		 * @param bool $step_aside Default true.
		 */
		return (bool) apply_filters( 'doubleyoupi_filter_admin_columns_step_aside', true );
	}

	/**
	 * Handles the two links of the notice: "Deactivate Admin Columns" (explicit,
	 * user-initiated) and "Keep Admin Columns" (dismisses the notice for good).
	 */
	public function maybe_handle_action(): void {
		if ( empty( $_GET[ self::ACTION_ARG ] ) ) {
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::ACTION_NONCE ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET[ self::ACTION_ARG ] ) );
		if ( 'deactivate' === $action ) {
			$active = self::active_plugins();
			if ( ! empty( $active ) ) {
				deactivate_plugins( $active );
				set_transient( self::DONE_TRANSIENT . get_current_user_id(), 1, MINUTE_IN_SECONDS );
			}
		} elseif ( 'keep' === $action ) {
			update_option( self::DISMISSED_FLAG, 1, false );
		} else {
			return;
		}

		wp_safe_redirect( remove_query_arg( [ self::ACTION_ARG, '_wpnonce' ] ) );
		exit;
	}

	/**
	 * Screens where the notice is relevant: the plugins screen and the lists
	 * Admin Columns manages. Nowhere else (Guideline 11: notices in moderation).
	 */
	private function is_relevant_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->base, [ 'plugins', 'edit', 'users', 'edit-tags' ], true )
			|| 'woocommerce_page_wc-orders' === $screen->id;
	}

	public function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( get_transient( self::DONE_TRANSIENT . $user_id ) ) {
			delete_transient( self::DONE_TRANSIENT . $user_id );
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html__( 'Admin Columns deactivated.', 'doubleyoupi-refined-admin-dashboard' )
			);
			return;
		}

		if ( ! self::steps_aside() || get_option( self::DISMISSED_FLAG ) || ! $this->is_relevant_screen() ) {
			return;
		}

		$deactivate_url = wp_nonce_url( add_query_arg( self::ACTION_ARG, 'deactivate' ), self::ACTION_NONCE );
		$keep_url       = wp_nonce_url( add_query_arg( self::ACTION_ARG, 'keep' ), self::ACTION_NONCE );

		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a></p></div>',
			esc_html__( 'Admin Columns is active.', 'doubleyoupi-refined-admin-dashboard' ),
			esc_html__( 'It manages the columns of your lists, so Refined Admin Dashboard leaves those lists to it and keeps their native layout. Deactivate Admin Columns if you prefer the redesigned view.', 'doubleyoupi-refined-admin-dashboard' ),
			esc_url( $deactivate_url ),
			esc_html__( 'Deactivate Admin Columns', 'doubleyoupi-refined-admin-dashboard' ),
			esc_url( $keep_url ),
			esc_html__( 'Keep Admin Columns', 'doubleyoupi-refined-admin-dashboard' )
		);
	}
}

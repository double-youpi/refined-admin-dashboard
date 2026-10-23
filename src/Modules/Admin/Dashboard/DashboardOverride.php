<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard;

use DoubleYoupi\Modules\Admin\Dashboard\Compat\WpDashboardWidgetsAdapter;
use DoubleYoupi\Modules\Admin\Dashboard\Layout\Snapshot;
use DoubleYoupi\Modules\Admin\Dashboard\Tokens\TokenResolver;
use DoubleYoupi\Admin\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Overrides wp_dashboard_setup to replace the native WP Dashboard.
 *
 * Strategy:
 *   1. Clear native meta-boxes (At a Glance, WP Activity, etc.)
 *   2. Add ONE single meta-box `doubleyoupi_dashboard_root` to serve as the
 *      unique container for our grid
 *   3. Force the layout to 1 column (our grid manages its own columns)
 *
 * Third-party widgets (Yoast, WC, Bricks) remain registered via their own
 * wp_add_dashboard_widget calls — they will be offered in the "Plugins" tab
 * of the Customize panel in phase 2.
 */
final class DashboardOverride {

	/** Neutralises the native containers that constrain the dashboard width (index.php only). */
	private const POSTBOX_RESET_CSS = <<<'CSS'
	/* v4 — The dashboard occupies 100% of the content area (between the
	   left rail and the right edge). All native WP containers that
	   constrain the width are neutralised on the index.php screen. */
	body.index-php #wpbody-content { padding-bottom: 0 !important; }
	body.index-php #wpbody-content > .wrap {
		max-width: none !important;
		margin: 0 !important;
		padding: 0 !important;
	}
	body.index-php .wrap > h1.wp-heading-inline,
	body.index-php .wrap > h1:not(.dy-dashboard__title) { display: none !important; }
	body.index-php .page-title-action { display: none !important; }
	body.index-php #dashboard-widgets-wrap {
		padding: 0 !important;
		margin: 0 !important;
		max-width: none !important;
		width: 100% !important;
	}
	body.index-php #dashboard-widgets {
		display: block !important;
		margin: 0 !important;
		width: 100% !important;
	}
	body.index-php #dashboard-widgets .postbox-container {
		width: 100% !important;
		float: none !important;
		max-width: none !important;
	}
	body.index-php #dashboard-widgets .meta-box-sortables {
		min-height: 0 !important;
		display: block !important;
	}
	/* Root container: neutralise the WP 'postbox' appearance. */
	#doubleyoupi_dashboard_root {
		background: transparent !important;
		border: none !important;
		box-shadow: none !important;
		margin: 0 !important;
		padding: 0 !important;
	}
	#doubleyoupi_dashboard_root .postbox-header,
	#doubleyoupi_dashboard_root > .postbox-header { display: none !important; }
	#doubleyoupi_dashboard_root .inside {
		padding: 0 !important;
		margin: 0 !important;
		min-height: 0 !important;
		background-color: var(--dya-bg, #0c0e13) !important;
	}
	#doubleyoupi_dashboard_root .handle-actions,
	#doubleyoupi_dashboard_root .handlediv { display: none !important; }
	/* WP Welcome panel: hidden (we have our own title/paragraph). */
	#welcome-panel { display: none !important; }
	/* Force full width for the body content (in case another WP stylesheet
	   has set a max-width). */
	body.index-php #wpcontent { padding-left: 0 !important; }
CSS;


	public static function register(): void {
		global $wp_meta_boxes;

		// This method is hooked to `wp_dashboard_setup` at PHP_INT_MAX priority
		// (see Dashboard::boot) — it runs after WP core and all plugins have
		// registered their widgets via this hook.
		//
		// 1. Capture the full state — used for the native WP screen widget list.
		WpDashboardWidgetsAdapter::snapshot();

		// 2. Clear the ENTIRE native dashboard. Our Renderer takes over; native
		//    widgets are rendered only in the native WP screen (last screen,
		//    can be disabled).
		$wp_meta_boxes['dashboard'] = [];

		// 3. Add our root meta-box — the only one rendered by wp_dashboard().
		wp_add_dashboard_widget(
			'doubleyoupi_dashboard_root',
			__( 'Double Youpï Dashboard', 'doubleyoupi-refined-admin-dashboard' ),
			[ self::class, 'render_root' ]
		);

		// 4. Safety net: some plugins register widgets via add_meta_boxes or
		//    later hooks (rare but it happens). We re-clear at the very last
		//    moment before rendering, preserving our root widget only.
		//    Hook do_meta_boxes priority 1 = just before WP's internal foreach.
		add_action( 'do_meta_boxes', [ self::class, 'late_purge' ], 1, 3 );

		// 5. Force 1 column for our dashboard.
		add_filter( 'screen_layout_columns', static fn() => [ 'dashboard' => 1 ] );
		add_filter( 'get_user_option_screen_layout_dashboard', static fn() => 1 );
		add_action( 'admin_print_styles-index.php', [ self::class, 'inline_postbox_reset' ] );
	}

	/**
	 * Last-resort safety guard. Re-captures widgets added late (between
	 * `wp_dashboard_setup` and actual rendering), then clears again.
	 * Preserves our `doubleyoupi_dashboard_root`.
	 */
	public static function late_purge( string $screen_id, string $context, $screen ): void {
		unset( $context, $screen );
		if ( $screen_id !== 'dashboard' ) {
			return;
		}
		global $wp_meta_boxes;
		if ( ! isset( $wp_meta_boxes['dashboard'] ) || ! is_array( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}

		// Detect post-snapshot additions: anything not in the initial snapshot
		// and not our root.
		$snapshot_ids = array_keys( WpDashboardWidgetsAdapter::captured() );
		$keep_only    = 'doubleyoupi_dashboard_root';
		$late_added   = [];

		foreach ( $wp_meta_boxes['dashboard'] as $ctx => $priorities ) {
			if ( ! is_array( $priorities ) ) {
				continue;
			}
			foreach ( $priorities as $priority => $boxes ) {
				if ( ! is_array( $boxes ) ) {
					continue;
				}
				foreach ( $boxes as $id => $box ) {
					if ( $id === $keep_only ) {
						continue;
					}
					if ( ! in_array( (string) $id, $snapshot_ids, true ) && is_array( $box ) ) {
						// Widget added AFTER wp_dashboard_setup → add it to the snapshot so it
						// appears in the native WP screen, then remove it from the live dashboard.
						WpDashboardWidgetsAdapter::capture_late( (string) $id, $box );
						$late_added[] = (string) $id;
					}
				}
			}
		}

		// Re-clear, keeping only our root.
		$root_box = null;
		foreach ( $wp_meta_boxes['dashboard'] as $ctx => $priorities ) {
			if ( ! is_array( $priorities ) ) {
				continue;
			}
			foreach ( $priorities as $priority => $boxes ) {
				if ( is_array( $boxes ) && isset( $boxes[ $keep_only ] ) ) {
					$root_box = [
						'context'  => $ctx,
						'priority' => $priority,
						'box'      => $boxes[ $keep_only ],
					];
					break 2;
				}
			}
		}

		$wp_meta_boxes['dashboard'] = [];
		if ( $root_box ) {
			$wp_meta_boxes['dashboard'][ $root_box['context'] ][ $root_box['priority'] ][ $keep_only ] = $root_box['box'];
		} else {
			// If for any reason our root has disappeared, re-add it.
			wp_add_dashboard_widget(
				$keep_only,
				__( 'Double Youpï Dashboard', 'doubleyoupi-refined-admin-dashboard' ),
				[ self::class, 'render_root' ]
			);
		}
	}

	/**
	 * Renders the root container. Inner HTML is generated by Renderer from the
	 * full snapshot (layout + data).
	 *
	 * v4 — The bootstrap is exposed in two formats:
	 *   - data-bootstrap (JSON) on the container, for readers without JS
	 *     or to re-hydrate after soft navigation.
	 *   - window.doubleyoupiDashboardData (global JS) via inline script, for direct
	 *     access from dashboard.js without JSON parsing.
	 */
	public static function render_root(): void {
		$user_id  = get_current_user_id();
		$snapshot = Snapshot::build( $user_id );

		$bootstrap = [
			'layout'       => $snapshot['layout'],
			'widget_data'  => $snapshot['widget_data'],
			'catalog'      => $snapshot['catalog'],
			'presets'      => $snapshot['presets'],
			'dispositions' => $snapshot['dispositions'],
			'role_assignments' => $snapshot['role_assignments'],
			'role_defaults' => $snapshot['role_defaults'],
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'rest_url'     => esc_url_raw( rest_url( 'doubleyoupi/v1/dashboard' ) ),
			'caps'         => [
				'can_customize' => current_user_can( 'doubleyoupi_customize_dashboard' )
					|| current_user_can( 'manage_options' ),
				'can_manage'    => current_user_can( Access::CAP ),
			],
			'breakpoints' => [
				'customize_min_px' => 1300,
				'slots_full_min_px' => 1500,
			],
			'tokens'       => self::tokens_for_picker(),
			'token_values' => self::resolved_token_values(),
			'roles'        => self::roles_list(),
			'i18n'         => self::i18n_strings(),
		];

		printf(
			'<div id="dy-dashboard-root" class="dy-dashboard" data-bootstrap="%s">',
			esc_attr( (string) wp_json_encode( $bootstrap ) )
		);

		Renderer::print_layout( $snapshot['layout'], $snapshot['widget_data'] );

		echo '</div>';

		// SYNCHRONOUS restoration of the active screen, BEFORE the first paint, to
		// avoid a flash of screen 1 on reload / browser back. dashboard.js (loaded
		// in the footer) would do this too late → we set the scroll here, inline,
		// as soon as the screen markup is parsed. We read the same sessionStorage key
		// as Nav ("dy-dash-screen:<pathname>") and set the scroll to the target
		// screen's offsetLeft (exact, no width calculation needed).
		$restore = '(function(){try{'
			. 'var k="dy-dash-screen:"+location.pathname;'
			. 'var i=parseInt(sessionStorage.getItem(k),10);'
			. 'if(!i||i<0)return;'
			. 'var vp=document.querySelector("#dy-dashboard-root .dy-dashboard__viewport");'
			. 'if(!vp||vp.scrollWidth<=vp.clientWidth+4)return;' // vertical layout (mobile) → nothing
			. 'var s=vp.querySelectorAll(":scope > .dy-dashboard__screen");'
			. 'if(i>=s.length)return;'
			. 'vp.style.scrollBehavior="auto";'
			. 'vp.scrollLeft=s[i].offsetLeft;'
			. 'vp.setAttribute("data-restored-screen",String(i));'
			. '}catch(e){}})();';
		wp_print_inline_script_tag( $restore, [ 'id' => 'doubleyoupi-dashboard-restore-screen' ] );

		// Global JS bootstrap — loaded inline just after the markup so that
		// dashboard.js finds it already available at execution time.
		wp_print_inline_script_tag(
			'window.doubleyoupiDashboardData = ' . wp_json_encode( $bootstrap, JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			[ 'id' => 'doubleyoupi-dashboard-bootstrap' ]
		);
	}

	/**
	 * Aggressive override of WP styles that constrain the Dashboard.
	 *
	 * Several issues to address:
	 *   1. WP wraps content in `.wrap` which adds padding/margin
	 *   2. `#dashboard-widgets-wrap` has 8px lateral padding
	 *   3. `#dashboard-widgets` is a flex container that splits into columns
	 *   4. `.postbox-container` limits width to ~50%
	 *   5. The root meta-box renders as a "postbox" (border, header)
	 *
	 * Goal: ensure `#doubleyoupi_dashboard_root` occupies 100% of the available
	 * width (viewport minus sidebar rail). Without this, container queries and
	 * media queries in the dashboard detect a wrong width.
	 *
	 * Hooked to `admin_print_styles-index.php` so it only applies on this screen.
	 */
	/**
	 * List of available tokens for the "dynamic content" picker in the Customize
	 * panel. Format: [{ token, label, group }].
	 *
	 * @return array<int, array{token:string, label:string, group:string}>
	 */
	private static function tokens_for_picker(): array {
		$groups = [
			'{{user.firstname}}'  => 'user',
			'{{user.name}}'       => 'user',
			'{{site.name}}'       => 'site',
			'{{site.url}}'        => 'site',
			'{{date.greeting}}'   => 'date',
			'{{date.day}}'        => 'date',
			'{{date.now}}'        => 'date',
			'{{updates.summary}}' => 'updates',
		];
		$out = [];
		foreach ( TokenResolver::AVAILABLE as $token => $label ) {
			$out[] = [
				'token' => (string) $token,
				'label' => (string) $label,
				'group' => $groups[ $token ] ?? 'misc',
			];
		}
		return $out;
	}

	/**
	 * Server-side resolved token values, so the JS can display a live preview
	 * (title/paragraph) without re-implementing the full resolution logic.
	 * Keys = '{{user.firstname}}', values = resolved string.
	 *
	 * @return array<string, string>
	 */
	private static function resolved_token_values(): array {
		$out = [];
		foreach ( array_keys( TokenResolver::AVAILABLE ) as $token ) {
			$out[ (string) $token ] = TokenResolver::resolve( (string) $token );
		}
		return $out;
	}

	/**
	 * List of WP roles with their translated names (slug => label). Used by the
	 * "Assign to role" menu in the Customize panel.
	 *
	 * @return array<string, string>
	 */
	private static function roles_list(): array {
		$out = [];
		$wp_roles = wp_roles();
		foreach ( $wp_roles->get_names() as $slug => $name ) {
			$out[ (string) $slug ] = translate_user_role( (string) $name );
		}
		return $out;
	}

	/**
	 * User-facing strings used on the JS side, exposed as translated values in
	 * the bootstrap. Keeps i18n centralised in the gettext pipeline
	 * (.po/.mo, text domain `doubleyoupi`) without relying on wp_set_script_translations.
	 *
	 * Strings containing %s/%d are formatted on the JS side (Util.sprintf).
	 *
	 * @return array<string, string>
	 */
	private static function i18n_strings(): array {
		return [
			// Panel / sections.
			'customize'          => __( 'Customize', 'doubleyoupi-refined-admin-dashboard' ),
			'close_panel'        => __( 'Close customize panel', 'doubleyoupi-refined-admin-dashboard' ),
			'screen_current'     => __( 'Current screen', 'doubleyoupi-refined-admin-dashboard' ),
			'field_title'        => __( 'Title', 'doubleyoupi-refined-admin-dashboard' ),
			'field_paragraph'    => __( 'Paragraph', 'doubleyoupi-refined-admin-dashboard' ),
			'section_widgets'    => __( 'Widgets', 'doubleyoupi-refined-admin-dashboard' ),
			'section_layout'     => __( 'Layout', 'doubleyoupi-refined-admin-dashboard' ),
			'section_screens'    => __( 'Screens', 'doubleyoupi-refined-admin-dashboard' ),
			'section_advanced'   => __( 'Advanced', 'doubleyoupi-refined-admin-dashboard' ),
			'section_widget'     => __( 'Widget', 'doubleyoupi-refined-admin-dashboard' ),
			'widget_no_settings' => __( 'This widget has no settings.', 'doubleyoupi-refined-admin-dashboard' ),
			'add_item'           => __( '+ Add', 'doubleyoupi-refined-admin-dashboard' ),
			'remove_item'        => __( 'Remove', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %d: maximum number of items */
			'max_items'          => __( 'Maximum %d items.', 'doubleyoupi-refined-admin-dashboard' ),
			'note_title_ph'      => __( 'Note title', 'doubleyoupi-refined-admin-dashboard' ),
			'note_body_ph'       => __( 'Click to write your note…', 'doubleyoupi-refined-admin-dashboard' ),
			'backup_started'     => __( 'Backup started.', 'doubleyoupi-refined-admin-dashboard' ),
			'backup_failed'      => __( 'Could not start the backup.', 'doubleyoupi-refined-admin-dashboard' ),
			'drag_hint'          => __( 'Drag a tile onto the current screen, or click it to add it.', 'doubleyoupi-refined-admin-dashboard' ),
			'assign_role'        => __( 'Assign to role', 'doubleyoupi-refined-admin-dashboard' ),
			'role_none'          => __( 'None', 'doubleyoupi-refined-admin-dashboard' ),
			'lock_layout'        => __( 'Lock the layout', 'doubleyoupi-refined-admin-dashboard' ),
			'export_json'        => __( 'Export JSON', 'doubleyoupi-refined-admin-dashboard' ),
			'import'             => __( 'Import', 'doubleyoupi-refined-admin-dashboard' ),
			'add_screen'         => __( '+ Add a screen', 'doubleyoupi-refined-admin-dashboard' ),
			'show_wp_screen'     => __( 'Show the WordPress screen (last)', 'doubleyoupi-refined-admin-dashboard' ),
			'autosave'           => __( 'Auto-sync', 'doubleyoupi-refined-admin-dashboard' ),
			'saving'             => __( 'Saving…', 'doubleyoupi-refined-admin-dashboard' ),
			'saved'              => __( 'Saved', 'doubleyoupi-refined-admin-dashboard' ),
			'reset'              => __( 'Reset', 'doubleyoupi-refined-admin-dashboard' ),
			'insert_token'       => __( 'Insert dynamic content', 'doubleyoupi-refined-admin-dashboard' ),

			// Dispositions.
			'custom_layout'      => __( 'Custom', 'doubleyoupi-refined-admin-dashboard' ),
			'custom_layout_desc' => __( 'Your current settings', 'doubleyoupi-refined-admin-dashboard' ),
			'save_as_disposition' => __( 'Save as disposition', 'doubleyoupi-refined-admin-dashboard' ),
			'save_disposition_prompt' => __( 'Disposition name:', 'doubleyoupi-refined-admin-dashboard' ),
			'disposition_name_placeholder' => __( 'Disposition name', 'doubleyoupi-refined-admin-dashboard' ),
			'save_new_disposition' => __( 'New disposition', 'doubleyoupi-refined-admin-dashboard' ),
			'save'               => __( 'Save', 'doubleyoupi-refined-admin-dashboard' ),
			'cancel'             => __( 'Cancel', 'doubleyoupi-refined-admin-dashboard' ),
			'delete_disposition' => __( 'Delete this disposition', 'doubleyoupi-refined-admin-dashboard' ),
			'disposition_saved'  => __( 'Disposition saved.', 'doubleyoupi-refined-admin-dashboard' ),
			'disposition_updated' => __( 'Disposition updated.', 'doubleyoupi-refined-admin-dashboard' ),
			'disposition_deleted' => __( 'Disposition deleted.', 'doubleyoupi-refined-admin-dashboard' ),
			'not_styled_yet'     => __( 'Not styled yet', 'doubleyoupi-refined-admin-dashboard' ),
			'catalog_extensions' => __( 'From your plugins', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: disposition name */
			'confirm_delete_disposition' => __( 'Delete the disposition « %s »?', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: disposition name */
			'apply_disposition_confirm' => __( 'Replace the current layout with « %s »? Unsaved changes will be lost.', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: disposition name */
			'update_disposition_confirm' => __( 'Update « %s » with the current settings?', 'doubleyoupi-refined-admin-dashboard' ),
			'confirm_clear_all'  => __( 'Clear everything and start from a single blank screen?', 'doubleyoupi-refined-admin-dashboard' ),
			'invalid_disposition_file' => __( 'This file is not a valid disposition export.', 'doubleyoupi-refined-admin-dashboard' ),
			'imported_disposition' => __( 'Imported disposition', 'doubleyoupi-refined-admin-dashboard' ),
			// Three-dot context menu for dispositions.
			'more_actions'       => __( 'More actions', 'doubleyoupi-refined-admin-dashboard' ),
			'menu_apply'         => __( 'Apply', 'doubleyoupi-refined-admin-dashboard' ),
			'menu_update_current' => __( 'Update with current settings', 'doubleyoupi-refined-admin-dashboard' ),
			'menu_export'        => __( 'Export', 'doubleyoupi-refined-admin-dashboard' ),
			'menu_delete'        => __( 'Delete', 'doubleyoupi-refined-admin-dashboard' ),
			'menu_clear_all'     => __( 'Clear everything', 'doubleyoupi-refined-admin-dashboard' ),
			'menu_save_new'      => __( 'Save as new disposition', 'doubleyoupi-refined-admin-dashboard' ),
			'menu_import_disposition' => __( 'Import a disposition', 'doubleyoupi-refined-admin-dashboard' ),
			'menu_assign_roles'  => __( 'Assign to role(s)…', 'doubleyoupi-refined-admin-dashboard' ),
			'menu_lock_layout'   => __( 'Lock', 'doubleyoupi-refined-admin-dashboard' ),
			'menu_unlock_layout' => __( 'Unlock', 'doubleyoupi-refined-admin-dashboard' ),
			'layout_locked'      => __( 'Layout locked.', 'doubleyoupi-refined-admin-dashboard' ),
			'layout_unlocked'    => __( 'Layout unlocked.', 'doubleyoupi-refined-admin-dashboard' ),
			// Role assignment modal.
			/* translators: %s: disposition name */
			'assign_roles_to'    => __( 'Assign « %s » to roles', 'doubleyoupi-refined-admin-dashboard' ),
			'assign_roles_hint'  => __( 'Users with these roles will see this disposition (read-only).', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: another disposition name */
			'role_already_on'    => __( 'already on %s', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: disposition name used by default for this role */
			'role_default_is'    => __( 'default: %s', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: list of roles and their current disposition */
			'role_conflict_confirm' => __( 'These roles are already assigned to another disposition: %s. Switch them to this one?', 'doubleyoupi-refined-admin-dashboard' ),
			'roles_assigned'     => __( 'Roles assigned.', 'doubleyoupi-refined-admin-dashboard' ),

			// Widgets / slots.
			'delete_widget'      => __( 'Remove this widget', 'doubleyoupi-refined-admin-dashboard' ),
			'delete_screen'      => __( 'Delete this screen', 'doubleyoupi-refined-admin-dashboard' ),
			'widget_added'       => __( 'Widget added.', 'doubleyoupi-refined-admin-dashboard' ),
			'widget_removed'     => __( 'Widget removed.', 'doubleyoupi-refined-admin-dashboard' ),
			'position_updated'   => __( 'Position updated.', 'doubleyoupi-refined-admin-dashboard' ),
			'drop_impossible'    => __( 'Cannot drop on this screen.', 'doubleyoupi-refined-admin-dashboard' ),
			'no_room_target'     => __( 'No room left on the target screen.', 'doubleyoupi-refined-admin-dashboard' ),
			'no_room_screen'     => __( 'No room left on this screen (6 slots max).', 'doubleyoupi-refined-admin-dashboard' ),
			'render_error'       => __( 'Render error', 'doubleyoupi-refined-admin-dashboard' ),

			// Screens / miscellaneous.
			/* translators: %d: screen number */
			'screen_n'           => __( 'Screen %d', 'doubleyoupi-refined-admin-dashboard' ),
			'insert_screen_here' => __( 'Insert a screen here', 'doubleyoupi-refined-admin-dashboard' ),
		'add_screen_plain'   => __( 'Add a screen', 'doubleyoupi-refined-admin-dashboard' ),
			'blank_slate_title'  => __( 'Back to square one', 'doubleyoupi-refined-admin-dashboard' ),
			// 5 "simple and fun" titles chosen at random when a screen is created.
			'new_screen_title_1' => __( 'A fresh canvas', 'doubleyoupi-refined-admin-dashboard' ),
			'new_screen_title_2' => __( 'Room to breathe', 'doubleyoupi-refined-admin-dashboard' ),
			'new_screen_title_3' => __( 'The next chapter', 'doubleyoupi-refined-admin-dashboard' ),
			'new_screen_title_4' => __( 'Terra incognita', 'doubleyoupi-refined-admin-dashboard' ),
			'new_screen_title_5' => __( 'Something new', 'doubleyoupi-refined-admin-dashboard' ),
			'min_one_screen'     => __( 'At least one user screen is required.', 'doubleyoupi-refined-admin-dashboard' ),
			'confirm_delete_screen' => __( 'Delete this screen?', 'doubleyoupi-refined-admin-dashboard' ),
			'confirm_reset'      => __( 'Reset to the standard layout? All customizations will be lost.', 'doubleyoupi-refined-admin-dashboard' ),
			'layout_exported'    => __( 'Layout exported', 'doubleyoupi-refined-admin-dashboard' ),
			'invalid_json'       => __( 'Invalid JSON', 'doubleyoupi-refined-admin-dashboard' ),
			'save_failed'        => __( 'Save failed', 'doubleyoupi-refined-admin-dashboard' ),
			'error'              => __( 'Error', 'doubleyoupi-refined-admin-dashboard' ),
			// #6 — Undo / Redo history.
			'history'            => __( 'History', 'doubleyoupi-refined-admin-dashboard' ),
			'undo'               => __( 'Undo', 'doubleyoupi-refined-admin-dashboard' ),
			'redo'               => __( 'Redo', 'doubleyoupi-refined-admin-dashboard' ),
			// #3 — inline editing placeholders for title / paragraph.
			'screen_title_ph'     => __( 'Screen title', 'doubleyoupi-refined-admin-dashboard' ),
			'screen_paragraph_ph' => __( 'Add a paragraph…', 'doubleyoupi-refined-admin-dashboard' ),
			// Per-screen colors.
			'screen_accent'       => __( 'Accent color', 'doubleyoupi-refined-admin-dashboard' ),
			'screen_bg'           => __( 'Background color', 'doubleyoupi-refined-admin-dashboard' ),
			'inherits_global'     => __( 'Global', 'doubleyoupi-refined-admin-dashboard' ),
			'reset_to_global'     => __( 'Reset to global color', 'doubleyoupi-refined-admin-dashboard' ),
		];
	}

	public static function inline_postbox_reset(): void {
		wp_register_style( 'doubleyoupi-dashboard-reset', false, [], DOUBLEYOUPI_VERSION );
		wp_add_inline_style( 'doubleyoupi-dashboard-reset', self::POSTBOX_RESET_CSS );
		wp_print_styles( 'doubleyoupi-dashboard-reset' );
	}
}

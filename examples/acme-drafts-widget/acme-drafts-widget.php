<?php
/**
 * Plugin Name: Acme Drafts Widget
 * Description: Example: adds a "Drafts waiting" widget to the Refined Admin Dashboard dashboard.
 * Version:     1.0.0
 * Requires PHP: 8.1
 * Text Domain: acme-drafts-widget
 * License:     GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Refined Admin Dashboard fires this action once its own widgets are
 * registered. It only fires when Refined Admin Dashboard is active and draws
 * the dashboard for the current user: without it, nothing below runs, and
 * nothing breaks.
 *
 * The widget class extends a Refined Admin Dashboard class, so it is loaded
 * here, inside the callback, never at the top of the file.
 */
add_action(
	'doubleyoupi_dashboard_widgets_registered',
	static function ( $registry ) {
		if ( ! class_exists( '\DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget' ) ) {
			return;
		}
		require_once __DIR__ . '/includes/class-acme-drafts-widget.php';
		$registry->register( new Acme_Drafts_Widget() );
	}
);

# Hooks and constants

The filters, actions and constants that Refined Admin Dashboard (RAD) and Refined Media Library
(RML) offer to other plugins, themes and site owners. This page lists what exists in the code
published on WordPress.org, nothing else.

Every hook name starts with `doubleyoupi_`. Code goes in a plugin, a must-use plugin or your
theme's `functions.php`; constants go in `wp-config.php`, before the line that says "That's all,
stop editing!".

## Three levels of promise

- **Stable.** We do not rename, remove or change these without announcing it in the changelog
  first, and the old way keeps working for at least one release.
- **Beta.** Public, documented and supported, still open to change. A change is announced in the
  changelog, and the old way keeps working for at least one release.
- **Internal.** They exist in the code and you can use them, but they follow our own needs and may
  change in any release, without notice.

## Stable filters

### `doubleyoupi_filter_feedback_enabled`

Shows or hides the "Feedback" button and the "Leave a review" link next to it, for everyone.

```php
add_filter( 'doubleyoupi_filter_feedback_enabled', '__return_false' );
```

Receives `true`. Since RAD 1.0.0, RML 0.3.0. The setting "Hide the Feedback button and review
requests" does the same from the screen (RAD 1.0.13, RML 0.3.19).

### `doubleyoupi_filter_review_ask_enabled`

Shows or hides the notice that asks for a review on WordPress.org (to administrators, after a week
and three days of use).

```php
add_filter( 'doubleyoupi_filter_review_ask_enabled', '__return_false' );
```

Receives `true`. Since RAD 1.0.13, RML 0.3.19.

### `doubleyoupi_filter_review_link_enabled`

Shows or hides the "Leave a review" link only, keeping the Feedback button.

```php
add_filter( 'doubleyoupi_filter_review_link_enabled', '__return_false' );
```

Receives `true`. Since RAD 1.0.6.

### `doubleyoupi_filter_feedback_endpoint`

The address the Feedback button sends its reports to. An empty string turns the button off.

```php
add_filter( 'doubleyoupi_filter_feedback_endpoint', fn() => 'https://example.com/feedback' );
```

Receives the default address, `https://doubleyoupi.com/api/v1/feedback`. Since RAD 1.0.0,
RML 0.3.0.

### `doubleyoupi_filter_can_manage`

Decides who may open the Double Youpï settings when they are kept to one account (Settings, Team).
Grants or removes the `doubleyoupi_manage` capability, computed on the fly, never stored.

```php
add_filter( 'doubleyoupi_filter_can_manage', function ( bool $allowed, WP_User $user ): bool {
	return $allowed || in_array( 'agency', (array) $user->roles, true );
}, 10, 2 );
```

Receives whether the user is allowed, and the `WP_User`. Since RAD 1.0.13, RML 0.3.19. Locked out?
See `DOUBLEYOUPI_UNLOCK_SETTINGS` below.

### `doubleyoupi_filter_manage_folders_cap`

The capability needed to create, rename, move and delete media folders and collections. Anyone who
can upload can still file media into them.

```php
add_filter( 'doubleyoupi_filter_manage_folders_cap', fn() => 'upload_files' );
```

Receives `'edit_others_posts'` (editors and administrators). Since RAD 1.0.13, RML 0.3.19.

### `doubleyoupi_filter_media_folders_public`

Whether media folders and collections are exposed, read only, in the REST API and in WPGraphQL.

```php
add_filter( 'doubleyoupi_filter_media_folders_public', '__return_false' );
```

Receives `true`. Since RAD 1.0.10, RML 0.3.17.

### `doubleyoupi_filter_media_graphql_names`

The GraphQL type names of folders and collections, if they clash with names on your schema.

```php
add_filter( 'doubleyoupi_filter_media_graphql_names', function ( array $names ): array {
	$names['doubleyoupi_media_folder'] = [ 'siteMediaFolder', 'siteMediaFolders' ];
	return $names;
} );
```

Receives an array keyed by taxonomy (`doubleyoupi_media_folder`, `doubleyoupi_media_collection`),
each value `[ singular, plural ]`; the defaults are `mediaFolder` and `mediaCollection`. Since RAD 1.0.10, RML 0.3.17.

### `doubleyoupi_filter_admin_prefetch`

Whether admin screens linked from the navigation are fetched ahead when the pointer rests on the
link. Links that do something (delete, create, log out) are never fetched.

```php
add_filter( 'doubleyoupi_filter_admin_prefetch', '__return_false' );
```

Receives `true`. Since RAD 1.0.16.

### `doubleyoupi_native_content_prefixes`

Plugin screens that RAD leaves on WordPress's own light canvas instead of redesigning them, because
they bring a design of their own. Matched against the start of the `page` parameter, case
insensitive.

```php
add_filter( 'doubleyoupi_native_content_prefixes', function ( array $prefixes ): array {
	$prefixes[] = 'my-plugin';
	return $prefixes;
} );
```

Receives RAD's list. Since RAD 1.0.0.

### `doubleyoupi_native_app_skinned_prefixes`

The opposite: plugin screens RAD redesigns on purpose, even when they would otherwise move to the
light canvas. Wins over the list above.

```php
add_filter( 'doubleyoupi_native_app_skinned_prefixes', function ( array $prefixes ): array {
	$prefixes[] = 'my-plugin-settings';
	return $prefixes;
} );
```

Receives RAD's list. Since RAD 1.0.4.

### `doubleyoupi_filter_admin_columns_step_aside`

With Admin Columns active, RAD steps aside on the lists Admin Columns manages. Return `false` to
keep RAD's redesigned view on those lists.

```php
add_filter( 'doubleyoupi_filter_admin_columns_step_aside', '__return_false' );
```

Receives `true`. Since RAD 1.0.0.

## Stable constants

| Constant | What it does | Since |
|---|---|---|
| `DOUBLEYOUPI_ADMIN_PROFILE` | Path to a profile exported from RAD (Settings, Profile). The accent, logo, navigation, disabled screens and tools come from that file and cannot be changed from the screen. | RAD 1.0.0 |
| `DOUBLEYOUPI_UNLOCK_SETTINGS` | `true` opens the settings to every administrator again, when they were kept to an account that is no longer there. | RAD 1.0.13, RML 0.3.19 |
| `DOUBLEYOUPI_FEEDBACK_ENDPOINT` | Same as `doubleyoupi_filter_feedback_endpoint`. An empty string turns the Feedback button off. | RAD 1.0.0, RML 0.3.0 |
| `DOUBLEYOUPI_DISABLE_VIEW_TRANSITIONS` | `true` turns off the transitions between admin screens. | RAD 1.0.0 |

```php
define( 'DOUBLEYOUPI_ADMIN_PROFILE', __DIR__ . '/rad-profile.json' );
```

## Beta: dashboard widgets

Your plugin can add its own card to the RAD dashboard. Everything is in
[rad-dashboard-widget-api.md](rad-dashboard-widget-api.md), with a complete example plugin in
[`examples/acme-drafts-widget/`](https://github.com/double-youpi/refined-admin-dashboard/tree/main/examples/acme-drafts-widget). The hook is the action
`doubleyoupi_dashboard_widgets_registered`, where you register your widget; it then appears in the
"From your plugins" section of the palette. Since RAD 1.0.14.

Also beta: `doubleyoupi_dashboard_palette_external_widgets`. RAD's palette offers its own widgets
only; the classic dashboard widgets of WordPress and of other plugins stay on the dashboard's last
screen, "WordPress and plugins". Return `true` to offer them in the palette as well (receives
`false`).

RAD does not ship widgets for other plugins: each plugin ships and maintains its own.

## Internal

Present in the code, without any promise. Use them at your own risk, and tell us in an issue if
you rely on one: it may become stable.

`doubleyoupi_admin_chip_icon`, `doubleyoupi_admin_managed_builders`, `doubleyoupi_admin_plugin_line`,
`doubleyoupi_admin_profile_imported`, `doubleyoupi_admin_standalone`, `doubleyoupi_admin_themes`,
`doubleyoupi_all_modules_booted`, `doubleyoupi_backup_last_snapshot`, `doubleyoupi_backup_run_endpoint`,
`doubleyoupi_backup_sources`, `doubleyoupi_dashboard_create_button_excluded`,
`doubleyoupi_dashboard_create_hub_excluded`, `doubleyoupi_dashboard_default_role_disposition`,
`doubleyoupi_dashboard_resolved_layout`, `doubleyoupi_dashboard_validated_widgets`,
`doubleyoupi_dashboard_widget_visible`, `doubleyoupi_event`, `doubleyoupi_eyebrow_html`,
`doubleyoupi_feedback_sent`, `doubleyoupi_filter_admin_profile`, `doubleyoupi_filter_feedback_owner`,
`doubleyoupi_filter_feedback_payload`, `doubleyoupi_filter_module_toggle_note`,
`doubleyoupi_filter_tables_config`, `doubleyoupi_generic_table_screen`, `doubleyoupi_installing`,
`doubleyoupi_ml_orphan_exclude_ids`, `doubleyoupi_ml_orphan_min_age_hours`,
`doubleyoupi_ml_orphan_network_site_limit`, `doubleyoupi_ml_where_used_network_site_limit`,
`doubleyoupi_module_booted`, `doubleyoupi_overview_after_modules_grid`,
`doubleyoupi_overview_before_grid`, `doubleyoupi_perf_image_converter`,
`doubleyoupi_static_site_snapshot`, `doubleyoupi_toolkit_force_https_in_local`,
`doubleyoupi_toolkit_plugin_active`.

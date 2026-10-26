# Add a widget to the Refined Admin Dashboard dashboard

Refined Admin Dashboard (RAD) replaces the WordPress dashboard with screens of cards that each
person composes. Your plugin can add its own card to the palette: your users drag it onto their
dashboard like any other.

This page is for plugin developers. It is complete: everything you need is here, and everything
here exists in the code.

> **Status: beta.** Requires Refined Admin Dashboard **1.0.14** or later. The hook and the class
> below are public from 1.0.14. While the API is in beta, a change may still happen; if it does, it
> will be announced in the changelog and the old way will keep working for at least one release.

## In short

1. Hook `doubleyoupi_dashboard_widgets_registered`.
2. In the callback, load a class that extends
   `DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget`, and register it.
3. Return your card's HTML with the dashboard's card classes.

When RAD is not active, the hook never fires: your plugin works as before, with nothing to check
elsewhere.

## A complete example

A plugin that adds a "Drafts waiting" card: the current user's latest drafts, with a setting for
how many to show. Tested with Refined Admin Dashboard 1.0.14. The two files are also in the
plugin's GitHub repository, ready to install:
[`examples/acme-drafts-widget/`](https://github.com/double-youpi/refined-admin-dashboard/tree/main/examples/acme-drafts-widget).

**`acme-drafts-widget/acme-drafts-widget.php`**

```php
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
```

**`acme-drafts-widget/includes/class-acme-drafts-widget.php`**

```php
<?php
/**
 * "Drafts waiting": the current user's most recent drafts.
 *
 * Loaded only from the doubleyoupi_dashboard_widgets_registered callback,
 * once Refined Admin Dashboard has declared AbstractDataWidget.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

final class Acme_Drafts_Widget extends AbstractDataWidget {

	/** Stored in saved layouts: prefix it with your plugin slug and never change it. */
	public function id(): string {
		return 'acme_drafts';
	}

	/** Title of the card and of the palette tile. */
	public function name(): string {
		return __( 'Drafts waiting', 'acme-drafts-widget' );
	}

	/** Shown under the name in the palette. */
	public function description(): string {
		return __( 'Your most recent drafts, one click from the editor.', 'acme-drafts-widget' );
	}

	/** 24 × 24 line icon, stroke only: it takes the colour of the interface. */
	public function icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/></svg>';
	}

	/** Who may see the widget, in the palette and on the dashboard. Empty: everyone with dashboard access. */
	public function required_cap(): string {
		return 'edit_posts';
	}

	/** Width when first placed: 1 or 2 slots of a six-slot row. */
	public function recommended_slots(): int {
		return 2;
	}

	public function default_config(): array {
		return [ 'count' => '5' ];
	}

	/** Settings shown when the user selects the widget in customize mode. */
	public function config_schema(): array {
		return [
			[
				'key'     => 'count',
				'type'    => 'select',
				'label'   => __( 'Drafts shown', 'acme-drafts-widget' ),
				'options' => [
					[ 'value' => '3', 'label' => '3' ],
					[ 'value' => '5', 'label' => '5' ],
					[ 'value' => '8', 'label' => '8' ],
				],
			],
		];
	}

	/** Always return a clean array: never throw, never return a WP_Error. */
	public function validate_config( array $config ): array {
		$count = (string) ( $config['count'] ?? '5' );
		return [ 'count' => in_array( $count, [ '3', '5', '8' ], true ) ? $count : '5' ];
	}

	/** Server side, once per page load. Keep it fast; cache anything heavy. */
	public function get_data( array $config ): array {
		$posts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'author'         => get_current_user_id(),
				'posts_per_page' => (int) $config['count'],
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);
		$items = [];
		foreach ( $posts as $post ) {
			$items[] = [
				'title' => get_the_title( $post ) ?: __( '(no title)', 'acme-drafts-widget' ),
				'url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
				'when'  => human_time_diff( (int) get_post_modified_time( 'U', true, $post ), time() ),
			];
		}
		return [ 'items' => $items ];
	}

	/** Escaped HTML, built with the dashboard's card classes. No <script> or <style>: they are stripped. */
	public function render_html( array $config, array $data ): string {
		$items = (array) ( $data['items'] ?? [] );
		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php echo esc_html( $this->name() ); ?></span>
			<span class="dy-dashboard__pill"><?php echo esc_html( (string) count( $items ) ); ?></span>
		</div>
		<div class="dy-dashboard__card-body">
			<?php if ( ! $items ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No drafts. Nice.', 'acme-drafts-widget' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $items as $item ) : ?>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__dot dy-dashboard__dot--accent"></span>
					<a class="dy-dashboard__row-v" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
					<span class="dy-dashboard__row-k"><?php echo esc_html( $item['when'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		echo self::dy_foot_link( admin_url( 'edit.php?post_status=draft&post_type=post' ), __( 'All drafts', 'acme-drafts-widget' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper.
		return (string) ob_get_clean();
	}
}
```

Activate it, open the dashboard, click **Customize**: the card is in the Widgets section, under
"From your plugins".

## The hook

```php
add_action( 'doubleyoupi_dashboard_widgets_registered', function ( $registry ) {
	// $registry->register( new My_Widget() );
} );
```

- It fires once per request, on `init` (priority 0), after RAD has registered its own widgets.
  Add your callback when your plugin loads, or on `plugins_loaded`: later is too late.
- It fires only when RAD draws the admin for the current user. A user who turned the RAD
  interface off for their account gets the WordPress dashboard, and no hook.
- `$registry->register()` accepts one widget object. Call it as many times as you have widgets.

**Load your class inside the callback.** It extends a RAD class: declared at the top of your file,
it would be a fatal error on sites without RAD.

## Your widget class

Extend `AbstractDataWidget`. Four methods are required, the others have sensible defaults.

| Method | Returns | Required |
|---|---|---|
| `id()` | A unique, stable id. Prefix it with your plugin's slug (`acme_drafts`). It is stored in saved layouts: never change it. | yes |
| `name()` | The card title, also shown in the palette. Translated with your own text domain. | yes |
| `icon_svg()` | A 24 × 24 line icon: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">…</svg>`. | yes |
| `render_html( $config, $data )` | The card's HTML, already escaped. | yes |
| `description()` | One line under the name in the palette. | no (empty) |
| `required_cap()` | The capability needed to see the card. | no (everyone with dashboard access) |
| `recommended_slots()` | Width: `1` or `2` slots of a six-slot row. | no (`2`) |
| `is_instanceable()` | `true` if the card can be placed several times. | no (`false`) |
| `default_config()` | The settings of a newly placed card. | no (none) |
| `config_schema()` | The settings fields shown in customize mode. | no (none) |
| `validate_config( $config )` | The cleaned settings. Always return an array. | no (unchanged) |
| `get_data( $config )` | The data your card shows, computed server side. | no (none) |

**An id already taken is refused.** You can add widgets, never replace one: registering an id that
exists triggers a "doing it wrong" notice and is ignored.

### Settings fields

`config_schema()` returns a list of fields. Each has a `key`, a `type` and a `label`:

| Type | Extra keys |
|---|---|
| `text`, `url` | `placeholder` |
| `textarea` | `rows` |
| `select`, `multiselect` | `options`: a list of `[ 'value' => …, 'label' => … ]` |
| `repeater` | `max`, `item_fields`: a list of fields of the types above |

Values come back as strings. Check them in `validate_config()`: it runs before every save and
before every display.

### Data and speed

`get_data()` runs on every dashboard load, for every card on the screen. Keep it fast. Cache
anything heavy in a transient, as RAD's own cards do (for example 15 minutes for shop figures,
one hour for disk sizes).

If `get_data()` or `render_html()` throws, your card shows "This widget could not load." and the
rest of the dashboard stays up. The error is logged.

## Who sees your card

`required_cap()` decides, everywhere at once: a user without the capability does not find the card
in the palette, and it is removed from any layout they receive, including layouts assigned to their
role.

A site can change the rule for any card with a filter:

```php
add_filter( 'doubleyoupi_dashboard_widget_visible', function ( bool $visible, string $id, string $cap ) {
	if ( 'acme_drafts' === $id ) {
		return current_user_can( 'edit_others_posts' );
	}
	return $visible;
}, 10, 3 );
```

## Markup and style

RAD draws the card frame. Your HTML goes inside, built with these classes; they follow the user's
theme (dark or light) and accent colour, so your card looks native with no CSS of your own.

```html
<div class="dy-dashboard__card-h">
	<span class="dy-dashboard__card-title">Title</span>
	<span class="dy-dashboard__pill dy-dashboard__pill--ok">badge</span>
</div>
<div class="dy-dashboard__card-body">
	<div class="dy-dashboard__row">
		<span class="dy-dashboard__dot dy-dashboard__dot--warn"></span>
		<span class="dy-dashboard__row-v">Main text</span>
		<span class="dy-dashboard__row-k">secondary</span>
	</div>
	<p class="dy-dashboard__empty">Nothing to show.</p>
</div>
```

| Class | Use |
|---|---|
| `dy-dashboard__card-h`, `dy-dashboard__card-title` | Header and title |
| `dy-dashboard__card-body` | Content |
| `dy-dashboard__row`, `dy-dashboard__row-v`, `dy-dashboard__row-k` | A line: value, then secondary text |
| `dy-dashboard__dot` with `--ok`, `--warn`, `--danger`, `--accent`, `--mute` | Status dot at the start of a line |
| `dy-dashboard__pill` with `--ok`, `--warn`, `--danger`, `--accent` | Badge in the header |
| `dy-dashboard__big-num` with `--ok`, `--warn`, `--bad` | One large figure |
| `dy-dashboard__empty` | Empty state |

For a link at the foot of the card, use the helper your class inherits:
`self::dy_foot_link( $url, $label )` (it escapes both).

**Rules:**

- Escape everything (`esc_html()`, `esc_url()`, `esc_attr()`).
- No `<script>` or `<style>` in the HTML: the dashboard filters the card's markup and removes them.
  If your card needs a script or a stylesheet, enqueue it with `admin_enqueue_scripts` when the
  hook suffix is `index.php`.
- Do not style the card frame or the row of slots: they belong to RAD.

## When things are switched off

- **RAD deactivated**: the hook does not fire, your class is never loaded, nothing to do.
- **Your plugin deactivated**: your card disappears from every dashboard. Reactivate it and it
  comes back where it was, unless the user rearranged their dashboard in the meantime (saving a
  layout drops cards that no longer exist).
- **A user without the capability**: the card is never shown to them (see above).

## Questions and feedback

The API is in beta: tell us what you build with it and what you miss, from the Feedback button in
any admin screen or at https://doubleyoupi.com.

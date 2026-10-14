<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Common contract for all v4 modular Dashboard widgets.
 *
 * Two families:
 *   - DATA widgets: Site State, Activity, Modules, etc. Singleton by default
 *     (only one occurrence allowed across the entire layout).
 *   - CONTENT widgets: Heading, Paragraph. Historically instanceable,
 *     kept for v3→v4 back-compat but no longer placed as slots —
 *     their render logic now powers the screen title/paragraph.
 *
 * v4 size model: `recommended_slots()` returns 1 or 2 (width in slots within
 * the screen's horizontal 6-slot row). No Y-axis, no free resize.
 */
interface WidgetInterface {

	/**
	 * Unique kebab-snake identifier for the widget (e.g. 'site_state', 'heading').
	 * Must be stable — it is persisted in the JSON layout.
	 */
	public function id(): string;

	/**
	 * Short label displayed in the palette and the widget header.
	 */
	public function name(): string;

	/**
	 * Long description shown on hover in the palette.
	 */
	public function description(): string;

	/**
	 * Line-art SVG displayed in the palette tile. Full format:
	 * `<svg viewBox="0 0 24 24">…</svg>`, stroke 1.5, no fill.
	 */
	public function icon_svg(): string;

	/**
	 * Widget family for UI categorisation.
	 * @return string 'data' | 'content'
	 */
	public function category(): string;

	/**
	 * Can this widget be placed multiple times in the same layout
	 * (with distinct configs)? Singletons return false.
	 */
	public function is_instanceable(): bool;

	/**
	 * Recommended width in slots when first placed.
	 * Must return 1 or 2 (6-slot row, so 2 slots = one third of the row).
	 */
	public function recommended_slots(): int;

	/**
	 * Default configuration when a new instance is created.
	 * @return array<string, mixed>
	 */
	public function default_config(): array;

	/**
	 * Schema of configurable fields exposed in the Customize panel.
	 * Empty array = widget has no configuration.
	 *
	 * Each field: { key, type, label, ... }. JS-supported types:
	 *   - 'text' / 'url'  : { key, label, placeholder? }
	 *   - 'textarea'      : { key, label, rows? }
	 *   - 'select'        : { key, label, options:[{value,label}] }
	 *   - 'multiselect'   : { key, label, options:[{value,label}] }
	 *   - 'repeater'      : { key, label, max?, item_fields:[{key,type,label,placeholder?}] }
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function config_schema(): array;

	/**
	 * Validates and sanitises the received config. Returns the sanitised config.
	 * Must always return an array — no exceptions, no WP_Error.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public function validate_config( array $config ): array;

	/**
	 * Fetches live widget data (WP/PHP versions, recent activity, etc.).
	 * Called server-side — no JS mock.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public function get_data( array $config ): array;

	/**
	 * HTML rendering of the widget. Receives the sanitised config and freshly
	 * fetched data. Must return already-escaped HTML.
	 *
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $data
	 */
	public function render_html( array $config, array $data ): string;
}

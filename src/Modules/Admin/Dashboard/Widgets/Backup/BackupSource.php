<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One backup plugin the Backup widget can read.
 *
 * A source only reads what the plugin already stored (options, its own
 * table): it never calls the plugin's classes, never makes a remote call and
 * never writes. When the stored data is missing or not in the expected shape,
 * `last()` returns null rather than guessing.
 */
interface BackupSource {

	/** Stable identifier (e.g. `updraftplus`). */
	public function id(): string;

	/** Product name shown in the widget (not translated). */
	public function label(): string;

	/** Whether the plugin is active on this site. */
	public function is_active(): bool;

	/** The last backup the plugin recorded, or null if none can be read. */
	public function last(): ?BackupSnapshot;

	/** The plugin's own backup screen. */
	public function admin_url(): string;
}

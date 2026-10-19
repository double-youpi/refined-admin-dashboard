<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The backup plugins the Backup widget knows how to read (19/10/2026).
 *
 * "Detect and step back": Refined Admin Dashboard has no backup engine of its
 * own outside the suite, so the widget reports what the site's backup plugin
 * already did. Extendable through `doubleyoupi_backup_sources`.
 */
final class BackupSources {

	/** @return BackupSource[] */
	public static function all(): array {
		$sources = [
			new Sources\UpdraftPlus(),
			new Sources\AllInOneWpMigration(),
			new Sources\Duplicator(),
			new Sources\WpVivid(),
			new Sources\BackWpUp(),
			new Sources\BackupMigration(),
			new Sources\JetpackBackup(),
			new Sources\BlogVault(),
			new Sources\TotalUpkeep(),
			new Sources\XCloner(),
		];
		/** @var mixed $filtered */
		$filtered = apply_filters( 'doubleyoupi_backup_sources', $sources );
		return array_values( array_filter(
			is_array( $filtered ) ? $filtered : $sources,
			static fn( $source ): bool => $source instanceof BackupSource
		) );
	}

	/** @return BackupSource[] */
	public static function active(): array {
		return array_values( array_filter( self::all(), static fn( BackupSource $s ): bool => $s->is_active() ) );
	}

	/** Whether at least one known backup plugin is active. */
	public static function any_active(): bool {
		return [] !== self::active();
	}

	/**
	 * The most recent backup among the active plugins. When several are active
	 * and none has a readable backup yet, the first active one is returned with
	 * a null snapshot, so the widget can still point to it.
	 *
	 * @return array{source: BackupSource, snapshot: ?BackupSnapshot}|null
	 */
	public static function latest(): ?array {
		$best = null;
		foreach ( self::active() as $source ) {
			$snapshot = $source->last();
			if ( null === $best ) {
				$best = [ 'source' => $source, 'snapshot' => $snapshot ];
				continue;
			}
			if ( null !== $snapshot && ( null === $best['snapshot'] || $snapshot->finished_at > $best['snapshot']->finished_at ) ) {
				$best = [ 'source' => $source, 'snapshot' => $snapshot ];
			}
		}
		return $best;
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * i18n strings for the migration "Import" tab, injected into JS
 * (migration.js) via wp_localize_script from MigrationManager::enqueue_assets().
 *
 * sprintf-style substitutions on the JS side via the fmt() helper: %s, %d.
 */
final class MigrationI18n {

	/** @return array<string,string> */
	public static function strings(): array {
		return [
			'noSources'      => __( 'No other folder plugin detected. Nothing to import.', 'doubleyoupi-refined-admin-dashboard' ),
			'detected'       => __( 'Detected plugins', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %1$d: folder count, %2$d: media count */
			'sourceSummary'  => __( '%1$d folders · %2$d media', 'doubleyoupi-refined-admin-dashboard' ),
			'alreadyImported'=> __( 'Already imported', 'doubleyoupi-refined-admin-dashboard' ),
			'preview'        => __( 'Preview', 'doubleyoupi-refined-admin-dashboard' ),
			'import'         => __( 'Import', 'doubleyoupi-refined-admin-dashboard' ),
			'rollback'       => __( 'Undo this import', 'doubleyoupi-refined-admin-dashboard' ),
			'previewing'     => __( 'Reading source…', 'doubleyoupi-refined-admin-dashboard' ),
			'previewTitle'   => __( 'Import preview', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %1$d: folders, %2$d: collections, %3$d: media */
			'previewCounts'  => __( '%1$d folders, %2$d collections and %3$d media will be imported.', 'doubleyoupi-refined-admin-dashboard' ),
			'confirmImport'  => __( 'Start import', 'doubleyoupi-refined-admin-dashboard' ),
			'cancel'         => __( 'Cancel', 'doubleyoupi-refined-admin-dashboard' ),
			'importing'      => __( 'Importing…', 'doubleyoupi-refined-admin-dashboard' ),
			'phaseTerms'     => __( 'Creating folders…', 'doubleyoupi-refined-admin-dashboard' ),
			'phaseRelations' => __( 'Assigning media…', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %1$d: folders+collections, %2$d: media */
			'doneMessage'    => __( 'Done. %1$d folders and %2$d media imported.', 'doubleyoupi-refined-admin-dashboard' ),
			'viewLibrary'    => __( 'Open the media library', 'doubleyoupi-refined-admin-dashboard' ),
			'rollbackConfirm'=> __( 'Remove all folders created by this import? Your media and the source plugin are left untouched.', 'doubleyoupi-refined-admin-dashboard' ),
			'rollingBack'    => __( 'Undoing…', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %d: number of folders removed */
			'rolledBack'     => __( '%d imported folders removed.', 'doubleyoupi-refined-admin-dashboard' ),
			'error'          => __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-admin-dashboard' ),
			'folder'         => __( 'Folder', 'doubleyoupi-refined-admin-dashboard' ),
			'collection'     => __( 'Collection', 'doubleyoupi-refined-admin-dashboard' ),
		];
	}
}

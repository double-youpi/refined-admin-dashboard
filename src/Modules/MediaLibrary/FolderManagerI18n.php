<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralised i18n string catalogue for the Refined Media Library module.
 *
 * All user-facing strings used by the JS frontend (folder-tree.js,
 * media-detail.js, media-filters.js, etc.) are declared here and injected
 * via wp_localize_script from FolderManager::enqueue_assets().
 *
 * Why a dedicated class rather than an inline array:
 *   - FolderManager::enqueue_assets() stays readable (~70 lines instead of 197)
 *   - The i18n catalogue lives in one file that is easy to grep
 *   - Future string scanners (POT generators) have a single place to parse
 *
 * Key conventions (JS side):
 *   - camelCase
 *   - sprintf substitutions: %s, %d, %1$d %2$s (helper fmt() in folder-tree.js)
 *   - Categories grouped by commented sections
 */
final class FolderManagerI18n {

	/**
	 * Full map of i18n strings exposed to the JS frontend.
	 *
	 * @return array<string,string>
	 */
	public static function strings(): array {
		return [
			// ── Folder actions ─────────────────────────────────────────────
			'foldersLabel'            => __( 'Folders', 'doubleyoupi-refined-admin-dashboard' ),
			'newFolder'               => __( 'New folder', 'doubleyoupi-refined-admin-dashboard' ),
			'newSubfolder'            => __( 'New subfolder', 'doubleyoupi-refined-admin-dashboard' ),
			'moveTo'                  => __( 'Move to…', 'doubleyoupi-refined-admin-dashboard' ),
			'topLevel'                => __( 'Top level', 'doubleyoupi-refined-admin-dashboard' ),
			'moreActions'             => __( 'More actions', 'doubleyoupi-refined-admin-dashboard' ),
			'rename'                  => __( 'Rename', 'doubleyoupi-refined-admin-dashboard' ),
			'delete'                  => __( 'Delete folder', 'doubleyoupi-refined-admin-dashboard' ),
			'deleteConfirm'           => __( 'Delete this folder? Media files will not be deleted.', 'doubleyoupi-refined-admin-dashboard' ),
			'noFolder'                => __( 'No folder', 'doubleyoupi-refined-admin-dashboard' ),
			'allFolders'              => __( 'All folders', 'doubleyoupi-refined-admin-dashboard' ),
			'noFoldersYet'            => __( 'No folders yet.', 'doubleyoupi-refined-admin-dashboard' ),
			'couldNotLoadFolders'     => __( 'Could not load folders.', 'doubleyoupi-refined-admin-dashboard' ),
			'showAll'                 => __( 'Show all media', 'doubleyoupi-refined-admin-dashboard' ),
			'showOrphans'             => __( 'Media without folder', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Collections ────────────────────────────────────────────────
			'collections'             => __( 'Collections', 'doubleyoupi-refined-admin-dashboard' ),
			'newCollection'           => __( 'New collection', 'doubleyoupi-refined-admin-dashboard' ),
			'renameCollection'        => __( 'Rename collection', 'doubleyoupi-refined-admin-dashboard' ),
			'deleteCollection'        => __( 'Delete collection', 'doubleyoupi-refined-admin-dashboard' ),
			'deleteColConfirm'        => __( 'Delete this collection? Media files will not be deleted.', 'doubleyoupi-refined-admin-dashboard' ),
			'noCollectionsYet'        => __( 'No collections yet.', 'doubleyoupi-refined-admin-dashboard' ),
			'couldNotLoadCollections' => __( 'Could not load collections.', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Disabled sections + panel footer ────────────────────────────
			'foldersDisabled'         => __( 'Folders are disabled.', 'doubleyoupi-refined-admin-dashboard' ),
			'collectionsDisabled'     => __( 'Collections are disabled.', 'doubleyoupi-refined-admin-dashboard' ),
			'enable'                  => __( 'Enable', 'doubleyoupi-refined-admin-dashboard' ),
			'panelSettings'           => __( 'Settings', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Where used ────────────────────────────────────────────────
			'whereUsed'               => __( 'Where used', 'doubleyoupi-refined-admin-dashboard' ),
			'notUsed'                 => __( 'Not used anywhere', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Bulk dialogs ──────────────────────────────────────────────
			'destinationFolder'       => __( 'Destination folder', 'doubleyoupi-refined-admin-dashboard' ),
			'rootNoFolder'            => __( 'Root (no folder)', 'doubleyoupi-refined-admin-dashboard' ),
			'chooseCollection'        => __( 'Choose a collection', 'doubleyoupi-refined-admin-dashboard' ),
			'cancel'                  => __( 'Cancel', 'doubleyoupi-refined-admin-dashboard' ),
			'move'                    => __( 'Move', 'doubleyoupi-refined-admin-dashboard' ),
			'add'                     => __( 'Add', 'doubleyoupi-refined-admin-dashboard' ),
			'create'                  => __( 'Create', 'doubleyoupi-refined-admin-dashboard' ),
			'deleteAction'            => __( 'Delete', 'doubleyoupi-refined-admin-dashboard' ),
			'discard'                 => __( 'Discard', 'doubleyoupi-refined-admin-dashboard' ),
			'folderName'              => __( 'Folder name', 'doubleyoupi-refined-admin-dashboard' ),
			'collectionName'          => __( 'Collection name', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %d: number of selected media files. */
			'bulkMoveTitle'           => __( 'Move %d files to a folder', 'doubleyoupi-refined-admin-dashboard' ),
			'bulkMoveTitle1'          => __( 'Move 1 file to a folder', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %d: number of selected media files. */
			'bulkAddColTitle'         => __( 'Add %d files to a collection', 'doubleyoupi-refined-admin-dashboard' ),
			'bulkAddColTitle1'        => __( 'Add 1 file to a collection', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Confirms (use %s for the entity name) ─────────────────────
			/* translators: %s: folder name. */
			'deleteFolderConfirm'     => __( "Delete folder « %s »?

Media inside won’t be deleted, they will be moved to « Media without folder ».", 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: collection name. */
			'deleteCollectionConfirm' => __( "Delete collection « %s »?

Media files won’t be deleted, they will simply be removed from this collection.", 'doubleyoupi-refined-admin-dashboard' ),
			'thisFolder'              => __( 'this folder', 'doubleyoupi-refined-admin-dashboard' ),
			'thisCollection'          => __( 'this collection', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Toast messages (use %s / %d / %1$d %2$s for substitution) ─
			/* translators: %s: folder name. */
			'folderDeleted'           => __( 'Folder « %s » deleted', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: collection name. */
			'collectionDeleted'       => __( 'Collection « %s » deleted', 'doubleyoupi-refined-admin-dashboard' ),
			'folderDeleteFailed'      => __( 'Could not delete folder', 'doubleyoupi-refined-admin-dashboard' ),
			'collectionDeleteFailed'  => __( 'Could not delete collection', 'doubleyoupi-refined-admin-dashboard' ),
			'folderMoveFailed'        => __( 'Move failed', 'doubleyoupi-refined-admin-dashboard' ),
			'collectionAddFailed'     => __( 'Add failed', 'doubleyoupi-refined-admin-dashboard' ),
			'folderFallback'          => __( 'folder', 'doubleyoupi-refined-admin-dashboard' ),
			'collectionFallback'      => __( 'collection', 'doubleyoupi-refined-admin-dashboard' ),

			/* translators: %s: folder name. */
			'movedOne'                => __( '1 file moved to « %s »', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %1$d: number of files; %2$s: folder name. */
			'movedMany'               => __( '%1$d files moved to « %2$s »', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: collection name. */
			'addedOne'                => __( '1 file added to « %s »', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %1$d: number of files; %2$s: collection name. */
			'addedMany'               => __( '%1$d files added to « %2$s »', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: collection name. */
			'alreadyInOne'            => __( 'Already in « %s »', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %s: collection name. */
			'alreadyInAll'            => __( 'All already in « %s »', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %1$d: number of files moved; %2$d: number already present. */
			'movedAndDup'             => __( '%1$d moved, %2$d already present', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %1$d: number of files added; %2$d: number already present. */
			'addedAndDup'             => __( '%1$d added, %2$d already present', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Detail panel ──────────────────────────────────────────────
			'loading'                 => __( 'Loading…', 'doubleyoupi-refined-admin-dashboard' ),
			'loadingDetails'          => __( 'Loading details…', 'doubleyoupi-refined-admin-dashboard' ),
			'loadingShort'            => __( 'Loading…', 'doubleyoupi-refined-admin-dashboard' ),
			'loadError'               => __( 'Loading error.', 'doubleyoupi-refined-admin-dashboard' ),
			'previous'                => __( 'Previous', 'doubleyoupi-refined-admin-dashboard' ),
			'next'                    => __( 'Next', 'doubleyoupi-refined-admin-dashboard' ),
			'close'                   => __( 'Close', 'doubleyoupi-refined-admin-dashboard' ),
			'dragToResize'            => __( 'Drag to resize', 'doubleyoupi-refined-admin-dashboard' ),
			'viewFullSize'            => __( 'View full size', 'doubleyoupi-refined-admin-dashboard' ),

			'information'             => __( 'Information', 'doubleyoupi-refined-admin-dashboard' ),
			'date'                    => __( 'Date', 'doubleyoupi-refined-admin-dashboard' ),
			'mimeType'                => __( 'Type', 'doubleyoupi-refined-admin-dashboard' ),
			'dimensions'              => __( 'Dimensions', 'doubleyoupi-refined-admin-dashboard' ),
			'size'                    => __( 'Size', 'doubleyoupi-refined-admin-dashboard' ),
			'altText'                 => __( 'Alternative text', 'doubleyoupi-refined-admin-dashboard' ),
			'altPlaceholder'          => __( 'Describe the image for accessibility…', 'doubleyoupi-refined-admin-dashboard' ),
			'title'                   => __( 'Title', 'doubleyoupi-refined-admin-dashboard' ),
			// The two extra native fields, always visible: portfolios and photo
			// archives live on them, and hiding them behind a toggle only moved
			// the problem. No setting involved.
			'caption'                 => __( 'Caption', 'doubleyoupi-refined-admin-dashboard' ),
			'description'             => __( 'Description', 'doubleyoupi-refined-admin-dashboard' ),
			// Shown instead of the action bar when the current user may read the
			// file but not edit it (an author looking at someone else's upload).
			'readOnlyNotice'          => __( 'You can view this file but not edit it.', 'doubleyoupi-refined-admin-dashboard' ),
			'fileUrl'                 => __( 'File URL', 'doubleyoupi-refined-admin-dashboard' ),
			'copy'                    => __( 'Copy', 'doubleyoupi-refined-admin-dashboard' ),
			'copied'                  => __( 'Copied!', 'doubleyoupi-refined-admin-dashboard' ),

			'folder'                  => __( 'Folder', 'doubleyoupi-refined-admin-dashboard' ),
			'noFolderOption'          => __( 'No folder', 'doubleyoupi-refined-admin-dashboard' ),
			'addToCollection'         => __( '+ Add to a collection', 'doubleyoupi-refined-admin-dashboard' ),
			'noCollections'           => __( 'No collection', 'doubleyoupi-refined-admin-dashboard' ),
			'remove'                  => __( 'Remove', 'doubleyoupi-refined-admin-dashboard' ),

			'save'                    => __( 'Save', 'doubleyoupi-refined-admin-dashboard' ),
			'edit'                    => __( 'Edit', 'doubleyoupi-refined-admin-dashboard' ),
			'deleteAction'            => __( 'Delete', 'doubleyoupi-refined-admin-dashboard' ),
			'saving'                  => __( 'Saving…', 'doubleyoupi-refined-admin-dashboard' ),
			'saved'                   => __( 'Saved ✓', 'doubleyoupi-refined-admin-dashboard' ),
			'errorRetry'              => __( 'Error, try again', 'doubleyoupi-refined-admin-dashboard' ),

			'notUsedInContent'        => __( 'Not used in content.', 'doubleyoupi-refined-admin-dashboard' ),
			'untitled'                => __( '(untitled)', 'doubleyoupi-refined-admin-dashboard' ),

			'deleteFileConfirm'       => __( 'Permanently delete this file? This action cannot be undone.', 'doubleyoupi-refined-admin-dashboard' ),
			'deleteFileFailed'        => __( 'Could not delete this file.', 'doubleyoupi-refined-admin-dashboard' ),
			'unsavedConfirm'          => __( 'Unsaved changes will be lost. Continue?', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: shown after the panel saved pending edits on its own
			   when the user moved to another media file. */
			'savedOnLeave'            => __( 'Changes saved', 'doubleyoupi-refined-admin-dashboard' ),
			'saveOnLeaveFailed'       => __( 'Could not save your changes', 'doubleyoupi-refined-admin-dashboard' ),
			'unsavedHint'             => __( 'You have unsaved changes', 'doubleyoupi-refined-admin-dashboard' ),

			// Locale tag for JS date formatting (e.g. "fr-FR", "en-US").
			'dateLocale'              => str_replace( '_', '-', get_locale() ),

			// ── File size units ──────────────────────────────────────────
			'sizeBytes'               => __( 'B', 'doubleyoupi-refined-admin-dashboard' ),
			'sizeKB'                  => __( 'KB', 'doubleyoupi-refined-admin-dashboard' ),
			'sizeMB'                  => __( 'MB', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Type filter (media-filters.js) ───────────────────────────
			'allTypes'                => __( 'All types', 'doubleyoupi-refined-admin-dashboard' ),
			'images'                  => __( 'Images', 'doubleyoupi-refined-admin-dashboard' ),
			'videos'                  => __( 'Videos', 'doubleyoupi-refined-admin-dashboard' ),
			'audio'                   => __( 'Audio', 'doubleyoupi-refined-admin-dashboard' ),
			'documents'               => __( 'Documents', 'doubleyoupi-refined-admin-dashboard' ),
			'svg'                     => __( 'SVG', 'doubleyoupi-refined-admin-dashboard' ),
			'mine'                    => __( 'Mine', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Search action button (grid view, mirrors list view) ──────
			'searchAction'            => __( 'Search', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Bulk actions on list view ────────────────────────────────
			'noItemsSelected'         => __( 'No media selected.', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Bulk metadata dialog (bulk-metadata.js) ──────────────────
			/* translators: %d: number of selected media files. */
			'editMetadataCount'       => __( 'Edit metadata (%d)', 'doubleyoupi-refined-admin-dashboard' ),
			'bulkEditEyebrow'         => __( 'Bulk edit', 'doubleyoupi-refined-admin-dashboard' ),
			'filesSelectedOne'        => __( '1 file selected', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %d: number of selected media files. */
			'filesSelectedMany'       => __( '%d files selected', 'doubleyoupi-refined-admin-dashboard' ),
			'bulkKeepHint'            => __( 'Leave a field empty to keep each file’s existing value unchanged.', 'doubleyoupi-refined-admin-dashboard' ),
			'altLabel'                => __( 'Alt text', 'doubleyoupi-refined-admin-dashboard' ),
			'altDescribe'             => __( 'Describe the image…', 'doubleyoupi-refined-admin-dashboard' ),
			'titlePlaceholder'        => __( 'File title…', 'doubleyoupi-refined-admin-dashboard' ),
			'applyToOne'              => __( 'Apply to 1 file', 'doubleyoupi-refined-admin-dashboard' ),
			/* translators: %d: number of selected media files. */
			'applyToMany'             => __( 'Apply to %d files', 'doubleyoupi-refined-admin-dashboard' ),
			'applying'                => __( 'Applying…', 'doubleyoupi-refined-admin-dashboard' ),
			'retry'                   => __( 'Retry', 'doubleyoupi-refined-admin-dashboard' ),

			// ── Detail panel : third-party compat fields section ───────────
			'moreFields'              => __( 'More fields', 'doubleyoupi-refined-admin-dashboard' ),
		];
	}
}

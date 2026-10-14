<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for CONTENT widgets (Heading, Paragraph).
 *
 * v4 — Content widgets are no longer placed as slots in the row
 * (screen title + paragraph are now screen-level metadata).
 * These classes are kept for two reasons:
 *   1. The rendering logic (lightweight markdown + tokens) is reused by the
 *      Renderer to build the screen header.
 *   2. Back-compat: v3 widgets stored in the DB are migrated to screen
 *      fields via Migration::v3_to_v4().
 */
abstract class AbstractContentWidget extends AbstractWidget {

	public function category(): string {
		return 'content';
	}

	public function is_instanceable(): bool {
		return true;
	}
}

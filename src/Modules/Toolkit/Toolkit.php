<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit;

use DoubleYoupi\Core\AbstractModule;
use DoubleYoupi\Core\ModuleSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolkit — bundled with Refined Admin Dashboard
 *
 * Utility toggles that replace the micro-plugins systematically
 * installed on professional sites: Classic Editor, Disable Comments, Yoast
 * Duplicate Post, Really Simple SSL, Safe SVG.
 *
 * The Toolkit is NO LONGER a standalone module/product: it is a free feature
 * of Refined Admin Dashboard. Its toggles live on their own sub-page
 * "Double Youpi > Tools" (ToolsPage), not in the Overview.
 * It therefore only boots when the Admin module is active (see is_available()),
 * and does not appear in the module catalogue.
 *
 * If an equivalent plugin is already active, the toggle is greyed out and the
 * feature does not apply ("Detect and step aside", manifesto §2).
 */
final class Toolkit extends AbstractModule {

	public function id(): string {
		return 'toolkit';
	}

	public function name(): string {
		return 'Toolkit';
	}

	/**
	 * The Toolkit is part of Refined Admin Dashboard: it only activates when
	 * the Admin module is enabled. (In the standalone Refined Media Library,
	 * the Admin module is not bundled → the Toolkit never boots.)
	 */
	public function is_available(): bool {
		return ModuleSettings::is_enabled( 'admin' );
	}

	public function boot(): void {
		// Register options in WP (auto-sanitized on write).
		add_action( 'admin_init', [ Settings::class, 'register' ] );

		// Dedicated sub-page "Double Youpi > Tools" + form handler.
		ToolsPage::register();

		// Boot each feature: it skips itself if not enabled or if there is a conflict.
		foreach ( Settings::features() as $feature ) {
			$feature->boot();
		}
	}
}

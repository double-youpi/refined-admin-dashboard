<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for DATA widgets (Site State, Activity, etc.).
 *
 * Singleton by default — a "system state" widget makes no sense placed twice
 * (two Site State tiles showing the same versions adds nothing). A subclass
 * may override is_instanceable() when there is a genuine reason to allow
 * multiple instances (Quick Actions, Shortcuts).
 */
abstract class AbstractDataWidget extends AbstractWidget {

	public function category(): string {
		return 'data';
	}
}

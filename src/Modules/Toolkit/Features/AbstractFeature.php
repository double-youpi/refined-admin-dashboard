<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Toolkit\Features;

use DoubleYoupi\Modules\Toolkit\Detection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Common contract for all features in the Toolkit module.
 *
 * A "feature" = a very simple toggle that replaces a common micro-plugin
 * (Classic Editor, Disable Comments, Yoast Duplicate Post, Really Simple SSL,
 * Safe SVG). Each feature follows the "Detect and step aside" rule from the
 * manifesto (§2): if the equivalent plugin is installed and active, the feature
 * wires NO hooks and its toggle is greyed out in the Overview UI.
 *
 * Convention: option_key() returns `doubleyoupi_toolkit_{id()}` (boolean,
 * default false). Settings::register() is responsible for calling register_setting() on the key.
 */
abstract class AbstractFeature {

	/**
	 * Stable kebab-snake_case identifier. Used as the option suffix and as the
	 * name attribute for the checkbox in the Overview section.
	 * Example: 'classic_editor', 'disable_comments'.
	 */
	abstract public function id(): string;

	/**
	 * Short label displayed in the <th> of the form-table row.
	 */
	abstract public function label(): string;

	/**
	 * Checkbox label (the phrase displayed to the right of the checkbox).
	 */
	abstract public function checkbox_label(): string;

	/**
	 * Long description displayed below the checkbox in <p class="description">.
	 */
	abstract public function description(): string;

	/**
	 * Line-art SVG icon for the overview card. Format: complete <svg> tag,
	 * viewBox 24×24, stroke="currentColor", no fill (line-art).
	 *
	 * Default implementation: a 4-branch star (neutral placeholder).
	 * Each concrete feature overrides with its own domain icon.
	 */
	public function icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M12 2v6M12 16v6M2 12h6M16 12h6"/>'
			. '</svg>';
	}

	/**
	 * List of conflicting plugins. Each entry is the relative path accepted
	 * by is_plugin_active() ($plugin_basename), e.g.
	 * 'classic-editor/classic-editor.php'.
	 *
	 * If any of them is active, the feature does not apply (manifesto §2).
	 *
	 * @return array<int, array{file:string, name:string}>
	 */
	abstract public function conflict_plugin_candidates(): array;

	/**
	 * Wires the WordPress hooks for the feature.
	 * Called by boot() only if is_enabled() AND no conflict.
	 */
	abstract protected function apply(): void;

	/**
	 * WordPress option key. Convention: `doubleyoupi_toolkit_{id()}`.
	 */
	final public function option_key(): string {
		return 'doubleyoupi_toolkit_' . $this->id();
	}

	/**
	 * Is the toggle enabled in the database?
	 */
	final public function is_enabled(): bool {
		return (bool) get_option( $this->option_key(), false );
	}

	/**
	 * Returns the first active conflicting plugin, or null if none.
	 *
	 * @return array{file:string, name:string}|null
	 */
	final public function active_conflict(): ?array {
		foreach ( $this->conflict_plugin_candidates() as $candidate ) {
			if ( Detection::is_plugin_active( $candidate['file'] ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Environmental (non-plugin) reason for disabling the feature.
	 *
	 * Overridden by features that have an external condition beyond a plugin
	 * conflict: for example, ForceHttps refuses to activate in a local
	 * environment to avoid breaking site access without a self-signed certificate.
	 *
	 * Returns an explanatory string (displayed to the user) or null if
	 * the feature can run. Default: null (no condition).
	 */
	public function environment_block(): ?string {
		return null;
	}

	/**
	 * Should the toggle be greyed out in the UI?
	 * True if a conflicting plugin is active OR if an environmental condition blocks it.
	 */
	final public function is_externally_blocked(): bool {
		return $this->active_conflict() !== null || $this->environment_block() !== null;
	}

	/**
	 * HTML for additional settings rendered inside the toggle card, below the
	 * description. The vast majority of features have none (pure binary toggle):
	 * the default implementation is empty. Hide login overrides this to expose
	 * its "login slug" field.
	 *
	 * IMPORTANT: the feature is responsible for escaping its own output
	 * (OverviewSection performs a raw echo, as it does for icon_svg()). Any input
	 * field must be named with the `doubleyoupi_toolkit_` prefix so it can be read by the
	 * form handler (see on_save()).
	 */
	public function extra_settings_html(): string {
		return '';
	}

	/**
	 * Script that goes with extra_settings_html(), printed right after it by
	 * the Tools page through wp_print_inline_script_tag(). Kept out of the
	 * HTML, which is filtered by wp_kses() at output.
	 */
	public function extra_settings_script(): string {
		return '';
	}

	/**
	 * Persistence hook called by OverviewSection::handle_form() for each
	 * non-blocked feature, after nonce + capability verification and before
	 * writing the main toggle value. Receives the raw $_POST (already nonce-checked)
	 * and the before/after toggle state, enabling side effects on transition
	 * (e.g. notification email on activation).
	 *
	 * Default: no-op. Hide login overrides this to store its slug and
	 * notify the admin of the new login URL.
	 *
	 * @param array<string,mixed> $post        Raw POST data (nonce already verified upstream).
	 * @param bool                $was_enabled Toggle state before this save.
	 * @param bool                $now_enabled Toggle state after this save.
	 */
	public function on_save( array $post, bool $was_enabled, bool $now_enabled ): void {
		// no-op by default.
	}

	/**
	 * Entry point called by Toolkit::boot().
	 * Silent skip if disabled OR if externally blocked (plugin conflict or env).
	 */
	final public function boot(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( $this->is_externally_blocked() ) {
			return;
		}
		$this->apply();
	}
}

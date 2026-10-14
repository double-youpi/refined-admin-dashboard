<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Layout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Promotes a layout payload to the current version (v4).
 *
 * Called upstream of LayoutValidator. Payloads in the DB may date from v3
 * experiments (20×12 grid) — they are converted silently rather than discarded.
 *
 * v3 → v4 conversion strategy:
 *   - Screens and their order are preserved
 *   - For each screen:
 *     * If a `heading` widget is present, its `text` becomes `screen.title`
 *       (and its `em_color` signals that accent italic should be displayed if possible)
 *     * If a `paragraph` widget is present, its `text` becomes `screen.paragraph`
 *     * Other widgets are converted: `w >= 6` → `slots: 2`, otherwise `slots: 1`,
 *       respecting the 6-slot limit per screen (excess widgets are dropped)
 *   - x/y/h fields are removed; screen.title/title_em/paragraph/paragraph_enabled are added
 */
final class Migration {

	/**
	 * Converts a payload to v4 if necessary. No-op if already v4.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function to_v4( array $payload ): array {
		$version = (int) ( $payload['version'] ?? 0 );

		if ( $version >= 4 ) {
			return $payload;
		}

		// Everything below v4 is treated as v3-style (v3 already had multi-screens;
		// older schemas are handled on a best-effort basis).
		return self::convert_v3_to_v4( $payload );
	}

	/**
	 * @param array<string, mixed> $v3
	 * @return array<string, mixed>
	 */
	private static function convert_v3_to_v4( array $v3 ): array {
		$out = [
			'version'          => 4,
			'schema'           => 'doubleyoupi.dashboard.v4',
			'preset'           => isset( $v3['preset'] ) ? (string) $v3['preset'] : 'custom',
			'active_screen'    => max( 0, (int) ( $v3['active_screen'] ?? 0 ) ),
			'lock'             => false,
			'role_target'      => null,
			'screens'          => [],
			'wp_native_screen' => [
				'enabled'        => true,
				'hidden_widgets' => [],
			],
		];

		$screens = is_array( $v3['screens'] ?? null ) ? $v3['screens'] : [];
		if ( $screens === [] && isset( $v3['widgets'] ) && is_array( $v3['widgets'] ) ) {
			// Ultra-flat v1/v2 schema: wrap in a single screen.
			$screens = [ [ 'widgets' => $v3['widgets'] ] ];
		}

		foreach ( $screens as $idx => $screen ) {
			if ( ! is_array( $screen ) ) {
				continue;
			}
			$out['screens'][] = self::convert_screen( $screen, $idx );
		}

		if ( $out['screens'] === [] ) {
			// Always guarantee at least one empty screen after migration.
			$out['screens'][] = [
				'id'                => 'scr-1',
				'title'             => '',
				'title_em'          => '',
				'paragraph'         => '',
				'paragraph_enabled' => true,
				'accent_intensity'  => 'auto',
				'widgets'           => [],
			];
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $screen
	 * @return array<string, mixed>
	 */
	private static function convert_screen( array $screen, int $idx ): array {
		$widgets = is_array( $screen['widgets'] ?? null ) ? $screen['widgets'] : [];

		$title             = '';
		$title_em          = '';
		$paragraph         = '';
		$paragraph_enabled = true;
		$out_widgets       = [];
		$slots_used        = 0;
		$max_slots         = 6;

		foreach ( $widgets as $w ) {
			if ( ! is_array( $w ) ) {
				continue;
			}
			$id     = isset( $w['id'] ) ? (string) $w['id'] : '';
			$config = is_array( $w['config'] ?? null ) ? $w['config'] : [];

			if ( $id === 'heading' && $title === '' ) {
				$title    = isset( $config['text'] ) ? (string) $config['text'] : '';
				$em_color = isset( $config['em_color'] ) ? (string) $config['em_color'] : '';
				if ( $em_color !== '' && $em_color !== 'none' ) {
					// We don't know which word was italic in v3; leave empty —
					// the user can redefine it via Customize.
					$title_em = '';
				}
				continue;
			}
			if ( $id === 'paragraph' && $paragraph === '' ) {
				$paragraph = isset( $config['text'] ) ? (string) $config['text'] : '';
				continue;
			}

			// Data widget: derive slots from w (v3 grid width).
			$w_size = (int) ( $w['w'] ?? 4 );
			$slots  = $w_size >= 6 ? 2 : 1;

			if ( $slots_used + $slots > $max_slots ) {
				continue;
			}
			$slots_used += $slots;

			$converted = [
				'id'    => sanitize_key( $id ),
				'slots' => $slots,
				'config' => $config,
			];
			if ( isset( $w['instance'] ) ) {
				$converted['instance'] = sanitize_key( (string) $w['instance'] );
			}
			$out_widgets[] = $converted;
		}

		return [
			'id'                => isset( $screen['id'] ) ? sanitize_key( (string) $screen['id'] ) : 'scr-' . ( $idx + 1 ),
			'title'             => $title,
			'title_em'          => $title_em,
			'paragraph'         => $paragraph,
			'paragraph_enabled' => $paragraph_enabled,
			'accent_intensity'  => 'auto',
			'widgets'           => $out_widgets,
		];
	}
}

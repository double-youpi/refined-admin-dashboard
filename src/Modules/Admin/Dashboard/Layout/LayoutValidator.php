<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Layout;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\WidgetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates a v4 JSON layout payload before any write to the DB.
 *
 * v4 model:
 *   - Multi-screen (up to MAX_SCREENS)
 *   - Each screen has its own title + paragraph (screen metadata)
 *   - One horizontal slot row of SLOTS_PER_SCREEN slots per screen
 *   - Each widget occupies 1 or 2 slots (no Y-axis, no free resize)
 *   - An optional "native WP" screen rendered last
 *
 * Defensive strategy:
 *   - Strict on version (v4 expected; v3 and older must be migrated by
 *     Migration::to_v4() before reaching this validator)
 *   - Tolerant of unknown widgets: silently filtered out
 *   - Strict on slot bounds ∈ {1,2} and sum ≤ SLOTS_PER_SCREEN
 *   - Strict on singleton uniqueness across all screens
 */
final class LayoutValidator {

	public const CURRENT_VERSION   = 4;
	public const SLOTS_PER_SCREEN  = 6;
	public const MAX_SCREENS       = 20;
	// 1 or 2 slots for standard "data" widgets; 4 slots allowed for wide widgets
	// (e.g. the "Create" hub, which aligns several large buttons).
	public const ALLOWED_SLOT_SIZES = [ 1, 2, 4 ];

	/**
	 * Returns the sanitised layout. Returns WP_Error on a fatal validation failure.
	 *
	 * @param mixed $payload
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function validate( $payload ) {
		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'invalid_payload',
				__( 'The layout payload must be an array.', 'doubleyoupi-refined-admin-dashboard' )
			);
		}

		// Auto-promote v3 (and older) to v4 before strict validation.
		$payload = Migration::to_v4( $payload );

		$version = (int) ( $payload['version'] ?? 0 );
		if ( $version !== self::CURRENT_VERSION ) {
			return new \WP_Error(
				'invalid_version',
				sprintf(
					/* translators: %1$d: expected version, %2$d: received version */
					__( 'Invalid layout version (expected %1$d, got %2$d).', 'doubleyoupi-refined-admin-dashboard' ),
					self::CURRENT_VERSION,
					$version
				)
			);
		}

		if ( ! isset( $payload['screens'] ) || ! is_array( $payload['screens'] ) || $payload['screens'] === [] ) {
			return new \WP_Error( 'no_screens', __( 'At least one screen is required.', 'doubleyoupi-refined-admin-dashboard' ) );
		}

		$registry = WidgetRegistry::instance();
		$clean    = [
			'version'       => self::CURRENT_VERSION,
			'schema'        => 'doubleyoupi.dashboard.v4',
			'preset'        => isset( $payload['preset'] ) ? sanitize_key( (string) $payload['preset'] ) : 'custom',
			'active_screen' => max( 0, (int) ( $payload['active_screen'] ?? 0 ) ),
			'lock'          => ! empty( $payload['lock'] ),
			'role_target'   => isset( $payload['role_target'] ) && $payload['role_target'] !== null
				? sanitize_key( (string) $payload['role_target'] )
				: null,
			'screens'       => [],
			'wp_native_screen' => self::sanitize_wp_native_screen( $payload['wp_native_screen'] ?? [] ),
		];

		$screens = array_slice( $payload['screens'], 0, self::MAX_SCREENS );

		// Track singletons across all screens.
		$singleton_seen = [];

		foreach ( $screens as $index => $screen ) {
			if ( ! is_array( $screen ) ) {
				continue;
			}

			$clean_screen = self::sanitize_screen_meta( $screen, $index );

			$widgets       = is_array( $screen['widgets'] ?? null ) ? $screen['widgets'] : [];
			$slots_used    = 0;
			$clean_widgets = [];

			foreach ( $widgets as $widget ) {
				if ( ! is_array( $widget ) ) {
					continue;
				}
				$widget_id = isset( $widget['id'] ) ? sanitize_key( (string) $widget['id'] ) : '';
				$instance  = $registry->get( $widget_id );

				if ( ! $instance ) {
					continue;
				}

				// Content widgets (heading/paragraph) can no longer be placed in slots.
				if ( $instance->category() === 'content' ) {
					continue;
				}

				// Role gating: remove widgets for which the current user does not have
				// the required capability (disk space, modules, shop reports…).
				// Because the layout is always validated before rendering, this also
				// covers display — and applying a preset via a restricted role will not
				// place disallowed widgets.
				if ( method_exists( $instance, 'is_visible_to_current_user' ) && ! $instance->is_visible_to_current_user() ) {
					continue;
				}

				// Singleton constraint: if already seen elsewhere, skip this instance.
				if ( ! $instance->is_instanceable() ) {
					if ( isset( $singleton_seen[ $widget_id ] ) ) {
						continue;
					}
					$singleton_seen[ $widget_id ] = true;
				}

				$slots = self::sanitize_slots( $widget['slots'] ?? null, $instance->recommended_slots() );

				// If the row overflows, reject the widget rather than clipping it.
				if ( $slots_used + $slots > self::SLOTS_PER_SCREEN ) {
					continue;
				}
				$slots_used += $slots;

				$clean_widgets[] = self::sanitize_widget( $widget, $instance, $slots );
			}

			$clean_screen['widgets'] = $clean_widgets;

			// Screen emptied by gating: if it contained widgets but none survived
			// (role insufficient for all of them), remove the screen rather than
			// leaving an orphan title with no content. A screen with NO widgets at
			// source (intentional separator) is preserved.
			if ( $clean_widgets === [] && $widgets !== [] ) {
				continue;
			}

			$clean['screens'][] = $clean_screen;
		}

		if ( $clean['screens'] === [] ) {
			return new \WP_Error( 'no_valid_screens', __( 'No valid screen after sanitization.', 'doubleyoupi-refined-admin-dashboard' ) );
		}

		if ( $clean['active_screen'] >= count( $clean['screens'] ) ) {
			$clean['active_screen'] = 0;
		}

		return $clean;
	}

	/**
	 * @param array<string, mixed> $screen
	 * @return array<string, mixed>
	 */
	private static function sanitize_screen_meta( array $screen, int $index ): array {
		$title = isset( $screen['title'] ) ? sanitize_text_field( (string) $screen['title'] ) : '';
		$title_em = isset( $screen['title_em'] ) ? sanitize_text_field( (string) $screen['title_em'] ) : '';
		// title_em must be empty or present within title (for the accent italic rendering).
		if ( $title_em !== '' && mb_stripos( $title, $title_em ) === false ) {
			$title_em = '';
		}

		return [
			'id'                => isset( $screen['id'] ) ? sanitize_key( (string) $screen['id'] ) : 'scr-' . ( $index + 1 ),
			'title'             => $title,
			'title_em'          => $title_em,
			'paragraph'         => isset( $screen['paragraph'] ) ? wp_kses_post( (string) $screen['paragraph'] ) : '',
			'paragraph_enabled' => ! isset( $screen['paragraph_enabled'] ) || ! empty( $screen['paragraph_enabled'] ),
			'accent_intensity'  => self::sanitize_accent_intensity( $screen['accent_intensity'] ?? 'auto' ),
			// Per-screen colours (v4 showcase): empty = inherits the global colour
			// (DS default or custom client accent). Otherwise a validated #rrggbb.
			'accent_color'      => self::sanitize_optional_hex( $screen['accent_color'] ?? '' ),
			'bg_color'          => self::sanitize_optional_hex( $screen['bg_color'] ?? '' ),
			'widgets'           => [],
		];
	}

	/**
	 * Optional hex colour: '' (inherits from global) or a validated '#rrggbb'.
	 *
	 * @param mixed $value
	 */
	private static function sanitize_optional_hex( $value ): string {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		$hex = sanitize_hex_color( $value );
		return is_string( $hex ) ? $hex : '';
	}

	private static function sanitize_slots( $value, int $fallback ): int {
		$value = $value === null ? $fallback : (int) $value;
		return in_array( $value, self::ALLOWED_SLOT_SIZES, true ) ? $value : $fallback;
	}

	private static function sanitize_accent_intensity( $value ): string {
		$value = (string) $value;
		return in_array( $value, [ 'auto', 'high', 'medium', 'low', 'none' ], true ) ? $value : 'auto';
	}

	/**
	 * @param mixed $raw
	 * @return array<string, mixed>
	 */
	private static function sanitize_wp_native_screen( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$hidden = is_array( $raw['hidden_widgets'] ?? null ) ? $raw['hidden_widgets'] : [];
		$hidden = array_values( array_filter( array_map(
			static fn( $v ) => sanitize_key( (string) $v ),
			$hidden
		) ) );
		return [
			'enabled'        => ! isset( $raw['enabled'] ) || ! empty( $raw['enabled'] ),
			'hidden_widgets' => $hidden,
		];
	}

	/**
	 * @param array<string, mixed> $widget
	 */
	private static function sanitize_widget(
		array $widget,
		\DoubleYoupi\Modules\Admin\Dashboard\Widgets\WidgetInterface $instance,
		int $slots
	): array {
		$clean = [
			'id'    => $instance->id(),
			'slots' => $slots,
		];

		if ( $instance->is_instanceable() ) {
			$clean['instance'] = isset( $widget['instance'] )
				? sanitize_key( (string) $widget['instance'] )
				: $instance->id() . '-' . substr( md5( wp_generate_uuid4() ), 0, 6 ); // generate a unique instance key
		}

		$incoming_config = is_array( $widget['config'] ?? null ) ? $widget['config'] : [];
		$clean['config'] = $instance->validate_config( $incoming_config );

		return $clean;
	}
}

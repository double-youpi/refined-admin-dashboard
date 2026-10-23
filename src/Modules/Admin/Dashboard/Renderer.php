<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard;

use DoubleYoupi\Admin\Kses;
use DoubleYoupi\Modules\Admin\Dashboard\Compat\WpDashboardWidgetsAdapter;
use DoubleYoupi\Modules\Admin\Dashboard\Tokens\TokenResolver;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\WidgetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side HTML rendering of a v4 layout.
 *
 * Anatomy of the generated markup:
 *
 *   <div class="dy-dashboard__viewport">
 *     <article class="dy-dashboard__screen" data-screen-index="0">
 *       <div class="dy-dashboard__screen-bg" aria-hidden="true"></div>
 *       <header class="dy-dashboard__screen-header">
 *         <div class="dy-dashboard__eyebrow">…</div>
 *         <h2 class="dy-dashboard__title">…</h2>
 *         <p  class="dy-dashboard__paragraph">…</p>     (optional)
 *       </header>
 *       <div class="dy-dashboard__slots" data-slots-total="6">
 *         <div class="dy-dashboard__slot dy-dashboard__slot--2" …>
 *           …widget render_html()…
 *         </div>
 *       </div>
 *     </article>
 *     …N user screens…
 *     <article class="dy-dashboard__screen dy-dashboard__screen--native">…</article>
 *   </div>
 *   <nav class="dy-dashboard__nav">
 *     <button class="dy-dashboard__nav-arrow dy-dashboard__nav-arrow--prev">…</button>
 *     <ul class="dy-dashboard__pagination">…dots…</ul>
 *     <button class="dy-dashboard__nav-arrow dy-dashboard__nav-arrow--next">…</button>
 *   </nav>
 *   <button class="dy-dashboard__customize-btn" data-action="open-sidebar">…</button>
 *
 * The viewport uses CSS scroll-snap for swipe/keyboard navigation.
 * JS then takes over for interactivity (sidebar, drag&drop, custom transitions)
 * without replacing the initial render.
 */
final class Renderer {

	/**
	 * Renders the full layout (multi-screens + native WP screen if enabled).
	 *
	 * @param array<string, mixed> $layout      Resolved and validated v4 layout.
	 * @param array<string, array<string, mixed>> $widget_data Pre-fetched data.
	 */
	public static function render_layout( array $layout, array $widget_data ): string {
		ob_start();
		self::print_layout( $layout, $widget_data );
		return (string) ob_get_clean();
	}

	/**
	 * Prints the full layout. Our own fragments are filtered at the point of
	 * output (Kses::ui); the widgets other plugins register on the native
	 * screen print themselves, as they do in WordPress's own do_meta_boxes().
	 *
	 * @param array<string, mixed> $layout      Resolved and validated v4 layout.
	 * @param array<string, array<string, mixed>> $widget_data Pre-fetched data.
	 */
	public static function print_layout( array $layout, array $widget_data ): void {
		$screens     = is_array( $layout['screens'] ?? null ) ? $layout['screens'] : [];
		$show_native = ! empty( $layout['wp_native_screen']['enabled'] );

		// Total screen count used for background intensity and pagination calculations.
		// The native WP screen (if rendered) has no gradient — it is excluded from
		// the intensity gradient but counted in the pagination.
		$user_screens_count = count( $screens );
		$total_with_native  = $user_screens_count + ( $show_native ? 1 : 0 );

		if ( $total_with_native === 0 ) {
			echo wp_kses( self::render_empty_state(), Kses::ui_tags() );
			return;
		}

		$active_index = max( 0, min( $total_with_native - 1, (int) ( $layout['active_screen'] ?? 0 ) ) );

		?>
		<div class="dy-dashboard__ambient" aria-hidden="true">
			<div class="dy-dashboard__ambient-grad">
				<span class="dy-dashboard__ambient-halo dy-dashboard__ambient-halo--a"></span>
				<span class="dy-dashboard__ambient-halo dy-dashboard__ambient-halo--b"></span>
			</div>
		</div>
		<div class="dy-dashboard__viewport" data-active-screen="<?php echo esc_attr( (string) $active_index ); ?>">
			<?php
			foreach ( $screens as $index => $screen ) {
				echo wp_kses( self::render_screen( $screen, $index, $user_screens_count, $total_with_native, $widget_data ), Kses::ui_tags() );
			}
			if ( $show_native ) {
				$native_hidden = (array) ( $layout['wp_native_screen']['hidden_widgets'] ?? [] );
				self::print_native_screen( $user_screens_count, $total_with_native, $native_hidden );
			}
			?>
		</div>
		<?php
		echo wp_kses( self::render_nav( $total_with_native, $active_index ), Kses::ui_tags() );
		echo wp_kses( self::render_customize_button(), Kses::ui_tags() );
	}

	/**
	 * Renders a user screen (title + paragraph + slot row).
	 *
	 * @param array<string, mixed> $screen
	 * @param array<string, array<string, mixed>> $widget_data
	 */
	private static function render_screen( array $screen, int $index, int $total_user, int $total_with_native, array $widget_data ): string {
		$intensity = self::resolve_intensity( $screen['accent_intensity'] ?? 'auto', $index, $total_user );
		$style     = sprintf(
			'--dy-screen-intensity:%s;--dy-screen-index:%d;--dy-screen-total:%d;',
			self::css_number( $intensity ),
			$index + 1,
			max( $total_user, 1 )
		);

		// Per-screen accent colour (v4 showcase): if set, overrides --dya-accent +
		// --dya-accent-rgb on the article → all accent elements (em title, eyebrow,
		// slot halos, badges) follow. Empty = inherits from the global.
		// The --dya-accent-on override ensures readable text contrast.
		$accent_color = isset( $screen['accent_color'] ) ? (string) $screen['accent_color'] : '';
		if ( $accent_color !== '' ) {
			$style .= sprintf(
				'--dya-accent:%s;--dya-accent-rgb:%s;--dya-accent-on:%s;',
				$accent_color,
				self::hex_to_rgb( $accent_color ),
				self::readable_on( $accent_color )
			);
		}

		ob_start();
		?>
		<article
			class="dy-dashboard__screen"
			data-screen-id="<?php echo esc_attr( (string) ( $screen['id'] ?? 'scr-' . ( $index + 1 ) ) ); ?>"
			data-screen-index="<?php echo esc_attr( (string) $index ); ?>"
			data-screen-kind="user"
			<?php if ( $accent_color !== '' ) : ?>data-screen-accent="<?php echo esc_attr( $accent_color ); ?>"<?php endif; ?>
			<?php if ( ! empty( $screen['bg_color'] ) ) : ?>data-screen-bg="<?php echo esc_attr( (string) $screen['bg_color'] ); ?>"<?php endif; ?>
			style="<?php echo esc_attr( $style ); ?>"
		>
			<div class="dy-dashboard__screen-inner">
				<header class="dy-dashboard__screen-header">
					<?php echo wp_kses( self::render_eyebrow( $index, $total_with_native ), Kses::ui_tags() ); ?>
					<?php echo wp_kses( self::render_title( $screen ), Kses::ui_tags() ); ?>
					<?php echo wp_kses( self::render_paragraph( $screen ), Kses::ui_tags() ); ?>
				</header>
				<?php echo wp_kses( self::render_slot_row( $screen, $widget_data ), Kses::ui_tags() ); ?>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the native WP screen (last screen, no background, minimal interaction).
	 *
	 * @param array<int, string> $hidden_widgets
	 */
	private static function print_native_screen( int $index, int $total_with_native, array $hidden_widgets ): void {
		$captured     = WpDashboardWidgetsAdapter::captured();
		$visible_boxes = [];
		foreach ( $captured as $id => $box ) {
			if ( in_array( $id, $hidden_widgets, true ) ) {
				continue;
			}
			$visible_boxes[] = $box;
		}

		?>
		<article
			class="dy-dashboard__screen dy-dashboard__screen--native"
			data-screen-id="scr-wp-native"
			data-screen-index="<?php echo esc_attr( (string) $index ); ?>"
			data-screen-kind="native"
		>
			<div class="dy-dashboard__screen-inner">
				<header class="dy-dashboard__screen-header">
					<?php echo wp_kses( self::render_eyebrow( $index, $total_with_native ), Kses::ui_tags() ); ?>
					<h2 class="dy-dashboard__title dy-dashboard__title--native">
						<?php
						printf(
							/* translators: %s: "WordPress", italicised for stylistic emphasis */
							esc_html__( '%s and plugins', 'doubleyoupi-refined-admin-dashboard' ),
							'<em class="dy-dashboard__title-em">' . esc_html__( 'WordPress', 'doubleyoupi-refined-admin-dashboard' ) . '</em>'
						);
						?>
					</h2>
				</header>
				<div class="dy-dashboard__native-list">
				<?php if ( $visible_boxes === [] ) : ?>
					<p class="dy-dashboard__native-empty">
						<?php esc_html_e( 'No native or plugin widget is registered on this site.', 'doubleyoupi-refined-admin-dashboard' ); ?>
					</p>
				<?php else : ?>
					<?php foreach ( $visible_boxes as $box ) : ?>
						<?php
						// Plugin widgets get their own id and an `.inside` body, as in
						// WordPress' postbox: plugins scope their styles to them
						// (Independent Analytics fixes its chart height with
						// `#iawp .chart-inner`; without it Chart.js grew the chart to
						// thousands of pixels, translator feedback 23/10/2026). Not
						// WordPress' own widgets: their ids wake up core and theme rules
						// written for the classic dashboard. The native dashboard only
						// keeps an empty container, so the id stays unique.
						$is_plugin = 'wordpress' !== ( $box['origin'] ?? 'plugin' );
						?>
						<section
							class="dy-dashboard__native-item"
							<?php if ( $is_plugin ) : ?>id="<?php echo esc_attr( (string) ( $box['id'] ?? '' ) ); ?>"<?php endif; ?>
							data-native-id="<?php echo esc_attr( (string) ( $box['id'] ?? '' ) ); ?>"
							data-native-origin="<?php echo esc_attr( (string) ( $box['origin'] ?? 'plugin' ) ); ?>"
						>
							<header class="dy-dashboard__native-item-header">
								<span class="dy-dashboard__native-origin-dot" aria-hidden="true"></span>
								<h3 class="dy-dashboard__native-item-title"><?php echo esc_html( (string) ( $box['title'] ?? '' ) ); ?></h3>
							</header>
							<div class="dy-dashboard__native-item-body<?php echo $is_plugin ? ' inside' : ''; ?>">
								<?php
								$callback = $box['callback'] ?? null;
								if ( is_callable( $callback ) ) {
									$args = isset( $box['args'] ) && is_array( $box['args'] ) ? $box['args'] : [];
									call_user_func( $callback, null, [ 'args' => $args ] );
								} else {
									echo '<p>' . esc_html__( 'This widget could not be rendered (callback missing).', 'doubleyoupi-refined-admin-dashboard' ) . '</p>';
								}
								?>
							</div>
						</section>
					<?php endforeach; ?>
				<?php endif; ?>
				</div>
			</div>
		</article>
		<?php
	}

	/**
	 * Minimal eyebrow showing "current position / total" (e.g. "1 / 2").
	 * Returns empty string when there is only one screen (information is useless).
	 */
	private static function render_eyebrow( int $index, int $total ): string {
		if ( $total <= 1 ) {
			return '';
		}
		return sprintf(
			'<div class="dy-dashboard__eyebrow"><span class="dy-dashboard__eyebrow-count">%1$d / %2$d</span></div>',
			$index + 1,
			$total
		);
	}

	/**
	 * Screen title with the italic word (title_em) wrapped in an <em>.
	 *
	 * @param array<string, mixed> $screen
	 */
	private static function render_title( array $screen ): string {
		$title = (string) ( $screen['title'] ?? '' );

		if ( $title === '' ) {
			// No title configured: return an accessible empty title so the
			// semantics are not broken (sr-only via SCSS).
			return '<h2 class="dy-dashboard__title dy-dashboard__title--placeholder" aria-hidden="true">&nbsp;</h2>';
		}

		$resolved_title = TokenResolver::resolve( $title );

		// AUTO-DETECT the last "word" (= last non-whitespace sequence) in the
		// title and wrap it in <em>. `\S+` naturally captures any attached
		// punctuation (e.g. "nice.", "admin!", "hello…") — so a period/comma/etc.
		// after the last word gets the same accent colour as the word itself.
		// The legacy `title_em` field is still read from storage but no longer
		// influences rendering: title ↔ accent consistency follows the current
		// content in real time.
		$html_title = self::wrap_title_accent( $resolved_title );

		return '<h2 class="dy-dashboard__title">' . $html_title . '</h2>';
	}

	/**
	 * Wraps the last non-whitespace token of the title in `<em class="dy-dashboard__title-em">`.
	 * Escapes each piece (before, em, after) with `esc_html` before assembly —
	 * the output is safe for direct insertion.
	 */
	private static function wrap_title_accent( string $title ): string {
		if ( $title === '' ) {
			return '';
		}
		// `(\S+)(\s*)$` : last non-whitespace sequence + its trailing spaces.
		if ( ! preg_match( '/(\S+)(\s*)$/u', $title, $m, PREG_OFFSET_CAPTURE ) ) {
			return esc_html( $title );
		}
		$last_word        = (string) $m[1][0];
		$last_word_offset = (int) $m[1][1]; // byte offset, safe for byte-level substr
		$before           = substr( $title, 0, $last_word_offset );
		$after            = substr( $title, $last_word_offset + strlen( $last_word ) );
		return esc_html( $before )
			. '<em class="dy-dashboard__title-em">' . esc_html( $last_word ) . '</em>'
			. esc_html( $after );
	}

	/**
	 * Screen paragraph with light Markdown (bold, italic, link, code).
	 *
	 * @param array<string, mixed> $screen
	 */
	private static function render_paragraph( array $screen ): string {
		if ( empty( $screen['paragraph_enabled'] ) ) {
			return '';
		}
		$text = (string) ( $screen['paragraph'] ?? '' );
		if ( $text === '' ) {
			return '';
		}

		$resolved = TokenResolver::resolve( $text );
		$html     = self::apply_light_markdown( $resolved );

		return '<p class="dy-dashboard__paragraph">' . $html . '</p>';
	}

	/**
	 * Horizontal widget row (up to 6 slots).
	 *
	 * @param array<string, mixed> $screen
	 * @param array<string, array<string, mixed>> $widget_data
	 */
	private static function render_slot_row( array $screen, array $widget_data ): string {
		$widgets = is_array( $screen['widgets'] ?? null ) ? $screen['widgets'] : [];

		$slots_used = 0;
		foreach ( $widgets as $w ) {
			$slots_used += (int) ( $w['slots'] ?? 1 );
		}

		ob_start();
		?>
		<div
			class="dy-dashboard__slots"
			data-slots-total="<?php echo esc_attr( (string) \DoubleYoupi\Modules\Admin\Dashboard\Layout\LayoutValidator::SLOTS_PER_SCREEN ); ?>"
			data-slots-used="<?php echo esc_attr( (string) $slots_used ); ?>"
		>
			<?php
			$registry = WidgetRegistry::instance();
			foreach ( $widgets as $widget ) {
				$instance_cls = $registry->get( (string) ( $widget['id'] ?? '' ) );
				if ( ! $instance_cls ) {
					continue;
				}
				$slots = (int) ( $widget['slots'] ?? $instance_cls->recommended_slots() );
				$key   = (string) ( $widget['instance'] ?? $widget['id'] ?? '' );
				$data  = is_array( $widget_data[ $key ] ?? null ) ? $widget_data[ $key ] : [];
				if ( ! empty( $data['error'] ) ) {
					$inner = self::failed_widget_html( $instance_cls->name() );
				} else {
					try {
						$inner = $instance_cls->render_html( (array) ( $widget['config'] ?? [] ), $data );
					} catch ( \Throwable $e ) {
						Layout\Snapshot::log_failure( $instance_cls->id(), $e );
						$inner = self::failed_widget_html( $instance_cls->name() );
					}
				}
				?>
				<div
					class="dy-dashboard__slot dy-dashboard__slot--<?php echo esc_attr( (string) $slots ); ?>"
					data-widget-id="<?php echo esc_attr( $instance_cls->id() ); ?>"
					data-instance="<?php echo esc_attr( $key ); ?>"
					data-slots="<?php echo esc_attr( (string) $slots ); ?>"
				>
					<?php echo wp_kses( $inner, Kses::ui_tags() ); ?>
				</div>
				<?php
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_nav( int $total, int $active_index ): string {
		if ( $total <= 1 ) {
			// No navigation when there is only one screen.
			return '';
		}
		ob_start();
		?>
		<nav class="dy-dashboard__nav" aria-label="<?php esc_attr_e( 'Dashboard screens navigation', 'doubleyoupi-refined-admin-dashboard' ); ?>">
			<button
				type="button"
				class="dy-dashboard__nav-arrow dy-dashboard__nav-arrow--prev"
				data-nav-direction="prev"
				aria-label="<?php esc_attr_e( 'Previous screen', 'doubleyoupi-refined-admin-dashboard' ); ?>"
			>
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
			</button>
			<?php // A row of buttons, not tabs: the "+ screen" buttons sit between them (audit, 21/10/2026). ?>
			<ul class="dy-dashboard__pagination" role="none">
				<?php for ( $i = 0; $i < $total; $i++ ) : ?>
					<li role="presentation">
						<button
							type="button"
							class="dy-dashboard__nav-dot<?php echo $i === $active_index ? ' is-active' : ''; ?>"
							data-screen-target="<?php echo esc_attr( (string) $i ); ?>"
							<?php echo $i === $active_index ? 'aria-current="true"' : ''; ?>
							aria-label="<?php
							/* translators: %d: screen number */
							echo esc_attr( sprintf( __( 'Go to screen %d', 'doubleyoupi-refined-admin-dashboard' ), $i + 1 ) );
							?>"
						></button>
					</li>
				<?php endfor; ?>
			</ul>
			<button
				type="button"
				class="dy-dashboard__nav-arrow dy-dashboard__nav-arrow--next"
				data-nav-direction="next"
				aria-label="<?php esc_attr_e( 'Next screen', 'doubleyoupi-refined-admin-dashboard' ); ?>"
			>
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
			</button>
		</nav>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_customize_button(): string {
		$can_customize = current_user_can( 'doubleyoupi_customize_dashboard' )
			|| current_user_can( 'manage_options' );
		if ( ! $can_customize ) {
			return '';
		}
		ob_start();
		?>
		<button
			type="button"
			class="dy-dashboard__customize-btn"
			data-action="open-sidebar"
			aria-controls="dy-dashboard-sidebar"
		>
			<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<line x1="4" y1="6" x2="20" y2="6"/>
				<line x1="4" y1="12" x2="20" y2="12"/>
				<line x1="4" y1="18" x2="14" y2="18"/>
				<circle cx="17" cy="18" r="2" fill="currentColor" stroke="none"/>
			</svg>
			<span class="dy-dashboard__customize-btn-label"><?php esc_html_e( 'Customize', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
		</button>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_empty_state(): string {
		return '<div class="dy-dashboard__empty-state">'
			. esc_html__( 'No screen configured.', 'doubleyoupi-refined-admin-dashboard' )
			. '</div>';
	}

	/**
	 * Converts `accent_intensity` (auto/high/medium/low/none) to a 0..1 value.
	 * In `auto` mode, intensity decreases from the first to the last screen.
	 */
	private static function resolve_intensity( string $mode, int $index, int $total ): float {
		switch ( $mode ) {
			case 'high':
				return 1.0;
			case 'medium':
				return 0.5;
			case 'low':
				return 0.25;
			case 'none':
				return 0.0;
			case 'auto':
			default:
				if ( $total <= 1 ) {
					return 1.0;
				}
				// First screen: 1.0; last user screen: ~0.25.
				// Linear decrease across that range.
				$ratio = $index / max( ( $total - 1 ), 1 );
				return max( 0.0, 1.0 - $ratio * 0.75 );
		}
	}

	/**
	 * Formats a float for safe insertion into a CSS custom property.
	 */
	private static function css_number( float $value ): string {
		return number_format( max( 0.0, min( 1.0, $value ) ), 3, '.', '' );
	}

	/**
	 * "#1a2b3c" → "26, 43, 60" for rgba(var(--dya-accent-rgb), .x).
	 * The hex value is already validated upstream (LayoutValidator::sanitize_optional_hex).
	 */
	private static function hex_to_rgb( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) !== 6 ) {
			return '212, 178, 122'; // default accent fallback
		}
		return sprintf(
			'%d, %d, %d',
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) )
		);
	}

	/**
	 * Readable text colour (black/white) on a given accent colour, using WCAG
	 * relative luminance. Used for --dya-accent-on (text on buttons/pills).
	 */
	private static function readable_on( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) !== 6 ) {
			return '#fff';
		}
		$lin = static function ( float $c ): float {
			$c /= 255;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		$l = 0.2126 * $lin( (float) hexdec( substr( $hex, 0, 2 ) ) )
			+ 0.7152 * $lin( (float) hexdec( substr( $hex, 2, 2 ) ) )
			+ 0.0722 * $lin( (float) hexdec( substr( $hex, 4, 2 ) ) );
		return $l > 0.45 ? '#10131a' : '#ffffff';
	}

	/**
	 * Light Markdown for screen paragraphs: **bold**, *italic*,
	 * [link](url), `code`. Tags are produced locally then sanitised via
	 * wp_kses to block any injection.
	 */
	private static function apply_light_markdown( string $raw ): string {
		// No escape-then-unescape round trip: a user could type the escaped
		// form of a link and have it brought back to life. Links are set aside
		// as tokens, the text is escaped, then each link is rebuilt from its
		// own escaped parts.
		$text  = str_replace( "\0", '', wp_strip_all_tags( $raw ) );
		$links = [];

		// Links: [label](url) — esc_url keeps http(s) and relative URLs only.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/u',
			static function ( array $m ) use ( &$links ): string {
				$links[] = [ $m[1], $m[2] ];
				return "\0" . ( count( $links ) - 1 ) . "\0";
			},
			$text
		) ?? $text;

		$out = esc_html( $text );

		// Bold / italic / code — applied to the escaped text.
		$out = preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $out ) ?? $out;
		$out = preg_replace( '/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '<em>$1</em>', $out ) ?? $out;
		$out = preg_replace( '/`([^`\n]+)`/u', '<code>$1</code>', $out ) ?? $out;

		$out = preg_replace_callback(
			'/\0(\d+)\0/',
			static function ( array $m ) use ( $links ): string {
				[ $label, $url ] = $links[ (int) $m[1] ] ?? [ '', '' ];
				$url = esc_url( $url );
				if ( '' === $url ) {
					return esc_html( $label );
				}
				return '<a href="' . $url . '" class="dy-dashboard__paragraph-link">' . esc_html( $label ) . '</a>';
			},
			$out
		) ?? $out;

		return wp_kses(
			$out,
			[
				'a'      => [ 'href' => true, 'class' => true ],
				'strong' => [],
				'em'     => [],
				'code'   => [],
			]
		);
	}

	/**
	 * Card shown in place of a widget whose data or markup threw: the
	 * dashboard stays up, the owner sees which tile is out.
	 */
	private static function failed_widget_html( string $name ): string {
		return '<div class="dy-dashboard__card-h"><span class="dy-dashboard__card-title">' . esc_html( $name ) . '</span></div>'
			. '<div class="dy-dashboard__card-body"><p class="dy-dashboard__empty">'
			. esc_html__( 'This widget could not load.', 'doubleyoupi-refined-admin-dashboard' ) . '</p></div>';
	}
}

<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Tables;

use DoubleYoupi\Core\Build;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ColumnWatch — tells the user when a list table gained columns.
 *
 * A plugin that adds columns to a list (WooCommerce on products, an SEO
 * plugin on posts) does so silently: the columns just appear. We show them
 * (the author wanted them visible, and hiding is one click away) and say
 * where they come from, once, without blocking anything: a dot on the table
 * gear for the first visit and a notice that lands in the notices centre.
 *
 * Per user, per screen, the set of column ids last seen lives in the meta
 * `doubleyoupi_tables_seen_columns`. The first visit records the set silently
 * (no storm after an update); later visits diff it. Attribution walks the
 * callbacks hooked on the `manage_*_columns` filters and maps their files to
 * plugins, so the notice can name WooCommerce rather than "an extension".
 */
final class ColumnWatch {

	public const META_SEEN = 'doubleyoupi_tables_seen_columns';

	/** Screens remembered per user (oldest dropped beyond this). */
	private const MAX_SCREENS = 40;

	/** @var array{new:array<int,array{id:string,label:string}>,by:string[]}|null */
	private ?array $result = null;
	private bool $resolved = false;

	public function boot(): void {
		add_filter( 'doubleyoupi_filter_tables_config', [ $this, 'inject_config' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	/**
	 * Adds `newColumns` / `newColumnsBy` to the JS config of the list.
	 *
	 * @param array<string,mixed> $config
	 * @param array<string,mixed> $screen
	 * @return array<string,mixed>
	 */
	public function inject_config( array $config, array $screen ): array {
		$r = $this->detect();
		if ( null !== $r && ! empty( $r['new'] ) ) {
			$config['newColumns']   = $r['new'];
			$config['newColumnsBy'] = $r['by'];
		}
		return $config;
	}

	public function render_notice(): void {
		$r = $this->detect();
		if ( null === $r || empty( $r['new'] ) ) {
			return;
		}
		$labels = implode( ', ', array_map( static fn( array $c ): string => $c['label'], $r['new'] ) );
		if ( ! empty( $r['by'] ) ) {
			$text = sprintf(
				/* translators: 1: extension name(s), 2: column names */
				__( 'New columns from %1$s: %2$s.', 'doubleyoupi-refined-admin-dashboard' ),
				implode( ', ', $r['by'] ),
				$labels
			);
		} else {
			$text = sprintf(
				/* translators: %s: column names */
				__( 'New columns added by an extension: %s.', 'doubleyoupi-refined-admin-dashboard' ),
				$labels
			);
		}
		printf(
			'<div class="notice notice-info is-dismissible dy-column-watch-notice"><p>%1$s %2$s</p></div>',
			esc_html( $text ),
			esc_html__( 'Hide the ones you do not need from the table gear.', 'doubleyoupi-refined-admin-dashboard' )
		);
	}

	/**
	 * Diffs the current column set against the last seen one. Runs once per
	 * request; records the new set. Null when the screen is not a list.
	 *
	 * @return array{new:array<int,array{id:string,label:string}>,by:string[]}|null
	 */
	private function detect(): ?array {
		if ( $this->resolved ) {
			return $this->result;
		}
		$this->resolved = true;

		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}
		// Admin Columns owns the columns of these lists; our gear is not there.
		if ( \DoubleYoupi\Modules\Admin\AdminColumnsConflict::steps_aside() ) {
			return null;
		}
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || ! in_array( $screen->base, [ 'edit', 'users', 'edit-tags' ], true ) ) {
			return null;
		}
		$headers = get_column_headers( $screen );
		if ( ! is_array( $headers ) || empty( $headers ) ) {
			return null;
		}
		$ids = [];
		foreach ( array_keys( $headers ) as $id ) {
			$id = (string) $id;
			if ( '' !== $id && 'cb' !== $id ) {
				$ids[] = $id;
			}
		}
		sort( $ids );

		$user_id = get_current_user_id();
		$seen    = $this->read_seen( $user_id );
		$key     = (string) $screen->id;
		$before  = isset( $seen[ $key ] ) && is_array( $seen[ $key ] ) ? array_map( 'strval', $seen[ $key ] ) : null;

		$new = [];
		if ( null !== $before ) {
			foreach ( array_diff( $ids, $before ) as $id ) {
				$label = trim( wp_strip_all_tags( (string) $headers[ $id ] ) );
				$new[] = [ 'id' => $id, 'label' => '' !== $label ? $label : $id ];
			}
		}
		if ( null === $before || $before !== $ids ) {
			unset( $seen[ $key ] );
			$seen[ $key ] = $ids;
			if ( count( $seen ) > self::MAX_SCREENS ) {
				$seen = array_slice( $seen, -self::MAX_SCREENS, null, true );
			}
			update_user_meta( $user_id, self::META_SEEN, wp_slash( wp_json_encode( $seen ) ) );
		}

		$this->result = [
			'new' => $new,
			'by'  => $new ? $this->attribute( $screen ) : [],
		];
		return $this->result;
	}

	/** @return array<string,array<int,string>> */
	private function read_seen( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_SEEN, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return [];
	}

	/**
	 * Names of the extensions (plugins, theme) hooked on the column filters of
	 * this screen, excluding core and ourselves.
	 *
	 * @return string[]
	 */
	private function attribute( \WP_Screen $screen ): array {
		$hooks = [ 'manage_' . $screen->id . '_columns' ];
		if ( 'edit' === $screen->base && $screen->post_type ) {
			$hooks[] = 'manage_' . $screen->post_type . '_posts_columns';
			$hooks[] = 'manage_posts_columns';
			$hooks[] = 'manage_pages_columns';
		} elseif ( 'users' === $screen->base ) {
			$hooks[] = 'manage_users_columns';
		} elseif ( 'edit-tags' === $screen->base && $screen->taxonomy ) {
			$hooks[] = 'manage_edit-' . $screen->taxonomy . '_columns';
		}

		global $wp_filter;
		$names = [];
		foreach ( array_unique( $hooks ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof \WP_Hook ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( (array) $callbacks as $cb ) {
					$name = self::owner_name( self::callback_file( $cb['function'] ?? null ) );
					if ( null !== $name ) {
						$names[ $name ] = true;
					}
				}
			}
		}
		return array_keys( $names );
	}

	/** File that declares a callback, '' when it cannot be resolved. */
	private static function callback_file( $fn ): string {
		try {
			if ( $fn instanceof \Closure ) {
				return (string) ( new \ReflectionFunction( $fn ) )->getFileName();
			}
			if ( is_array( $fn ) && 2 === count( $fn ) ) {
				return (string) ( new \ReflectionMethod( $fn[0], (string) $fn[1] ) )->getFileName();
			}
			if ( is_string( $fn ) ) {
				if ( str_contains( $fn, '::' ) ) {
					return (string) ( new \ReflectionMethod( $fn ) )->getFileName();
				}
				return (string) ( new \ReflectionFunction( $fn ) )->getFileName();
			}
			if ( is_object( $fn ) && method_exists( $fn, '__invoke' ) ) {
				return (string) ( new \ReflectionMethod( $fn, '__invoke' ) )->getFileName();
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}

	/** Human name of the plugin or theme owning a file; null for core or us. */
	private static function owner_name( string $file ): ?string {
		if ( '' === $file ) {
			return null;
		}
		$file = wp_normalize_path( $file );
		// Every Double Youpi root, not just the constant: with two standalone
		// builds active the constant names only one of them, and a column
		// registered by the other would be credited to a third party.
		foreach ( Build::roots() as $ours ) {
			if ( str_starts_with( $file, $ours ) ) {
				return null;
			}
		}

		// plugin_basename() reduces a file inside the plugins or must-use
		// plugins directory to its relative path (symlinked plugins included)
		// and leaves any other file as it is: no directory constant to read.
		$rel = plugin_basename( $file );
		if ( '' !== $rel && trim( $file, '/' ) !== $rel ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( ! str_contains( $rel, '/' ) && isset( get_mu_plugins()[ $rel ] ) ) {
				return basename( $rel, '.php' );
			}
			$slug = strtok( $rel, '/' );
			if ( ! is_string( $slug ) || '' === $slug ) {
				return null;
			}
			foreach ( get_plugins() as $basename => $data ) {
				if ( str_starts_with( (string) $basename, $slug . '/' ) || $basename === $slug ) {
					$name = trim( wp_strip_all_tags( (string) ( $data['Name'] ?? '' ) ) );
					return '' !== $name ? $name : $slug;
				}
			}
			return $slug;
		}

		$themes = wp_normalize_path( get_theme_root() ) . '/';
		if ( str_starts_with( $file, $themes ) ) {
			$theme = wp_get_theme();
			$name  = trim( wp_strip_all_tags( (string) $theme->get( 'Name' ) ) );
			return '' !== $name ? $name : null;
		}

		return null; // core, or somewhere we do not name
	}
}

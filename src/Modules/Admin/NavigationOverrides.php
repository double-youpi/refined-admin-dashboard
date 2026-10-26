<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NavigationOverrides — user layer on top of the static PluginMap.
 *
 * Stores navigation tree customizations (reordering, cross-line moves,
 * hiding, promotion/demotion) in the JSON option
 * `doubleyoupi_admin_navigation_overrides`. Reset = simply deleting the option,
 * which fully restores the default curated map.
 *
 * Option schema:
 * {
 *   "version": 1,
 *   "line_order":        ["content", "commerce", …],            // order of the 7 lines
 *   "standalone_order":  ["bricks", "elementor", "astra"],      // order of the top slots in the rail
 *   "classifications":   { "wpseo_dashboard": "developer" },    // override of PluginMap::classify
 *   "weights":           { "edit.php": 5 },                     // override of PluginMap::item_weight
 *   "hidden":            ["edit-comments.php"],                 // hidden chips
 *   "subitem_hidden":    ["edit.php::post-new.php"],            // hidden sub-pages (parent_slug::child_slug)
 *   "promoted":          { "site-health.php": "system" },       // sub-page → top-level chip in this line
 *   "demoted":           { "tools.php": "system::index.php" },  // chip → sub-page under this parent (line::parent_slug)
 *   "subitem_order":     { "edit.php": ["edit.php", "edit-tags.php?taxonomy=category"] }
 * }
 *
 * All `apply_*` methods return a value (never null): if there is no override,
 * they return the default value passed as argument. This contract allows
 * Rail::collect() to call them unconditionally without extra branching.
 */
final class NavigationOverrides {

	public const OPTION_KEY = 'doubleyoupi_admin_navigation_overrides';
	public const VERSION    = 2;

	/** Prefix for ids of custom groups created by the user. */
	public const CUSTOM_GROUP_PREFIX = 'dyg_';

	/** Anti-bloat guards. */
	private const MAX_SLUG_LEN   = 300;
	private const MAX_LIST_LEN   = 500;
	private const MAX_LABEL_LEN  = 60;
	private const MAX_GROUPS     = 30;

	/** @var array<string, mixed>|null In-memory cache to avoid multiple get_option calls per request. */
	private static ?array $cache = null;

	/**
	 * Empty schema (state "no overrides"). All `apply_*` methods fall back
	 * to their default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'version'          => self::VERSION,
			// v2: unified order for ALL columns (native lines + standalone slugs
			// + custom group ids). Supersedes line_order + standalone_order for
			// ordering (those are still read as a fallback).
			'column_order'     => [],
			'custom_groups'    => [],   // { groupId: { label, icon } }
			'group_labels'     => [],   // { lineKey|groupId: label } — renommage
			'group_icons'      => [],   // { lineKey|groupId: iconId }
			// v1 fields (kept for backwards compatibility).
			'line_order'       => [],
			'standalone_order' => [],
			'classifications'  => [],
			'weights'          => [],
			'hidden'           => [],
			'subitem_hidden'   => [],
			'promoted'         => [],
			'demoted'          => [],
			'subitem_order'    => [],
			// v2: re-parenting a sub-page to a different chip parent.
			// { childSlug: newParentSlug }. Covers moving a sub-page from
			// one chip to another (a case that promoted/demoted do not handle:
			// the item is not top-level in $menu).
			'subitem_reparent' => [],
			// Where the rail's separator sits (24/10/2026): the id of the
			// first column below it, 'end' for no bottom zone, '' for the
			// automatic rule (before the first tool column).
			'divider_before'   => '',
		];
	}

	/** The rail separator chosen in the editor: a column id, 'end', or '' (automatic). */
	public static function divider_before(): string {
		return (string) ( self::get()['divider_before'] ?? '' );
	}

	/**
	 * Sub-page re-parenting: { childSlug => newParentSlug }.
	 *
	 * @return array<string,string>
	 */
	public static function subitem_reparent(): array {
		$ov  = self::get();
		$out = [];
		foreach ( (array) ( $ov['subitem_reparent'] ?? [] ) as $child => $parent ) {
			$child  = PluginMap::normalize_slug( (string) $child );
			$parent = PluginMap::normalize_slug( (string) $parent );
			if ( '' !== $child && '' !== $parent ) {
				$out[ $child ] = $parent;
			}
		}
		return $out;
	}

	/**
	 * Valid classification targets: the 7 native lines + defined custom group ids.
	 * A chip/sub-page can be assigned to any of them. (Standalone slugs are NOT
	 * classification targets — we do not place arbitrary chips in them.)
	 *
	 * @return string[]
	 */
	public static function valid_targets(): array {
		$ov  = self::get();
		$out = PluginMap::line_order();
		foreach ( array_keys( (array) $ov['custom_groups'] ) as $gid ) {
			$gid = (string) $gid;
			if ( str_starts_with( $gid, self::CUSTOM_GROUP_PREFIX ) ) {
				$out[] = $gid;
			}
		}
		return $out;
	}

	/**
	 * Raw read of overrides (merged with defaults so all keys are always present).
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw = get_option( self::OPTION_KEY, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			self::$cache = self::defaults();
			return self::$cache;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			self::$cache = self::defaults();
			return self::$cache;
		}
		self::$cache = array_merge( self::defaults(), $decoded );
		return self::$cache;
	}

	/**
	 * Write (with strict sanitization). Returns the actually persisted object
	 * (after sanitization) or WP_Error on invalid input.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set( array $payload ) {
		$sanitized = self::sanitize( $payload );
		update_option( self::OPTION_KEY, wp_json_encode( $sanitized ), true ); // read on every admin screen
		self::$cache = $sanitized;
		return $sanitized;
	}

	/**
	 * Deletes the option — fully restores the default map.
	 */
	public static function reset(): void {
		delete_option( self::OPTION_KEY );
		self::$cache = null;
	}

	/**
	 * For tests / caches that need to force a reload.
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	// -----------------------------------------------------------------
	// Override application — methods called by Rail::collect().
	// -----------------------------------------------------------------

	/**
	 * Returns the effective line for a slug: override > $default.
	 * If the returned value is not in LINE_ORDER, falls back to $default.
	 */
	public static function apply_to_line( string $slug, ?string $default ): ?string {
		$ov   = self::get();
		$slug = PluginMap::normalize_slug( $slug );
		if ( ! isset( $ov['classifications'][ $slug ] ) ) {
			return $default;
		}
		$line = (string) $ov['classifications'][ $slug ];
		// Invalid target (unknown line, deleted custom group…) → we NEVER
		// drop the chip: fall back to the original default.
		if ( ! in_array( $line, self::valid_targets(), true ) ) {
			return $default;
		}
		return $line;
	}

	/**
	 * Returns the effective weight for a slug: override > $default.
	 */
	public static function apply_to_weight( string $slug, int $default ): int {
		$ov   = self::get();
		$slug = PluginMap::normalize_slug( $slug );
		if ( ! isset( $ov['weights'][ $slug ] ) ) {
			return $default;
		}
		return (int) $ov['weights'][ $slug ];
	}

	/**
	 * True if the chip (top-level slug) is hidden.
	 */
	public static function is_chip_hidden( string $slug ): bool {
		$ov = self::get();
		return in_array( PluginMap::normalize_slug( $slug ), (array) $ov['hidden'], true );
	}

	/**
	 * True if a sub-page (under a given parent) is hidden.
	 * Canonical key: `{parent_slug}::{child_slug}` to avoid collisions.
	 */
	public static function is_subitem_hidden( string $parent_slug, string $child_slug ): bool {
		$ov  = self::get();
		$key = PluginMap::normalize_slug( $parent_slug ) . '::' . PluginMap::normalize_slug( $child_slug );
		return in_array( $key, (array) $ov['subitem_hidden'], true );
	}

	/**
	 * Returns the custom sub-item order for a parent, or null if no override.
	 *
	 * @return string[]|null
	 */
	public static function subitem_order_for( string $parent_slug ): ?array {
		$ov          = self::get();
		$parent_slug = PluginMap::normalize_slug( $parent_slug );
		if ( ! isset( $ov['subitem_order'][ $parent_slug ] ) ) {
			return null;
		}
		$order = (array) $ov['subitem_order'][ $parent_slug ];
		return array_values( array_map( 'strval', $order ) );
	}

	// -----------------------------------------------------------------
	// v2 accessors — columns, custom groups, labels, icons.
	// -----------------------------------------------------------------

	/**
	 * Unified column order, or null if no override. The consumer (Rail)
	 * appends missing columns and ignores disappeared ones.
	 *
	 * @return string[]|null
	 */
	public static function column_order(): ?array {
		$ov = self::get();
		if ( empty( $ov['column_order'] ) ) {
			return null;
		}
		return array_values( array_map( 'strval', (array) $ov['column_order'] ) );
	}

	/**
	 * Custom groups: { groupId: { label, icon } }.
	 *
	 * @return array<string, array{label:string, icon:string}>
	 */
	public static function custom_groups(): array {
		$ov  = self::get();
		$out = [];
		foreach ( (array) $ov['custom_groups'] as $gid => $meta ) {
			$gid = (string) $gid;
			if ( ! str_starts_with( $gid, self::CUSTOM_GROUP_PREFIX ) || ! is_array( $meta ) ) {
				continue;
			}
			$out[ $gid ] = [
				'label' => (string) ( $meta['label'] ?? $gid ),
				'icon'  => (string) ( $meta['icon'] ?? 'i-pick-folder' ),
			];
		}
		return $out;
	}

	/**
	 * Effective label of a group (native or custom line): override > default.
	 */
	public static function group_label( string $group_id, string $default ): string {
		$ov = self::get();
		$lbl = $ov['group_labels'][ $group_id ] ?? null;
		return ( is_string( $lbl ) && '' !== $lbl ) ? $lbl : $default;
	}

	/**
	 * Effective icon of a group: override > default.
	 */
	public static function group_icon( string $group_id, string $default ): string {
		$ov = self::get();
		$ic = $ov['group_icons'][ $group_id ] ?? null;
		return ( is_string( $ic ) && '' !== $ic ) ? $ic : $default;
	}

	/**
	 * Returns the custom rail line order, or null if no override.
	 *
	 * @return string[]|null
	 */
	public static function line_order(): ?array {
		$ov = self::get();
		if ( empty( $ov['line_order'] ) ) {
			return null;
		}
		$valid = PluginMap::line_order();
		$out   = [];
		foreach ( (array) $ov['line_order'] as $line ) {
			$line = (string) $line;
			if ( in_array( $line, $valid, true ) && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}
		// Append any missing lines (so that none disappears).
		foreach ( $valid as $line ) {
			if ( ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Returns the custom order of standalone slots, or null if no override.
	 *
	 * @return string[]|null
	 */
	public static function standalone_order(): ?array {
		$ov = self::get();
		if ( empty( $ov['standalone_order'] ) ) {
			return null;
		}
		return array_values( array_map( 'strval', (array) $ov['standalone_order'] ) );
	}

	/**
	 * List of sub-pages to promote to a top-level chip. Key = sub-item slug,
	 * value = target line.
	 *
	 * @return array<string, string>
	 */
	public static function promoted(): array {
		$ov      = self::get();
		$out     = [];
		$targets = self::valid_targets();
		foreach ( (array) $ov['promoted'] as $slug => $line ) {
			$slug = PluginMap::normalize_slug( (string) $slug );
			$line = (string) $line;
			if ( '' === $slug || ! in_array( $line, $targets, true ) ) {
				continue;
			}
			$out[ $slug ] = $line;
		}
		return $out;
	}

	/**
	 * List of chips to demote as a sub-page. Key = chip slug, value
	 * = "{line}::{parent_slug}" (the new parent within the given line).
	 *
	 * @return array<string, string>
	 */
	public static function demoted(): array {
		$ov  = self::get();
		$out = [];
		foreach ( (array) $ov['demoted'] as $slug => $target ) {
			$slug   = PluginMap::normalize_slug( (string) $slug );
			$target = (string) $target;
			if ( '' === $slug || ! str_contains( $target, '::' ) ) {
				continue;
			}
			$out[ $slug ] = $target;
		}
		return $out;
	}

	// -----------------------------------------------------------------
	// Sanitization — called by set() and the REST controller defensively.
	// -----------------------------------------------------------------

	/**
	 * Defensive sanitization: validates types, filters out-of-domain values
	 * (unknown lines, empty slugs, etc.). Never throws — simply drops
	 * invalid entries.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $payload ): array {
		$out         = self::defaults();
		$valid_lines = PluginMap::line_order();

		// ── custom_groups (sanitized FIRST: they extend the valid targets
		//    for classifications/promoted/demoted). ──
		if ( isset( $payload['custom_groups'] ) && is_array( $payload['custom_groups'] ) ) {
			$count = 0;
			foreach ( $payload['custom_groups'] as $gid => $meta ) {
				if ( $count >= self::MAX_GROUPS ) {
					break;
				}
				$gid = self::sanitize_group_id( (string) $gid );
				if ( '' === $gid || ! is_array( $meta ) ) {
					continue;
				}
				$label = self::sanitize_label( (string) ( $meta['label'] ?? '' ) );
				$icon  = self::sanitize_icon_id( (string) ( $meta['icon'] ?? '' ) );
				if ( '' === $label ) {
					$label = $gid;
				}
				$out['custom_groups'][ $gid ] = [
					'label' => $label,
					'icon'  => '' !== $icon ? $icon : 'i-pick-folder',
				];
				$count++;
			}
		}

		// Valid targets = native lines + retained custom group ids.
		$valid_targets = array_merge( $valid_lines, array_keys( $out['custom_groups'] ) );

		// group_labels: { lineKey|groupId: label }.
		if ( isset( $payload['group_labels'] ) && is_array( $payload['group_labels'] ) ) {
			foreach ( $payload['group_labels'] as $id => $label ) {
				$id    = (string) $id;
				$label = self::sanitize_label( (string) $label );
				if ( in_array( $id, $valid_targets, true ) && '' !== $label ) {
					$out['group_labels'][ $id ] = $label;
				}
			}
		}

		// group_icons: { lineKey|groupId: iconId }.
		if ( isset( $payload['group_icons'] ) && is_array( $payload['group_icons'] ) ) {
			foreach ( $payload['group_icons'] as $id => $icon ) {
				$id   = (string) $id;
				$icon = self::sanitize_icon_id( (string) $icon );
				if ( in_array( $id, $valid_targets, true ) && '' !== $icon ) {
					$out['group_icons'][ $id ] = $icon;
				}
			}
		}

		// column_order: column ids (lines ∪ standalone slugs ∪ custom groups).
		// Keep any well-formed slug: Rail filters columns that are actually
		// present at render time and appends any missing ones.
		if ( isset( $payload['column_order'] ) && is_array( $payload['column_order'] ) ) {
			$seen = [];
			foreach ( $payload['column_order'] as $cid ) {
				if ( count( $out['column_order'] ) >= self::MAX_LIST_LEN ) {
					break;
				}
				$cid = self::sanitize_slug( (string) $cid );
				if ( '' !== $cid && ! isset( $seen[ $cid ] ) ) {
					$out['column_order'][] = $cid;
					$seen[ $cid ]          = true;
				}
			}
		}

		if ( isset( $payload['divider_before'] ) && is_string( $payload['divider_before'] ) ) {
			$div                   = trim( $payload['divider_before'] );
			$out['divider_before'] = 'end' === $div ? 'end' : self::sanitize_slug( $div );
		}

		// line_order: valid lines only, deduplicated.
		if ( isset( $payload['line_order'] ) && is_array( $payload['line_order'] ) ) {
			$seen = [];
			foreach ( $payload['line_order'] as $line ) {
				$line = sanitize_key( (string) $line );
				if ( in_array( $line, $valid_lines, true ) && ! isset( $seen[ $line ] ) ) {
					$out['line_order'][] = $line;
					$seen[ $line ]       = true;
				}
			}
		}

		// standalone_order: free slugs, format-sanitized only.
		if ( isset( $payload['standalone_order'] ) && is_array( $payload['standalone_order'] ) ) {
			foreach ( $payload['standalone_order'] as $slug ) {
				$slug = self::sanitize_slug( (string) $slug );
				if ( '' !== $slug ) {
					$out['standalone_order'][] = $slug;
				}
			}
		}

		// classifications: slug → valid target (native line or custom group).
		if ( isset( $payload['classifications'] ) && is_array( $payload['classifications'] ) ) {
			foreach ( $payload['classifications'] as $slug => $line ) {
				if ( count( $out['classifications'] ) >= self::MAX_LIST_LEN ) {
					break;
				}
				$slug = self::sanitize_slug( (string) $slug );
				$line = (string) $line;
				if ( '' === $slug || ! in_array( $line, $valid_targets, true ) ) {
					continue;
				}
				$out['classifications'][ $slug ] = $line;
			}
		}

		// weights: slug → int (-1000..1000 guard).
		if ( isset( $payload['weights'] ) && is_array( $payload['weights'] ) ) {
			foreach ( $payload['weights'] as $slug => $weight ) {
				$slug   = self::sanitize_slug( (string) $slug );
				$weight = (int) $weight;
				if ( '' === $slug ) {
					continue;
				}
				$out['weights'][ $slug ] = max( -1000, min( 1000, $weight ) );
			}
		}

		// hidden: list of slugs.
		if ( isset( $payload['hidden'] ) && is_array( $payload['hidden'] ) ) {
			foreach ( $payload['hidden'] as $slug ) {
				$slug = self::sanitize_slug( (string) $slug );
				if ( '' !== $slug && ! in_array( $slug, $out['hidden'], true ) ) {
					$out['hidden'][] = $slug;
				}
			}
		}

		// subitem_hidden: list of "parent::child" keys.
		if ( isset( $payload['subitem_hidden'] ) && is_array( $payload['subitem_hidden'] ) ) {
			foreach ( $payload['subitem_hidden'] as $key ) {
				$key = (string) $key;
				if ( ! str_contains( $key, '::' ) ) {
					continue;
				}
				[ $parent, $child ] = explode( '::', $key, 2 );
				$parent             = self::sanitize_slug( $parent );
				$child              = self::sanitize_slug( $child );
				if ( '' !== $parent && '' !== $child ) {
					$out['subitem_hidden'][] = $parent . '::' . $child;
				}
			}
		}

		// promoted: sub-item slug → valid target.
		if ( isset( $payload['promoted'] ) && is_array( $payload['promoted'] ) ) {
			foreach ( $payload['promoted'] as $slug => $line ) {
				if ( count( $out['promoted'] ) >= self::MAX_LIST_LEN ) {
					break;
				}
				$slug = self::sanitize_slug( (string) $slug );
				$line = (string) $line;
				if ( '' === $slug || ! in_array( $line, $valid_targets, true ) ) {
					continue;
				}
				$out['promoted'][ $slug ] = $line;
			}
		}

		// demoted: chip slug → "target::parent_slug" (target = line or group).
		if ( isset( $payload['demoted'] ) && is_array( $payload['demoted'] ) ) {
			foreach ( $payload['demoted'] as $slug => $target ) {
				if ( count( $out['demoted'] ) >= self::MAX_LIST_LEN ) {
					break;
				}
				$slug   = self::sanitize_slug( (string) $slug );
				$target = (string) $target;
				if ( '' === $slug || ! str_contains( $target, '::' ) ) {
					continue;
				}
				[ $line, $parent ] = explode( '::', $target, 2 );
				$parent            = self::sanitize_slug( $parent );
				if ( ! in_array( $line, $valid_targets, true ) || '' === $parent ) {
					continue;
				}
				$out['demoted'][ $slug ] = $line . '::' . $parent;
			}
		}

		// subitem_order: parent_slug → [child_slug, …].
		if ( isset( $payload['subitem_order'] ) && is_array( $payload['subitem_order'] ) ) {
			foreach ( $payload['subitem_order'] as $parent => $children ) {
				$parent = self::sanitize_slug( (string) $parent );
				if ( '' === $parent || ! is_array( $children ) ) {
					continue;
				}
				$list = [];
				foreach ( $children as $child ) {
					$child = self::sanitize_slug( (string) $child );
					if ( '' !== $child && ! in_array( $child, $list, true ) ) {
						$list[] = $child;
					}
				}
				if ( ! empty( $list ) ) {
					$out['subitem_order'][ $parent ] = $list;
				}
			}
		}

		// subitem_reparent: childSlug → newParentSlug (moving a sub-page
		// from one chip to another).
		if ( isset( $payload['subitem_reparent'] ) && is_array( $payload['subitem_reparent'] ) ) {
			foreach ( $payload['subitem_reparent'] as $child => $parent ) {
				if ( count( $out['subitem_reparent'] ) >= self::MAX_LIST_LEN ) {
					break;
				}
				$child  = self::sanitize_slug( (string) $child );
				$parent = self::sanitize_slug( (string) $parent );
				if ( '' !== $child && '' !== $parent && $child !== $parent ) {
					$out['subitem_reparent'][ $child ] = $parent;
				}
			}
		}

		return $out;
	}

	/**
	 * Valid WP-admin slug: alphanumeric + hyphens + . + ? + = + & + /.
	 * WP slugs can include query strings (`edit.php?post_type=page`).
	 * We reject any HTML/script character and any protocol injection.
	 */
	private static function sanitize_slug( string $slug ): string {
		$slug = trim( $slug );
		if ( '' === $slug || strlen( $slug ) > self::MAX_SLUG_LEN ) {
			return '';
		}
		// Reject protocols & HTML.
		if ( preg_match( '/[<>"\'\\\\]/', $slug ) ) {
			return '';
		}
		if ( preg_match( '#^(?:javascript|data|vbscript):#i', $slug ) ) {
			return '';
		}
		// Whitelist: alphanum + . _ / ? = & % # , : - (chars valid in
		// slug+query; `#` anchor, `,` WC paths `path=/a,b`, `:` occasionally).
		// Delimiter `~` because the pattern itself contains `#`.
		if ( ! preg_match( '~^[A-Za-z0-9._/?=&%#,:\\-]+$~', $slug ) ) {
			return '';
		}
		// Canonical unique form (see PluginMap::normalize_slug) — ensures that
		// the stored key == the snapshot key == the key read from $menu.
		return PluginMap::normalize_slug( $slug );
	}

	/**
	 * Custom group id: must start with `dyg_` followed by alphanum/-/_.
	 */
	private static function sanitize_group_id( string $id ): string {
		$id = trim( $id );
		if ( ! str_starts_with( $id, self::CUSTOM_GROUP_PREFIX ) ) {
			return '';
		}
		if ( strlen( $id ) > self::MAX_SLUG_LEN || ! preg_match( '/^dyg_[A-Za-z0-9_\\-]{1,64}$/', $id ) ) {
			return '';
		}
		return $id;
	}

	/**
	 * Group label: plain text, no HTML, bounded length.
	 */
	private static function sanitize_label( string $label ): string {
		$label = trim( wp_strip_all_tags( $label ) );
		if ( '' === $label ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $label, 0, self::MAX_LABEL_LEN );
		}
		return substr( $label, 0, self::MAX_LABEL_LEN );
	}

	/**
	 * DY icon set id: `i-pick-…` (the picker only exposes this namespace).
	 */
	private static function sanitize_icon_id( string $icon ): string {
		$icon = trim( $icon );
		if ( ! preg_match( '/^i-pick-[a-z0-9\\-]{1,40}$/', $icon ) ) {
			return '';
		}
		return $icon;
	}
}

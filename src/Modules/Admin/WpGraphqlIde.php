<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WpGraphqlIde: completes the WPGraphQL IDE skin (_wpgraphql.scss) where
 * CSS alone cannot reach.
 *
 * WPGraphQL IDE 5 colours the query with its own variables, which the
 * stylesheet maps onto our palette. Its response, variables and headers
 * panes, though, fall back on CodeMirror's default highlight style: fixed
 * colours (#a11 for strings, #164 for numbers…) on generated class names
 * (`.ͼ1j`) that change with every IDE release. On our dark editor the
 * strings fell to 2.3:1 (headless test of 21/10/2026).
 *
 * The script reads those generated rules, recognises the default colours and
 * restates each class with the matching IDE variable (set on <body> by
 * _wpgraphql.scss), so the JSON follows the theme and the accent like the
 * query does. It runs
 * on every admin screen while the IDE is active, since the IDE also opens as
 * a drawer from the admin bar, and watches for the styles CodeMirror adds
 * when an editor mounts.
 */
final class WpGraphqlIde {

	private const SYNTAX_JS = <<<'JS'
(function () {
	var MAP = {
		'rgb(170, 17, 17)': '--json-color-string',
		'rgb(17, 102, 68)': '--json-color-number',
		'rgb(34, 17, 153)': '--json-color-bool',
		'rgb(119, 0, 136)': '--gql-color-keyword',
		'rgb(0, 0, 204)': '--json-color-property',
		'rgb(0, 0, 255)': '--json-color-property',
		'rgb(51, 0, 170)': '--gql-color-variable',
		'rgb(0, 136, 85)': '--gql-color-type',
		'rgb(17, 102, 119)': '--gql-color-type',
		'rgb(34, 85, 102)': '--gql-color-directive',
		'rgb(238, 68, 0)': '--gql-color-variable',
		'rgb(153, 68, 0)': '--gql-color-comment'
	};
	var done = {}, out = null, queued = false;
	function rules(sheet) {
		try { return sheet.cssRules || []; } catch (e) { return []; }
	}
	function scan() {
		queued = false;
		var sheets = [].slice.call(document.styleSheets).concat(document.adoptedStyleSheets || []);
		var add = '';
		sheets.forEach(function (sheet) {
			[].forEach.call(rules(sheet), function (r) {
				var sel = r.selectorText;
				if (!sel || done[sel] || !/^\.\u037c[0-9a-z]+$/.test(sel) || !r.style) { return; }
				var v = MAP[r.style.color];
				if (!v) { return; }
				done[sel] = true;
				add += 'body.dy-admin ' + sel + '{color:var(' + v + ')}';
			});
		});
		if (!add) { return; }
		if (!out) {
			out = document.createElement('style');
			out.id = 'doubleyoupi-wpgraphql-ide-syntax';
			document.head.appendChild(out);
		}
		out.appendChild(document.createTextNode(add));
	}
	function later() {
		if (!queued) { queued = true; window.requestAnimationFrame(scan); }
	}
	new MutationObserver(function (list) {
		for (var i = 0; i < list.length; i++) {
			if (list[i].target !== out && !(out && out.contains(list[i].target))) { later(); return; }
		}
	}).observe(document.head, { childList: true, subtree: true, characterData: true });
	document.addEventListener('DOMContentLoaded', later);
	window.addEventListener('load', later);
	document.addEventListener('click', function (e) {
		if (e.target && e.target.closest && e.target.closest('#wpgraphql-ide-root, #wp-admin-bar-wpgraphql-ide')) { later(); }
	}, true);
})();
JS;

	public function boot(): void {
		add_action( 'admin_head', [ $this, 'print_syntax_script' ], 20 );
	}

	/**
	 * True when WPGraphQL IDE (5.x, the @wordpress/components rebuild) is active.
	 */
	public static function is_active(): bool {
		return defined( 'WPGRAPHQL_IDE_VERSION' ) && version_compare( (string) WPGRAPHQL_IDE_VERSION, '5.0', '>=' );
	}

	public function print_syntax_script(): void {
		if ( ! self::is_active() ) {
			return;
		}
		wp_print_inline_script_tag( self::SYNTAX_JS, [ 'id' => 'doubleyoupi-wpgraphql-ide' ] );
	}
}

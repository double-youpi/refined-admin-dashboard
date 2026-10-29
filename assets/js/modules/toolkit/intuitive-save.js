/**
 * Double Youpi — Toolkit / Intuitive Save
 *
 * Intercepts Cmd+S (Mac) / Ctrl+S (PC) on WordPress admin pages and
 * triggers the primary save button on the page.
 *
 * Explicitly excluded target: the block editor (Gutenberg) — WP 5.0+ already
 * has the native `editor/save` shortcut, so we avoid double-saving.
 *
 * Additional target: the TinyMCE iframe in Classic Editor — when focus is
 * inside it, keydown events do not bubble up to `document` (frame boundary),
 * so we register a native TinyMCE shortcut that syncs with the WP publish button.
 */
(function () {
	'use strict';

	var isMac = /Mac|iPod|iPhone|iPad/i.test( navigator.platform );

	function isSaveShortcut( event ) {
		// The key can be 's' or 'S' depending on Shift, so we accept both.
		if ( event.key !== 's' && event.key !== 'S' ) {
			return false;
		}
		// Reject if additional modifiers are present (Shift, Alt, Cmd+Ctrl simultaneously…).
		if ( event.altKey || event.shiftKey ) {
			return false;
		}
		if ( isMac ) {
			return event.metaKey && ! event.ctrlKey;
		}
		return event.ctrlKey && ! event.metaKey;
	}

	/**
	 * The block editor has its own native shortcut: recognised by the classes
	 * WordPress puts on its pages. Not by the `core/editor` data store: the
	 * classic editor loads it too since WordPress 7.1, and the shortcut then
	 * did nothing there (review of 29/10/2026).
	 */
	function isInBlockEditor() {
		var c = document.body && document.body.classList;
		return !! c && ( c.contains( 'block-editor-page' ) || c.contains( 'wp-admin-block-editor' ) || c.contains( 'site-editor-php' ) );
	}

	/**
	 * Finds the button to click. It saves, it never publishes (security
	 * review of 29/10/2026: « Publish » came first, so Cmd+S on a draft
	 * published it). Priority order:
	 *  1. #save-post (Classic Editor: « Save Draft », « Save as Pending »),
	 *     present while the post is not published;
	 *  2. #publish only when it is « Update » (WordPress names it `save`
	 *     then; `publish` when it would publish, schedule or submit);
	 *  3. The first visible .button-primary[type=submit] outside the post
	 *     editor (Settings pages, profile, terms).
	 */
	function findSubmitButton() {
		var editor = document.getElementById( 'post' ) && document.getElementById( 'submitdiv' );
		var selectors = editor ? [
			'#save-post:not(:disabled)',
			'#publish[name="save"]:not(:disabled)'
		] : [
			'form input[type="submit"].button-primary:not(:disabled)',
			'form button[type="submit"].button-primary:not(:disabled)'
		];
		for ( var i = 0; i < selectors.length; i++ ) {
			var el = document.querySelector( selectors[ i ] );
			if ( el && el.offsetParent !== null ) return el;
		}
		return null;
	}

	function triggerSave() {
		var button = findSubmitButton();
		if ( ! button ) return false;
		// For <input type="submit"> elements, WP uses .click() which submits the parent form.
		button.click();
		return true;
	}

	function handleKeydown( event ) {
		if ( ! isSaveShortcut( event ) ) return;
		if ( isInBlockEditor() ) return;
		// Always call preventDefault as soon as we have a candidate — prevents the
		// browser's "Save page" dialog. If no button is found, let the browser handle it
		// (probably nothing useful, but no reason to block it).
		var button = findSubmitButton();
		if ( ! button ) return;
		event.preventDefault();
		event.stopPropagation();
		button.click();
	}

	// Capture phase to pre-empt any admin handlers that call
	// stopPropagation on S (rare but it happens).
	document.addEventListener( 'keydown', handleKeydown, true );

	/**
	 * TinyMCE — when the cursor is inside the TinyMCE iframe, keydown events
	 * stay within the iframe's contentDocument. We register the shortcut via
	 * the native API, which broadcasts it to all current and future editors.
	 */
	function bindTinymce() {
		if ( typeof window.tinymce === 'undefined' ) return;

		var registerOn = function ( editor ) {
			if ( ! editor || typeof editor.addShortcut !== 'function' ) return;
			// "meta+s" covers Cmd+S on Mac and Ctrl+S on PC per the TinyMCE convention.
			editor.addShortcut( 'meta+s,ctrl+s', 'Enregistrer le contenu', function () {
				triggerSave();
			} );
		};

		// Editors already initialised by the time the script runs.
		if ( window.tinymce.editors && window.tinymce.editors.length ) {
			for ( var i = 0; i < window.tinymce.editors.length; i++ ) {
				registerOn( window.tinymce.editors[ i ] );
			}
		}

		// Editors added later (Classic Editor initialises on DOM ready).
		if ( typeof window.tinymce.on === 'function' ) {
			window.tinymce.on( 'AddEditor', function ( event ) {
				registerOn( event && event.editor );
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bindTinymce );
	} else {
		bindTinymce();
	}
} )();

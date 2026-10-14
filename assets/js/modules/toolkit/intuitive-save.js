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
	 * The block editor has its own native shortcut. Three possible signals —
	 * we return true as soon as any one of them matches.
	 */
	function isInBlockEditor() {
		if ( document.body && document.body.classList ) {
			if ( document.body.classList.contains( 'block-editor-page' ) ) return true;
			if ( document.body.classList.contains( 'wp-admin-block-editor' ) ) return true;
		}
		if ( window.wp && window.wp.data && typeof window.wp.data.select === 'function' ) {
			try {
				if ( window.wp.data.select( 'core/editor' ) ) return true;
			} catch ( e ) {
				// wp.data may throw if the store is not registered — ignored.
			}
		}
		return false;
	}

	/**
	 * Finds the button to click. Priority order:
	 *  1. #publish (Classic Editor — "Publish"/"Update" button)
	 *  2. #save-post (Classic Editor — "Save Draft" button)
	 *  3. The first visible .button-primary[type=submit] (Settings pages, etc.)
	 *  4. The first button[type=submit] inside a form (broad fallback)
	 */
	function findSubmitButton() {
		var selectors = [
			'#publish:not(:disabled)',
			'#save-post:not(:disabled)',
			'form input[type="submit"].button-primary:not(:disabled)',
			'form button[type="submit"].button-primary:not(:disabled)'
		];
		for ( var i = 0; i < selectors.length; i++ ) {
			var el = document.querySelector( selectors[ i ] );
			if ( el ) return el;
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

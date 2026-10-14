/**
 * Double Youpi — Toolkit / Local Avatars
 *
 * WordPress media frame for the user profile avatar field.
 * Opens the media library, restricts selection to images, stores the attachment
 * ID in the hidden field, and updates the preview. No external network calls.
 */
( function ( $ ) {
	'use strict';

	var l10n = window.doubleyoupiLocalAvatars || { title: 'Select an avatar', button: 'Use as avatar' };

	$( function () {
		var $root = $( '.doubleyoupi-local-avatar' );
		if ( ! $root.length || ! window.wp || ! window.wp.media ) {
			return;
		}

		var $preview = $root.find( '.doubleyoupi-local-avatar__preview' );
		var $field   = $root.find( '.doubleyoupi-local-avatar__id' );
		var $remove  = $root.find( '.doubleyoupi-local-avatar__remove' );
		var frame    = null;

		$root.on( 'click', '.doubleyoupi-local-avatar__choose', function ( e ) {
			e.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = window.wp.media( {
				title: l10n.title,
				button: { text: l10n.button },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.url;
				if ( attachment.sizes && attachment.sizes.thumbnail ) {
					url = attachment.sizes.thumbnail.url;
				}
				$field.val( attachment.id );
				$preview.attr( 'src', url ).show();
				$remove.show();
			} );

			frame.open();
		} );

		$remove.on( 'click', function ( e ) {
			e.preventDefault();
			$field.val( '' );
			$preview.attr( 'src', '' ).hide();
			$( this ).hide();
		} );
	} );
} )( jQuery );

jQuery( function( $ ) {
	var feedbackTimer, dirtySaveState = false;

	function activateSpinner() {
		$( '#wpcom-thumbnail-actions .spinner' ).addClass( 'is-active' );
	}

	function deactivateSpinner() {
		$( '#wpcom-thumbnail-actions .spinner' ).removeClass( 'is-active' );
	}

	function giveUserFeedback( message, type, autoHide ) {
		autoHide = !! autoHide;
		clearTimeout( feedbackTimer );

		$( '#wpcom-thumbnail-feedback' )
			.removeClass( 'success error' )
			.addClass( type ? type : '' )
			.text( message )
			.fadeIn( 'fast' );

		if ( autoHide ) {
			// Hide the message after 10 seconds.
			feedbackTimer = setTimeout( hideUserFeedback, 10000 );
		}
	}

	function hideUserFeedback() {
		$( '#wpcom-thumbnail-feedback' ).fadeOut();
	}

	function updatePreview( selection, thumbnailDimensions ) {
		// This is how big the selection image is
		var imgWidth  = window.wpcomThumbnailEditor.imgWidth;
		var imgHeight = window.wpcomThumbnailEditor.imgHeight;

		var scaleX = thumbnailDimensions[0] / ( selection.width || 1 );
		var scaleY = thumbnailDimensions[1] / ( selection.height || 1 );

		// Update the preview image
		$( '#wpcom-thumbnail-edit-preview' ).css( {
			width: Math.round( scaleX * imgWidth ) + 'px',
			height: Math.round( scaleY * imgHeight ) + 'px',
			marginLeft: '-' + Math.round( scaleX * selection.x1 ) + 'px',
			marginTop: '-' + Math.round( scaleY * selection.y1 ) + 'px'
		});
	}

	function buildImgAreaSelect( ratio, initialSelection ) {
		var thumbnailDimensions = ratio.split( ':' );

		if ( !! $( '#wpcom-thumbnail-edit' ).data('imgAreaSelect') ) {
			// Remove and reinit.
			$( '#wpcom-thumbnail-edit' ).imgAreaSelect( { remove: true } );
		}

		$( '#wpcom-thumbnail-edit' ).imgAreaSelect( {
			aspectRatio: ratio,
			handles: true,

			// Initial selection
			x1: initialSelection[0],
			y1: initialSelection[1],
			x2: initialSelection[2],
			y2: initialSelection[3],

			// Update the preview
			onInit: function( img, selection ) {
				updatePreview( selection, thumbnailDimensions );
			},
			onSelectChange: function( img, selection ) {
				updatePreview( selection, thumbnailDimensions );
			},

			// Fill the hidden fields with the selected coordinates for the form
			onSelectEnd: function( img, selection ) {
				$( '#wpcom_thumbnail_edit_x1' ).val( selection.x1 );
				$( '#wpcom_thumbnail_edit_y1' ).val( selection.y1 );
				$( '#wpcom_thumbnail_edit_x2' ).val( selection.x2 );
				$( '#wpcom_thumbnail_edit_y2' ).val( selection.y2 );
				dirtySaveState = true;
				console.log( 'onSelectEnd' );
			}
		});
	}

	function confirmDiscardUnsavedData( e ) {
		if ( dirtySaveState ) {
			if ( e ) {
				e.returnValue = window.wpcomThumbnailEditor.unloadConfirmation;
				return window.wpcomThumbnailEditor.unloadConfirmation;
			} else {
				return window.confirm( window.wpcomThumbnailEditor.unloadConfirmation );
			}
		}
		return true;
	}

	$( '.wpcom-thumbnail-crop-activate' ).click( function( e ) {
		e.preventDefault();

		if ( ! confirmDiscardUnsavedData() ) {
			return;
		}

		var ratio = $( this ).data( 'ratio' ),
			selection = $( this ).data( 'selection' ).split( ',' ),
			thumbnailDimensions = ratio.split( ':' );

		$( 'html, body' ).animate({
			scrollTop: $( $( this ).attr( 'href' ) ).offset().top - 50
		}, 750);

		buildImgAreaSelect( ratio, selection );
		$( '.wpcom-thumbnail-save' ).prop( 'disabled', false );

		$( '#wpcom-thumbnail-edit-preview-mask' ).css( {
			width: thumbnailDimensions[0] + 'px',
			height: thumbnailDimensions[1] + 'px'
		});

		$( '#wpcom-thumbnail-edit-preview' ).show();
		$( '#wpcom-thumbnail-edit-preview-container' ).fadeIn();

		$( '#wpcom-thumbnail-size' ).val( $( this ).data( 'size' ) );
	});

	$( '.wpcom-thumbnail-save' ).click( function( e ) {
		e.preventDefault();
		activateSpinner();
		giveUserFeedback( window.wpcomThumbnailEditor.savingMessage );
		var formData = $( this ).closest( 'form' ).serialize();
		if ( 'wpcom_thumbnail_edit_reset' === $( this ).attr( 'name' ) ) {
			formData += '&wpcom_thumbnail_edit_reset=true';
		}
		$.post( wpcomThumbnailEditor.ajaxUrl, formData )
			.done( function( response ) {
				dirtySaveState = false;
				var $thumb = $( '#wpcom-thumbnail-size-' + response.data.size );
				if ( $thumb.length ) {
					$thumb.data( 'selection', response.data.selection );
					$( 'img', $thumb ).attr( 'src', response.data.thumbnail );
					$thumb.click();
				}
				giveUserFeedback( response.data.message, 'success', true );
			})
			.fail( function( jqXHR, textStatus, errorThrown ) {
				giveUserFeedback( 'Error! ' + textStatus, 'error', true );
				if ( console && console.log ) {
					console.log( jqXHR );
					console.log( textStatus );
					console.log( errorThrown );
				}
			})
			.always( deactivateSpinner );
	});

	$( '#wpcom-thumbnail-cancel' ).click( function( e ) {
		$( '#wpcom-thumbnail-edit' ).imgAreaSelect( { remove: true } );
		$( '#wpcom_thumbnail_edit_x1, #wpcom_thumbnail_edit_y1, #wpcom_thumbnail_edit_x2, #wpcom_thumbnail_edit_y2, #wpcom-thumbnail-size' ).val( '' );
		$( '#wpcom-thumbnail-edit-preview-container' ).fadeOut();
		$( '.wpcom-thumbnail-save' ).prop( 'disabled', true );
		dirtySaveState = false;
	});

	window.addEventListener( 'beforeunload', confirmDiscardUnsavedData );
});
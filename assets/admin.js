( function () {
	'use strict';

	var form = document.querySelector( '[data-ktss-form]' );

	if ( ! form ) {
		return;
	}

	var ACTIVE = 'kadence-blocks-style-library__nav-item-button--active';
	var referer = form.querySelector( 'input[name="_wp_http_referer"]' );

	function show( id ) {
		form.querySelectorAll( '[data-ktss-screen]' ).forEach( function ( screen ) {
			screen.hidden = screen.getAttribute( 'data-ktss-screen' ) !== id;
		} );

		form.querySelectorAll( '[data-ktss-nav]' ).forEach( function ( button ) {
			button.classList.toggle( ACTIVE, button.getAttribute( 'data-ktss-nav' ) === id );
		} );

		var url = new URL( window.location.href );
		url.searchParams.set( 'ktss-screen', id );
		url.searchParams.delete( 'settings-updated' );
		window.history.replaceState( null, '', url.toString() );

		// options.php redirects back to the referer: keep the user on this screen after saving.
		if ( referer ) {
			referer.value = url.pathname + url.search;
		}
	}

	form.querySelectorAll( '[data-ktss-nav]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			show( button.getAttribute( 'data-ktss-nav' ) );
		} );
	} );

	form.querySelectorAll( '[data-ktss-toggle]' ).forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			input.parentNode.classList.toggle( 'is-checked', input.checked );
		} );
	} );

	function markChanged( select ) {
		select.classList.toggle( 'is-changed', select.value !== select.getAttribute( 'data-initial' ) );
	}

	form.querySelectorAll( '[data-ktss-slot]' ).forEach( function ( select ) {
		select.setAttribute( 'data-initial', select.value );
		select.addEventListener( 'change', function () {
			markChanged( select );
		} );
	} );

	form.querySelectorAll( '[data-ktss-preset]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var map = JSON.parse( button.getAttribute( 'data-ktss-preset' ) );

			form.querySelectorAll( '[data-ktss-slot]' ).forEach( function ( select ) {
				var value = map[ select.getAttribute( 'data-ktss-slot' ) ] || '';

				if ( select.querySelector( 'option[value="' + value + '"]' ) ) {
					select.value = value;
					markChanged( select );
				}
			} );
		} );
	} );

	var saved = form.querySelector( '[data-ktss-saved]' );

	if ( saved ) {
		window.setTimeout( function () {
			saved.classList.add( 'is-hidden' );
		}, 4000 );
	}

	var current = form.querySelector( '[data-ktss-screen]:not([hidden])' );

	if ( current ) {
		show( current.getAttribute( 'data-ktss-screen' ) );
	}
} )();

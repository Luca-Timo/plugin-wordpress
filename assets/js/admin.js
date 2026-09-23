/**
 * The import screen.
 *
 * Plain DOM on purpose: this is one screen, and pulling in a framework to
 * render a grid would be a dependency the plugin otherwise does not need.
 *
 * Every request goes to this site's own picpeak/v1 namespace, never to
 * PicPeak — the token lives server side and the browser never sees it.
 */
( function () {
	'use strict';

	var data = window.picpeakData;
	if ( ! data ) { return; }
	var t = data.i18n;

	var state = {
		events: [],
		event: null,
		photos: [],
		selected: {},
		filters: { marked_only: '', color_labels: '', min_rating: '' },
		importing: false,
		stop: false
	};

	var app = document.getElementById( 'picpeak-app' );

	function api( path, options ) {
		options = options || {};
		options.headers = Object.assign( { 'X-WP-Nonce': data.nonce }, options.headers || {} );
		return fetch( data.root + path, options ).then( function ( r ) {
			return r.json().then( function ( body ) {
				if ( ! r.ok ) {
					throw new Error( ( body && body.message ) || 'Request failed' );
				}
				return body;
			} );
		} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'class' ) { node.className = attrs[ k ]; }
			else if ( k === 'text' ) { node.textContent = attrs[ k ]; }
			else if ( k.slice( 0, 2 ) === 'on' ) { node.addEventListener( k.slice( 2 ), attrs[ k ] ); }
			else if ( attrs[ k ] !== null && attrs[ k ] !== undefined ) { node.setAttribute( k, attrs[ k ] ); }
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c ) { node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c ); }
		} );
		return node;
	}

	function sprintf( template, values ) {
		var i = 0;
		return String( template )
			.replace( /%(\d+)\$d/g, function ( _, n ) { return values[ n - 1 ]; } )
			.replace( /%d/g, function () { return values[ i++ ]; } );
	}

	function clear( node ) { while ( node.firstChild ) { node.removeChild( node.firstChild ); } }

	function selectedIds() { return Object.keys( state.selected ).filter( function ( id ) { return state.selected[ id ]; } ); }

	/* ---------------------------------------------------------------- render */

	function render() {
		clear( app );
		app.appendChild( renderGalleryPicker() );
		if ( state.event ) {
			app.appendChild( renderToolbar() );
			app.appendChild( renderGrid() );
			app.appendChild( renderFooter() );
		}
	}

	function renderGalleryPicker() {
		var select = el( 'select', {
			id: 'picpeak-event',
			onchange: function ( e ) {
				var id = parseInt( e.target.value, 10 );
				state.event = state.events.filter( function ( ev ) { return ev.id === id; } )[ 0 ] || null;
				state.photos = [];
				state.selected = {};
				render();
				if ( state.event ) { loadPhotos(); }
			}
		}, [ el( 'option', { value: '', text: '—' } ) ] );

		state.events.forEach( function ( ev ) {
			var opt = el( 'option', { value: ev.id, text: ev.event_name || ev.slug } );
			if ( state.event && state.event.id === ev.id ) { opt.selected = true; }
			select.appendChild( opt );
		} );

		return el( 'p', { class: 'picpeak-row' }, [
			el( 'label', { for: 'picpeak-event', text: 'Gallery' } ),
			select
		] );
	}

	function renderToolbar() {
		function filter( key, label, options ) {
			var sel = el( 'select', {
				onchange: function ( e ) { state.filters[ key ] = e.target.value; loadPhotos(); }
			} );
			options.forEach( function ( o ) {
				var opt = el( 'option', { value: o.value, text: o.label } );
				if ( state.filters[ key ] === o.value ) { opt.selected = true; }
				sel.appendChild( opt );
			} );
			return el( 'span', { class: 'picpeak-filter' }, [ el( 'label', { text: label } ), sel ] );
		}

		return el( 'div', { class: 'picpeak-toolbar' }, [
			filter( 'marked_only', 'Show', [
				{ value: '', label: 'All photos' },
				{ value: 'true', label: 'Marked by the client' }
			] ),
			filter( 'color_labels', 'Colour', [
				{ value: '', label: 'Any' },
				{ value: 'green', label: 'Green' },
				{ value: 'yellow', label: 'Yellow' },
				{ value: 'red', label: 'Red' },
				{ value: 'blue', label: 'Blue' },
				{ value: 'purple', label: 'Purple' }
			] ),
			filter( 'min_rating', 'Rating', [
				{ value: '', label: 'Any' },
				{ value: '3', label: '3 stars and up' },
				{ value: '4', label: '4 stars and up' },
				{ value: '5', label: '5 stars' }
			] ),
			el( 'span', { class: 'picpeak-spacer' } ),
			el( 'button', {
				type: 'button', class: 'button',
				text: t.selectAll,
				onclick: function () {
					state.photos.forEach( function ( p ) {
						if ( p.media_type !== 'video' ) { state.selected[ p.id ] = true; }
					} );
					render();
				}
			} ),
			el( 'button', {
				type: 'button', class: 'button',
				text: t.selectNone,
				onclick: function () { state.selected = {}; render(); }
			} )
		] );
	}

	function renderGrid() {
		if ( ! state.photos.length ) {
			return el( 'p', { class: 'picpeak-empty', text: t.noPhotos } );
		}

		var wrapper = el( 'div' );
		if ( state.truncated ) {
			wrapper.appendChild( el( 'div', { class: 'notice notice-info inline' }, [
				el( 'p', { text: sprintf( t.truncated, [ state.photos.length ] ) } )
			] ) );
		}

		var grid = el( 'div', { class: 'picpeak-grid' } );

		state.photos.forEach( function ( photo ) {
			var isVideo = photo.media_type === 'video';
			var busy = photo.processing_status && photo.processing_status !== 'complete';
			var selectable = ! isVideo && ! busy;

			var tile = el( 'label', {
				class: 'picpeak-tile' + ( state.selected[ photo.id ] ? ' is-selected' : '' ) +
					( selectable ? '' : ' is-disabled' )
			} );

			var box = el( 'input', { type: 'checkbox' } );
			box.checked = !! state.selected[ photo.id ];
			box.disabled = ! selectable;
			box.addEventListener( 'change', function () {
				state.selected[ photo.id ] = box.checked;
				tile.classList.toggle( 'is-selected', box.checked );
				updateCount();
			} );
			tile.appendChild( box );

			if ( isVideo || busy ) {
				tile.appendChild( el( 'span', {
					class: 'picpeak-placeholder',
					text: busy ? t.processing : t.video
				} ) );
			} else {
				var img = el( 'img', {
					loading: 'lazy',
					alt: photo.source_filename || '',
					// An <img> cannot send the X-WP-Nonce header, and WordPress
					// treats a cookie-authenticated REST request without a nonce
					// as logged out — so the capability check would refuse every
					// preview. rest_cookie_check_errors accepts _wpnonce in the
					// query string, which is the only form an <img> can carry.
					src: data.root + '/events/' + state.event.id + '/preview/' + photo.id +
						'?w=640&_wpnonce=' + encodeURIComponent( data.nonce )
				} );
				// PicPeak answers 404 for anything with no preview tier; a
				// placeholder is the honest rendering, not a broken image.
				img.addEventListener( 'error', function () {
					if ( img.parentNode ) {
						img.parentNode.replaceChild(
							el( 'span', { class: 'picpeak-placeholder', text: t.noPreview } ),
							img
						);
					}
				} );
				tile.appendChild( img );
			}

			tile.appendChild( el( 'span', {
				class: 'picpeak-name',
				text: photo.source_filename || photo.filename || String( photo.id )
			} ) );

			grid.appendChild( tile );
		} );

		wrapper.appendChild( grid );
		return wrapper;
	}

	function renderFooter() {
		// Sizes are expressed as the longest edge, which is how an export
		// dialog puts it and how photographers think about it. PicPeak takes a
		// BOX and fits the photo inside it with the aspect ratio kept, so a
		// square box of NxN is exactly "no edge longer than N" — a 3000x2000
		// landscape at 2048 comes back 2048x1365, not squashed to a square.
		var custom = el( 'input', {
			type: 'number', id: 'picpeak-custom-size', class: 'small-text',
			min: '1', max: '99999', step: '1', value: '2048',
			'aria-label': t.longestEdge
		} );
		var customWrap = el( 'span', { class: 'picpeak-custom' }, [
			custom,
			el( 'span', { text: ' px' } )
		] );
		customWrap.hidden = true;

		var resolution = el( 'select', {
			id: 'picpeak-resolution',
			onchange: function () { customWrap.hidden = resolution.value !== 'custom'; }
		}, [
			el( 'option', { value: '2048x2048', text: t.size2048 } ),
			el( 'option', { value: '1600x1600', text: t.size1600 } ),
			el( 'option', { value: '1200x1200', text: t.size1200 } ),
			el( 'option', { value: 'custom', text: t.sizeCustom } ),
			el( 'option', { value: 'original', text: t.sizeOriginal } )
		] );

		// What actually goes to the API: a preset id, or the custom edge as a
		// square box. Anything out of range falls back to the default rather
		// than sending a value the API will refuse.
		function chosenResolution() {
			if ( resolution.value !== 'custom' ) { return resolution.value; }
			var n = parseInt( custom.value, 10 );
			if ( ! ( n > 0 ) || n > 99999 ) { return '2048x2048'; }
			return n + 'x' + n;
		}

		var watermark = el( 'input', { type: 'checkbox', id: 'picpeak-watermark' } );
		var replace = el( 'input', { type: 'checkbox', id: 'picpeak-replace' } );

		var count = el( 'span', { id: 'picpeak-count', class: 'picpeak-count' } );
		var status = el( 'span', { id: 'picpeak-status', class: 'picpeak-status', role: 'status' } );

		var button = el( 'button', {
			type: 'button', class: 'button button-primary', id: 'picpeak-import',
			text: t.import,
			onclick: function () {
				runImport( {
					resolution: chosenResolution(),
					watermark: watermark.checked,
					on_duplicate: replace.checked ? 'replace' : 'skip'
				} );
			}
		} );

		var footer = el( 'div', { class: 'picpeak-footer' }, [
			el( 'span', { class: 'picpeak-filter' }, [
				el( 'label', { for: 'picpeak-resolution', text: t.size } ),
				resolution,
				customWrap
			] ),
			el( 'label', { class: 'picpeak-check' }, [ watermark, document.createTextNode( ' Apply the gallery watermark' ) ] ),
			el( 'label', { class: 'picpeak-check' }, [ replace, document.createTextNode( ' Re-import photos already here' ) ] ),
			el( 'span', { class: 'picpeak-spacer' } ),
			count,
			button,
			status
		] );

		if ( data.folder ) {
			footer.appendChild( el( 'p', {
				class: 'picpeak-note',
				text: 'Imports will also be placed in a ' + data.folder + ' folder named after the gallery.'
			} ) );
		}

		setTimeout( updateCount, 0 );
		return footer;
	}

	function updateCount() {
		var node = document.getElementById( 'picpeak-count' );
		var button = document.getElementById( 'picpeak-import' );
		var n = selectedIds().length;
		if ( node ) { node.textContent = sprintf( t.selected, [ n ] ); }
		if ( button ) { button.disabled = n === 0 || state.importing; }
	}

	/* ----------------------------------------------------------------- data */

	function loadEvents() {
		api( '/events?limit=100' ).then( function ( body ) {
			state.events = body.events || [];
			if ( ! state.events.length ) {
				clear( app );
				app.appendChild( el( 'p', { text: t.noGalleries } ) );
				return;
			}
			render();
		} ).catch( fail );
	}

	/**
	 * Loads every page of the selected gallery.
	 *
	 * 100 is the API's own maximum page size, and a wedding gallery is
	 * routinely several hundred photos, so a single page would silently show a
	 * fraction of the shoot and "select all" would quietly mean "select the
	 * first hundred". PAGE_CAP stops a pathological gallery from making this
	 * screen unusable; when it bites, the count says so rather than pretending
	 * the gallery ends there.
	 */
	var PAGE_CAP = 20;

	function loadPhotos() {
		if ( ! state.event ) { return; }

		var eventId = state.event.id;
		var collected = [];
		state.photos = [];
		state.selected = {};

		clear( app );
		app.appendChild( renderGalleryPicker() );
		app.appendChild( el( 'p', { class: 'picpeak-empty', text: t.loading } ) );

		function query( page ) {
			var parts = [ 'limit=100', 'page=' + page ];
			Object.keys( state.filters ).forEach( function ( k ) {
				if ( state.filters[ k ] ) { parts.push( k + '=' + encodeURIComponent( state.filters[ k ] ) ); }
			} );
			return parts.join( '&' );
		}

		function page( n ) {
			return api( '/events/' + eventId + '/photos?' + query( n ) ).then( function ( body ) {
				// The gallery changed under us while paging; drop the result.
				if ( ! state.event || state.event.id !== eventId ) { return; }

				collected = collected.concat( body.photos || [] );
				var pages = ( body.pagination && body.pagination.pages ) || 1;

				if ( n < pages && n < PAGE_CAP ) { return page( n + 1 ); }

				state.photos = collected;
				state.truncated = pages > PAGE_CAP;
				render();
			} );
		}

		page( 1 ).catch( fail );
	}

	function fail( error ) {
		clear( app );
		app.appendChild( el( 'div', { class: 'notice notice-error' }, [
			el( 'p', { text: error.message } )
		] ) );
	}

	/* --------------------------------------------------------------- import */

	function runImport( options ) {
		var ids = selectedIds().map( Number );
		var queue = state.photos.filter( function ( p ) { return ids.indexOf( p.id ) !== -1; } );
		var total = queue.length;
		if ( ! total ) { return; }

		state.importing = true;
		state.stop = false;
		updateCount();

		var status = document.getElementById( 'picpeak-status' );
		var button = document.getElementById( 'picpeak-import' );
		button.textContent = t.importing;

		var stopButton = el( 'button', {
			type: 'button', class: 'button picpeak-stop', text: t.stop,
			onclick: function () { state.stop = true; }
		} );
		button.parentNode.insertBefore( stopButton, button.nextSibling );

		var done = 0;
		var tally = { imported: 0, skipped: 0, replaced: 0, failed: 0 };
		var failures = [];
		var folderFailures = 0;

		function finish() {
			state.importing = false;
			button.textContent = t.import;
			if ( stopButton.parentNode ) { stopButton.parentNode.removeChild( stopButton ); }
			updateCount();

			var parts = [];
			[ 'imported', 'replaced', 'skipped', 'failed' ].forEach( function ( k ) {
				if ( tally[ k ] ) { parts.push( tally[ k ] + ' ' + t[ k ] ); }
			} );
			status.textContent = t.done + ' ' + parts.join( ', ' ) + '.';

			// A folder plugin is active but did not take the attachment. The
			// import itself is fine, so this is a notice rather than a failure
			// — but it is said out loud, because the alternative is a folder
			// tree that silently never fills up.
			if ( folderFailures ) {
				app.appendChild( el( 'div', { class: 'notice notice-warning' }, [
					el( 'p', { text: sprintf( t.folderFailed, [ folderFailures, data.folder || '' ] ) } )
				] ) );
			}

			if ( failures.length ) {
				app.appendChild( el( 'div', { class: 'notice notice-warning picpeak-failures' }, [
					el( 'p', { text: 'These did not import:' } ),
					el( 'ul', {}, failures.map( function ( f ) {
						return el( 'li', { text: f } );
					} ) )
				] ) );
			}

			app.appendChild( el( 'p', {}, [
				el( 'a', { href: data.library, text: t.viewLibrary } )
			] ) );
		}

		function step() {
			if ( state.stop || ! queue.length ) { return finish(); }

			var batch = queue.splice( 0, data.batch );
			api( '/import', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					event: { id: state.event.id, slug: state.event.slug, event_name: state.event.event_name },
					photos: batch,
					options: options
				} )
			} ).then( function ( body ) {
				// The server stops a batch on a 429 rather than collecting more
				// of the same; the un-imported remainder goes back on the queue.
				if ( body.rate_limited ) {
					var wait = body.retry_after || 30;
					var handled = ( body.results || [] ).length;
					queue = batch.slice( handled ).concat( queue );
					status.textContent = sprintf( t.rateLimited, [ wait ] );
					( body.results || [] ).forEach( record );
					setTimeout( step, wait * 1000 );
					return;
				}

				( body.results || [] ).forEach( record );
				status.textContent = sprintf( t.progress, [ done, total ] );
				step();
			} ).catch( function ( error ) {
				tally.failed += batch.length;
				failures.push( error.message );
				done += batch.length;
				finish();
			} );
		}

		function record( result ) {
			done++;
			tally[ result.status ] = ( tally[ result.status ] || 0 ) + 1;
			if ( result.folder === 'failed' ) { folderFailures++; }
			if ( result.status === 'failed' ) {
				failures.push( ( result.title || result.photo_id ) + ': ' + result.message );
			}
			status.textContent = sprintf( t.progress, [ done, total ] );
		}

		step();
	}

	loadEvents();
}() );

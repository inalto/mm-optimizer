/* MM Optimizer - amministrazione */
( function () {
	'use strict';

	function post( action, onDone ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', window.mmOptimizer.nonce );

		return fetch( window.mmOptimizer.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( response ) { return response.json(); } )
			.then( onDone )
			.catch( function () { window.alert( window.mmOptimizer.i18n.failed ); } );
	}

	function busy( button, label ) {
		button.disabled = true;
		button.dataset.originalLabel = button.textContent;
		button.textContent = label;
	}

	function bind( id, confirmFirst, busyLabel, action ) {
		var button = document.getElementById( id );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			if ( confirmFirst && ! window.confirm( window.mmOptimizer.i18n.confirm ) ) {
				return;
			}

			busy( button, busyLabel );

			post( action, function ( result ) {
				if ( result && result.success ) {
					window.location.reload();
					return;
				}

				button.disabled = false;
				button.textContent = button.dataset.originalLabel;
				window.alert( ( result && result.data && result.data.message ) || window.mmOptimizer.i18n.failed );
			} );
		} );
	}

	var bulk = { running: false };

	function setStatus( text ) {
		var el = document.getElementById( 'mm-opt-bulk-status' );
		if ( el ) {
			el.textContent = text;
		}
	}

	function setBar( done, total ) {
		var bar = document.getElementById( 'mm-opt-bar' );
		if ( bar && total > 0 ) {
			bar.style.width = Math.min( 100, Math.round( ( done / total ) * 100 ) ) + '%';
		}
	}

	function postData( action, extra ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', window.mmOptimizer.nonce );
		Object.keys( extra || {} ).forEach( function ( key ) {
			body.append( key, extra[ key ] );
		} );

		return fetch( window.mmOptimizer.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	function scanStep( first ) {
		if ( ! bulk.running ) {
			return;
		}

		setStatus( window.mmOptimizer.i18n.scanning );

		postData( 'mm_optimizer_bulk_scan', first ? { restart: 1 } : {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				return stopBulk( window.mmOptimizer.i18n.failed );
			}

			if ( res.data.finished ) {
				bulk.total = res.data.remaining;
				return tick();
			}

			scanStep( false );
		} ).catch( function () { stopBulk( window.mmOptimizer.i18n.failed ); } );
	}

	function tick() {
		if ( ! bulk.running ) {
			return;
		}

		postData( 'mm_optimizer_bulk_tick', {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				return stopBulk( window.mmOptimizer.i18n.failed );
			}

			var d = res.data;

			if ( ! bulk.total ) {
				bulk.total = d.remaining + d.done + d.skipped;
			}

			setBar( bulk.total - d.remaining, bulk.total );
			setStatus(
				window.mmOptimizer.i18n.progress
					.replace( '%1$s', d.done )
					.replace( '%2$s', d.skipped )
					.replace( '%3$s', d.errors )
					.replace( '%4$s', d.saved )
					.replace( '%5$s', d.remaining )
			);

			if ( d.remaining > 0 ) {
				window.setTimeout( tick, d.locked ? 3000 : 200 );
				return;
			}

			stopBulk( window.mmOptimizer.i18n.finished );
		} ).catch( function () { stopBulk( window.mmOptimizer.i18n.failed ); } );
	}

	function stopBulk( message ) {
		bulk.running = false;
		setStatus( message );

		var start = document.getElementById( 'mm-opt-bulk-start' );
		var stop = document.getElementById( 'mm-opt-bulk-stop' );

		if ( start ) { start.disabled = false; }
		if ( stop ) { stop.disabled = true; }
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var start = document.getElementById( 'mm-opt-bulk-start' );
		var stop = document.getElementById( 'mm-opt-bulk-stop' );

		if ( start ) {
			start.addEventListener( 'click', function () {
				bulk.running = true;
				bulk.total = 0;
				start.disabled = true;
				if ( stop ) { stop.disabled = false; }
				scanStep( true );
			} );
		}

		if ( stop ) {
			stop.addEventListener( 'click', function () {
				postData( 'mm_optimizer_bulk_stop', {} ).then( function () {
					stopBulk( window.mmOptimizer.i18n.stopped );
				} );
			} );
		}

		bind( 'mm-opt-detect', false, window.mmOptimizer.i18n.detecting, 'mm_optimizer_detect' );
		bind( 'mm-opt-apply', true, window.mmOptimizer.i18n.applying, 'mm_optimizer_apply_recommendation' );

		var importBtn = document.getElementById( 'mm-opt-import-settings' );
		var adoptBtn = document.getElementById( 'mm-opt-adopt' );

		function migrateStatus( text ) {
			var el = document.getElementById( 'mm-opt-migrate-status' );
			if ( el ) { el.textContent = text; }
		}

		if ( importBtn ) {
			importBtn.addEventListener( 'click', function () {
				importBtn.disabled = true;
				postData( 'mm_optimizer_import_settings', {} ).then( function ( res ) {
					migrateStatus( ( res && res.data && res.data.message ) || window.mmOptimizer.i18n.failed );
					importBtn.disabled = false;
				} );
			} );
		}

		if ( adoptBtn ) {
			adoptBtn.addEventListener( 'click', function adopt() {
				adoptBtn.disabled = true;
				postData( 'mm_optimizer_adopt', {} ).then( function ( res ) {
					if ( ! res || ! res.success ) {
						migrateStatus( window.mmOptimizer.i18n.failed );
						adoptBtn.disabled = false;
						return;
					}
					migrateStatus( res.data.message );
					if ( ! res.data.finished ) {
						window.setTimeout( adopt, 200 );
						return;
					}
					adoptBtn.disabled = false;
				} );
			} );
		}

		document.querySelectorAll( '.mm-opt-preset input[type="radio"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				document.querySelectorAll( '.mm-opt-preset' ).forEach( function ( card ) {
					card.classList.remove( 'is-active' );
				} );
				input.closest( '.mm-opt-preset' ).classList.add( 'is-active' );
			} );
		} );
	} );
}() );

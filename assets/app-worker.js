/**
 * NovaWork app worker (installable app, 1.1.0).
 *
 * Served by the site at ?novawork=app-worker, which puts the workspace's
 * settings above this file as `NOVAWORK` ({ pages, offline }).
 *
 * It does one thing: when the workspace or its sign-in page is opened
 * without a connection, it shows a short "You're offline" page instead of
 * the browser's error. It caches nothing, and every other request (the
 * API, files, the rest of the site) goes to the network untouched.
 */
/* eslint-env serviceworker */
/* global NOVAWORK */

self.addEventListener( 'install', () => self.skipWaiting() );
self.addEventListener( 'activate', ( event ) => event.waitUntil( self.clients.claim() ) );

const strip = ( path ) => path.replace( /\/+$/, '' );

/** Whether a URL is one of the workspace's pages (any extra query is fine: ?action=lost). */
function isPage( url ) {
	return NOVAWORK.pages.some( ( page ) => {
		const p = new URL( page );
		if ( p.origin !== url.origin || strip( p.pathname ) !== strip( url.pathname ) ) {
			return false;
		}
		for ( const [ key, value ] of p.searchParams ) {
			if ( url.searchParams.get( key ) !== value ) {
				return false;
			}
		}
		return true;
	} );
}

self.addEventListener( 'fetch', ( event ) => {
	const req = event.request;
	if ( req.mode !== 'navigate' || req.method !== 'GET' || ! isPage( new URL( req.url ) ) ) {
		return;
	}
	event.respondWith(
		fetch( req ).catch(
			() =>
				new Response( NOVAWORK.offline, {
					headers: { 'Content-Type': 'text/html; charset=utf-8' },
				} )
		)
	);
} );

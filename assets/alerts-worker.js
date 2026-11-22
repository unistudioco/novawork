/**
 * NovaWork desktop alerts.
 *
 * The workspace registers this worker only to show its alerts: Android
 * browsers show notifications through a service worker and nothing else.
 * It caches nothing and never touches network requests (no fetch handler).
 * Clicking an alert brings the workspace tab forward and tells it which
 * notification to open; with no tab open it opens the workspace.
 */
/* eslint-env serviceworker */

self.addEventListener( 'install', () => self.skipWaiting() );
self.addEventListener( 'activate', ( event ) => event.waitUntil( self.clients.claim() ) );

self.addEventListener( 'notificationclick', ( event ) => {
	const data = event.notification.data || {};
	event.notification.close();
	event.waitUntil(
		self.clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( ( list ) => {
			const app = data.appUrl ? list.find( ( c ) => c.url.indexOf( data.appUrl ) === 0 ) : null;
			if ( app ) {
				app.postMessage( { novaworkAlert: data.id } );
				return app.focus();
			}
			return data.appUrl ? self.clients.openWindow( data.appUrl ) : undefined;
		} )
	);
} );

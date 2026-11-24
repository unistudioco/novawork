/**
 * A smarter timer (owner, 1.2.0): NovaWork never loses time silently and
 * never counts time nobody confirmed.
 *
 * - After an automatic stop, "Were you working?" offers to count the time.
 * - Stop works offline and is sent when the connection returns.
 * - "Still working?" also shows as an alert, answered from its buttons.
 *
 * Alerts and workers need a secure page: this browser treats the dev site
 * as secure, as alerts.spec.js does.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, unique, cleanUp, wpEval } from './helpers.js';

const BASE = process.env.NOVAWORK_URL || 'http://novawork.local';

test.use( {
	launchOptions: [
		async ( { launchOptions }, use ) => {
			await use( { ...launchOptions, args: [ ...( launchOptions.args || [] ), '--unsafely-treat-insecure-origin-as-secure=' + BASE ] } );
		},
		{ scope: 'worker' },
	],
} );

test.beforeEach( async ( { context, page } ) => {
	await signIn( context );
	await page.goto( APP + '#focus' );
	await page.locator( '.nw-focus' ).waitFor();
} );

test.afterEach( async ( { page } ) => {
	if ( ! page.isClosed() ) {
		await page.evaluate( async () => {
			const h = { 'X-WP-Nonce': window.novawork.nonce };
			await fetch( window.novawork.restUrl + 'timer/stop', { method: 'POST', headers: h } );
			await fetch( window.novawork.restUrl + 'timer/dismiss', { method: 'POST', headers: h } );
		} );
		await cleanUp( page );
	}
} );

async function track( page, title ) {
	const id = await page.evaluate( async ( t ) => {
		const h = { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' };
		return ( await ( await fetch( window.novawork.restUrl + 'tasks', { method: 'POST', headers: h, body: JSON.stringify( { title: t, status: 'now' } ) } ) ).json() ).id;
	}, title );
	await page.reload();
	await page.locator( `.nw-slot[data-id="${ id }"] [data-act="track"]` ).click();
	await expect( page.locator( '.nw-now-card.nw-run' ) ).toBeVisible();
	return id;
}

const running = ( page ) =>
	page.evaluate( async () => {
		const d = await ( await fetch( window.novawork.restUrl + 'sync', { headers: { 'X-WP-Nonce': window.novawork.nonce } } ) ).json();
		return d.data.timeEntries.find( ( e ) => e.running && e.by === d.me ) || null;
	} );

test( 'away for 30 minutes: "Were you working?" asks, and Count it puts the time back and keeps tracking', async ( { page } ) => {
	const title = unique( 'were you working' );
	await track( page, title );
	const me = await page.evaluate( () => window.novawork.me );
	// Started 50 minutes ago; the app went quiet 30 minutes ago (a folded laptop).
	// Other tests' sessions from that hour would overlap, so clear them first.
	wpEval( `global $wpdb; $e = new NovaWork\\Data\\TimeEntryRepository(); $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND running_user IS NULL AND ( ended_at IS NULL OR ended_at > %s )', $e->table_name(), ${ me }, gmdate( 'Y-m-d H:i:s', time() - 2 * 3600 ) ) );` );
	wpEval(
		`$e = new NovaWork\\Data\\TimeEntryRepository(); $r = $e->running_for( ${ me } ); $e->update( (int) $r['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 50 * 60 ) ) ); update_user_meta( ${ me }, NovaWork\\Members::SEEN_META, time() - 30 * 60 ); update_user_meta( ${ me }, NovaWork\\Members::ACTIVE_META, ( time() - 30 * 60 ) * 1000 );`
	);
	await page.reload();
	const dlg = page.locator( '#nw-dlg[open]' );
	await expect( dlg.locator( 'h2' ) ).toHaveText( 'Were you working?', { timeout: 10000 } );
	await expect( dlg.locator( '.nw-recover-lead' ) ).toContainText( title );
	await expect( dlg.locator( '[name="keep"]' ) ).toBeChecked();
	await dlg.locator( 'button[value="save"]' ).click();
	await expect( page.locator( '.nw-toast', { hasText: 'Counted, and still tracking' } ) ).toBeVisible();
	const run = await running( page );
	expect( run ).not.toBeNull();
	// The same session, from 50 minutes ago, still running.
	expect( Date.now() - run.start ).toBeGreaterThan( 49 * 60000 );
	await expect( page.locator( '.nw-now-card.nw-run' ) ).toBeVisible();
} );

test( "Don't count it leaves the session where it stopped", async ( { page } ) => {
	await track( page, unique( 'not working' ) );
	const me = await page.evaluate( () => window.novawork.me );
	wpEval(
		`$e = new NovaWork\\Data\\TimeEntryRepository(); $r = $e->running_for( ${ me } ); $e->update( (int) $r['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * 60 ) ) ); update_user_meta( ${ me }, NovaWork\\Members::SEEN_META, time() - 25 * 60 ); update_user_meta( ${ me }, NovaWork\\Members::ACTIVE_META, ( time() - 25 * 60 ) * 1000 );`
	);
	await page.reload();
	const dlg = page.locator( '#nw-dlg[open]' );
	await expect( dlg.locator( 'h2' ) ).toHaveText( 'Were you working?', { timeout: 10000 } );
	await dlg.locator( '[data-recover="no"]' ).click();
	await expect( page.locator( '.nw-toast', { hasText: 'Left as it was' } ) ).toBeVisible();
	expect( await running( page ) ).toBeNull();
	// Asked once: a reload doesn't ask again.
	await page.reload();
	await page.waitForTimeout( 2500 );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 0 );
} );

test( 'Stop while offline is kept, and sent when the connection returns', async ( { page, context } ) => {
	await track( page, unique( 'offline stop' ) );
	// Five minutes in, so the session is long enough to keep.
	const me = await page.evaluate( () => window.novawork.me );
	wpEval( `$e = new NovaWork\\Data\\TimeEntryRepository(); $r = $e->running_for( ${ me } ); $e->update( (int) $r['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * 60 ) ) );` );
	await expect.poll( () => page.evaluate( () => Date.now() - window.Alpine.store( 'nw' ).timeEntries[ Object.keys( window.Alpine.store( 'nw' ).timeEntries ).find( ( k ) => window.Alpine.store( 'nw' ).timeEntries[ k ].running ) ]?.start ), { timeout: 15000 } ).toBeGreaterThan( 4 * 60000 );
	await context.setOffline( true );
	await expect( page.locator( '.nw-toast', { hasText: 'You’re offline' } ) ).toBeVisible( { timeout: 15000 } );
	const pressed = Date.now();
	await page.locator( '.nw-now-card [data-act="track"]' ).click();
	await expect( page.locator( '.nw-toast', { hasText: 'saved as soon as you’re back' } ) ).toBeVisible();
	await page.waitForTimeout( 3000 );
	await context.setOffline( false );
	await expect( page.locator( '.nw-toast', { hasText: 'Back online' } ) ).toBeVisible( { timeout: 20000 } );
	expect( await running( page ) ).toBeNull();
	const ended = await page.evaluate( async () => {
		const d = await ( await fetch( window.novawork.restUrl + 'time-entries?from=' + ( Date.now() - 3600e3 ), { headers: { 'X-WP-Nonce': window.novawork.nonce } } ) ).json();
		return d.filter( ( e ) => e.by === window.novawork.me ).sort( ( a, b ) => b.start - a.start )[ 0 ];
	} );
	// Ended when Stop was pressed, not when the connection came back.
	expect( ended && ended.running ).toBe( false );
	expect( Math.abs( ended.end - pressed ) ).toBeLessThan( 5000 );
} );

test( '"Still working?" shows as an alert when the app is in the background, and its button answers it', async ( { page } ) => {
	await page.addInitScript( () => {
		window.__alerts = [];
		ServiceWorkerRegistration.prototype.showNotification = function ( title, o ) {
			window.__alerts.push( { title, actions: ( o.actions || [] ).map( ( a ) => a.action ), tag: o.tag } );
			return Promise.resolve();
		};
		ServiceWorkerRegistration.prototype.getNotifications = function () {
			return Promise.resolve( [] );
		};
		window.Notification = class {
			static permission = 'granted';
			static requestPermission() {
				return Promise.resolve( 'granted' );
			}
			close() {}
		};
		try {
			localStorage.setItem( 'nw.alerts', 'on' );
		} catch ( e ) {}
		// Working in another window.
		document.hasFocus = () => false;
	} );
	// The inactivity setting at its minimum, one minute; a longer jump would read as sleep.
	wpEval( "$s = (array) get_option( NovaWork\\Settings::OPTION, array() ); $s['idle_minutes'] = 1; update_option( NovaWork\\Settings::OPTION, $s );" );
	try {
		await page.reload();
		await track( page, unique( 'alert me' ) );
		// Let the click's scroll settle first: a scroll is activity, and one
		// landing after the clock moves would rightly delay the question.
		await page.waitForTimeout( 1500 );
		await page.evaluate( () => {
			const real = Date.now.bind( Date );
			Date.now = () => real() + 70000;
		} );
		await expect.poll( () => page.evaluate( () => window.__alerts.filter( ( a ) => a.tag === 'novawork-idle' ).length ), { timeout: 10000 } ).toBe( 1 );
		const alert = await page.evaluate( () => window.__alerts.find( ( a ) => a.tag === 'novawork-idle' ) );
		expect( alert.title ).toBe( 'Still working?' );
		expect( alert.actions ).toEqual( [ 'resume', 'pause' ] );
		// "I'm still working" on the alert (the worker passes the answer on).
		await page.evaluate( () => navigator.serviceWorker.dispatchEvent( new MessageEvent( 'message', { data: { novaworkIdle: 'resume' } } ) ) );
		await expect( page.locator( '#nw-idle-dlg[open]' ) ).toHaveCount( 0 );
		await expect( page.locator( '.nw-now-card.nw-run' ) ).toBeVisible();
	} finally {
		wpEval( "$s = (array) get_option( NovaWork\\Settings::OPTION, array() ); unset( $s['idle_minutes'] ); update_option( NovaWork\\Settings::OPTION, $s );" );
	}
} );

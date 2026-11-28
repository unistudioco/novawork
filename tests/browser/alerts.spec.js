/**
 * Desktop alerts (owner request, 2026-11-22): turned on from the bell, they
 * show a teammate's message while the workspace is in the background, never
 * while you are looking at it, and a click opens the item.
 *
 * Alerts need a secure page; the dev site is plain http, so this browser
 * treats it as secure. The browser's own alert is replaced by a recorder.
 */
import { test, expect } from './offline.js';
import { APP, signIn, unique, cleanChat } from './helpers.js';

const BASE = process.env.NOVAWORK_URL || 'http://novawork.local';

// Added to the configured launch options, not in place of them.
test.use( {
	launchOptions: [
		async ( { launchOptions }, use ) => {
			await use( { ...launchOptions, args: [ ...( launchOptions.args || [] ), '--unsafely-treat-insecure-origin-as-secure=' + BASE ] } );
		},
		{ scope: 'worker' },
	],
} );

test.afterAll( () => {
	cleanChat();
} );

/** Record alerts instead of showing them; `focused` stands in for window focus. */
const RECORDER = () => {
	window.__alerts = [];
	window.__focused = true;
	document.hasFocus = () => window.__focused;
	const record = ( title, o ) => window.__alerts.push( { title, body: o.body, tag: o.tag, id: o.data && o.data.id } );
	ServiceWorkerRegistration.prototype.showNotification = function ( title, o ) {
		record( title, o );
		return Promise.resolve();
	};
	window.Notification = class {
		static permission = 'granted';
		static requestPermission() {
			return Promise.resolve( 'granted' );
		}
		constructor( title, o ) {
			record( title, o );
		}
		close() {}
	};
};

test( 'turn alerts on; a mention in the background alerts once; looking at the app does not', async ( { browser } ) => {
	const ctxA = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctxA, 'A' );
	const a = await ctxA.newPage();
	await a.addInitScript( RECORDER );
	await a.goto( APP + '#focus' );
	await a.evaluate( () => window.localStorage.removeItem( 'nw.alerts' ) );

	await a.click( '#nw-bell-btn' );
	const row = a.locator( '.nw-nalerts' );
	await expect( row ).toContainText( 'Get desktop alerts' );
	await row.locator( '[data-n="alerts-on"]' ).click();
	await expect( row ).toContainText( 'Desktop alerts are on' );
	await a.keyboard.press( 'Escape' );

	const ctxB = await browser.newContext();
	await signIn( ctxB, 'B' );
	const b = await ctxB.newPage();
	await b.goto( APP );
	const meA = await a.evaluate( () => window.Alpine.store( 'nw' ).me );
	const say = ( text ) =>
		b.evaluate(
			async ( [ t, id ] ) => {
				const h = { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' };
				const name = window.Alpine.store( 'nw' ).members.find( ( m ) => m.id === id ).name;
				await fetch( window.novawork.restUrl + 'messages', { method: 'POST', headers: h, body: JSON.stringify( { text: t + ' @' + name, mentions: [ id ] } ) } );
			},
			[ text, meA ]
		);

	// Looking at the app: no alert, the bell is enough.
	const seen = unique( 'while looking' );
	await say( seen );
	await expect.poll( () => a.evaluate( () => Object.values( window.Alpine.store( 'nw' ).notifications ).some( ( n ) => ( n.data?.excerpt || '' ).includes( 'while looking' ) ) ), { timeout: 15000 } ).toBe( true );
	await a.waitForTimeout( 300 );
	expect( await a.evaluate( () => window.__alerts.length ) ).toBe( 0 );

	// In the background: one alert with the bell's wording.
	await a.evaluate( () => ( window.__focused = false ) );
	const away = unique( 'while away' );
	await say( away );
	await expect.poll( () => a.evaluate( () => window.__alerts.length ), { timeout: 15000 } ).toBe( 1 );
	const alert = ( await a.evaluate( () => window.__alerts ) )[ 0 ];
	expect( alert.body ).toContain( 'mentioned you in chat' );
	expect( alert.body ).toContain( 'while away' );
	expect( alert.tag ).toBe( 'novawork-' + alert.id );

	// A click on it (the worker posts the id back) opens the chat on the message and marks it read.
	await a.evaluate( ( id ) => navigator.serviceWorker.dispatchEvent( new MessageEvent( 'message', { data: { novaworkAlert: id } } ) ), alert.id );
	await expect( a.locator( '#nw-chat' ) ).toBeVisible();
	await expect.poll( () => a.evaluate( ( id ) => window.Alpine.store( 'nw' ).notifications[ id ].read, alert.id ) ).toBe( true );

	// Off again: nothing more.
	await a.evaluate( () => window.localStorage.setItem( 'nw.alerts', 'off' ) );
	await say( unique( 'after off' ) );
	await a.waitForTimeout( 6000 );
	expect( await a.evaluate( () => window.__alerts.length ) ).toBe( 1 );

	await ctxA.close();
	await ctxB.close();
} );

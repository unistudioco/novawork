/**
 * One member, two devices (owner report, 2026-11-23): with the workspace open
 * on a phone and a laptop and the timer running, the phone asked "Still
 * working?" while the laptop was in use, and paused the timer, losing time.
 * On Android, "I'm still working" also brought the question straight back.
 *
 * Activity now counts across devices (views/idle.js, lib/activity.js). The
 * idle threshold is set to its minimum, one minute, so these tests take
 * real minutes.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, unique, cleanUp, wpEval } from './helpers.js';

const setIdle = ( minutes ) => wpEval( `$s = (array) get_option( NovaWork\\Settings::OPTION, array() ); $s['idle_minutes'] = ${ minutes }; update_option( NovaWork\\Settings::OPTION, $s );` );
const unsetIdle = () => wpEval( "$s = (array) get_option( NovaWork\\Settings::OPTION, array() ); unset( $s['idle_minutes'] ); update_option( NovaWork\\Settings::OPTION, $s );" );

test.beforeAll( () => setIdle( 1 ) );
test.afterAll( () => unsetIdle() );

async function open( browser, viewport, init ) {
	const context = await browser.newContext( { viewport } );
	if ( init ) {
		await context.addInitScript( init );
	}
	await signIn( context );
	const page = await context.newPage();
	await page.goto( APP + '#focus' );
	await page.locator( '.nw-focus' ).waitFor();
	return page;
}

/**
 * Start the timer on a new task in Now. With { reported: true } it returns
 * only once the poll carrying the click's activity has come back (the
 * server may keep it or not: it stores activity at most every 10 seconds).
 */
async function track( page, title, { reported = false } = {} ) {
	const id = await page.evaluate( async ( t ) => {
		const h = { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' };
		return ( await ( await fetch( window.novawork.restUrl + 'tasks', { method: 'POST', headers: h, body: JSON.stringify( { title: t, status: 'now' } ) } ) ).json() ).id;
	}, title );
	const isSync = ( url ) => new URL( url ).pathname.endsWith( '/sync' ) || new URL( url ).searchParams.get( 'rest_route' )?.endsWith( '/sync' );
	// The first poll after loading reports the activity so far, so nothing is
	// waiting to be sent when Play is clicked.
	const settled = reported ? page.waitForResponse( ( r ) => isSync( r.url() ), { timeout: 20000 } ) : null;
	await page.reload();
	const play = page.locator( `.nw-slot[data-id="${ id }"] [data-act="track"]` );
	await play.waitFor();
	let report = null;
	if ( reported ) {
		await settled;
		// The app sends its latest activity with the next poll, so the next
		// request that carries `active` carries the click (no clock compared).
		report = page.waitForRequest( ( r ) => isSync( r.url() ) && new URL( r.url() ).searchParams.has( 'active' ), { timeout: 20000 } );
	}
	await play.click();
	await expect( page.locator( '.nw-now-card.nw-run' ) ).toBeVisible();
	if ( report ) {
		const answered = await ( await report ).response();
		expect( answered && answered.ok() ).toBe( true );
	}
}

async function finish( page ) {
	await page.evaluate( () => fetch( window.novawork.restUrl + 'timer/stop', { method: 'POST', headers: { 'X-WP-Nonce': window.novawork.nonce } } ) );
	await cleanUp( page );
}

test( 'working on the laptop: the phone never asks, and its question closes once the laptop is used again', async ( { browser } ) => {
	test.setTimeout( 300000 );
	const laptop = await open( browser, { width: 1366, height: 900 } );
	const phone = await open( browser, { width: 390, height: 844 } );
	try {
		await track( laptop, unique( 'two devices' ) );
		await expect( phone.locator( '.nw-now-card.nw-run' ) ).toBeVisible( { timeout: 15000 } );
		await phone.evaluate( () => {
			window.__asked = 0;
			new MutationObserver( () => {
				if ( document.getElementById( 'nw-idle-dlg' ).open ) {
					window.__asked++;
				}
			} ).observe( document.getElementById( 'nw-idle-dlg' ), { attributes: true, attributeFilter: [ 'open' ] } );
		} );

		// 75 seconds of work on the laptop only (the threshold is 60).
		for ( let i = 0; i < 25; i++ ) {
			await laptop.mouse.move( 200 + ( i % 5 ) * 40, 300 + ( i % 3 ) * 30 );
			await laptop.waitForTimeout( 3000 );
		}
		expect( await phone.evaluate( () => window.__asked ) ).toBe( 0 );
		await expect( phone.locator( '.nw-now-card.nw-run' ) ).toBeVisible();

		// Away from both: the phone asks about a minute after the laptop's last activity…
		await expect( phone.locator( '#nw-idle-dlg[open]' ) ).toHaveCount( 1, { timeout: 120000 } );
		// …and using the laptop again answers it, with the time kept.
		await laptop.locator( '#nw-idle-dlg [data-idle="resume"]' ).click( { timeout: 15000 } ).catch( () => laptop.mouse.move( 10, 10 ) );
		await expect( phone.locator( '#nw-idle-dlg[open]' ) ).toHaveCount( 0, { timeout: 20000 } );
		await expect( phone.locator( '.nw-toast', { hasText: 'another device' } ) ).toBeVisible();
		await expect( phone.locator( '.nw-now-card.nw-run' ) ).toBeVisible();
	} finally {
		await finish( laptop );
		await laptop.context().close();
		await phone.context().close();
	}
} );

/** The Idle Detection API as Android Chrome has it, reporting "idle" at once. */
const IDLE_DETECTOR = () => {
	class FakeIdleDetector extends EventTarget {
		constructor() {
			super();
			this.userState = 'active';
			this.screenState = 'unlocked';
		}
		async start() {
			setTimeout( () => {
				this.userState = 'idle';
				this.dispatchEvent( new Event( 'change' ) );
			}, 300 );
		}
	}
	FakeIdleDetector.requestPermission = async () => 'granted';
	window.IdleDetector = FakeIdleDetector;
	const query = navigator.permissions.query.bind( navigator.permissions );
	navigator.permissions.query = ( d ) => ( d && d.name === 'idle-detection' ? Promise.resolve( { state: 'granted' } ) : query( d ) );
};

test( '"I\'m still working" answers once, even when the system says idle (Android)', async ( { browser } ) => {
	const page = await open( browser, { width: 390, height: 844 }, IDLE_DETECTOR );
	try {
		// The click on Play is activity: the clock moves only once the poll that
		// reports it is back. Moving just the browser's clock is not real time
		// passing; a poll that reports the click after the move re-syncs with
		// the server's clock and undoes the fake minute. Right after a poll, the
		// next one is seconds away and the one-second check asks first.
		await track( page, unique( 'idle detector' ), { reported: true } );
		// A minute and a bit without touching it (less than a sleep).
		await page.evaluate( () => {
			const real = Date.now.bind( Date );
			Date.now = () => real() + 70000;
		} );
		await expect( page.locator( '#nw-idle-dlg[open]' ) ).toHaveCount( 1, { timeout: 10000 } );
		await page.locator( '#nw-idle-dlg [data-idle="resume"]' ).click();
		await expect( page.locator( '#nw-idle-dlg[open]' ) ).toHaveCount( 0 );
		// It used to open again on the next tick, until the timer was paused.
		await page.waitForTimeout( 3000 );
		await expect( page.locator( '#nw-idle-dlg[open]' ) ).toHaveCount( 0 );
		await expect( page.locator( '.nw-now-card.nw-run' ) ).toBeVisible();
	} finally {
		await finish( page );
		await page.context().close();
	}
} );

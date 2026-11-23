/**
 * Take the WordPress.org screenshots (readme.txt "== Screenshots ==") into
 * .wordpress-org/screenshot-N.png, from the dev site's demo workspace.
 *
 * Run on a site with the generic demo data (bash bin/seed-dev.sh) and with
 * Fontshare reachable, so the real typefaces show:
 *
 *   node bin/screenshots.mjs        (all of them)
 *   node bin/screenshots.mjs 11     (only the ones named)
 *
 * Reads NOVAWORK_URL, NOVAWORK_USER_A (a member) and NOVAWORK_ADMIN from
 * .dev.env, like the browser tests. Uses the installed Chrome; set
 * NOVAWORK_CHROME to a browser binary to use another one.
 */
import { chromium } from '@playwright/test';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { wpEval } from '../tests/browser/helpers.js';

if ( existsSync( '.dev.env' ) ) {
	for ( const line of readFileSync( '.dev.env', 'utf8' ).split( '\n' ) ) {
		const m = line.match( /^\s*(NOVAWORK_[A-Z_]+)="?([^"]*)"?\s*$/ );
		if ( m && ! process.env[ m[ 1 ] ] ) {
			// Shell-style values: $HOME as the shell would expand it (as playwright.config.js does).
			process.env[ m[ 1 ] ] = m[ 2 ].replace( /\$HOME/g, process.env.HOME || '' );
		}
	}
}
const BASE = process.env.NOVAWORK_URL || 'http://novawork.local';
const MEMBER = ( process.env.NOVAWORK_USER_A || 'alex:alex' ).split( ':' );
const ADMIN = ( process.env.NOVAWORK_ADMIN || 'admin:admin' ).split( ':' );
const APP = BASE + '/novawork/';
const OUT = '.wordpress-org/';
mkdirSync( OUT, { recursive: true } );

const browser = await chromium.launch( process.env.NOVAWORK_CHROME ? { executablePath: process.env.NOVAWORK_CHROME } : { channel: 'chrome' } );

// Numbers on the command line take only those screenshots.
const only = process.argv.slice( 2 ).map( Number ).filter( Boolean );
const want = ( n ) => ! only.length || only.includes( n );

// A phone: an iPhone-sized screen with touch, as the installed app shows it.
const PHONE = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true };

async function signedIn( [ user, pass ], colorScheme = 'light', device = null ) {
	const context = await browser.newContext( { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, colorScheme, ...( device || {} ) } );
	await context.addCookies( [ { name: 'wordpress_test_cookie', value: 'WP Cookie check', url: BASE } ] );
	const res = await context.request.post( BASE + '/wp-login.php', { form: { log: user, pwd: pass, testcookie: '1' }, maxRedirects: 0 } );
	if ( res.status() !== 302 ) {
		throw new Error( 'Sign-in failed for ' + user );
	}
	const page = await context.newPage();
	// Show the whole team's board, and the chat in its default place.
	await page.addInitScript( () => {
		try {
			localStorage.setItem( 'nw.fPerson', 'all' );
			localStorage.setItem( 'nw.fProject', 'all' );
			localStorage.removeItem( 'nw.chat' );
		} catch ( e ) {}
	} );
	page.on( 'pageerror', ( e ) => console.error( 'Page error:', e.message ) );
	// The welcome tour would start on top of the screenshots: mark it seen.
	await page.goto( APP );
	await page.evaluate( () => fetch( window.novawork.restUrl + 'tour/seen', { method: 'POST', headers: { 'X-WP-Nonce': window.novawork.nonce } } ) );
	return page;
}
// Let fonts, avatars and animations settle.
const settle = async ( page ) => {
	await page.evaluate( () => document.fonts.ready );
	await page.waitForTimeout( 900 );
};
// Focus shows the timer running (its flowing gradient), at about 1h 24m:
// start it in the app, move its start back, and delete that entry after
// the screenshot so the demo's tracked time stays as it was.
const RUNNING = `$e = new NovaWork\\Data\\TimeEntryRepository(); $u = get_user_by( 'login', '${ MEMBER[ 0 ] }' ); $r = $e->running_for( (int) $u->ID );`;
async function runTimer( page ) {
	await page.locator( '.nw-now-card [data-act="track"]' ).click();
	await page.waitForSelector( '.nw-now-card.nw-run' );
	wpEval( `${ RUNNING } $e->update( (int) $r['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 84 * 60 ) ) );` );
	// The next poll brings the earlier start.
	await page.waitForFunction( () => Number( document.querySelector( '.nw-now-card [data-since]' )?.dataset.since ) < Date.now() - 3600e3, null, { timeout: 20000 } );
	// Leave the button, so its tooltip goes.
	await page.mouse.move( 2, 2 );
	await page.waitForFunction( () => ! document.querySelector( '.nw-tip.nw-tip-on' ) );
}
const dropTimer = () => wpEval( `${ RUNNING } if ( $r ) { $e->delete( (int) $r['id'] ); }` );

const shot = async ( page, n ) => {
	await settle( page );
	await page.screenshot( { path: `${ OUT }screenshot-${ n }.png` } );
	console.log( `screenshot-${ n }.png` );
};

const p = await signedIn( MEMBER );

if ( want( 1 ) ) {
	// 1. Focus.
	await p.goto( APP + '#focus' );
	await p.waitForSelector( '.nw-focus' );
	await runTimer( p );
	try {
		await shot( p, 1 );
	} finally {
		dropTimer();
	}
}

if ( want( 2 ) ) {
	// 2. The board, a task's card carrying the running timer's gradient
	// (the banner uses it too).
	await p.goto( APP + '#focus' );
	await p.waitForSelector( '.nw-focus' );
	await runTimer( p );
	try {
		await p.goto( APP + '#board' );
		await p.waitForSelector( '.nw-card.nw-run' );
		await p.mouse.move( 2, 2 );
		await shot( p, 2 );
	} finally {
		dropTimer();
	}
}

if ( want( 3 ) ) {
	// 3. A task with its checklist: the first card that has subtasks.
	await p.goto( APP + '#board' );
	await p.waitForSelector( '.nw-card' );
	const withSubs = p.locator( '.nw-card:has(.nw-subtask-n)' ).first();
	await ( ( await withSubs.count() ) ? withSubs : p.locator( '.nw-card' ).first() ).click();
	await p.waitForSelector( '#nw-dlg[open]' );
	await shot( p, 3 );
	await p.keyboard.press( 'Escape' );
}

if ( want( 4 ) ) {
	// 4. Roadmap.
	await p.goto( APP + '#roadmap' );
	await p.waitForSelector( '.nw-proj' );
	await shot( p, 4 );
}

if ( want( 5 ) ) {
	// 5. Decisions.
	await p.goto( APP + '#decisions' );
	await p.waitForSelector( '.nw-dec' );
	await shot( p, 5 );
}

if ( want( 6 ) ) {
	// 6. Chat beside the board.
	await p.goto( APP + '#board' );
	await p.waitForSelector( '.nw-card' );
	await p.click( '#nw-chat-btn' );
	await p.waitForSelector( '#nw-chat:visible' );
	await shot( p, 6 );
	await p.click( '#nw-chat-close' );
}

if ( want( 7 ) ) {
	// 7. Notifications.
	await p.goto( APP + '#focus' );
	await p.waitForSelector( '.nw-focus' );
	await p.click( '#nw-bell-btn' );
	await p.waitForSelector( '.nw-pop.nw-notes .nw-nlist' );
	await shot( p, 7 );
}

if ( want( 8 ) ) {
	// 8. Time.
	await p.goto( APP + '#time' );
	await p.waitForTimeout( 1200 );
	await shot( p, 8 );
}

if ( want( 9 ) ) {
	// 9. Dark mode.
	const dark = await signedIn( MEMBER, 'dark' );
	await dark.goto( APP + '#board' );
	await dark.waitForSelector( '.nw-card' );
	await shot( dark, 9 );
}

if ( want( 10 ) ) {
	// 10. Settings in wp-admin.
	const admin = await signedIn( ADMIN );
	await admin.goto( BASE + '/wp-admin/admin.php?page=novawork-settings' );
	await admin.waitForSelector( '.nw-admin .nw-scard' );
	await shot( admin, 10 );
}

if ( want( 11 ) ) {
	// 11. On a phone, the timer running (the banner uses it too:
	// bin/wporg-assets.mjs).
	const phone = await signedIn( MEMBER, 'light', PHONE );
	await phone.goto( APP + '#focus' );
	await phone.waitForSelector( '.nw-focus' );
	await runTimer( phone );
	try {
		await shot( phone, 11 );
	} finally {
		dropTimer();
	}
}

await browser.close();

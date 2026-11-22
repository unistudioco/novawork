/**
 * Take the WordPress.org screenshots (readme.txt "== Screenshots ==") into
 * .wordpress-org/screenshot-N.png, from the dev site's demo workspace.
 *
 * Run on a site with the generic demo data (bash bin/seed-dev.sh) and with
 * Fontshare reachable, so the real typefaces show:
 *
 *   node bin/screenshots.mjs
 *
 * Reads NOVAWORK_URL, NOVAWORK_USER_A (a member) and NOVAWORK_ADMIN from
 * .dev.env, like the browser tests. Uses the installed Chrome; set
 * NOVAWORK_CHROME to a browser binary to use another one.
 */
import { chromium } from '@playwright/test';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';

if ( existsSync( '.dev.env' ) ) {
	for ( const line of readFileSync( '.dev.env', 'utf8' ).split( '\n' ) ) {
		const m = line.match( /^\s*(NOVAWORK_[A-Z_]+)="?([^"]*)"?\s*$/ );
		if ( m && ! process.env[ m[ 1 ] ] ) {
			process.env[ m[ 1 ] ] = m[ 2 ];
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

async function signedIn( [ user, pass ], colorScheme = 'light' ) {
	const context = await browser.newContext( { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, colorScheme } );
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
	return page;
}
// Let fonts, avatars and animations settle.
const settle = async ( page ) => {
	await page.evaluate( () => document.fonts.ready );
	await page.waitForTimeout( 900 );
};
const shot = async ( page, n ) => {
	await settle( page );
	await page.screenshot( { path: `${ OUT }screenshot-${ n }.png` } );
	console.log( `screenshot-${ n }.png` );
};

const p = await signedIn( MEMBER );

// 1. Focus.
await p.goto( APP + '#focus' );
await p.waitForSelector( '.nw-focus' );
await shot( p, 1 );

// 2. The board.
await p.goto( APP + '#board' );
await p.waitForSelector( '.nw-card' );
await shot( p, 2 );

// 3. A task with its checklist: the first card that has subtasks.
const withSubs = p.locator( '.nw-card:has(.nw-subtask-n)' ).first();
await ( ( await withSubs.count() ) ? withSubs : p.locator( '.nw-card' ).first() ).click();
await p.waitForSelector( '#nw-dlg[open]' );
await shot( p, 3 );
await p.keyboard.press( 'Escape' );

// 4. Roadmap.
await p.goto( APP + '#roadmap' );
await p.waitForSelector( '.nw-proj' );
await shot( p, 4 );

// 5. Decisions.
await p.goto( APP + '#decisions' );
await p.waitForSelector( '.nw-dec' );
await shot( p, 5 );

// 6. Chat beside the board.
await p.goto( APP + '#board' );
await p.waitForSelector( '.nw-card' );
await p.click( '#nw-chat-btn' );
await p.waitForSelector( '#nw-chat:visible' );
await shot( p, 6 );
await p.click( '#nw-chat-close' );

// 7. Notifications.
await p.goto( APP + '#focus' );
await p.waitForSelector( '.nw-focus' );
await p.click( '#nw-bell-btn' );
await p.waitForSelector( '.nw-pop.nw-notes .nw-nlist' );
await shot( p, 7 );

// 8. Time.
await p.goto( APP + '#time' );
await p.waitForTimeout( 1200 );
await shot( p, 8 );

// 9. Dark mode.
const dark = await signedIn( MEMBER, 'dark' );
await dark.goto( APP + '#board' );
await dark.waitForSelector( '.nw-card' );
await shot( dark, 9 );

// 10. Settings in wp-admin.
const admin = await signedIn( ADMIN );
await admin.goto( BASE + '/wp-admin/admin.php?page=novawork-settings' );
await admin.waitForSelector( '.nw-admin .nw-scard' );
await shot( admin, 10 );

await browser.close();

/**
 * Extension points for add-ons (1.3.0): the app tells add-ons it is ready,
 * a header button, a team menu row, a notification kind, data on boot and
 * sync, agents in @mentions and in "Who decides", and a page under the
 * NovaWork menu built with the settings look and kit. Uses the test add-on in
 * tests/fixtures (a must-use plugin, linked for this file only and gated by
 * an option), and checks that nothing changes without it.
 */
import { test, expect } from '@playwright/test';
import { existsSync, mkdirSync, symlinkSync, unlinkSync, readdirSync, rmdirSync, lstatSync } from 'node:fs';
import { resolve } from 'node:path';
import { APP, signIn, unique, cleanChat, cleanUp, wpEval } from './helpers.js';

const MU = resolve( process.env.NOVAWORK_WP_PATH || '', 'wp-content/mu-plugins' );
const LINK = resolve( MU, 'novawork-test-addon.php' );
let madeMu = false;

const addon = ( on ) => wpEval( on ? "update_option( 'novawork_test_addon', '1', false );" : "delete_option( 'novawork_test_addon' );" );

test.beforeAll( () => {
	if ( ! existsSync( MU ) ) {
		mkdirSync( MU );
		madeMu = true;
	}
	if ( ! existsSync( LINK ) ) {
		symlinkSync( resolve( 'tests/fixtures/test-addon.php' ), LINK );
	}
} );

test.afterAll( () => {
	addon( false );
	cleanChat();
	wpEval( "global $wpdb; $n = new NovaWork\\Data\\NotificationRepository(); foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE type = %s', $n->table_name(), 'test_ping' ) ) as $id ) { $n->delete( (int) $id ); } delete_metadata( 'user', 0, 'novawork_test_flag', '', true );" );
	if ( existsSync( LINK ) || lstatSync( LINK, { throwIfNoEntry: false } ) ) {
		unlinkSync( LINK );
	}
	if ( madeMu && ! readdirSync( MU ).length ) {
		rmdirSync( MU );
	}
} );

test( 'without an add-on the app is unchanged, and still says it is ready', async ( { browser } ) => {
	addon( false );
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.waitForFunction( () => !! ( window.novawork && window.novawork.app ) );
	await expect( page.locator( '[id^="nw-x-"], [id^="nw-xm-"]' ) ).toHaveCount( 0 );
	const state = await page.evaluate( () => ( { addons: window.novawork.addons, agents: window.Alpine.store( 'nw' ).agents.length, store: window.Alpine.store( 'nw' ).addons } ) );
	expect( state ).toEqual( { addons: {}, agents: 0, store: {} } );
	// The chat button still sits right after the search, as before.
	await expect( page.locator( '#nw-chat-btn' ) ).toBeVisible();
	await ctx.close();
} );

test( 'an add-on adds a header button, a team menu row and data on boot and sync', async ( { browser } ) => {
	addon( true );
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.waitForFunction( () => !! window.nwTestAddon );
	const seen = await page.evaluate( () => window.nwTestAddon );
	expect( seen.keys ).toEqual( [ '__', 'addHeaderButton', 'addMenuItem', 'addNoteKind', 'api', 'esc', 'ic', 'icf', 'sprintf', 'store', 'toast' ] );
	expect( seen.same ).toBe( true );
	expect( seen.boot.hello ).toBe( 'boot' );

	// The header button sits just before the chat button, with a tooltip name.
	const btn = page.locator( '#nw-x-test' );
	await expect( btn ).toBeVisible();
	await expect( btn ).toHaveAttribute( 'aria-label', 'Test add-on' );
	expect( await page.evaluate( () => document.getElementById( 'nw-x-test' ).nextElementSibling.id ) ).toBe( 'nw-chat-btn' );
	await btn.click();
	await expect( page.locator( '.nw-toast', { hasText: 'Header button works' } ) ).toBeVisible();

	// Its dot follows what the add-on sends with each poll.
	await expect( btn.locator( '.nw-dot-alert' ) ).toHaveCount( 0 );
	wpEval( "update_user_meta( get_user_by( 'login', 'alex' )->ID, 'novawork_test_flag', 1 );" );
	await expect( btn.locator( '.nw-dot-alert' ) ).toHaveCount( 1, { timeout: 15000 } );

	// A row in the team menu: first in the menu; picking it closes the menu.
	await page.click( '#nw-crew-btn' );
	const row = page.locator( '.nw-pmenu #nw-xm-test' );
	await expect( row ).toHaveText( 'Test add-on row' );
	expect( await page.evaluate( () => document.querySelector( '.nw-pmenu' ).firstElementChild.id ) ).toBe( 'nw-xm-test' );
	await row.click();
	await expect( page.locator( '.nw-toast', { hasText: 'Menu row works' } ) ).toBeVisible();
	await expect( page.locator( '#nw-xm-test' ) ).toBeHidden();
	await ctx.close();
} );

test( 'an add-on’s notification kind shows in the bell with its own wording', async ( { browser } ) => {
	addon( true );
	const title = unique( 'ping' );
	wpEval( `NovaWork\\Api::notify( get_user_by( 'login', 'alex' )->ID, 'test_ping', array( 'title' => '${ title }' ), 'helper' );` );
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.waitForFunction( () => !! window.nwTestAddon );
	await page.click( '#nw-bell-btn' );
	const item = page.locator( '#nw-notes .nw-nitem', { hasText: title } );
	await expect( item.locator( '.nw-ntext' ) ).toContainText( `Helper pinged you about ${ title }` );
	await expect( item.locator( '.nw-ntext b' ) ).toHaveText( [ 'Helper', title ] );
	expect( await item.locator( '.nw-nic' ).getAttribute( 'style' ) ).toContain( 'teal' );
	await ctx.close();
} );

test( 'an agent is suggested after @, mentioned, and answers in chat', async ( { browser } ) => {
	addon( true );
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.waitForFunction( () => !! window.nwTestAddon );
	await page.click( '#nw-chat-btn' );
	const text = unique( 'question' );
	await page.locator( '#nw-chat-in' ).pressSequentially( text + ' @Hel' );
	const pick = page.locator( '#nw-mention-list li.nw-active' );
	await expect( pick.locator( '.nw-opt-lbl' ) ).toHaveText( 'Helper' );
	await expect( pick.locator( '.nw-avatar svg' ) ).toHaveCount( 1 ); // the agent's sparkle, not initials
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#nw-chat-in' ) ).toHaveValue( `${ text } @Helper ` );
	await page.keyboard.press( 'Enter' );
	const sent = page.locator( '#nw-msgs .nw-msg', { hasText: text } );
	await expect( sent.locator( '.nw-mention' ) ).toHaveText( '@Helper' );
	const stored = await page.evaluate( async ( t ) => {
		const d = await ( await fetch( window.novawork.restUrl + 'messages?q=' + encodeURIComponent( t ), { headers: { 'X-WP-Nonce': window.novawork.nonce } } ) ).json();
		return d[ 0 ] && d[ 0 ].mentions;
	}, text );
	expect( stored ).toEqual( [ { agent: 'helper', name: 'Helper' } ] );

	// The agent answers through the write API; the app shows it as the agent.
	const answer = unique( 'answer' );
	wpEval( `NovaWork\\Api::post_message( 'helper', '${ answer }' );` );
	const reply = page.locator( '#nw-msgs .nw-msg', { hasText: answer } );
	await expect( reply.locator( '.nw-mwho' ) ).toContainText( 'Helper', { timeout: 15000 } );
	await expect( reply.locator( '.nw-avatar svg' ) ).toHaveCount( 1 );
	await ctx.close();
} );

test( '"Who decides" can name an agent; the person who opened it records it', async ( { browser } ) => {
	addon( true );
	const Q = unique( 'agent decides' );
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1500 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#decisions' );
	await page.waitForFunction( () => !! window.nwTestAddon );
	await page.evaluate( async ( q ) => {
		const h = { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' };
		const d = await ( await fetch( window.novawork.restUrl + 'decisions', { method: 'POST', headers: h, body: JSON.stringify( { question: q } ) } ) ).json();
		window.Alpine.store( 'nw' ).upsert( 'decisions', d );
	}, Q );
	const card = page.locator( '.nw-dec', { hasText: Q } );
	await card.locator( '[data-act="edit-decision"]' ).click();
	// The custom dropdown sits right after the hidden native select.
	await page.click( '#nw-dlg #nw-d-own + .nw-sel .nw-sel-btn' );
	const option = page.locator( '.nw-sel-menu [role="option"]', { hasText: 'Helper' } );
	await expect( option.locator( '.nw-avatar svg' ) ).toHaveCount( 1 );
	await option.click();
	await page.click( '#nw-dlg button[value="save"]' );
	await expect( card.locator( '.nw-foot .nw-avatar' ).first() ).toHaveAttribute( 'title', 'Helper' );
	// Alex opened it, so Alex records it.
	await expect( card.locator( '[data-act="decide"]' ) ).toBeVisible();
	const saved = await page.evaluate( ( q ) => Object.values( window.Alpine.store( 'nw' ).decisions ).find( ( d ) => d.question === q ), Q );
	expect( [ saved.ownerId, saved.ownerAgent ] ).toEqual( [ 0, 'helper' ] );

	// Another member waits on the agent's advice.
	const ctxB = await browser.newContext( { viewport: { width: 1366, height: 1500 } } );
	await signIn( ctxB, 'B' );
	const pb = await ctxB.newPage();
	await pb.goto( APP + '#decisions' );
	const cardB = pb.locator( '.nw-dec', { hasText: Q } );
	await expect( cardB.locator( '.nw-waiting' ) ).toHaveText( 'Waiting on Helper' );
	await expect( cardB.locator( '[data-act="decide"]' ) ).toHaveCount( 0 );
	await ctxB.close();
	await cleanUp( page );
	await ctx.close();
} );

test( 'an add-on page under the NovaWork menu gets the settings look and the page kit', async ( { browser } ) => {
	addon( true );
	const base = process.env.NOVAWORK_URL || 'http://novawork.local';
	const [ user, pass ] = ( process.env.NOVAWORK_ADMIN || 'admin:admin' ).split( ':' );
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 900 } } );
	await ctx.addCookies( [ { name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base } ] );
	await ctx.request.post( base + '/wp-login.php', { form: { log: user, pwd: pass, testcookie: '1' }, maxRedirects: 0 } );
	const page = await ctx.newPage();
	await page.goto( base + '/wp-admin/admin.php?page=novawork-test-addon' );
	await page.waitForFunction( () => !! window.nwTestAddonPage );
	expect( await page.evaluate( () => window.nwTestAddonPage ) ).toEqual( { keys: [ 'Alpine', 'enhance', 'ic', 'icf', 'toast' ], same: true } );
	// The settings screen's look: body class, card, NovaWork's icons.
	await expect( page.locator( 'body' ) ).toHaveClass( /nw-admin-screen/ );
	await expect( page.locator( '#nw-test-page .nw-scard h3 svg.nw-ic' ) ).toHaveCount( 1 );
	expect( await page.locator( '#nw-test-page .nw-scard' ).evaluate( ( el ) => getComputedStyle( el ).borderTopLeftRadius ) ).not.toBe( '0px' );
	// Alpine runs the page; the native select becomes NovaWork's dropdown.
	await expect( page.locator( '#nw-t-picked' ) ).toHaveText( 'b' );
	await expect( page.locator( '#nw-t-pick' ) ).toHaveClass( /nw-vh/ );
	await page.click( '#nw-t-pick + .nw-sel .nw-sel-btn' );
	await page.locator( '.nw-sel-menu [role="option"]', { hasText: 'First' } ).click();
	await expect( page.locator( '#nw-t-picked' ) ).toHaveText( 'a' );
	await page.click( '#nw-t-hello' );
	await expect( page.locator( '.nw-toasts .nw-toast', { hasText: 'Kit toast works' } ) ).toBeVisible();
	await page.screenshot( { path: 'build/browser/addon-page.png' } );
	await ctx.close();
} );

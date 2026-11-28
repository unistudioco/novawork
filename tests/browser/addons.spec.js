/**
 * Extension points for add-ons (1.3.0): the app tells add-ons it is ready,
 * a header button, a team menu row, a notification kind, data on boot and
 * sync, agents in @mentions and in "Who decides", a button on messages and
 * comments, a status line under a thread, a panel, and a page under the
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
	await expect( page.locator( '[id^="nw-x-"], [id^="nw-xm-"], #nw-launchers, .nw-xdock' ) ).toHaveCount( 0 );
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
	expect( seen.keys ).toEqual( [ '__', 'addHeaderButton', 'addLauncher', 'addMenuItem', 'addMessageAction', 'addNoteKind', 'addPanel', 'addShortcut', 'api', 'closePanel', 'esc', 'ic', 'icf', 'isPanelOpen', 'openPanel', 'renderText', 'setThreadStatus', 'sprintf', 'store', 'toast' ] );
	expect( seen.same ).toBe( true );
	expect( seen.boot.hello ).toBe( 'boot' );

	// The header button sits just before the chat button, with a tooltip name.
	const btn = page.locator( '#nw-x-test' );
	await expect( btn ).toBeVisible();
	await expect( btn ).toHaveAttribute( 'aria-label', 'Test add-on' );
	// Right before the chat button (a launcher's phone button, hidden here, may sit between).
	expect( await page.evaluate( () => { let n = document.getElementById( 'nw-x-test' ).nextElementSibling; while ( n && n.classList.contains( 'nw-launch-head' ) ) { n = n.nextElementSibling; } return n && n.id; } ) ).toBe( 'nw-chat-btn' );
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
	// NovaWork's tooltips show on the page (data-tip, title), in its look.
	await page.hover( '#nw-t-help' );
	const tip = page.locator( '.nw-tip.nw-tip-on' );
	await expect( tip ).toHaveText( 'Help from the kit' );
	expect( await tip.evaluate( ( el ) => getComputedStyle( el ).borderRadius ) ).toBe( '8px' );
	await page.screenshot( { path: 'build/browser/addon-page.png' } );
	await ctx.close();
} );

test( 'an add-on’s button sits on its agent’s messages and comments, and toggles', async ( { browser } ) => {
	addon( true );
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1100 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.waitForFunction( () => !! window.nwTestAddon );
	const said = unique( 'agent says' );
	wpEval( `NovaWork\\Api::post_message( 'helper', '${ said }' );` );
	const mine = unique( 'person says' );
	wpEval( `NovaWork\\Api::post_message( get_user_by( 'login', 'alex' )->ID, '${ mine }' );` );
	await page.click( '#nw-chat-btn' );
	const agentMsg = page.locator( '#nw-msgs .nw-msg', { hasText: said } );
	const personMsg = page.locator( '#nw-msgs .nw-msg', { hasText: mine } );
	await expect( agentMsg ).toBeVisible( { timeout: 15000 } );
	await expect( personMsg ).toBeVisible( { timeout: 15000 } );
	// Only where when() says so, after NovaWork's own buttons.
	const btn = agentMsg.locator( '.nw-msg-acts [data-tact="x:test"]' );
	await expect( btn ).toHaveCount( 1 );
	await expect( personMsg.locator( '[data-tact="x:test"]' ) ).toHaveCount( 0 );
	expect( await agentMsg.locator( '.nw-msg-acts button' ).evaluateAll( ( bs ) => bs.map( ( b ) => b.dataset.tact ) ) ).toEqual( [ 'reply', 'x:test' ] );
	await expect( btn ).toHaveAttribute( 'aria-pressed', 'false' );
	await expect( btn ).toHaveAttribute( 'aria-label', 'Test action' );
	// A toggle: pressed shows the icon's fill weight.
	const regular = await btn.innerHTML();
	await agentMsg.hover();
	await btn.click();
	const on = agentMsg.locator( '[data-tact="x:test"]' );
	await expect( on ).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( on ).toHaveClass( /nw-on/ );
	expect( await on.innerHTML() ).not.toBe( regular );
	await agentMsg.hover();
	await on.click();
	await expect( agentMsg.locator( '[data-tact="x:test"]' ) ).toHaveAttribute( 'aria-pressed', 'false' );
	cleanChat();
	await ctx.close();
} );

test( 'an add-on’s status line shows above the chat and comment fields, and clears', async ( { browser } ) => {
	addon( true );
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1100 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.waitForFunction( () => !! window.nwTestStatus );
	await page.click( '#nw-chat-btn' );
	const line = page.locator( '#nw-chat-status' );
	await expect( line ).toBeHidden();
	await page.evaluate( () => window.nwTestStatus( 'chat', 'Helper is thinking…' ) );
	await expect( line ).toBeVisible();
	await expect( line ).toHaveText( 'Helper is thinking…' );
	await expect( line ).toHaveAttribute( 'role', 'status' );
	await expect( line.locator( '.nw-tdots i' ) ).toHaveCount( 3 );
	await page.evaluate( () => window.nwTestStatus( 'chat', null ) );
	await expect( line ).toBeHidden();

	// A task's comments: only that task's line.
	const id = Number( wpEval( "echo ( new NovaWork\\Data\\TaskRepository() )->insert( array( 'title' => 'E2E status line', 'status' => 'next', 'created_by' => get_user_by( 'login', 'alex' )->ID ) );" ).trim() );
	try {
		await page.reload();
		await page.waitForFunction( () => !! window.nwTestStatus );
		await page.evaluate( ( tid ) => {
			window.nwTestStatus( { objectType: 'task', objectId: tid }, 'Helper is reading this task…' );
			window.dispatchEvent( new CustomEvent( 'nw:open', { detail: { kind: 'task', id: tid } } ) );
		}, id );
		const cmt = page.locator( '#nw-dlg #nw-cmt-status' );
		await expect( cmt ).toHaveText( 'Helper is reading this task…' );
		await page.evaluate( ( tid ) => window.nwTestStatus( { objectType: 'task', objectId: tid + 1 }, 'Another task' ), id );
		await expect( cmt ).toHaveText( 'Helper is reading this task…' );
		await page.evaluate( ( tid ) => window.nwTestStatus( { objectType: 'task', objectId: tid }, null ), id );
		await expect( cmt ).toBeHidden();
	} finally {
		wpEval( `( new NovaWork\\Data\\TaskRepository() )->delete( ${ id } );` );
	}
	await ctx.close();
} );

test( 'an add-on’s panel opens like the app’s dialogs and locks the page behind it', async ( { browser } ) => {
	addon( true );
	for ( const [ w, h, touch ] of [ [ 1366, 900, false ], [ 390, 844, true ] ] ) {
		const ctx = await browser.newContext( { viewport: { width: w, height: h }, hasTouch: touch, isMobile: touch } );
		await signIn( ctx, 'A' );
		const page = await ctx.newPage();
		await page.goto( APP + '#focus' );
		await page.waitForFunction( () => !! window.nwTestAddon );
		expect( await page.evaluate( () => window.novawork.app.openPanel( 'nope' ) ) ).toBe( false );
		await page.evaluate( () => window.novawork.app.openPanel( 'test' ) );
		const panel = page.locator( 'dialog#nw-xpanel[open]' );
		await expect( panel ).toBeVisible();
		await expect( panel.locator( '#nw-xpanel-title' ) ).toHaveText( 'Test panel' );
		await expect( panel.locator( '#nw-test-panel-text' ) ).toHaveText( 'Panel works' );
		await expect( page.locator( 'html' ) ).toHaveClass( /nw-scroll-locked/ );
		if ( touch ) {
			// A bottom sheet: full width, at the bottom, with the grab handle
			// (measured once it has slid up).
			await panel.evaluate( ( el ) => Promise.all( el.getAnimations().map( ( a ) => a.finished ) ) );
			const box = await panel.boundingBox();
			expect( Math.round( box.width ) ).toBe( w );
			expect( Math.round( box.y + box.height ) ).toBe( h );
			await expect( panel.locator( '.nw-sgrab' ) ).toBeVisible();
		}
		await page.screenshot( { path: `build/browser/addon-panel-${ w }.png` } );
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( 'dialog#nw-xpanel[open]' ) ).toHaveCount( 0 );
		await expect( page.locator( 'html' ) ).not.toHaveClass( /nw-scroll-locked/ );
		// The close button and the backdrop close it too.
		await page.evaluate( () => window.novawork.app.openPanel( 'test' ) );
		await page.locator( '#nw-xpanel [data-close]' ).click();
		await expect( page.locator( 'dialog#nw-xpanel[open]' ) ).toHaveCount( 0 );
		if ( ! touch ) {
			await page.evaluate( () => window.novawork.app.openPanel( 'test' ) );
			await page.mouse.click( 6, h / 2 );
			await expect( page.locator( 'dialog#nw-xpanel[open]' ) ).toHaveCount( 0 );
		}
		await ctx.close();
	}
} );

test( 'an add-on’s launcher opens a docked panel; it docks opposite the chat, floats on narrower screens, and is a sheet on phones', async ( { browser } ) => {
	addon( true );
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 900 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.waitForFunction( () => !! window.nwTestLauncher );
	await page.evaluate( () => window.localStorage.removeItem( 'nw.dock.testdock' ) );
	const launcher = page.locator( '#nw-launch-test' );
	await expect( launcher ).toBeVisible();
	await expect( launcher ).toHaveAttribute( 'aria-label', 'Test launcher' );
	const box = await launcher.boundingBox();
	expect( 1366 - ( box.x + box.width ) ).toBeLessThan( 40 );
	expect( 900 - ( box.y + box.height ) ).toBeLessThan( 40 );
	// The badge: a count, a dot, none.
	await page.evaluate( () => window.nwTestLauncher.setBadge( 3 ) );
	await expect( launcher.locator( '.nw-launch-badge' ) ).toHaveText( '3' );
	await page.evaluate( () => window.nwTestLauncher.setBadge( true ) );
	await expect( launcher.locator( '.nw-dot-alert' ) ).toHaveCount( 1 );
	await page.evaluate( () => window.nwTestLauncher.setBadge( 0 ) );
	await expect( launcher.locator( '.nw-dot-alert, .nw-launch-badge' ) ).toHaveCount( 0 );

	// Opens docked on the right; the page makes room; the text renders like chat.
	await launcher.click();
	const dock = page.locator( '#nw-xdock-testdock' );
	await expect( dock ).toBeVisible();
	await expect( dock ).toHaveClass( /nw-dock-right/ );
	await expect( dock.locator( 'header h2' ) ).toHaveText( 'Test dock' );
	await expect( dock.locator( '#nw-test-dock-text .nw-mention' ) ).toHaveCount( 1 );
	expect( await page.evaluate( () => document.body.classList.contains( 'nw-xdocked-right' ) ) ).toBe( true );
	await expect( dock.locator( '#nw-test-dock-in' ) ).toBeFocused();
	await expect( launcher ).toBeVisible();
	// It slides left of the docked panel (a short transition).
	await expect.poll( async () => { const b = await launcher.boundingBox(); return b.x + b.width; } ).toBeLessThan( 1366 - 376 );
	// The team chat docked on the right (its default) sends the panel to the left.
	await page.click( '#nw-chat-btn' );
	await expect( page.locator( '#nw-chat' ) ).toBeVisible();
	await expect( dock ).toHaveClass( /nw-dock-left/ );
	// The page makes room on both sides (after its short transition).
	await expect.poll( () => page.evaluate( () => [ getComputedStyle( document.body ).paddingLeft, getComputedStyle( document.body ).paddingRight ].join( ' ' ) ) ).toBe( '376px 376px' );
	await page.screenshot( { path: 'build/browser/dock-both-1366.png' } );
	await page.click( '#nw-chat-close' );
	await expect( dock ).toHaveClass( /nw-dock-right/ );
	// Escape closes it; it was drawn once and keeps its content.
	await dock.locator( '#nw-test-dock-in' ).fill( 'kept' );
	await page.keyboard.press( 'Escape' );
	await expect( dock ).toBeHidden();
	expect( await page.evaluate( () => document.body.classList.contains( 'nw-xdocked-right' ) ) ).toBe( false );
	// The H shortcut opens it again; N stays the app's.
	// Focus went back to the launcher; H works from there.
	await expect( launcher ).toBeFocused();
	await page.keyboard.press( 'h' );
	await expect( dock ).toBeVisible();
	await expect( dock.locator( '#nw-test-dock-in' ) ).toHaveValue( 'kept' );
	expect( await page.evaluate( () => [ window.nwTestDockRenders, window.nwTestDockOpens, window.nwTestTaken ] ) ).toEqual( [ 1, 2, null ] );
	await page.click( '#nw-xdock-testdock [data-close]' );
	await expect( dock ).toBeHidden();
	await ctx.close();

	// Below 1100px it floats in a corner.
	const mid = await browser.newContext( { viewport: { width: 1000, height: 800 } } );
	await signIn( mid, 'A' );
	const p2 = await mid.newPage();
	await p2.goto( APP + '#focus' );
	await p2.waitForFunction( () => !! window.nwTestLauncher );
	await p2.locator( '#nw-launch-test' ).click();
	await expect( p2.locator( '#nw-xdock-testdock' ) ).toHaveClass( /nw-float/ );
	expect( await p2.evaluate( () => document.body.classList.contains( 'nw-xdocked-right' ) || document.body.classList.contains( 'nw-xdocked-left' ) ) ).toBe( false );
	await p2.screenshot( { path: 'build/browser/dock-float-1000.png' } );
	await mid.close();

	// Phones: the launcher is a header button; the panel is a bottom sheet.
	const phone = await browser.newContext( { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } );
	await signIn( phone, 'A' );
	const p3 = await phone.newPage();
	await p3.goto( APP + '#focus' );
	await p3.waitForFunction( () => !! window.nwTestLauncher );
	await expect( p3.locator( '#nw-launch-test' ) ).toBeHidden();
	await expect( p3.locator( '#nw-launch-h-test' ) ).toBeVisible();
	await p3.locator( '#nw-launch-h-test' ).click();
	const sheet = p3.locator( '#nw-xdock-testdock' );
	await expect( sheet ).toBeVisible();
	const sb = await sheet.boundingBox();
	expect( Math.round( sb.y + sb.height ) ).toBeGreaterThanOrEqual( 843 );
	await expect( p3.locator( '.nw-xdock-scrim' ) ).toBeVisible();
	await p3.screenshot( { path: 'build/browser/dock-sheet-390.png' } );
	await p3.locator( '.nw-xdock-scrim' ).click( { position: { x: 20, y: 20 } } );
	await expect( sheet ).toBeHidden();
	await phone.close();
} );


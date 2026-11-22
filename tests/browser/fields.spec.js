/**
 * Owner's fixes, 2026-11-22: selecting text in a dialog never closes it,
 * message fields take Shift+Enter as a new line, Focus slots print no empty
 * meta row, and a Board card without a project has no empty top row.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, serverData, newTask, unique, cleanUp, cleanChat } from './helpers.js';

test.beforeEach( async ( { context, page } ) => {
	await signIn( context );
	await page.goto( APP + '#board' );
	await page.locator( '.nw-board' ).waitFor();
} );

test.afterEach( async ( { page } ) => {
	if ( ! page.isClosed() ) {
		await cleanUp( page );
	}
} );

test( 'selecting text in a dialog and letting go over the backdrop keeps it open', async ( { page } ) => {
	await page.keyboard.press( 'n' );
	await page.fill( '#nw-f-title', 'Some words to select' );
	const field = await page.locator( '#nw-f-title' ).boundingBox();
	const box = await page.locator( '#nw-dlg-form' ).boundingBox();
	// Press inside the title, drag out past the dialog, release on the backdrop.
	await page.mouse.move( field.x + 20, field.y + field.height / 2 );
	await page.mouse.down();
	await page.mouse.move( box.x + box.width + 120, field.y + field.height / 2, { steps: 8 } );
	await page.mouse.up();
	await page.waitForTimeout( 400 );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 );

	// A real click on the backdrop still closes it.
	await page.mouse.click( box.x + box.width + 120, box.y + 20 );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 0 );
} );

test( 'chat: Shift+Enter starts a new line, Enter sends', async ( { page } ) => {
	const T = unique( 'lines' );
	try {
		await page.evaluate( () => window.dispatchEvent( new CustomEvent( 'nw:chat', { detail: { open: true } } ) ) );
		await page.click( '#nw-chat-in' );
		await page.keyboard.type( T );
		await page.keyboard.press( 'Shift+Enter' );
		await page.keyboard.type( 'second line' );
		const one = 32;
		expect( await page.$eval( '#nw-chat-in', ( e ) => e.offsetHeight ) ).toBeGreaterThan( one );
		await page.keyboard.press( 'Enter' );
		await expect.poll( async () => ( await serverData( page ) ).messages.some( ( m ) => m.text === T + '\nsecond line' ) ).toBe( true );
		await expect( page.locator( '#nw-chat-in' ) ).toHaveValue( '' );
		expect( await page.$eval( '#nw-chat-in', ( e ) => e.offsetHeight ) ).toBe( one );
	} finally {
		cleanChat();
	}
} );

test( 'comments: Shift+Enter starts a new line, Enter posts', async ( { page } ) => {
	const T = unique( 'comment lines' );
	await newTask( page, T );
	await page.locator( 'article', { hasText: T } ).click();
	await page.click( '#nw-cmt-in' );
	await page.keyboard.type( 'first' );
	await page.keyboard.press( 'Shift+Enter' );
	await page.keyboard.type( 'second' );
	await expect( page.locator( '#nw-cmt-in' ) ).toHaveValue( 'first\nsecond' );
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#nw-cmts .nw-msg .nw-txt' ) ).toHaveText( 'first\nsecond' );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 );
} );

test( 'no empty rows: a Focus slot without meta, a Board card without a project', async ( { page } ) => {
	const T = unique( 'bare' );
	// New tasks default to a project, so make this one through the API.
	await page.evaluate( async ( title ) => {
		const me = window.Alpine.store( 'nw' ).me;
		await fetch( window.novawork.restUrl + 'tasks', { method: 'POST', headers: { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify( { title, status: 'now', assignees: [ me ] } ) } );
	}, T );
	const card = page.locator( 'article', { hasText: T } );
	await expect( card.locator( '.nw-ctop.nw-ctop-t .nw-t' ) ).toHaveText( T );
	await expect( card.locator( '.nw-chip' ) ).toHaveCount( 0 );

	await page.goto( APP + '#focus' );
	const slot = page.locator( '.nw-slot', { hasText: T } );
	await slot.waitFor();
	await expect( slot.locator( '.nw-meta' ) ).toHaveCount( 0 );
	await expect( page.locator( '.nw-meta:empty' ) ).toHaveCount( 0 );
} );

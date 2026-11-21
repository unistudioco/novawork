/**
 * Decisions and comments in a real browser, as member A.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, serverData, unique, cleanUp } from './helpers.js';

test.beforeEach( async ( { context, page } ) => {
	await signIn( context );
	await page.goto( APP + '#decisions' );
	await page.locator( '.nw-dec-cols' ).waitFor();
} );

test.afterEach( async ( { page } ) => {
	await cleanUp( page );
} );

test( 'open, decide, comment, reopen and delete a decision', async ( { page } ) => {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	const Q = unique( 'decision' );
	const find = async () => ( await serverData( page ) ).decisions.find( ( d ) => d.question === Q );

	await page.click( '[data-act="new-decision"]' );
	await page.fill( '#nw-d-q', Q );
	await page.fill( '#nw-d-o', 'Option A or option B' );
	await page.click( '#nw-dlg button[value="save"]' );
	const card = page.locator( '.nw-dec', { hasText: Q } );
	await expect( card ).toHaveCount( 1 );
	await expect( page.locator( '.nw-dec-cols section' ).first().locator( '.nw-dec', { hasText: Q } ) ).toHaveCount( 1 );

	// Deciding needs an answer.
	await card.locator( '[data-act="decide"]' ).click();
	await page.click( '#nw-dlg button[value="save"]' );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 );
	await page.fill( '#nw-d-a', 'Option B, because it is simpler.' );
	await page.click( '#nw-dlg button[value="save"]' );
	await expect( page.locator( '.nw-dec-cols section' ).nth( 1 ).locator( '.nw-dec.nw-decided', { hasText: Q } ) ).toHaveCount( 1 );
	await expect.poll( async () => ( await find() )?.status ).toBe( 'decided' );

	// Comment, then reopen.
	await page.locator( '.nw-dec', { hasText: Q } ).locator( '[data-act="edit-decision"]' ).click();
	await page.fill( '#nw-cmt-in', 'Agreed.' );
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#nw-cmts .nw-msg', { hasText: 'Agreed.' } ) ).toHaveCount( 1 );
	await page.check( '#nw-dlg input[name="reopen"]' );
	await page.click( '#nw-dlg button[value="save"]' );
	await expect.poll( async () => ( await find() )?.status ).toBe( 'open' );
	await expect( page.locator( '.nw-dec', { hasText: Q } ).locator( '.nw-cnt' ) ).toHaveText( '1' );

	// Delete with Undo, then let it go.
	await page.locator( '.nw-dec', { hasText: Q } ).locator( '[data-act="edit-decision"]' ).click();
	await page.click( '#nw-dlg [data-del]' );
	await page.click( '#nw-dlg [data-del]' );
	await expect( page.locator( '.nw-dec', { hasText: Q } ) ).toHaveCount( 0 );
	await page.click( '.nw-toast-act' );
	await expect( page.locator( '.nw-dec', { hasText: Q } ) ).toHaveCount( 1 );
	expect( errors ).toEqual( [] );
} );

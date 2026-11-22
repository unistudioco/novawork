/**
 * Subtasks (owner request, 2026-11-22): a checklist in the task dialog,
 * saved with a new task or changed live on a saved one, with "2/3" on the
 * board card.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, serverData, unique, cleanUp } from './helpers.js';

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

const subs = async ( page, title ) => ( await serverData( page ) ).tasks.find( ( t ) => t.title === title )?.subtasks || [];

test( 'a new task saves its checklist; tick, rename, assign, remove and Undo on a saved one', async ( { page } ) => {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	const T = unique( 'checklist' );

	// New task: the checklist is a draft saved with it. Enter adds, never submits.
	await page.keyboard.press( 'n' );
	await page.fill( '#nw-f-title', T );
	for ( const s of [ 'Outline', 'Draft', 'Review' ] ) {
		await page.fill( '#nw-sub-in', s );
		await page.keyboard.press( 'Enter' );
	}
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 );
	await expect( page.locator( '#nw-subs .nw-sub' ) ).toHaveCount( 3 );
	await page.click( '#nw-dlg button[value="save"]' );
	await expect.poll( async () => ( await subs( page, T ) ).map( ( s ) => s.title ) ).toEqual( [ 'Outline', 'Draft', 'Review' ] );

	// The card shows progress.
	const card = page.locator( 'article', { hasText: T } );
	await expect( card.locator( '.nw-subn' ) ).toContainText( '0/3' );

	// Saved task: tick, rename, assign.
	await card.click();
	const row = ( name ) => page.locator( '#nw-subs .nw-sub', { hasText: name } );
	await row( 'Outline' ).locator( '[data-sub="tick"]' ).click();
	await expect.poll( async () => ( await subs( page, T ) ).find( ( s ) => s.title === 'Outline' )?.done ).toBe( true );
	await expect( page.locator( '.nw-subs-count' ) ).toHaveText( ' · 1/3' );

	await row( 'Draft' ).locator( '.nw-sub-t' ).click();
	await page.keyboard.press( 'ControlOrMeta+a' );
	await page.keyboard.type( 'First draft' );
	await page.keyboard.press( 'Enter' );
	await expect.poll( async () => ( await subs( page, T ) ).map( ( s ) => s.title ) ).toEqual( [ 'Outline', 'First draft', 'Review' ] );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 );

	await row( 'Review' ).hover();
	await row( 'Review' ).locator( '.nw-sel-btn' ).click();
	await page.locator( '#nw-subs .nw-sel-menu li[aria-selected="false"]' ).nth( 1 ).click();
	await expect.poll( async () => ( await subs( page, T ) ).find( ( s ) => s.title === 'Review' )?.assigneeId ).toBeGreaterThan( 0 );

	// Escape while renaming cancels the rename, not the dialog.
	await row( 'Review' ).locator( '.nw-sub-t' ).click();
	await page.keyboard.type( 'nope' );
	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 );
	await expect( row( 'Review' ) ).toHaveCount( 1 );

	// Remove, then Undo puts it back in its place, ticked as before.
	await row( 'Outline' ).hover();
	await row( 'Outline' ).locator( '[data-sub="remove"]' ).click();
	await expect.poll( async () => ( await subs( page, T ) ).length ).toBe( 2 );
	await page.locator( '.nw-toast-act' ).click();
	await expect.poll( async () => ( await subs( page, T ) ).map( ( s ) => s.title + ( s.done ? ' ✓' : '' ) ) ).toEqual( [ 'Outline ✓', 'First draft', 'Review' ] );

	await page.keyboard.press( 'Escape' );
	await expect( card.locator( '.nw-subn' ) ).toContainText( '1/3' );
	expect( errors ).toEqual( [] );
} );

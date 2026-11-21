/**
 * Board, editors, Undo and toasts in a real browser, as member A.
 * Everything a test creates it deletes again.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, serverData, pointerDrag, newTask, deleteTask } from './helpers.js';

test.beforeEach( async ( { context, page } ) => {
	await signIn( context );
	await page.goto( APP + '#board' );
	await page.locator( '.nw-board' ).waitFor();
} );

test( 'create, edit, drag, comment and delete a task', async ( { page } ) => {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );

	await newTask( page, 'E2E task' );
	await expect( page.locator( '.nw-lane.nw-s-now article', { hasText: 'E2E task' } ) ).toHaveCount( 1 );

	await page.locator( 'article', { hasText: 'E2E task' } ).click();
	await page.fill( '#nw-f-title', 'E2E task edited' );
	await page.locator( '#nw-dlg .nw-sel-btn' ).first().click();
	await page.locator( '#nw-dlg .nw-sel-menu li', { hasText: 'High' } ).click();
	await page.click( '#nw-dlg button[value="save"]' );
	await expect.poll( async () => ( await serverData( page ) ).tasks.find( ( t ) => t.title === 'E2E task edited' )?.priority ).toBe( 'high' );

	await pointerDrag( page, page.locator( 'article', { hasText: 'E2E task edited' } ), page.locator( '.nw-lane.nw-s-later header' ) );
	await expect( page.locator( '.nw-lane.nw-s-later article', { hasText: 'E2E task edited' } ) ).toHaveCount( 1 );
	await expect.poll( async () => ( await serverData( page ) ).tasks.find( ( t ) => t.title === 'E2E task edited' )?.status ).toBe( 'later' );

	await page.locator( 'article', { hasText: 'E2E task edited' } ).click();
	await page.fill( '#nw-cmt-in', 'A comment from the browser' );
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#nw-cmts .nw-msg', { hasText: 'A comment from the browser' } ) ).toHaveCount( 1 );

	await page.click( '#nw-dlg [data-del]' );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 ); // first click only arms
	await page.click( '#nw-dlg [data-del]' );
	await expect( page.locator( 'article', { hasText: 'E2E task edited' } ) ).toHaveCount( 0 );
	await page.locator( '.nw-toast-act' ).waitFor();
	await page.mouse.move( 5, 5 );
	await expect.poll( async () => ( await serverData( page ) ).tasks.some( ( t ) => t.title === 'E2E task edited' ), { timeout: 12000 } ).toBe( false );
	expect( await page.evaluate( () => document.documentElement.classList.contains( 'nw-scroll-locked' ) ) ).toBe( false );
	expect( errors ).toEqual( [] );
} );

test( 'Undo restores a deleted task and never deletes it', async ( { page } ) => {
	await newTask( page, 'E2E undo' );
	await deleteTask( page, 'E2E undo' );
	await expect( page.locator( 'article', { hasText: 'E2E undo' } ) ).toHaveCount( 0 );
	await expect( page.locator( '.nw-toast', { hasText: 'Deleted.' } ).locator( '.nw-toast-act' ) ).toHaveText( 'Undo' );
	await page.click( '.nw-toast-act' );
	await expect( page.locator( 'article', { hasText: 'E2E undo' } ) ).toHaveCount( 1 );
	await page.waitForTimeout( 6500 );
	expect( ( await serverData( page ) ).tasks.some( ( t ) => t.title === 'E2E undo' ) ).toBe( true );

	// Clean up, letting the toast run out this time.
	await deleteTask( page, 'E2E undo' );
	await page.mouse.move( 5, 5 );
	await expect.poll( async () => ( await serverData( page ) ).tasks.some( ( t ) => t.title === 'E2E undo' ), { timeout: 12000 } ).toBe( false );
} );

test( 'closing the tab during the Undo window still deletes', async ( { context, page } ) => {
	await newTask( page, 'E2E close tab' );
	await deleteTask( page, 'E2E close tab' );
	await page.close();
	const again = await context.newPage();
	await again.goto( APP );
	await again.waitForTimeout( 1000 );
	expect( ( await serverData( again ) ).tasks.some( ( t ) => t.title === 'E2E close tab' ) ).toBe( false );
} );

test( 'toasts stack, newest at the bottom, and leave one by one', async ( { page } ) => {
	await newTask( page, 'E2E toast 1' );
	await newTask( page, 'E2E toast 2' );
	const toasts = page.locator( '.nw-toasts .nw-toast' );
	await expect( toasts ).toHaveCount( 2 );
	const [ a, b ] = await Promise.all( [ toasts.nth( 0 ).boundingBox(), toasts.nth( 1 ).boundingBox() ] );
	expect( b.y ).toBeGreaterThan( a.y ); // newest below
	await page.screenshot( { path: 'build/browser/toasts-stacked.png', clip: { x: 383, y: 1250, width: 600, height: 250 } } );
	await expect( toasts ).toHaveCount( 0, { timeout: 6000 } );
	for ( const title of [ 'E2E toast 1', 'E2E toast 2' ] ) {
		await deleteTask( page, title );
	}
	await page.mouse.move( 5, 5 );
	await expect.poll( async () => ( await serverData( page ) ).tasks.filter( ( t ) => t.title.startsWith( 'E2E toast' ) ).length, { timeout: 14000 } ).toBe( 0 );
} );

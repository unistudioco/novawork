/**
 * Board, editors, Undo and toasts in a real browser, as member A.
 * Everything a test creates it deletes again.
 */
import { test, expect } from './offline.js';
import { APP, signIn, serverData, pointerDrag, newTask, deleteTask, unique, cleanUp } from './helpers.js';

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

test( 'create, edit, drag, comment and delete a task', async ( { page } ) => {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );

	const T = unique( 'task' );
	const E = T + ' edited';
	await newTask( page, T );
	await expect( page.locator( '.nw-lane.nw-s-now article', { hasText: T } ) ).toHaveCount( 1 );

	await page.locator( 'article', { hasText: T } ).click();
	await page.fill( '#nw-f-title', E );
	await page.locator( '#nw-dlg .nw-sel-btn' ).first().click();
	await page.locator( '#nw-dlg .nw-sel-menu li', { hasText: 'High' } ).click();
	await page.click( '#nw-dlg button[value="save"]' );
	await expect.poll( async () => ( await serverData( page ) ).tasks.find( ( t ) => t.title === E )?.priority ).toBe( 'high' );

	// The dialog animates out and the board redraws: wait for both before dragging.
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 0 );
	await expect( page.locator( 'article', { hasText: E } ) ).toBeVisible();
	await pointerDrag( page, page.locator( 'article', { hasText: E } ), page.locator( '.nw-lane.nw-s-later header' ) );
	await expect( page.locator( '.nw-lane.nw-s-later article', { hasText: E } ) ).toHaveCount( 1 );
	await expect.poll( async () => ( await serverData( page ) ).tasks.find( ( t ) => t.title === E )?.status ).toBe( 'later' );

	await page.locator( 'article', { hasText: E } ).click();
	await page.fill( '#nw-cmt-in', 'A comment from the browser' );
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#nw-cmts .nw-msg', { hasText: 'A comment from the browser' } ) ).toHaveCount( 1 );

	await page.click( '#nw-dlg [data-del]' );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 ); // first click only arms
	await page.click( '#nw-dlg [data-del]' );
	await expect( page.locator( 'article', { hasText: E } ) ).toHaveCount( 0 );
	await page.locator( '.nw-toast-act' ).waitFor();
	await page.mouse.move( 5, 5 );
	await expect.poll( async () => ( await serverData( page ) ).tasks.some( ( t ) => t.title === E ), { timeout: 12000 } ).toBe( false );
	expect( await page.evaluate( () => document.documentElement.classList.contains( 'nw-scroll-locked' ) ) ).toBe( false );
	expect( errors ).toEqual( [] );
} );

test( 'Undo restores a deleted task and never deletes it', async ( { page } ) => {
	const U = unique( 'undo' );
	await newTask( page, U );
	await deleteTask( page, U );
	await expect( page.locator( 'article', { hasText: U } ) ).toHaveCount( 0 );
	await expect( page.locator( '.nw-toast', { hasText: 'Deleted.' } ).locator( '.nw-toast-act' ) ).toHaveText( 'Undo' );
	await page.click( '.nw-toast-act' );
	await expect( page.locator( 'article', { hasText: U } ) ).toHaveCount( 1 );
	await page.waitForTimeout( 6500 );
	expect( ( await serverData( page ) ).tasks.some( ( t ) => t.title === U ) ).toBe( true );

	// Clean up, letting the toast run out this time.
	await deleteTask( page, U );
	await page.mouse.move( 5, 5 );
	await expect.poll( async () => ( await serverData( page ) ).tasks.some( ( t ) => t.title === U ), { timeout: 12000 } ).toBe( false );
} );

test( 'closing the tab during the Undo window still deletes', async ( { context, page } ) => {
	const C = unique( 'close tab' );
	await newTask( page, C );
	await deleteTask( page, C );
	await page.close();
	const again = await context.newPage();
	await again.goto( APP );
	await again.waitForTimeout( 1000 );
	expect( ( await serverData( again ) ).tasks.some( ( t ) => t.title === C ) ).toBe( false );
} );

test( 'toasts stack, newest at the bottom, and leave one by one', async ( { page } ) => {
	const A = unique( 'toast 1' );
	const B = unique( 'toast 2' );
	await newTask( page, A );
	await newTask( page, B );
	const toasts = page.locator( '.nw-toasts .nw-toast' );
	await expect( toasts ).toHaveCount( 2 );
	// Both read in the same frame: two separate reads can straddle the glide
	// that moves the first toast up as the second rises in, or its exit.
	const tops = await page.evaluate( () => [ ...document.querySelectorAll( '.nw-toasts .nw-toast' ) ].map( ( t ) => t.getBoundingClientRect().top ) );
	expect( tops ).toHaveLength( 2 );
	expect( tops[ 1 ] ).toBeGreaterThan( tops[ 0 ] ); // newest below
	await page.screenshot( { path: 'build/browser/toasts-stacked.png', clip: { x: 383, y: 1250, width: 600, height: 250 } } );
	await expect( toasts ).toHaveCount( 0, { timeout: 6000 } );
	for ( const title of [ A, B ] ) {
		await deleteTask( page, title );
	}
	await page.mouse.move( 5, 5 );
	await expect.poll( async () => ( await serverData( page ) ).tasks.filter( ( t ) => [ A, B ].includes( t.title ) ).length, { timeout: 14000 } ).toBe( 0 );
} );

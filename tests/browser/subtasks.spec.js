/**
 * Subtasks (owner request, 2026-11-22): a checklist in the task dialog,
 * saved with a new task or changed live on a saved one, with "2/3" on the
 * board card.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, serverData, unique, cleanUp, wpEval } from './helpers.js';

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
		await page.fill( '#nw-subtask-in', s );
		await page.keyboard.press( 'Enter' );
	}
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 );
	await expect( page.locator( '#nw-subtasks .nw-subtask' ) ).toHaveCount( 3 );
	await page.click( '#nw-dlg button[value="save"]' );
	await expect.poll( async () => ( await subs( page, T ) ).map( ( s ) => s.title ) ).toEqual( [ 'Outline', 'Draft', 'Review' ] );

	// The card shows progress.
	const card = page.locator( 'article', { hasText: T } );
	await expect( card.locator( '.nw-subtask-n' ) ).toContainText( '0/3' );

	// Saved task: tick, rename, assign.
	await card.click();
	const row = ( name ) => page.locator( '#nw-subtasks .nw-subtask', { hasText: name } );
	await row( 'Outline' ).locator( '[data-sub="tick"]' ).click();
	await expect.poll( async () => ( await subs( page, T ) ).find( ( s ) => s.title === 'Outline' )?.done ).toBe( true );
	await expect( page.locator( '.nw-subtasks-count' ) ).toHaveText( ' · 1/3' );

	await row( 'Draft' ).locator( '.nw-subtask-t' ).click();
	await page.keyboard.press( 'ControlOrMeta+a' );
	await page.keyboard.type( 'First draft' );
	await page.keyboard.press( 'Enter' );
	await expect.poll( async () => ( await subs( page, T ) ).map( ( s ) => s.title ) ).toEqual( [ 'Outline', 'First draft', 'Review' ] );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 );

	await row( 'Review' ).hover();
	await row( 'Review' ).locator( '.nw-sel-btn' ).click();
	await page.locator( '#nw-subtasks .nw-sel-menu li[aria-selected="false"]' ).nth( 1 ).click();
	await expect.poll( async () => ( await subs( page, T ) ).find( ( s ) => s.title === 'Review' )?.assigneeId ).toBeGreaterThan( 0 );

	// Escape while renaming cancels the rename, not the dialog.
	await row( 'Review' ).locator( '.nw-subtask-t' ).click();
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
	await expect( card.locator( '.nw-subtask-n' ) ).toContainText( '1/3' );
	expect( errors ).toEqual( [] );
} );

test( 'an open “Who does” menu stays open when a poll brings the task back unchanged', async ( { page } ) => {
	// A poll that brings the task (here: its notes changed elsewhere) used to
	// rebuild the checklist and close the menu under the pointer (1.2.0 fix).
	const T = unique( 'menu stays' );
	const id = Number( wpEval( `$r = new NovaWork\\Data\\TaskRepository(); $id = $r->insert( array( 'title' => '${ T }', 'status' => 'next', 'created_by' => get_user_by( 'login', 'alex' )->ID ) ); ( new NovaWork\\Data\\SubtaskRepository() )->insert( array( 'task_id' => $id, 'title' => 'Pick someone', 'sort_order' => 1 ) ); echo $id;` ).trim() );
	try {
		await page.reload();
		await page.evaluate( ( tid ) => window.dispatchEvent( new CustomEvent( 'nw:open', { detail: { kind: 'task', id: tid } } ) ), id );
		const row = page.locator( '#nw-subtasks .nw-subtask', { hasText: 'Pick someone' } );
		await row.hover();
		await row.locator( '.nw-sel-btn' ).click();
		const menu = page.locator( '#nw-subtasks .nw-sel-menu:visible' );
		await expect( menu ).toHaveCount( 1 );
		wpEval( `( new NovaWork\\Data\\TaskRepository() )->update( ${ id }, array( 'notes' => 'Changed elsewhere' ) );` );
		await expect.poll( () => page.evaluate( ( tid ) => window.Alpine.store( 'nw' ).tasks[ tid ]?.notes, id ), { timeout: 15000 } ).toBe( 'Changed elsewhere' );
		await expect( menu ).toHaveCount( 1 );
		// A real change to the checklist still shows.
		wpEval( `( new NovaWork\\Data\\SubtaskRepository() )->insert( array( 'task_id' => ${ id }, 'title' => 'Added elsewhere', 'sort_order' => 2 ) ); ( new NovaWork\\Data\\TaskRepository() )->touch( ${ id } );` );
		await expect( page.locator( '#nw-subtasks .nw-subtask', { hasText: 'Added elsewhere' } ) ).toHaveCount( 1, { timeout: 15000 } );
	} finally {
		wpEval( `( new NovaWork\\Data\\TaskRepository() )->delete( ${ id } );` );
	}
} );

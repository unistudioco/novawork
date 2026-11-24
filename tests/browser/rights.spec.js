/**
 * Edit rights (owner, 1.2.0): members change tasks they created or are
 * assigned to, and projects and decisions they created; someone else's work
 * opens read only (comments, votes and tracking stay open).
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, unique, cleanUp } from './helpers.js';

async function open( browser, who ) {
	const context = await browser.newContext( { viewport: { width: 1366, height: 1500 } } );
	await context.addInitScript( () => {
		try {
			localStorage.setItem( 'nw.fPerson', 'all' );
			localStorage.setItem( 'nw.fProject', 'all' );
		} catch ( e ) {}
	} );
	await signIn( context, who );
	const page = await context.newPage();
	await page.goto( APP + '#board' );
	await page.locator( '.nw-board' ).waitFor();
	return page;
}

const api = ( page, method, path, body ) =>
	page.evaluate(
		async ( [ m, p, b ] ) => {
			const r = await fetch( window.novawork.restUrl + p, { method: m, headers: { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' }, body: b ? JSON.stringify( b ) : undefined } );
			return { status: r.status, body: await r.json() };
		},
		[ method, path, body ]
	);

test( "someone else's task opens read only: no drag, no Save, but comments and your own subtask work", async ( { browser } ) => {
	const alex = await open( browser, 'A' );
	const sam = await open( browser, 'B' );
	const title = unique( 'not yours' );
	try {
		const samId = await sam.evaluate( () => window.novawork.me );
		const made = await api( alex, 'POST', 'tasks', { title, status: 'now' } );
		const id = made.body.id;
		await api( alex, 'POST', `tasks/${ id }/subtasks`, { title: 'Alex only' } );
		await api( alex, 'POST', `tasks/${ id }/subtasks`, { title: 'For Sam', assigneeId: samId } );
		await sam.reload();
		const card = sam.locator( `article[data-id="${ id }"]` );
		await expect( card ).toHaveAttribute( 'draggable', 'false' );
		await card.click();
		const dlg = sam.locator( '#nw-dlg[open]' );
		await expect( dlg.locator( '#nw-f-title' ) ).toBeDisabled();
		await expect( dlg.locator( 'button[value="save"]' ) ).toHaveCount( 0 );
		await expect( dlg.locator( '[data-del]' ) ).toHaveCount( 0 );
		await expect( dlg.locator( '#nw-subtask-in' ) ).toBeHidden();
		await expect( dlg.locator( '.nw-subtask-ro', { hasText: 'Alex only' } ).locator( '.nw-subtask-check' ) ).toBeDisabled();
		await dlg.locator( '.nw-subtask-ro', { hasText: 'For Sam' } ).locator( '.nw-subtask-check' ).click();
		await expect( dlg.locator( '.nw-subtask-ro.nw-done', { hasText: 'For Sam' } ) ).toHaveCount( 1 );
		// Comments stay open to everyone.
		await expect( dlg.locator( '#nw-cmt-in' ) ).toBeEnabled();

		// The server says the same, whatever the page does.
		expect( ( await api( sam, 'PATCH', `tasks/${ id }`, { title: 'Mine now' } ) ).status ).toBe( 403 );

		// Once Sam is on it, it's his to change.
		await api( alex, 'PATCH', `tasks/${ id }`, { assignees: [ samId ] } );
		await sam.keyboard.press( 'Escape' );
		await sam.reload();
		await expect( sam.locator( `article[data-id="${ id }"]` ) ).toHaveAttribute( 'draggable', 'true' );
		await sam.locator( `article[data-id="${ id }"]` ).click();
		await expect( sam.locator( '#nw-dlg[open] #nw-f-title' ) ).toBeEnabled();
		await expect( sam.locator( '#nw-dlg[open] button[value="save"]' ) ).toHaveCount( 1 );
	} finally {
		await cleanUp( alex );
		await alex.context().close();
		await sam.context().close();
	}
} );

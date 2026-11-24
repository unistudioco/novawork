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

test( 'an administrator adds, changes and deletes a session on the Time page; members see no edit buttons', async ( { browser } ) => {
	const context = await browser.newContext( { viewport: { width: 1366, height: 1500 } } );
	await context.addInitScript( () => {
		try {
			localStorage.setItem( 'nw.range', 'week' );
			localStorage.setItem( 'nw.tWho', 'all' );
		} catch ( e ) {}
	} );
	const [ user, pass ] = ( process.env.NOVAWORK_ADMIN || 'admin:admin' ).split( ':' );
	const base = process.env.NOVAWORK_URL || 'http://novawork.local';
	await context.addCookies( [ { name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base } ] );
	await context.request.post( base + '/wp-login.php', { form: { log: user, pwd: pass, testcookie: '1' }, maxRedirects: 0 } );
	const page = await context.newPage();
	await page.goto( APP + '#time' );
	await page.locator( '#nw-tbody .nw-kpi' ).first().waitFor();
	const title = unique( 'fixed by hand' );
	const task = ( await api( page, 'POST', 'tasks', { title, status: 'next' } ) ).body;
	await page.reload();
	try {
		await page.locator( '[data-act="add-session"]' ).click();
		const dlg = page.locator( '#nw-dlg[open]' );
		// Three hours ago, for an hour.
		const when = await page.evaluate( () => {
			const two = ( n ) => String( n ).padStart( 2, '0' );
			const a = new Date( Date.now() - 3 * 3600e3 );
			const b = new Date( a.getTime() + 3600e3 );
			return { day: `${ a.getFullYear() }-${ two( a.getMonth() + 1 ) }-${ two( a.getDate() ) }`, from: `${ two( a.getHours() ) }:${ two( a.getMinutes() ) }`, to: `${ two( b.getHours() ) }:${ two( b.getMinutes() ) }` };
		} );
		await dlg.locator( '#nw-s-task' ).evaluate( ( sel, id ) => {
			sel.value = String( id );
		}, task.id );
		await dlg.locator( '#nw-s-day' ).fill( when.day );
		await dlg.locator( '#nw-s-from' ).fill( when.from );
		await dlg.locator( '#nw-s-to' ).fill( when.to );
		await dlg.locator( 'button[value="save"]' ).click();
		const sessions = page.locator( '#nw-tbody .nw-panel', { has: page.locator( 'h3', { hasText: 'Sessions' } ) } );
		// The list shows the newest 8; other tests' sessions may be newer than this one.
		await expect( dlg ).toHaveCount( 0 );
		const all = sessions.locator( '[data-sessions="all"]' );
		if ( await all.count() ) {
			await all.click();
		}
		const row = sessions.locator( '.nw-row', { hasText: title } );
		await expect( row ).toHaveCount( 1 );
		await expect( row.locator( '.nw-tstat' ) ).toHaveText( '1h' );

		// Half an hour longer.
		await row.locator( '[data-act="edit-session"]' ).click();
		const later = await page.evaluate( ( t ) => {
			const [ h, m ] = t.split( ':' ).map( Number );
			const d = new Date( 2000, 0, 1, h, m + 30 );
			return String( d.getHours() ).padStart( 2, '0' ) + ':' + String( d.getMinutes() ).padStart( 2, '0' );
		}, when.to );
		await dlg.locator( '#nw-s-to' ).fill( later );
		await dlg.locator( 'button[value="save"]' ).click();
		await expect( row.locator( '.nw-tstat' ) ).toHaveText( '1h 30m' );

		// Delete, then Undo brings it back.
		await row.locator( '[data-act="edit-session"]' ).click();
		await dlg.locator( '[data-del]' ).click();
		await dlg.locator( '[data-del]' ).click();
		await expect( row ).toHaveCount( 0 );
		await page.locator( '.nw-toast .nw-toast-act', { hasText: 'Undo' } ).click();
		await expect( row ).toHaveCount( 1 );
		await expect( row.locator( '.nw-tstat' ) ).toHaveText( '1h 30m' );

		// A member sees the sessions, not the buttons.
		const member = await open( browser, 'A' );
		await member.goto( APP + '#time' );
		await member.locator( '#nw-tbody .nw-kpi' ).first().waitFor();
		await expect( member.locator( '[data-act="add-session"], [data-act="edit-session"]' ) ).toHaveCount( 0 );
		await member.context().close();
	} finally {
		const rows = await page.evaluate( async ( tid ) => {
			const r = await fetch( window.novawork.restUrl + 'time-entries?from=' + ( Date.now() - 8 * 86400e3 ), { headers: { 'X-WP-Nonce': window.novawork.nonce } } );
			return ( await r.json() ).filter( ( e ) => e.taskId === tid ).map( ( e ) => e.id );
		}, task.id );
		for ( const id of rows ) {
			await api( page, 'DELETE', 'time-entries/' + id );
		}
		await api( page, 'DELETE', 'tasks/' + task.id );
		await context.close();
	}
} );

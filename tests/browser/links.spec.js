/**
 * /links and @mentions in chat and comments (owner requests).
 */
import { test, expect } from './offline.js';
import { APP, signIn, unique, cleanChat, cleanUp } from './helpers.js';

test.afterAll( () => {
	cleanChat();
} );

test( 'link a task in chat; the chip opens it; its assignee is told', async ( { browser } ) => {
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );

	// A task assigned to member B, to link.
	const title = unique( 'linked task' );
	const ids = await page.evaluate( async ( t ) => {
		const nw = window.Alpine.store( 'nw' );
		const b = nw.members.find( ( m ) => m.id !== nw.me && m.id !== 1 );
		const h = { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' };
		const task = await ( await fetch( window.novawork.restUrl + 'tasks', { method: 'POST', headers: h, body: JSON.stringify( { title: t, assignees: [ b.id ] } ) } ) ).json();
		nw.upsert( 'tasks', task );
		return { task: task.id, b: b.id };
	}, title );

	await page.click( '#nw-chat-btn' );
	const text = unique( 'please look' );
	await page.locator( '#nw-chat-in' ).pressSequentially( text + ' /task ' + title.split( ' ' ).slice( 0, 3 ).join( ' ' ).toLowerCase() );
	await expect( page.locator( '#nw-mention-list li.nw-active .nw-opt-lbl' ) ).toHaveText( title );
	await expect( page.locator( '#nw-mention-list li.nw-active .nw-ref-kind' ) ).toHaveText( 'Task' );
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#nw-chat-in' ) ).toHaveValue( `${ text } /${ title } ` );
	await page.keyboard.press( 'Enter' );
	const chip = page.locator( '#nw-msgs .nw-msg', { hasText: text } ).locator( '.nw-ref' );
	await expect( chip ).toHaveText( title );
	// A link in chat goes to the task on the Board (owner), not its editor.
	await chip.click();
	await expect( page ).toHaveURL( /#board$/ );
	await expect( page.locator( `.nw-card[data-id="${ ids.task }"]` ) ).toHaveClass( /nw-flash/ );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 0 );
	await page.locator( `.nw-card[data-id="${ ids.task }"]` ).click();
	await expect( page.locator( '#nw-dlg[open] #nw-f-title' ) ).toHaveValue( title );

	// In the task's comments: @mention and /link work too.
	await page.locator( '#nw-cmt-in' ).pressSequentially( 'See /' + title.slice( 0, 12 ).toLowerCase() );
	await expect( page.locator( '#nw-cmt-suggest li' ).first() ).toBeVisible();
	await page.keyboard.press( 'Enter' );
	await page.keyboard.press( 'Enter' ); // sends the comment
	await expect( page.locator( '#nw-cmts .nw-msg .nw-ref' ) ).toHaveText( title );

	// The assignee hears about the link once (not twice).
	const ctxB = await browser.newContext();
	await signIn( ctxB, 'B' );
	const pb = await ctxB.newPage();
	await pb.goto( APP );
	const notes = await pb.evaluate( async ( task ) => {
		const d = await ( await fetch( window.novawork.restUrl + 'sync', { headers: { 'X-WP-Nonce': window.novawork.nonce } } ) ).json();
		return d.data.notifications.filter( ( n ) => n.objectType === 'task' && n.objectId === task ).map( ( n ) => n.type );
	}, ids.task );
	const meB = await pb.evaluate( () => window.novawork.me );
	if ( meB === ids.b ) {
		expect( notes.filter( ( t ) => t === 'link' ).length ).toBe( 1 );
		expect( notes ).toContain( 'comment' ); // the comment on their task
	}
	await cleanUp( page );
	await ctx.close();
	await ctxB.close();
} );

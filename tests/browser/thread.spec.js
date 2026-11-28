/**
 * Reply, Edit and Delete on chat messages and comments (owner request).
 */
import { test, expect } from './offline.js';
import { APP, signIn, unique, cleanChat, cleanUp, newTask } from './helpers.js';

test.afterAll( () => {
	cleanChat();
} );

test( 'chat: reply mentions the author and quotes the message; edit and delete with Undo', async ( { browser } ) => {
	// Member B says something.
	const ctxB = await browser.newContext();
	await signIn( ctxB, 'B' );
	const pb = await ctxB.newPage();
	await pb.goto( APP );
	const said = unique( 'original' );
	const nameB = await pb.evaluate( async ( text ) => {
		await fetch( window.novawork.restUrl + 'messages', { method: 'POST', headers: { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify( { text } ) } );
		const nw = window.Alpine.store( 'nw' );
		return nw.members.find( ( m ) => m.id === nw.me ).name;
	}, said );

	// Member A replies to it.
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.click( '#nw-chat-btn' );
	const theirs = page.locator( '#nw-msgs .nw-msg', { has: page.locator( '.nw-txt', { hasText: said } ) } );
	await theirs.hover();
	await expect( theirs.locator( '[data-tact="edit"]' ) ).toHaveCount( 0 ); // not yours
	await theirs.locator( '[data-tact="reply"]' ).click();
	await expect( page.locator( '#nw-chat-in' ) ).toHaveValue( `@${ nameB } ` );
	await expect( page.locator( '#nw-chat-reply' ) ).toContainText( nameB );
	const answer = unique( 'answer' );
	await page.locator( '#nw-chat-in' ).pressSequentially( answer );
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#nw-chat-reply' ) ).toBeHidden();
	const mine = page.locator( '#nw-msgs .nw-msg', { has: page.locator( '.nw-txt', { hasText: answer } ) } );
	await expect( mine.locator( '.nw-quote' ) ).toContainText( said );
	await expect( mine.locator( '.nw-mention' ) ).toHaveText( '@' + nameB );

	// The quote takes you to the original.
	await mine.locator( '.nw-quote' ).click();
	await expect( theirs ).toHaveClass( /nw-flash/ );

	// Edit: Enter saves, and it says "edited"; Escape cancels without closing the chat.
	const edited = unique( 'answer, edited' );
	await mine.hover();
	await mine.locator( '[data-tact="edit"]' ).click();
	await page.locator( '#nw-edit-in' ).fill( `@${ nameB } ${ edited }` );
	await page.keyboard.press( 'Enter' );
	const after = page.locator( '#nw-msgs .nw-msg', { has: page.locator( '.nw-txt', { hasText: edited } ) } );
	await expect( after.locator( '.nw-edited' ) ).toBeVisible();
	await after.hover();
	await after.locator( '[data-tact="edit"]' ).click();
	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '#nw-edit-in' ) ).toHaveCount( 0 );
	await expect( page.locator( '#nw-chat' ) ).toBeVisible();

	// Member B sees the edit.
	await pb.reload();
	await pb.click( '#nw-chat-btn' );
	await expect( pb.locator( '#nw-msgs .nw-txt', { hasText: edited } ) ).toBeVisible();

	// Delete, then Undo puts it back.
	await after.hover();
	await after.locator( '[data-tact="delete"]' ).click();
	await expect( after ).toHaveCount( 0 );
	await page.locator( '.nw-toast button', { hasText: 'Undo' } ).click();
	await expect( after ).toHaveCount( 1 );
	await ctx.close();
	await ctxB.close();
} );

test( 'comments: reply, edit, and a reply whose comment was deleted', async ( { browser } ) => {
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#board' );
	const title = unique( 'thread task' );
	await newTask( page, title );
	await page.locator( '.nw-card', { hasText: title } ).click();

	const first = unique( 'first comment' );
	await page.locator( '#nw-cmt-in' ).fill( first );
	await page.keyboard.press( 'Enter' );
	const c1 = page.locator( '#nw-cmts .nw-msg', { has: page.locator( '.nw-txt', { hasText: first } ) } );
	await c1.hover();
	await c1.locator( '[data-tact="reply"]' ).click();
	await expect( page.locator( '#nw-cmt-reply' ) ).toBeVisible();
	const second = unique( 'reply comment' );
	await page.locator( '#nw-cmt-in' ).fill( second );
	await page.keyboard.press( 'Enter' );
	const c2 = page.locator( '#nw-cmts .nw-msg', { has: page.locator( '.nw-txt', { hasText: second } ) } );
	await expect( c2.locator( '.nw-quote' ) ).toContainText( first );

	// Enter in the edit field saves the comment, not the task dialog.
	await c2.hover();
	await c2.locator( '[data-tact="edit"]' ).click();
	await page.locator( '#nw-edit-in' ).fill( second + ' edited' );
	await page.keyboard.press( 'Enter' );
	await expect( c2.locator( '.nw-edited' ) ).toBeVisible();
	await expect( page.locator( '#nw-dlg' ) ).toHaveAttribute( 'open', '' );

	await c1.hover();
	await c1.locator( '[data-tact="delete"]' ).click();
	await expect( c2.locator( '.nw-quote' ) ).toHaveText( 'The comment this replied to was deleted.' );
	await page.keyboard.press( 'Escape' );
	await cleanUp( page );
	await ctx.close();
} );

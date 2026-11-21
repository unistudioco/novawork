/**
 * Notifications: Mine (only your own) and Workspace (the team's activity).
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, unique, cleanChat, cleanUp } from './helpers.js';

test.afterAll( () => {
	cleanChat();
} );

test( 'the bell: your notifications, the team feed, open, mark read', async ( { browser } ) => {
	const title = unique( 'bell task' );
	// Member B assigns A a task and finishes it.
	const ctxB = await browser.newContext();
	await signIn( ctxB, 'B' );
	const pb = await ctxB.newPage();
	await pb.goto( APP );
	const chatText = unique( 'hello team' );
	const { nameB, taskId } = await pb.evaluate( async ( [ t, msg ] ) => {
		const nw = window.Alpine.store( 'nw' );
		const h = { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' };
		const a = nw.members.find( ( m ) => m.id !== nw.me && m.id !== 1 );
		const task = await ( await fetch( window.novawork.restUrl + 'tasks', { method: 'POST', headers: h, body: JSON.stringify( { title: t, assignees: [ a.id ] } ) } ) ).json();
		await fetch( window.novawork.restUrl + 'tasks/' + task.id, { method: 'PATCH', headers: h, body: JSON.stringify( { status: 'done' } ) } );
		await fetch( window.novawork.restUrl + 'messages', { method: 'POST', headers: h, body: JSON.stringify( { text: msg } ) } );
		return { nameB: nw.members.find( ( m ) => m.id === nw.me ).name, taskId: task.id };
	}, [ title, chatText ] );

	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.evaluate( () => window.localStorage.removeItem( 'nw.notesTab' ) );
	await page.click( '#nw-bell-btn' );
	const panel = page.locator( '#nw-notes' );
	await expect( panel ).toBeVisible();
	await expect( panel.locator( '[data-ntab="mine"]' ) ).toHaveClass( /nw-on/ );

	// Mine: the assignment, unread; a plain chat message is not here.
	const mine = panel.locator( '.nw-nitem', { hasText: `${ nameB } assigned you ${ title }` } );
	await expect( mine ).toHaveClass( /nw-unread/ );
	await expect( panel.locator( '.nw-nitem', { hasText: chatText } ) ).toHaveCount( 0 );
	await expect( panel.locator( '[data-ntab="team"] .nw-tcount' ) ).toBeVisible();
	// Clicking it goes to the task on the Board (owner), not its editor.
	await mine.click();
	await expect( panel ).toBeHidden();
	await expect( page ).toHaveURL( /#board$/ );
	await expect( page.locator( `.nw-card[data-id="${ taskId }"]` ) ).toHaveClass( /nw-flash/ );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 0 );
	await page.locator( `.nw-card[data-id="${ taskId }"]` ).click();
	await expect( page.locator( '#nw-dlg[open] #nw-f-title' ) ).toHaveValue( title );

	// Your own comment on it is not in your notifications.
	await page.locator( '#nw-cmt-in' ).fill( unique( 'my comment' ) );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.press( 'Escape' );
	await page.click( '#nw-bell-btn' );
	await expect( panel.locator( '.nw-nitem', { hasText: `${ nameB } assigned you ${ title }` } ) ).not.toHaveClass( /nw-unread/ );
	await expect( panel.locator( '.nw-nitem', { hasText: `commented on ${ title }` } ) ).toHaveCount( 0 );

	// Workspace: B's chat message (unread) and what B did, for everyone.
	await panel.locator( '[data-ntab="team"]' ).click();
	await expect( panel.locator( '.nw-nitem', { hasText: chatText } ) ).toHaveClass( /nw-unread/ );
	await expect( panel.locator( '.nw-nitem', { hasText: `${ nameB } finished ${ title }` } ) ).toBeVisible();
	await expect( panel.locator( '.nw-nitem', { hasText: `${ nameB } added ${ title }` } ) ).toBeVisible();
	await expect( panel.locator( '.nw-nitem', { hasText: `You commented on ${ title }` } ) ).toBeVisible();
	await panel.locator( '.nw-nitem', { hasText: `${ nameB } finished ${ title }` } ).click();
	await expect( page ).toHaveURL( /#board$/ );
	await expect( page.locator( `.nw-card[data-id="${ taskId }"]` ) ).toHaveClass( /nw-flash/ );

	// A chat message opens the chat on it.
	await page.click( '#nw-bell-btn' );
	await panel.locator( '.nw-nitem', { hasText: chatText } ).click();
	await expect( page.locator( '#nw-chat' ) ).toBeVisible();
	await expect( page.locator( '#nw-msgs .nw-msg', { has: page.locator( '.nw-txt', { hasText: chatText } ) } ) ).toHaveClass( /nw-flash/ );
	await page.click( '#nw-chat-close' );

	// The tab is remembered; Mark all as read clears the badge; Escape closes.
	await page.click( '#nw-bell-btn' );
	await expect( panel.locator( '[data-ntab="team"]' ) ).toHaveClass( /nw-on/ );
	for ( const tab of [ 'mine', 'team' ] ) {
		// Mark all as read marks the open tab only.
		await panel.locator( `[data-ntab="${ tab }"]` ).click();
		if ( await panel.locator( '[data-n="all"]' ).count() ) {
			await panel.locator( '[data-n="all"]' ).click();
		}
		await expect( panel.locator( '.nw-nitem.nw-unread' ) ).toHaveCount( 0 );
		await expect( panel.locator( `[data-ntab="${ tab }"] .nw-tcount` ) ).toHaveCount( 0 );
	}
	await expect( page.locator( '#nw-bell-btn .nw-nbadge' ) ).toHaveCount( 0 );
	await page.keyboard.press( 'Escape' );
	await expect( panel ).toBeHidden();

	await cleanUp( page );
	await ctx.close();
	await ctxB.close();
} );

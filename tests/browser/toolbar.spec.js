/**
 * The message toolbar (Reply, Edit, Delete) never covers the words or links of
 * the message it belongs to, nor the message above (owner, 2026-11-25: it hid
 * the end of a /task link on a message's first line).
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, unique, cleanChat, cleanUp } from './helpers.js';

test.afterAll( () => {
	cleanChat();
} );

/** The toolbar's box against its message's text and the item above it. */
const measure = ( msg ) =>
	msg.evaluate( ( el ) => {
		const acts = el.querySelector( ':scope > .nw-msg-acts' ).getBoundingClientRect();
		const txt = el.querySelector( ':scope > .nw-txt' ).getBoundingClientRect();
		const prev = el.previousElementSibling;
		const list = el.parentElement.getBoundingClientRect();
		return {
			shown: acts.height > 0,
			clearOfText: acts.bottom <= txt.top,
			clearOfPrev: ! prev || acts.top >= prev.getBoundingClientRect().bottom,
			insideList: acts.top >= list.top,
		};
	} );

test( 'the message toolbar sits above the words, in chat and in comments', async ( { browser } ) => {
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.locator( '.nw-focus' ).waitFor();
	const words = unique( 'toolbar check' ) + ' with a first line long enough to run right under where the toolbar used to sit';
	const taskTitle = unique( 'toolbar task' );
	const id = await page.evaluate(
		async ( [ a, b, t ] ) => {
			const h = { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' };
			const post = ( path, body ) => fetch( window.novawork.restUrl + path, { method: 'POST', headers: h, body: JSON.stringify( body ) } ).then( ( r ) => r.json() );
			await post( 'messages', { text: a } );
			await post( 'messages', { text: b } );
			const task = await post( 'tasks', { title: t, status: 'next' } );
			await post( 'comments', { objectType: 'task', objectId: task.id, text: b } );
			return task.id;
		},
		[ unique( 'before it' ), words, taskTitle ]
	);
	try {
		await page.reload();
		await page.locator( '.nw-focus' ).waitFor();
		await page.click( '#nw-chat-btn' );
		const msg = page.locator( '#nw-msgs .nw-msg', { hasText: 'with a first line long enough' } ).last();
		await msg.hover();
		expect( await measure( msg ) ).toEqual( { shown: true, clearOfText: true, clearOfPrev: true, insideList: true } );

		// A task's first comment: its toolbar has room above it in the list.
		await page.keyboard.press( 'Escape' );
		await page.evaluate( ( taskId ) => window.dispatchEvent( new CustomEvent( 'nw:open', { detail: { kind: 'task', id: taskId } } ) ), id );
		const cmt = page.locator( '#nw-dlg[open] .nw-comments .nw-msg' ).first();
		await cmt.hover();
		expect( await measure( cmt ) ).toEqual( { shown: true, clearOfText: true, clearOfPrev: true, insideList: true } );
	} finally {
		await cleanUp( page );
		await ctx.close();
	}
} );

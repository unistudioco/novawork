/**
 * Quick search, final pass: the whole chat history is searched (the app only
 * holds the newest messages), and a message result opens the chat on it.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, unique, cleanChat } from './helpers.js';

test.afterAll( () => {
	cleanChat();
} );

test( 'finds an old chat message the app has not loaded, and opens the chat on it', async ( { browser } ) => {
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	const word = 'zq' + Date.now().toString( 36 );
	const text = unique( `the ${ word } plan` );
	const id = await page.evaluate( async ( t ) => {
		const nw = window.Alpine.store( 'nw' );
		const send = async ( body ) => ( await fetch( window.novawork.restUrl + 'messages', { method: 'POST', headers: { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify( { text: body } ) } ) ).json();
		const m = await send( t );
		for ( const later of [ 'E2E later one', 'E2E later two' ] ) {
			nw.upsert( 'messages', await send( later ) );
		}
		// As if it were older than everything this device holds.
		Object.keys( nw.messages ).map( Number ).filter( ( x ) => x <= m.id ).forEach( ( x ) => nw.remove( 'messages', x ) );
		return m.id;
	}, text );

	await page.locator( '#nw-qs .nw-qs-field' ).click();
	await page.keyboard.type( word );
	const result = page.locator( '#nw-qs-res .nw-qs-item', { hasText: word } );
	await expect( result ).toBeVisible(); // from the server
	// A sync redraw can replace the message right after it flashed, so note
	// the flash when it happens instead of looking for it afterwards.
	await page.evaluate( ( mid ) => {
		window.__flashed = false;
		new MutationObserver( ( list ) => {
			if ( list.some( ( r ) => r.target.matches && r.target.matches( `.nw-msg[data-mid="${ mid }"].nw-flash` ) ) ) {
				window.__flashed = true;
			}
		} ).observe( document.body, { subtree: true, attributes: true, attributeFilter: [ 'class' ] } );
	}, id );
	await result.click();
	await expect( page.locator( '#nw-chat' ) ).toBeVisible();
	await expect.poll( () => page.evaluate( () => window.__flashed ) ).toBe( true );
	await ctx.close();
} );

test( 'a message with a /link reads as its title in the results', async ( { browser } ) => {
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	const word = 'zr' + Date.now().toString( 36 );
	const name = await page.evaluate( async ( w ) => {
		const nw = window.Alpine.store( 'nw' );
		const p = Object.values( nw.projects )[ 0 ];
		const m = await ( await fetch( window.novawork.restUrl + 'messages', { method: 'POST', headers: { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify( { text: `E2E ${ w } see /${ p.name }`, refs: [ { type: 'project', id: p.id } ] } ) } ) ).json();
		nw.upsert( 'messages', m );
		return p.name;
	}, word );
	await page.locator( '#nw-qs .nw-qs-field' ).click();
	await page.keyboard.type( word );
	await expect( page.locator( '#nw-qs-res .nw-qs-item .nw-tt', { hasText: word } ) ).toHaveText( `E2E ${ word } see ${ name }` );
	await ctx.close();
} );

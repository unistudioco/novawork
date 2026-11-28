/**
 * Team chat in a real browser: send, @mention, attach, search, dock.
 */
import { test, expect } from './offline.js';
import { APP, signIn, unique, cleanChat } from './helpers.js';

test.afterAll( () => {
	cleanChat();
} );

const PNG = Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64' );

test( 'send a message that mentions a teammate; they get a mention', async ( { browser } ) => {
	const ctxA = await browser.newContext( { viewport: { width: 1366, height: 1000 } } );
	await signIn( ctxA, 'A' );
	const a = await ctxA.newPage();
	await a.goto( APP + '#focus' );
	await a.click( '#nw-chat-btn' );
	await expect( a.locator( '#nw-chat' ) ).toBeVisible();

	const text = unique( 'hello' );
	await a.locator( '#nw-chat-in' ).pressSequentially( text + ' @sa' );
	await expect( a.locator( '#nw-mention-list li' ).first() ).toBeVisible();
	const pick = await a.locator( '#nw-mention-list li.nw-active > span:last-child' ).textContent();
	await a.keyboard.press( 'Enter' ); // picks, does not send
	await expect( a.locator( '#nw-chat-in' ) ).toHaveValue( `${ text } @${ pick } ` );
	await a.keyboard.press( 'Enter' ); // now sends
	const msg = a.locator( '#nw-msgs .nw-msg', { hasText: text } );
	await expect( msg ).toHaveCount( 1 );
	await expect( msg.locator( '.nw-mention' ) ).toHaveText( '@' + pick );

	// The mentioned teammate gets a "mention" (not a plain chat) notification.
	const ctxB = await browser.newContext();
	const [ userB ] = ( process.env.NOVAWORK_USER_B || 'sam:sam' ).split( ':' );
	await signIn( ctxB, 'B' );
	const b = await ctxB.newPage();
	await b.goto( APP );
	const notes = await b.evaluate( async ( t ) => {
		const h = { 'X-WP-Nonce': window.novawork.nonce };
		const d = await ( await fetch( window.novawork.restUrl + 'sync', { headers: h } ) ).json();
		const m = d.data.messages.find( ( x ) => x.text.startsWith( t ) );
		return { me: d.me, mentions: m ? m.mentions.map( ( x ) => x.id ) : [], types: d.data.notifications.filter( ( n ) => m && n.objectId === m.id ).map( ( n ) => n.type ) };
	}, text );
	if ( pick.toLowerCase().startsWith( userB.toLowerCase() ) ) {
		expect( notes.mentions ).toContain( notes.me );
		expect( notes.types ).toEqual( [ 'mention' ] );
	}
	await ctxA.close();
	await ctxB.close();
} );

test( 'attach an image, search, and dock on the other side', async ( { context, page } ) => {
	await signIn( context );
	await page.setViewportSize( { width: 1366, height: 1000 } );
	await page.goto( APP + '#focus' );
	await page.click( '#nw-chat-btn' );
	await page.locator( '#nw-file-in' ).setInputFiles( { name: 'e2e-pixel.png', mimeType: 'image/png', buffer: PNG } );
	await expect( page.locator( '#nw-pending .nw-fchip' ) ).toHaveCount( 1 );
	await expect( page.locator( '#nw-pending .nw-fchip.nw-uploading' ) ).toHaveCount( 0 );
	const text = unique( 'file' );
	await page.fill( '#nw-chat-in', text );
	await page.click( '#nw-chat-send' );
	const msg = page.locator( '#nw-msgs .nw-msg', { hasText: text } );
	await expect( msg.locator( '.nw-files img' ) ).toHaveCount( 1 );
	await expect.poll( () => msg.locator( '.nw-files img' ).evaluate( ( img ) => img.naturalWidth ) ).toBe( 1 ); // served by the private route

	await page.click( '#nw-chat-find' );
	await page.fill( '#nw-chat-q', text );
	await expect( page.locator( '#nw-chat-count' ) ).toHaveText( '1 message' );
	await expect( page.locator( '#nw-msgs mark' ) ).toHaveCount( 1 );
	await page.click( '#nw-chat-find' );

	await expect( page.locator( 'body' ) ).toHaveClass( /nw-docked-right/ );
	await page.click( '#nw-chat-dock' );
	await expect( page.locator( 'body' ) ).toHaveClass( /nw-docked-left/ );
	await page.click( '#nw-chat-dock' );
	await page.click( '#nw-chat-close' );
	await expect( page.locator( '#nw-chat' ) ).toBeHidden();
	await expect( page.locator( 'body' ) ).not.toHaveClass( /nw-docked/ );
} );

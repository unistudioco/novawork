/**
 * The members' sign-in page (owner mark): signed-out visits land on it,
 * wrong details never say which part was wrong, signing in returns to the
 * view you asked for, and signing out comes back here.
 */
import { test, expect } from './offline.js';
import { APP } from './helpers.js';

const [ USER, PASS ] = ( process.env.NOVAWORK_USER_A || 'alex:alex' ).split( ':' );

test( 'sign in on the workspace page and back out again', async ( { browser } ) => {
	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	await page.goto( APP + '#board' );
	await expect( page ).toHaveURL( /\/login\/?#board$/ );
	// The workspace's name, as text or as the logo's alt text.
	await expect( page.locator( '.nw-login-card h1' ) ).toHaveAccessibleName( /\S/ );

	await page.fill( '#nw-login-user', USER );
	await page.fill( '#nw-login-pass', 'not-the-password' );
	await page.click( '.nw-login-go' );
	const wrong = await page.locator( '.nw-login-msg' ).innerText();
	await page.fill( '#nw-login-user', 'nobody-' + Date.now() );
	await page.fill( '#nw-login-pass', 'x' );
	await page.click( '.nw-login-go' );
	await expect( page.locator( '.nw-login-msg' ) ).toHaveText( wrong ); // same words either way

	await page.goto( APP + '#board' );
	await page.fill( '#nw-login-user', USER );
	await page.fill( '#nw-login-pass', PASS );
	await page.click( '.nw-login-go' );
	await expect( page ).toHaveURL( /#board$/ );
	await expect( page.locator( '#nw-main' ) ).toBeVisible();

	const out = await page.evaluate( () => window.novawork.logoutUrl );
	await page.goto( out );
	await expect( page ).toHaveURL( /\/login\/\?signed-out=1$/ );
	await expect( page.locator( '.nw-login-msg' ) ).toHaveText( /signed out/ );
	await ctx.close();
} );

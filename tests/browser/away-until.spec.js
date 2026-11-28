/**
 * "Away until…" (1.3.0): a member sets their holiday from the team menu; it
 * shows on their avatar and in Team today, for everyone; "I'm back" ends it.
 * Administrators can set anyone's (owner, 2026-11-28).
 */
import { test, expect } from './offline.js';
import { APP, signIn, wpEval } from './helpers.js';

const clear = () => wpEval( "delete_metadata( 'user', 0, 'novawork_away', '', true );" );

test.beforeAll( clear );
test.afterAll( clear );

test( 'a member sets “Away until…” from the team menu; everyone sees it; “I’m back” ends it', async ( { browser } ) => {
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 900 } } );
	await signIn( ctx, 'A' );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.click( '#nw-crew-btn' );
	const item = page.locator( '#nw-away-item' );
	await expect( item ).toHaveText( 'Away until…' );
	await item.click();
	const dlg = page.locator( '#nw-dlg[open]' );
	await expect( dlg.locator( '#nw-dlg-title' ) ).toHaveText( 'Away until…' );
	await dlg.getByRole( 'button', { name: 'Next week' } ).click();
	const until = await dlg.locator( '#nw-away-until' ).inputValue();
	expect( until ).toMatch( /^\d{4}-\d{2}-\d{2}$/ );
	await dlg.locator( '#nw-away-note' ).fill( 'Holiday in Lisbon' );
	await page.screenshot( { path: 'build/browser/away-dialog-1366.png' } );
	await dlg.getByRole( 'button', { name: 'Save' } ).click();
	await expect( dlg ).toHaveCount( 0 );
	await expect( page.locator( '.nw-toast', { hasText: 'You’re set: Away until' } ) ).toBeVisible();

	// Team today: the line and the moon on the avatar.
	const row = page.locator( '.nw-sec-team .nw-row', { hasText: 'You' } );
	await expect( row ).toContainText( 'Away until' );
	await expect( row ).toContainText( 'Holiday in Lisbon' );
	await expect( row.locator( '.nw-avatar.nw-is-off .nw-off-mark' ) ).toHaveCount( 1 );
	await row.screenshot( { path: 'build/browser/away-team-row.png' } );

	// Another member sees it on the next poll.
	const ctxB = await browser.newContext( { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } );
	await signIn( ctxB, 'B' );
	const pb = await ctxB.newPage();
	await pb.goto( APP + '#focus' );
	const alexName = await page.evaluate( () => window.Alpine.store( 'nw' ).members.find( ( m ) => m.id === window.Alpine.store( 'nw' ).me ).name );
	const seen = pb.locator( '.nw-sec-team .nw-row', { hasText: alexName } );
	await expect( seen ).toContainText( 'Away until', { timeout: 15000 } );
	await pb.screenshot( { path: 'build/browser/away-team-390.png', fullPage: true } );
	// A member has no button to change someone else's.
	await pb.click( '#nw-crew-btn' );
	await expect( pb.locator( '.nw-crew-list .nw-off-btn' ) ).toHaveCount( 0 );
	await ctxB.close();

	// I'm back.
	await page.click( '#nw-crew-btn' );
	await expect( page.locator( '#nw-away-item' ) ).toContainText( 'Away until' );
	await page.locator( '#nw-away-item' ).click();
	await expect( page.locator( '#nw-dlg[open] #nw-away-note' ) ).toHaveValue( 'Holiday in Lisbon' );
	await page.locator( '#nw-dlg[open]' ).getByRole( 'button', { name: 'I’m back' } ).click();
	await expect( page.locator( '.nw-toast', { hasText: 'Welcome back.' } ) ).toBeVisible();
	await expect( row ).not.toContainText( 'Away until' );
	await ctx.close();
} );

test( 'an administrator sets another member’s away status from the team menu', async ( { browser } ) => {
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 900 } } );
	// The administrator from .dev.env (as rights.spec.js signs in).
	const [ user, pass ] = ( process.env.NOVAWORK_ADMIN || 'admin:admin' ).split( ':' );
	const base = process.env.NOVAWORK_URL || 'http://novawork.local';
	await ctx.addCookies( [ { name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base } ] );
	await ctx.request.post( base + '/wp-login.php', { form: { log: user, pwd: pass, testcookie: '1' }, maxRedirects: 0 } );
	const page = await ctx.newPage();
	await page.goto( APP + '#focus' );
	await page.click( '#nw-crew-btn' );
	const btn = page.locator( '.nw-crew-list .nw-off-btn' ).first();
	const label = await btn.getAttribute( 'aria-label' );
	expect( label ).toMatch( /^Away status for / );
	await btn.click();
	const dlg = page.locator( '#nw-dlg[open]' );
	await expect( dlg.locator( '#nw-dlg-title' ) ).toHaveText( label );
	await dlg.getByRole( 'button', { name: 'Tomorrow' } ).click();
	await dlg.getByRole( 'button', { name: 'Save' } ).click();
	await expect( dlg ).toHaveCount( 0 );
	const name = label.replace( 'Away status for ', '' );
	await expect( page.locator( '.nw-toast', { hasText: name + ': Away until' } ) ).toBeVisible();
	await ctx.close();
} );

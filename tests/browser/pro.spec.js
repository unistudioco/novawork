/**
 * NovaWork Pro (owner, 1.1.0): a "Pro waitlist" button in the settings
 * screen's header, for administrators. It never opens by itself; a click shows
 * a short dialog whose link opens the waitlist page in a new tab.
 */
import { test, expect } from '@playwright/test';

const BASE = process.env.NOVAWORK_URL || 'http://novawork.local';

test( 'the Pro waitlist dialog opens only on a click, links out, and closes', async ( { browser } ) => {
	const context = await browser.newContext( { viewport: { width: 1366, height: 900 } } );
	const [ user, pass ] = ( process.env.NOVAWORK_ADMIN || 'admin:admin' ).split( ':' );
	await context.addCookies( [ { name: 'wordpress_test_cookie', value: 'WP Cookie check', url: BASE } ] );
	await context.request.post( BASE + '/wp-login.php', { form: { log: user, pwd: pass, testcookie: '1' }, maxRedirects: 0 } );
	const page = await context.newPage();
	try {
		await page.goto( BASE + '/wp-admin/admin.php?page=novawork-settings' );
		await page.locator( '.nw-admin #nw-s-workspace' ).waitFor();
		const dlg = page.locator( 'dialog.nw-pro-dlg' );
		// Never on its own.
		await page.waitForTimeout( 800 );
		await expect( dlg ).not.toHaveAttribute( 'open', '' );

		const btn = page.locator( '.nw-shead .nw-pro-btn' );
		await expect( btn ).toHaveText( 'Pro waitlist' );
		await btn.click();
		await expect( dlg ).toHaveAttribute( 'open', '' );
		await expect( dlg.locator( 'h2' ) ).toHaveText( 'NovaWork Pro is on its way' );
		// What the waitlist promises (1.3.0): two emails at most.
		await expect( dlg.locator( '.nw-pro-note' ) ).toHaveText( 'Join the waitlist for the launch price, kept for life. Two emails at most: a quick vote on what ships first, and one when Pro is ready.' );
		// The Pro mark is the filled crown (crown-simple), in the button and the dialog.
		const crown = 'path[d^="M239.75,90.81"]';
		await expect( btn.locator( '.nw-pro-spark ' + crown ) ).toHaveCount( 1 );
		await expect( dlg.locator( '.nw-pro-mark ' + crown ) ).toHaveCount( 1 );
		const join = dlg.locator( 'a.nw-primary' );
		await expect( join ).toHaveAttribute( 'href', 'https://novawork.unistudio.co/pro-waitlist/' );
		await expect( join ).toHaveAttribute( 'target', '_blank' );

		// Not now, Escape and a real backdrop click all close it.
		await dlg.locator( 'button', { hasText: 'Not now' } ).click();
		await expect( dlg ).not.toHaveAttribute( 'open', '' );
		await btn.click();
		await page.keyboard.press( 'Escape' );
		await expect( dlg ).not.toHaveAttribute( 'open', '' );
		await btn.click();
		await page.mouse.click( 8, 450 );
		await expect( dlg ).not.toHaveAttribute( 'open', '' );
		// A click inside the card keeps it open.
		await btn.click();
		await dlg.locator( '.nw-pro-lead' ).click();
		await expect( dlg ).toHaveAttribute( 'open', '' );
	} finally {
		await context.close();
	}
} );

test( 'the Plugins screen shows the Pro waitlist link on NovaWork\'s row', async ( { browser } ) => {
	const context = await browser.newContext( { viewport: { width: 1366, height: 900 } } );
	const [ user, pass ] = ( process.env.NOVAWORK_ADMIN || 'admin:admin' ).split( ':' );
	await context.addCookies( [ { name: 'wordpress_test_cookie', value: 'WP Cookie check', url: BASE } ] );
	await context.request.post( BASE + '/wp-login.php', { form: { log: user, pwd: pass, testcookie: '1' }, maxRedirects: 0 } );
	const page = await context.newPage();
	try {
		await page.goto( BASE + '/wp-admin/plugins.php' );
		const link = page.locator( 'tr[data-slug="novawork"] .row-actions .novawork_pro a' );
		await expect( link ).toHaveAttribute( 'href', 'https://novawork.unistudio.co/pro-waitlist/' );
		await expect( link ).toHaveAttribute( 'target', '_blank' );
		await expect( link ).toContainText( 'Pro waitlist' );
		// NovaWork's orange, bold, on its own row only.
		await expect( link ).toHaveCSS( 'color', 'rgb(243, 79, 53)' );
		await expect( link ).toHaveCSS( 'font-weight', '700' );
		// Only on NovaWork's row: another plugin's links keep WordPress's colour.
		// (NovaWork's own "Deactivate" isn't a link while a plugin requires it.)
		await expect( page.locator( 'tr:not([data-slug="novawork"]) .row-actions a' ).first() ).not.toHaveCSS( 'color', 'rgb(243, 79, 53)' );
	} finally {
		await context.close();
	}
} );

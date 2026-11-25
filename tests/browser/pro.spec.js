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

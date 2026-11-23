/**
 * Tooltips (owner, 1.1.0): one NovaWork tooltip replaces the browser's
 * `title` popups. Hover shows it after a short delay, the timer shows its
 * T shortcut, it sits above open dialogs, and touch never shows it.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn } from './helpers.js';

test.beforeEach( async ( { context, page } ) => {
	await signIn( context );
	await page.goto( APP + '#board' );
	await page.locator( '.nw-board' ).waitFor();
} );

test( 'hovering an icon button shows its tooltip, leaving hides it', async ( { page } ) => {
	await page.hover( '#nw-bell-btn' );
	const tip = page.locator( '#nw-tip' );
	await expect( tip ).toHaveClass( /nw-tip-on/ );
	await expect( tip.locator( 'span' ) ).toHaveText( /\S/ );
	await expect( page.locator( '#nw-bell-btn' ) ).toHaveAttribute( 'aria-describedby', 'nw-tip' );

	await page.mouse.move( 5, 600 );
	await expect( tip ).not.toHaveClass( /nw-tip-on/ );
	await expect( page.locator( '#nw-bell-btn' ) ).not.toHaveAttribute( 'aria-describedby', 'nw-tip' );
} );

test( 'the timer: its title moves into the tooltip with the T chip, then comes back', async ( { page } ) => {
	const btn = page.locator( '.nw-tplay' ).first();
	const title = await btn.getAttribute( 'title' );
	expect( title ).toBeTruthy();
	await btn.hover();
	const tip = page.locator( '#nw-tip' );
	await expect( tip ).toHaveClass( /nw-tip-on/ );
	await expect( tip.locator( 'span' ) ).toHaveText( title );
	await expect( tip.locator( 'kbd' ) ).toHaveText( 'T' );
	// No browser popup while ours shows.
	await expect( btn ).not.toHaveAttribute( 'title', /./ );

	await page.mouse.move( 5, 600 );
	await expect( tip ).not.toHaveClass( /nw-tip-on/ );
	await expect( btn ).toHaveAttribute( 'title', title );
} );

test( 'a tooltip shows above an open dialog', async ( { page } ) => {
	await page.keyboard.press( 'n' );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 );
	const close = page.locator( '#nw-dlg[open] button[aria-label]' ).filter( { hasText: /^$/ } ).first();
	await close.hover();
	const tip = page.locator( '#nw-tip' );
	await expect( tip ).toHaveClass( /nw-tip-on/ );
	// It is in the top layer (an open popover), above the modal dialog.
	await expect( tip ).toBeVisible();
	expect( await tip.evaluate( ( el ) => el.matches( ':popover-open' ) ) ).toBe( true );
} );

test( 'touch never shows a tooltip', async ( { browser } ) => {
	const context = await browser.newContext( { hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 } } );
	await signIn( context );
	const page = await context.newPage();
	await page.goto( APP + '#board' );
	await page.locator( '.nw-board' ).waitFor();
	await page.tap( '#nw-bell-btn' );
	await page.waitForTimeout( 600 );
	await expect( page.locator( '#nw-tip.nw-tip-on' ) ).toHaveCount( 0 );
	await context.close();
} );

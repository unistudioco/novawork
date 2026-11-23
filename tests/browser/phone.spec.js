/**
 * Phones (owner, 2026-11-23): "Still working?" is a full-width bottom sheet
 * like every other dialog, and the floating tracker carries the running
 * gradient.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn } from './helpers.js';

test.use( { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true } );

test( 'Still working? fills the phone width; the floating tracker flows while tracking', async ( { context, page } ) => {
	await signIn( context );
	await page.goto( APP + '#focus' );
	await page.locator( '#nw-ftimer' ).waitFor();
	await page.evaluate( () => document.getElementById( 'nw-idle-dlg' ).showModal() );
	const box = await page.locator( '#nw-idle-dlg' ).boundingBox();
	expect( Math.round( box.width ) ).toBe( 390 );
	await page.evaluate( () => document.getElementById( 'nw-idle-dlg' ).close() );

	await page.locator( '#nw-ftimer .nw-tplay' ).tap();
	await expect( page.locator( '#nw-ftimer.nw-run' ) ).toHaveCount( 1 );
	await expect.poll( () => page.locator( '#nw-ftimer' ).evaluate( ( el ) => getComputedStyle( el, '::before' ).backgroundImage ) ).toContain( 'conic-gradient' );
	await page.locator( '#nw-ftimer .nw-tplay' ).tap();
	await expect( page.locator( '#nw-ftimer.nw-run' ) ).toHaveCount( 0 );
} );

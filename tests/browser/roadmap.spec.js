/**
 * Roadmap in a real browser, as member A.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn } from './helpers.js';

test.beforeEach( async ( { context, page } ) => {
	await signIn( context );
	await page.addInitScript( () => {
		try {
			window.localStorage.setItem( 'nw.tlZoom', 'month' );
		} catch ( e ) {}
	} );
	await page.goto( APP + '#roadmap' );
	await page.locator( '#nw-tl-scroll' ).waitFor();
} );

test( 'opens on today, keeps your scroll through a redraw, and Today brings it back', async ( { page } ) => {
	const sc = page.locator( '#nw-tl-scroll' );
	const opened = await sc.evaluate( ( el ) => el.scrollLeft );
	expect( opened ).toBeGreaterThan( 0 );
	await sc.evaluate( ( el ) => {
		el.scrollLeft = 0;
	} );
	// A redraw from data (a teammate's change) must not move the timeline.
	await page.evaluate( () => {
		const nw = window.Alpine.store( 'nw' );
		const p = Object.values( nw.projects )[ 0 ];
		nw.upsert( 'projects', Object.assign( {}, p ) );
	} );
	await page.waitForTimeout( 300 );
	expect( await page.locator( '#nw-tl-scroll' ).evaluate( ( el ) => el.scrollLeft ) ).toBe( 0 );
	await page.locator( '[data-act="tl-today"]' ).click();
	await expect.poll( () => page.locator( '#nw-tl-scroll' ).evaluate( ( el ) => Math.round( el.scrollLeft ) ) ).toBe( Math.round( opened ) );
} );

test( 'zoom switches between Months and Quarters', async ( { page } ) => {
	await page.locator( '#nw-tl-zoom .nw-tab', { hasText: 'Quarters' } ).click();
	await expect( page.locator( '.nw-mtick' ).first() ).toHaveText( /^Q[1-4]/ );
	await page.locator( '#nw-tl-zoom .nw-tab', { hasText: 'Months' } ).click();
	await expect( page.locator( '.nw-mtick.nw-cur' ) ).toHaveCount( 1 );
} );

test( 'a bar and a card open the project editor', async ( { page } ) => {
	await page.locator( '.nw-tl-bar' ).first().click();
	await expect( page.locator( '#nw-dlg[open] #nw-p-name' ) ).toBeVisible();
	await page.keyboard.press( 'Escape' );
	await page.locator( '.nw-proj [data-act="edit-project"]' ).first().click();
	await expect( page.locator( '#nw-dlg[open] #nw-p-name' ) ).toBeVisible();
} );

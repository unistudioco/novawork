/**
 * Focus and Time in a real browser, as member A. Leaves no timer running.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn } from './helpers.js';

test.beforeEach( async ( { context } ) => {
	await signIn( context );
} );

test( 'the big card starts and pauses the timer, and the header follows', async ( { page } ) => {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	await page.goto( APP + '#focus' );
	await page.locator( '.nw-now-card' ).waitFor();
	if ( await page.locator( '.nw-now-card.nw-run' ).count() ) {
		await page.locator( '.nw-now-card .nw-tbtn' ).click();
		await expect( page.locator( '.nw-now-card.nw-run' ) ).toHaveCount( 0 );
	}
	await page.locator( '.nw-now-card .nw-tbtn' ).click();
	await expect( page.locator( '.nw-now-card.nw-run' ) ).toHaveCount( 1 );
	await expect( page.locator( '#nw-tracker.nw-run' ) ).toHaveCount( 1 );
	await expect( page.locator( '.nw-now-card .nw-big' ) ).toHaveText( /^0:0[1-9]$/, { timeout: 5000 } );
	await page.locator( '.nw-now-card .nw-tbtn' ).click();
	await expect( page.locator( '.nw-now-card.nw-run' ) ).toHaveCount( 0 );
	await expect( page.locator( '#nw-tracker.nw-run' ) ).toHaveCount( 0 );
	expect( errors ).toEqual( [] );
} );

test( 'Time: ranges switch, Month and Year load history', async ( { page } ) => {
	await page.goto( APP + '#time' );
	for ( const range of [ 'Today', 'Week', 'Month', 'Year' ] ) {
		await page.locator( '#nw-range-tabs .nw-tab', { hasText: range } ).click();
		await expect( page.locator( '#nw-range-tabs .nw-tab.nw-on' ) ).toHaveText( range );
		await expect( page.locator( '#nw-tbody .nw-kpi' ) ).toHaveCount( 4 );
	}
	// Year has months of seeded history, more than the 8 days the app keeps.
	await expect( page.locator( '#nw-tbody .nw-plot .nw-col i' ).first() ).toBeVisible( { timeout: 5000 } );
	await expect( page.locator( '#nw-tbody' ) ).not.toContainText( 'Loading' );
} );

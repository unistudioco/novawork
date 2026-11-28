/**
 * Focus and Time in a real browser, as member A. Leaves no timer running.
 */
import { test, expect } from './offline.js';
import { APP, signIn, wpEval } from './helpers.js';

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

test( 'Today: the chart covers the whole day, so time tracked after midnight shows', async ( { browser } ) => {
	// A browser whose clock reads about 3 a.m.: Etc/GMT-N is UTC+N.
	const n = ( ( ( 3 - new Date().getUTCHours() + 12 ) % 24 ) + 24 ) % 24 - 12;
	const zone = n === 0 ? 'Etc/GMT' : `Etc/GMT${ n > 0 ? '-' : '+' }${ Math.abs( n ) }`;
	const context = await browser.newContext( { timezoneId: zone } );
	await signIn( context );
	const page = await context.newPage();
	await page.goto( APP + '#time' );
	await page.locator( '#nw-tbody .nw-kpi' ).first().waitFor();
	const me = await page.evaluate( () => window.novawork.me );
	// 00:30 to 01:30 there, today.
	const id = wpEval(
		`$m = (int) floor( ( time() + ${ n } * 3600 ) / 86400 ) * 86400 - ${ n } * 3600; echo ( new NovaWork\\Data\\TimeEntryRepository() )->insert( array( 'user_id' => ${ me }, 'task_id' => 0, 'started_at' => gmdate( 'Y-m-d H:i:s', $m + 1800 ), 'ended_at' => gmdate( 'Y-m-d H:i:s', $m + 5400 ) ) );`
	).trim();
	try {
		await page.reload();
		await page.locator( '#nw-range-tabs .nw-tab', { hasText: 'Today' } ).click();
		// 24 columns from midnight; the 0 and 1 o'clock ones hold the hour.
		await expect( page.locator( '#nw-tbody .nw-plot .nw-col' ) ).toHaveCount( 24 );
		await expect( page.locator( '#nw-tbody .nw-xax span' ).first() ).toHaveText( '0' );
		await expect( page.locator( '#nw-tbody .nw-plot .nw-col' ).nth( 0 ).locator( 'i' ) ).toHaveCount( 1 );
		await expect( page.locator( '#nw-tbody .nw-plot .nw-col' ).nth( 1 ).locator( 'i' ) ).toHaveCount( 1 );
	} finally {
		wpEval( `( new NovaWork\\Data\\TimeEntryRepository() )->delete( ${ Number( id ) } );` );
		await context.close();
	}
} );

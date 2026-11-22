/**
 * A running timer needs a sign of life (owner, 2026-11-22): a folded laptop
 * or a closed tab must not keep tracking.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, unique, cleanUp, wpEval } from './helpers.js';

test.beforeEach( async ( { context, page } ) => {
	await signIn( context );
	await page.goto( APP + '#focus' );
	await page.locator( '.nw-focus' ).waitFor();
} );

test.afterEach( async ( { page } ) => {
	if ( ! page.isClosed() ) {
		await page.evaluate( async () => {
			await fetch( window.novawork.restUrl + 'timer/stop', { method: 'POST', headers: { 'X-WP-Nonce': window.novawork.nonce } } );
		} );
		await cleanUp( page );
	}
} );

async function trackNewTask( page, title ) {
	const id = await page.evaluate( async ( t ) => {
		const h = { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' };
		const task = await ( await fetch( window.novawork.restUrl + 'tasks', { method: 'POST', headers: h, body: JSON.stringify( { title: t, status: 'now' } ) } ) ).json();
		return task.id;
	}, title );
	await page.reload();
	await page.locator( `.nw-slot[data-id="${ id }"] [data-act="track"]` ).click();
	await expect( page.locator( '.nw-now-card.nw-run' ) ).toBeVisible();
	return id;
}

test( 'the page slept (laptop folded): it stops the timer on waking, without asking', async ( { page } ) => {
	await trackNewTask( page, unique( 'folded' ) );
	// Jump the page's clock 40 minutes, as a sleeping laptop does.
	await page.evaluate( () => {
		const real = Date.now.bind( Date );
		Date.now = () => real() + 40 * 60000;
	} );
	await expect( page.locator( '.nw-toast', { hasText: 'went quiet' } ) ).toBeVisible( { timeout: 5000 } );
	await expect( page.locator( '.nw-now-card.nw-run' ) ).toHaveCount( 0 );
	await expect( page.locator( '#nw-idle-dlg[open]' ) ).toHaveCount( 0 );
	const running = await page.evaluate( async () => {
		const d = await ( await fetch( window.novawork.restUrl + 'sync', { headers: { 'X-WP-Nonce': window.novawork.nonce } } ) ).json();
		return d.data.timeEntries.filter( ( e ) => e.running && e.by === d.me ).length;
	} );
	expect( running ).toBe( 0 );
} );

test( 'the tab was closed: opening the app again finds the timer stopped where it went quiet', async ( { page } ) => {
	const title = unique( 'closed tab' );
	const id = await trackNewTask( page, title );
	// The tab closed 30 minutes ago, after 20 minutes of work.
	const me = await page.evaluate( () => window.novawork.me );
	wpEval(
		`$e = new NovaWork\\Data\\TimeEntryRepository(); $r = $e->running_for( ${ me } ); $e->update( (int) $r['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 50 * 60 ) ) ); update_user_meta( ${ me }, NovaWork\\Members::SEEN_META, time() - 30 * 60 );`
	);
	await page.reload();
	await expect( page.locator( '.nw-toast', { hasText: 'went quiet' } ) ).toContainText( title );
	await expect( page.locator( '.nw-now-card.nw-run' ) ).toHaveCount( 0 );
	const kept = await page.evaluate( async ( taskId ) => {
		const d = await ( await fetch( window.novawork.restUrl + 'sync', { headers: { 'X-WP-Nonce': window.novawork.nonce } } ) ).json();
		const e = d.data.timeEntries.find( ( x ) => x.taskId === taskId && x.by === d.me );
		return e ? Math.round( ( e.end - e.start ) / 60000 ) : null;
	}, id );
	expect( kept ).toBe( 20 );
} );

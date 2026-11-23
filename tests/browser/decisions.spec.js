/**
 * Decisions and comments in a real browser, as member A.
 */
import { test, expect } from '@playwright/test';
import { APP, signIn, serverData, unique, cleanUp, wpEval } from './helpers.js';

test.beforeEach( async ( { context, page } ) => {
	await signIn( context );
	await page.goto( APP + '#decisions' );
	await page.locator( '.nw-dec-cols' ).waitFor();
} );

test.afterEach( async ( { page } ) => {
	await cleanUp( page );
} );

test( 'open, decide, comment, reopen and delete a decision', async ( { page } ) => {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	const Q = unique( 'decision' );
	const find = async () => ( await serverData( page ) ).decisions.find( ( d ) => d.question === Q );

	await page.click( '[data-act="new-decision"]' );
	await page.fill( '#nw-d-q', Q );
	await page.fill( '#nw-d-o', 'Option A or option B' );
	await page.click( '#nw-dlg button[value="save"]' );
	const card = page.locator( '.nw-dec', { hasText: Q } );
	await expect( card ).toHaveCount( 1 );
	await expect( page.locator( '.nw-dec-cols section' ).first().locator( '.nw-dec', { hasText: Q } ) ).toHaveCount( 1 );

	// Deciding needs an answer.
	await card.locator( '[data-act="decide"]' ).click();
	await page.click( '#nw-dlg button[value="save"]' );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 1 );
	await page.fill( '#nw-d-a', 'Option B, because it is simpler.' );
	await page.click( '#nw-dlg button[value="save"]' );
	await expect( page.locator( '.nw-dec-cols section' ).nth( 1 ).locator( '.nw-dec.nw-decided', { hasText: Q } ) ).toHaveCount( 1 );
	await expect.poll( async () => ( await find() )?.status ).toBe( 'decided' );

	// Comment, then reopen.
	await page.locator( '.nw-dec', { hasText: Q } ).locator( '[data-act="edit-decision"]' ).click();
	await page.fill( '#nw-cmt-in', 'Agreed.' );
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#nw-cmts .nw-msg', { hasText: 'Agreed.' } ) ).toHaveCount( 1 );
	await page.check( '#nw-dlg input[name="reopen"]' );
	await page.click( '#nw-dlg button[value="save"]' );
	await expect.poll( async () => ( await find() )?.status ).toBe( 'open' );
	await expect( page.locator( '.nw-dec', { hasText: Q } ).locator( '.nw-cnt' ) ).toHaveText( '1' );

	// Delete with Undo, then let it go.
	await page.locator( '.nw-dec', { hasText: Q } ).locator( '[data-act="edit-decision"]' ).click();
	await page.click( '#nw-dlg [data-del]' );
	await page.click( '#nw-dlg [data-del]' );
	await expect( page.locator( '.nw-dec', { hasText: Q } ) ).toHaveCount( 0 );
	await page.click( '.nw-toast-act' );
	await expect( page.locator( '.nw-dec', { hasText: Q } ) ).toHaveCount( 1 );
	expect( errors ).toEqual( [] );
} );

test( 'team votes: vote, change, withdraw; the decider sees the tally', async ( { page } ) => {
	const Q = unique( 'vote' );
	const find = async () => ( await serverData( page ) ).decisions.find( ( d ) => d.question === Q );
	await page.click( '[data-act="new-decision"]' );
	await page.fill( '#nw-d-q', Q );
	await page.check( '#nw-d-vote' );
	await page.fill( '#nw-d-ol', 'Plan A\nPlan B\nPlan A' );
	await page.click( '#nw-dlg button[value="save"]' );
	const card = page.locator( '.nw-dec', { hasText: Q } );
	await expect( card.locator( '.nw-vote' ) ).toHaveCount( 2 ); // duplicate line dropped
	const me = await page.evaluate( () => window.novawork.me );

	await card.locator( '.nw-vote', { hasText: 'Plan B' } ).click();
	await expect( card.locator( '.nw-vote.nw-on' ) ).toHaveText( /Plan B/ );
	await expect.poll( async () => ( await find() )?.votes[ me ] ).toBe( 'o2' );
	await card.locator( '.nw-vote', { hasText: 'Plan A' } ).click();
	await expect.poll( async () => ( await find() )?.votes[ me ] ).toBe( 'o1' );
	await card.locator( '.nw-vote', { hasText: 'Plan A' } ).click(); // again: withdraw
	await expect.poll( async () => ( await find() )?.votes[ me ] ).toBeUndefined();

	// You named yourself as the decider (the default), so you see Decide and the tally.
	await card.locator( '.nw-vote', { hasText: 'Plan B' } ).click();
	await card.locator( '[data-act="decide"]' ).click();
	await expect( page.locator( '#nw-dlg .nw-votes .nw-vote' ) ).toHaveCount( 2 );
	await page.locator( '#nw-dlg [data-act="pick"]', { hasText: 'Plan B' } ).click();
	await expect( page.locator( '#nw-d-a' ) ).toHaveValue( /^Plan B\. / );
	await page.click( '#nw-dlg button[value="save"]' );
	await expect.poll( async () => ( await find() )?.status ).toBe( 'decided' );
	await expect( page.locator( '.nw-dec.nw-decided', { hasText: Q } ).locator( '.nw-vote[disabled]' ) ).toHaveCount( 2 ); // voting closed
} );

test( 'someone else decides: you see "Waiting on …", not Decide', async ( { page } ) => {
	const Q = unique( 'waiting' );
	const other = await page.evaluate( () => {
		const nw = window.Alpine.store( 'nw' );
		return nw.members.find( ( m ) => m.id !== nw.me && m.id !== 1 );
	} );
	await page.evaluate( async ( [ q, owner ] ) => {
		await fetch( window.novawork.restUrl + 'decisions', { method: 'POST', headers: { 'X-WP-Nonce': window.novawork.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify( { question: q, ownerId: owner } ) } );
	}, [ Q, other.id ] );
	const card = page.locator( '.nw-dec', { hasText: Q } );
	await expect( card.locator( '.nw-waiting' ) ).toHaveText( 'Waiting on ' + other.name );
	await expect( card.locator( '[data-act="decide"]' ) ).toHaveCount( 0 );
} );

test( 'vote order: the leading option rises to the top; "as written" keeps the order', async ( { page } ) => {
	const Q = unique( 'vote order' );
	const order = () => page.locator( '.nw-dec', { hasText: Q } ).locator( '.nw-vote .nw-vote-lbl' ).allTextContents();
	const setOrder = ( v ) => wpEval( `$s = (array) get_option( NovaWork\\Settings::OPTION, array() ); $s['vote_order'] = '${ v }'; update_option( NovaWork\\Settings::OPTION, $s );` );
	try {
		setOrder( 'votes' );
		await page.reload();
		await page.locator( '[data-act="new-decision"]' ).first().waitFor();
		await page.click( '[data-act="new-decision"]' );
		await page.fill( '#nw-d-q', Q );
		await page.check( '#nw-d-vote' );
		await page.fill( '#nw-d-ol', 'Plan A\nPlan B\nPlan C' );
		await page.click( '#nw-dlg button[value="save"]' );
		await expect.poll( order ).toEqual( [ 'Plan A', 'Plan B', 'Plan C' ] ); // no votes: as written

		await page.locator( '.nw-dec', { hasText: Q } ).locator( '.nw-vote', { hasText: 'Plan C' } ).click();
		await expect.poll( order ).toEqual( [ 'Plan C', 'Plan A', 'Plan B' ] );

		setOrder( 'written' );
		await page.reload();
		await expect.poll( order ).toEqual( [ 'Plan A', 'Plan B', 'Plan C' ] );
	} finally {
		setOrder( 'votes' );
	}
} );

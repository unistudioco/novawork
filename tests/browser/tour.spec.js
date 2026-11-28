/**
 * The welcome tour (owner, 2026-11-23): it starts by itself on a member's
 * first visit, goes to each page and spotlights the part it talks about,
 * is remembered once finished or skipped, and can be taken again from the
 * menu. Administrators also get "Workspace settings" in that menu.
 */
import { test, expect } from './offline.js';
import { APP, signIn, wpEval } from './helpers.js';

const login = ( which ) => ( process.env[ 'NOVAWORK_USER_' + which ] || ( which === 'A' ? 'alex:alex' : 'sam:sam' ) ).split( ':' )[ 0 ];
const resetTour = ( user ) => wpEval( `$u = get_user_by( 'login', '${ user }' ); delete_user_meta( $u->ID, NovaWork\\Rest\\TourController::SEEN_META );` );
const seenTour = ( user ) => wpEval( `$u = get_user_by( 'login', '${ user }' ); echo get_user_meta( $u->ID, NovaWork\\Rest\\TourController::SEEN_META, true ) ? 'yes' : 'no';` ).trim();

test.afterAll( () => {
	// Leave the test member as the setup left them: tour seen.
	wpEval( `$u = get_user_by( 'login', '${ login( 'A' ) }' ); update_user_meta( $u->ID, NovaWork\\Rest\\TourController::SEEN_META, time() );` );
} );

test( 'first visit: the tour starts, walks the pages, and Esc ends it for good', async ( { context, page } ) => {
	resetTour( login( 'A' ) );
	await signIn( context );
	await page.goto( APP + '#focus' );
	const card = page.locator( '.nw-tour-card' );
	await expect( card ).toContainText( 'Welcome to' );
	await expect( card ).toContainText( '1 of' );

	// Next: the spotlight lands on the Focus timer, then on "your three".
	await page.keyboard.press( 'ArrowRight' );
	await expect( card ).toContainText( 'Your day and the timer' );
	await page.keyboard.press( 'ArrowRight' );
	await expect( card ).toContainText( 'Your three' );
	const slots = await page.locator( '.nw-slots' ).boundingBox();
	await expect.poll( async () => {
		const s = await page.locator( '.nw-tour-spot' ).boundingBox();
		return s && s.x <= slots.x && s.y <= slots.y + 1 && s.x + s.width >= slots.x + slots.width;
	} ).toBe( true );

	// The app's own shortcuts wait while the tour is open (N would open a new task).
	await page.keyboard.press( 'n' );
	await expect( page.locator( '#nw-dlg[open]' ) ).toHaveCount( 0 );

	// On to the board: the tour goes to the page.
	await page.click( '[data-tour="next"]' );
	await expect( card ).toContainText( 'The board' );
	await expect.poll( () => page.evaluate( () => window.location.hash ) ).toBe( '#board' );
	await page.click( '[data-tour="back"]' );
	await expect( card ).not.toContainText( 'The board' );

	// Roadmap and Time spotlight the timeline and the analytics, not their toolbars.
	for ( const [ title, sel ] of [ [ 'Roadmap', '#nw-tl-scroll' ], [ 'Time', '#nw-tbody' ] ] ) {
		while ( ( await card.locator( 'h3' ).textContent() ) !== title ) {
			// One step at a time: wait for the card to move on before clicking again.
			const before = await card.locator( '.nw-tour-step' ).textContent();
			await page.click( '[data-tour="next"]' );
			await expect( card.locator( '.nw-tour-step' ) ).not.toHaveText( before );
		}
		await expect.poll( async () => {
			const t = await page.locator( sel ).boundingBox();
			const s = await page.locator( '.nw-tour-spot' ).boundingBox();
			return !! t && !! s && s.height > 150 && s.y < t.y + 60 && s.y + s.height > t.y + 100;
		} ).toBe( true );
	}

	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '.nw-tour' ) ).toHaveCount( 0 );
	await expect.poll( () => page.evaluate( () => window.location.hash ) ).toBe( '#focus' );
	await expect.poll( () => seenTour( login( 'A' ) ) ).toBe( 'yes' );

	await page.reload();
	await page.locator( '.nw-focus' ).waitFor();
	await page.waitForTimeout( 1500 );
	await expect( page.locator( '.nw-tour' ) ).toHaveCount( 0 );
} );

test( 'take the tour again from the menu, to the end; members see no settings link', async ( { context, page } ) => {
	await signIn( context );
	await page.goto( APP + '#board' );
	await page.locator( '.nw-board' ).waitFor();
	await page.click( '#nw-crew-btn' );
	await expect( page.locator( '.nw-pmenu-item[href*="novawork-settings"]' ) ).toHaveCount( 0 );
	await page.locator( '.nw-pmenu-item', { hasText: 'Take the tour' } ).click();
	const card = page.locator( '.nw-tour-card' );
	await expect( card ).toContainText( 'Welcome to' );
	const total = Number( ( await card.locator( '.nw-tour-step' ).textContent() ).split( ' of ' )[ 1 ] );
	for ( let i = 1; i < total; i++ ) {
		await page.click( '[data-tour="next"]' );
		await expect( card ).toContainText( `${ i + 1 } of ${ total }` );
	}
	await expect( page.locator( '[data-tour="next"]' ) ).toHaveText( 'Start working' );
	await page.click( '[data-tour="next"]' );
	await expect( page.locator( '.nw-tour' ) ).toHaveCount( 0 );
	// Back where it started.
	await expect.poll( () => page.evaluate( () => window.location.hash ) ).toBe( '#board' );
} );

test( 'administrators reach the settings screen from the workspace menu', async ( { browser } ) => {
	const context = await browser.newContext();
	const [ user, pass ] = ( process.env.NOVAWORK_ADMIN || 'admin:admin' ).split( ':' );
	const base = process.env.NOVAWORK_URL || 'http://novawork.local';
	await context.addCookies( [ { name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base } ] );
	await context.request.post( base + '/wp-login.php', { form: { log: user, pwd: pass, testcookie: '1' }, maxRedirects: 0 } );
	const page = await context.newPage();
	await page.goto( APP + '#focus' );
	await page.locator( '.nw-focus' ).waitFor();
	await page.click( '#nw-crew-btn' );
	const link = page.locator( '.nw-pmenu-item', { hasText: 'Workspace settings' } );
	await expect( link ).toHaveAttribute( 'href', /wp-admin\/admin\.php\?page=novawork-settings/ );
	await link.click();
	await page.locator( '.nw-admin #nw-s-workspace' ).waitFor();
	await context.close();
} );

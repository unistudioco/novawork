/**
 * Shared helpers for the browser tests.
 */
export const APP = '/novawork/';

/** Sign a member in on a browser context (cookie auth, as a person would). */
export async function signIn( context, which = 'A' ) {
	const [ user, pass ] = ( process.env[ 'NOVAWORK_USER_' + which ] || 'alex:alex' ).split( ':' );
	const base = process.env.NOVAWORK_URL || 'http://novawork.local';
	await context.addCookies( [ { name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base } ] );
	const res = await context.request.post( base + '/wp-login.php', { form: { log: user, pwd: pass, testcookie: '1' }, maxRedirects: 0 } );
	if ( res.status() !== 302 ) {
		throw new Error( 'Sign-in failed for ' + user );
	}
}

/** Read the server's current data the way the app does. */
export const serverData = ( page ) =>
	page.evaluate( async () => {
		const r = await fetch( window.novawork.restUrl + 'sync', { headers: { 'X-WP-Nonce': window.novawork.nonce } } );
		return ( await r.json() ).data;
	} );

/** Drag with real pointer moves (Playwright's dragTo scrolls mid-drag and picks the wrong card). */
export async function pointerDrag( page, from, to ) {
	const a = await from.boundingBox();
	const b = await to.boundingBox();
	await page.mouse.move( a.x + a.width / 2, a.y + 20 );
	await page.mouse.down();
	await page.mouse.move( b.x + b.width / 2, b.y + 60, { steps: 12 } );
	await page.mouse.up();
}

/** New task through the N shortcut. */
export async function newTask( page, title ) {
	await page.keyboard.press( 'n' );
	await page.fill( '#nw-f-title', title );
	await page.click( '#nw-dlg button[value="save"]' );
	await page.locator( 'article', { hasText: title } ).waitFor();
}

/** Open a card and delete it (two clicks). */
export async function deleteTask( page, title ) {
	await page.locator( 'article', { hasText: title } ).click();
	await page.click( '#nw-dlg [data-del]' );
	await page.click( '#nw-dlg [data-del]' );
}

/** A title no other test run can collide with. */
export const unique = ( label ) => `E2E ${ label } ${ Date.now().toString( 36 ) }`;

/**
 * Delete every task and decision a test run left behind ("E2E …"), even
 * when the test failed halfway. Runs in the page, through the API.
 */
export const cleanUp = ( page ) =>
	page.evaluate( async () => {
		if ( ! window.novawork ) {
			return;
		}
		const h = { 'X-WP-Nonce': window.novawork.nonce };
		const r = await fetch( window.novawork.restUrl + 'sync', { headers: h } );
		const d = await r.json();
		for ( const t of d.data.tasks.filter( ( x ) => x.title.startsWith( 'E2E ' ) ) ) {
			await fetch( window.novawork.restUrl + 'tasks/' + t.id, { method: 'DELETE', headers: h } );
		}
		for ( const x of d.data.decisions.filter( ( y ) => y.question.startsWith( 'E2E ' ) ) ) {
			await fetch( window.novawork.restUrl + 'decisions/' + x.id, { method: 'DELETE', headers: h } );
		}
	} );

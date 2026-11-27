import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
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
	// A "Were you working?" question left by an earlier test's automatic stop
	// (1.2.0) would open over the page: every test starts without one.
	wpEval( `$u = get_user_by( 'login', '${ user }' ); if ( $u ) { delete_user_meta( $u->ID, NovaWork\\Services\\TimeEdits::PENDING_META ); }` );
}

/** Read the server's current data the way the app does. */
export const serverData = ( page ) =>
	page.evaluate( async () => {
		const r = await fetch( window.novawork.restUrl + 'sync', { headers: { 'X-WP-Nonce': window.novawork.nonce } } );
		return ( await r.json() ).data;
	} );

/** Drag with real pointer moves (Playwright's dragTo scrolls mid-drag and picks the wrong card). */
export async function pointerDrag( page, from, to ) {
	// The board redraws when a sync poll lands, replacing its cards: an element
	// found a moment ago can be detached when measured (boundingBox() → null).
	// Measure again until it is on screen.
	const measure = async ( loc ) => {
		for ( let i = 0; i < 30; i++ ) {
			const box = await loc.boundingBox();
			if ( box ) {
				return box;
			}
			await page.waitForTimeout( 100 );
		}
		throw new Error( 'Drag: ' + loc + ' is not on screen' );
	};
	const a = await measure( from );
	const b = await measure( to );
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
 * when the test failed halfway. Runs in the page, through the API, so it
 * deletes only what that member may delete; cleanLeftovers() (after the
 * whole run) catches the rest.
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

/**
 * Run PHP inside the dev site through WP-CLI (for cleanup the API does not
 * offer, such as removing test chat messages). Uses .dev.env settings.
 *
 * @param {string} php Code for `wp eval`.
 */
export function wpEval( php ) {
	const args = [];
	if ( process.env.NOVAWORK_MYSQL_SOCKET ) {
		args.push( '-d', 'mysqli.default_socket=' + process.env.NOVAWORK_MYSQL_SOCKET );
	}
	const wp = execFileSync( 'bash', [ '-lc', 'command -v wp' ] ).toString().trim();
	return execFileSync( 'php', [ ...args, wp, '--path=' + resolve( process.env.NOVAWORK_WP_PATH || '' ), 'eval', php ] ).toString();
}

let workspacePath = null;

/**
 * The path the workspace really lives at on the dev site: "/novawork/" (or
 * the chosen address), or "/" when Settings → "Where it lives" is the home
 * page. APP still works for visiting it (an old address forwards), but
 * checks of the address itself (the app's manifest and worker) use this.
 */
export function appPath() {
	if ( null === workspacePath ) {
		workspacePath = wpEval( "echo wp_parse_url( NovaWork\\App\\Route::url(), PHP_URL_PATH ) ?: '/';" ).trim();
	}
	return workspacePath;
}

/** Remove chat messages a test sent (text containing "E2E "), with their files and notifications. */
export const cleanChat = () =>
	wpEval( `global $wpdb; $r = new NovaWork\\Data\\MessageRepository(); $n = new NovaWork\\Data\\NotificationRepository(); foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE body LIKE %s", $r->table_name(), "%E2E %" ) ) as $id ) { $r->delete( (int) $id ); $n->delete_for_object( "message", (int) $id ); }` );

/**
 * Delete every task and decision still titled "E2E …", whoever made it, as
 * an administrator through the API (a member's cleanUp() can't delete an
 * administrator's task, and a run stopped halfway skips a test's finally).
 */
export const cleanLeftovers = () => {
	const admin = ( process.env.NOVAWORK_ADMIN || 'admin:admin' ).split( ':' )[ 0 ];
	return wpEval( `global $wpdb; $u = get_user_by( 'login', ${ JSON.stringify( admin ) } ); if ( ! $u ) { return; } wp_set_current_user( $u->ID ); foreach ( array( 'tasks' => 'title', 'decisions' => 'question' ) as $t => $col ) { foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE %i LIKE %s', NovaWork\\Data\\Db::table( $t ), $col, 'E2E %' ) ) as $id ) { rest_do_request( new WP_REST_Request( 'DELETE', '/novawork/v1/' . $t . '/' . (int) $id ) ); } }` );
};

/** Remove the team activity rows about test items ("E2E …" titles). */
export const cleanActivity = () =>
	wpEval( `global $wpdb; $r = new NovaWork\\Data\\ActivityRepository(); foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE data LIKE %s", $r->table_name(), "%E2E %" ) ) as $id ) { $r->delete( (int) $id ); }` );

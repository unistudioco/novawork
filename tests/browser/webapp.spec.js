/**
 * The installable app (owner request, 1.1.0): a manifest with the
 * workspace's name and icons, an app worker that shows an offline page,
 * "Install app" in the team menu when the browser offers it, and a setting
 * that turns it all off.
 *
 * Workers need a secure page; the dev site is plain http, so this browser
 * treats it as secure (as in alerts.spec.js).
 */
import { test, expect } from './offline.js';
import { APP, appPath, signIn, wpEval } from './helpers.js';

const BASE = process.env.NOVAWORK_URL || 'http://novawork.local';

test.use( {
	launchOptions: [
		async ( { launchOptions }, use ) => {
			await use( { ...launchOptions, args: [ ...( launchOptions.args || [] ), '--unsafely-treat-insecure-origin-as-secure=' + BASE ] } );
		},
		{ scope: 'worker' },
	],
} );

const setSetting = ( key, php ) => wpEval( `$s = (array) get_option( NovaWork\\Settings::OPTION, array() ); $s['${ key }'] = ${ php }; update_option( NovaWork\\Settings::OPTION, $s );` );
const unsetSetting = ( key ) => wpEval( `$s = (array) get_option( NovaWork\\Settings::OPTION, array() ); unset( $s['${ key }'] ); update_option( NovaWork\\Settings::OPTION, $s );` );

test.beforeEach( async ( { context, page } ) => {
	await signIn( context );
	await page.goto( APP + '#focus' );
	await page.locator( '.nw-hdr' ).waitFor();
} );

/** An icon's size and the colour of one pixel, read in the page. */
const readIcon = ( page, src, x, y ) =>
	page.evaluate(
		async ( [ url, px, py ] ) => {
			const res = await fetch( url, { credentials: 'omit' } );
			const bmp = await createImageBitmap( await res.blob() );
			const c = new OffscreenCanvas( bmp.width, bmp.height );
			const g = c.getContext( '2d' );
			g.drawImage( bmp, 0, 0 );
			const d = g.getImageData( px, py, 1, 1 ).data;
			const hex = '#' + [ d[ 0 ], d[ 1 ], d[ 2 ] ].map( ( v ) => v.toString( 16 ).padStart( 2, '0' ) ).join( '' );
			return { type: res.headers.get( 'content-type' ), w: bmp.width, h: bmp.height, hex, alpha: d[ 3 ] };
		},
		[ src, x, y ]
	);

test( 'the manifest names the workspace and its icons are the header mark', async ( { page } ) => {
	const href = await page.locator( 'link[rel="manifest"]' ).getAttribute( 'href' );
	// Browsers fetch it without cookies.
	const manifest = await page.evaluate( async ( url ) => ( await fetch( url, { credentials: 'omit' } ) ).json(), href );
	expect( manifest.name ).toBe( await page.evaluate( () => document.title ) );
	expect( manifest.display ).toBe( 'standalone' );
	expect( new URL( manifest.start_url ).pathname ).toBe( appPath() );
	expect( manifest.start_url.startsWith( manifest.scope ) ).toBe( true );
	expect( manifest.icons.map( ( i ) => i.sizes + ' ' + i.purpose ) ).toEqual( [ '192x192 any', '512x512 any', '512x512 maskable' ] );

	const any = await readIcon( page, manifest.icons[ 1 ].src, 256, 30 );
	expect( any ).toMatchObject( { type: 'image/png', w: 512, h: 512, hex: '#2065f5', alpha: 255 } );
	// Rounded corners.
	expect( ( await readIcon( page, manifest.icons[ 1 ].src, 2, 2 ) ).alpha ).toBe( 0 );
	expect( await readIcon( page, manifest.icons[ 0 ].src, 0, 0 ) ).toMatchObject( { w: 192, h: 192, alpha: 0 } );
	// Maskable and home-screen icons are edge to edge.
	expect( await readIcon( page, manifest.icons[ 2 ].src, 2, 2 ) ).toMatchObject( { hex: '#2065f5', alpha: 255 } );
	const apple = await page.locator( 'link[rel="apple-touch-icon"]' ).getAttribute( 'href' );
	expect( await readIcon( page, apple, 1, 1 ) ).toMatchObject( { w: 180, h: 180, hex: '#2065f5', alpha: 255 } );
	await expect( page.locator( 'meta[name="theme-color"]' ) ).toHaveCount( 2 );
} );

test( 'the icon follows the accent colour', async ( { page } ) => {
	try {
		setSetting( 'accent', "'#16a34a'" );
		await page.reload();
		const href = await page.locator( 'link[rel="manifest"]' ).getAttribute( 'href' );
		const manifest = await page.evaluate( async ( url ) => ( await fetch( url ) ).json(), href );
		expect( ( await readIcon( page, manifest.icons[ 1 ].src, 256, 30 ) ).hex ).toBe( '#16a34a' );
	} finally {
		unsetSetting( 'accent' );
	}
} );

test( 'opened without a connection, the installed app shows its offline page', async ( { page, context } ) => {
	await expect.poll( () => page.evaluate( async () => !! ( await navigator.serviceWorker.getRegistration( location.href ) )?.active ) ).toBe( true );
	const scope = await page.evaluate( async () => ( await navigator.serviceWorker.getRegistration( location.href ) ).scope );
	expect( new URL( scope ).pathname ).toBe( appPath() );

	await context.setOffline( true );
	try {
		await page.goto( appPath() );
		await expect( page.locator( 'h1' ) ).toHaveText( 'You’re offline' );
		await expect( page.locator( 'button' ) ).toHaveText( 'Try again' );
	} finally {
		await context.setOffline( false );
	}
	// Back online, the page reloads by itself into the workspace (it checks every 5 seconds).
	await expect( page.locator( '.nw-hdr' ) ).toBeVisible( { timeout: 15000 } );
} );

test( '"Install app" appears when the browser offers it and opens its dialog', async ( { page } ) => {
	await page.click( '#nw-crew-btn' );
	await expect( page.locator( '.nw-pmenu-item', { hasText: 'Install app' } ) ).toHaveCount( 0 );
	await page.keyboard.press( 'Escape' );

	await page.evaluate( () => {
		window.__prompted = 0;
		const e = new Event( 'beforeinstallprompt', { cancelable: true } );
		e.prompt = async () => {
			window.__prompted++;
			return { outcome: 'accepted' };
		};
		window.dispatchEvent( e );
		window.__prevented = e.defaultPrevented;
	} );
	// The browser's own banner waits; the menu offers it instead.
	expect( await page.evaluate( () => window.__prevented ) ).toBe( true );
	await page.click( '#nw-crew-btn' );
	const row = page.locator( '.nw-pmenu-item', { hasText: 'Install app' } );
	await row.click();
	expect( await page.evaluate( () => window.__prompted ) ).toBe( 1 );
	await page.click( '#nw-crew-btn' );
	await expect( row ).toHaveCount( 0 );
} );

test( 'turned off: no manifest, and the app worker goes away', async ( { page } ) => {
	await expect.poll( () => page.evaluate( async () => !! ( await navigator.serviceWorker.getRegistration( location.href ) )?.active ) ).toBe( true );
	try {
		setSetting( 'installable', 'false' );
		await page.reload();
		await expect( page.locator( 'link[rel="manifest"]' ) ).toHaveCount( 0 );
		expect( await page.evaluate( async () => ( await fetch( '/?novawork=manifest' ) ).status ) ).toBe( 404 );
		await expect.poll( () => page.evaluate( async () => ( await navigator.serviceWorker.getRegistrations() ).some( ( r ) => ( r.active || r.installing || r.waiting )?.scriptURL.includes( 'novawork=app-worker' ) ) ) ).toBe( false );
	} finally {
		unsetSetting( 'installable' );
	}
} );

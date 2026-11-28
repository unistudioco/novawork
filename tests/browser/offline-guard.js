/**
 * The offline guard as a Playwright fixture, with no imports of its own, so
 * a test suite that has its own copy of @playwright/test (an add-on's) can
 * extend its own `test` with it: `base.extend( offlineFixtures( expect ) )`.
 * Every page may load only the dev site (and localhost); any other request
 * is aborted, and the test that made it fails, naming the address. Contexts
 * made with browser.newContext() in a test are guarded too.
 */
const site = new URL( process.env.NOVAWORK_URL || 'http://novawork.local' ).hostname;
const LOCAL = new Set( [ site, 'localhost', '127.0.0.1', '[::1]' ] );

/** Whether an address is the dev site or this machine (data: and blob: too). */
export const isLocal = ( raw ) => {
	const url = new URL( raw );
	return [ 'data:', 'blob:', 'about:' ].includes( url.protocol ) || LOCAL.has( url.hostname );
};

/** The fixture, for test.extend(); expect is the suite's own. */
export const offlineFixtures = ( expect ) => ( {
	offlineGuard: [
		async ( { browser, context }, use ) => {
			const outside = [];
			const guard = ( ctx ) =>
				ctx.route(
					( url ) => ! isLocal( url.href ),
					( route ) => {
						outside.push( route.request().url() );
						route.abort( 'blockedbyclient' );
					}
				);
			await guard( context );
			const newContext = browser.newContext.bind( browser );
			browser.newContext = async ( ...args ) => {
				const ctx = await newContext( ...args );
				await guard( ctx );
				return ctx;
			};
			await use();
			browser.newContext = newContext;
			expect( outside, 'requests outside the dev site' ).toEqual( [] );
		},
		{ auto: true },
	],
} );

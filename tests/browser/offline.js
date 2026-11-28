/**
 * Browser tests never reach the internet: every page may load only the dev
 * site (and localhost). Any other request is aborted, and the test that made
 * it fails, naming the address. Contexts made with browser.newContext() in a
 * test are guarded too. Specs import { test, expect } from here, not from
 * @playwright/test. (The site's own outgoing requests are blocked by the
 * tests/fixtures/test-offline.php mu-plugin; teardown.js fails on those.)
 */
import { test as base, expect } from '@playwright/test';

const site = new URL( process.env.NOVAWORK_URL || 'http://novawork.local' ).hostname;
const LOCAL = new Set( [ site, 'localhost', '127.0.0.1', '[::1]' ] );

/** Whether an address is the dev site or this machine (data: and blob: too). */
export const isLocal = ( raw ) => {
	const url = new URL( raw );
	return [ 'data:', 'blob:', 'about:' ].includes( url.protocol ) || LOCAL.has( url.hostname );
};

export const test = base.extend( {
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

export { expect };

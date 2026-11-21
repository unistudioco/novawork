/**
 * Calls to the NovaWork REST API (novawork/v1) with the wp_rest nonce.
 *
 * Every response is parsed as JSON before anything uses it; a body that is
 * not JSON is an error, never something to put in the page.
 */
const boot = window.novawork || {};
let nonce = boot.nonce || '';

/** Sync hands out a fresh nonce on every poll, so a long-open tab never goes stale. */
export function setNonce( value ) {
	if ( value ) {
		nonce = value;
	}
}

export function currentNonce() {
	return nonce;
}

/**
 * Build a route URL. Works with pretty permalinks (/wp-json/novawork/v1/)
 * and plain ones (?rest_route=/novawork/v1/).
 *
 * @param {string} path   Route path, e.g. "sync".
 * @param {Object} params Query parameters.
 */
export function routeUrl( path, params = {} ) {
	const url = new URL( boot.restUrl, window.location.href );
	const clean = String( path ).replace( /^\//, '' );
	if ( url.searchParams.has( 'rest_route' ) ) {
		url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ).replace( /\/?$/, '/' ) + clean );
	} else {
		url.pathname = url.pathname.replace( /\/?$/, '/' ) + clean;
	}
	for ( const [ key, value ] of Object.entries( params ) ) {
		if ( value !== undefined && value !== null && value !== '' ) {
			url.searchParams.set( key, String( value ) );
		}
	}
	return url.toString();
}

/**
 * @param {string} method HTTP method.
 * @param {string} path   Route path.
 * @param {Object} [body] JSON body, FormData, or query params for GET.
 */
export async function api( method, path, body ) {
	const isForm = body instanceof FormData;
	const isGet = method === 'GET';
	const headers = { Accept: 'application/json', 'X-WP-Nonce': nonce };
	if ( body && ! isForm && ! isGet ) {
		headers[ 'Content-Type' ] = 'application/json';
	}
	const response = await fetch( routeUrl( path, isGet ? body : {} ), {
		method,
		credentials: 'same-origin',
		headers,
		body: isGet || ! body ? undefined : isForm ? body : JSON.stringify( body ),
	} );
	const text = await response.text();
	let data = null;
	try {
		data = text ? JSON.parse( text ) : null;
	} catch ( e ) {
		throw Object.assign( new Error( 'The server did not answer with JSON.' ), { status: response.status } );
	}
	if ( ! response.ok ) {
		throw Object.assign( new Error( ( data && data.message ) || 'Request failed' ), { status: response.status, code: data && data.code, data } );
	}
	return data;
}

export const get = ( path, params ) => api( 'GET', path, params );
export const post = ( path, body ) => api( 'POST', path, body || {} );
export const patch = ( path, body ) => api( 'PATCH', path, body || {} );
export const del = ( path ) => api( 'DELETE', path );

/**
 * Polls GET /sync: every few seconds while the tab is visible, slowly while
 * it is hidden, at once when it comes back. Presence rides on the same poll.
 * No websockets and no outside services, so it works on any host.
 */
import { get, setNonce } from './lib/api.js';

const HIDDEN_MS = 30000;
const MAX_BACKOFF_MS = 60000;

export function startSync( store ) {
	let timer = null;
	let busy = false;
	let failures = 0;

	async function poll() {
		if ( busy ) {
			return;
		}
		busy = true;
		clearTimeout( timer );
		try {
			let more = true;
			while ( more ) {
				const payload = await get( 'sync', store.cursor === null ? {} : { since: store.cursor } );
				setNonce( payload.nonce );
				store.apply( payload );
				more = !! payload.more;
			}
			failures = 0;
		} catch ( error ) {
			failures++;
			// Signed out, or the nonce expired while the laptop slept: a reload
			// either sends them to the login screen or gets a fresh nonce.
			if ( error.status === 401 || error.code === 'rest_cookie_invalid_nonce' ) {
				window.location.reload();
				return;
			}
		} finally {
			busy = false;
			schedule();
		}
	}

	function schedule() {
		clearTimeout( timer );
		const base = Math.max( 2, Number( store.settings.pollSeconds ) || 4 ) * 1000;
		const delay = document.hidden ? HIDDEN_MS : Math.min( MAX_BACKOFF_MS, base * 2 ** Math.min( failures, 4 ) );
		timer = setTimeout( poll, delay );
	}

	document.addEventListener( 'visibilitychange', () => {
		if ( ! document.hidden ) {
			poll();
		}
	} );

	schedule();
	return { poll };
}

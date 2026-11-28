/**
 * Polls GET /sync: every few seconds while the tab is visible, slowly while
 * it is hidden, at once when it comes back. Presence rides on the same poll.
 * No websockets and no outside services, so it works on any host.
 */
import { get, setNonce } from './lib/api.js';
import { lastActive } from './lib/activity.js';
import { serverNow } from './store.js';

const HIDDEN_MS = 30000;
const MAX_BACKOFF_MS = 60000;

export function startSync( store ) {
	let timer = null;
	let busy = false;
	let failures = 0;
	// The last activity already reported (local ms).
	let sentActive = 0;

	// A poll in flight; asking again waits for it (views/idle.js needs fresh data).
	let inflight = null;

	function poll() {
		if ( ! inflight ) {
			inflight = run().finally( () => {
				inflight = null;
			} );
		}
		return inflight;
	}

	async function run() {
		if ( busy ) {
			return;
		}
		busy = true;
		clearTimeout( timer );
		try {
			let more = true;
			while ( more ) {
				const args = store.cursor === null ? {} : { since: store.cursor };
				// Activity on this device, so the member's other devices know
				// they are here (views/idle.js).
				const active = lastActive();
				if ( active > sentActive ) {
					args.active = Math.round( active + ( serverNow() - Date.now() ) );
				}
				const payload = await get( 'sync', args );
				// Counted as sent only once it arrived (offline, it goes with the next poll).
				sentActive = Math.max( sentActive, args.active ? active : 0 );
				setNonce( payload.nonce );
				store.apply( payload );
				more = !! payload.more;
			}
			failures = 0;
			if ( store.offlineSince ) {
				// Back online (1.2.0): a Stop pressed offline is sent now (views/actions.js).
				store.lastOffline = [ store.offlineSince, Date.now() ];
				store.offlineSince = 0;
				window.dispatchEvent( new CustomEvent( 'nw:online' ) );
			}
		} catch ( error ) {
			failures++;
			if ( ! error.status && ! store.offlineSince ) {
				// No answer at all: the network, not the site.
				store.offlineSince = Date.now();
				window.dispatchEvent( new CustomEvent( 'nw:offline' ) );
			}
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

	// A poll that starts after now (one in flight may predate a write the
	// caller just made): app.sync() for add-ons.
	async function fresh() {
		if ( inflight ) {
			await inflight;
		}
		return poll();
	}

	schedule();
	return { poll, fresh };
}

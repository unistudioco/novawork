/**
 * Desktop alerts (owner request, 2026-11-22).
 *
 * While the workspace is open in a tab that is hidden or not focused, a new
 * notification for you also shows as a browser alert; clicking it brings
 * the tab forward and opens the item, as a click in the bell would. Each
 * member turns alerts on for their own device from the bell, and an
 * administrator can switch the feature off for everyone (Settings → Team
 * and work → Desktop alerts).
 *
 * No outside service: alerts come from the same /sync poll as everything
 * else, so a workspace that is not open anywhere sends none. A small worker
 * (assets/alerts-worker.js) shows them, because Android browsers show
 * notifications only through one; where it cannot register, the page shows
 * them itself. Several open tabs alert once (a shared "last alerted" mark,
 * plus a per-notification tag that replaces instead of stacking).
 */
import { safeEffect } from '../lib/effect.js';
import { serverNow } from '../store.js';
import { store } from './model.js';

const PREF = 'nw.alerts';
const LAST = 'nw.alertedId';
/* Only alert for what just happened, never for old rows a full sync brings back. */
const FRESH_MS = 10 * 60 * 1000;

const boot = () => ( window.novawork || {} ).alerts || {};
const getPref = ( k ) => {
	try {
		return window.localStorage.getItem( k );
	} catch ( e ) {
		return null;
	}
};
const setPref = ( k, v ) => {
	try {
		window.localStorage.setItem( k, v );
	} catch ( e ) {}
};

const hooks = { text: null, open: null };
let seen = 0;
let worker = null;

/** Whether this browser and address can show alerts (they need https). */
export const supported = () => 'Notification' in window && !! window.isSecureContext;

/**
 * Where alerts stand on this device.
 *
 * @return {'on'|'off'|'blocked'|'unavailable'} State.
 */
export function alertState() {
	if ( ! supported() || store().settings.desktopAlerts === false ) {
		return 'unavailable';
	}
	if ( Notification.permission === 'denied' ) {
		return 'blocked';
	}
	return Notification.permission === 'granted' && getPref( PREF ) === 'on' ? 'on' : 'off';
}

/** Resolve once a registration has an active worker (or give up after 10 s). */
function whenActive( reg ) {
	if ( reg.active ) {
		return Promise.resolve( reg );
	}
	const w = reg.installing || reg.waiting;
	if ( ! w ) {
		return Promise.resolve( null );
	}
	return new Promise( ( resolve ) => {
		const t = setTimeout( () => resolve( reg.active ? reg : null ), 10000 );
		w.addEventListener( 'statechange', () => {
			if ( w.state === 'activated' ) {
				clearTimeout( t );
				resolve( reg );
			}
		} );
	} );
}

/** The worker's registration, or null (then the page shows alerts itself). */
function registration() {
	if ( ! worker ) {
		const url = boot().worker;
		worker =
			url && 'serviceWorker' in navigator
				? navigator.serviceWorker
					.register( url )
					.then( whenActive )
					.catch( () => null )
				: Promise.resolve( null );
	}
	return worker;
}

/** An alert's sentence as plain text (the bell's wording without its markup). */
function plain( html ) {
	const box = document.createElement( 'div' );
	box.innerHTML = html;
	return ( box.textContent || '' ).replace( /\s+/g, ' ' ).trim();
}

async function show( n ) {
	const [ , html ] = hooks.text( n );
	const title = boot().name || document.title;
	const options = {
		body: plain( html ),
		tag: 'novawork-' + n.id,
		icon: boot().icon || undefined,
		data: { id: n.id, appUrl: ( window.novawork || {} ).appUrl || '' },
	};
	const reg = await registration();
	if ( reg ) {
		await reg.showNotification( title, options );
		return;
	}
	try {
		const a = new Notification( title, options );
		a.onclick = () => {
			window.focus();
			a.close();
			hooks.open( n );
		};
	} catch ( e ) {
		// Browsers that only allow alerts through a worker: nothing to show.
	}
}

function maybeShow( n ) {
	if ( n.read || alertState() !== 'on' ) {
		return;
	}
	// You are looking at the workspace: the bell's badge is enough.
	if ( document.visibilityState === 'visible' && document.hasFocus() ) {
		return;
	}
	if ( serverNow() - n.at > FRESH_MS ) {
		return;
	}
	// Another tab already alerted for this one.
	if ( Number( getPref( LAST ) || 0 ) >= n.id ) {
		return;
	}
	setPref( LAST, String( n.id ) );
	show( n ).catch( () => {} );
}

/**
 * Ask the browser for permission (from a click) and turn alerts on here.
 *
 * @return {Promise<string>} The new state.
 */
export async function turnOn() {
	if ( ! supported() ) {
		return alertState();
	}
	const answer = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
	if ( answer === 'granted' ) {
		setPref( PREF, 'on' );
		registration();
	}
	return alertState();
}

export function turnOff() {
	setPref( PREF, 'off' );
	return alertState();
}

/**
 * Start watching for new notifications.
 *
 * @param {Object}   h      What the bell provides.
 * @param {Function} h.text A notification's [kind, html].
 * @param {Function} h.open Open a notification's item and mark it read.
 */
export function mountAlerts( h ) {
	hooks.text = h.text;
	hooks.open = h.open;
	if ( ! supported() ) {
		return;
	}
	// What is already there when the page opens is not news.
	seen = Math.max( 0, ...Object.keys( store().notifications ).map( Number ) );
	if ( 'serviceWorker' in navigator ) {
		navigator.serviceWorker.addEventListener( 'message', ( e ) => {
			const id = e.data && e.data.novaworkAlert;
			const n = id && store().notifications[ id ];
			if ( n ) {
				hooks.open( n );
			}
		} );
	}
	if ( alertState() === 'on' ) {
		registration();
	}
	safeEffect( () => {
		const fresh = Object.values( store().notifications ).filter( ( n ) => n.id > seen );
		if ( ! fresh.length ) {
			return;
		}
		seen = Math.max( seen, ...fresh.map( ( n ) => n.id ) );
		// Outside the effect: showing an alert must not run inside Alpine's update.
		setTimeout( () => fresh.sort( ( a, b ) => a.id - b.id ).forEach( maybeShow ), 0 );
	} );
}

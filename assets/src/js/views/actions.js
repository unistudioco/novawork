/**
 * Writes to the API with the prototype's feedback: a toast on success, a
 * calm error toast on failure, and an immediate sync so every view updates.
 */
import { post, get } from '../lib/api.js';
import { __, sprintf } from '../lib/i18n.js';
import { dur, dayStart } from '../lib/format.js';
import { serverNow } from '../store.js';
import { toast } from '../lib/ui.js';
import { store, task, myRun, tracked, me } from './model.js';
import { askIdlePermission } from './idle.js';

let sync = null;
export function bindSync( s ) {
	sync = s;
}
export const poll = () => sync && sync.poll();

/**
 * Run a write. Server messages for 4xx errors are already written for
 * people (and translated), so they are shown as they come.
 *
 * @param {Function} fn Returns a promise.
 * @param {string}   ok Toast on success.
 * @return {Promise<*>} The response, or false on failure.
 */
export async function write( fn, ok ) {
	try {
		const result = await fn();
		if ( ok ) {
			toast( ok );
		}
		poll();
		return result === undefined ? true : result;
	} catch ( e ) {
		const human = e && e.status >= 400 && e.status < 500 && e.message && e.code !== 'rest_cookie_invalid_nonce';
		toast( human ? e.message : __( 'Could not save. Check your connection and try again.', 'novawork' ), 'x' );
		return false;
	}
}

/** Put the API's answer for a task into the store at once. */
export const keepTask = ( t ) => t && t.id && store().upsert( 'tasks', t );

export async function startTracking( taskId ) {
	const S = store();
	if ( ! taskId ) {
		toast( __( 'Add a task to Now first, then start tracking.', 'novawork' ), 'clock' );
		return;
	}
	// Still inside the click, where Chrome allows asking for idle detection.
	askIdlePermission();
	const res = await write( () => post( 'timer/start', { taskId: Number( taskId ) } ) );
	if ( ! res ) {
		return;
	}
	const running = myRun();
	if ( running && running.id !== res.entry.id ) {
		S.remove( 'timeEntries', running.id );
	}
	if ( res.stopped ) {
		S.upsert( 'timeEntries', res.stopped );
	}
	S.upsert( 'timeEntries', res.entry );
	keepTask( res.task );
	try {
		window.localStorage.setItem( 'nw.lastTask', String( taskId ) );
	} catch ( e ) {}
	toast( sprintf( /* translators: %s: task title. */ __( 'Timer started: %s', 'novawork' ), res.task.title ), 'play' );
}

/**
 * @param {boolean} silent No toast (e.g. when switching tasks).
 * @param {number}  endMs  End at this time instead of now (idle pause).
 */
/* A Stop pressed while offline (1.2.0), sent when the site answers again. */
const QUEUE = 'nw.queuedStop';
export function queuedStop() {
	try {
		return JSON.parse( window.localStorage.getItem( QUEUE ) || 'null' );
	} catch ( e ) {
		return null;
	}
}
function setQueued( q ) {
	try {
		if ( q ) {
			window.localStorage.setItem( QUEUE, JSON.stringify( q ) );
		} else {
			window.localStorage.removeItem( QUEUE );
		}
	} catch ( e ) {}
}

/**
 * Back online: end the session where Stop was pressed. The server may have
 * stopped it meanwhile (it heard nothing), then the stop is put back to
 * that moment through "Were you working?" without asking.
 */
export async function flushQueuedStop() {
	const q = queuedStop();
	if ( ! q ) {
		return;
	}
	const S = store();
	try {
		// Ask the server, not this page (it still shows the session stopped).
		const mine = await get( 'time-entries', { from: q.start - 1000, user: S.me } );
		const same = ( mine || [] ).find( ( e ) => e.start === q.start );
		const pending = S.pendingStop;
		if ( same && same.running ) {
			await post( 'timer/stop', { endedAt: Math.round( q.at ) } );
		} else if ( pending && pending.startedAt === q.start ) {
			await post( 'timer/recover', { until: Math.round( q.at ), keep: false } );
		}
		setQueued( null );
		toast( __( 'Back online. Your timer was saved where you stopped it.', 'novawork' ), 'check' );
	} catch ( e ) {
		if ( e.status ) {
			// The site refused it (nothing left to fix): forget it.
			setQueued( null );
		}
	}
	poll();
}

/**
 * Stop your timer.
 *
 * @param {boolean} silent No "Paused" toast.
 * @param {number}  endMs  End there (server ms) instead of now.
 * @param {string}  reason The app stopped it on its own: "idle" or "sleep" (1.2.0).
 */
export async function stopTracking( silent, endMs, reason ) {
	const S = store();
	const run = myRun();
	if ( ! run ) {
		return null;
	}
	const body = endMs ? { endedAt: Math.round( endMs ) } : {};
	if ( reason ) {
		body.reason = reason;
	}
	let res = null;
	try {
		res = await post( 'timer/stop', body );
		poll();
	} catch ( e ) {
		if ( ! e.status && ! reason ) {
			// Offline: stop here now, send it when the site answers again.
			const at = endMs || serverNow();
			setQueued( { start: run.start, at } );
			S.upsert( 'timeEntries', Object.assign( {}, run, { running: false, end: at } ) );
			toast( __( 'Stopped. You’re offline, so it’s saved as soon as you’re back.', 'novawork' ), 'pause' );
			return null;
		}
		const human = e && e.status >= 400 && e.status < 500 && e.message;
		toast( human ? e.message : __( 'Could not save. Check your connection and try again.', 'novawork' ), 'x' );
		return null;
	}
	S.remove( 'timeEntries', run.id );
	if ( res.entry ) {
		S.upsert( 'timeEntries', res.entry );
	}
	if ( ! silent ) {
		toast( sprintf( /* translators: %s: time tracked today. */ __( 'Paused. %s tracked today.', 'novawork' ), dur( tracked( me(), dayStart( serverNow() ) ) ) ), 'pause' );
	}
	return res.entry;
}

export function toggleTask( id ) {
	const run = myRun();
	if ( run && run.taskId === Number( id ) ) {
		stopTracking();
	} else {
		startTracking( id );
	}
}

/** The task the header play button starts: the last one tracked, else your first in Now. */
export function defaultTaskId() {
	const S = store();
	let last = 0;
	try {
		last = Number( window.localStorage.getItem( 'nw.lastTask' ) );
	} catch ( e ) {}
	const t = last && task( last );
	if ( t && t.status !== 'done' ) {
		return last;
	}
	const mine = Object.values( S.tasks )
		.filter( ( x ) => x.status === 'now' && ( x.assignees || [] ).includes( S.me ) )
		.sort( ( a, b ) => a.order - b.order );
	return mine.length ? mine[ 0 ].id : 0;
}

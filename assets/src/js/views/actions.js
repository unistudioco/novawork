/**
 * Writes to the API with the prototype's feedback: a toast on success, a
 * calm error toast on failure, and an immediate sync so every view updates.
 */
import { post } from '../lib/api.js';
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
export async function stopTracking( silent, endMs ) {
	const S = store();
	const run = myRun();
	if ( ! run ) {
		return null;
	}
	const res = await write( () => post( 'timer/stop', endMs ? { endedAt: Math.round( endMs ) } : {} ) );
	if ( ! res ) {
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

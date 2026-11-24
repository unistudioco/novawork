/**
 * "Were you working?" (owner request, 1.2.0).
 *
 * When NovaWork stops a timer on its own (the app went quiet, the device
 * slept, the connection dropped, "Still working?" went unanswered), the
 * server remembers it (Services\TimeEdits) and /sync sends it as
 * `pendingStop`. Here the member is asked once, calmly, the next time
 * they're in the workspace: Count it (until a time they can change, now by
 * default, and keep tracking) or Don't count it. The server enforces the
 * limits: their own stop only, within 24 hours, never into a later session.
 *
 * Closing the dialog leaves the question for the next visit. Answered on
 * another device, it closes here. A Stop pressed offline is settled without
 * asking (views/actions.js flushQueuedStop).
 */
import { __, sprintf } from '../lib/i18n.js';
import { esc, dur } from '../lib/format.js';
import { post } from '../lib/api.js';
import { toast } from '../lib/ui.js';
import { safeEffect } from '../lib/effect.js';
import { serverNow } from '../store.js';
import { store, task, myRun, iso } from './model.js';
import { openDialog, closeDialog, dialogForm } from './dialog.js';
import { write, queuedStop } from './actions.js';

// The stop already asked about on this page (startedAt:endedAt).
let asked = '';

const two = ( n ) => String( n ).padStart( 2, '0' );
const hhmm = ( d ) => two( d.getHours() ) + ':' + two( d.getMinutes() );

/** Why it stopped, in the member's words. */
function why( p, localEnd ) {
	const off = store().lastOffline || ( store().offlineSince ? [ store().offlineSince, Date.now() ] : null );
	if ( p.reason === 'sleep' ) {
		return __( 'this device went to sleep', 'novawork' );
	}
	if ( p.reason === 'idle' ) {
		return __( 'nobody answered “Still working?”', 'novawork' );
	}
	if ( off && localEnd >= off[ 0 ] - 120000 && localEnd <= off[ 1 ] ) {
		return __( 'this device lost its connection', 'novawork' );
	}
	return __( 'no NovaWork window of yours was open or awake', 'novawork' );
}

function ask( p, key ) {
	if ( asked === key || ! store().pendingStop ) {
		return;
	}
	const q = queuedStop();
	if ( q && q.start === p.startedAt ) {
		// A Stop pressed offline settles this one (actions.js).
		return;
	}
	// Never on top of another dialog, the idle question or the tour: try again soon.
	if ( document.querySelector( 'dialog[open], .nw-tour' ) ) {
		setTimeout( () => ask( p, key ), 3000 );
		return;
	}
	asked = key;
	const S = store();
	const offset = serverNow() - Date.now();
	const end = new Date( p.endedAt - offset );
	const now = new Date();
	const t = task( p.taskId );
	const sameDay = iso( end ) === iso( now );
	const at = end.toLocaleTimeString( S.locale || undefined, { hour: '2-digit', minute: '2-digit' } ) + ( sameDay ? '' : ', ' + end.toLocaleDateString( S.locale || undefined, { weekday: 'short', day: 'numeric', month: 'short' } ) );
	const other = myRun();
	const lead = t
		? sprintf( /* translators: 1: task, 2: time it stopped, 3: why, 4: how long ago. */ __( 'Your timer on %1$s stopped at %2$s, when %3$s. That was %4$s ago.', 'novawork' ), t.title, at, why( p, end.getTime() ), dur( now - end ) )
		: sprintf( /* translators: 1: time it stopped, 2: why, 3: how long ago. */ __( 'Your timer stopped at %1$s, when %2$s. That was %3$s ago.', 'novawork' ), at, why( p, end.getTime() ), dur( now - end ) );
	openDialog(
		__( 'Were you working?', 'novawork' ),
		`<p class="nw-recover-lead">${ esc( lead ) }</p>` +
			`<div class="nw-two"><div class="nw-field"><label for="nw-r-day">${ esc( __( 'Count until, day', 'novawork' ) ) }</label><input class="nw-input" id="nw-r-day" type="date" name="day" required min="${ iso( end ) }" max="${ iso( now ) }" value="${ iso( now ) }"></div>` +
			`<div class="nw-field"><label for="nw-r-time">${ esc( __( 'Time', 'novawork' ) ) }</label><input class="nw-input" id="nw-r-time" type="time" name="time" required value="${ hhmm( now ) }"></div></div>` +
			( other
				? ''
				: `<label class="nw-checkline"><input type="checkbox" name="keep" value="1" checked> ${ esc( __( 'Keep tracking from now', 'novawork' ) ) }</label>` ) +
			`<div class="nw-dlg-actions"><button class="nw-btn nw-ghost" type="button" data-recover="no">${ esc( __( 'Don’t count it', 'novawork' ) ) }</button><span class="nw-sp"></span><button class="nw-btn nw-primary" type="submit" value="save">${ esc(
				__( 'Count it', 'novawork' )
			) }</button></div>`,
		async ( fd ) => {
			let until = new Date( String( fd.get( 'day' ) ) + 'T' + String( fd.get( 'time' ) || '00:00' ) ).getTime();
			until = Math.min( until, Date.now() );
			if ( ! until || until <= end.getTime() ) {
				toast( __( 'Pick a time after the timer stopped.', 'novawork' ), 'x' );
				return false;
			}
			const keep = !! fd.get( 'keep' ) && Date.now() - until < 120000;
			const saved = await write( () => post( 'timer/recover', { until: Math.round( until + offset ), keep } ) );
			if ( saved && saved.id ) {
				S.upsert( 'timeEntries', saved );
				// The server may have capped it (a later session): say what it did.
				toast(
					saved.running
						? __( 'Counted, and still tracking.', 'novawork' )
						: sprintf( /* translators: %s: time added, e.g. "1h 20m". */ __( 'Counted: %s added.', 'novawork' ), dur( until - end.getTime() ) ),
					'check'
				);
			}
			return !! saved;
		}
	);
	dialogForm()
		.querySelector( '[data-recover="no"]' )
		.addEventListener( 'click', async () => {
			closeDialog();
			await write( () => post( 'timer/dismiss' ) );
			toast( __( 'Left as it was.', 'novawork' ), 'check' );
		} );
}

export function mountRecover() {
	safeEffect( () => {
		const p = store().pendingStop;
		if ( ! p ) {
			// Answered on another device: close it here.
			const form = dialogForm();
			if ( form && form.closest( 'dialog[open]' ) && form.querySelector( '.nw-recover-lead' ) ) {
				setTimeout( closeDialog, 0 );
			}
			return;
		}
		const key = p.startedAt + ':' + p.endedAt;
		// Outside the effect: opening a dialog must not run inside Alpine's update.
		setTimeout( () => ask( p, key ), 600 );
	} );
}

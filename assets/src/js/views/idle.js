/**
 * The idle rule: after the idle threshold (a setting, 10 min by default) with
 * no activity while your timer runs, "Still working?" asks for 60 seconds.
 * No answer pauses the timer at the LAST activity, so time away is never
 * counted.
 *
 * Activity comes from the Idle Detection API when the browser has it and the
 * member allowed it (system-wide: working in another app counts); otherwise
 * from activity in this tab.
 *
 * Owner decision (2026-11-22): a sleeping computer runs no code, so the rule
 * above could not see a folded laptop, and on waking up "Still working?"
 * counted the whole time away. Now:
 * - The page notices that it was asleep (its one-second ticker jumped) and,
 *   when the gap is at least the away limit, stops the timer at the last
 *   moment it was awake, without asking.
 * - The server stops a timer whose app has been silent that long (see
 *   Timer::stop_if_away()), which also covers a closed tab or a flat battery.
 *   Whichever acts first, the member is told once what was not counted.
 *
 * Owner report (2026-11-23): with the workspace open on a phone and a laptop,
 * the phone asked "Still working?" (and paused the timer, losing time) while
 * the laptop was in use, and "Yes" brought the question straight back. Now:
 * - Activity counts across devices: each app reports its last activity with
 *   its poll (lib/activity.js, sync.js) and the server sends back the latest
 *   on any device (`activeAt`). A device asks only when the member has been
 *   inactive everywhere, and an open question closes by itself once they are
 *   active on another device.
 * - "Yes" also clears the Idle Detection API's "idle since", which kept the
 *   question coming back on Android.
 * - A device that wakes from sleep asks the server first: if the member kept
 *   working elsewhere, the timer keeps running.
 */
import { __, sprintf } from '../lib/i18n.js';
import { icf } from '../lib/icons.js';
import { dur, MIN } from '../lib/format.js';
import { toast } from '../lib/ui.js';
import { safeEffect } from '../lib/effect.js';
import { serverNow } from '../store.js';
import { store, myRun, task } from './model.js';
import { stopTracking } from './actions.js';
import { showIdleAlert, closeIdleAlert } from './alerts.js';
import { lastActive, markActive } from '../lib/activity.js';

let idle = null;
let detector = null;
let idleSince = null;
let dlg = null;
/* The last tick of the one-second ticker, to notice the page was asleep. */
let lastTick = Date.now();
/* When a stop for being away was last explained: the page and the server
   may both notice the same sleep a moment apart; say it once. */
let toldAway = 0;
/* The sync loop, to ask the server before acting on a wake-up. */
let sync = null;
/* True while a wake-up waits for the server. */
let waking = false;

/** The member's latest activity on any device, in local time (0 if none known). */
function anywhere() {
	const at = Number( store().activeAt ) || 0;
	return at ? at - ( serverNow() - Date.now() ) : 0;
}

/* Same limit as the server: the idle threshold, never under three minutes. */
const awayAfter = () => Math.max( 3 * MIN, threshold() );

function tellAway( endedAtServer, awayMs, taskId ) {
	// With "Ask after an automatic stop" on, "Were you working?" explains it
	// and offers to count the time (views/recover.js, 1.2.0).
	if ( Date.now() - toldAway < 30000 || store().settings.recoverTime ) {
		return;
	}
	toldAway = Date.now();
	const t = task( taskId );
	const at = new Date( endedAtServer - ( serverNow() - Date.now() ) ).toLocaleTimeString( store().locale || undefined, { hour: '2-digit', minute: '2-digit' } );
	const msg = t
		? sprintf( /* translators: 1: task, 2: clock time, 3: time away, e.g. "2h 10m". */ __( 'Your timer on %1$s stopped at %2$s, when this device went quiet. The %3$s after that weren’t counted.', 'novawork' ), t.title, at, dur( awayMs ) )
		: sprintf( /* translators: 1: clock time, 2: time away, e.g. "2h 10m". */ __( 'Your timer stopped at %1$s, when this device went quiet. The %2$s after that weren’t counted.', 'novawork' ), at, dur( awayMs ) );
	toast( msg, 'pause', { label: __( 'OK', 'novawork' ), run: () => {} } );
}

/**
 * The page was asleep (laptop folded, system sleep): its ticker stopped for
 * `gap` ms. Stop the timer at the last moment it was awake, without asking.
 *
 * @param {number} wokeAt  Local time it woke up.
 * @param {number} gap     How long it slept.
 */
async function wokeUp( wokeAt, gap ) {
	if ( ! myRun() || gap < awayAfter() ) {
		return;
	}
	idle = null;
	if ( dlg && dlg.open ) {
		dlg.close();
	}
	// The member may have kept working on another device meanwhile: hear
	// from the server first (it may also have stopped the timer already).
	// Waking up is not reported as activity until then, or it would count.
	waking = true;
	if ( sync ) {
		try {
			await sync.poll();
		} catch ( e ) {}
	}
	const run = myRun();
	// Asleep here but active elsewhere: only the time away from every device counts.
	const sleptAt = Math.max( wokeAt - gap, Math.min( anywhere(), wokeAt ) );
	markActive( wokeAt );
	waking = false;
	if ( ! run || wokeAt - sleptAt < awayAfter() ) {
		return;
	}
	const endServer = sleptAt + ( serverNow() - Date.now() );
	tellAway( Math.max( endServer, run.start ), wokeAt - Math.max( sleptAt, run.start - ( serverNow() - Date.now() ) ), run.taskId );
	stopTracking( true, Math.max( endServer, run.start ), 'sleep' );
}

const threshold = () => Math.max( 1, Number( store().settings.idleMinutes ) || 10 ) * MIN;
const grace = () => Math.max( 10, Number( store().settings.idleGrace ) || 60 ) * 1000;

async function startDetector() {
	if ( detector || ! ( 'IdleDetector' in window ) ) {
		return;
	}
	try {
		const d = new window.IdleDetector();
		d.addEventListener( 'change', () => {
			if ( d.userState === 'idle' ) {
				idleSince = Date.now() - Math.max( threshold(), 60000 );
			} else {
				idleSince = null;
				if ( ! idle ) {
					markActive();
				}
			}
		} );
		await d.start( { threshold: Math.max( threshold(), 60000 ) } );
		detector = d;
	} catch ( e ) {
		detector = null;
	}
}

/**
 * Ask for system-wide idle detection. Call it from a click (Chrome only lets
 * a page ask during a user gesture); once allowed, it starts by itself.
 */
export function askIdlePermission() {
	if ( detector || ! ( 'IdleDetector' in window ) || ! window.IdleDetector.requestPermission ) {
		return;
	}
	window.IdleDetector.requestPermission()
		.then( ( state ) => {
			if ( state === 'granted' ) {
				startDetector();
			}
		} )
		.catch( () => {} );
}

/**
 * Pause at the last activity.
 *
 * @param {boolean} chosen The member pressed Pause (no "Were you working?"
 *                         later); otherwise nobody answered in time.
 */
function pause( chosen = true ) {
	const run = myRun();
	// The last activity here or on any other device.
	const from = idle ? Math.max( idle.from, Math.min( anywhere(), Date.now() ) ) : Date.now();
	idle = null;
	closeIdleAlert();
	if ( dlg.open ) {
		dlg.close();
	}
	markActive();
	if ( ! run ) {
		return;
	}
	stopTracking( true, from + ( serverNow() - Date.now() ), chosen ? undefined : 'idle' ).then( () => {
		if ( chosen || ! store().settings.recoverTime ) {
			toast( sprintf( /* translators: %s: time away, e.g. "12m". */ __( 'Timer paused. The %s you were away wasn’t counted.', 'novawork' ), dur( Date.now() - from ) ), 'pause' );
		}
	} );
}

function resume( elsewhere = false ) {
	idle = null;
	closeIdleAlert();
	// Back now: also forget the Idle Detection API's "idle since", or the
	// next tick would ask again (Android).
	idleSince = null;
	markActive();
	if ( dlg.open ) {
		dlg.close();
	}
	toast( elsewhere ? __( 'Still tracking: you’re active on another device.', 'novawork' ) : __( 'Still tracking', 'novawork' ), 'play' );
}

/** Runs every second from the view ticker. */
export function checkIdle() {
	if ( ! dlg ) {
		return;
	}
	const now = Date.now();
	const gap = now - lastTick;
	lastTick = now;
	// A one-second ticker that skipped minutes means the page was asleep (a
	// background tab ticks at least once a minute).
	if ( gap >= 2 * MIN ) {
		wokeUp( now, gap );
	}
	if ( waking ) {
		return;
	}
	const run = myRun();
	if ( ! run ) {
		if ( idle ) {
			idle = null;
			if ( dlg.open ) {
				dlg.close();
			}
		}
		return;
	}
	const startedLocal = run.start - ( serverNow() - now );
	const elsewhere = Math.min( anywhere(), now );
	// Answered by activity on another device while the question is open: a
	// newer server value than when it asked, and later than the activity the
	// question counts from (this device's own last report can still be on
	// its way; it is never later than that).
	if ( idle && ( Number( store().activeAt ) || 0 ) > idle.seen && anywhere() > idle.from + 1000 ) {
		resume( true );
		return;
	}
	let from = null;
	if ( detector ) {
		from = idleSince === null ? null : Math.max( idleSince, lastActive(), elsewhere, startedLocal );
	} else {
		from = Math.max( lastActive(), elsewhere, startedLocal );
	}
	if ( ! idle && from !== null && now - from >= threshold() ) {
		idle = { from, asked: now, seen: Number( store().activeAt ) || 0 };
		dlg.querySelector( '#nw-idle-for' ).textContent = dur( now - from );
		if ( ! dlg.open ) {
			dlg.showModal();
		}
		// Not the window in front (working in another app): ask there too, as
		// an alert with the two answers (owner, 1.2.0).
		if ( document.visibilityState !== 'visible' || ! document.hasFocus() ) {
			const t = task( run.taskId );
			showIdleAlert(
				t
					? sprintf( /* translators: 1: time without activity, e.g. "10m", 2: task. */ __( 'No activity for %1$s. Your timer on %2$s is still running.', 'novawork' ), dur( now - from ), t.title )
					: sprintf( /* translators: %s: time without activity, e.g. "10m". */ __( 'No activity for %s. Your timer is still running.', 'novawork' ), dur( now - from ) )
			);
		}
	}
	if ( idle ) {
		const left = Math.max( 0, Math.ceil( ( idle.asked + grace() - now ) / 1000 ) );
		dlg.querySelector( '#nw-idle-left' ).textContent = String( left );
		if ( ! left ) {
			pause( false );
		}
	}
}

export function mountIdle( syncLoop ) {
	sync = syncLoop || null;
	dlg = document.getElementById( 'nw-idle-dlg' );
	if ( ! dlg ) {
		return;
	}
	dlg.querySelector( '#nw-idle-icon' ).innerHTML = icf( 'clock' );
	// The server stopped the timer because this app went quiet: say so once.
	safeEffect( () => {
		const a = store().timerAway;
		if ( a ) {
			setTimeout( () => tellAway( a.endedAt, a.awayMs, a.taskId ), 0 );
		}
	} );
	[ 'pointermove', 'pointerdown', 'keydown', 'wheel', 'touchstart', 'scroll' ].forEach( ( type ) =>
		window.addEventListener(
			type,
			() => {
				if ( ! idle ) {
					markActive();
				}
			},
			{ passive: true, capture: true }
		)
	);
	dlg.addEventListener( 'click', ( e ) => {
		const b = e.target.closest( '[data-idle]' );
		if ( b ) {
			if ( b.dataset.idle === 'resume' ) {
				resume();
			} else {
				pause();
			}
		}
	} );
	dlg.addEventListener( 'cancel', ( e ) => {
		e.preventDefault();
		resume();
	} );
	// Answered from the alert's buttons (views/alerts.js, alerts-worker.js).
	if ( 'serviceWorker' in navigator ) {
		navigator.serviceWorker.addEventListener( 'message', ( e ) => {
			const answer = e.data && e.data.novaworkIdle;
			if ( ! idle || ! answer ) {
				return;
			}
			if ( answer === 'resume' ) {
				resume();
			} else if ( answer === 'pause' ) {
				pause();
			}
		} );
	}
	// Allowed before? Start without asking again.
	if ( 'IdleDetector' in window && navigator.permissions ) {
		navigator.permissions
			.query( { name: 'idle-detection' } )
			.then( ( p ) => {
				if ( p.state === 'granted' ) {
					startDetector();
				}
			} )
			.catch( () => {} );
	}
}

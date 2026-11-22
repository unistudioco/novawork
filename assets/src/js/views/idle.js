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
 */
import { __, sprintf } from '../lib/i18n.js';
import { icf } from '../lib/icons.js';
import { dur, MIN } from '../lib/format.js';
import { toast } from '../lib/ui.js';
import { safeEffect } from '../lib/effect.js';
import { serverNow } from '../store.js';
import { store, myRun, task } from './model.js';
import { stopTracking } from './actions.js';

let lastActive = Date.now();
let idle = null;
let detector = null;
let idleSince = null;
let dlg = null;
/* The last tick of the one-second ticker, to notice the page was asleep. */
let lastTick = Date.now();
/* When a stop for being away was last explained: the page and the server
   may both notice the same sleep a moment apart; say it once. */
let toldAway = 0;

/* Same limit as the server: the idle threshold, never under three minutes. */
const awayAfter = () => Math.max( 3 * MIN, threshold() );

function tellAway( endedAtServer, awayMs, taskId ) {
	if ( Date.now() - toldAway < 30000 ) {
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
function wokeUp( wokeAt, gap ) {
	const run = myRun();
	if ( ! run || gap < awayAfter() ) {
		return;
	}
	const sleptAt = wokeAt - gap;
	idle = null;
	if ( dlg && dlg.open ) {
		dlg.close();
	}
	lastActive = wokeAt;
	const endServer = sleptAt + ( serverNow() - Date.now() );
	tellAway( Math.max( endServer, run.start ), wokeAt - Math.max( sleptAt, run.start - ( serverNow() - Date.now() ) ), run.taskId );
	stopTracking( true, Math.max( endServer, run.start ) );
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
					lastActive = Date.now();
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

function pause() {
	const run = myRun();
	const from = idle ? idle.from : Date.now();
	idle = null;
	if ( dlg.open ) {
		dlg.close();
	}
	lastActive = Date.now();
	if ( ! run ) {
		return;
	}
	stopTracking( true, from + ( serverNow() - Date.now() ) ).then( () => {
		toast( sprintf( /* translators: %s: time away, e.g. "12m". */ __( 'Timer paused. The %s you were away wasn’t counted.', 'novawork' ), dur( Date.now() - from ) ), 'pause' );
	} );
}

function resume() {
	idle = null;
	lastActive = Date.now();
	if ( dlg.open ) {
		dlg.close();
	}
	toast( __( 'Still tracking', 'novawork' ), 'play' );
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
	let from = null;
	if ( detector ) {
		from = idleSince === null ? null : Math.max( idleSince, startedLocal );
	} else {
		from = Math.max( lastActive, startedLocal );
	}
	if ( ! idle && from !== null && now - from >= threshold() ) {
		idle = { from, asked: now };
		dlg.querySelector( '#nw-idle-for' ).textContent = dur( now - from );
		if ( ! dlg.open ) {
			dlg.showModal();
		}
	}
	if ( idle ) {
		const left = Math.max( 0, Math.ceil( ( idle.asked + grace() - now ) / 1000 ) );
		dlg.querySelector( '#nw-idle-left' ).textContent = String( left );
		if ( ! left ) {
			pause();
		}
	}
}

export function mountIdle() {
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
					lastActive = Date.now();
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

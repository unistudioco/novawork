/**
 * The idle rule: after the idle threshold (a setting, 10 min by default) with
 * no activity while your timer runs, "Still working?" asks for 60 seconds.
 * No answer pauses the timer at the LAST activity, so time away is never
 * counted.
 *
 * Activity comes from the Idle Detection API when the browser has it and the
 * member allowed it (system-wide: working in another app counts); otherwise
 * from activity in this tab.
 */
import { __, sprintf } from '../lib/i18n.js';
import { icf } from '../lib/icons.js';
import { dur, MIN } from '../lib/format.js';
import { toast } from '../lib/ui.js';
import { serverNow } from '../store.js';
import { store, myRun } from './model.js';
import { stopTracking } from './actions.js';

let lastActive = Date.now();
let idle = null;
let detector = null;
let idleSince = null;
let dlg = null;

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

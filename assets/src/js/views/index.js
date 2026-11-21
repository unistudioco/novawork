/**
 * Renders the active view into <main>, like the prototype's render().
 *
 * The view redraws when the data it reads changes (an Alpine effect), never
 * on the clock: running timers are [data-since] spans that a one-second
 * ticker updates in place. A redraw waits while a card is being dragged or a
 * dropdown in the view is open, so a poll never pulls the floor out.
 */
import Alpine from 'alpinejs';
import { __ } from '../lib/i18n.js';
import { esc, clock, dur, dayStart } from '../lib/format.js';
import { serverNow } from '../store.js';
import { bind, store, tracked } from './model.js';
import { bindSync, toggleTask, write, keepTask } from './actions.js';
import { enhance, openSelectBox } from './select.js';
import { mountDialog } from './dialog.js';
import { openTask, openProject, openDecision, vote } from './editors.js';
import { viewDecisions } from './decisions.js';
import { viewBoard, wireBoard, toggleAllDone, place } from './board.js';
import { viewFocus } from './focus.js';
import { viewRoadmap, wireRoadmap, scrollToToday, forgetRoadmapScroll, rememberRoadmapScroll } from './roadmap.js';
import { viewTime, wireTime } from './time.js';
import { mountIdle, checkIdle } from './idle.js';
import { mountChat } from './chat.js';
import { patch } from '../lib/api.js';

const VIEWS = {
	focus: viewFocus,
	board: viewBoard,
	roadmap: viewRoadmap,
	time: viewTime,
	decisions: viewDecisions,
};
const WIRE = { board: wireBoard, time: wireTime, roadmap: wireRoadmap };

let main = null;
let pending = null;
let drag = null;

function busy() {
	const box = openSelectBox();
	return !! drag || !! ( box && main.contains( box ) );
}

function paint( html ) {
	if ( busy() ) {
		pending = html;
		return;
	}
	pending = null;
	if ( store().view !== 'roadmap' ) {
		forgetRoadmapScroll();
	} else {
		rememberRoadmapScroll( main );
	}
	main.innerHTML = html;
	enhance( main );
	const wire = WIRE[ store().view ];
	if ( wire ) {
		wire( main, rerender );
	}
	wireDrag( main );
	tick();
}

// Bumping this redraws the view for UI-only changes (filters, "Show all").
const state = Alpine.reactive( { force: 0 } );
export const rerender = () => {
	state.force++;
};

/**
 * Fill in running clocks ([data-since]) and members' live totals for today
 * ([data-today] = a member id); runs every second. Never use these attribute
 * names for anything else: the ticker overwrites the element's text.
 */
export function tick() {
	const now = serverNow();
	document.querySelectorAll( '[data-since]' ).forEach( ( el ) => {
		if ( el.dataset.since ) {
			el.textContent = clock( now - Number( el.dataset.since ) );
		}
	} );
	document.querySelectorAll( '[data-today]' ).forEach( ( el ) => {
		el.textContent = dur( tracked( Number( el.dataset.today ), dayStart( now ) ) );
	} );
	checkIdle();
}

/* ---------- drag and drop: [data-drag] items inside [data-zone] lists ---------- */
function clearDrop() {
	document.querySelectorAll( '.nw-drop-line' ).forEach( ( l ) => l.remove() );
	document.querySelectorAll( '[data-zone].nw-over' ).forEach( ( l ) => l.classList.remove( 'nw-over' ) );
}
const itemAfter = ( items, y ) =>
	items.find( ( c ) => {
		const r = c.getBoundingClientRect();
		return y < r.top + r.height / 2;
	} ) || null;

function wireDrag( root ) {
	root.querySelectorAll( '[data-drag]' ).forEach( ( el ) => {
		el.draggable = true;
		el.addEventListener( 'dragstart', ( e ) => {
			drag = el.dataset.id;
			el.classList.add( 'nw-dragging' );
			e.dataTransfer.effectAllowed = 'move';
			try {
				e.dataTransfer.setData( 'text/plain', drag );
			} catch ( x ) {}
		} );
		el.addEventListener( 'dragend', () => {
			el.classList.remove( 'nw-dragging' );
			drag = null;
			clearDrop();
			if ( pending !== null ) {
				paint( pending );
			}
		} );
	} );
	root.querySelectorAll( '[data-zone]' ).forEach( ( zone ) => {
		const items = () => [ ...zone.querySelectorAll( '[data-drag]' ) ].filter( ( x ) => x.dataset.id !== drag );
		zone.addEventListener( 'dragover', ( e ) => {
			if ( ! drag ) {
				return;
			}
			e.preventDefault();
			clearDrop();
			zone.classList.add( 'nw-over' );
			const line = document.createElement( zone.tagName === 'OL' || zone.tagName === 'UL' ? 'li' : 'div' );
			line.className = 'nw-drop-line';
			zone.insertBefore( line, itemAfter( items(), e.clientY ) || zone.querySelector( '[data-tail]' ) );
		} );
		zone.addEventListener( 'dragleave', ( e ) => {
			if ( ! zone.contains( e.relatedTarget ) ) {
				clearDrop();
			}
		} );
		zone.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			if ( ! drag ) {
				return;
			}
			const id = Number( drag );
			const list = items();
			const after = itemAfter( list, e.clientY );
			drag = null;
			clearDrop();
			place( id, zone.dataset.zone, list.map( ( x ) => Number( x.dataset.id ) ), after ? Number( after.dataset.id ) : null );
		} );
	} );
}

/* ---------- clicks ---------- */
function onClick( e ) {
	const el = e.target.closest( '[data-act]' );
	if ( ! el || el.closest( 'dialog' ) ) {
		return;
	}
	const id = Number( el.dataset.id );
	const act = el.dataset.act;
	if ( act === 'edit-task' ) {
		openTask( id );
	} else if ( act === 'new-task' ) {
		openTask( null, Object.assign( { status: el.dataset.status || 'next' }, el.dataset.project ? { projectId: Number( el.dataset.project ) } : {} ) );
	} else if ( act === 'done' ) {
		write( () => patch( 'tasks/' + id, { status: 'done' } ), __( 'Done. Nice work.', 'novawork' ) ).then( keepTask );
	} else if ( act === 'to-now' ) {
		write( () => patch( 'tasks/' + id, { status: 'now' } ) ).then( keepTask );
	} else if ( act === 'track' ) {
		toggleTask( id );
	} else if ( act === 'edit-project' ) {
		openProject( id );
	} else if ( act === 'new-project' ) {
		openProject( null );
	} else if ( act === 'edit-decision' ) {
		openDecision( id );
	} else if ( act === 'new-decision' ) {
		openDecision( null );
	} else if ( act === 'decide' ) {
		openDecision( id, true );
	} else if ( act === 'all-done' ) {
		toggleAllDone( rerender );
	} else if ( act === 'open-ref' ) {
		e.preventDefault();
		window.dispatchEvent( new CustomEvent( 'nw:open', { detail: { kind: el.dataset.type, id, stay: true } } ) );
	} else if ( act === 'vote' ) {
		vote( id, el.dataset.opt );
	} else if ( act === 'tl-today' ) {
		scrollToToday( true );
	}
}

export function mountViews( sync ) {
	const S = Alpine.store( 'nw' );
	bind( S );
	bindSync( sync );
	mountDialog();
	mountIdle();
	mountChat();
	main = document.getElementById( 'nw-main' );
	main.addEventListener( 'nw:painted', tick );

	Alpine.effect( () => {
		void state.force;
		const view = VIEWS[ S.view ] || VIEWS.focus;
		paint( view() );
	} );

	document.addEventListener( 'click', onClick );
	setInterval( tick, 1000 );

	// Intents from the shell (create sheet, N, quick search).
	window.addEventListener( 'nw:create', ( e ) => {
		const d = e.detail || {};
		if ( d.kind === 'task' ) {
			openTask( null, { status: d.status || 'now' } );
		} else if ( d.kind === 'project' ) {
			openProject( null );
		} else if ( d.kind === 'decision' ) {
			openDecision( null );
		}
	} );
	window.addEventListener( 'nw:open', ( e ) => {
		const d = e.detail || {};
		if ( d.kind === 'task' ) {
			openTask( d.id );
		} else if ( d.kind === 'project' ) {
			openProject( d.id );
		} else if ( d.kind === 'decision' ) {
			openDecision( d.id );
		}
	} );

}

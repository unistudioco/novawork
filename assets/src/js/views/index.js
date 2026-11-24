/**
 * Renders the active view into <main>, like the prototype's render().
 *
 * The view redraws when the data it reads changes (an Alpine effect), never
 * on the clock: running timers are [data-since] spans that a one-second
 * ticker updates in place. A redraw waits while a card is being dragged or a
 * dropdown in the view is open, so a poll never pulls the floor out.
 */
import Alpine from 'alpinejs';
import { safeEffect } from '../lib/effect.js';
import { __ } from '../lib/i18n.js';
import { esc, clock, dur, dayStart } from '../lib/format.js';
import { serverNow } from '../store.js';
import { bind, store, tracked, task, canEditTask } from './model.js';
import { bindSync, toggleTask, write, keepTask } from './actions.js';
import { enhance, openSelectBox } from './select.js';
import { mountDialog } from './dialog.js';
import { openTask, openProject, openDecision, openSession, vote } from './editors.js';
import { viewDecisions } from './decisions.js';
import { viewBoard, wireBoard, toggleAllDone, place, revealTask } from './board.js';
import { toast } from '../lib/ui.js';
import { viewFocus } from './focus.js';
import { viewRoadmap, wireRoadmap, scrollToToday, forgetRoadmapScroll, rememberRoadmapScroll } from './roadmap.js';
import { viewTime, wireTime, sessionById, forgetHistory } from './time.js';
import { mountIdle, checkIdle } from './idle.js';
import { mountChat } from './chat.js';
import { mountNotes } from './notes.js';
import { mountTour } from './tour.js';
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

/*
 * Elements marked data-flip="key" glide from where they were to where the
 * redraw put them (owner, 2026-11-23: a vote option rising to the top).
 * Off with reduced motion.
 */
function flipBefore( root ) {
	const at = new Map();
	if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return at;
	}
	root.querySelectorAll( '[data-flip]' ).forEach( ( el ) => at.set( el.dataset.flip, el.getBoundingClientRect().top ) );
	return at;
}
function flipAfter( root, before ) {
	if ( ! before.size ) {
		return;
	}
	root.querySelectorAll( '[data-flip]' ).forEach( ( el ) => {
		const was = before.get( el.dataset.flip );
		const dy = was === undefined ? 0 : was - el.getBoundingClientRect().top;
		if ( Math.abs( dy ) > 1 && el.animate ) {
			el.animate( [ { transform: `translateY(${ dy }px)` }, { transform: 'none' } ], { duration: 360, easing: 'cubic-bezier(.2, .9, .25, 1)' } );
		}
	} );
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
	const before = flipBefore( main );
	main.innerHTML = html;
	flipAfter( main, before );
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

const notYours = () => toast( __( 'Only the people on this task, its creator or an administrator can change it.', 'novawork' ), 'warning-circle' );

function wireDrag( root ) {
	root.querySelectorAll( '[data-drag]' ).forEach( ( el ) => {
		// Someone else's task stays where it is (edit rights, 1.2.0).
		if ( ! canEditTask( task( Number( el.dataset.id ) ) ) ) {
			el.draggable = false;
			return;
		}
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
	} else if ( ( act === 'done' || act === 'to-now' ) && ! canEditTask( task( id ) ) ) {
		notYours();
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
	} else if ( act === 'edit-session' ) {
		const s = sessionById( id );
		if ( s ) {
			openSession( s, forgetHistory );
		}
	} else if ( act === 'add-session' ) {
		openSession( null, forgetHistory );
	} else if ( act === 'decide' ) {
		openDecision( id, true );
	} else if ( act === 'all-done' ) {
		toggleAllDone( rerender );
	} else if ( act === 'open-ref' ) {
		// A link in chat goes to the item's page (owner).
		e.preventDefault();
		window.dispatchEvent( new CustomEvent( 'nw:goto', { detail: { kind: el.dataset.type, id } } ) );
	} else if ( act === 'vote' ) {
		vote( id, el.dataset.opt );
	} else if ( act === 'tl-today' ) {
		scrollToToday( true );
	}
}

const PAGE = { task: 'board', decision: 'decisions', project: 'roadmap' };
const CARD = { task: ( id ) => `.nw-card[data-id="${ id }"]`, decision: ( id ) => `.nw-dec[data-dec="${ id }"]`, project: ( id ) => `.nw-proj[data-proj="${ id }"]` };
const frames = ( n ) => new Promise( ( r ) => ( function f( i ) {
	requestAnimationFrame( () => ( i > 1 ? f( i - 1 ) : r() ) );
} )( n ) );

/**
 * Go to an item's page and bring its card into view (owner: from a
 * notification, the team feed or a chat link, rather than opening its
 * editor). The card flashes once.
 *
 * @param {Object} d { kind: "task"|"decision"|"project", id }
 */
async function goTo( d ) {
	const S = store();
	const id = Number( d.id );
	const rows = { task: S.tasks, decision: S.decisions, project: S.projects }[ d.kind ];
	if ( ! rows || ! rows[ id ] ) {
		toast( __( 'That item was deleted.', 'novawork' ), 'x' );
		return;
	}
	if ( d.kind === 'task' && revealTask( id ) ) {
		toast( __( 'Showing everyone’s tasks so you can see it.', 'novawork' ), 'users-three' );
	}
	if ( location.hash !== '#' + PAGE[ d.kind ] ) {
		location.hash = PAGE[ d.kind ];
	}
	rerender();
	for ( let i = 0; i < 20; i++ ) {
		await frames( 2 );
		const card = main.querySelector( CARD[ d.kind ]( id ) );
		if ( card ) {
			card.scrollIntoView( { block: 'center', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
			card.focus( { preventScroll: true } );
			card.classList.remove( 'nw-flash' );
			void card.offsetWidth;
			card.classList.add( 'nw-flash' );
			setTimeout( () => card.classList.remove( 'nw-flash' ), 1900 );
			return;
		}
	}
}

export function mountViews( sync ) {
	const S = Alpine.store( 'nw' );
	bind( S );
	bindSync( sync );
	mountDialog();
	mountIdle( sync );
	mountChat();
	mountNotes();
	mountTour();
	main = document.getElementById( 'nw-main' );
	main.addEventListener( 'nw:painted', tick );

	safeEffect( () => {
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
	window.addEventListener( 'nw:goto', ( e ) => goTo( e.detail || {} ) );
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

/**
 * Board: Now / Next / Later / Done, filters by project and person, drag to
 * reorder and move. Ported from the prototype's viewBoard() / wireBoard().
 */
import { ic } from '../lib/icons.js';
import { __, sprintf } from '../lib/i18n.js';
import { esc } from '../lib/format.js';
import { post } from '../lib/api.js';
import { write, keepTask } from './actions.js';
import { openTask } from './editors.js';
import { nowLimit, store, people, projects, tasks, task, assignees, has, myRun, byOrder, STATUSES, projChip, tbtn, dueHTML, prio, avatars, commentsOf } from './model.js';

const pref = ( k, v ) => {
	try {
		if ( v === undefined ) {
			return window.localStorage.getItem( k );
		}
		window.localStorage.setItem( k, v );
	} catch ( e ) {}
	return null;
};

const ui = { allDone: false };

export function viewBoard() {
	const fProject = pref( 'nw.fProject' ) || 'all';
	// Default to you (owner); a choice made on this device is remembered.
	const fPerson = pref( 'nw.fPerson' ) || String( store().me );
	const ts = tasks().filter(
		( t ) =>
			( fProject === 'all' || t.projectId === Number( fProject ) ) &&
			( fPerson === 'all' || ( fPerson === 'none' ? ! assignees( t ).length : has( t, fPerson ) ) )
	);
	const pOpts =
		`<option value="all" data-icon="folder-simple">${ esc( __( 'All projects', 'novawork' ) ) }</option>` +
		projects()
			.map( ( p ) => `<option data-color="${ esc( p.color ) }" value="${ p.id }"${ fProject === String( p.id ) ? ' selected' : '' }>${ esc( p.name ) }</option>` )
			.join( '' );
	const uOpts =
		`<option value="all" data-everyone>${ esc( __( 'Everyone', 'novawork' ) ) }</option>` +
		people()
			.map( ( p ) => `<option data-person value="${ p.id }"${ fPerson === String( p.id ) ? ' selected' : '' }${ p.id === store().me ? ' data-you' : '' }>${ esc( p.name ) }</option>` )
			.join( '' ) +
		`<option data-person data-pid="0" value="none"${ fPerson === 'none' ? ' selected' : '' }>${ esc( __( 'Unassigned', 'novawork' ) ) }</option>`;
	const run = myRun();

	const lanes = STATUSES()
		.map( ( s ) => {
			let list = ts.filter( ( t ) => t.status === s.key );
			if ( s.key === 'done' ) {
				list.sort( ( a, b ) => ( b.doneAt || 0 ) - ( a.doneAt || 0 ) );
			} else {
				list.sort( byOrder );
			}
			const total = list.length;
			if ( s.key === 'done' && ! ui.allDone ) {
				list = list.slice( 0, 8 );
			}
			let warn = '';
			if ( s.key === 'now' ) {
				const limit = nowLimit();
				const over = people().filter( ( p ) => ts.filter( ( t ) => t.status === 'now' && has( t, p.id ) ).length > limit );
				if ( over.length ) {
					const names = over.map( ( p ) => p.name ).join( ', ' );
					const text =
						limit === 3
							? sprintf( /* translators: %s: names of members, comma-separated. */ __( '%s has more than three in Now.', 'novawork' ), names )
							: sprintf( /* translators: 1: names of members, 2: the Now limit. */ __( '%1$s has more than %2$d in Now.', 'novawork' ), names, limit );
					warn = `<div class="nw-note" style="margin:0">${ ic( 'flag-banner' ) }<span>${ esc( text ) }</span></div>`;
				}
			}
			const addLabel = esc( sprintf( /* translators: %s: column name, e.g. "Next". */ __( 'Add a task to %s', 'novawork' ), s.label ) );
			return (
				`<section class="nw-lane nw-s-${ s.key }" data-zone="${ s.key }"><header><span class="nw-st">${ esc( s.label ) }</span><span class="nw-lcount">${ total }</span><span class="nw-sp"></span>${
					s.key !== 'done' ? `<button class="nw-iconbtn nw-xs" type="button" data-act="new-task" data-status="${ s.key }" aria-label="${ addLabel }" title="${ esc( __( 'Add a task', 'novawork' ) ) }">${ ic( 'plus', 15 ) }</button>` : ''
				}</header>` +
				( s.hint ? `<div class="nw-hint">${ esc( s.hint ) }</div>` : '' ) +
				warn +
				list
					.map( ( t ) => {
						const comments = commentsOf( 'task', t.id ).length;
						return `<article class="nw-card${ t.status === 'done' ? ' nw-done' : '' }${ run && run.taskId === t.id ? ' nw-run' : '' }" data-drag data-id="${ t.id }" tabindex="0"><div class="nw-ctop">${ projChip( t.projectId ) }${
							t.status !== 'done' ? tbtn( t ) : ''
						}</div><div class="nw-t">${ esc( t.title ) }</div><div class="nw-cfoot">${ dueHTML( t.due, t.status ) }<span class="nw-sp"></span>${
							comments ? `<span class="nw-cnt">${ ic( 'chat-circle' ) }${ comments }</span>` : ''
						}${ prio( t.priority ) }${ avatars( t ) }</div></article>`;
					} )
					.join( '' ) +
				( s.key === 'done' && total > 8
					? `<button class="nw-btn nw-sm nw-ghost nw-add" type="button" data-tail data-act="all-done">${ esc(
						ui.allDone ? __( 'Show recent only', 'novawork' ) : sprintf( /* translators: %d: number of done tasks. */ __( 'Show all %d', 'novawork' ), total )
					) }</button>`
					: '' ) +
				( s.key !== 'done' ? `<button class="nw-btn nw-sm nw-ghost nw-add" type="button" data-tail data-act="new-task" data-status="${ s.key }">${ ic( 'plus', 14 ) }${ esc( __( 'Add task', 'novawork' ) ) }</button>` : '' ) +
				'</section>'
			);
		} )
		.join( '' );

	return (
		`<div class="nw-view-head"><div><h1>${ esc( __( 'Board', 'novawork' ) ) }</h1><p>${ esc( /* translators: %s: the keyboard key N. */ __( 'Drag cards to reorder them or move them between columns. Press %s for a new task.', 'novawork' ) ).replace( '%s', '<kbd>N</kbd>' ) }</p></div><div class="nw-acts"><select id="nw-f-project" data-nw-sel aria-label="${ esc(
			__( 'Filter by project', 'novawork' )
		) }">${ pOpts }</select><select id="nw-f-person" data-nw-sel data-right="1" aria-label="${ esc( __( 'Filter by person', 'novawork' ) ) }">${ uOpts }</select></div></div>` + `<div class="nw-board">${ lanes }</div>`
	);
}

export function wireBoard( main, rerender ) {
	main.querySelector( '#nw-f-project' ).addEventListener( 'change', ( e ) => {
		pref( 'nw.fProject', e.target.value );
		rerender();
	} );
	main.querySelector( '#nw-f-person' ).addEventListener( 'change', ( e ) => {
		pref( 'nw.fPerson', e.target.value );
		rerender();
	} );
	main.querySelectorAll( '.nw-card' ).forEach( ( c ) => {
		c.addEventListener( 'click', ( e ) => {
			if ( ! e.target.closest( 'button' ) ) {
				openTask( Number( c.dataset.id ) );
			}
		} );
		c.addEventListener( 'keydown', ( e ) => {
			if ( ( e.key === 'Enter' || e.key === ' ' ) && e.target === c ) {
				e.preventDefault();
				openTask( Number( c.dataset.id ) );
			}
		} );
	} );
}

/**
 * Make sure a task's card is on the board (going to it from a notification
 * or chat): widen the filters if they hide it, show all of Done if it is an
 * older done task. Returns true when a filter had to change.
 *
 * @param {number} id Task.
 */
export function revealTask( id ) {
	const t = task( id );
	if ( ! t ) {
		return false;
	}
	const fProject = pref( 'nw.fProject' ) || 'all';
	const fPerson = pref( 'nw.fPerson' ) || String( store().me );
	let widened = false;
	if ( fProject !== 'all' && t.projectId !== Number( fProject ) ) {
		pref( 'nw.fProject', 'all' );
		widened = true;
	}
	if ( fPerson !== 'all' && ( fPerson === 'none' ? assignees( t ).length : ! has( t, fPerson ) ) ) {
		pref( 'nw.fPerson', 'all' );
		widened = true;
	}
	if ( t.status === 'done' ) {
		ui.allDone = true;
	}
	return widened;
}

export function toggleAllDone( rerender ) {
	ui.allDone = ! ui.allDone;
	rerender();
}

/**
 * Drop a task into a column before another task (or at the end). The board
 * updates at once; the server confirms (and renumbers if it must).
 *
 * @param {number}      id       Task.
 * @param {string}      status   Column.
 * @param {number[]}    ids      Other tasks in the column, top to bottom.
 * @param {number|null} beforeId Task it now sits above.
 */
export function place( id, status, ids, beforeId ) {
	const t = task( id );
	if ( ! t ) {
		return;
	}
	const list = ids.map( task ).filter( Boolean );
	let order;
	if ( ! list.length ) {
		order = t.order || 1024;
	} else if ( ! beforeId ) {
		order = ( list[ list.length - 1 ].order || 0 ) + 1024;
	} else {
		const i = list.findIndex( ( k ) => k.id === beforeId );
		const nx = list[ i ].order || 0;
		const pv = i > 0 ? list[ i - 1 ].order || 0 : nx - 2048;
		order = ( pv + nx ) / 2;
	}
	const moved = status !== t.status;
	store().upsert( 'tasks', Object.assign( {}, t, { order, status, doneAt: moved ? ( status === 'done' ? Date.now() : null ) : t.doneAt } ) );
	write( () => post( 'tasks/' + id + '/move', { status, beforeId: beforeId || null } ), moved && status === 'done' ? __( 'Done. Nice work.', 'novawork' ) : '' ).then( keepTask );
}

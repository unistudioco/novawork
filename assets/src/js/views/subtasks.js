/**
 * Subtasks in the task dialog (owner request, 2026-11-22): a checklist with
 * a done tick, an optional assignee and drag to reorder.
 *
 * On a saved task every change goes to the server at once, like comments,
 * and the list redraws from the store, so a teammate's ticks show up while
 * the dialog is open. On a new task the list is a draft that is saved right
 * after the task. Removing a subtask is instant, with Undo (it is added back
 * in its place).
 */
import Alpine from 'alpinejs';
import { safeEffect } from '../lib/effect.js';
import { ic } from '../lib/icons.js';
import { __, sprintf } from '../lib/i18n.js';
import { esc } from '../lib/format.js';
import { post, patch, del } from '../lib/api.js';
import { toast } from '../lib/ui.js';
import { store, task, people, subtasksOf } from './model.js';
import { enhance } from './select.js';
import { write, keepTask } from './actions.js';

const MAX = 50;

/* The open dialog's checklist: a saved task's id, or a draft for a new task. */
let current = null;

/**
 * The checklist field for the task dialog.
 *
 * @param {Object|null} t Task, or null for a new one.
 */
export function subtasksField( t ) {
	const list = t ? subtasksOf( t ) : [];
	return `<div class="nw-field nw-subtasks-field"><span class="nw-flabel" id="nw-subtasks-label">${ esc( __( 'Subtasks', 'novawork' ) ) }<span class="nw-subtasks-count"></span></span>` +
		`<div class="nw-pbar nw-subtasks-bar"${ list.length ? '' : ' hidden' }><i></i></div>` +
		`<ul class="nw-subtasks" id="nw-subtasks" aria-labelledby="nw-subtasks-label"></ul>` +
		`<div class="nw-subtask-add">${ ic( 'plus', 15 ) }<label for="nw-subtask-in" class="nw-vh">${ esc( __( 'Add a subtask', 'novawork' ) ) }</label><input class="nw-input" id="nw-subtask-in" maxlength="200" autocomplete="off" enterkeyhint="done" placeholder="${ esc(
			__( 'Add a subtask', 'novawork' )
		) }"></div></div>`;
}

const items = () => ( current.id ? subtasksOf( task( current.id ) ) : current.draft );

function rowHTML( s ) {
	const label = esc( sprintf( s.done ? /* translators: %s: subtask. */ __( 'Mark %s not done', 'novawork' ) : /* translators: %s: subtask. */ __( 'Mark %s done', 'novawork' ), s.title ) );
	const who =
		`<option data-person data-pid="0" value="0"${ s.assigneeId ? '' : ' selected' }>${ esc( __( 'No one', 'novawork' ) ) }</option>` +
		people()
			.map( ( p ) => `<option data-person value="${ p.id }"${ p.id === s.assigneeId ? ' selected' : '' }${ p.id === store().me ? ' data-you' : '' }>${ esc( p.name ) }</option>` )
			.join( '' );
	return `<li class="nw-subtask${ s.done ? ' nw-done' : '' }${ s.assigneeId ? ' nw-has-who' : '' }" data-sid="${ s.id }" draggable="true">` +
		`<button class="nw-subtask-check" type="button" data-sub="tick" aria-pressed="${ s.done }" aria-label="${ label }">${ ic( 'check', 13 ) }</button>` +
		`<span class="nw-subtask-t" data-sub="edit" tabindex="0" role="button" aria-label="${ esc( sprintf( /* translators: %s: subtask. */ __( 'Rename %s', 'novawork' ), s.title ) ) }">${ esc( s.title ) }</span>` +
		`<select class="nw-subtask-who" data-nw-sel data-right="1" data-sub="who" aria-label="${ esc( sprintf( /* translators: %s: subtask. */ __( 'Who does %s', 'novawork' ), s.title ) ) }">${ who }</select>` +
		`<button class="nw-iconbtn nw-xs nw-subtask-x" type="button" data-sub="remove" aria-label="${ esc( sprintf( /* translators: %s: subtask. */ __( 'Remove %s', 'novawork' ), s.title ) ) }">${ ic( 'x', 14 ) }</button></li>`;
}

function draw() {
	const ul = document.getElementById( 'nw-subtasks' );
	if ( ! ul || ! current || current.editing ) {
		return;
	}
	const list = items();
	const done = list.filter( ( s ) => s.done ).length;
	ul.innerHTML = list.map( rowHTML ).join( '' );
	enhance( ul );
	const field = ul.closest( '.nw-subtasks-field' );
	field.querySelector( '.nw-subtasks-count' ).textContent = list.length ? ' · ' + done + '/' + list.length : '';
	const bar = field.querySelector( '.nw-subtasks-bar' );
	bar.hidden = ! list.length;
	bar.firstChild.style.width = list.length ? Math.round( ( done / list.length ) * 100 ) + '%' : '0';
	field.querySelector( '.nw-subtask-add' ).hidden = list.length >= MAX;
}

/* ---------- changes ---------- */

/** Put the change in the store at once; the server's answer replaces it. */
function local( fn ) {
	if ( ! current.id ) {
		fn( current.draft );
		draw();
		return;
	}
	const t = task( current.id );
	if ( t ) {
		const list = subtasksOf( t ).map( ( s ) => Object.assign( {}, s ) );
		fn( list );
		store().upsert( 'tasks', Object.assign( {}, t, { subtasks: list } ) );
	}
}

const path = ( sid ) => 'tasks/' + current.id + '/subtasks' + ( sid ? '/' + sid : '' );
const find = ( list, sid ) => list.find( ( s ) => s.id === sid );

async function add( title, assigneeId ) {
	if ( ! current.id ) {
		current.draft.push( { id: --current.temp, title, done: false, assigneeId: assigneeId || 0, order: current.draft.length } );
		draw();
		return;
	}
	keepTask( await write( () => post( path(), { title, assigneeId: assigneeId || 0 } ) ) );
}

function tick( sid ) {
	let done = false;
	local( ( list ) => {
		const s = find( list, sid );
		if ( s ) {
			s.done = ! s.done;
			done = s.done;
		}
	} );
	if ( current.id ) {
		write( () => patch( path( sid ), { done } ) ).then( keepTask );
	}
}

function rename( sid, title ) {
	local( ( list ) => {
		const s = find( list, sid );
		if ( s ) {
			s.title = title;
		}
	} );
	if ( current.id ) {
		write( () => patch( path( sid ), { title } ) ).then( keepTask );
	}
}

function assign( sid, assigneeId ) {
	local( ( list ) => {
		const s = find( list, sid );
		if ( s ) {
			s.assigneeId = assigneeId;
		}
	} );
	if ( current.id ) {
		write( () => patch( path( sid ), { assigneeId } ) ).then( keepTask );
	}
}

function remove( sid ) {
	const list = items();
	const i = list.findIndex( ( s ) => s.id === sid );
	if ( i < 0 ) {
		return;
	}
	const gone = list[ i ];
	const nextId = list[ i + 1 ] ? list[ i + 1 ].id : null;
	const ctx = current;
	local( ( l ) => l.splice( l.findIndex( ( s ) => s.id === sid ), 1 ) );
	if ( ! ctx.id ) {
		return;
	}
	write( () => del( path( sid ) ) ).then( keepTask );
	toast( __( 'Deleted.', 'novawork' ), 'trash', {
		label: __( 'Undo', 'novawork' ),
		// Add it back where it was, ticked and assigned as before.
		run: async () => {
			const base = 'tasks/' + ctx.id + '/subtasks';
			const t = await write( () => post( base, { title: gone.title, assigneeId: gone.assigneeId || 0 } ) );
			if ( ! t ) {
				return;
			}
			const back = subtasksOf( t ).slice( -1 )[ 0 ];
			let last = t;
			if ( back && gone.done ) {
				last = ( await write( () => patch( base + '/' + back.id, { done: true } ) ) ) || last;
			}
			if ( back && nextId && subtasksOf( last ).some( ( s ) => s.id === nextId ) ) {
				last = ( await write( () => post( base + '/' + back.id + '/move', { beforeId: nextId } ) ) ) || last;
			}
			keepTask( last );
		},
	} );
}

function move( sid, beforeId ) {
	local( ( list ) => {
		const from = list.findIndex( ( s ) => s.id === sid );
		const [ s ] = list.splice( from, 1 );
		const to = beforeId ? list.findIndex( ( x ) => x.id === beforeId ) : list.length;
		list.splice( to < 0 ? list.length : to, 0, s );
		list.forEach( ( x, i ) => ( x.order = ( i + 1 ) * 1024 ) );
	} );
	if ( current.id ) {
		write( () => post( path( sid ) + '/move', { beforeId: beforeId || null } ) ).then( keepTask );
	}
}

/* ---------- editing a title in place ---------- */
function startRename( row ) {
	const sid = Number( row.dataset.sid );
	const s = find( items(), sid );
	const span = row.querySelector( '.nw-subtask-t' );
	if ( ! s || ! span ) {
		return;
	}
	current.editing = true;
	row.draggable = false;
	const input = document.createElement( 'input' );
	input.className = 'nw-input nw-subtask-edit';
	input.maxLength = 200;
	input.value = s.title;
	input.setAttribute( 'aria-label', __( 'Subtask', 'novawork' ) );
	span.replaceWith( input );
	input.focus();
	input.select();
	let done = false;
	const finish = ( save ) => {
		if ( done ) {
			return;
		}
		done = true;
		current.editing = false;
		const title = input.value.trim();
		if ( save && title && title !== s.title ) {
			rename( sid, title );
		}
		draw();
	};
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' && ! e.isComposing ) {
			e.preventDefault();
			finish( true );
		} else if ( e.key === 'Escape' ) {
			// Cancel the rename only, not the dialog.
			e.preventDefault();
			e.stopPropagation();
			finish( false );
		}
	} );
	input.addEventListener( 'blur', () => finish( true ) );
}

/* ---------- drag to reorder ---------- */
function wireDrag( ul ) {
	let dragId = null;
	const line = document.createElement( 'li' );
	line.className = 'nw-drop-line';
	const rows = () => [ ...ul.querySelectorAll( '.nw-subtask' ) ].filter( ( r ) => Number( r.dataset.sid ) !== dragId );
	const after = ( y ) => rows().find( ( r ) => y < r.getBoundingClientRect().top + r.offsetHeight / 2 ) || null;
	ul.addEventListener( 'dragstart', ( e ) => {
		const row = e.target.closest && e.target.closest( '.nw-subtask' );
		if ( ! row ) {
			return;
		}
		dragId = Number( row.dataset.sid );
		row.classList.add( 'nw-dragging' );
		e.dataTransfer.effectAllowed = 'move';
		try {
			e.dataTransfer.setData( 'text/plain', String( dragId ) );
		} catch ( x ) {}
	} );
	ul.addEventListener( 'dragover', ( e ) => {
		if ( dragId === null ) {
			return;
		}
		e.preventDefault();
		ul.insertBefore( line, after( e.clientY ) );
	} );
	ul.addEventListener( 'drop', ( e ) => {
		if ( dragId === null ) {
			return;
		}
		e.preventDefault();
		const next = after( e.clientY );
		const id = dragId;
		dragId = null;
		line.remove();
		move( id, next ? Number( next.dataset.sid ) : null );
	} );
	ul.addEventListener( 'dragend', () => {
		dragId = null;
		line.remove();
		draw();
	} );
}

/**
 * Wire the checklist of the open dialog.
 *
 * @param {number|null} id Task id, or null for a new task (a draft).
 */
export function wireSubtasks( id ) {
	current = { id, draft: [], temp: 0, editing: false };
	const ul = document.getElementById( 'nw-subtasks' );
	const input = document.getElementById( 'nw-subtask-in' );
	if ( ! ul || ! input ) {
		return;
	}
	draw();
	ul.addEventListener( 'click', ( e ) => {
		const el = e.target.closest( '[data-sub]' );
		const row = e.target.closest( '.nw-subtask' );
		if ( ! el || ! row ) {
			return;
		}
		const sid = Number( row.dataset.sid );
		if ( el.dataset.sub === 'tick' ) {
			tick( sid );
		} else if ( el.dataset.sub === 'remove' ) {
			remove( sid );
		} else if ( el.dataset.sub === 'edit' ) {
			startRename( row );
		}
	} );
	ul.addEventListener( 'keydown', ( e ) => {
		if ( ( e.key === 'Enter' || e.key === ' ' ) && e.target.dataset.sub === 'edit' ) {
			e.preventDefault();
			startRename( e.target.closest( '.nw-subtask' ) );
		}
	} );
	ul.addEventListener( 'change', ( e ) => {
		if ( e.target.dataset.sub === 'who' ) {
			assign( Number( e.target.closest( '.nw-subtask' ).dataset.sid ), Number( e.target.value ) );
		}
	} );
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key !== 'Enter' || e.isComposing ) {
			return;
		}
		// Enter adds the subtask and keeps the field for the next one; it
		// never submits the task form.
		e.preventDefault();
		const title = input.value.trim();
		if ( title && items().length < MAX ) {
			input.value = '';
			add( title );
		}
	} );
	wireDrag( ul );

	if ( id ) {
		// A teammate's changes show while the dialog is open.
		const fx = safeEffect( () => {
			const t = store().tasks[ id ];
			void ( t && JSON.stringify( t.subtasks ) );
			void store().members;
			if ( ! ul.isConnected ) {
				queueMicrotask( () => Alpine.release( fx ) );
				return;
			}
			draw();
		} );
	}
}

/**
 * After a new task is saved: save its draft checklist, in order.
 *
 * @param {Object} saved The new task, as the API returned it.
 * @return {Promise<Object>} The task with its subtasks.
 */
export async function saveDraft( saved ) {
	const draft = current && ! current.id ? current.draft.slice() : [];
	let last = saved;
	for ( const s of draft ) {
		const base = 'tasks/' + saved.id + '/subtasks';
		const t = await write( () => post( base, { title: s.title, assigneeId: s.assigneeId || 0 } ) );
		if ( ! t ) {
			break;
		}
		last = t;
		const made = subtasksOf( t ).slice( -1 )[ 0 ];
		if ( s.done && made ) {
			last = ( await write( () => patch( base + '/' + made.id, { done: true } ) ) ) || last;
		}
	}
	return last;
}

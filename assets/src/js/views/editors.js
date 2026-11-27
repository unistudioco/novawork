/**
 * Task and project editors (and the comment box they share), ported from the
 * prototype's openTask() / openProject(). Decisions arrive with the
 * Decisions view.
 */
import { ic } from '../lib/icons.js';
import { __ } from '../lib/i18n.js';
import { esc, dur } from '../lib/format.js';
import { serverNow } from '../store.js';
import { post, patch, del } from '../lib/api.js';
import { deleteWithUndo } from './undo.js';
import { richText } from './rich.js';
import { attachComposer } from './composer.js';
import { multiline, fit } from '../lib/multiline.js';
import { subtasksField, wireSubtasks, saveDraft } from './subtasks.js';
import { wireThread, quoteHTML, actsHTML, kindOf } from './thread.js';
import { watchThreadStatus } from '../lib/ext.js';
import Alpine from 'alpinejs';
import { safeEffect } from '../lib/effect.js';
import { routeUrl, currentNonce } from '../lib/api.js';
import { openDialog, actions, closeOnly, lockFields, onDelete, onComment, dialogForm } from './dialog.js';
import { write, keepTask, stopTracking } from './actions.js';
import { toast } from '../lib/ui.js';
import {
	store, me, people, person, projects, task, project, has, commentsOf, myRun, avatar, COLORS, STATUSES, PRIORITIES, fmt, iso, todayISO, size, byOrder, votesHTML,
	canEditTask, canEditOwn, canDecide, agentAvatar,
} from './model.js';

/* ---------- comments ---------- */

/**
 * One comment or chat message (the prototype's msgHTML), with the reply
 * quote, "edited" and the Reply / Edit / Delete toolbar (owner request).
 *
 * @param {Object}  m             Message or comment.
 * @param {string}  [q]           Search text to mark.
 * @param {Object}  [opts]
 * @param {boolean} [opts.acts=true] Show the toolbar (not on search results).
 */
export function msgHTML( m, q, opts ) {
	const withActs = ! opts || opts.acts !== false;
	const S = store();
	const agent = m.agent ? S.agents.find( ( a ) => a.key === m.agent ) : null;
	const p = agent ? null : person( m.by );
	const name = agent ? agent.name : p ? p.name : __( 'Someone', 'novawork' );
	const d = new Date( m.at );
	const when = iso( d ) === todayISO() ? d.toLocaleTimeString( S.locale || undefined, { hour: '2-digit', minute: '2-digit' } ) : fmt( iso( d ) );
	const files = ( m.files || [] )
		.map( ( f ) => {
			const url = esc( f.url + ( f.url.includes( '?' ) ? '&' : '?' ) + '_wpnonce=' + encodeURIComponent( currentNonce() ) );
			return /^image\//.test( f.type ) && /^image\/(jpeg|png|gif|webp)$/.test( f.type )
				? `<a href="${ url }" target="_blank" rel="noopener"><img src="${ url }" alt="${ esc( f.name ) }"></a>`
				: `<a class="nw-fchip" href="${ url }" download>${ ic( 'file' ) }<span class="nw-fn">${ esc( f.name ) }</span><small>${ esc( size( f.size ) ) }</small>${ ic( 'download-simple', 16 ) }</a>`;
		} )
		.join( '' );
	const av = agent ? agentAvatar( m.agent ) : avatar( m.by );
	const edited = m.editedAt ? `<span class="nw-edited">${ esc( __( 'edited', 'novawork' ) ) }</span>` : '';
	return `<div class="nw-msg" data-mid="${ Number( m.id ) }" data-kind="${ kindOf( m ) }">${ av }<div class="nw-mwho">${ esc( name ) }<time>${ esc( when ) }</time>${ edited }</div>${ quoteHTML( m ) }${
		m.text ? `<div class="nw-txt">${ richText( m, q || '' ) }</div>` : ''
	}${ files ? `<div class="nw-files">${ files }</div>` : '' }${ withActs ? actsHTML( m ) : '' }</div>`;
}

function commentBox( kind, id ) {
	const list = commentsOf( kind, id );
	return `<div class="nw-field"><span class="nw-flabel">${ esc( __( 'Comments', 'novawork' ) ) }${ list.length ? ' · ' + list.length : '' }</span><div class="nw-comments" id="nw-cmts">${
		list.length ? list.map( ( c ) => msgHTML( c ) ).join( '' ) : `<span style="color:var(--nw-faint);font-size:13px">${ esc( __( 'No comments yet.', 'novawork' ) ) }</span>`
	}</div>` +
		`<div class="nw-tstatus" id="nw-cmt-status" role="status" aria-live="polite" hidden></div>` +
		`<div class="nw-replying" id="nw-cmt-reply" hidden></div>` +
		`<div class="nw-cmt-in"><label for="nw-cmt-in" class="nw-vh">${ esc( __( 'Comment', 'novawork' ) ) }</label><textarea class="nw-input nw-grow" id="nw-cmt-in" rows="1" maxlength="2000" enterkeyhint="send" placeholder="${ esc( __( 'Write a comment', 'novawork' ) ) }"></textarea><button class="nw-btn" type="button" data-cmt="1" aria-label="${ esc( __( 'Send comment', 'novawork' ) ) }">${ ic( 'paper-plane-right', 15 ) }</button></div></div>`;
}

/**
 * Wire the comment box of the open dialog to an object. The list redraws
 * whenever its comments change (yours, an edit, an Undo, or a teammate's
 * arriving with sync), except while you are editing one.
 */
function commentsFor( kind, id ) {
	const form = dialogForm();
	const inputBox = form.querySelector( '.nw-cmt-in' );
	const input = form.querySelector( '#nw-cmt-in' );
	const list = form.querySelector( '#nw-cmts' );
	const composer = attachComposer( input, { parent: inputBox, id: 'nw-cmt-suggest' } );
	let toBottom = false;
	const draw = () => {
		if ( ! list.isConnected ) {
			return;
		}
		const all = commentsOf( kind, id );
		list.innerHTML = all.length ? all.map( ( x ) => msgHTML( x ) ).join( '' ) : `<span style="color:var(--nw-faint);font-size:13px">${ esc( __( 'No comments yet.', 'novawork' ) ) }</span>`;
		list.previousElementSibling.textContent = __( 'Comments', 'novawork' ) + ( all.length ? ' · ' + all.length : '' );
		if ( toBottom ) {
			list.scrollTop = list.scrollHeight;
			toBottom = false;
		}
	};
	const thread = wireThread( {
		list: () => list,
		input: () => input,
		composer: () => composer,
		bar: () => form.querySelector( '#nw-cmt-reply' ),
		redraw: draw,
	} );
	list.addEventListener( 'click', thread.onClick );
	form.querySelector( '#nw-cmt-reply' ).addEventListener( 'click', thread.onClick );
	watchThreadStatus( form.querySelector( '#nw-cmt-status' ), `${ kind }:${ Number( id ) }` );
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' && ! composer.isOpen() && thread.escape() ) {
			e.preventDefault();
			e.stopPropagation();
		}
	} );
	const fx = safeEffect( () => {
		const sig = commentsOf( kind, id )
			.map( ( c ) => c.id + '.' + ( c.editedAt || 0 ) )
			.join();
		void store().members;
		if ( ! list.isConnected ) {
			queueMicrotask( () => Alpine.release( fx ) );
			return;
		}
		if ( sig !== list.dataset.sig && ! thread.editing() ) {
			list.dataset.sig = sig;
			draw();
		}
	} );
	const send = async () => {
		const text = input.value.trim();
		if ( ! text || composer.isOpen() ) {
			return;
		}
		const c = await write( () => post( 'comments', Object.assign( { objectType: kind, objectId: Number( id ), text, replyTo: thread.replyTo() }, composer.payload() ) ) );
		if ( ! c ) {
			return;
		}
		toBottom = true;
		store().upsert( 'comments', c );
		composer.reset();
		thread.clearReply();
		input.value = '';
		fit( input );
		input.focus();
	};
	onComment( send );
	multiline( input, send );
}

/* ---------- options ---------- */
export const projOpts = ( sel ) =>
	`<option value="0" data-icon="folder-simple">${ esc( __( 'No project', 'novawork' ) ) }</option>` +
	projects()
		.map( ( p ) => `<option data-color="${ esc( p.color ) }" value="${ p.id }"${ p.id === Number( sel ) ? ' selected' : '' }>${ esc( p.name ) }</option>` )
		.join( '' );

export const personOpts = ( sel, none ) =>
	`<option data-person value="0">${ esc( none ) }</option>` +
	people()
		.map( ( p ) => `<option data-person value="${ p.id }"${ p.id === Number( sel ) ? ' selected' : '' }>${ esc( p.name ) }</option>` )
		.join( '' );

/** "Who decides": members, then the agents add-ons registered (1.3.0). */
const deciderOpts = ( d ) =>
	personOpts( d.ownerAgent ? -1 : d.ownerId, __( 'Anyone', 'novawork' ) ) +
	store()
		.agents.map( ( a ) => `<option data-agent="${ esc( a.key ) }" value="agent:${ esc( a.key ) }"${ a.key === d.ownerAgent ? ' selected' : '' }>${ esc( a.name ) }</option>` )
		.join( '' );

/** The "Who decides" value as the API takes it: a member's id or "agent:key". */
const ownerFields = ( value ) => ( value.startsWith( 'agent:' ) ? { ownerId: 0, ownerAgent: value.slice( 6 ) } : { ownerId: Number( value ) || 0, ownerAgent: '' } );

const swatches = ( name, sel ) =>
	'<div class="nw-swatches">' +
	COLORS.map( ( c ) => `<label title="${ c }"><input type="radio" name="${ name }" value="${ c }"${ c === sel ? ' checked' : '' }><span style="--nw-c:var(--nw-c-${ c })"></span></label>` ).join( '' ) +
	'</div>';

/* ---------- task ---------- */

/**
 * @param {number|null} id       Task id, or null for a new task.
 * @param {Object}      defaults Fields for a new task (status, projectId).
 */
export function openTask( id, defaults ) {
	const S = store();
	const fProject = ( () => {
		try {
			return window.localStorage.getItem( 'nw.fProject' ) || 'all';
		} catch ( e ) {
			return 'all';
		}
	} )();
	const firstProject = projects()[ 0 ];
	const t = id
		? task( id )
		: Object.assign( { title: '', status: 'next', priority: '', assignees: [ me() ], projectId: fProject !== 'all' ? Number( fProject ) : firstProject ? firstProject.id : 0, due: '', notes: '' }, defaults || {} );
	if ( ! t ) {
		return;
	}
	const run = myRun();
	const spent = ( t.trackedMs || 0 ) + ( id && run && run.taskId === t.id ? serverNow() - run.start : 0 );
	const L = ( s ) => esc( s );
	// Someone else's task (edit rights, 1.2.0): read, comment, track; no changes.
	const ro = !! id && ! canEditTask( t );
	openDialog(
		ro ? __( 'Task', 'novawork' ) : id ? __( 'Edit task', 'novawork' ) : __( 'New task', 'novawork' ),
		`<div class="nw-field"><label for="nw-f-title" class="nw-vh">${ L( __( 'Task', 'novawork' ) ) }</label><input class="nw-input nw-big" id="nw-f-title" name="title" required maxlength="200" autofocus value="${ esc( t.title ) }" placeholder="${ L( __( 'What needs to happen?', 'novawork' ) ) }"></div>` +
			`<div class="nw-tabs nw-seg nw-full" role="radiogroup" aria-label="${ L( __( 'Status', 'novawork' ) ) }"><span class="nw-ind" aria-hidden="true"></span>${ STATUSES()
				.map( ( s ) => `<label class="nw-tab nw-s-${ s.key }${ s.key === t.status ? ' nw-on' : '' }"><input type="radio" name="status" value="${ s.key }"${ s.key === t.status ? ' checked' : '' }><span class="nw-sdot"></span>${ L( s.label ) }</label>` )
				.join( '' ) }</div>` +
			`<div class="nw-two"><div class="nw-field"><label for="nw-f-prio">${ L( __( 'Priority', 'novawork' ) ) }</label><select id="nw-f-prio" data-nw-sel data-block="1" name="priority">${ PRIORITIES()
				.map( ( p ) => `<option data-prio value="${ p.key }"${ p.key === ( t.priority || '' ) ? ' selected' : '' }>${ L( p.label ) }</option>` )
				.join( '' ) }</select></div>` +
			`<div class="nw-field"><label for="nw-f-due">${ L( __( 'Due date', 'novawork' ) ) }</label><input class="nw-input" id="nw-f-due" type="date" name="due" value="${ esc( t.due || '' ) }"></div></div>` +
			`<div class="nw-field"><label for="nw-f-proj">${ L( __( 'Project', 'novawork' ) ) }</label><select id="nw-f-proj" data-nw-sel data-block="1" name="projectId">${ projOpts( t.projectId ) }</select></div>` +
			`<div class="nw-field"><span class="nw-flabel">${ L( __( 'Assigned to', 'novawork' ) ) }</span><div class="nw-assign">${ people()
				.map( ( p ) => `<label><input type="checkbox" name="assignees" value="${ p.id }"${ has( t, p.id ) ? ' checked' : '' }><span>${ avatar( p.id ) }${ esc( p.name ) }</span></label>` )
				.join( '' ) }</div></div>` +
			`<div class="nw-field"><label for="nw-f-notes">${ L( __( 'Notes', 'novawork' ) ) }</label><textarea class="nw-input" id="nw-f-notes" name="notes" maxlength="4000" placeholder="${ L( __( 'Links, context, what done looks like', 'novawork' ) ) }">${ esc( t.notes || '' ) }</textarea></div>` +
			subtasksField( id ? t : null ) +
			( id
				? `<div class="nw-field"><span class="nw-flabel">${ L( __( 'Time tracked', 'novawork' ) ) }</span><div style="display:flex;align-items:center;gap:10px"><b class="nw-tnum" style="font:700 15px var(--nw-font-head)">${ esc( dur( spent ) ) }</b><span style="color:var(--nw-faint);font-size:12.5px">${ L( __( 'across all sessions', 'novawork' ) ) }</span></div></div><div class="nw-dlg-sep"></div>` +
				  commentBox( 'task', id )
				: '' ) +
			( ro ? closeOnly( __( 'Only the people on this task, its creator or an administrator can change it.', 'novawork' ) ) : actions( !! id ) ),
		async ( fd ) => {
			const title = String( fd.get( 'title' ) || '' ).trim();
			if ( ! title ) {
				return false;
			}
			const data = {
				title,
				status: String( fd.get( 'status' ) || 'next' ),
				priority: String( fd.get( 'priority' ) || '' ),
				assignees: fd.getAll( 'assignees' ).map( Number ),
				projectId: Number( fd.get( 'projectId' ) || 0 ),
				due: String( fd.get( 'due' ) || '' ),
				notes: String( fd.get( 'notes' ) || '' ),
			};
			if ( id && data.status === 'done' && t.status !== 'done' ) {
				const r = myRun();
				if ( r && r.taskId === t.id ) {
					await stopTracking( true );
				}
			}
			let saved = await write( () => ( id ? patch( 'tasks/' + id, data ) : post( 'tasks', data ) ), id ? __( 'Saved', 'novawork' ) : __( 'Task added', 'novawork' ) );
			if ( saved && ! id ) {
				saved = await saveDraft( saved );
			}
			keepTask( saved );
			return !! saved;
		}
	);
	wireSubtasks( id || null, ro );
	if ( ro ) {
		lockFields();
	}
	if ( id ) {
		commentsFor( 'task', id );
	}
	if ( id && ! ro ) {
		onDelete( () => {
			deleteWithUndo( 'tasks', id, 'tasks/' + id );
			return true;
		} );
	}
}

/* ---------- project ---------- */

export function openProject( id ) {
	const count = Object.keys( store().projects ).length;
	const p = id ? project( id ) : { name: '', goal: '', color: COLORS[ count % COLORS.length ], start: todayISO(), target: '', deadline: '', status: 'active' };
	if ( ! p ) {
		return;
	}
	const L = ( s ) => esc( s );
	const ro = !! id && ! canEditOwn( p );
	const statuses = [
		[ 'active', __( 'Active', 'novawork' ) ],
		[ 'paused', __( 'Paused', 'novawork' ) ],
		[ 'done', __( 'Done', 'novawork' ) ],
	];
	openDialog(
		ro ? __( 'Project', 'novawork' ) : id ? __( 'Edit project', 'novawork' ) : __( 'New project', 'novawork' ),
		`<div class="nw-field"><label for="nw-p-name" class="nw-vh">${ L( __( 'Name', 'novawork' ) ) }</label><input class="nw-input nw-big" id="nw-p-name" name="name" required maxlength="80" autofocus value="${ esc( p.name ) }" placeholder="${ L( __( 'Project name', 'novawork' ) ) }"></div>` +
			`<div class="nw-field"><label for="nw-p-goal">${ L( __( 'Goal', 'novawork' ) ) }</label><textarea class="nw-input" id="nw-p-goal" name="goal" maxlength="600" placeholder="${ L( __( 'What does done look like?', 'novawork' ) ) }">${ esc( p.goal || '' ) }</textarea></div>` +
			`<div class="nw-two"><div class="nw-field"><label for="nw-p-start">${ L( __( 'Start', 'novawork' ) ) }</label><input class="nw-input" id="nw-p-start" type="date" name="start" value="${ esc( p.start || '' ) }"></div><div class="nw-field"><label for="nw-p-target">${ L( __( 'Target', 'novawork' ) ) }</label><input class="nw-input" id="nw-p-target" type="date" name="target" value="${ esc( p.target || '' ) }"></div></div>` +
			`<div class="nw-two"><div class="nw-field"><label for="nw-p-dead">${ L( __( 'Hard deadline', 'novawork' ) ) }</label><input class="nw-input" id="nw-p-dead" type="date" name="deadline" value="${ esc( p.deadline || '' ) }"></div><div class="nw-field"><label for="nw-p-status">${ L( __( 'Status', 'novawork' ) ) }</label><select id="nw-p-status" data-nw-sel data-block="1" name="status">${ statuses
				.map( ( s ) => `<option value="${ s[ 0 ] }"${ s[ 0 ] === ( p.status || 'active' ) ? ' selected' : '' }>${ L( s[ 1 ] ) }</option>` )
				.join( '' ) }</select></div></div>` +
			`<div class="nw-field"><span class="nw-flabel">${ L( __( 'Colour', 'novawork' ) ) }</span>${ swatches( 'color', p.color ) }</div>` +
			( ro ? closeOnly( __( 'Only its creator or an administrator can change this project.', 'novawork' ) ) : actions( !! id ) ),
		async ( fd ) => {
			const data = {
				name: String( fd.get( 'name' ) || '' ).trim(),
				goal: String( fd.get( 'goal' ) || '' ),
				start: String( fd.get( 'start' ) || '' ),
				target: String( fd.get( 'target' ) || '' ),
				deadline: String( fd.get( 'deadline' ) || '' ),
				status: String( fd.get( 'status' ) || 'active' ),
				color: String( fd.get( 'color' ) || 'slate' ),
			};
			if ( ! data.name ) {
				return false;
			}
			const saved = await write( () => ( id ? patch( 'projects/' + id, data ) : post( 'projects', data ) ), id ? __( 'Saved', 'novawork' ) : __( 'Project added', 'novawork' ) );
			if ( saved && saved.id ) {
				store().upsert( 'projects', saved );
			}
			return !! saved;
		}
	);
	if ( ro ) {
		lockFields();
	} else if ( id ) {
		onDelete( () => {
			deleteWithUndo( 'projects', id, 'projects/' + id );
			return true;
		} );
	}
}

/* ---------- decision ---------- */

/**
 * Turn "one per line" text into options, keeping each option's key so votes
 * survive an edit: a line equal to an old label keeps that key; a changed
 * line in the same place keeps the key of the option it replaced (a rename).
 */
function optionsFrom( text, old ) {
	const lines = text.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean ).slice( 0, 8 );
	const used = new Set();
	return lines.map( ( label, i ) => {
		const same = old.find( ( o ) => o.label === label && ! used.has( o.key ) );
		const renamed = ! same && old[ i ] && ! lines.includes( old[ i ].label ) && ! used.has( old[ i ].key ) ? old[ i ] : null;
		const key = ( same || renamed || {} ).key;
		if ( key ) {
			used.add( key );
		}
		return key ? { key, label } : { label };
	} );
}

/** Show the options box with the checkbox; in Decide, an option fills the answer. */
function wireDecisionDialog() {
	const form = dialogForm();
	const box = form.querySelector( '#nw-d-vote' );
	if ( box ) {
		box.addEventListener( 'change', () => {
			form.querySelector( '#nw-d-opts' ).hidden = ! box.checked;
		} );
	}
	form.querySelectorAll( '[data-act="pick"]' ).forEach( ( b ) =>
		b.addEventListener( 'click', () => {
			const answer = form.querySelector( '#nw-d-a' );
			answer.value = b.querySelector( '.nw-vote-lbl' ).textContent + '. ';
			answer.focus();
			answer.setSelectionRange( answer.value.length, answer.value.length );
		} )
	);
}

/** Vote for an option, or withdraw by clicking your own choice again. */
export async function vote( id, key ) {
	const S = store();
	const d = S.decisions[ id ];
	if ( ! d ) {
		return;
	}
	const mine = ( d.votes || {} )[ S.me ];
	const option = mine === key ? null : key;
	const votes = Object.assign( {}, d.votes );
	if ( option ) {
		votes[ S.me ] = option;
	} else {
		delete votes[ S.me ];
	}
	S.upsert( 'decisions', Object.assign( {}, d, { votes } ) );
	const saved = await write( () => post( 'decisions/' + id + '/vote', { option } ) );
	if ( saved && saved.id ) {
		S.upsert( 'decisions', saved );
	} else {
		S.upsert( 'decisions', d );
	}
}

/**
 * @param {number|null} id        Decision id, or null for a new one.
 * @param {boolean}     decideNow Open on the answer ("Decide").
 */
export function openDecision( id, decideNow ) {
	const S = store();
	const d = id ? S.decisions[ id ] : { question: '', context: '', projectId: 0, ownerId: me(), ownerAgent: '', decideBy: '', status: 'open', answer: '', voting: false, options: [], votes: {} };
	if ( ! d ) {
		return;
	}
	const showAnswer = decideNow || d.status === 'decided';
	const L = ( s ) => esc( s );
	// Someone else's decision (edit rights, 1.2.0): the one who decides still
	// records or reopens it; everything else is read only.
	const ro = !! id && ! canEditOwn( d );
	const decider = ro && canDecide( d ) && showAnswer;
	openDialog(
		decideNow ? __( 'Record decision', 'novawork' ) : id ? __( 'Edit decision', 'novawork' ) : __( 'New decision', 'novawork' ),
		`<div class="nw-field"><label for="nw-d-q" class="nw-vh">${ L( __( 'Question', 'novawork' ) ) }</label><input class="nw-input nw-big" id="nw-d-q" name="question" required maxlength="240" ${ decideNow ? '' : 'autofocus ' }value="${ esc( d.question ) }" placeholder="${ L(
			__( 'What needs deciding?', 'novawork' )
		) }"></div>` +
			`<div class="nw-field"><label for="nw-d-o">${ L( __( 'Options and context', 'novawork' ) ) }</label><textarea class="nw-input" id="nw-d-o" name="context" maxlength="2000">${ esc( d.context || '' ) }</textarea></div>` +
			`<div class="nw-two"><div class="nw-field"><label for="nw-d-p">${ L( __( 'Project', 'novawork' ) ) }</label><select id="nw-d-p" data-nw-sel data-block="1" name="projectId">${ projOpts( d.projectId ) }</select></div><div class="nw-field"><label for="nw-d-by">${ L(
				__( 'Decide by', 'novawork' )
			) }</label><input class="nw-input" id="nw-d-by" type="date" name="decideBy" value="${ esc( d.decideBy || '' ) }"></div></div>` +
			`<div class="nw-field"><label for="nw-d-own">${ L( __( 'Who decides', 'novawork' ) ) }</label><select id="nw-d-own" data-nw-sel data-block="1" name="ownerId">${ deciderOpts( d ) }</select></div>` +
			( ! decideNow && S.settings.decisionVotes !== false
				? `<label class="nw-checkline"><input type="checkbox" name="voting" value="1" id="nw-d-vote"${ d.voting ? ' checked' : '' }> ${ L( __( 'Ask the team to vote', 'novawork' ) ) }</label>` +
				  `<div class="nw-field" id="nw-d-opts"${ d.voting ? '' : ' hidden' }><label for="nw-d-ol">${ L( __( 'Options, one per line', 'novawork' ) ) }</label><textarea class="nw-input" id="nw-d-ol" name="options" maxlength="1000" placeholder="${ L(
					__( 'Up to eight short options', 'novawork' )
				) }">${ esc( ( d.options || [] ).map( ( o ) => o.label ).join( '\n' ) ) }</textarea></div>`
				: '' ) +
			( decideNow && d.voting && ( d.options || [] ).length
				? `<div class="nw-field"><span class="nw-flabel">${ L( __( 'Team votes', 'novawork' ) ) }</span>${ votesHTML( d, 'pick', true ) }</div>`
				: '' ) +
			( showAnswer
				? `<div class="nw-field"><label for="nw-d-a">${ L( __( 'Decision', 'novawork' ) ) }</label><textarea class="nw-input" id="nw-d-a" name="answer" maxlength="2000" ${ decideNow ? 'autofocus required ' : '' }placeholder="${ L(
					__( 'What we decided, and why', 'novawork' )
				) }">${ esc( d.answer || '' ) }</textarea></div>` +
				  ( id && d.status === 'decided' ? `<label class="nw-checkline"><input type="checkbox" name="reopen" value="1"> ${ L( __( 'Reopen this decision', 'novawork' ) ) }</label>` : '' )
				: '' ) +
			( id ? commentBox( 'decision', id ) : '' ) +
			( ro && ! decider ? closeOnly( __( 'Only its creator or an administrator can change this decision.', 'novawork' ) ) : actions( !! id && ! ro ) ),
		async ( fd ) => {
			if ( ro ) {
				// The one who decides: only the outcome.
				const outcome = { answer: String( fd.get( 'answer' ) || '' ) };
				if ( decideNow ) {
					if ( ! outcome.answer.trim() ) {
						return false;
					}
					outcome.status = 'decided';
				}
				if ( fd.get( 'reopen' ) ) {
					outcome.status = 'open';
				}
				const done = await write( () => patch( 'decisions/' + id, outcome ), decideNow ? __( 'Decision recorded', 'novawork' ) : __( 'Saved', 'novawork' ) );
				if ( done && done.id ) {
					store().upsert( 'decisions', done );
				}
				return !! done;
			}
			const data = {
				question: String( fd.get( 'question' ) || '' ).trim(),
				context: String( fd.get( 'context' ) || '' ),
				projectId: Number( fd.get( 'projectId' ) || 0 ),
				decideBy: String( fd.get( 'decideBy' ) || '' ),
				...ownerFields( String( fd.get( 'ownerId' ) || '0' ) ),
			};
			if ( ! data.question ) {
				return false;
			}
			if ( showAnswer ) {
				data.answer = String( fd.get( 'answer' ) || '' );
			}
			if ( ! decideNow && S.settings.decisionVotes !== false ) {
				data.voting = !! fd.get( 'voting' );
				data.options = optionsFrom( String( fd.get( 'options' ) || '' ), d.options || [] );
			}
			if ( decideNow ) {
				if ( ! data.answer.trim() ) {
					return false;
				}
				data.status = 'decided';
			}
			if ( fd.get( 'reopen' ) ) {
				data.status = 'open';
			}
			const ok = decideNow ? __( 'Decision recorded', 'novawork' ) : id ? __( 'Saved', 'novawork' ) : __( 'Decision added', 'novawork' );
			const saved = await write( () => ( id ? patch( 'decisions/' + id, data ) : post( 'decisions', data ) ), ok );
			if ( saved && saved.id ) {
				store().upsert( 'decisions', saved );
			}
			return !! saved;
		}
	);
	wireDecisionDialog();
	if ( ro ) {
		lockFields( decider ? '#nw-d-a, [name="reopen"]' : '' );
	}
	if ( id ) {
		commentsFor( 'decision', id );
	}
	if ( id && ! ro ) {
		onDelete( () => {
			deleteWithUndo( 'decisions', id, 'decisions/' + id );
			return true;
		} );
	}
}

/* ---------- a tracked session (administrators, 1.2.0) ---------- */

const two = ( n ) => String( n ).padStart( 2, '0' );
const hhmm = ( ms ) => {
	const d = new Date( ms );
	return two( d.getHours() ) + ':' + two( d.getMinutes() );
};
/** Local date + "HH:MM" to epoch ms. */
const at = ( date, time ) => new Date( date + 'T' + ( time || '00:00' ) ).getTime();

/**
 * Add or change a session by hand: who, which task, the day, from and to.
 * Only administrators see the buttons; the server checks it too.
 *
 * @param {Object|null} e     Session ({id, by, taskId, start, end, running}), or null to add one.
 * @param {Function}    after Called after a save or delete (the Time page refreshes).
 */
export function openSession( e, after ) {
	const S = store();
	const now = serverNow();
	const s = e || { by: S.me, taskId: 0, start: now - 3600000, end: now, running: false };
	const L = ( x ) => esc( x );
	const taskOpts = Object.values( S.tasks )
		.slice()
		.sort( ( a, b ) => a.title.localeCompare( b.title ) )
		.map( ( t ) => `<option value="${ t.id }"${ t.id === s.taskId ? ' selected' : '' }>${ esc( t.title ) }</option>` )
		.join( '' );
	const who = person( s.by );
	openDialog(
		e ? __( 'Edit session', 'novawork' ) : __( 'Add time', 'novawork' ),
		( e
			? `<div class="nw-field"><span class="nw-flabel">${ L( __( 'Tracked by', 'novawork' ) ) }</span><div class="nw-sess-who">${ avatar( s.by ) }<span>${ esc( who ? who.name : '' ) }</span></div></div>`
			: `<div class="nw-field"><label for="nw-s-by">${ L( __( 'Tracked by', 'novawork' ) ) }</label><select id="nw-s-by" data-nw-sel data-block="1" name="by">${ people()
				.map( ( p ) => `<option data-person value="${ p.id }"${ p.id === s.by ? ' selected' : '' }>${ esc( p.name ) }</option>` )
				.join( '' ) }</select></div>` ) +
			`<div class="nw-field"><label for="nw-s-task">${ L( __( 'Task', 'novawork' ) ) }</label><select id="nw-s-task" data-nw-sel data-block="1" name="taskId">${
				s.taskId && ! S.tasks[ s.taskId ] ? `<option value="${ s.taskId }" selected>${ L( __( 'Deleted task', 'novawork' ) ) }</option>` : ''
			}${ taskOpts }</select></div>` +
			`<div class="nw-three"><div class="nw-field"><label for="nw-s-day">${ L( __( 'Day', 'novawork' ) ) }</label><input class="nw-input" id="nw-s-day" type="date" name="day" required value="${ iso( new Date( s.start ) ) }"></div>` +
			`<div class="nw-field"><label for="nw-s-from">${ L( __( 'From', 'novawork' ) ) }</label><input class="nw-input" id="nw-s-from" type="time" name="from" required value="${ hhmm( s.start ) }"></div>` +
			`<div class="nw-field"><label for="nw-s-to">${ L( __( 'To', 'novawork' ) ) }</label>${
				s.running
					? `<input class="nw-input" id="nw-s-to" type="text" disabled value="${ L( __( 'Running', 'novawork' ) ) }">`
					: `<input class="nw-input" id="nw-s-to" type="time" name="to" required value="${ hhmm( s.end ) }">`
			}</div></div>` +
			`<p class="nw-sess-note">${ L( __( 'An end earlier than the start counts as the next day. Sessions of one person can’t overlap.', 'novawork' ) ) }</p>` +
			actions( !! e ),
		async ( fd ) => {
			const day = String( fd.get( 'day' ) || '' );
			const start = at( day, String( fd.get( 'from' ) || '' ) );
			const taskId = Number( fd.get( 'taskId' ) || 0 );
			if ( ! day || ! taskId ) {
				toast( __( 'Pick a task and a day.', 'novawork' ), 'x' );
				return false;
			}
			const body = { taskId, start };
			if ( ! s.running ) {
				let end = at( day, String( fd.get( 'to' ) || '' ) );
				if ( end <= start ) {
					end += 86400000;
				}
				body.end = end;
			}
			const saved = await write(
				() => ( e ? patch( 'time-entries/' + e.id, body ) : post( 'time-entries', Object.assign( body, { userId: Number( fd.get( 'by' ) || S.me ) } ) ) ),
				e ? __( 'Session saved', 'novawork' ) : __( 'Time added', 'novawork' )
			);
			if ( saved && saved.id ) {
				S.upsert( 'timeEntries', saved );
				after();
			}
			return !! saved;
		}
	);
	if ( e ) {
		onDelete( () => {
			write( () => del( 'time-entries/' + e.id ) ).then( ( gone ) => {
				if ( ! gone ) {
					return;
				}
				S.remove( 'timeEntries', e.id );
				after();
				const back = gone.session || e;
				toast( __( 'Deleted.', 'novawork' ), 'trash', {
					label: __( 'Undo', 'novawork' ),
					run: () =>
						write( () => post( 'time-entries', { userId: back.by, taskId: back.taskId, start: back.start, end: back.end || serverNow() } ) ).then( ( again ) => {
							if ( again && again.id ) {
								S.upsert( 'timeEntries', again );
								after();
							}
						} ),
				} );
			} );
			return true;
		} );
	}
}

// Exposed for views that sort projects the same way.
export { byOrder, routeUrl };

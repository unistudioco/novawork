/**
 * Suggestions while typing a chat message or a comment (owner requests):
 * "@" suggests members; "/" suggests tasks, decisions and projects ("/task",
 * "/decision" or "/project" narrows to one kind). Arrows move, Enter or Tab
 * picks, Escape closes. The picked text goes into the field ("@Sam ",
 * "/Fix the checkout bug ") and the id is remembered for the server, which
 * keeps it only if that text is still there when you send.
 */
import { ic } from '../lib/icons.js';
import { __ } from '../lib/i18n.js';
import { esc } from '../lib/format.js';
import { store, people, avatar, agentAvatar, byOrder } from './model.js';
import { REF_ICONS } from './rich.js';

const KINDS = () => ( { task: __( 'Task', 'novawork' ), decision: __( 'Decision', 'novawork' ), project: __( 'Project', 'novawork' ) } );

function items( type, q ) {
	const S = store();
	const all = [];
	if ( ! type || type === 'task' ) {
		Object.values( S.tasks ).forEach( ( t ) => all.push( { type: 'task', id: t.id, label: t.title, open: t.status !== 'done', mine: ( t.assignees || [] ).includes( S.me ) && t.status === 'now', order: t.order } ) );
	}
	if ( ! type || type === 'decision' ) {
		Object.values( S.decisions ).forEach( ( d ) => all.push( { type: 'decision', id: d.id, label: d.question, open: d.status !== 'decided', mine: d.status !== 'decided', order: d.order } ) );
	}
	if ( ! type || type === 'project' ) {
		Object.values( S.projects ).forEach( ( p ) => all.push( { type: 'project', id: p.id, label: p.name, open: p.status !== 'done', mine: p.status === 'active', order: p.order } ) );
	}
	if ( ! q ) {
		// Nothing typed yet: your current work first.
		return all.filter( ( x ) => x.mine ).sort( byOrder ).slice( 0, 8 );
	}
	const starts = ( x ) => x.label.toLowerCase().startsWith( q ) || x.label.toLowerCase().split( /\s+/ ).some( ( w ) => w.startsWith( q ) );
	return all
		.filter( ( x ) => x.label.toLowerCase().includes( q ) )
		.sort( ( a, b ) => Number( starts( b ) ) - Number( starts( a ) ) || Number( b.open ) - Number( a.open ) || a.label.localeCompare( b.label ) )
		.slice( 0, 8 );
}

/**
 * @param {HTMLInputElement} input   The text field.
 * @param {Object}           options
 * @param {HTMLElement}      options.parent Positioned element the list goes in.
 * @param {string}           options.id     Id for the list (aria-controls).
 * @param {Object}           [options.seed] Mentions and links already in the text (editing).
 */
export function attachComposer( input, { parent, id, seed } ) {
	const mentions = new Set( ( ( seed && seed.mentions ) || [] ).filter( ( m ) => m.id ).map( ( m ) => m.id ) );
	// Agents add-ons registered (1.3.0) are mentioned by key.
	const agents = new Set( ( ( seed && seed.mentions ) || [] ).filter( ( m ) => m.agent ).map( ( m ) => m.agent ) );
	const refs = new Map( ( ( seed && seed.refs ) || [] ).map( ( r ) => [ r.type + ':' + r.id, { type: r.type, id: r.id, label: r.label } ] ) );
	let state = null;

	input.setAttribute( 'aria-autocomplete', 'list' );
	input.setAttribute( 'aria-controls', id );

	function query() {
		const before = input.value.slice( 0, input.selectionStart );
		let m = before.match( /(^|\s)@([^\s@/]{0,30})$/ );
		if ( m ) {
			return { kind: 'at', q: m[ 2 ].toLowerCase(), start: before.length - m[ 2 ].length - 1 };
		}
		m = before.match( /(^|\s)\/([^/\n]{0,40})$/ );
		if ( m && ! /\s{2}/.test( m[ 2 ] ) ) {
			let q = m[ 2 ].toLowerCase();
			let type = '';
			const k = q.match( /^(task|decision|project)(\s|$)/ );
			if ( k ) {
				type = k[ 1 ];
				q = q.slice( k[ 0 ].length );
			}
			return { kind: 'slash', q: q.trim(), type, start: before.length - m[ 2 ].length - 1 };
		}
		return null;
	}

	function close() {
		const ul = document.getElementById( id );
		if ( ul ) {
			ul.remove();
		}
		input.removeAttribute( 'aria-activedescendant' );
		state = null;
	}

	function show() {
		const found = query();
		if ( ! found ) {
			close();
			return;
		}
		const S = store();
		const list =
			found.kind === 'at'
				? [
						...people()
							.filter( ( p ) => p.id !== S.me )
							.map( ( p ) => ( { kind: 'at', id: p.id, label: p.name } ) ),
						...S.agents.map( ( a ) => ( { kind: 'at', agent: a.key, label: a.name } ) ),
				  ]
						.filter( ( x ) => [ x.label.toLowerCase(), ...x.label.toLowerCase().split( /\s+/ ) ].some( ( w ) => w.startsWith( found.q ) ) )
						.slice( 0, 6 )
				: items( found.type, found.q ).map( ( x ) => Object.assign( { kind: 'slash' }, x ) );
		if ( ! list.length ) {
			close();
			return;
		}
		state = { start: found.start, list, act: state && state.act < list.length ? state.act : 0 };
		let ul = document.getElementById( id );
		if ( ! ul ) {
			ul = document.createElement( 'ul' );
			ul.id = id;
			ul.className = 'nw-sel-menu nw-mention-menu';
			ul.setAttribute( 'role', 'listbox' );
			ul.addEventListener( 'pointerdown', ( e ) => {
				const li = e.target.closest( 'li' );
				if ( li ) {
					e.preventDefault();
					pick( Number( li.dataset.i ) );
				}
			} );
			parent.appendChild( ul );
		}
		ul.setAttribute( 'aria-label', found.kind === 'at' ? __( 'Mention someone', 'novawork' ) : __( 'Link a task, decision or project', 'novawork' ) );
		const kinds = KINDS();
		ul.innerHTML = list
			.map(
				( x, i ) =>
					`<li role="option" id="${ id }-${ i }" data-i="${ i }" aria-selected="${ i === state.act }" class="${ i === state.act ? 'nw-active' : '' }">${
						x.kind === 'at' ? ( x.agent ? agentAvatar( x.agent ) : avatar( x.id ) ) : ic( REF_ICONS[ x.type ], 16 )
					}<span class="nw-opt-lbl">${ esc( x.label ) }</span>${ x.kind === 'slash' ? `<small class="nw-ref-kind">${ esc( kinds[ x.type ] ) }</small>` : '' }</li>`
			)
			.join( '' );
		input.setAttribute( 'aria-activedescendant', id + '-' + state.act );
		const active = ul.children[ state.act ];
		if ( active ) {
			active.scrollIntoView( { block: 'nearest' } );
		}
	}

	function pick( i ) {
		const x = state && state.list[ i ];
		if ( ! x ) {
			return;
		}
		const caret = input.selectionStart;
		const insert = ( x.kind === 'at' ? '@' : '/' ) + x.label + ' ';
		input.value = input.value.slice( 0, state.start ) + insert + input.value.slice( caret );
		const at = state.start + insert.length;
		input.setSelectionRange( at, at );
		if ( x.kind === 'at' && x.agent ) {
			agents.add( x.agent );
		} else if ( x.kind === 'at' ) {
			mentions.add( x.id );
		} else {
			refs.set( x.type + ':' + x.id, { type: x.type, id: x.id, label: x.label } );
		}
		close();
		input.focus();
	}

	input.addEventListener( 'input', show );
	input.addEventListener( 'keydown', ( e ) => {
		if ( ! state ) {
			return;
		}
		const n = state.list.length;
		if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
			e.preventDefault();
			state.act = ( state.act + ( e.key === 'ArrowDown' ? 1 : n - 1 ) ) % n;
			show();
		} else if ( e.key === 'Enter' || e.key === 'Tab' ) {
			e.preventDefault();
			e.stopPropagation();
			pick( state.act );
		} else if ( e.key === 'Escape' ) {
			e.preventDefault();
			e.stopPropagation();
			close();
		}
	} );
	input.addEventListener( 'blur', () => setTimeout( close, 120 ) );

	return {
		isOpen: () => !! state,
		close,
		/** Remember a member mentioned without the list (a reply's "@Name "). */
		mention( mid ) {
			mentions.add( mid );
		},
		/** Remember an agent mentioned without the list (a reply to an agent). */
		mentionAgent( key ) {
			agents.add( key );
		},
		/** What to send with the text: only what is still in it. */
		payload() {
			const text = input.value.toLowerCase();
			const ppl = people();
			return {
				mentions: [ ...mentions ].filter( ( mid ) => {
					const p = ppl.find( ( m ) => m.id === mid );
					return p && text.includes( '@' + p.name.toLowerCase() );
				} ),
				refs: [ ...refs.values() ].filter( ( r ) => text.includes( '/' + r.label.toLowerCase() ) ).map( ( r ) => ( { type: r.type, id: r.id } ) ),
				agents: [ ...agents ].filter( ( key ) => {
					const a = store().agents.find( ( x ) => x.key === key );
					return a && text.includes( '@' + a.name.toLowerCase() );
				} ),
			};
		},
		reset() {
			mentions.clear();
			agents.clear();
			refs.clear();
			close();
		},
	};
}

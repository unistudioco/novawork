/**
 * Reply, Edit and Delete on chat messages and comments (owner request).
 *
 * - Reply puts "@Name " in the text field and a "Replying to" bar above it;
 *   the sent message quotes the one it answers, and clicking the quote
 *   scrolls to it (loading older chat history when needed) and flashes it.
 * - Edit turns your own message into a text field in place: Enter saves,
 *   Escape cancels. @mentions and /links work as when writing.
 * - Delete is the usual delete with Undo.
 *
 * Who may edit or delete follows the message_edits setting (always, for a
 * number of minutes after posting, or never); administrators may delete
 * anyone's. The server checks the same rule.
 */
import { ic } from '../lib/icons.js';
import { __, sprintf } from '../lib/i18n.js';
import { esc } from '../lib/format.js';
import { patch } from '../lib/api.js';
import { toast } from '../lib/ui.js';
import { serverNow } from '../store.js';
import { store, person } from './model.js';
import { plainText } from './rich.js';
import { attachComposer } from './composer.js';
import { multiline, isSendKey } from '../lib/multiline.js';
import { deleteWithUndo } from './undo.js';
import { messageActions, runMessageAction } from '../lib/ext.js';

const KEY = { message: 'messages', comment: 'comments' };
const PATH = { message: 'messages/', comment: 'comments/' };

export const kindOf = ( m ) => ( m.objectType ? 'comment' : 'message' );

/** Whether you may edit this message or comment (your own, under the setting). */
export function canEdit( m ) {
	const S = store();
	if ( m.agent || m.by !== S.me ) {
		return false;
	}
	const rule = S.settings.messageEdits || 'always';
	if ( rule === 'never' ) {
		return false;
	}
	return rule !== 'window' || serverNow() - m.at < ( S.settings.editMinutes || 15 ) * 60000;
}

/** Whether you may delete it: as for editing, and administrators anyone's. */
export const canDelete = ( m ) => store().canManage || canEdit( m );

const authorName = ( m ) => {
	const S = store();
	if ( m.agent ) {
		const a = S.agents.find( ( x ) => x.key === m.agent );
		return a ? a.name : __( 'Someone', 'novawork' );
	}
	const p = person( m.by );
	return p ? p.name : __( 'Someone', 'novawork' );
};

/**
 * The message a reply answers: the live one when it is on this device, else
 * the server's copy (older chat history); null when it was deleted.
 */
function original( m ) {
	const S = store();
	const kind = kindOf( m );
	const live = S[ KEY[ kind ] ][ m.replyTo ];
	if ( live ) {
		return live;
	}
	if ( kind === 'comment' ) {
		return null; // Every comment is on the device: missing means deleted.
	}
	// Chat history is loaded from the newest back: a missing message newer
	// than the oldest loaded one was deleted.
	const ids = Object.keys( S.messages ).map( Number );
	const oldest = ids.length ? Math.min( ...ids ) : 0;
	return m.reply && m.replyTo < oldest ? m.reply : null;
}

/** The quote above a reply (empty for a plain message). */
export function quoteHTML( m ) {
	if ( ! m.replyTo ) {
		return '';
	}
	const o = original( m );
	if ( ! o ) {
		return `<span class="nw-quote nw-gone">${ ic( 'arrow-bend-up-left', 13 ) }<span>${ esc( kindOf( m ) === 'comment' ? __( 'The comment this replied to was deleted.', 'novawork' ) : __( 'The message this replied to was deleted.', 'novawork' ) ) }</span></span>`;
	}
	const text = plainText( o ) || __( 'A file', 'novawork' );
	return `<button type="button" class="nw-quote" data-tact="jump" data-to="${ Number( m.replyTo ) }" aria-label="${ esc( sprintf( /* translators: %s: a person's name. */ __( 'Go to the message from %s', 'novawork' ), authorName( o ) ) ) }">${ ic( 'arrow-bend-up-left', 13 ) }<b>${ esc( authorName( o ) ) }</b><span>${ esc( text ) }</span></button>`;
}

/**
 * The small toolbar on a message (hover or focus on desktop, a tap on touch):
 * Reply, Edit and Delete, then any add-on's buttons (1.3.0).
 */
export function actsHTML( m ) {
	const btn = ( act, icon, label, pressed = null ) =>
		`<button type="button" class="nw-iconbtn${ pressed ? ' nw-on' : '' }" data-tact="${ esc( act ) }" aria-label="${ esc( label ) }" title="${ esc( label ) }"${ null === pressed ? '' : ` aria-pressed="${ pressed ? 'true' : 'false' }"` }>${ icon }</button>`;
	const extra = messageActions( m, kindOf( m ) )
		.map( ( a ) => btn( 'x:' + a.id, a.icon, a.label, a.pressed ) )
		.join( '' );
	return (
		`<div class="nw-msg-acts" role="toolbar" aria-label="${ esc( __( 'Message actions', 'novawork' ) ) }">` +
		btn( 'reply', ic( 'arrow-bend-up-left', 15 ), __( 'Reply', 'novawork' ) ) +
		( canEdit( m ) ? btn( 'edit', ic( 'pencil-simple', 15 ), __( 'Edit', 'novawork' ) ) : '' ) +
		( canDelete( m ) ? btn( 'delete', ic( 'trash', 15 ), __( 'Delete', 'novawork' ) ) : '' ) +
		extra +
		'</div>'
	);
}

/**
 * Wire Reply / Edit / Delete for one list of messages and its text field.
 *
 * @param {Object}           o
 * @param {Function}         o.list     The element holding the messages (now).
 * @param {HTMLInputElement} o.input    The text field replies go into.
 * @param {Object}           o.composer Its composer (mention suggestions).
 * @param {Function}         o.bar      The "Replying to" element (now).
 * @param {Function}         o.redraw   Draw the list again.
 * @param {Function}         [o.find]   Load older messages until an id is there.
 * @return {Object} { replyTo(), clearReply(), editing() }
 */
export function wireThread( o ) {
	let reply = null;
	let edit = null;

	function paintBar() {
		const bar = o.bar();
		if ( ! bar ) {
			return;
		}
		bar.hidden = ! reply;
		bar.innerHTML = reply
			? `${ ic( 'arrow-bend-up-left', 14 ) }<span class="nw-rp-txt">${ sprintf(
				/* translators: %s: a person's name. */
				esc( __( 'Replying to %s', 'novawork' ) ),
				`<b>${ esc( reply.name ) }</b>`
			) }<span>${ esc( reply.text ) }</span></span><button type="button" class="nw-iconbtn" data-tact="unreply" aria-label="${ esc( __( 'Cancel reply', 'novawork' ) ) }">${ ic( 'x', 14 ) }</button>`
			: '';
	}

	function startReply( m ) {
		const S = store();
		reply = { id: m.id, name: authorName( m ), text: plainText( m ) || __( 'A file', 'novawork' ) };
		paintBar();
		const input = o.input();
		const p = ! m.agent && m.by !== S.me ? person( m.by ) : null;
		if ( p && ! input.value.toLowerCase().includes( '@' + p.name.toLowerCase() ) ) {
			input.value = '@' + p.name + ' ' + input.value.replace( /^\s+/, '' );
			o.composer().mention( p.id );
		}
		// Replying to an agent asks it again (1.3.0).
		const a = m.agent ? S.agents.find( ( x ) => x.key === m.agent ) : null;
		if ( a && ! input.value.toLowerCase().includes( '@' + a.name.toLowerCase() ) ) {
			input.value = '@' + a.name + ' ' + input.value.replace( /^\s+/, '' );
			o.composer().mentionAgent( a.key );
		}
		input.focus();
		input.setSelectionRange( input.value.length, input.value.length );
	}

	function clearReply() {
		reply = null;
		paintBar();
	}

	function startEdit( el, m ) {
		if ( edit ) {
			stopEdit();
		}
		const txt = el.querySelector( '.nw-txt' );
		const box = document.createElement( 'div' );
		box.className = 'nw-edit';
		box.innerHTML = `<label class="nw-vh" for="nw-edit-in">${ esc( __( 'Edit message', 'novawork' ) ) }</label><textarea class="nw-input nw-grow" id="nw-edit-in" rows="1" maxlength="2000"></textarea><div class="nw-edit-foot"><span>${ esc(
			__( 'Enter to save · Esc to cancel', 'novawork' )
		) }</span><button type="button" class="nw-btn nw-ghost nw-sm" data-tact="edit-cancel">${ esc( __( 'Cancel', 'novawork' ) ) }</button><button type="button" class="nw-btn nw-primary nw-sm" data-tact="edit-save">${ esc( __( 'Save', 'novawork' ) ) }</button></div>`;
		if ( txt ) {
			txt.replaceWith( box );
		} else {
			el.querySelector( '.nw-mwho' ).after( box );
		}
		el.classList.add( 'nw-editing' );
		const input = box.querySelector( 'textarea' );
		input.value = m.text || '';
		const composer = attachComposer( input, { parent: box, id: 'nw-edit-suggest', seed: { mentions: m.mentions, refs: m.refs } } );
		edit = { m, el, input, composer };
		input.addEventListener( 'keydown', ( e ) => {
			if ( composer.isOpen() ) {
				return;
			}
			if ( e.key === 'Escape' ) {
				// Cancel the edit only, not the chat window or the dialog.
				e.preventDefault();
				e.stopPropagation();
				stopEdit();
			} else if ( isSendKey( e ) ) {
				e.preventDefault();
				e.stopPropagation();
				saveEdit();
			} else if ( e.key === 'Enter' ) {
				// Shift+Enter: a new line, kept inside the edit.
				e.stopPropagation();
			}
		} );
		multiline( input );
		input.focus();
		input.setSelectionRange( input.value.length, input.value.length );
	}

	function stopEdit() {
		if ( ! edit ) {
			return;
		}
		edit.composer.close();
		edit = null;
		o.redraw();
	}

	async function saveEdit() {
		if ( ! edit || edit.saving ) {
			return;
		}
		const { m, input, composer } = edit;
		const text = input.value.trim();
		if ( text === ( m.text || '' ) ) {
			stopEdit();
			return;
		}
		if ( ! text && ! ( m.files || [] ).length ) {
			toast( kindOf( m ) === 'comment' ? __( 'A comment needs some text. To remove it, delete it.', 'novawork' ) : __( 'A message needs some text. To remove it, delete it.', 'novawork' ), 'x' );
			return;
		}
		edit.saving = true;
		try {
			const row = await patch( PATH[ kindOf( m ) ] + m.id, Object.assign( { text }, composer.payload() ) );
			store().upsert( KEY[ kindOf( m ) ], row );
			stopEdit();
		} catch ( e ) {
			edit.saving = false;
			toast( ( e && e.status < 500 && e.message ) || __( 'Could not save. Check your connection and try again.', 'novawork' ), 'x' );
		}
	}

	async function jump( id ) {
		let el = o.list().querySelector( `[data-mid="${ Number( id ) }"]` );
		if ( ! el && o.find ) {
			await o.find( id );
			el = o.list().querySelector( `[data-mid="${ Number( id ) }"]` );
		}
		if ( ! el ) {
			toast( __( 'That message is no longer here.', 'novawork' ), 'x' );
			return;
		}
		el.scrollIntoView( { block: 'center', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
		el.classList.remove( 'nw-flash' );
		void el.offsetWidth;
		el.classList.add( 'nw-flash' );
		setTimeout( () => el.classList.remove( 'nw-flash' ), 1700 );
	}

	function onClick( e ) {
		const b = e.target.closest( '[data-tact]' );
		const list = o.list();
		if ( ! b ) {
			// Touch: a tap on a message shows its actions (one at a time).
			const el = e.target.closest( '.nw-msg[data-mid]' );
			if ( el && list.contains( el ) && window.matchMedia( '(hover: none)' ).matches && ! e.target.closest( 'a, button, input' ) ) {
				const on = ! el.classList.contains( 'nw-acts-on' );
				list.querySelectorAll( '.nw-acts-on' ).forEach( ( n ) => n.classList.remove( 'nw-acts-on' ) );
				el.classList.toggle( 'nw-acts-on', on );
			}
			return;
		}
		const act = b.dataset.tact;
		if ( act === 'unreply' ) {
			clearReply();
			o.input().focus();
			return;
		}
		if ( act === 'edit-cancel' ) {
			stopEdit();
			return;
		}
		if ( act === 'edit-save' ) {
			saveEdit();
			return;
		}
		if ( act === 'jump' ) {
			e.preventDefault();
			jump( Number( b.dataset.to ) );
			return;
		}
		const el = b.closest( '.nw-msg[data-mid]' );
		if ( ! el ) {
			return;
		}
		const kind = el.dataset.kind;
		const m = store()[ KEY[ kind ] ][ Number( el.dataset.mid ) ];
		if ( ! m ) {
			return;
		}
		e.preventDefault();
		el.classList.remove( 'nw-acts-on' );
		if ( act.startsWith( 'x:' ) ) {
			runMessageAction( act.slice( 2 ), m, kind ).finally( () => o.redraw() );
			return;
		}
		if ( act === 'reply' ) {
			startReply( m );
		} else if ( act === 'edit' ) {
			startEdit( el, m );
		} else if ( act === 'delete' ) {
			if ( reply && reply.id === m.id ) {
				clearReply();
			}
			deleteWithUndo( KEY[ kind ], m.id, PATH[ kind ] + m.id );
			o.redraw();
		}
	}

	return {
		onClick,
		replyTo: () => ( reply ? reply.id : 0 ),
		clearReply,
		editing: () => !! edit,
		jump,
		/** Escape in the text field: drop the reply first. */
		escape() {
			if ( reply ) {
				clearReply();
				return true;
			}
			return false;
		},
		paintBar,
	};
}

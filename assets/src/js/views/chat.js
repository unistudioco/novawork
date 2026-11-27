/**
 * Team chat: one channel. The window docks left or right (pushing the page
 * over), floats anywhere when dragged, and rises as a bottom sheet on phones.
 * Attachments, history that loads as you scroll up, search with a date
 * filter, day dividers. Ported from the prototype's chat; @mentions, /links
 * and Reply / Edit / Delete (views/thread.js) are owner requests.
 */
import { safeEffect } from '../lib/effect.js';
import { ic } from '../lib/icons.js';
import { __, _n, sprintf } from '../lib/i18n.js';
import { esc, DAY } from '../lib/format.js';
import { get, post, del } from '../lib/api.js';
import { isPhone, lockBgScroll, unlockBgScroll, sheetOut, placeInd, toast } from '../lib/ui.js';
import { store, iso, todayISO, parse, size } from './model.js';
import { enhance } from './select.js';
import { msgHTML } from './editors.js';
import { attachComposer } from './composer.js';
import { wireThread } from './thread.js';
import { watchThreadStatus } from '../lib/ext.js';
import { multiline, fit } from '../lib/multiline.js';

const $ = ( id ) => document.getElementById( id );
const MAX_FILES = 6;

let chat = null;
let open = false;
let locked = false;
let pos = { mode: 'right' };
let pending = [];
let search = { active: false, list: [], q: '' };
let searchTimer = null;
let olderDone = false;
let loadingOlder = false;
let composer = null;
let thread = null;

const pref = ( k, v ) => {
	try {
		if ( v === undefined ) {
			return window.localStorage.getItem( k );
		}
		window.localStorage.setItem( k, v );
	} catch ( e ) {}
	return null;
};

/* ---------- placement ---------- */
function apply() {
	chat.classList.remove( 'nw-dock-left', 'nw-dock-right', 'nw-float' );
	document.body.classList.remove( 'nw-docked-left', 'nw-docked-right' );
	$( 'nw-scrim' ).hidden = chat.hidden || ! isPhone();
	if ( pos.mode === 'float' ) {
		chat.classList.add( 'nw-float' );
		const w = 360;
		const h = Math.min( 560, window.innerHeight - 32 );
		chat.style.left = Math.min( Math.max( 8, pos.x || 0 ), window.innerWidth - w - 8 ) + 'px';
		chat.style.top = Math.min( Math.max( 8, pos.y || 0 ), window.innerHeight - h - 8 ) + 'px';
	} else {
		chat.classList.add( 'nw-dock-' + pos.mode );
		chat.style.left = '';
		chat.style.top = '';
		if ( ! chat.hidden && ! isPhone() ) {
			document.body.classList.add( 'nw-docked-' + pos.mode );
		}
	}
	const dock = $( 'nw-chat-dock' );
	dock.innerHTML = ic( pos.mode === 'left' ? 'arrow-right' : 'arrow-left', 16 );
	dock.setAttribute( 'aria-label', pos.mode === 'left' ? __( 'Dock on the right', 'novawork' ) : __( 'Dock on the left', 'novawork' ) );
	dock.title = dock.getAttribute( 'aria-label' );
	dock.hidden = isPhone();
	setTimeout( () => document.querySelectorAll( '.nw-tabs' ).forEach( ( n ) => placeInd( n, true ) ), 320 );
	const should = ! chat.hidden && isPhone();
	if ( should && ! locked ) {
		locked = true;
		lockBgScroll();
	} else if ( ! should && locked ) {
		locked = false;
		unlockBgScroll();
	}
}

function setOpen( value ) {
	open = value;
	window.dispatchEvent( new CustomEvent( 'nw:chat-state', { detail: { open } } ) );
}

export function openChat( searchText ) {
	chat.hidden = false;
	setOpen( true );
	apply();
	if ( typeof searchText === 'string' && searchText ) {
		$( 'nw-chat-tools' ).hidden = false;
		$( 'nw-chat-find' ).setAttribute( 'aria-expanded', 'true' );
		$( 'nw-chat-find' ).classList.add( 'nw-on' );
		$( 'nw-chat-q' ).value = searchText;
		enhance( $( 'nw-chat-tools' ) );
		runSearch();
	}
	paint( true );
	if ( ! isPhone() ) {
		$( 'nw-chat-in' ).focus();
	}
}

export function closeChat() {
	const swiped = !! chat.style.transform;
	const finish = () => {
		chat.style.transform = '';
		chat.style.transition = '';
		chat.hidden = true;
		setOpen( false );
		composer.close();
		apply();
		$( 'nw-chat-btn' ).focus();
	};
	if ( swiped || chat.hidden ) {
		finish();
	} else {
		sheetOut( chat, finish, $( 'nw-scrim' ) );
	}
}

/* ---------- messages ---------- */
function dayLabel( at ) {
	const S = store();
	const k = iso( new Date( at ) );
	if ( k === todayISO() ) {
		return __( 'Today', 'novawork' );
	}
	const y = new Date( Date.now() );
	y.setDate( y.getDate() - 1 );
	if ( k === iso( y ) ) {
		return __( 'Yesterday', 'novawork' );
	}
	return new Date( at ).toLocaleDateString( S.locale || undefined, { weekday: 'short', day: 'numeric', month: 'short' } );
}

function list() {
	if ( search.active ) {
		return search.list;
	}
	return Object.values( store().messages ).sort( ( a, b ) => a.id - b.id );
}

function paint( toBottom ) {
	if ( ! open || ( thread && thread.editing() ) ) {
		return;
	}
	const box = $( 'nw-msgs' );
	const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
	let last = '';
	let html = '';
	const msgs = list();
	msgs.forEach( ( m ) => {
		const k = iso( new Date( m.at ) );
		if ( k !== last ) {
			html += `<div class="nw-dsep">${ esc( dayLabel( m.at ) ) }</div>`;
			last = k;
		}
		html += msgHTML( m, search.q, { acts: ! search.active } );
	} );
	box.innerHTML =
		html ||
		( search.active
			? `<div class="nw-empty">${ ic( 'magnifying-glass' ) }${ esc( __( 'No messages match.', 'novawork' ) ) }</div>`
			: `<div class="nw-empty">${ ic( 'chat-circle-dots' ) }${ esc( __( 'Say hello. Messages here are for the team only.', 'novawork' ) ) }</div>` );
	const count = $( 'nw-chat-count' );
	count.textContent = search.active ? sprintf( /* translators: %d: number of messages. */ _n( '%d message', '%d messages', msgs.length, 'novawork' ), msgs.length ) : '';
	count.hidden = ! search.active;
	if ( search.active ) {
		box.scrollTop = 0;
	} else if ( toBottom || atBottom ) {
		box.scrollTop = box.scrollHeight;
		markRead();
	}
}

/** You have read up to the newest message: clear the unread count and chat notifications. */
function markRead() {
	const S = store();
	const ids = Object.keys( S.messages ).map( Number );
	const last = ids.length ? Math.max( ...ids ) : 0;
	if ( ! last || ( last <= ( S.chat.readId || 0 ) && ! S.chat.unread ) ) {
		return;
	}
	S.chat = Object.assign( {}, S.chat, { readId: last, unread: 0 } );
	Object.values( S.notifications )
		.filter( ( n ) => ( n.type === 'chat' || n.type === 'mention' ) && ! n.read )
		.forEach( ( n ) => S.upsert( 'notifications', Object.assign( {}, n, { read: true } ) ) );
	post( 'chat/read', { lastId: last } ).catch( () => {} );
}

/** Older history, when you scroll to the top. */
async function loadOlder() {
	const S = store();
	if ( loadingOlder || olderDone || search.active ) {
		return;
	}
	const ids = Object.keys( S.messages ).map( Number );
	loadingOlder = true;
	const box = $( 'nw-msgs' );
	const before = box.scrollHeight;
	try {
		// Nothing here yet: the newest page.
		const rows = await get( 'messages', { beforeId: ids.length ? Math.min( ...ids ) : 0, limit: 50 } );
		if ( ! rows.length ) {
			olderDone = true;
		}
		rows.forEach( ( m ) => S.upsert( 'messages', m ) );
		await Promise.resolve();
		paint( false );
		box.scrollTop = box.scrollHeight - before;
	} catch ( e ) {
		// Try again on the next scroll.
	} finally {
		loadingOlder = false;
	}
}

/** Load older history until a message is there (a reply's quote was clicked). */
async function loadUntil( id ) {
	if ( search.active ) {
		$( 'nw-chat-find' ).click();
	}
	for ( let i = 0; i < 40 && ! store().messages[ id ] && ! olderDone; i++ ) {
		if ( loadingOlder ) {
			await new Promise( ( r ) => setTimeout( r, 150 ) );
		} else {
			await loadOlder();
		}
	}
}

/* ---------- search ---------- */
function filterRange() {
	const when = $( 'nw-chat-when' ).value;
	const today = parse( todayISO() );
	const d = ( n ) => iso( new Date( today.getTime() + n * DAY ) );
	switch ( when ) {
		case 'today':
			return { from: d( 0 ) };
		case 'yesterday':
			return { from: d( -1 ), to: d( -1 ) };
		case '7':
			return { from: d( -6 ) };
		case '30':
			return { from: d( -29 ) };
		case 'date': {
			const v = $( 'nw-chat-date' ).value;
			return parse( v ) ? { from: v, to: v } : {};
		}
		default:
			return {};
	}
}

function runSearch() {
	clearTimeout( searchTimer );
	const q = $( 'nw-chat-q' ).value.trim();
	const range = filterRange();
	const active = ! $( 'nw-chat-tools' ).hidden && ( !! q || $( 'nw-chat-when' ).value !== 'all' );
	if ( ! active ) {
		search = { active: false, list: [], q: '' };
		paint( true );
		return;
	}
	searchTimer = setTimeout( async () => {
		try {
			const rows = await get( 'messages', Object.assign( { q, limit: 100 }, range ) );
			search = { active: true, list: rows, q: q.toLowerCase() };
		} catch ( e ) {
			search = { active: true, list: [], q: q.toLowerCase() };
		}
		paint( false );
	}, 200 );
}

/* ---------- attachments ---------- */
function paintPending() {
	$( 'nw-pending' ).innerHTML = pending
		.map(
			( f, i ) =>
				`<span class="nw-fchip${ f.status === 'up' ? ' nw-uploading' : '' }">${ ic( /^image\//.test( f.type ) ? 'image' : 'file' ) }<span class="nw-fn">${ esc( f.name ) }</span><small>${ esc(
					f.status === 'up' ? __( 'Uploading…', 'novawork' ) : size( f.size )
				) }</small><button type="button" data-rm="${ i }" aria-label="${ esc( sprintf( /* translators: %s: file name. */ __( 'Remove %s', 'novawork' ), f.name ) ) }">${ ic( 'x' ) }</button></span>`
		)
		.join( '' );
}

function addFiles( files ) {
	const S = store();
	const max = S.settings.uploadMaxBytes || 0;
	const types = ( S.settings.uploadTypes || [] ).map( ( t ) => t.toLowerCase() );
	[ ...files ].slice( 0, Math.max( 0, MAX_FILES - pending.length ) ).forEach( ( file ) => {
		const ext = ( file.name.split( '.' ).pop() || '' ).toLowerCase();
		if ( types.length && ! types.includes( ext ) ) {
			toast( sprintf( /* translators: %s: file name. */ __( '%s: this type of file can’t be shared here.', 'novawork' ), file.name ), 'x' );
			return;
		}
		if ( max && file.size > max ) {
			toast( sprintf( /* translators: 1: file name, 2: size limit. */ __( '%1$s is too big. Files can be up to %2$s.', 'novawork' ), file.name, size( max ) ), 'x' );
			return;
		}
		const item = { name: file.name, size: file.size, type: file.type, status: 'up', id: 0 };
		const body = new FormData();
		body.append( 'file', file );
		item.done = post( 'attachments', body )
			.then( ( a ) => {
				item.id = a.id;
				item.status = 'ok';
			} )
			.catch( ( e ) => {
				pending = pending.filter( ( x ) => x !== item );
				toast( ( e && e.message ) || __( 'The file did not upload. Try again.', 'novawork' ), 'x' );
			} )
			.finally( paintPending );
		pending.push( item );
	} );
	paintPending();
}

/* ---------- sending ---------- */
async function send() {
	const input = $( 'nw-chat-in' );
	const text = input.value.trim();
	if ( ! text && ! pending.length ) {
		return;
	}
	const sendBtn = $( 'nw-chat-send' );
	sendBtn.disabled = true;
	try {
		await Promise.all( pending.map( ( p ) => p.done ) );
		const files = pending.filter( ( p ) => p.id ).map( ( p ) => p.id );
		if ( ! text && ! files.length ) {
			return;
		}
		const m = await post( 'messages', Object.assign( { text, attachmentIds: files, replyTo: thread.replyTo() }, composer.payload() ) );
		store().upsert( 'messages', m );
		input.value = '';
		fit( input );
		pending = [];
		composer.reset();
		thread.clearReply();
		paintPending();
		paint( true );
	} catch ( e ) {
		toast( ( e && e.status < 500 && e.message ) || __( 'Could not send. Check your connection and try again.', 'novawork' ), 'x' );
	} finally {
		sendBtn.disabled = false;
		input.focus();
	}
}

/* ---------- drag, dock, swipe ---------- */
function wireDrag() {
	const head = $( 'nw-chat-head' );
	const L = $( 'nw-snap-l' );
	const R = $( 'nw-snap-r' );
	let d = null;
	head.addEventListener( 'pointerdown', ( e ) => {
		if ( isPhone() || e.button !== 0 || e.target.closest( 'button' ) ) {
			return;
		}
		const r = chat.getBoundingClientRect();
		d = { x0: e.clientX, y0: e.clientY, dx: e.clientX - r.left, dy: e.clientY - r.top, moved: false };
		head.setPointerCapture( e.pointerId );
	} );
	head.addEventListener( 'pointermove', ( e ) => {
		if ( ! d ) {
			return;
		}
		if ( ! d.moved ) {
			if ( Math.hypot( e.clientX - d.x0, e.clientY - d.y0 ) < 5 ) {
				return;
			}
			d.moved = true;
			const r = chat.getBoundingClientRect();
			pos = { mode: 'float', x: r.left, y: Math.max( 8, r.top + ( r.height > 600 ? 40 : 0 ) ) };
			apply();
			chat.classList.add( 'nw-dragging' );
		}
		pos.x = e.clientX - d.dx;
		pos.y = e.clientY - d.dy;
		chat.style.left = Math.min( Math.max( 8, pos.x ), window.innerWidth - 368 ) + 'px';
		chat.style.top = Math.min( Math.max( 8, pos.y ), window.innerHeight - 120 ) + 'px';
		L.classList.toggle( 'nw-on', e.clientX < 96 );
		R.classList.toggle( 'nw-on', e.clientX > window.innerWidth - 96 );
	} );
	const end = () => {
		if ( ! d ) {
			return;
		}
		const moved = d.moved;
		d = null;
		chat.classList.remove( 'nw-dragging' );
		if ( ! moved ) {
			return;
		}
		if ( L.classList.contains( 'nw-on' ) ) {
			pos = { mode: 'left' };
		} else if ( R.classList.contains( 'nw-on' ) ) {
			pos = { mode: 'right' };
		}
		L.classList.remove( 'nw-on' );
		R.classList.remove( 'nw-on' );
		pref( 'nw.chat', JSON.stringify( pos ) );
		apply();
	};
	head.addEventListener( 'pointerup', end );
	head.addEventListener( 'pointercancel', end );

	// Phone: swipe the sheet down to close it.
	let s = null;
	const down = ( e ) => {
		if ( ! isPhone() || e.target.closest( 'button' ) ) {
			return;
		}
		s = { y0: e.clientY, dy: 0 };
		e.currentTarget.setPointerCapture( e.pointerId );
		chat.style.transition = 'none';
	};
	const move = ( e ) => {
		if ( ! s ) {
			return;
		}
		s.dy = Math.max( 0, e.clientY - s.y0 );
		chat.style.transform = `translateY(${ s.dy }px)`;
	};
	const up = () => {
		if ( ! s ) {
			return;
		}
		const dy = s.dy;
		s = null;
		chat.style.transition = 'transform .25s var(--nw-ease)';
		if ( dy > 110 ) {
			closeChat();
		} else {
			chat.style.transform = '';
		}
	};
	[ $( 'nw-grab' ), head ].forEach( ( el ) => {
		el.addEventListener( 'pointerdown', down );
		el.addEventListener( 'pointermove', move );
		el.addEventListener( 'pointerup', up );
		el.addEventListener( 'pointercancel', up );
	} );
}

/* ---------- mount ---------- */
export function mountChat() {
	chat = $( 'nw-chat' );
	if ( ! chat ) {
		return;
	}
	try {
		pos = JSON.parse( pref( 'nw.chat' ) ) || { mode: 'right' };
	} catch ( e ) {
		pos = { mode: 'right' };
	}
	$( 'nw-chat-close' ).innerHTML = ic( 'x', 18 );
	$( 'nw-chat-find' ).innerHTML = ic( 'magnifying-glass', 17 );
	$( 'nw-chat-q-icon' ).innerHTML = ic( 'magnifying-glass', 15 );
	$( 'nw-chat-send' ).innerHTML = ic( 'paper-plane-right', 16 );
	$( 'nw-attach' ).innerHTML = ic( 'paperclip', 19 );
	enhance( $( 'nw-chat-tools' ) );

	$( 'nw-chat-dock' ).addEventListener( 'click', () => {
		pos = { mode: pos.mode === 'left' ? 'right' : 'left' };
		pref( 'nw.chat', JSON.stringify( pos ) );
		apply();
	} );
	$( 'nw-chat-close' ).addEventListener( 'click', closeChat );
	$( 'nw-scrim' ).addEventListener( 'click', closeChat );
	window.addEventListener( 'resize', apply );
	wireDrag();

	$( 'nw-chat-find' ).addEventListener( 'click', () => {
		const tools = $( 'nw-chat-tools' );
		const show = tools.hidden;
		tools.hidden = ! show;
		$( 'nw-chat-find' ).setAttribute( 'aria-expanded', String( show ) );
		$( 'nw-chat-find' ).classList.toggle( 'nw-on', show );
		if ( show ) {
			enhance( tools );
			$( 'nw-chat-q' ).focus();
		} else {
			$( 'nw-chat-q' ).value = '';
			$( 'nw-chat-when' ).value = 'all';
			$( 'nw-chat-date' ).hidden = true;
			enhance( tools );
		}
		runSearch();
	} );
	$( 'nw-chat-q' ).addEventListener( 'input', runSearch );
	$( 'nw-chat-when' ).addEventListener( 'change', () => {
		const date = $( 'nw-chat-date' );
		date.hidden = $( 'nw-chat-when' ).value !== 'date';
		if ( ! date.hidden && ! date.value ) {
			date.value = todayISO();
		}
		runSearch();
	} );
	$( 'nw-chat-date' ).addEventListener( 'change', runSearch );

	$( 'nw-attach' ).addEventListener( 'click', () => $( 'nw-file-in' ).click() );
	$( 'nw-file-in' ).addEventListener( 'change', ( e ) => {
		addFiles( e.target.files );
		e.target.value = '';
	} );
	$( 'nw-pending' ).addEventListener( 'click', ( e ) => {
		const b = e.target.closest( '[data-rm]' );
		if ( ! b ) {
			return;
		}
		const [ item ] = pending.splice( Number( b.dataset.rm ), 1 );
		if ( item && item.id ) {
			del( 'attachments/' + item.id ).catch( () => {} );
		}
		paintPending();
	} );
	chat.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' && ! composer.isOpen() ) {
			// Escape in the text field drops a reply before it closes the chat.
			if ( e.target.id === 'nw-chat-in' && thread.escape() ) {
				return;
			}
			closeChat();
		}
	} );
	composer = attachComposer( $( 'nw-chat-in' ), { parent: $( 'nw-chat-form' ), id: 'nw-mention-list' } );
	// After the composer, so Enter in its suggestion list picks instead of sending.
	multiline( $( 'nw-chat-in' ), () => $( 'nw-chat-form' ).requestSubmit() );
	thread = wireThread( {
		list: () => $( 'nw-msgs' ),
		input: () => $( 'nw-chat-in' ),
		composer: () => composer,
		bar: () => $( 'nw-chat-reply' ),
		redraw: () => paint( false ),
		find: loadUntil,
	} );
	$( 'nw-msgs' ).addEventListener( 'click', thread.onClick );
	$( 'nw-chat-reply' ).addEventListener( 'click', thread.onClick );
	watchThreadStatus( $( 'nw-chat-status' ), 'chat' );
	$( 'nw-chat-form' ).addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		if ( ! composer.isOpen() ) {
			send();
		}
	} );
	$( 'nw-msgs' ).addEventListener( 'scroll', () => {
		const box = $( 'nw-msgs' );
		if ( box.scrollTop < 40 ) {
			loadOlder();
		}
		if ( box.scrollHeight - box.scrollTop - box.clientHeight < 60 ) {
			markRead();
		}
	} );

	window.addEventListener( 'nw:goto', () => {
		if ( open && isPhone() ) {
			closeChat();
		}
	} );
	window.addEventListener( 'nw:chat', ( e ) => {
		const d = e.detail || {};
		if ( d.open === false ) {
			if ( open ) {
				closeChat();
			}
		} else {
			openChat( d.search );
			if ( d.jump ) {
				// A notification about a message: go to it.
				requestAnimationFrame( () => thread.jump( d.jump ) );
			}
		}
	} );

	// New, edited and deleted messages (from anyone) redraw the open window.
	safeEffect( () => {
		const S = store();
		void Object.values( S.messages )
			.map( ( m ) => m.id + '.' + ( m.editedAt || 0 ) )
			.join();
		void S.members;
		void S.agents;
		if ( open && ! search.active ) {
			paint( false );
		}
	} );
	apply();
}

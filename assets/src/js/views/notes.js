/**
 * The notifications panel (the bell), ported from the prototype's
 * paintNotes(): Today / Earlier sections, an icon per kind, unread dots,
 * Mark all as read. On phones it is a bottom sheet.
 *
 * Owner requests: a small Mine | Workspace filter, each with an unread
 * count. Mine is what is about you (assigned, mentioned, asked to vote,
 * comments on your work, due dates). Workspace is the team: new chat
 * messages (your own chat notifications, unread until read) and the team's
 * activity feed (tasks finished, decisions made…), with older rows loaded
 * as you scroll. Nobody ever sees anyone else's notifications.
 */
import { safeEffect } from '../lib/effect.js';
import { ic, icf } from '../lib/icons.js';
import { __, sprintf, _n } from '../lib/i18n.js';
import { esc, ago } from '../lib/format.js';
import { get, post } from '../lib/api.js';
import { placeInd } from '../lib/ui.js';
import { serverNow } from '../store.js';
import { store, person, cpair, parse, todayISO, days } from './model.js';
import { refTitle } from './rich.js';
import { mountAlerts, alertState, turnOn, turnOff } from './alerts.js';
import { noteKind } from '../lib/ext.js';

const $ = ( id ) => document.getElementById( id );

let open = false;
let tab = 'mine';
let olderDone = false;
let loadingOlder = false;

try {
	tab = window.localStorage.getItem( 'nw.notesTab' ) === 'team' ? 'team' : 'mine';
} catch ( e ) {}

/* Icon and colour per kind (the prototype's NTYPE, plus the owner's later kinds). */
const KIND = {
	due: [ 'calendar-blank', 'amber' ],
	late: [ 'warning-circle', 'red' ],
	decision: [ 'scales', 'blue' ],
	vote: [ 'scales', 'indigo' ],
	chat: [ 'chat-circle-dots', 'teal' ],
	mention: [ 'chat-circle-dots', 'grape' ],
	comment: [ 'chat-circle', 'emerald' ],
	link: [ 'map-trifold', 'sky' ],
	assign: [ 'user-plus', 'grape' ],
	task_added: [ 'plus', 'slate' ],
	task_done: [ 'check', 'emerald' ],
	project_added: [ 'folder-simple', 'blue' ],
	project_done: [ 'flag-banner', 'emerald' ],
	decision_opened: [ 'scales', 'blue' ],
	decision_made: [ 'scales', 'emerald' ],
	vote_opened: [ 'scales', 'indigo' ],
	commented: [ 'chat-circle', 'teal' ],
	member_joined: [ 'user-plus', 'emerald' ],
};

const b = ( s ) => `<b>${ esc( s ) }</b>`;

function who( id, agent ) {
	const S = store();
	if ( agent ) {
		const a = S.agents.find( ( x ) => x.key === agent );
		return a ? a.name : __( 'Someone', 'novawork' );
	}
	const p = person( id );
	return p ? ( id === S.me ? __( 'You', 'novawork' ) : p.name ) : __( 'Someone', 'novawork' );
}

/** An object's current title, or the one saved with the row. */
const titleOf = ( type, id, saved ) => refTitle( type, id ) || saved || '';

const plural = ( n ) => sprintf( /* translators: %d: number of days. */ _n( '%d day', '%d days', n, 'novawork' ), n );

/** ": what was said", or what was shared when a message is files only. */
function said( d ) {
	if ( d.excerpt ) {
		return ': ' + esc( d.excerpt );
	}
	const count = Number( d.count ) || ( d.files || [] ).length;
	if ( ! count ) {
		return '';
	}
	return (
		' ' +
		( count === 1 && d.files && d.files[ 0 ]
			? sprintf( /* translators: %s: file name. */ esc( __( 'shared %s', 'novawork' ) ), b( d.files[ 0 ] ) )
			: esc( sprintf( /* translators: %d: number of files. */ _n( 'shared %d file', 'shared %d files', count, 'novawork' ), count ) ) )
	);
}

/** [kind, html] for one of your notifications. */
function noteText( n ) {
	const d = n.data || {};
	const by = b( who( n.actor, n.agent ) );
	const quote = said( d );
	switch ( n.type ) {
		case 'assign':
			if ( d.subtask ) {
				return [ 'assign', sprintf( /* translators: 1: a person, 2: subtask, 3: its task. */ esc( __( '%1$s gave you %2$s on %3$s', 'novawork' ) ), by, b( d.subtask ), b( titleOf( 'task', n.objectId, d.title ) ) ) ];
			}
			return [ 'assign', sprintf( /* translators: 1: a person, 2: task. */ esc( __( '%1$s assigned you %2$s', 'novawork' ) ), by, b( titleOf( 'task', n.objectId, d.title ) ) ) ];
		case 'comment':
			return [ 'comment', sprintf( /* translators: 1: a person, 2: task or decision. */ esc( __( '%1$s commented on %2$s', 'novawork' ) ), by, b( titleOf( n.objectType, n.objectId, d.title ) ) ) + quote ];
		case 'mention':
			return n.objectType === 'message'
				? [ 'mention', sprintf( /* translators: %s: a person. */ esc( __( '%s mentioned you in chat', 'novawork' ) ), by ) + quote ]
				: [ 'mention', sprintf( /* translators: 1: a person, 2: task or decision. */ esc( __( '%1$s mentioned you on %2$s', 'novawork' ) ), by, b( titleOf( n.objectType, n.objectId, d.title ) ) ) + quote ];
		case 'chat':
			return [ 'chat', d.excerpt ? by + quote : by + ( quote || ' ' + esc( __( 'sent a message', 'novawork' ) ) ) ];
		case 'link':
			return [ 'link', sprintf( /* translators: 1: a person, 2: task or decision. */ esc( __( '%1$s linked %2$s', 'novawork' ) ), by, b( titleOf( n.objectType, n.objectId, d.title ) ) ) + quote ];
		case 'vote':
			return [ 'vote', sprintf( /* translators: 1: a person, 2: decision. */ esc( __( '%1$s asked you to vote on %2$s', 'novawork' ) ), by, b( titleOf( 'decision', n.objectId, d.question ) ) ) ];
		case 'decision': {
			const q = b( titleOf( 'decision', n.objectId, d.question ) );
			const left = days( d.decideBy );
			const html =
				left === null || left > 0
					? sprintf( /* translators: 1: decision, 2: "3 days". */ esc( __( 'Decide %1$s in %2$s', 'novawork' ) ), q, esc( plural( Math.max( 1, left || 1 ) ) ) )
					: left === 0
					? sprintf( /* translators: %s: decision. */ esc( __( 'Decide %s today', 'novawork' ) ), q )
					: sprintf( /* translators: %s: decision. */ esc( __( 'Decide %s (overdue)', 'novawork' ) ), q );
			return [ 'decision', html ];
		}
		case 'due': {
			const t = b( titleOf( 'task', n.objectId, d.title ) );
			const left = days( d.due );
			if ( left !== null && left < 0 ) {
				return [ 'late', sprintf( /* translators: 1: task, 2: "3 days". */ esc( __( '%1$s is %2$s late', 'novawork' ) ), t, esc( plural( -left ) ) ) ];
			}
			return [
				'due',
				left === 0 || left === null
					? sprintf( /* translators: %s: task. */ esc( __( '%s is due today', 'novawork' ) ), t )
					: sprintf( /* translators: 1: task, 2: "3 days". */ esc( __( '%1$s is due in %2$s', 'novawork' ) ), t, esc( plural( left ) ) ),
			];
		}
		default: {
			// A kind an add-on writes and words (lib/ext.js addNoteKind, 1.3.0).
			const own = noteKind( n.type );
			if ( own ) {
				try {
					return [ n.type, String( own.text( n, { b, esc, who: () => who( n.actor, n.agent ) } ) ) ];
				} catch ( e ) {
					// eslint-disable-next-line no-console
					console.error( 'NovaWork:', e );
				}
			}
			return [ n.type, by + quote ];
		}
	}
}

/** [kind, html] for one row of the team's activity. */
function activityText( a ) {
	const d = a.data || {};
	const by = b( who( a.actor, a.agent ) );
	const it = b( titleOf( a.objectType, a.objectId, d.title ) );
	const f = ( s ) => sprintf( esc( s ), by, it );
	switch ( a.verb ) {
		case 'task_added':
			return f( /* translators: 1: a person, 2: task. */ __( '%1$s added %2$s', 'novawork' ) );
		case 'task_done':
			return f( /* translators: 1: a person, 2: task. */ __( '%1$s finished %2$s', 'novawork' ) );
		case 'project_added':
			return f( /* translators: 1: a person, 2: project. */ __( '%1$s started the project %2$s', 'novawork' ) );
		case 'project_done':
			return f( /* translators: 1: a person, 2: project. */ __( '%1$s finished the project %2$s', 'novawork' ) );
		case 'decision_opened':
			return f( /* translators: 1: a person, 2: decision. */ __( '%1$s opened a decision: %2$s', 'novawork' ) );
		case 'decision_made':
			return f( /* translators: 1: a person, 2: decision. */ __( '%1$s decided %2$s', 'novawork' ) ) + ( d.answer ? ': ' + esc( d.answer ) : '' );
		case 'vote_opened':
			return f( /* translators: 1: a person, 2: decision. */ __( '%1$s asked the team to vote on %2$s', 'novawork' ) );
		case 'commented':
			return f( /* translators: 1: a person, 2: task or decision. */ __( '%1$s commented on %2$s', 'novawork' ) );
		case 'member_joined':
			return sprintf( /* translators: %s: a person. */ esc( __( '%s joined the workspace', 'novawork' ) ), b( d.title || who( a.actor, a.agent ) ) );
		default:
			return by;
	}
}

/** The bell's last row: desktop alerts on this device (owner request, 2026-11-22). */
function alertsRow() {
	const state = alertState();
	if ( state === 'unavailable' ) {
		return '';
	}
	const [ text, act, label ] = {
		off: [ __( 'Get desktop alerts on this device while the workspace is open in the background.', 'novawork' ), 'alerts-on', __( 'Turn on', 'novawork' ) ],
		on: [ __( 'Desktop alerts are on for this device.', 'novawork' ), 'alerts-off', __( 'Turn off', 'novawork' ) ],
		blocked: [ __( 'Alerts are blocked for this site. Allow notifications in your browser’s site settings to turn them on.', 'novawork' ), '', '' ],
	}[ state ];
	return `<div class="nw-nalerts nw-alerts-${ state }">${ state === 'on' ? icf( 'bell-ringing', 16 ) : ic( 'bell-ringing', 16 ) }<span>${ esc( text ) }</span>${
		act ? `<button class="nw-btn nw-sm nw-ghost" type="button" data-n="${ act }">${ esc( label ) }</button>` : ''
	}</div>`;
}

function itemHTML( id, kind, html, at, unread, now ) {
	const own = ! KIND[ kind ] && noteKind( kind );
	const [ icon, color ] = KIND[ kind ] || ( own ? [ own.icon || 'bell', own.color || 'slate' ] : [ 'bell', 'slate' ] );
	return `<button class="nw-nitem${ unread ? ' nw-unread' : '' }" type="button" data-nid="${ esc( String( id ) ) }"><span class="nw-nic" style="${ cpair( color ) }">${ ic( icon ) }</span><span class="nw-ntext"><span class="nw-nbody">${ html }</span><time>${ esc(
		ago( at, now, store().locale )
	) }</time></span><i class="nw-ndot"></i></button>`;
}

function sections( rows ) {
	const today0 = parse( todayISO() ).getTime();
	const t = rows.filter( ( r ) => r.at >= today0 );
	const e = rows.filter( ( r ) => r.at < today0 );
	return (
		( t.length ? `<div class="nw-nsec">${ esc( __( 'Today', 'novawork' ) ) }</div>` + t.map( ( r ) => r.html ).join( '' ) : '' ) +
		( e.length ? `<div class="nw-nsec">${ esc( __( 'Earlier', 'novawork' ) ) }</div>` + e.map( ( r ) => r.html ).join( '' ) : '' )
	);
}

const newest = ( x, y ) => y.at - x.at || String( y.key ).localeCompare( String( x.key ) );
/* Chat messages go under Workspace; everything else about you under Mine. */
const isTeamNote = ( n ) => n.type === 'chat';
const mine = () => Object.values( store().notifications ).filter( ( n ) => ! isTeamNote( n ) ).sort( ( x, y ) => y.at - x.at || y.id - x.id );
const chatNotes = () => Object.values( store().notifications ).filter( isTeamNote );
const team = () =>
	[ ...chatNotes().map( ( n ) => ( { key: 'n' + n.id, at: n.at, note: n } ) ), ...Object.values( store().activity || {} ).map( ( a ) => ( { key: 'a' + a.id, at: a.at, act: a } ) ) ].sort( newest );

const unreadIn = ( list ) => list.filter( ( n ) => ! n.read ).length;
export const unreadCount = () => Object.values( store().notifications ).filter( ( n ) => ! n.read ).length;

function paint() {
	const body = $( 'nw-notes-body' );
	if ( ! open || ! body ) {
		return;
	}
	const now = serverNow();
	const list = body.querySelector( '.nw-nlist' );
	const keep = list && body.dataset.tab === tab ? list.scrollTop : 0;
	const counts = { mine: unreadIn( mine() ), team: unreadIn( chatNotes() ) };
	const unread = counts[ tab ];
	let rows;
	let empty;
	if ( tab === 'mine' ) {
		rows = mine().map( ( n ) => {
			const [ kind, html ] = noteText( n );
			return { at: n.at, html: itemHTML( n.id, kind, html, n.at, ! n.read, now ) };
		} );
		empty = `<div class="nw-empty" style="margin:6px">${ ic( 'bell' ) }${ esc( __( 'You’re all caught up.', 'novawork' ) ) }</div>`;
	} else {
		rows = team().map( ( r ) => {
			if ( r.note ) {
				const [ kind, html ] = noteText( r.note );
				return { at: r.at, html: itemHTML( r.note.id, kind, html, r.at, ! r.note.read, now ) };
			}
			return { at: r.at, html: itemHTML( 'a' + r.act.id, r.act.verb, activityText( r.act ), r.at, false, now ) };
		} );
		empty = `<div class="nw-empty" style="margin:6px">${ ic( 'users-three' ) }${ esc( __( 'Nothing has happened yet. What the team does shows up here.', 'novawork' ) ) }</div>`;
	}
	const badge = ( n ) =>
		n ? `<span class="nw-tcount" aria-label="${ esc( sprintf( /* translators: %d: number of unread notifications. */ _n( '%d unread', '%d unread', n, 'novawork' ), n ) ) }">${ n > 9 ? '9+' : n }</span>` : '';
	const seg = `<div class="nw-tabs nw-seg nw-sm nw-notes-tabs" role="tablist" aria-label="${ esc( __( 'Show', 'novawork' ) ) }"><span class="nw-ind" aria-hidden="true"></span>${ [
		[ 'mine', __( 'Mine', 'novawork' ) ],
		[ 'team', __( 'Workspace', 'novawork' ) ],
	]
		.map( ( [ k, l ] ) => `<button class="nw-tab${ k === tab ? ' nw-on' : '' }" type="button" role="tab" aria-selected="${ k === tab }" data-ntab="${ k }">${ esc( l ) }${ badge( counts[ k ] ) }</button>` )
		.join( '' ) }</div>`;
	body.dataset.tab = tab;
	body.innerHTML =
		`<div class="nw-sgrab" data-grab aria-hidden="true"></div><div class="nw-nhead"><h3>${ esc( __( 'Notifications', 'novawork' ) ) }</h3>${
			unread ? `<button class="nw-btn nw-sm nw-ghost" type="button" data-n="all">${ esc( __( 'Mark all as read', 'novawork' ) ) }</button>` : ''
		}</div><div class="nw-ntabs">${ seg }</div>` +
		`<div class="nw-nlist" role="tabpanel">${ rows.length ? sections( rows ) : empty }</div>` +
		alertsRow();
	const nav = body.querySelector( '.nw-notes-tabs' );
	placeInd( nav, true );
	const next = body.querySelector( '.nw-nlist' );
	next.scrollTop = keep;
	next.addEventListener( 'scroll', onScroll, { passive: true } );
}

function onScroll( e ) {
	const el = e.currentTarget;
	if ( tab === 'team' && el.scrollHeight - el.scrollTop - el.clientHeight < 80 ) {
		loadOlder();
	}
}

/** Older team activity, when you scroll to the end. */
async function loadOlder() {
	const S = store();
	const ids = Object.keys( S.activity || {} ).map( Number );
	if ( loadingOlder || olderDone || ! ids.length ) {
		return;
	}
	loadingOlder = true;
	try {
		const rows = await get( 'activity', { beforeId: Math.min( ...ids ), limit: 50 } );
		if ( rows.length < 50 ) {
			olderDone = true;
		}
		rows.forEach( ( a ) => S.upsert( 'activity', a ) );
	} catch ( e ) {
		// Try again on the next scroll.
	} finally {
		loadingOlder = false;
	}
}

function markRead( ids ) {
	const S = store();
	const list = ids ? ids.map( ( id ) => S.notifications[ id ] ).filter( Boolean ) : Object.values( S.notifications );
	const changed = list.filter( ( n ) => ! n.read );
	if ( ! changed.length ) {
		return;
	}
	changed.forEach( ( n ) => S.upsert( 'notifications', Object.assign( {}, n, { read: true } ) ) );
	post( 'notifications/read', ids ? { ids: changed.map( ( n ) => n.id ) } : { all: true } ).catch( () => {} );
}

/** Go to what a notification or activity row is about. */
function openTarget( type, id ) {
	window.dispatchEvent( new CustomEvent( 'nw:notes', { detail: { open: false } } ) );
	if ( type === 'message' ) {
		window.dispatchEvent( new CustomEvent( 'nw:chat', { detail: { open: true, jump: id } } ) );
		return;
	}
	window.dispatchEvent( new CustomEvent( 'nw:goto', { detail: { kind: type, id } } ) );
}

function onClick( e ) {
	const S = store();
	if ( e.target.closest( '[data-n="all"]' ) ) {
		// Mark all as read: the open tab's notifications.
		markRead( ( tab === 'mine' ? mine() : chatNotes() ).map( ( n ) => n.id ) );
		return;
	}
	const al = e.target.closest( '[data-n="alerts-on"], [data-n="alerts-off"]' );
	if ( al ) {
		( al.dataset.n === 'alerts-on' ? turnOn() : Promise.resolve( turnOff() ) ).then( paint );
		return;
	}
	const t = e.target.closest( '[data-ntab]' );
	if ( t ) {
		tab = t.dataset.ntab;
		try {
			window.localStorage.setItem( 'nw.notesTab', tab );
		} catch ( err ) {}
		paint();
		return;
	}
	const item = e.target.closest( '[data-nid]' );
	if ( ! item ) {
		return;
	}
	const nid = item.dataset.nid;
	if ( nid.startsWith( 'a' ) ) {
		const a = ( S.activity || {} )[ Number( nid.slice( 1 ) ) ];
		if ( a && a.objectType !== 'member' ) {
			openTarget( a.objectType, a.objectId );
		}
		return;
	}
	const n = S.notifications[ Number( nid ) ];
	if ( ! n ) {
		return;
	}
	openNote( n );
}

/**
 * Open what a notification is about and mark it read: a row in the bell,
 * or a click on its desktop alert.
 *
 * @param {Object} n Notification.
 */
function openNote( n ) {
	markRead( [ n.id ] );
	const own = noteKind( n.type );
	if ( own && typeof own.open === 'function' ) {
		own.open( n );
		return;
	}
	openTarget( n.objectType, n.objectId );
}

/** The shell opens and closes the panel; this draws what is in it. */
export function mountNotes() {
	const body = $( 'nw-notes-body' );
	if ( ! body ) {
		return;
	}
	body.addEventListener( 'click', onClick );
	mountAlerts( { text: noteText, open: openNote } );
	window.addEventListener( 'nw:notes-state', ( e ) => {
		open = !! ( e.detail && e.detail.open );
		if ( open ) {
			paint();
			const first = body.querySelector( '.nw-nitem, [data-ntab].nw-on' );
			if ( first && ! window.matchMedia( '(hover: none)' ).matches ) {
				first.focus( { preventScroll: true } );
			}
		}
	} );
	// Redraw while open when notifications, activity or members change.
	safeEffect( () => {
		const S = store();
		void Object.values( S.notifications )
			.map( ( n ) => n.id + ( n.read ? 'r' : 'u' ) )
			.join();
		void Object.keys( S.activity || {} ).length;
		void S.members;
		if ( open ) {
			paint();
		}
	} );
}

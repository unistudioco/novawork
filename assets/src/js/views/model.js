/**
 * Data lookups and the small HTML fragments every view shares, ported from
 * the prototype (avatar, chips, due dates, priority, the play/pause button).
 *
 * Views build HTML strings like the prototype did. Every piece of text that
 * came from a person goes through esc(); icons are bundled SVG.
 */
import { ic, icf } from '../lib/icons.js';
import { __, sprintf } from '../lib/i18n.js';
import { esc, initials, DAY } from '../lib/format.js';
import { serverNow } from '../store.js';

let S = null;

/** Called once with the Alpine store. */
export function bind( store ) {
	S = store;
}
export const store = () => S;

export const COLORS = [ 'blue', 'indigo', 'grape', 'violet', 'pink', 'rose', 'salmon', 'red', 'peach', 'amber', 'gold', 'sand', 'lime', 'mint', 'emerald', 'aqua', 'teal', 'sky', 'slate' ];

/** The Now limit per person (a setting, 3 by default). */
export const nowLimit = () => Math.max( 1, Number( S && S.settings.nowLimit ) || 3 );

export const STATUSES = () => [
	{
		key: 'now',
		label: __( 'Now', 'novawork' ),
		hint: nowLimit() === 3 ? __( 'Three at most, per person', 'novawork' ) : sprintf( /* translators: %d: tasks per person. */ __( '%d at most, per person', 'novawork' ), nowLimit() ),
	},
	{ key: 'next', label: __( 'Next', 'novawork' ), hint: __( 'Ready to start', 'novawork' ) },
	{ key: 'later', label: __( 'Later', 'novawork' ), hint: __( 'Parked on purpose', 'novawork' ) },
	{ key: 'done', label: __( 'Done', 'novawork' ), hint: __( 'Woohoo, well done!', 'novawork' ) },
];

export const PRIORITIES = () => [
	{ key: 'urgent', label: __( 'Urgent', 'novawork' ) },
	{ key: 'high', label: __( 'High', 'novawork' ) },
	{ key: 'medium', label: __( 'Medium', 'novawork' ) },
	{ key: 'low', label: __( 'Low', 'novawork' ) },
	{ key: '', label: __( 'No priority', 'novawork' ) },
];

/* ---------- lookups ---------- */
export const byOrder = ( a, b ) => ( a.order || 0 ) - ( b.order || 0 );
export const me = () => S.me;
export const people = () => S.members.slice();
export const person = ( id ) => S.members.find( ( m ) => m.id === Number( id ) ) || null;
export const projects = () => Object.values( S.projects ).sort( byOrder );
export const project = ( id ) => S.projects[ id ] || null;
export const tasks = () => Object.values( S.tasks );
export const task = ( id ) => S.tasks[ id ] || null;
export const decisions = () => Object.values( S.decisions ).sort( byOrder );
export const assignees = ( t ) => ( Array.isArray( t.assignees ) ? t.assignees : [] );
export const has = ( t, id ) => assignees( t ).includes( Number( id ) );
/** A task's subtasks in checklist order (owner request, 2026-11-22). */
export const subtasksOf = ( t ) => ( t && Array.isArray( t.subtasks ) ? t.subtasks.slice().sort( byOrder ) : [] );
export const commentsOf = ( kind, id ) =>
	Object.values( S.comments )
		.filter( ( c ) => c.objectType === kind && c.objectId === Number( id ) )
		.sort( ( a, b ) => a.id - b.id );
export const runOf = ( uid ) => Object.values( S.timeEntries ).find( ( e ) => e.running && e.by === Number( uid ) ) || null;
export const myRun = () => runOf( S.me );

/** Milliseconds tracked by a member (0 = everyone) between two times; a running timer counts up to now. */
export function tracked( uid, from, to ) {
	const now = serverNow();
	to = to || now;
	return Object.values( S.timeEntries )
		.filter( ( e ) => ! uid || e.by === Number( uid ) )
		.reduce( ( sum, e ) => {
			const end = e.end || now;
			return end > from && e.start < to ? sum + Math.min( end, to ) - Math.max( e.start, from ) : sum;
		}, 0 );
}

export function status( uid ) {
	if ( runOf( uid ) ) {
		return 'tracking';
	}
	const m = person( uid );
	return Number( uid ) === S.me || ( m && m.online ) ? 'online' : 'away';
}

/* ---------- dates (calendar days in the viewer's time zone) ---------- */
export const iso = ( d ) => d.getFullYear() + '-' + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-' + String( d.getDate() ).padStart( 2, '0' );
export const todayISO = () => iso( new Date( serverNow() ) );
export function parse( s ) {
	if ( ! s || ! /^\d{4}-\d{2}-\d{2}$/.test( s ) ) {
		return null;
	}
	const [ y, m, d ] = s.split( '-' ).map( Number );
	return new Date( y, m - 1, d );
}
export function days( s ) {
	const d = parse( s );
	return d ? Math.round( ( d - parse( todayISO() ) ) / DAY ) : null;
}
export function fmt( s, year ) {
	const d = parse( s );
	if ( ! d ) {
		return '';
	}
	const o = { day: 'numeric', month: 'short' };
	if ( year || d.getFullYear() !== new Date().getFullYear() ) {
		o.year = 'numeric';
	}
	return d.toLocaleDateString( S.locale || undefined, o );
}

/* ---------- fragments ---------- */
const colorKey = ( k ) => ( COLORS.includes( k ) ? k : 'slate' );
export const col = ( k ) => `var(--nw-c-${ colorKey( k ) })`;
export const cpair = ( k ) => `--nw-c:var(--nw-c-${ colorKey( k ) });--nw-ci:var(--nw-ci-${ colorKey( k ) })`;

function photo( m ) {
	return m && m.avatar ? `<img src="${ esc( m.avatar ) }" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">` : '';
}

/** A member's avatar, or the "Unassigned" mark. */
export function avatar( id, lg ) {
	const p = person( id );
	if ( ! p ) {
		return `<span class="nw-avatar nw-none${ lg ? ' nw-lg' : '' }" title="${ esc( __( 'Unassigned', 'novawork' ) ) }">${ icf( 'prohibit-inset' ) }</span>`;
	}
	return `<span class="nw-avatar${ lg ? ' nw-lg' : '' }" title="${ esc( p.name ) }" style="${ cpair( p.color ) }">${ esc( initials( p.name ) ) }${ photo( p ) }</span>`;
}

export function avatars( t ) {
	const a = assignees( t ).filter( person );
	return a.length ? `<span class="nw-avs">${ a.map( ( id ) => avatar( id ) ).join( '' ) }</span>` : avatar( 0 );
}

export function presAvatar( uid, lg ) {
	return `<span class="nw-pres nw-is-${ status( uid ) }">${ avatar( uid, lg ) }<i class="nw-st-dot"></i></span>`;
}

export function projChip( id ) {
	const p = project( id );
	return p ? `<span class="nw-chip"><span class="nw-dot" style="--nw-c:${ col( p.color ) }"></span>${ esc( p.name ) }</span>` : '';
}

/** "2/5" with a checklist icon on a card; nothing when a task has no subtasks. */
export function subChip( t ) {
	const list = subtasksOf( t );
	if ( ! list.length ) {
		return '';
	}
	const done = list.filter( ( x ) => x.done ).length;
	const label = esc( sprintf( /* translators: 1: subtasks done, 2: all subtasks. */ __( '%1$d of %2$d subtasks done', 'novawork' ), done, list.length ) );
	return `<span class="nw-subtask-n${ done === list.length ? ' nw-all' : '' }" role="img" title="${ label }" aria-label="${ label }">${ ic( 'list-checks' ) }<span aria-hidden="true">${ done }/${ list.length }</span></span>`;
}

export function dueHTML( s, st ) {
	if ( ! s ) {
		return '';
	}
	const n = days( s );
	let cls = 'nw-due';
	let txt = fmt( s );
	if ( st !== 'done' && n !== null ) {
		if ( n < 0 ) {
			cls += ' nw-late';
			txt += ' · ' + sprintf( /* translators: %d: days late. */ __( '%dd late', 'novawork' ), -n );
		} else if ( n === 0 ) {
			cls += ' nw-soon';
			txt += ' · ' + __( 'today', 'novawork' );
		} else if ( n <= 7 ) {
			cls += ' nw-soon';
			txt += ' · ' + sprintf( /* translators: %d: days left. */ __( '%dd', 'novawork' ), n );
		}
	}
	return `<span class="${ cls }">${ ic( 'calendar-blank' ) }${ esc( txt ) }</span>`;
}

export function priSvg( k ) {
	if ( k === 'urgent' ) {
		return '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><rect x="1" y="1" width="14" height="14" rx="4" fill="var(--nw-p-urgent)"/><rect x="7.1" y="3.8" width="1.8" height="5.4" rx=".9" fill="#fff"/><circle cx="8" cy="11.5" r="1.1" fill="#fff"/></svg>';
	}
	const n = { high: 3, medium: 2, low: 1 }[ k ] || 0;
	const c = { high: 'var(--nw-p-high)', medium: 'var(--nw-p-med)', low: 'var(--nw-p-low)' }[ k ];
	return `<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">${ [ 0, 1, 2 ]
		.map( ( i ) => {
			const h = 4 + i * 3.5;
			return `<rect x="${ 1.5 + i * 4.75 }" y="${ 14 - h }" width="3.25" height="${ h }" rx="1" fill="${ i < n ? c : 'var(--nw-line-strong)' }"/>`;
		} )
		.join( '' ) }</svg>`;
}

export function prio( k ) {
	if ( ! k ) {
		return '';
	}
	const p = PRIORITIES().find( ( x ) => x.key === k );
	const label = esc( sprintf( /* translators: %s: priority, e.g. "High". */ __( '%s priority', 'novawork' ), p ? p.label : k ) );
	return `<span class="nw-prio" role="img" aria-label="${ label }" title="${ label }">${ priSvg( k ) }</span>`;
}

/** The round play/pause button on a task. */
export function tbtn( t ) {
	const r = myRun();
	const run = r && r.taskId === t.id;
	const label = esc( sprintf( run ? /* translators: %s: task title. */ __( 'Pause %s', 'novawork' ) : /* translators: %s: task title. */ __( 'Track time on %s', 'novawork' ), t.title ) );
	return `<button class="nw-tbtn${ run ? ' nw-run' : '' }" type="button" data-act="track" data-id="${ t.id }" aria-label="${ label }">${ run ? icf( 'pause' ) : icf( 'play' ) }</button>`;
}

export const checkBtn = ( t ) =>
	`<button class="nw-check" type="button" data-act="done" data-id="${ t.id }" aria-label="${ esc( sprintf( /* translators: %s: task title. */ __( 'Mark %s done', 'novawork' ), t.title ) ) }">${ ic( 'check' ) }</button>`;

/**
 * Whether you may record (or reopen) a decision: administrators always;
 * otherwise the person named in "Who decides", or anyone when no one is
 * named (or when the decide_rule setting is "anyone"). The server enforces
 * the same rule.
 */
export function canDecide( d ) {
	return !! ( S.canManage || S.settings.decideRule === 'anyone' || ! d.ownerId || d.ownerId === S.me );
}

/**
 * A decision's options with their votes (owner decision: votes advise the
 * person who decides). Each row shows its share as a soft fill, the voters'
 * avatars and the count; yours is highlighted. Buttons when you can vote.
 *
 * @param {Object} d      Decision.
 * @param {string} act    data-act for the rows ("vote" or "pick").
 * @param {boolean} live  Whether the rows are clickable.
 */
export function votesHTML( d, act, live ) {
	const votes = d.votes || {};
	const counts = {};
	Object.values( votes ).forEach( ( k ) => {
		counts[ k ] = ( counts[ k ] || 0 ) + 1;
	} );
	const total = Object.keys( votes ).length;
	const mine = votes[ S.me ];
	const rows = ( d.options || [] )
		.map( ( o ) => {
			const n = counts[ o.key ] || 0;
			const pct = total ? Math.round( ( n / total ) * 100 ) : 0;
			const who = Object.keys( votes ).filter( ( uid ) => votes[ uid ] === o.key );
			const label = esc( sprintf( /* translators: 1: option, 2: number of votes. */ __( '%1$s, %2$d votes', 'novawork' ), o.label, n ) );
			return `<button class="nw-vote${ mine === o.key ? ' nw-on' : '' }" type="button" data-act="${ act }" data-id="${ d.id }" data-opt="${ esc( o.key ) }" aria-pressed="${ mine === o.key }" aria-label="${ label }"${ live ? '' : ' disabled' }><span class="nw-vote-fill" style="width:${ pct }%"></span><span class="nw-vote-lbl">${ esc( o.label ) }</span>${
				who.length ? `<span class="nw-avs">${ who.map( ( uid ) => avatar( uid ) ).join( '' ) }</span>` : ''
			}<span class="nw-vote-n">${ n }</span></button>`;
		} )
		.join( '' );
	const voted = sprintf( /* translators: 1: members who voted, 2: all members. */ __( '%1$d of %2$d voted', 'novawork' ), total, S.members.length );
	return `<div class="nw-votes">${ rows }<span class="nw-votes-meta">${ esc( voted ) }</span></div>`;
}

/** A running clock; the one-second ticker fills it in. */
export const since = ( start ) => `<span data-since="${ Number( start ) }"></span>`;

export function size( n ) {
	return n > 1048576 ? ( n / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( n / 1024 ) ) + ' KB';
}

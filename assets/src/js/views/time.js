/**
 * Time: where the hours went. Today / Week / Month / Year, for you, someone,
 * or everyone. Ported from the prototype's viewTime() / paintTime().
 *
 * The app keeps 8 days of entries; Month and Year fetch their range once
 * (GET /time-entries) and merge the live entries on top, so a running timer
 * and today's sessions are always current.
 */
import Alpine from 'alpinejs';
import { ic } from '../lib/icons.js';
import { __, _n, sprintf } from '../lib/i18n.js';
import { esc, dur, dayStart, MIN, HOUR, DAY } from '../lib/format.js';
import { get } from '../lib/api.js';
import { placeInd } from '../lib/ui.js';
import { serverNow } from '../store.js';
import { store, me, people, person, task, project, col, avatar, presAvatar, iso, todayISO, fmt } from './model.js';
import { weekStart } from './focus.js';

const pref = ( k, v ) => {
	try {
		if ( v === undefined ) {
			return window.localStorage.getItem( k );
		}
		window.localStorage.setItem( k, v );
	} catch ( e ) {}
	return null;
};

const RANGES = () => [
	{ key: 'today', label: __( 'Today', 'novawork' ) },
	{ key: 'week', label: __( 'Week', 'novawork' ) },
	{ key: 'month', label: __( 'Month', 'novawork' ) },
	{ key: 'year', label: __( 'Year', 'novawork' ) },
];

// Fetched history, keyed by range start: { status: 'loading'|'ready', entries }.
const history = Alpine.reactive( {} );
let refresh = () => {};

function rangeStart( range, now ) {
	const d = new Date( dayStart( now ) );
	if ( range === 'week' ) {
		return weekStart( now );
	}
	if ( range === 'month' ) {
		d.setDate( 1 );
	} else if ( range === 'year' ) {
		d.setMonth( 0, 1 );
	}
	return d.getTime();
}

/** Entries for the range: fetched history (Month, Year) plus everything live. */
function entriesFrom( from, range ) {
	const live = Object.values( store().timeEntries );
	if ( range === 'today' || range === 'week' ) {
		return { loading: false, list: live };
	}
	const key = String( from );
	const cached = history[ key ];
	if ( ! cached ) {
		history[ key ] = { status: 'loading', entries: [] };
		get( 'time-entries', { from, to: serverNow() + HOUR } )
			.then( ( rows ) => {
				history[ key ] = { status: 'ready', entries: rows || [] };
				refresh();
			} )
			.catch( () => {
				delete history[ key ];
			} );
		return { loading: true, list: live };
	}
	const byId = new Map( cached.entries.map( ( e ) => [ e.id, e ] ) );
	live.forEach( ( e ) => byId.set( e.id, e ) );
	return { loading: cached.status === 'loading', list: [ ...byId.values() ] };
}

function niceMax( v ) {
	const steps = [ 15, 30, 60, 120, 180, 240, 360, 480, 600, 720, 960, 1200, 1800, 2400, 3600, 4800, 6000, 9000, 12000 ];
	const m = v / MIN;
	return ( steps.find( ( s ) => s >= m ) || Math.ceil( m / 6000 ) * 6000 ) * MIN;
}

export function viewTime() {
	const S = store();
	const range = pref( 'nw.range' ) || 'week';
	const tWho = pref( 'nw.tWho' ) || 'me';
	const mePerson = person( me() );
	const who =
		`<option value="all" data-everyone>${ esc( __( 'Everyone', 'novawork' ) ) }</option><option value="me" data-person data-you data-pid="${ me() }"${ tWho === 'me' ? ' selected' : '' }>${ esc( mePerson ? mePerson.name : __( 'You', 'novawork' ) ) }</option>` +
		people()
			.filter( ( p ) => p.id !== me() )
			.map( ( p ) => `<option data-person value="${ p.id }"${ tWho === String( p.id ) ? ' selected' : '' }>${ esc( p.name ) }</option>` )
			.join( '' );
	const whoHtml = who.replace( '<option value="all" data-everyone>', `<option value="all" data-everyone${ tWho === 'all' ? ' selected' : '' }>` );

	return (
		`<div class="nw-view-head"><div><h1>${ esc( __( 'Time', 'novawork' ) ) }</h1><p>${ esc( __( 'Where the hours went. Start and pause tracking from the header or from any task.', 'novawork' ) ) }</p></div></div>` +
		`<div class="nw-tbar"><div class="nw-tabs nw-seg nw-sm" id="nw-range-tabs" role="tablist" aria-label="${ esc( __( 'Period', 'novawork' ) ) }"><span class="nw-ind" aria-hidden="true"></span>${ RANGES()
			.map( ( r ) => `<button class="nw-tab${ r.key === range ? ' nw-on' : '' }" type="button" role="tab" aria-selected="${ r.key === range }" data-range="${ r.key }">${ esc( r.label ) }</button>` )
			.join( '' ) }</div><select id="nw-t-who" data-nw-sel data-right="1" aria-label="${ esc( __( 'Whose time', 'novawork' ) ) }">${ whoHtml }</select></div>` +
		`<div id="nw-tbody">${ body( S, range, tWho ) }</div>`
	);
}

function body( S, range, tWho ) {
	const uid = tWho === 'me' ? me() : tWho === 'all' ? null : Number( tWho );
	const now = serverNow();
	const from = rangeStart( range, now );
	const { loading, list } = entriesFrom( from, range );
	const es = list
		.map( ( e ) => Object.assign( {}, e, { end: e.end || now, live: e.running } ) )
		.filter( ( e ) => ( ! uid || e.by === uid ) && e.end > from )
		.map( ( e ) => Object.assign( e, { s: Math.max( e.start, from ), ms: Math.min( e.end, now ) - Math.max( e.start, from ) } ) );
	const total = es.reduce( ( a, e ) => a + e.ms, 0 );
	const L = S.locale || undefined;

	// Buckets.
	const buckets = [];
	const d0 = new Date( from );
	if ( range === 'today' ) {
		// The whole day, 00:00 to 23:59 (owner, 2026-11-23: the prototype's
		// 6 to 24 hid time tracked after midnight).
		for ( let h = 0; h < 24; h++ ) {
			const a = new Date( d0 );
			a.setHours( h );
			buckets.push( { a: +a, b: +a + HOUR, l: h % 3 === 0 ? String( h ) : '', now: new Date( now ).getHours() === h } );
		}
	} else if ( range === 'week' ) {
		for ( let i = 0; i < 7; i++ ) {
			const a = new Date( d0 );
			a.setDate( a.getDate() + i );
			buckets.push( { a: +a, b: +a + DAY, l: a.toLocaleDateString( L, { weekday: 'short' } ), now: iso( a ) === todayISO() } );
		}
	} else if ( range === 'month' ) {
		const n = new Date( d0.getFullYear(), d0.getMonth() + 1, 0 ).getDate();
		for ( let i = 0; i < n; i++ ) {
			const a = new Date( d0 );
			a.setDate( i + 1 );
			buckets.push( { a: +a, b: +a + DAY, l: ( i + 1 ) % 5 === 0 || i === 0 ? String( i + 1 ) : '', now: iso( a ) === todayISO() } );
		}
	} else {
		for ( let i = 0; i < 12; i++ ) {
			const a = new Date( d0.getFullYear(), i, 1 );
			const b = new Date( d0.getFullYear(), i + 1, 1 );
			buckets.push( { a: +a, b: +b, l: a.toLocaleDateString( L, { month: 'narrow' } ), now: new Date( now ).getMonth() === i } );
		}
	}
	const projOf = ( e ) => ( task( e.taskId ) || {} ).projectId || 0;
	const pids = [ ...new Set( es.map( projOf ) ) ];
	buckets.forEach( ( bk ) => {
		bk.by = {};
		es.forEach( ( e ) => {
			const ov = Math.min( e.s + e.ms, bk.b ) - Math.max( e.s, bk.a );
			if ( ov > 0 ) {
				bk.by[ projOf( e ) ] = ( bk.by[ projOf( e ) ] || 0 ) + ov;
			}
		} );
		bk.sum = Object.values( bk.by ).reduce( ( a, x ) => a + x, 0 );
	} );
	const max = niceMax( Math.max( ...buckets.map( ( b ) => b.sum ), range === 'today' ? 30 * MIN : HOUR ) );
	const pcol = ( pid ) => ( pid && project( pid ) ? col( project( pid ).color ) : 'var(--nw-line-strong)' );
	const pname = ( pid ) => ( project( pid ) ? project( pid ).name : __( 'No project', 'novawork' ) );
	const gap = range === 'month' ? 3 : range === 'today' ? 4 : 10;
	const chart =
		`<div class="nw-chart" style="--nw-gap:${ gap }px"><div class="nw-yax"><span>${ esc( dur( max, true ) ) }</span><span>${ esc( dur( max / 2, true ) ) }</span><span>0</span></div>` +
		`<div class="nw-plot"><div class="nw-gl" style="top:0"></div><div class="nw-gl" style="top:50%"></div>${ buckets
			.map(
				( bk ) =>
					`<div class="nw-col${ bk.now ? ' nw-today' : '' }" title="${ esc( dur( bk.sum ) ) }">${ pids
						.filter( ( p ) => bk.by[ p ] )
						.map( ( p ) => `<i style="--nw-c:${ pcol( p ) };height:${ ( ( bk.by[ p ] / max ) * 100 ).toFixed( 2 ) }%"></i>` )
						.join( '' ) }</div>`
			)
			.join( '' ) }</div>` +
		`<div class="nw-xax">${ buckets.map( ( bk ) => `<span>${ esc( bk.l ) }</span>` ).join( '' ) }</div></div>`;
	const byProj = pids.map( ( p ) => ( { p, ms: es.filter( ( e ) => projOf( e ) === p ).reduce( ( a, e ) => a + e.ms, 0 ) } ) ).sort( ( a, b ) => b.ms - a.ms );
	const byTask = Object.values(
		es.reduce( ( m, e ) => {
			( m[ e.taskId ] = m[ e.taskId ] || { id: e.taskId, ms: 0 } ).ms += e.ms;
			return m;
		}, {} )
	)
		.sort( ( a, b ) => b.ms - a.ms )
		.slice( 0, 7 );
	const workDays = new Set( es.map( ( e ) => iso( new Date( e.s ) ) ) ).size;
	const longest = es.reduce( ( a, e ) => Math.max( a, e.ms ), 0 );
	const rangeLabel = { today: '', week: __( 'week to date', 'novawork' ), month: __( 'month to date', 'novawork' ), year: __( 'year to date', 'novawork' ) }[ range ];
	const kpis = [
		[ __( 'Tracked', 'novawork' ), dur( total ), range === 'today' ? __( 'since midnight', 'novawork' ) : rangeLabel ],
		range === 'today'
			? [ __( 'Longest stretch', 'novawork' ), dur( longest ), sprintf( /* translators: %d: number of sessions. */ _n( '%d session', '%d sessions', es.length, 'novawork' ), es.length ) ]
			: [
				__( 'Daily average', 'novawork' ),
				dur( workDays ? total / workDays : 0 ),
				sprintf( /* translators: %d: number of working days. */ _n( 'over %d working day', 'over %d working days', workDays, 'novawork' ), workDays ),
			],
		[ __( 'Sessions', 'novawork' ), String( es.length ), es.length ? sprintf( /* translators: %s: average session length. */ __( 'avg %s', 'novawork' ), dur( total / es.length ) ) : __( 'none yet', 'novawork' ) ],
		[
			__( 'Top project', 'novawork' ),
			byProj[ 0 ] && project( byProj[ 0 ].p ) ? project( byProj[ 0 ].p ).name : '–',
			byProj[ 0 ] && total ? sprintf( /* translators: %d: percent. */ __( '%d%% of the time', 'novawork' ), Math.round( ( byProj[ 0 ].ms / total ) * 100 ) ) : '',
		],
	];
	const none = `<div style="color:var(--nw-faint)">${ esc( __( 'No time yet.', 'novawork' ) ) }</div>`;
	const team = ! uid
		? `<div class="nw-panel" style="margin-top:14px"><h3>${ esc( __( 'By person', 'novawork' ) ) }<span>${ esc( dur( total ) ) }</span></h3>${ people()
			.map( ( p ) => {
				const ms = es.filter( ( e ) => e.by === p.id ).reduce( ( a, e ) => a + e.ms, 0 );
				return `<div class="nw-hbar" style="--nw-c:${ col( p.color ) }"><div class="nw-nm">${ presAvatar( p.id ) }<span>${ esc( p.name ) }</span></div><span class="nw-v">${ esc( dur( ms ) ) }</span><div class="nw-track"><i style="width:${
					total ? ( ( ms / total ) * 100 ).toFixed( 1 ) : 0
				}%"></i></div></div>`;
			} )
			.join( '' ) }</div>`
		: '';
	const recent = es
		.slice()
		.sort( ( a, b ) => b.s - a.s )
		.slice( 0, 8 );
	const chartTitle = range === 'today' ? __( 'By hour', 'novawork' ) : range === 'year' ? __( 'By month', 'novawork' ) : __( 'By day', 'novawork' );
	const emptyChart = range === 'today' ? __( 'Nothing tracked today yet.', 'novawork' ) : __( 'Nothing tracked in this period yet.', 'novawork' );
	return (
		`<div class="nw-kpis">${ kpis.map( ( k ) => `<div class="nw-kpi"><span>${ esc( k[ 0 ] ) }</span><b>${ esc( k[ 1 ] ) }</b><small>${ esc( k[ 2 ] ) }</small></div>` ).join( '' ) }</div>` +
		`<div class="nw-panel" style="margin-top:14px"><h3>${ esc( chartTitle ) }<span>${ esc( loading ? __( 'Loading…', 'novawork' ) : dur( total ) ) }</span></h3>${
			total ? chart : `<div class="nw-empty">${ ic( 'clock' ) }${ esc( loading ? __( 'Loading…', 'novawork' ) : emptyChart ) }</div>`
		}` +
		( total ? `<div class="nw-legend">${ byProj.map( ( x ) => `<span><i class="nw-dot" style="--nw-c:${ pcol( x.p ) }"></i>${ esc( pname( x.p ) ) }</span>` ).join( '' ) }</div>` : '' ) +
		'</div>' +
		`<div class="nw-two-col"><div class="nw-panel"><h3>${ esc( __( 'By project', 'novawork' ) ) }</h3>${
			byProj.length
				? byProj
					.map(
						( x ) =>
							`<div class="nw-hbar" style="--nw-c:${ pcol( x.p ) }"><div class="nw-nm"><span class="nw-dot"></span><span>${ esc( pname( x.p ) ) }</span></div><span class="nw-v">${ esc( dur( x.ms ) ) } · ${
								total ? Math.round( ( x.ms / total ) * 100 ) : 0
							}%</span><div class="nw-track"><i style="width:${ total ? ( ( x.ms / total ) * 100 ).toFixed( 1 ) : 0 }%"></i></div></div>`
					)
					.join( '' )
				: none
		}</div>` +
		`<div class="nw-panel"><h3>${ esc( __( 'Top tasks', 'novawork' ) ) }</h3><div class="nw-log">${
			byTask.length
				? byTask
					.map( ( x ) => {
						const t = task( x.id );
						return `<div class="nw-row"><span class="nw-dot" style="--nw-c:${ pcol( t ? t.projectId : 0 ) }"></span><span class="nw-grow" data-act="edit-task" data-id="${ x.id }">${ esc(
							t ? t.title : __( 'Deleted task', 'novawork' )
						) }</span><span class="nw-tstat">${ esc( dur( x.ms ) ) }</span></div>`;
					} )
					.join( '' )
				: none
		}</div></div></div>` +
		team +
		`<div class="nw-panel" style="margin-top:14px"><h3>${ esc( __( 'Recent sessions', 'novawork' ) ) }</h3><div class="nw-log">${
			recent.length
				? recent
					.map( ( e ) => {
						const t = task( e.taskId );
						const d = new Date( e.s );
						const when = iso( d ) === todayISO() ? d.toLocaleTimeString( L, { hour: '2-digit', minute: '2-digit' } ) : fmt( iso( d ) );
						return `<div class="nw-row">${ avatar( e.by ) }<span class="nw-grow" data-act="edit-task" data-id="${ e.taskId }">${ esc( t ? t.title : __( 'Deleted task', 'novawork' ) ) }</span><span class="nw-due">${ esc(
							when
						) }</span><span class="nw-tstat">${ e.live ? `<span data-since="${ e.start }"></span>` : esc( dur( e.ms ) ) }</span></div>`;
					} )
					.join( '' )
				: `<div style="color:var(--nw-faint)">${ esc( __( 'No sessions yet.', 'novawork' ) ) }</div>`
		}</div></div>`
	);
}

function paintBody( main ) {
	const tbody = main.querySelector( '#nw-tbody' );
	if ( tbody ) {
		tbody.innerHTML = body( store(), pref( 'nw.range' ) || 'week', pref( 'nw.tWho' ) || 'me' );
		main.dispatchEvent( new CustomEvent( 'nw:painted' ) );
	}
}

export function wireTime( main, rerender ) {
	refresh = rerender;
	const nav = main.querySelector( '#nw-range-tabs' );
	placeInd( nav, true );
	nav.addEventListener( 'click', ( e ) => {
		const b = e.target.closest( '[data-range]' );
		if ( ! b ) {
			return;
		}
		pref( 'nw.range', b.dataset.range );
		nav.querySelectorAll( '.nw-tab' ).forEach( ( x ) => {
			const on = x === b;
			x.classList.toggle( 'nw-on', on );
			x.setAttribute( 'aria-selected', String( on ) );
		} );
		placeInd( nav );
		// Only the body redraws, so the indicator slides (as in the prototype).
		paintBody( main );
	} );
	main.querySelector( '#nw-t-who' ).addEventListener( 'change', ( e ) => {
		pref( 'nw.tWho', e.target.value );
		rerender();
	} );
}

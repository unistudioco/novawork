/**
 * Roadmap: a timeline with project names pinned on the left, Months /
 * Quarters zoom, opening scrolled to today, and the project cards. Ported
 * from the prototype's viewRoadmap() / wireRoadmap().
 *
 * One change: the prototype scrolled back to today on every redraw. With live
 * sync a teammate's edit redraws the view, so the timeline keeps its scroll
 * position and only jumps to today on first open, on zoom, or on "Today".
 */
import { ic } from '../lib/icons.js';
import { __, sprintf } from '../lib/i18n.js';
import { esc, dur } from '../lib/format.js';
import { placeInd } from '../lib/ui.js';
import { serverNow } from '../store.js';
import { store, projects, tasks, byOrder, parse, iso, todayISO, fmt, col, cpair, avatars } from './model.js';

const pref = ( k, v ) => {
	try {
		if ( v === undefined ) {
			return window.localStorage.getItem( k );
		}
		window.localStorage.setItem( k, v );
	} catch ( e ) {}
	return null;
};

let keptScroll = null;

const STATUS = () => ( { active: __( 'Active', 'novawork' ), paused: __( 'Paused', 'novawork' ), done: __( 'Done', 'novawork' ) } );

/** Time tracked on a project: finished sessions plus anything running now. */
function projectTime( pid, ts ) {
	const now = serverNow();
	const ids = new Set( ts.map( ( t ) => t.id ) );
	const live = Object.values( store().timeEntries )
		.filter( ( e ) => e.running && ids.has( e.taskId ) )
		.reduce( ( a, e ) => a + now - e.start, 0 );
	return ts.reduce( ( a, t ) => a + ( t.trackedMs || 0 ), 0 ) + live;
}

export function viewRoadmap() {
	const L = store().locale || undefined;
	const ps = projects();
	const dated = ps.filter( ( p ) => parse( p.start ) || parse( p.target ) );
	let html = `<div class="nw-view-head"><div><h1>${ esc( __( 'Roadmap', 'novawork' ) ) }</h1><p>${ esc(
		__( 'Each bar runs from a project’s start to its target date and fills as its tasks get done. The dashed red line is a hard deadline.', 'novawork' )
	) }</p></div><div class="nw-acts"><button class="nw-btn nw-primary" type="button" data-act="new-project">${ ic( 'plus', 15 ) }${ esc( __( 'New project', 'novawork' ) ) }</button></div></div>`;

	if ( dated.length ) {
		const phone = window.matchMedia( '(max-width: 719px)' ).matches;
		const zoom = pref( 'nw.tlZoom' ) === 'quarter' ? 'quarter' : 'month';
		const pxm = zoom === 'quarter' ? ( phone ? 40 : 58 ) : phone ? 96 : 136;
		const t0 = parse( todayISO() );
		let min = new Date( Math.min( ...dated.map( ( p ) => +( parse( p.start ) || parse( p.target ) ) ), +t0 ) );
		let max = new Date( Math.max( ...dated.map( ( p ) => +( parse( p.deadline ) || parse( p.target ) || parse( p.start ) ) ), +t0 ) );
		min = new Date( min.getFullYear(), min.getMonth() - 1, 1 );
		max = new Date( max.getFullYear(), max.getMonth() + 2, 1 );
		if ( zoom === 'quarter' ) {
			min = new Date( min.getFullYear(), Math.floor( min.getMonth() / 3 ) * 3, 1 );
			max = new Date( max.getFullYear(), Math.ceil( max.getMonth() / 3 ) * 3, 1 );
		}
		const months = [];
		for ( let d = new Date( min ); d < max; d = new Date( d.getFullYear(), d.getMonth() + 1, 1 ) ) {
			months.push( d );
		}
		const W = months.length * pxm;
		const X = ( d ) => {
			const i = ( d.getFullYear() - min.getFullYear() ) * 12 + d.getMonth() - min.getMonth();
			const dim = new Date( d.getFullYear(), d.getMonth() + 1, 0 ).getDate();
			return Math.round( ( i + ( d.getDate() - 1 ) / dim ) * pxm );
		};
		const curKey = todayISO().slice( 0, 7 );
		const tickMonths = zoom === 'quarter' ? months.filter( ( m ) => m.getMonth() % 3 === 0 ) : months;
		const ticks = tickMonths
			.map( ( m, i ) => {
				const q = Math.floor( m.getMonth() / 3 ) + 1;
				const cur = zoom === 'quarter' ? m.getFullYear() === t0.getFullYear() && q === Math.floor( t0.getMonth() / 3 ) + 1 : iso( m ).slice( 0, 7 ) === curKey;
				const label = zoom === 'quarter' ? sprintf( /* translators: %d: quarter number, 1-4. */ __( 'Q%d', 'novawork' ), q ) : m.toLocaleDateString( L, { month: 'short' } );
				return `<div class="nw-mtick${ cur ? ' nw-cur' : '' }" style="left:${ X( m ) }px">${ esc( label ) }<small>${ m.getMonth() === 0 || i === 0 ? m.getFullYear() : '' }</small></div>`;
			} )
			.join( '' );
		const grid = months.map( ( m ) => `<i class="nw-gl${ zoom === 'quarter' && m.getMonth() % 3 ? ' nw-minor' : '' }" style="left:${ X( m ) }px"></i>` ).join( '' );
		const curm = zoom === 'month' ? `<i class="nw-curm" style="left:${ X( new Date( t0.getFullYear(), t0.getMonth(), 1 ) ) }px;width:${ pxm }px"></i>` : '';
		const rows = ps
			.map( ( p ) => {
				const s0 = parse( p.start ) || parse( p.target );
				const e0 = parse( p.target ) || parse( p.start );
				const ts = tasks().filter( ( t ) => t.projectId === p.id );
				const done = ts.filter( ( t ) => t.status === 'done' ).length;
				const prog = ts.length ? Math.round( ( done / ts.length ) * 100 ) : 0;
				let bar = '';
				if ( s0 && e0 ) {
					const left = X( s0 );
					const w = Math.max( X( new Date( e0.getFullYear(), e0.getMonth(), e0.getDate() + 1 ) ) - left, 10 );
					const lbl = prog + '% · ' + fmt( p.target );
					const title = sprintf( /* translators: 1: project, 2: start date, 3: target date, 4: percent done. */ __( '%1$s: %2$s to %3$s, %4$d%% done', 'novawork' ), p.name, fmt( p.start, true ), fmt( p.target, true ), prog );
					bar = `<div class="nw-tl-bar${ w < 112 ? ' nw-narrow' : '' }" data-act="edit-project" data-id="${ p.id }" style="${ cpair( p.color ) };left:${ left }px;width:${ w }px" title="${ esc( title ) }"><div class="nw-fill" style="width:${ prog }%"></div><span class="nw-lbl">${ esc( lbl ) }</span></div>`;
					if ( parse( p.deadline ) ) {
						bar += `<div class="nw-tl-deadline" style="left:${ X( parse( p.deadline ) ) }px" title="${ esc( sprintf( /* translators: %s: date. */ __( 'Hard deadline %s', 'novawork' ), fmt( p.deadline, true ) ) ) }">${ ic( 'flag-banner', 12 ) }</div>`;
					}
				}
				return `<div class="nw-tl-row"><div class="nw-tl-label" data-act="edit-project" data-id="${ p.id }"><span class="nw-dot" style="--nw-c:${ col( p.color ) }"></span><span class="nw-nm">${ esc( p.name ) }</span><small>${ esc( fmt( p.start ) ) } – ${ esc(
					fmt( p.target )
				) }</small></div><div class="nw-tl-track" style="width:${ W }px">${ grid }${ curm }<div class="nw-tl-today-line" style="left:${ X( t0 ) }px"></div>${ bar }</div></div>`;
			} )
			.join( '' );
		html +=
			`<div class="nw-tl-tools"><div class="nw-tabs nw-seg nw-sm" id="nw-tl-zoom" role="tablist" aria-label="${ esc( __( 'Zoom', 'novawork' ) ) }"><span class="nw-ind" aria-hidden="true"></span>${ [
				[ 'month', __( 'Months', 'novawork' ) ],
				[ 'quarter', __( 'Quarters', 'novawork' ) ],
			]
				.map( ( [ k, l ] ) => `<button class="nw-tab${ zoom === k ? ' nw-on' : '' }" type="button" role="tab" aria-selected="${ zoom === k }" data-zoom="${ k }">${ esc( l ) }</button>` )
				.join( '' ) }</div><button class="nw-btn nw-sm" type="button" data-act="tl-today">${ ic( 'calendar-blank', 14 ) }${ esc( __( 'Today', 'novawork' ) ) }</button></div>` +
			`<div class="nw-timeline-scroll" id="nw-tl-scroll" data-today-x="${ X( t0 ) }" data-zoom="${ zoom }"><div class="nw-timeline"><div class="nw-tl-head"><div class="nw-tl-corner"></div><div class="nw-ticks" style="width:${ W }px">${ ticks }<div class="nw-tl-today" style="left:${ X(
				t0
			) }px"><span>${ esc( __( 'Today', 'novawork' ) ) }</span></div></div></div>${ rows }</div></div>`;
	}

	html +=
		'<div class="nw-projects">' +
		( ps.length
			? ps
				.map( ( p ) => {
					const ts = tasks().filter( ( t ) => t.projectId === p.id );
					const done = ts.filter( ( t ) => t.status === 'done' ).length;
					const prog = ts.length ? Math.round( ( done / ts.length ) * 100 ) : 0;
					const nextUp = ts.filter( ( t ) => t.status === 'now' ).sort( byOrder )[ 0 ] || ts.filter( ( t ) => t.status === 'next' ).sort( byOrder )[ 0 ];
					const status = p.status || 'active';
					return (
						`<article class="nw-proj" data-proj="${ Number( p.id ) }" tabindex="-1" style="--nw-c:${ col( p.color ) }"><header><span class="nw-dot"></span><h3>${ esc( p.name ) }</h3><button class="nw-iconbtn nw-sqbtn" type="button" data-act="edit-project" data-id="${ p.id }" aria-label="${ esc(
							sprintf( /* translators: %s: project name. */ __( 'Edit %s', 'novawork' ), p.name )
						) }">${ ic( 'pencil-simple', 15 ) }</button></header>` +
						( p.goal ? `<p>${ esc( p.goal ) }</p>` : '' ) +
						`<div class="nw-pbar"><i style="width:${ prog }%"></i></div>` +
						`<div class="nw-stats"><span class="nw-badge nw-${ esc( status ) }">${ esc( STATUS()[ status ] || status ) }</span><span>${ sprintf(
							/* translators: 1: tasks done, 2: all tasks. */ esc( __( '%1$s of %2$s done', 'novawork' ) ),
							`<b>${ done }</b>`,
							String( ts.length )
						) }</span><span>${ sprintf( /* translators: %s: time tracked. */ esc( __( '%s tracked', 'novawork' ) ), `<b>${ esc( dur( projectTime( p.id, ts ), true ) ) }</b>` ) }</span>${
							p.target ? `<span>${ esc( sprintf( /* translators: %s: date. */ __( 'Target %s', 'novawork' ), fmt( p.target, true ) ) ) }</span>` : ''
						}${ p.deadline ? `<span class="nw-dl">${ ic( 'flag-banner' ) }${ esc( sprintf( /* translators: %s: date. */ __( 'Deadline %s', 'novawork' ), fmt( p.deadline, true ) ) ) }</span>` : '' }</div>` +
						( nextUp
							? `<div class="nw-next-up">${ avatars( nextUp ) }<span>${ esc( __( 'Next up', 'novawork' ) ) }</span><b data-act="edit-task" data-id="${ nextUp.id }">${ esc( nextUp.title ) }</b></div>`
							: `<div class="nw-next-up"><span>${ esc( __( 'No open tasks.', 'novawork' ) ) }</span><button class="nw-btn nw-sm nw-ghost" type="button" data-act="new-task" data-status="next" data-project="${ p.id }">${ ic( 'plus', 14 ) }${ esc(
								__( 'Add one', 'novawork' )
							) }</button></div>` ) +
						'</article>'
					);
				} )
				.join( '' )
			: `<div class="nw-empty">${ ic( 'map-trifold' ) }${ esc( __( 'No projects yet. Add your first one.', 'novawork' ) ) }</div>` ) +
		'</div>';
	return html;
}

export function scrollToToday( smooth ) {
	const sc = document.getElementById( 'nw-tl-scroll' );
	if ( ! sc ) {
		return;
	}
	const lw = sc.querySelector( '.nw-tl-corner' ).offsetWidth;
	const left = Math.max( 0, Number( sc.dataset.todayX ) - ( sc.clientWidth - lw ) * 0.3 );
	sc.scrollTo( { left, behavior: smooth ? 'smooth' : 'auto' } );
}

export function wireRoadmap( main, rerender ) {
	const nav = main.querySelector( '#nw-tl-zoom' );
	const sc = main.querySelector( '#nw-tl-scroll' );
	if ( ! nav || ! sc ) {
		return;
	}
	placeInd( nav, true );
	const key = sc.dataset.zoom;
	if ( keptScroll && keptScroll.zoom === key ) {
		sc.scrollLeft = keptScroll.left;
	} else {
		scrollToToday( false );
	}
	keptScroll = { zoom: key, left: sc.scrollLeft };
	sc.addEventListener( 'scroll', () => {
		keptScroll = { zoom: key, left: sc.scrollLeft };
	}, { passive: true } );
	nav.addEventListener( 'click', ( e ) => {
		const b = e.target.closest( '[data-zoom]' );
		if ( ! b || b.dataset.zoom === key ) {
			return;
		}
		pref( 'nw.tlZoom', b.dataset.zoom );
		keptScroll = null;
		rerender();
	} );
}

/** Read the timeline's position just before a redraw replaces it. */
export function rememberRoadmapScroll( root ) {
	const sc = root.querySelector( '#nw-tl-scroll' );
	if ( sc ) {
		keptScroll = { zoom: sc.dataset.zoom, left: sc.scrollLeft };
	}
}

/** Leaving the view forgets the scroll, so coming back opens on today. */
export function forgetRoadmapScroll() {
	keptScroll = null;
}

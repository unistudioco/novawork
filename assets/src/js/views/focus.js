/**
 * Focus ("Your day"): the big timer card, your three in Now, up next, team
 * today, due soon and open decisions. Ported from the prototype's viewFocus().
 */
import { ic, icf } from '../lib/icons.js';
import { __, sprintf } from '../lib/i18n.js';
import { esc, dur, dayStart, longDate, shortTitle } from '../lib/format.js';
import { serverNow } from '../store.js';

/**
 * Owner decision (2026-11-22): while the administrator is alone in the
 * workspace, Team today offers to invite the team (Settings → Members).
 *
 * @param {number} count Members.
 */
const inviteRow = ( count ) => {
	const url = ( window.novawork || {} ).inviteUrl;
	if ( count > 1 || ! url ) {
		return '';
	}
	return `<a class="nw-row nw-invite" href="${ esc( url ) }"><span class="nw-invite-ic">${ ic( 'user-plus', 17 ) }</span><div class="nw-tm"><b>${ esc( __( 'Invite your team', 'novawork' ) ) }</b><span>${ esc( __( 'Send a link from Settings → Members.', 'novawork' ) ) }</span></div></a>`;
};
import { orderTeam } from '../lib/team.js';
import {
	canDecide, nowLimit, store, me, people, person, task, tasks, decisions, has, assignees, myRun, runOf, status, tracked, byOrder, days,
	projChip, dueHTML, prio, avatars, presAvatar, tbtn, checkBtn, since,
} from './model.js';

/** Start of the current week, using the site's first day of the week. */
export function weekStart( at ) {
	const first = Number( store().settings.weekStart ?? 1 );
	const d = new Date( dayStart( at ) );
	d.setDate( d.getDate() - ( ( d.getDay() - first + 7 ) % 7 ) );
	return d.getTime();
}

/** "0:12 · Write the…" for someone tracking, "Online" or "Away" otherwise. */
export function statusLine( uid ) {
	const r = runOf( uid );
	if ( r ) {
		const t = task( r.taskId );
		return since( r.start ) + ( t ? ` · <span class="nw-tname" title="${ esc( t.title ) }">${ esc( shortTitle( t.title ) ) }</span>` : '' );
	}
	return esc( status( uid ) === 'online' ? __( 'Online', 'novawork' ) : __( 'Away', 'novawork' ) );
}

/** "Team today" in the admin's chosen order (see lib/team.js). */
export function orderedTeam( list, now ) {
	const today = dayStart( now );
	return orderTeam( list, { order: store().settings.teamOrder || 'dynamic', status, today: ( id ) => tracked( id, today ), me: me() } );
}

export function viewFocus() {
	const S = store();
	const now = serverNow();
	const mePerson = person( me() );
	const mine = tasks().filter( ( t ) => has( t, me() ) && t.status === 'now' ).sort( byOrder );
	const nexts = tasks().filter( ( t ) => has( t, me() ) && t.status === 'next' ).sort( byOrder );
	const r = myRun();
	const rt = r && task( r.taskId );
	const top = mine[ 0 ] || nexts[ 0 ];
	const today = tracked( me(), dayStart( now ) );
	const week = tracked( me(), weekStart( now ) );

	const card = r
		? `<div class="nw-now-card nw-run"><div class="nw-txt2"><span>${ rt ? esc( rt.title ) : esc( __( 'Running', 'novawork' ) ) }</span><div class="nw-big" data-since="${ r.start }"></div><span>${ esc(
			sprintf( /* translators: 1: time today, 2: time this week. */ __( '%1$s today · %2$s this week', 'novawork' ), dur( today ), dur( week ) )
		) }</span></div><button class="nw-tbtn nw-run" type="button" data-act="track" data-id="${ r.taskId }" aria-label="${ esc( __( 'Pause tracking', 'novawork' ) ) }">${ icf( 'pause' ) }</button></div>`
		: `<div class="nw-now-card"><div class="nw-txt2"><span>${ esc( __( 'Tracked today', 'novawork' ) ) }</span><div class="nw-big">${ esc( dur( today ) ) }</div><span>${ esc(
			sprintf( /* translators: %s: time this week. */ __( '%s this week', 'novawork' ), dur( week ) ) + ( top ? ' · ' + sprintf( /* translators: %s: task title. */ __( 'Next: %s', 'novawork' ), top.title ) : '' )
		) }</span></div>${
			top ? `<button class="nw-tbtn" type="button" data-act="track" data-id="${ top.id }" aria-label="${ esc( sprintf( /* translators: %s: task title. */ __( 'Start tracking %s', 'novawork' ), top.title ) ) }">${ icf( 'play' ) }</button>` : ''
		}</div>`;

	const limit = nowLimit();
	const three = limit === 3;
	const slot = ( t ) =>
		t
			? `<li class="nw-slot${ r && r.taskId === t.id ? ' nw-run' : '' }" data-drag data-id="${ t.id }">${ checkBtn( t ) }<div class="nw-t" data-act="edit-task" data-id="${ t.id }">${ esc( t.title ) }</div>${ prio( t.priority ) || '<span></span>' }${ tbtn( t ) }<div class="nw-meta">${ projChip( t.projectId ) }${ dueHTML( t.due, t.status ) }${ assignees( t ).length > 1 ? avatars( t ) : '' }</div></li>`
			: `<li class="nw-slot nw-empty" data-tail>${ ic( 'circle-dashed' ) }<div class="nw-t">${ esc( __( 'Open slot', 'novawork' ) ) }</div><button class="nw-btn nw-sm nw-ghost" type="button" data-act="new-task" data-status="now">${ ic( 'plus', 14 ) }${ esc( __( 'Add', 'novawork' ) ) }</button></li>`;

	const left =
		`<section><div class="nw-label">${ esc( __( 'Your day', 'novawork' ) ) }</div>${ card }</section>` +
		`<section><div class="nw-label">${ esc(
			three
				? sprintf( /* translators: %s: your name. */ __( '%s, your three', 'novawork' ), mePerson ? mePerson.name : __( 'You', 'novawork' ) )
				: sprintf( /* translators: 1: your name, 2: the Now limit. */ __( '%1$s, your top %2$d', 'novawork' ), mePerson ? mePerson.name : __( 'You', 'novawork' ), limit )
		) }<span class="nw-n nw-tnum">${ esc( sprintf( /* translators: 1: tasks in Now, 2: the Now limit. */ __( '%1$d of %2$d', 'novawork' ), Math.min( mine.length, limit ), limit ) ) }</span></div>` +
		`<ol class="nw-slots" data-zone="now">${ mine.map( slot ).join( '' ) }${ Array.from( { length: Math.max( 0, limit - mine.length ) }, () => slot( null ) ).join( '' ) }</ol>` +
		( mine.length > limit
			? `<div class="nw-note">${ ic( 'flag-banner' ) }<span>${ esc(
				three
					? sprintf( /* translators: %d: tasks in Now. */ __( '%d things in Now. Three at a time works best: drag the rest down to Up next.', 'novawork' ), mine.length )
					: sprintf( /* translators: 1: tasks in Now, 2: the Now limit. */ __( '%1$d things in Now. %2$d at a time works best: drag the rest down to Up next.', 'novawork' ), mine.length, limit )
			) }</span></div>`
			: '' ) +
		'</section>' +
		`<section><div class="nw-label">${ esc( __( 'Up next for you', 'novawork' ) ) }<span class="nw-n nw-tnum">${ nexts.length }</span></div>` +
		( nexts.length
			? `<div class="nw-list" data-zone="next">${ nexts
				.slice( 0, 6 )
				.map(
					( t ) =>
						`<div class="nw-row" data-drag data-id="${ t.id }">${ prio( t.priority ) || '<span class="nw-st nw-s-next"></span>' }<span class="nw-grow" data-act="edit-task" data-id="${ t.id }">${ esc( t.title ) }</span>${ dueHTML(
							t.due,
							t.status
						) }<button class="nw-btn nw-sm nw-ghost" type="button" data-act="to-now" data-id="${ t.id }">${ esc( __( 'Start', 'novawork' ) ) }${ ic( 'arrow-right', 14 ) }</button></div>`
				)
				.join( '' ) }</div>`
			: `<div class="nw-empty">${ ic( 'sparkle' ) }${ esc( __( 'Your Next list is empty.', 'novawork' ) ) }</div>` ) +
		'</section>';

	// "Due in the next 3 weeks" is the prototype's fixed look-ahead for this list.
	const soon = tasks()
		.filter( ( t ) => t.status !== 'done' && t.due && days( t.due ) <= 21 )
		.sort( ( a, b ) => a.due.localeCompare( b.due ) );
	const openDec = decisions()
		.filter( ( d ) => d.status !== 'decided' )
		.sort( ( a, b ) => ( a.decideBy || '9999' ).localeCompare( b.decideBy || '9999' ) );

	const right =
		`<section class="nw-sec-team"><div class="nw-label">${ esc( __( 'Team today', 'novawork' ) ) }</div><div class="nw-list nw-team">${ orderedTeam( people(), now )
			.map(
				( p ) =>
					`<div class="nw-row">${ presAvatar( p.id ) }<div class="nw-tm"><b>${ esc( p.name ) }${ p.id === S.me ? `<span class="nw-you">${ esc( __( 'You', 'novawork' ) ) }</span>` : '' }</b><span>${ statusLine(
						p.id
					) }</span></div><span class="nw-tstat" title="${ esc( __( 'Tracked today', 'novawork' ) ) }" data-today="${ p.id }">${ esc( dur( tracked( p.id, dayStart( now ) ) ) ) }</span></div>`
			)
			.join( '' ) }${ inviteRow( people().length ) }</div></section>` +
		`<section class="nw-sec-due"><div class="nw-label">${ esc( __( 'Due in the next 3 weeks', 'novawork' ) ) }<span class="nw-n nw-tnum">${ soon.length }</span></div>` +
		( soon.length
			? `<div class="nw-list">${ soon.map( ( t ) => `<div class="nw-row">${ avatars( t ) }<span class="nw-grow" data-act="edit-task" data-id="${ t.id }">${ esc( t.title ) }</span>${ dueHTML( t.due, t.status ) }</div>` ).join( '' ) }</div>`
			: `<div class="nw-empty">${ ic( 'calendar-blank' ) }${ esc( __( 'No deadlines in the next three weeks.', 'novawork' ) ) }</div>` ) +
		'</section>' +
		`<section class="nw-sec-wait"><div class="nw-label">${ esc( __( 'Waiting on a decision', 'novawork' ) ) }<span class="nw-n nw-tnum">${ openDec.length }</span></div>` +
		( openDec.length
			? `<div class="nw-list">${ openDec
				.slice( 0, 4 )
				.map( ( d ) => `<div class="nw-row">${ ic( 'scales', 17 ) }<span class="nw-grow" data-act="${ canDecide( d ) ? 'decide' : 'edit-decision' }" data-id="${ d.id }">${ esc( d.question ) }</span>${ dueHTML( d.decideBy, 'open' ) }</div>` )
				.join( '' ) }</div>`
			: `<div class="nw-empty">${ ic( 'scales' ) }${ esc( __( 'Nothing waiting on a decision.', 'novawork' ) ) }</div>` ) +
		'</section>';

	return (
		`<div class="nw-view-head"><div><h1>${ esc( __( 'Focus', 'novawork' ) ) }</h1><p>${ esc( longDate( now, S.locale ) ) }. ${ esc( __( 'Three things at a time. Everything else waits in Next.', 'novawork' ) ) }</p></div></div>` +
		`<div class="nw-focus"><div class="nw-stack">${ left }</div><div class="nw-stack nw-side">${ right }</div></div>`
	);
}

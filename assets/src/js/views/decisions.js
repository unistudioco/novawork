/**
 * Decisions: Open | Decided columns on desktop, a Decide action, comments.
 * Ported from the prototype's viewDecisions().
 */
import { ic } from '../lib/icons.js';
import { __, sprintf } from '../lib/i18n.js';
import { esc } from '../lib/format.js';
import { decisions, commentsOf, avatar, person, projChip, dueHTML, fmt, iso, canDecide, votesHTML } from './model.js';

/** "Waiting on Sam": shown instead of Decide to everyone who can't decide. */
function waiting( d ) {
	const p = person( d.ownerId );
	return `<span class="nw-waiting">${ esc( sprintf( /* translators: %s: name of the person who decides. */ __( 'Waiting on %s', 'novawork' ), p ? p.name : __( 'someone', 'novawork' ) ) ) }</span>`;
}

function card( d ) {
	const decided = d.status === 'decided';
	const comments = commentsOf( 'decision', d.id ).length;
	return (
		`<article class="nw-dec${ decided ? ' nw-decided' : '' }"><div class="nw-q">${ esc( d.question ) }</div>` +
		( d.context ? `<div class="nw-opts">${ esc( d.context ) }</div>` : '' ) +
		( d.voting && ( d.options || [] ).length ? votesHTML( d, 'vote', ! decided ) : '' ) +
		( decided && d.answer ? `<div class="nw-ans">${ esc( d.answer ) }</div>` : '' ) +
		`<div class="nw-foot">${ d.ownerId ? avatar( d.ownerId ) : '' }${
			decided
				? `<span class="nw-due">${ ic( 'check' ) }${ esc( sprintf( /* translators: %s: date. */ __( 'Decided %s', 'novawork' ), d.decidedAt ? fmt( iso( new Date( d.decidedAt ) ), true ) : '' ) ) }</span>`
				: d.decideBy
					? dueHTML( d.decideBy, 'open' )
					: ''
		}${ projChip( d.projectId ) }${ comments ? `<span class="nw-cnt">${ ic( 'chat-circle' ) }${ comments }</span>` : '' }<span class="nw-sp"></span>` +
		( decided ? '' : canDecide( d ) ? `<button class="nw-btn nw-secondary" type="button" data-act="decide" data-id="${ d.id }">${ ic( 'check', 15 ) }${ esc( __( 'Decide', 'novawork' ) ) }</button>` : waiting( d ) ) +
		`<button class="nw-iconbtn nw-sqbtn" type="button" data-act="edit-decision" data-id="${ d.id }" aria-label="${ esc( __( 'Edit decision', 'novawork' ) ) }">${ ic( 'pencil-simple', 16 ) }</button></div></article>`
	);
}

export function viewDecisions() {
	const all = decisions();
	const open = all.filter( ( d ) => d.status !== 'decided' ).sort( ( a, b ) => ( a.decideBy || '9999' ).localeCompare( b.decideBy || '9999' ) );
	const done = all.filter( ( d ) => d.status === 'decided' ).sort( ( a, b ) => ( b.decidedAt || 0 ) - ( a.decidedAt || 0 ) );
	return (
		`<div class="nw-view-head"><div><h1>${ esc( __( 'Decisions', 'novawork' ) ) }</h1><p>${ esc( __( 'Questions that block work. Record the answer so nobody has to ask twice.', 'novawork' ) ) }</p></div><div class="nw-acts"><button class="nw-btn nw-primary" type="button" data-act="new-decision">${ ic(
			'plus',
			15
		) }${ esc( __( 'New decision', 'novawork' ) ) }</button></div></div>` +
		`<div class="nw-dec-cols"><section><div class="nw-sec">${ esc( __( 'Open', 'novawork' ) ) }<span class="nw-tnum">${ open.length }</span></div><div class="nw-decs">${
			open.length ? open.map( card ).join( '' ) : `<div class="nw-empty">${ ic( 'scales' ) }${ esc( __( 'No open decisions.', 'novawork' ) ) }</div>`
		}</div></section>` +
		`<section><div class="nw-sec">${ esc( __( 'Decided', 'novawork' ) ) }<span class="nw-tnum">${ done.length }</span></div><div class="nw-decs">${
			done.length ? done.map( card ).join( '' ) : `<div class="nw-empty">${ ic( 'check' ) }${ esc( __( 'Decisions you record appear here, newest first.', 'novawork' ) ) }</div>`
		}</div></section></div>`
	);
}

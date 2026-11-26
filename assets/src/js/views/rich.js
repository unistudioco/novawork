/**
 * Text of a chat message or comment, with @mentions and /links (owner
 * requests). Mentions read as accent text (yours on the soft accent
 * surface); a link is a chip with the item's icon and current title that
 * opens it; a deleted item's chip is struck through. Search matches are
 * marked. Everything else is escaped text.
 */
import { ic } from '../lib/icons.js';
import { __ } from '../lib/i18n.js';
import { esc } from '../lib/format.js';
import { store } from './model.js';

export const REF_ICONS = { task: 'check', decision: 'scales', project: 'map-trifold' };

/** An item's current title, or null when it no longer exists. */
export function refTitle( type, id ) {
	const S = store();
	const row = { task: S.tasks, decision: S.decisions, project: S.projects }[ type ]?.[ id ];
	if ( ! row ) {
		return null;
	}
	return type === 'task' ? row.title : type === 'decision' ? row.question : row.name;
}

function mark( text, q ) {
	const i = q ? text.toLowerCase().indexOf( q ) : -1;
	return i < 0 ? esc( text ) : esc( text.slice( 0, i ) ) + '<mark>' + esc( text.slice( i, i + q.length ) ) + '</mark>' + esc( text.slice( i + q.length ) );
}

function find( text, token ) {
	const out = [];
	const low = text.toLowerCase();
	const t = token.toLowerCase();
	let from = 0;
	let at;
	while ( t && ( at = low.indexOf( t, from ) ) >= 0 ) {
		out.push( at );
		from = at + t.length;
	}
	return out;
}

/**
 * One line of a message for a reply quote: a link reads as its title
 * (without the slash), everything else as typed.
 *
 * @param {Object} m Message or comment (text, refs).
 * @return {string} Plain text.
 */
export function plainText( m ) {
	let text = String( m.text || '' );
	( m.refs || [] ).forEach( ( ref ) => {
		text = text.split( '/' + ref.label ).join( ref.label );
	} );
	return text.replace( /\s+/g, ' ' ).trim();
}

/**
 * @param {Object} m Message or comment (text, mentions, refs).
 * @param {string} q Search text to mark (lower case), or ''.
 * @return {string} HTML.
 */
export function richText( m, q ) {
	const text = String( m.text || '' );
	const S = store();
	const spots = [];
	( m.mentions || [] ).forEach( ( men ) => {
		const token = '@' + men.name;
		find( text, token ).forEach( ( at ) => spots.push( { at, end: at + token.length, html: `<span class="nw-mention${ men.id && men.id === S.me ? ' nw-me' : '' }">${ esc( text.slice( at, at + token.length ) ) }</span>` } ) );
	} );
	( m.refs || [] ).forEach( ( ref ) => {
		const token = '/' + ref.label;
		const title = refTitle( ref.type, ref.id );
		const icon = ic( REF_ICONS[ ref.type ] || 'file', 13 );
		const chip =
			title === null
				? `<span class="nw-ref nw-gone" title="${ esc( __( 'Deleted', 'novawork' ) ) }">${ icon }${ esc( ref.label ) }</span>`
				: `<a class="nw-ref" href="#${ esc( ref.type ) }-${ Number( ref.id ) }" role="button" data-act="open-ref" data-type="${ esc( ref.type ) }" data-id="${ Number( ref.id ) }">${ icon }${ esc( title ) }</a>`;
		find( text, token ).forEach( ( at ) => spots.push( { at, end: at + token.length, html: chip } ) );
	} );
	spots.sort( ( a, b ) => a.at - b.at );
	let html = '';
	let cur = 0;
	spots.forEach( ( s ) => {
		if ( s.at < cur ) {
			return;
		}
		html += mark( text.slice( cur, s.at ), q );
		html += s.html;
		cur = s.end;
	} );
	return html + mark( text.slice( cur ), q );
}

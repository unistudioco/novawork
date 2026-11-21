/**
 * The order members are listed in, set by the admin (setting team_order):
 * dynamic (default): here first (tracking or online), most tracked today
 * first; away members last, also by time. tracked: most tracked today.
 * online: here first in the order they came online; away last.
 * fixed: account order.
 *
 * Shared by Focus "Team today", the team popover and the header strip, so
 * they always agree.
 *
 * @param {Array}    list          Members.
 * @param {Object}   ctx
 * @param {string}   ctx.order     Setting value.
 * @param {Function} ctx.status    uid → 'tracking' | 'online' | 'away'.
 * @param {Function} ctx.today     uid → ms tracked today.
 * @param {number}   ctx.me        Current member id.
 */
export function orderTeam( list, { order, status, today, me } ) {
	if ( order === 'fixed' ) {
		return list.slice();
	}
	const ms = new Map( list.map( ( p ) => [ p.id, today( p.id ) ] ) );
	const away = ( p ) => ( status( p.id ) === 'away' ? 1 : 0 );
	const byTime = ( a, b ) => ms.get( b.id ) - ms.get( a.id );
	const arrived = ( p ) => ( p.id === me && ! p.onlineSince ? 0 : p.onlineSince || Infinity );
	const sorters = {
		dynamic: ( a, b ) => away( a ) - away( b ) || byTime( a, b ),
		tracked: byTime,
		online: ( a, b ) => away( a ) - away( b ) || ( away( a ) ? ( b.lastSeen || 0 ) - ( a.lastSeen || 0 ) : arrived( a ) - arrived( b ) ),
	};
	// Array.prototype.sort is stable: equal members keep account order.
	return list.slice().sort( sorters[ order ] || sorters.dynamic );
}

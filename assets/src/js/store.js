/**
 * The app's data, kept in step with the server by sync.js.
 *
 * Collections are objects keyed by id, exactly as GET /sync sends them.
 * Times are epoch ms; `now` is the server's clock (local clock + offset) so
 * a running timer reads the same on every device.
 */
/*
 * The gap between the server's clock and this device's, outside the reactive
 * store on purpose: it moves a little on every poll, and a reactive value
 * would redraw every view each time.
 */
let offset = 0;
export const serverNow = () => Date.now() + offset;

/** Members' fields that change what the app shows (not "last seen"). */
const memberSig = ( list ) => JSON.stringify( ( list || [] ).map( ( m ) => [ m.id, m.name, m.color, m.avatar, m.online, m.onlineSince ] ) );

/*
 * Rows deleted on this device but not yet on the server (the Undo window).
 * Sync must not bring them back meanwhile.
 */
const hidden = new Set();
export const hide = ( key, id ) => hidden.add( key + ':' + id );
export const unhide = ( key, id ) => hidden.delete( key + ':' + id );

export const COLLECTIONS = [ 'projects', 'tasks', 'decisions', 'comments', 'messages', 'timeEntries', 'notifications' ];

export function createStore( boot ) {
	return {
		me: boot.me || 0,
		locale: boot.locale || undefined,
		members: [],
		agents: [],
		settings: {},
		chat: { unread: 0, readId: 0 },
		projects: {},
		tasks: {},
		decisions: {},
		comments: {},
		messages: {},
		timeEntries: {},
		notifications: {},
		cursor: null,
		now: Date.now(),
		view: 'focus',

		/**
		 * Merge one /sync response.
		 *
		 * @param {Object} payload Sync response.
		 */
		apply( payload ) {
			if ( ! payload ) {
				return;
			}
			if ( payload.full ) {
				COLLECTIONS.forEach( ( key ) => {
					this[ key ] = {};
				} );
			}
			const data = payload.data || {};
			const deleted = payload.deleted || {};
			COLLECTIONS.forEach( ( key ) => {
				( data[ key ] || [] ).forEach( ( row ) => {
					if ( ! hidden.has( key + ':' + row.id ) ) {
						this[ key ][ row.id ] = row;
					}
				} );
				( deleted[ key ] || [] ).forEach( ( id ) => {
					delete this[ key ][ id ];
				} );
			} );
			// Only replace what changed, so an idle poll redraws nothing.
			const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );
			if ( payload.members && memberSig( payload.members ) !== memberSig( this.members ) ) {
				this.members = payload.members;
			}
			if ( payload.agents && ! same( payload.agents, this.agents ) ) {
				this.agents = payload.agents;
			}
			if ( payload.chat && ! same( payload.chat, this.chat ) ) {
				this.chat = payload.chat;
			}
			if ( payload.settings && ! same( payload.settings, this.settings ) ) {
				this.settings = payload.settings;
			}
			if ( payload.cursor !== this.cursor ) {
				this.cursor = payload.cursor;
			}
			if ( payload.now ) {
				offset = payload.now - Date.now();
			}
		},

		/** Put one row into a collection right away (the next poll confirms it). */
		upsert( key, row ) {
			if ( row && this[ key ] ) {
				this[ key ][ row.id ] = row;
			}
		},

		/** Drop one row from a collection. */
		remove( key, id ) {
			if ( this[ key ] ) {
				delete this[ key ][ id ];
			}
		},
	};
}

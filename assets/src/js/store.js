/**
 * The app's data, kept in step with the server by sync.js.
 *
 * Collections are objects keyed by id, exactly as GET /sync sends them.
 * Times are epoch ms; `now` is the server's clock (local clock + offset) so
 * a running timer reads the same on every device.
 */
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
		offset: 0,
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
					this[ key ][ row.id ] = row;
				} );
				( deleted[ key ] || [] ).forEach( ( id ) => {
					delete this[ key ][ id ];
				} );
			} );
			this.members = payload.members || this.members;
			this.agents = payload.agents || this.agents;
			this.chat = payload.chat || this.chat;
			this.settings = payload.settings || this.settings;
			this.cursor = payload.cursor;
			if ( payload.now ) {
				this.offset = payload.now - Date.now();
				this.now = Date.now() + this.offset;
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

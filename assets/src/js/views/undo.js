/**
 * Delete with Undo (owner decision). The item disappears at once and a
 * "Deleted. Undo" toast appears; the server delete is sent only when the
 * toast goes away. Undo puts the item back untouched, so nothing that
 * depends on it (a task's comments, a project's links) is ever lost.
 *
 * Closing the tab during the Undo window still sends the delete (fetch
 * keepalive on pagehide).
 */
import { __ } from '../lib/i18n.js';
import { del } from '../lib/api.js';
import { toast } from '../lib/ui.js';
import { hide, unhide } from '../store.js';
import { store } from './model.js';
import { poll } from './actions.js';

const pending = new Map();

function commit( entry, keepalive ) {
	if ( entry.done ) {
		return;
	}
	entry.done = true;
	pending.delete( entry.token );
	del( entry.path, { keepalive } )
		.then( () => {
			unhide( entry.key, entry.id );
			poll();
		} )
		.catch( ( e ) => {
			// Already gone on the server is fine; anything else brings it back.
			unhide( entry.key, entry.id );
			if ( ! e || e.status !== 404 ) {
				store().upsert( entry.key, entry.row );
				toast( __( 'Could not delete. It’s back where it was.', 'novawork' ), 'x' );
			}
		} );
}

/**
 * @param {string} key  Store collection, e.g. "tasks".
 * @param {number} id   Row id.
 * @param {string} path API path, e.g. "tasks/12".
 */
export function deleteWithUndo( key, id, path ) {
	const S = store();
	const row = S[ key ][ id ];
	if ( ! row ) {
		return;
	}
	const entry = { key, id, path, row, done: false, token: key + ':' + id };
	pending.set( entry.token, entry );
	hide( key, id );
	S.remove( key, id );
	toast( __( 'Deleted.', 'novawork' ), 'trash', {
		label: __( 'Undo', 'novawork' ),
		run: () => {
			if ( entry.done ) {
				return;
			}
			entry.done = true;
			pending.delete( entry.token );
			unhide( key, id );
			S.upsert( key, row );
		},
		settle: () => commit( entry, false ),
	} );
}

window.addEventListener( 'pagehide', () => {
	pending.forEach( ( entry ) => commit( entry, true ) );
} );

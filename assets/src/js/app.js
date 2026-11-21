/**
 * NovaWork app entry.
 *
 * Alpine owns state. The first sync arrives embedded in the page
 * (window.novawork.sync), so the app paints with data, then polls.
 */
import Alpine from 'alpinejs';
import '../css/tokens.css';
import '../css/app.css';
import '../css/additions.css';
import { createStore } from './store.js';
import { startSync } from './sync.js';
import shell from './shell.js';
import { setNonce } from './lib/api.js';

const boot = window.novawork || {};
const store = createStore( boot );

Alpine.store( 'nw', store );
const live = Alpine.store( 'nw' );
if ( boot.sync ) {
	setNonce( boot.sync.nonce );
	live.apply( boot.sync );
}

const sync = startSync( live );
Alpine.data( 'nwApp', () => shell( sync ) );

window.Alpine = Alpine;
Alpine.start();

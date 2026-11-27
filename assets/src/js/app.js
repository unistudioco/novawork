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
import '../css/tooltip.css';
import { createStore } from './store.js';
import { startSync } from './sync.js';
import shell from './shell.js';
import { mountViews } from './views/index.js';
import { setNonce } from './lib/api.js';
import { mountTooltips } from './lib/tooltip.js';
import { mountWebApp } from './views/webapp.js';
import { announceReady } from './lib/ext.js';

const boot = window.novawork || {};
// First, so the browser's install offer is never missed.
mountWebApp();
const store = createStore( boot );

Alpine.store( 'nw', store );
const live = Alpine.store( 'nw' );
if ( boot.sync ) {
	setNonce( boot.sync.nonce );
	live.apply( boot.sync );
}

const sync = startSync( live );
Alpine.data( 'nwApp', () => shell() );

window.Alpine = Alpine;
Alpine.start();
// Alpine reveals the page (x-cloak) in a microtask; draw the views on the
// next frame, once things have a size (scroll positions, tab indicators).
// Then add-ons hear that the app is ready (nw:ready, 1.3.0).
requestAnimationFrame( () => {
	mountViews( sync );
	announceReady( live );
} );
mountTooltips();

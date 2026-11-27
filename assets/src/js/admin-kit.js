/**
 * Tools for an add-on's page under the NovaWork menu (1.3.0), so it looks and
 * behaves like NovaWork → Settings without copying its code.
 *
 * The page enqueues its script with "novawork-admin-kit" as a dependency and
 * calls SettingsPage::enqueue_styles() for the look. The kit puts its tools
 * at window.novawork.admin and fires "nw:admin-ready" on window with the same
 * object: Alpine (register x-data with Alpine.data()), toast( message, icon ),
 * ic/icf( name, size ) for NovaWork's icons, and enhance( root ), which turns
 * each select[data-nw-sel] under root into NovaWork's dropdown.
 *
 * Alpine starts once the page has loaded, after every script printed with
 * it has run, so an add-on registers its data first. NovaWork's tooltips
 * show inside the page's .nw-admin, as in the app: for anything with a
 * title or data-tip, and for icon-only buttons from their aria-label.
 */
import Alpine from 'alpinejs';
import { ic, icf } from './lib/icons.js';
import { toast } from './lib/ui.js';
import { enhance } from './views/select.js';
import { mountTooltips } from './lib/tooltip.js';

const kit = Object.freeze( { Alpine, toast, ic, icf, enhance } );

window.novawork = window.novawork || {};
window.novawork.admin = kit;
window.dispatchEvent( new CustomEvent( 'nw:admin-ready', { detail: kit } ) );

mountTooltips( '.nw-admin' );

let started = false;
const start = () => {
	if ( ! started ) {
		started = true;
		Alpine.start();
	}
};
if ( 'complete' === document.readyState ) {
	start();
} else {
	// Module scripts run before DOMContentLoaded; "load" covers a kit added later.
	document.addEventListener( 'DOMContentLoaded', start, { once: true } );
	window.addEventListener( 'load', start, { once: true } );
}

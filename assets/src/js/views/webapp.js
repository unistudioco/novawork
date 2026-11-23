/**
 * The installable app (owner request, 1.1.0).
 *
 * Registers the app worker (assets/app-worker.js, served by App\WebApp),
 * which only shows a "You're offline" page when the workspace opens without
 * a connection. With the setting off it removes a worker it registered
 * before.
 *
 * Installing: browsers that offer it (Chrome, Edge, Android) send
 * `beforeinstallprompt`; the team menu then shows "Install app", which opens
 * the browser's own install dialog. Safari has no such event: on iPhone and
 * iPad the menu shows "Add to Home Screen", on a Mac "Add to Dock", and a
 * toast says where that is. Nothing shows once the app is installed and
 * open, or in a browser that cannot install it.
 */
import { __ } from '../lib/i18n.js';
import { toast } from '../lib/ui.js';

const WORKER_MARK = 'novawork=app-worker';
let deferred = null;

const boot = () => ( window.novawork || {} ).webapp || {};

const standalone = () => window.matchMedia( '(display-mode: standalone)' ).matches || window.navigator.standalone === true;

const ua = () => window.navigator.userAgent;
const isIos = () => /iPhone|iPad|iPod/.test( ua() ) || ( /Macintosh/.test( ua() ) && window.navigator.maxTouchPoints > 1 );
const isMacSafari = () => /Macintosh/.test( ua() ) && window.navigator.maxTouchPoints <= 1 && /Safari\//.test( ua() ) && ! /Chrome|Chromium|Edg|OPR|Firefox/.test( ua() );

/** How this browser installs the workspace: 'prompt', 'ios', 'mac' or '' (it can't, or it is already the app). */
export function installWay() {
	if ( ! boot().worker || standalone() ) {
		return '';
	}
	if ( deferred ) {
		return 'prompt';
	}
	if ( isIos() ) {
		return 'ios';
	}
	return isMacSafari() ? 'mac' : '';
}

const announce = () => window.dispatchEvent( new CustomEvent( 'nw:install', { detail: { way: installWay() } } ) );

/** The menu's "Install app" row. */
export async function installApp() {
	const way = installWay();
	if ( way === 'prompt' ) {
		const e = deferred;
		// A prompt works once; the browser sends a new event if it can ask again.
		deferred = null;
		announce();
		await e.prompt();
		return;
	}
	if ( way === 'ios' ) {
		toast( __( 'Tap Share, then Add to Home Screen.', 'novawork' ), 'download-simple', { label: __( 'Got it', 'novawork' ), run() {} } );
	} else if ( way === 'mac' ) {
		toast( __( 'In Safari, choose File, then Add to Dock.', 'novawork' ), 'download-simple', { label: __( 'Got it', 'novawork' ), run() {} } );
	}
}

/**
 * Point the window colour (theme-color, printed by App\WebApp::head) at the
 * chosen appearance; 'system' follows the device again.
 *
 * @param {string} key 'light', 'dark' or 'system'.
 */
export function themeColor( key ) {
	document.querySelectorAll( 'meta[name="theme-color"][data-media]' ).forEach( ( m ) => {
		if ( key === 'light' || key === 'dark' ) {
			m.removeAttribute( 'media' );
			m.content = m.dataset[ key ];
		} else {
			m.setAttribute( 'media', m.dataset.media );
			m.content = m.dataset.media.includes( 'dark' ) ? m.dataset.dark : m.dataset.light;
		}
	} );
}

export function mountWebApp() {
	window.addEventListener( 'beforeinstallprompt', ( e ) => {
		// Offered from the team menu instead of the browser's own banner.
		e.preventDefault();
		deferred = e;
		announce();
	} );
	window.addEventListener( 'appinstalled', () => {
		deferred = null;
		announce();
	} );

	if ( ! ( 'serviceWorker' in navigator ) || ! window.isSecureContext ) {
		return;
	}
	const { worker, scope } = boot();
	if ( worker ) {
		navigator.serviceWorker.register( worker, { scope } ).catch( () => {} );
		return;
	}
	// Turned off: take ours away (the alerts worker stays).
	navigator.serviceWorker
		.getRegistrations()
		.then( ( list ) =>
			list.forEach( ( reg ) => {
				const w = reg.active || reg.waiting || reg.installing;
				if ( w && w.scriptURL.includes( WORKER_MARK ) ) {
					reg.unregister();
				}
			} )
		)
		.catch( () => {} );
}

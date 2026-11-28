// Before the whole browser run: add-ons that require NovaWork are switched
// off (see below), and the test members have "seen" the welcome
// tour, so it does not start by itself on top of every test. The tour's own
// test (tour.spec.js) resets it for one member on purpose.
import { execFileSync } from 'node:child_process';
import { existsSync, lstatSync, mkdirSync, symlinkSync } from 'node:fs';
import { resolve } from 'node:path';
import { wpEval } from './helpers.js';

/** Where the site's mu-plugins are, and the offline fixture's link there. */
export const OFFLINE = resolve( process.env.NOVAWORK_WP_PATH || '', 'wp-content/mu-plugins/novawork-test-offline.php' );

export default function setup() {
	// NovaWork alone: plugins that require it are off for the run, back on in
	// teardown.js (bin/addons.sh keeps the list if a run is interrupted).
	execFileSync( 'bash', [ 'bin/addons.sh', 'off' ], { stdio: 'inherit' } );
	// No request leaves the machine (offline.js for pages; this mu-plugin for
	// the site). Avatars (Gravatar) and Fontshare are off for the run, restored after.
	const mu = resolve( OFFLINE, '..' );
	if ( ! existsSync( mu ) ) {
		mkdirSync( mu );
	}
	if ( ! lstatSync( OFFLINE, { throwIfNoEntry: false } ) ) {
		symlinkSync( resolve( 'tests/fixtures/test-offline.php' ), OFFLINE );
	}
	wpEval( "if ( false === get_option( 'novawork_test_offline_saved' ) ) { $s = (array) get_option( NovaWork\\Settings::OPTION, array() ); update_option( 'novawork_test_offline_saved', array( 'show_avatars' => get_option( 'show_avatars' ), 'load_fontshare' => $s['load_fontshare'] ?? null ), false ); } update_option( 'show_avatars', '0' ); $s = (array) get_option( NovaWork\\Settings::OPTION, array() ); $s['load_fontshare'] = false; update_option( NovaWork\\Settings::OPTION, $s ); update_option( 'novawork_test_offline', '1', false ); delete_option( 'novawork_test_outside' );" );
	const logins = [ process.env.NOVAWORK_USER_A || 'alex:alex', process.env.NOVAWORK_USER_B || 'sam:sam', process.env.NOVAWORK_ADMIN || 'admin:admin' ].map( ( x ) => x.split( ':' )[ 0 ] );
	wpEval( `foreach ( ${ JSON.stringify( logins ) .replace( /^\[/, 'array(' ).replace( /\]$/, ')' ) } as $login ) { $u = get_user_by( 'login', $login ); if ( $u ) { update_user_meta( $u->ID, NovaWork\\Rest\\TourController::SEEN_META, time() ); } }` );
}

// Before the whole browser run: add-ons that require NovaWork are switched
// off (see below), and the test members have "seen" the welcome
// tour, so it does not start by itself on top of every test. The tour's own
// test (tour.spec.js) resets it for one member on purpose.
import { execFileSync } from 'node:child_process';
import { wpEval } from './helpers.js';

export default function setup() {
	// NovaWork alone: plugins that require it are off for the run, back on in
	// teardown.js (bin/addons.sh keeps the list if a run is interrupted).
	execFileSync( 'bash', [ 'bin/addons.sh', 'off' ], { stdio: 'inherit' } );
	const logins = [ process.env.NOVAWORK_USER_A || 'alex:alex', process.env.NOVAWORK_USER_B || 'sam:sam', process.env.NOVAWORK_ADMIN || 'admin:admin' ].map( ( x ) => x.split( ':' )[ 0 ] );
	wpEval( `foreach ( ${ JSON.stringify( logins ) .replace( /^\[/, 'array(' ).replace( /\]$/, ')' ) } as $login ) { $u = get_user_by( 'login', $login ); if ( $u ) { update_user_meta( $u->ID, NovaWork\\Rest\\TourController::SEEN_META, time() ); } }` );
}

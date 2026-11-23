// Before the whole browser run: the test members have "seen" the welcome
// tour, so it does not start by itself on top of every test. The tour's own
// test (tour.spec.js) resets it for one member on purpose.
import { wpEval } from './helpers.js';

export default function setup() {
	const logins = [ process.env.NOVAWORK_USER_A || 'alex:alex', process.env.NOVAWORK_USER_B || 'sam:sam', process.env.NOVAWORK_ADMIN || 'admin:admin' ].map( ( x ) => x.split( ':' )[ 0 ] );
	wpEval( `foreach ( ${ JSON.stringify( logins ) .replace( /^\[/, 'array(' ).replace( /\]$/, ')' ) } as $login ) { $u = get_user_by( 'login', $login ); if ( $u ) { update_user_meta( $u->ID, NovaWork\\Rest\\TourController::SEEN_META, time() ); } }` );
}

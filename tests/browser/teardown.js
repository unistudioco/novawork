// After the whole browser run: delete the test items a stopped or failed
// test left behind, then the team activity rows the tests made (tasks and
// decisions they created and deleted still left feed entries), make sure
// the test add-on (addons.spec.js) is gone even if that test stopped
// halfway, and switch back on the add-ons setup.js switched off.
import { execFileSync } from 'node:child_process';
import { lstatSync, unlinkSync } from 'node:fs';
import { resolve } from 'node:path';
import { cleanActivity, cleanLeftovers, wpEval } from './helpers.js';
import { OFFLINE } from './setup.js';

export default function teardown() {
	const link = resolve( process.env.NOVAWORK_WP_PATH || '', 'wp-content/mu-plugins/novawork-test-addon.php' );
	if ( lstatSync( link, { throwIfNoEntry: false } ) ) {
		unlinkSync( link );
	}
	wpEval( "delete_option( 'novawork_test_addon' );" );
	cleanLeftovers();
	cleanActivity();
	execFileSync( 'bash', [ 'bin/addons.sh', 'on' ], { stdio: 'inherit' } );
	// The site's own outgoing requests during the run (test-offline.php).
	const outside = JSON.parse( wpEval( "echo wp_json_encode( array_values( (array) get_option( 'novawork_test_outside', array() ) ) );" ) || '[]' );
	wpEval( "$saved = get_option( 'novawork_test_offline_saved' ); if ( is_array( $saved ) ) { update_option( 'show_avatars', $saved['show_avatars'] ); $s = (array) get_option( NovaWork\\Settings::OPTION, array() ); if ( null === $saved['load_fontshare'] ) { unset( $s['load_fontshare'] ); } else { $s['load_fontshare'] = $saved['load_fontshare']; } update_option( NovaWork\\Settings::OPTION, $s ); } delete_option( 'novawork_test_offline_saved' ); delete_option( 'novawork_test_offline' ); delete_option( 'novawork_test_outside' );" );
	if ( lstatSync( OFFLINE, { throwIfNoEntry: false } ) ) {
		unlinkSync( OFFLINE );
	}
	if ( outside.length ) {
		throw new Error( 'The site made requests outside itself during the browser run: ' + outside.join( ', ' ) );
	}
}

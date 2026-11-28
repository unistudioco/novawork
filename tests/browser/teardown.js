// After the whole browser run: delete the test items a stopped or failed
// test left behind, then the team activity rows the tests made (tasks and
// decisions they created and deleted still left feed entries), make sure
// the test add-on (addons.spec.js) is gone even if that test stopped
// halfway, and switch back on the add-ons setup.js switched off.
import { execFileSync } from 'node:child_process';
import { lstatSync, unlinkSync } from 'node:fs';
import { resolve } from 'node:path';
import { cleanActivity, cleanLeftovers, wpEval } from './helpers.js';

export default function teardown() {
	const link = resolve( process.env.NOVAWORK_WP_PATH || '', 'wp-content/mu-plugins/novawork-test-addon.php' );
	if ( lstatSync( link, { throwIfNoEntry: false } ) ) {
		unlinkSync( link );
	}
	wpEval( "delete_option( 'novawork_test_addon' );" );
	cleanLeftovers();
	cleanActivity();
	// The site's own outgoing requests during the run (bin/offline.sh), then
	// the site as it was, then the add-ons back on.
	const outside = JSON.parse( execFileSync( 'bash', [ 'bin/offline.sh', 'outside' ] ).toString().trim() || '[]' );
	execFileSync( 'bash', [ 'bin/offline.sh', 'off', '--quiet' ], { stdio: 'inherit' } );
	execFileSync( 'bash', [ 'bin/addons.sh', 'on' ], { stdio: 'inherit' } );
	if ( outside.length ) {
		throw new Error( 'The site made requests outside itself during the browser run: ' + outside.join( ', ' ) );
	}
}

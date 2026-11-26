// After the whole browser run: remove the team activity rows the tests made
// (tasks and decisions they created and deleted still left feed entries),
// and make sure the test add-on (addons.spec.js) is gone even if that test
// stopped halfway.
import { lstatSync, unlinkSync } from 'node:fs';
import { resolve } from 'node:path';
import { cleanActivity, wpEval } from './helpers.js';

export default function teardown() {
	const link = resolve( process.env.NOVAWORK_WP_PATH || '', 'wp-content/mu-plugins/novawork-test-addon.php' );
	if ( lstatSync( link, { throwIfNoEntry: false } ) ) {
		unlinkSync( link );
	}
	wpEval( "delete_option( 'novawork_test_addon' );" );
	cleanActivity();
}

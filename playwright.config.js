// Browser tests against the dev site (see .dev.env). Uses the installed
// Chrome, so no browser download is needed: npm run test:browser
import { defineConfig } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';

// Read NOVAWORK_* settings from .dev.env (shell-style KEY="value" lines).
if ( existsSync( '.dev.env' ) ) {
	for ( const line of readFileSync( '.dev.env', 'utf8' ).split( '\n' ) ) {
		const m = line.match( /^\s*(NOVAWORK_[A-Z_]+)="?([^"]*)"?\s*$/ );
		if ( m && ! process.env[ m[ 1 ] ] ) {
			process.env[ m[ 1 ] ] = m[ 2 ].replace( /\$HOME/g, process.env.HOME || '' );
		}
	}
}

export default defineConfig( {
	testDir: 'tests/browser',
	testMatch: '*.spec.js',
	globalTeardown: './tests/browser/teardown.js',
	workers: 1,
	timeout: 60000,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.NOVAWORK_URL || 'http://novawork.local',
		channel: 'chrome',
		viewport: { width: 1366, height: 1500 },
	},
} );

/**
 * Write the WordPress Playground blueprint for the plugin directory's Live
 * Preview: .wordpress-org/blueprints/blueprint.json (it goes to the SVN
 * assets folder as assets/blueprints/blueprint.json).
 *
 * The demo is the same generic studio the dev site uses (tests/seed/
 * dev-data.php), so there is one set of demo content: the visitor is signed
 * in as "Alex" (the administrator, so the settings screen works too), with a
 * teammate, Sam, five projects, tasks, decisions, chat and tracked time.
 * No demo mode inside the plugin (owner decision 2026-11-22).
 *
 *   node bin/blueprint.mjs
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

const seed = readFileSync( 'tests/seed/dev-data.php', 'utf8' )
	// Playground runs plain PHP, not WP-CLI: stand-ins for its two calls.
	.replace( /WP_CLI::error\(/g, 'novawork_demo_fail(' )
	.replace( /WP_CLI::success\(/g, 'novawork_demo_done(' )
	.replace(
		/^<\?php\n/,
		'<?php\nfunction novawork_demo_fail( $m ) { throw new RuntimeException( $m ); }\nfunction novawork_demo_done( $m ) { echo $m; }\n'
	)
	// The visitor is the administrator, shown as Alex.
	.replace( "get_user_by( 'login', 'alex' )", "get_user_by( 'login', 'admin' )" );
if ( /WP_CLI/.test( seed ) ) {
	throw new Error( 'The seed still calls WP_CLI; teach bin/blueprint.mjs about it.' );
}

const php = ( code ) => '<?php\nrequire_once \'/wordpress/wp-load.php\';\n' + code.trim() + '\n';

const blueprint = {
	$schema: 'https://playground.wordpress.net/blueprint-schema.json',
	meta: {
		title: 'NovaWork demo workspace',
		description: 'A small studio’s workspace with projects, tasks, subtasks, decisions, chat and tracked time. You are signed in as Alex.',
		author: 'unistudio',
		categories: [ 'Project management' ],
	},
	landingPage: '/novawork/',
	preferredVersions: { php: '8.3', wp: 'latest' },
	features: { networking: true },
	steps: [
		// Pretty links before the plugin is activated, so its address is ready.
		{ step: 'setSiteOptions', options: { blogname: 'Studio', permalink_structure: '/%postname%/' } },
		{ step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'novawork' }, options: { activate: true } },
		{
			step: 'runPHP',
			code: php( `
wp_update_user( array( 'ID' => 1, 'display_name' => 'Alex', 'first_name' => 'Alex' ) );
if ( ! get_user_by( 'login', 'sam' ) ) {
	wp_insert_user( array( 'user_login' => 'sam', 'user_pass' => wp_generate_password(), 'user_email' => 'sam@example.com', 'display_name' => 'Sam', 'first_name' => 'Sam', 'role' => 'novawork_member' ) );
}
// The demo has its own content, so no first-run sample items.
update_option( 'novawork_samples', 'made', false );
` ),
		},
		{ step: 'writeFile', path: '/tmp/novawork-demo.php', data: seed },
		{ step: 'runPHP', code: php( "require '/tmp/novawork-demo.php';\nflush_rewrite_rules();" ) },
		{ step: 'login', username: 'admin' },
	],
};

mkdirSync( '.wordpress-org/blueprints', { recursive: true } );
writeFileSync( '.wordpress-org/blueprints/blueprint.json', JSON.stringify( blueprint, null, '\t' ) + '\n' );
console.log( 'Wrote .wordpress-org/blueprints/blueprint.json' );

<?php
/**
 * NovaWork API integration tests.
 *
 * Runs inside a real WordPress through WP-CLI and drives the REST API the
 * way the app does (rest_do_request with JSON bodies), as different users.
 * Everything happens in one database transaction that is rolled back at the
 * end, so the dev site keeps no test data. Files written to the private
 * folder are removed afterwards.
 *
 * Run: bin/test-api.sh  (or: wp eval-file tests/api/run.php)
 *
 * Needs two members (alex, sam) on the site; see CLAUDE.md.
 *
 * @package NovaWork
 */

// phpcs:disable -- Test script, not shipped (see .distignore).

use NovaWork\Data\Db;
use NovaWork\Data\Repository;
use NovaWork\Data\TaskRepository;
use NovaWork\Data\TimeEntryRepository;
use NovaWork\Install\Schema;
use NovaWork\Services\Files;
use NovaWork\Services\Notifier;

if ( ! defined( 'WP_CLI' ) || ! class_exists( Schema::class ) ) {
	echo "Run with: wp eval-file tests/api/run.php (NovaWork must be active)\n";
	return;
}

final class NovaWork_Api_Test {
	public int $pass = 0;
	/** @var list<string> */
	public array $fail = array();
	public string $section = '';

	public function section( string $name ): void {
		$this->section = $name;
		WP_CLI::log( "\n" . $name );
	}

	public function ok( bool $cond, string $label, mixed $detail = null ): void {
		if ( $cond ) {
			++$this->pass;
			WP_CLI::log( '  ✓ ' . $label );
			return;
		}
		$this->fail[] = $this->section . ' › ' . $label;
		WP_CLI::log( '  ✗ ' . $label . ( null === $detail ? '' : '  → ' . wp_json_encode( $detail ) ) );
	}

	/**
	 * Call the API as a user. Returns [status, data].
	 *
	 * @return array{0:int,1:mixed}
	 */
	public function call( int $user, string $method, string $route, array $params = array() ): array {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( $method, '/novawork/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );
		return array( $response->get_status(), json_decode( (string) wp_json_encode( $data ), true ) );
	}
}

global $wpdb;
$t = new NovaWork_Api_Test();

$alex  = (int) ( get_user_by( 'login', 'alex' )->ID ?? 0 );
$sam   = (int) ( get_user_by( 'login', 'sam' )->ID ?? 0 );
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
if ( ! $alex || ! $sam || ! $admin ) {
	WP_CLI::error( 'Needs users alex and sam (NovaWork members) and an administrator.' );
}

$files_before = glob( Files::dir() . '/*' ) ?: array();
$wpdb->query( 'START TRANSACTION' );

try {
	rest_get_server();
	$outsider = wp_insert_user(
		array(
			'user_login' => 'nw_outsider_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);

	// ------------------------------------------------------------------
	$t->section( 'Schema' );
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$pending = dbDelta( Schema::statements(), false );
	$t->ok( array() === $pending, 'dbDelta has nothing left to change (schema is idempotent)', $pending );
	$t->ok( count( Db::TABLES ) === count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'novawork_' ) . '%' ) ) ), 'all ' . count( Db::TABLES ) . ' tables exist' );

	// ------------------------------------------------------------------
	$t->section( 'Access' );
	list( $s ) = $t->call( 0, 'GET', '/sync' );
	$t->ok( 401 === $s, 'signed out → 401', $s );
	list( $s ) = $t->call( (int) $outsider, 'GET', '/sync' );
	$t->ok( 403 === $s, 'signed in but not a member → 403', $s );
	list( $s ) = $t->call( (int) $outsider, 'POST', '/tasks', array( 'title' => 'Nope' ) );
	$t->ok( 403 === $s, 'non-member cannot write → 403', $s );
	list( $s ) = $t->call( $admin, 'GET', '/sync' );
	$t->ok( 200 === $s, 'administrator is a member → 200', $s );
	$routes   = rest_get_server()->get_routes( 'novawork/v1' );
	$open     = array();
	foreach ( $routes as $path => $handlers ) {
		foreach ( $handlers as $h ) {
			if ( empty( $h['permission_callback'] ) || '__return_true' === $h['permission_callback'] ) {
				$open[] = $path;
			}
		}
	}
	$open = array_diff( $open, array( '/novawork/v1' ) ); // Core's namespace index, which only lists routes.
	$t->ok( array() === $open, 'every route has a real permission callback (' . count( $routes ) . ' routes)', $open );

	// ------------------------------------------------------------------
	$t->section( 'Sync: full snapshot' );
	list( $s, $full ) = $t->call( $alex, 'GET', '/sync' );
	$t->ok( 200 === $s && true === $full['full'], 'first load (no cursor) returns a full snapshot' );
	$ids = array_column( $full['members'], 'id' );
	$t->ok( in_array( $alex, $ids, true ) && in_array( $sam, $ids, true ) && in_array( $admin, $ids, true ), 'members are alex, sam and the admin' );
	$t->ok( ! in_array( (int) $outsider, $ids, true ), 'a subscriber is not a member' );
	$me = $full['members'][ array_search( $alex, $ids, true ) ];
	$t->ok( in_array( $me['color'], array( 'violet', 'teal', 'amber', 'rose', 'sky', 'lime', 'red', 'gold' ), true ), 'member gets an accent colour', $me['color'] );
	$t->ok( true === $me['online'], 'polling marks the member online' );
	$t->ok( is_int( $me['onlineSince'] ) && $me['onlineSince'] <= $full['now'], 'members carry when they came online (for the "first online" order)', $me['onlineSince'] );
	$t->ok( in_array( $full['settings']['teamOrder'], array( 'dynamic', 'tracked', 'online', 'fixed' ), true ) && 3 === $full['settings']['nowLimit'], 'sync sends the team order and the Now limit settings', $full['settings'] );
	$t->ok( 'votes' === $full['settings']['voteOrder'] && 'votes' === NovaWork\Settings::clean( 'vote_order', 'votes' ) && is_wp_error( NovaWork\Settings::clean( 'vote_order', 'random' ) ), 'vote options: most votes first by default; only votes|written accepted', $full['settings']['voteOrder'] ?? null );
	$t->ok( 8 === $full['settings']['doneShown'] && 100 === NovaWork\Settings::clean( 'done_shown', 500 ), 'sync sends how many Done tasks the board shows (8; capped at 100)', $full['settings']['doneShown'] ?? null );
	$t->ok( is_string( $full['nonce'] ) && is_int( $full['cursor'] ), 'nonce and cursor come back' );

	// Activity across devices (owner report, 2026-11-23): each app reports its
	// last activity with its poll; the latest on any device comes back.
	$now_ms = NovaWork\Data\Db::now_ms();
	delete_user_meta( $alex, NovaWork\Members::ACTIVE_META );
	list( , $act ) = $t->call( $alex, 'GET', '/sync', array( 'active' => $now_ms - 5000 ) );
	$t->ok( abs( $act['activeAt'] - ( $now_ms - 5000 ) ) < 2, 'a poll reports the app\'s last activity; activeAt brings back the latest', $act['activeAt'] ?? null );
	list( , $act ) = $t->call( $alex, 'GET', '/sync', array( 'active' => $now_ms - 60000 ) );
	$t->ok( abs( $act['activeAt'] - ( $now_ms - 5000 ) ) < 2, 'an older report from another device never moves it back', $act['activeAt'] ?? null );
	list( , $act ) = $t->call( $alex, 'GET', '/sync', array( 'active' => $now_ms + 3600000 ) );
	$t->ok( $act['activeAt'] <= NovaWork\Data\Db::now_ms(), 'a report from the future is held to now', $act['activeAt'] ?? null );
	delete_user_meta( $sam, NovaWork\Members::ACTIVE_META );
	list( , $act ) = $t->call( $sam, 'GET', '/sync' );
	$t->ok( 0 === $act['activeAt'], 'each member hears only their own activity', $act['activeAt'] ?? null );

	// Installable app (1.1.0).
	$manifest = NovaWork\App\WebApp::manifest();
	$t->ok( true === NovaWork\Settings::get( 'installable' ) && 'standalone' === $manifest['display'] && NovaWork\Settings::workspace_name() === $manifest['name'], 'installable by default: the manifest names the workspace and opens it standalone', $manifest );
	$t->ok( 0 === strpos( $manifest['start_url'], $manifest['scope'] ) && 0 === strpos( NovaWork\App\Route::login_url(), $manifest['scope'] ), 'the scope covers the workspace and its sign-in page', $manifest['scope'] );
	$png = NovaWork\App\WebApp::icon( 'any-192' );
	$size = is_string( $png ) ? getimagesizefromstring( $png ) : false;
	$t->ok( is_array( $size ) && 192 === $size[0] && 192 === $size[1] && 'image/png' === $size['mime'], 'icons are made at their size as PNG', $size );
	$t->ok( false !== strpos( NovaWork\App\WebApp::worker(), 'const NOVAWORK = ' ), 'the app worker carries its pages and offline page' );
	foreach ( array( 'projects', 'tasks', 'decisions', 'comments', 'messages', 'timeEntries', 'notifications' ) as $k ) {
		$t->ok( isset( $full['data'][ $k ] ) && is_array( $full['data'][ $k ] ), "snapshot has $k" );
	}
	$cursor = $full['cursor'];

	// ------------------------------------------------------------------
	$t->section( 'Projects' );
	list( $s ) = $t->call( $alex, 'POST', '/projects', array() );
	$t->ok( 400 === $s, 'name is required', $s );
	list( $s ) = $t->call( $alex, 'POST', '/projects', array( 'name' => str_repeat( 'x', 81 ) ) );
	$t->ok( 400 === $s, 'name over 80 characters → 400', $s );
	list( $s ) = $t->call( $alex, 'POST', '/projects', array( 'name' => 'P', 'color' => 'chartreuse' ) );
	$t->ok( 400 === $s, 'unknown colour → 400', $s );
	list( $s ) = $t->call( $alex, 'POST', '/projects', array( 'name' => 'P', 'start' => '2026-02-30' ) );
	$t->ok( 400 === $s, 'impossible date → 400', $s );
	list( $s, $p1 ) = $t->call( $alex, 'POST', '/projects', array( 'name' => '  Launch <b>site</b> ', 'color' => 'teal', 'goal' => "Line one\nLine two", 'target' => '2027-01-31' ) );
	$t->ok( 201 === $s && 'Launch site' === $p1['name'], 'created; tags stripped, trimmed', $p1 );
	$t->ok( "Line one\nLine two" === $p1['goal'], 'goal keeps its line breaks' );
	$t->ok( 'active' === $p1['status'] && '2027-01-31' === $p1['target'] && '' === $p1['deadline'], 'defaults and dates' );
	list( , $p2 ) = $t->call( $sam, 'POST', '/projects', array( 'name' => 'Second' ) );
	$t->ok( $p2['order'] > $p1['order'], 'new project goes last' );
	list( $s, $p1b ) = $t->call( $alex, 'PATCH', '/projects/' . $p1['id'], array( 'status' => 'paused', 'deadline' => '' ) );
	$t->ok( 200 === $s && 'paused' === $p1b['status'] && 'Launch site' === $p1b['name'], 'PATCH changes only what was sent' );
	list( , $moved ) = $t->call( $sam, 'POST', '/projects/' . $p2['id'] . '/move', array( 'beforeId' => $p1['id'] ) );
	$t->ok( $moved['order'] < $p1['order'], 'move before another project' );
	list( $s ) = $t->call( $alex, 'PATCH', '/projects/999999', array( 'name' => 'x' ) );
	$t->ok( 404 === $s, 'unknown project → 404', $s );

	// ------------------------------------------------------------------
	$t->section( 'Tasks' );
	list( $s ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'x', 'projectId' => 999999 ) );
	$t->ok( 400 === $s, 'unknown project → 400', $s );
	list( $s ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'x', 'status' => 'someday' ) );
	$t->ok( 400 === $s, 'unknown status → 400', $s );
	list( $s ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => str_repeat( 'x', 201 ) ) );
	$t->ok( 400 === $s, 'title over 200 characters → 400', $s );
	list( $s, $mine ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'Mine by default' ) );
	$t->ok( 201 === $s && array( $alex ) === $mine['assignees'] && 'next' === $mine['status'], 'defaults: assigned to the creator, in Next', $mine );
	list( $s, $t1 ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'Write the brief', 'projectId' => $p1['id'], 'assignees' => array( $sam, (int) $outsider, $sam ), 'priority' => 'high' ) );
	$t->ok( array( $sam ) === $t1['assignees'], 'non-members and duplicates are dropped from assignees', $t1['assignees'] );
	$sam_notes = ( new NovaWork\Data\NotificationRepository() )->recent_for( $sam, 50 );
	$assign    = array_values( array_filter( $sam_notes, fn( $n ) => 'assign' === $n['type'] && (int) $n['object_id'] === $t1['id'] ) );
	$t->ok( 1 === count( $assign ) && (int) $assign[0]['actor_id'] === $alex, 'sam is notified of the assignment' );
	$alex_assign = array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $alex, 50 ), fn( $n ) => 'assign' === $n['type'] && (int) $n['object_id'] === $mine['id'] );
	$t->ok( ! $alex_assign, 'assigning yourself does not notify you' );

	list( , $t2 ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'Second' ) );
	list( , $t3 ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'Third' ) );
	$t->ok( $t1['order'] < $t2['order'] && $t2['order'] < $t3['order'], 'new tasks go to the end of their column' );
	list( , $m ) = $t->call( $alex, 'POST', '/tasks/' . $t3['id'] . '/move', array( 'beforeId' => $t1['id'] ) );
	$repo  = new TaskRepository();
	$order = array_column( array_filter( $repo->present_many( $repo->all() ), fn( $x ) => 'next' === $x['status'] && in_array( $x['id'], array( $t1['id'], $t2['id'], $t3['id'] ), true ) ), 'id' );
	$t->ok( array( $t3['id'], $t1['id'], $t2['id'] ) === array_values( $order ), 'drag: Third moved before the first task', $order );

	// Force neighbours with no room between them, then drop between them.
	$repo->update( $t1['id'], array( 'sort_order' => 5 ) );
	$repo->update( $t2['id'], array( 'sort_order' => 6 ) );
	$repo->update( $t3['id'], array( 'sort_order' => 7 ) );
	$t->call( $alex, 'POST', '/tasks/' . $t3['id'] . '/move', array( 'beforeId' => $t2['id'] ) );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, sort_order FROM %i WHERE id IN (%d,%d,%d) ORDER BY sort_order', $repo->table_name(), $t1['id'], $t2['id'], $t3['id'] ), ARRAY_A );
	$t->ok( array( $t1['id'], $t3['id'], $t2['id'] ) === array_map( 'intval', array_column( $rows, 'id' ) ), 'no room left → the column is renumbered and the order holds' );

	list( , $nowt ) = $t->call( $alex, 'POST', '/tasks/' . $t2['id'] . '/move', array( 'status' => 'now', 'beforeId' => null ) );
	$t->ok( 'now' === $nowt['status'] && null === $nowt['doneAt'], 'move to another column' );
	list( , $done ) = $t->call( $alex, 'POST', '/tasks/' . $t2['id'] . '/move', array( 'status' => 'done' ) );
	$t->ok( 'done' === $done['status'] && is_int( $done['doneAt'] ), 'moving to Done stamps doneAt' );
	list( , $back ) = $t->call( $alex, 'PATCH', '/tasks/' . $t2['id'], array( 'status' => 'later' ) );
	$t->ok( 'later' === $back['status'] && null === $back['doneAt'], 'moving out of Done clears doneAt' );
	list( $s ) = $t->call( $alex, 'PATCH', '/tasks/' . $t2['id'], array( 'title' => '   ' ) );
	$t->ok( 400 === $s, 'blank title → 400', $s );
	list( , $un ) = $t->call( $alex, 'PATCH', '/tasks/' . $t1['id'], array( 'assignees' => array() ) );
	$t->ok( array() === $un['assignees'], 'assignees can be cleared' );
	list( , $keep ) = $t->call( $alex, 'PATCH', '/tasks/' . $t1['id'], array( 'notes' => 'Only notes' ) );
	$t->ok( 'Write the brief' === $keep['title'] && 'high' === $keep['priority'] && $p1['id'] === $keep['projectId'], 'PATCH without a field leaves it alone' );

	// ------------------------------------------------------------------
	$t->section( 'Edit rights (owner, 1.2.0)' );
	$t->ok( 'own' === NovaWork\Settings::edit_rule() && 'own' === $full['settings']['editRule'], 'members change their own work by default; sync says so', $full['settings']['editRule'] ?? null );
	list( $s ) = $t->call( $sam, 'PATCH', '/tasks/' . $t3['id'], array( 'title' => 'Not his' ) );
	$t->ok( 403 === $s, 'a task someone else created and isn\'t yours → 403', $s );
	list( $s ) = $t->call( $sam, 'POST', '/tasks/' . $t3['id'] . '/move', array( 'status' => 'now' ) );
	$t->ok( 403 === $s, '…nor move it → 403', $s );
	list( $s ) = $t->call( $sam, 'DELETE', '/tasks/' . $t3['id'] );
	$t->ok( 403 === $s, '…nor delete it → 403', $s );
	$t->call( $alex, 'PATCH', '/tasks/' . $t3['id'], array( 'assignees' => array( $alex, $sam ) ) );
	list( $s, $as ) = $t->call( $sam, 'POST', '/tasks/' . $t3['id'] . '/move', array( 'status' => 'now' ) );
	$t->ok( 200 === $s && 'now' === $as['status'], 'assigned to it: he can move it', $s );
	list( $s ) = $t->call( $admin, 'PATCH', '/tasks/' . $t2['id'], array( 'notes' => 'An admin note' ) );
	$t->ok( 200 === $s, 'an administrator changes anyone\'s task', $s );
	list( $s ) = $t->call( $sam, 'PATCH', '/projects/' . $p1['id'], array( 'status' => 'done' ) );
	$t->ok( 403 === $s, 'a project someone else created → 403', $s );
	list( $s ) = $t->call( $sam, 'POST', '/projects/' . $p1['id'] . '/move', array( 'beforeId' => null ) );
	$t->ok( 403 === $s, '…nor reorder it → 403', $s );
	list( $s ) = $t->call( $sam, 'PATCH', '/projects/' . $p2['id'], array( 'goal' => 'His own' ) );
	$t->ok( 200 === $s, 'his own project → 200', $s );
	list( , $er_d ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Who ships it?', 'ownerId' => $sam ) );
	list( $s ) = $t->call( $sam, 'PATCH', '/decisions/' . $er_d['id'], array( 'question' => 'Changed' ) );
	$t->ok( 403 === $s, 'a decision someone else created → 403', $s );
	list( $s, $er_dd ) = $t->call( $sam, 'PATCH', '/decisions/' . $er_d['id'], array( 'question' => 'Who ships it?', 'status' => 'decided', 'answer' => 'Sam does.' ) );
	$t->ok( 200 === $s && 'decided' === $er_dd['status'], 'the one who decides still records it from the editor (only the outcome changes)', $s );
	list( $s ) = $t->call( $sam, 'DELETE', '/decisions/' . $er_d['id'] );
	$t->ok( 403 === $s, '…but can\'t delete it → 403', $s );
	$er_set = (array) get_option( NovaWork\Settings::OPTION, array() );
	update_option( NovaWork\Settings::OPTION, array_merge( $er_set, array( 'edit_rule' => 'anyone' ) ) );
	list( $s ) = $t->call( $sam, 'PATCH', '/projects/' . $p1['id'], array( 'goal' => 'Anyone may' ) );
	$t->ok( 200 === $s && 'anyone' === NovaWork\Settings::clean( 'edit_rule', 'anyone' ), 'with "Anyone in the team", every member changes everything', $s );
	update_option( NovaWork\Settings::OPTION, $er_set );
	$t->ok( is_wp_error( NovaWork\Settings::clean( 'edit_rule', 'some' ) ), 'only own|anyone accepted' );

	// ------------------------------------------------------------------
	$t->section( 'Subtasks (owner request, 2026-11-22)' );
	list( , $cur ) = $t->call( $alex, 'GET', '/sync' );
	$sub_cursor    = $cur['cursor'];
	list( $s, $st ) = $t->call( $alex, 'POST', '/tasks/' . $t1['id'] . '/subtasks', array( 'title' => 'Outline' ) );
	$t->ok( 201 === $s && 1 === count( $st['subtasks'] ) && 'Outline' === $st['subtasks'][0]['title'] && false === $st['subtasks'][0]['done'] && 0 === $st['subtasks'][0]['assigneeId'], 'add a subtask; the answer is the whole task', $st );
	$s1 = $st['subtasks'][0]['id'];
	list( , $st ) = $t->call( $alex, 'POST', '/tasks/' . $t1['id'] . '/subtasks', array( 'title' => 'First draft', 'assigneeId' => $sam ) );
	list( , $st ) = $t->call( $alex, 'POST', '/tasks/' . $t1['id'] . '/subtasks', array( 'title' => 'Send for review' ) );
	$t->ok( array( 'Outline', 'First draft', 'Send for review' ) === array_column( $st['subtasks'], 'title' ), 'new subtasks go to the end of the checklist' );
	$s2 = $st['subtasks'][1]['id'];
	$s3 = $st['subtasks'][2]['id'];
	$sub_note = array_values( array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $sam, 50 ), fn( $n ) => 'assign' === $n['type'] && (int) $n['object_id'] === $t1['id'] && str_contains( (string) $n['data'], 'First draft' ) ) );
	$t->ok( 1 === count( $sub_note ), 'sam is told about the subtask, on its task' );
	list( , $inc ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $sub_cursor ) );
	$synced        = array_values( array_filter( $inc['data']['tasks'] ?? array(), fn( $x ) => $x['id'] === $t1['id'] ) );
	$t->ok( $synced && 3 === count( $synced[0]['subtasks'] ), 'a subtask change reaches other members with its task', $inc['data'] ?? null );

	list( $s ) = $t->call( $alex, 'POST', '/tasks/' . $t1['id'] . '/subtasks', array( 'title' => '  ' ) );
	$t->ok( 400 === $s, 'blank subtask → 400', $s );
	list( $s ) = $t->call( $alex, 'POST', '/tasks/' . $t1['id'] . '/subtasks', array( 'title' => 'x', 'assigneeId' => (int) $outsider ) );
	$t->ok( 400 === $s, 'assigning a non-member → 400', $s );
	list( $s ) = $t->call( $alex, 'POST', '/tasks/999999/subtasks', array( 'title' => 'x' ) );
	$t->ok( 404 === $s, 'unknown task → 404', $s );
	list( $s ) = $t->call( $alex, 'PATCH', '/tasks/' . $t3['id'] . '/subtasks/' . $s1, array( 'done' => true ) );
	$t->ok( 404 === $s, 'a subtask is only reached through its own task → 404', $s );
	list( $s ) = $t->call( (int) $outsider, 'PATCH', '/tasks/' . $t1['id'] . '/subtasks/' . $s1, array( 'done' => true ) );
	$t->ok( 403 === $s, 'non-member cannot tick a subtask → 403', $s );

	list( , $st ) = $t->call( $alex, 'PATCH', '/tasks/' . $t1['id'] . '/subtasks/' . $s1, array( 'done' => true ) );
	$first_done   = $st['subtasks'][0]['doneAt'];
	$t->ok( true === $st['subtasks'][0]['done'] && is_int( $first_done ), 'the task\'s creator ticks a subtask' );
	list( , $st ) = $t->call( $alex, 'PATCH', '/tasks/' . $t1['id'] . '/subtasks/' . $s1, array( 'done' => true, 'title' => 'Outline the brief' ) );
	$t->ok( $first_done === $st['subtasks'][0]['doneAt'] && 'Outline the brief' === $st['subtasks'][0]['title'], 'ticking again keeps when it was done; rename' );
	list( , $st ) = $t->call( $alex, 'PATCH', '/tasks/' . $t1['id'] . '/subtasks/' . $s1, array( 'done' => false ) );
	$t->ok( false === $st['subtasks'][0]['done'] && null === $st['subtasks'][0]['doneAt'], 'untick' );
	list( $s ) = $t->call( $alex, 'PATCH', '/tasks/' . $t1['id'] . '/subtasks/' . $s1, array( 'title' => '' ) );
	$t->ok( 400 === $s, 'renaming to nothing → 400', $s );
	// Edit rights (1.2.0): sam is no longer on the task, but "First draft" is his.
	list( $s, $st ) = $t->call( $sam, 'PATCH', '/tasks/' . $t1['id'] . '/subtasks/' . $s2, array( 'done' => true ) );
	$t->ok( 200 === $s && true === $st['subtasks'][1]['done'], 'someone given a subtask can tick it on a task that isn\'t theirs', $s );
	$t->call( $sam, 'PATCH', '/tasks/' . $t1['id'] . '/subtasks/' . $s2, array( 'done' => false ) );
	list( $s ) = $t->call( $sam, 'PATCH', '/tasks/' . $t1['id'] . '/subtasks/' . $s2, array( 'title' => 'Renamed' ) );
	$t->ok( 403 === $s, '…but not rename it → 403', $s );
	list( $s ) = $t->call( $sam, 'PATCH', '/tasks/' . $t1['id'] . '/subtasks/' . $s1, array( 'done' => true ) );
	$t->ok( 403 === $s, '…nor tick someone else\'s → 403', $s );
	list( $s ) = $t->call( $sam, 'POST', '/tasks/' . $t1['id'] . '/subtasks', array( 'title' => 'Mine now' ) );
	$t->ok( 403 === $s, '…nor add to its checklist → 403', $s );

	list( , $st ) = $t->call( $alex, 'POST', '/tasks/' . $t1['id'] . '/subtasks/' . $s3 . '/move', array( 'beforeId' => $s1 ) );
	$t->ok( array( $s3, $s1, $s2 ) === array_column( $st['subtasks'], 'id' ), 'drag a subtask to the top' );
	list( , $st ) = $t->call( $alex, 'POST', '/tasks/' . $t1['id'] . '/subtasks/' . $s3 . '/move', array( 'beforeId' => null ) );
	$t->ok( array( $s1, $s2, $s3 ) === array_column( $st['subtasks'], 'id' ), 'and back to the end' );
	list( , $st ) = $t->call( $alex, 'DELETE', '/tasks/' . $t1['id'] . '/subtasks/' . $s3 );
	$t->ok( array( $s1, $s2 ) === array_column( $st['subtasks'], 'id' ), 'remove a subtask' );

	list( , $tmp_task ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'With a checklist' ) );
	$t->call( $alex, 'POST', '/tasks/' . $tmp_task['id'] . '/subtasks', array( 'title' => 'Step' ) );
	$t->call( $alex, 'DELETE', '/tasks/' . $tmp_task['id'] );
	$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE task_id = %d', Db::table( 'subtasks' ), $tmp_task['id'] ) );
	$t->ok( 0 === $left, 'deleting a task removes its subtasks' );

	// ------------------------------------------------------------------
	$t->section( 'Welcome tour (owner, 2026-11-23)' );
	delete_user_meta( $sam, NovaWork\Rest\TourController::SEEN_META );
	$t->ok( NovaWork\Rest\TourController::should_start( $sam ), 'the tour starts by itself for a member who has not seen it' );
	list( $s ) = $t->call( (int) $outsider, 'POST', '/tour/seen' );
	$t->ok( 403 === $s, 'non-member → 403', $s );
	list( $s, $seen ) = $t->call( $sam, 'POST', '/tour/seen' );
	$t->ok( 200 === $s && true === $seen['seen'] && ! NovaWork\Rest\TourController::should_start( $sam ), 'finishing or skipping it is remembered for that member' );
	$t->ok( NovaWork\Rest\TourController::should_start( $alex ) === ! get_user_meta( $alex, NovaWork\Rest\TourController::SEEN_META, true ), 'and only for that member' );
	delete_user_meta( $sam, NovaWork\Rest\TourController::SEEN_META );
	$settings_before = get_option( NovaWork\Settings::OPTION, array() );
	update_option( NovaWork\Settings::OPTION, array_merge( (array) $settings_before, array( 'show_tour' => false ) ) );
	$t->ok( ! NovaWork\Rest\TourController::should_start( $sam ), 'setting show_tour off: it never starts by itself' );
	update_option( NovaWork\Settings::OPTION, $settings_before );

	// ------------------------------------------------------------------
	$t->section( 'Sync: incremental and scoped' );
	list( , $inc ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $cursor ) );
	$t->ok( false === $inc['full'], 'a recent cursor gets only changes' );
	$got = array_column( $inc['data']['tasks'] ?? array(), 'id' );
	$t->ok( in_array( $t1['id'], $got, true ) && in_array( $t3['id'], $got, true ), "sam sees alex's tasks" );
	$t->ok( count( $got ) === count( array_unique( $got ) ), 'each object appears once however often it changed' );
	$t->ok( ! empty( $inc['data']['notifications'] ) && ! array_filter( $inc['data']['notifications'], fn( $n ) => false ), 'sam receives his own notifications' );
	list( , $alex_inc ) = $t->call( $alex, 'GET', '/sync', array( 'since' => $cursor ) );
	$leak = array_filter( $alex_inc['data']['notifications'] ?? array(), fn( $n ) => 'assign' === $n['type'] && $n['objectId'] === $t1['id'] );
	$t->ok( ! $leak, "alex does not receive sam's notifications" );
	list( , $again ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $inc['cursor'] ) );
	$t->ok( empty( (array) $again['data'] ) || ! array_diff( array_keys( (array) $again['data'] ), array( 'notifications' ) ), 'polling again with the new cursor brings nothing new' );
	update_option( NovaWork\Data\ChangeLog::FLOOR_OPTION, $cursor + 1, false );
	list( , $pruned ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $cursor ) );
	$t->ok( true === $pruned['full'], 'a cursor older than pruned history gets a full snapshot' );
	delete_option( NovaWork\Data\ChangeLog::FLOOR_OPTION );
	list( , $ahead ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $inc['cursor'] + 1000000 ) );
	$t->ok( true === $ahead['full'], 'a cursor from the future (reset log) gets a full snapshot' );
	list( $s ) = $t->call( $alex, 'DELETE', '/tasks/' . $t3['id'] );
	list( , $del ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $inc['cursor'] ) );
	$t->ok( 200 === $s && in_array( $t3['id'], $del['deleted']['tasks'] ?? array(), true ), 'deleting a task reaches the other member as a delete' );
	list( $s ) = $t->call( $alex, 'DELETE', '/tasks/' . $t3['id'] );
	$t->ok( 404 === $s, 'deleting twice → 404', $s );

	// ------------------------------------------------------------------
	$t->section( 'Due dates and decision deadlines' );
	$tomorrow = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
	list( , $due ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'Due soon', 'assignees' => array( $sam ), 'due' => $tomorrow ) );
	Notifier::check_due( true );
	Notifier::check_due( true );
	$dues = array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $sam, 100 ), fn( $n ) => 'due' === $n['type'] && (int) $n['object_id'] === $due['id'] );
	$t->ok( 1 === count( $dues ), 'a task due tomorrow notifies its assignee exactly once', count( $dues ) );
	list( , $later ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'Due later', 'assignees' => array( $sam ), 'due' => wp_date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ) ) );
	Notifier::check_due( true );
	$far = array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $sam, 100 ), fn( $n ) => 'due' === $n['type'] && (int) $n['object_id'] === $later['id'] );
	$t->ok( ! $far, 'a task due in 10 days does not notify yet' );
	list( , $d0 ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Anyone decides?', 'decideBy' => $tomorrow ) );
	Notifier::check_due( true );
	foreach ( array( 'alex' => $alex, 'sam' => $sam ) as $who => $uid ) {
		$hit = array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $uid, 100 ), fn( $n ) => 'decision' === $n['type'] && (int) $n['object_id'] === $d0['id'] );
		$t->ok( 1 === count( $hit ), "an unowned decision due tomorrow notifies $who" );
	}

	// ------------------------------------------------------------------
	$t->section( 'Comments' );
	list( $s ) = $t->call( $alex, 'POST', '/comments', array( 'objectType' => 'task', 'objectId' => 999999, 'text' => 'Hi' ) );
	$t->ok( 404 === $s, 'comment on a missing task → 404', $s );
	list( $s ) = $t->call( $alex, 'POST', '/comments', array( 'objectType' => 'task', 'objectId' => $due['id'], 'text' => '   ' ) );
	$t->ok( 400 === $s, 'blank comment → 400', $s );
	list( $s, $c1 ) = $t->call( $alex, 'POST', '/comments', array( 'objectType' => 'task', 'objectId' => $due['id'], 'text' => "Looks good.\n<script>x</script>Ship it" ) );
	$t->ok( 201 === $s && false === strpos( $c1['text'], '<script>' ), 'comment saved as plain text', $c1['text'] ?? null );
	$hit = array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $sam, 100 ), fn( $n ) => 'comment' === $n['type'] && (int) $n['object_id'] === $due['id'] );
	$t->ok( 1 === count( $hit ), 'the assignee is notified of a comment' );
	$t->call( $sam, 'POST', '/comments', array( 'objectType' => 'task', 'objectId' => $due['id'], 'text' => 'Done.' ) );
	$hit = array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $alex, 100 ), fn( $n ) => 'comment' === $n['type'] && (int) $n['object_id'] === $due['id'] );
	$t->ok( 1 === count( $hit ), 'the creator and earlier commenter is notified of the reply' );

	// ------------------------------------------------------------------
	$t->section( 'Decisions' );
	list( $s ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Q', 'ownerId' => (int) $outsider ) );
	$t->ok( 400 === $s, 'a non-member cannot be the one who decides', $s );
	list( , $d1 ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Refund window?', 'context' => '14 or 30 days', 'ownerId' => $sam, 'projectId' => $p1['id'] ) );
	$t->ok( 'open' === $d1['status'] && $sam === $d1['ownerId'], 'decision opened' );
	list( $s ) = $t->call( $sam, 'POST', '/decisions/' . $d1['id'] . '/decide', array( 'answer' => '  ' ) );
	$t->ok( 400 === $s, 'deciding needs an answer', $s );
	list( , $d1b ) = $t->call( $sam, 'POST', '/decisions/' . $d1['id'] . '/decide', array( 'answer' => '30 days.' ) );
	$t->ok( 'decided' === $d1b['status'] && $sam === $d1b['decidedBy'] && is_int( $d1b['decidedAt'] ), 'decided, with who and when' );
	list( , $d1c ) = $t->call( $sam, 'PATCH', '/decisions/' . $d1['id'], array( 'status' => 'open' ) );
	$t->ok( 'open' === $d1c['status'] && null === $d1c['decidedAt'] && '30 days.' === $d1c['answer'], 'reopen keeps the answer, clears the stamp' );
	list( $s ) = $t->call( $alex, 'PATCH', '/decisions/' . $d0['id'], array( 'status' => 'decided' ) );
	$t->ok( 400 === $s, 'marking decided without an answer → 400', $s );
	list( , $dc ) = $t->call( $sam, 'POST', '/comments', array( 'objectType' => 'decision', 'objectId' => $d1['id'], 'text' => 'Agreed' ) );
	$hit = array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $alex, 100 ), fn( $n ) => 'comment' === $n['type'] && 'decision' === $n['object_type'] );
	$t->ok( 1 === count( $hit ), 'comments on decisions notify its creator' );
	$t->call( $alex, 'DELETE', '/projects/' . $p1['id'] );
	list( , $orphan ) = $t->call( $alex, 'PATCH', '/decisions/' . $d1['id'], array() );
	$t->ok( 0 === $orphan['projectId'], "deleting a project clears it from its decisions" );
	$task_after = $repo->present( (array) $repo->find( $t1['id'] ) );
	$t->ok( 0 === $task_after['projectId'], 'and from its tasks, which stay' );

	// ------------------------------------------------------------------
	$t->section( 'Decision rights and votes' );
	delete_option( 'novawork_settings' );
	list( $s, $dv ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Which logo?', 'ownerId' => $sam, 'voting' => true, 'options' => array( array( 'label' => 'Round' ), array( 'label' => 'Square' ), array( 'label' => 'square' ), array( 'label' => '  ' ) ) ) );
	$t->ok( 201 === $s && true === $dv['voting'] && array( 'o1', 'o2' ) === array_column( $dv['options'], 'key' ), 'a vote opens with clean options (blank and duplicate dropped)', $dv['options'] ?? $dv );
	$votes_for = fn( $uid ) => array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $uid, 100 ), fn( $n ) => 'vote' === $n['type'] && (int) $n['object_id'] === $dv['id'] );
	$t->ok( 1 === count( $votes_for( $sam ) ) && 1 === count( $votes_for( $admin ) ) && 0 === count( $votes_for( $alex ) ), 'everyone but the asker is asked to vote' );
	list( , $v1 ) = $t->call( $alex, 'POST', '/decisions/' . $dv['id'] . '/vote', array( 'option' => 'o1' ) );
	list( , $v2 ) = $t->call( $sam, 'POST', '/decisions/' . $dv['id'] . '/vote', array( 'option' => 'o2' ) );
	$t->ok( array( (string) $alex => 'o1', (string) $sam => 'o2' ) == $v2['votes'], 'members vote', $v2['votes'] );
	list( , $v3 ) = $t->call( $alex, 'POST', '/decisions/' . $dv['id'] . '/vote', array( 'option' => 'o2' ) );
	$t->ok( 'o2' === $v3['votes'][ (string) $alex ], 'a member can change their vote' );
	list( , $v4 ) = $t->call( $alex, 'POST', '/decisions/' . $dv['id'] . '/vote', array( 'option' => null ) );
	$t->ok( ! isset( $v4['votes'][ (string) $alex ] ) && isset( $v4['votes'][ (string) $sam ] ), 'and withdraw it' );
	list( $s ) = $t->call( $alex, 'POST', '/decisions/' . $dv['id'] . '/vote', array( 'option' => 'o9' ) );
	$t->ok( 400 === $s, 'voting for a missing option → 400', $s );
	list( , $re ) = $t->call( $alex, 'PATCH', '/decisions/' . $dv['id'], array( 'options' => array( array( 'key' => 'o2', 'label' => 'Square, rounded corners' ), array( 'label' => 'Wordmark only' ) ) ) );
	$t->ok( array( 'o2', 'o3' ) === array_column( $re['options'], 'key' ) && 'o2' === ( $re['votes'][ (string) $sam ] ?? '' ), 'renaming an option keeps its votes; a new option gets a new key', $re );
	$t->call( $alex, 'POST', '/decisions/' . $dv['id'] . '/vote', array( 'option' => 'o3' ) );
	list( , $re2 ) = $t->call( $alex, 'PATCH', '/decisions/' . $dv['id'], array( 'options' => array( array( 'key' => 'o2', 'label' => 'Square, rounded corners' ) ) ) );
	$t->ok( ! isset( $re2['votes'][ (string) $alex ] ) && isset( $re2['votes'][ (string) $sam ] ), 'removing an option drops the votes for it' );
	list( $s, $no ) = $t->call( $alex, 'POST', '/decisions/' . $dv['id'] . '/decide', array( 'answer' => 'Round.' ) );
	$t->ok( 403 === $s && str_contains( $no['message'], 'Sam' ), 'only the named person can decide (403, says who)', $no );
	list( $s ) = $t->call( $alex, 'PATCH', '/decisions/' . $dv['id'], array( 'status' => 'decided', 'answer' => 'Round.' ) );
	$t->ok( 403 === $s, 'marking it decided through an edit is refused too', $s );
	list( $s, $okd ) = $t->call( $sam, 'POST', '/decisions/' . $dv['id'] . '/decide', array( 'answer' => 'Square, rounded corners.' ) );
	$t->ok( 200 === $s && 'decided' === $okd['status'], 'the named person decides' );
	list( $s ) = $t->call( $alex, 'POST', '/decisions/' . $dv['id'] . '/vote', array( 'option' => 'o2' ) );
	$t->ok( 400 === $s, 'voting closes once decided', $s );
	list( $s ) = $t->call( $alex, 'PATCH', '/decisions/' . $dv['id'], array( 'status' => 'open' ) );
	$t->ok( 403 === $s, 'reopening follows the same rule', $s );
	list( $s ) = $t->call( $alex, 'PATCH', '/decisions/' . $dv['id'], array( 'context' => 'Notes anyone may add.' ) );
	$t->ok( 200 === $s, 'anyone may still edit the question and context', $s );
	list( , $d2 ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Admin unblocks', 'ownerId' => $sam ) );
	list( $s ) = $t->call( $admin, 'POST', '/decisions/' . $d2['id'] . '/decide', array( 'answer' => 'Done.' ) );
	$t->ok( 200 === $s, 'an administrator can always decide', $s );
	list( , $d3 ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Anyone', 'ownerId' => 0 ) );
	list( $s ) = $t->call( $alex, 'POST', '/decisions/' . $d3['id'] . '/decide', array( 'answer' => 'Yes.' ) );
	$t->ok( 200 === $s, 'with no one named, anyone decides', $s );
	update_option( 'novawork_settings', array( 'decide_rule' => 'anyone', 'decision_votes' => false ) );
	list( , $d4 ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Rule anyone', 'ownerId' => $sam, 'voting' => true, 'options' => array( array( 'label' => 'A' ) ) ) );
	list( $s ) = $t->call( $alex, 'POST', '/decisions/' . $d4['id'] . '/decide', array( 'answer' => 'A.' ) );
	$t->ok( 200 === $s, 'setting decide_rule=anyone lets any member decide', $s );
	$t->ok( false === $d4['voting'], 'setting decision_votes=off: a new decision does not open a vote' );
	list( , $sync_s ) = $t->call( $alex, 'GET', '/sync' );
	$t->ok( 'anyone' === $sync_s['settings']['decideRule'] && false === $sync_s['settings']['decisionVotes'] && false === $sync_s['canManage'], 'sync sends the decision settings and whether you manage NovaWork' );
	delete_option( 'novawork_settings' );

	// ------------------------------------------------------------------
	$t->section( 'Timer' );
	$entries = new TimeEntryRepository();
	list( , $tn ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'Track me', 'status' => 'later' ) );
	list( $s, $start ) = $t->call( $alex, 'POST', '/timer/start', array( 'taskId' => $tn['id'] ) );
	$t->ok( 200 === $s && true === $start['entry']['running'], 'timer started on the server' );
	$t->ok( 'now' === $start['task']['status'], 'tracking a Later task moves it to Now' );
	list( $s ) = $t->call( $alex, 'POST', '/timer/start', array( 'taskId' => 999999 ) );
	$t->ok( 404 === $s, 'timer on a missing task → 404', $s );
	$wpdb->query( 'SAVEPOINT dup' );
	$wpdb->suppress_errors( true );
	$dup = $wpdb->insert( $entries->table_name(), array( 'user_id' => $alex, 'task_id' => $tn['id'], 'started_at' => Db::now(), 'running_user' => $alex, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
	$wpdb->suppress_errors( false );
	$wpdb->query( 'ROLLBACK TO SAVEPOINT dup' );
	$t->ok( false === $dup, 'the database refuses a second running timer for one member' );

	// Backdate, then stop at an earlier "last activity".
	$run = $entries->running_for( $alex );
	$entries->update( (int) $run['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ) ) );
	list( , $stop ) = $t->call( $sam, 'POST', '/timer/stop' );
	$still = $entries->running_for( $alex );
	$t->ok( null !== $still && (int) $still['id'] === (int) $run['id'] && ( null === $stop['entry'] || $sam === $stop['entry']['by'] ), "sam's stop only ever touches sam's own timer, never alex's" );
	$idle_end = ( time() - 8 * MINUTE_IN_SECONDS ) * 1000;
	list( , $stop ) = $t->call( $alex, 'POST', '/timer/stop', array( 'endedAt' => $idle_end ) );
	$t->ok( is_array( $stop['entry'] ) && abs( $stop['entry']['end'] - $idle_end ) < 1000, 'idle pause ends the entry at the last activity' );
	$t->ok( 12 * 60 === intdiv( $stop['entry']['end'] - $stop['entry']['start'], 1000 ), 'away time is not counted (12 of 20 minutes kept)' );
	$t->ok( null === $entries->running_for( $alex ), 'nothing is left running' );

	$t->call( $alex, 'POST', '/timer/start', array( 'taskId' => $tn['id'] ) );
	list( , $short ) = $t->call( $alex, 'POST', '/timer/stop' );
	$t->ok( null === $short['entry'], 'a session under a minute is discarded' );

	$t->call( $alex, 'POST', '/timer/start', array( 'taskId' => $tn['id'] ) );
	$run = $entries->running_for( $alex );
	$entries->update( (int) $run['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ) ) );
	list( , $future ) = $t->call( $alex, 'POST', '/timer/stop', array( 'endedAt' => ( time() + 3600 ) * 1000 ) );
	$t->ok( $future['entry']['end'] <= ( time() + 1 ) * 1000, 'an end in the future is clamped to now' );

	$t->call( $alex, 'POST', '/timer/start', array( 'taskId' => $tn['id'] ) );
	$run = $entries->running_for( $alex );
	$entries->update( (int) $run['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * MINUTE_IN_SECONDS ) ) );
	list( , $switch ) = $t->call( $alex, 'POST', '/timer/start', array( 'taskId' => $t1['id'] ) );
	$t->ok( is_array( $switch['stopped'] ) && $switch['stopped']['taskId'] === $tn['id'] && $switch['entry']['taskId'] === $t1['id'], 'starting another task stops the first and keeps its time' );
	$t->call( $alex, 'POST', '/tasks/' . $t1['id'] . '/move', array( 'status' => 'done' ) );
	$t->ok( null === $entries->running_for( $alex ), 'finishing the tracked task stops your timer' );

	list( $s ) = $t->call( $alex, 'GET', '/time-entries', array( 'from' => 0, 'to' => time() * 1000 ) );
	$t->ok( 400 === $s, 'more than a year of entries at once → 400', $s );
	list( $s ) = $t->call( $alex, 'GET', '/time-entries', array( 'from' => ( time() - 3600 ) * 1000, 'user' => (int) $outsider ) );
	$t->ok( 400 === $s, 'entries for a non-member → 400', $s );
	list( , $mine_e ) = $t->call( $sam, 'GET', '/time-entries', array( 'from' => ( time() - 3600 ) * 1000, 'user' => $alex ) );
	$t->ok( count( $mine_e ) >= 2 && ! array_filter( $mine_e, fn( $e ) => $e['by'] !== $alex ), "sam can see alex's tracked time for the Time view" );

	// ------------------------------------------------------------------
	$t->section( 'Timer: a running timer needs a sign of life (owner, 2026-11-22)' );
	$away_after = NovaWork\Services\Timer::away_after();
	$t->ok( 10 * MINUTE_IN_SECONDS === $away_after, 'the away limit is the inactivity setting (10 minutes)' );
	$backdate   = static function ( int $user, int $started_ago, int $seen_ago ) use ( $entries ) {
		$run = $entries->running_for( $user );
		$entries->update( (int) $run['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - $started_ago ) ) );
		update_user_meta( $user, NovaWork\Members::SEEN_META, time() - $seen_ago );
		return (int) $run['id'];
	};
	list( , $ta ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'Folded laptop', 'status' => 'now' ) );

	// Laptop folded 40 minutes ago after 30 minutes of work: back, the first poll stops it.
	$t->call( $alex, 'POST', '/timer/start', array( 'taskId' => $ta['id'] ) );
	$rid           = $backdate( $alex, 70 * MINUTE_IN_SECONDS, 40 * MINUTE_IN_SECONDS );
	list( , $back ) = $t->call( $alex, 'GET', '/sync' );
	$row           = $entries->find( $rid );
	$t->ok( null === $entries->running_for( $alex ), 'back after a folded laptop: the first poll stops the timer' );
	$t->ok( $row && abs( (int) strtotime( $row['ended_at'] . ' UTC' ) - ( time() - 40 * MINUTE_IN_SECONDS ) ) <= 2, 'it ends at the last sign of life, 40 minutes ago' );
	$t->ok( isset( $back['timerAway'] ) && abs( $back['timerAway']['awayMs'] - 40 * MINUTE_IN_SECONDS * 1000 ) < 3000 && $ta['id'] === $back['timerAway']['taskId'], 'the app is told how long was not counted', $back['timerAway'] ?? null );
	list( , $again ) = $t->call( $alex, 'GET', '/sync' );
	$t->ok( ! isset( $again['timerAway'] ), 'and only once' );

	// Quiet for less than the limit (a background tab polls about once a minute): it keeps running.
	$t->call( $alex, 'POST', '/timer/start', array( 'taskId' => $ta['id'] ) );
	$backdate( $alex, 30 * MINUTE_IN_SECONDS, 2 * MINUTE_IN_SECONDS );
	list( , $ok ) = $t->call( $alex, 'GET', '/sync' );
	$t->ok( null !== $entries->running_for( $alex ) && ! isset( $ok['timerAway'] ), 'two quiet minutes are not away: it keeps running' );

	// Started after the last poll (a fresh start counts as a sign of life).
	$backdate( $alex, 60, 50 * MINUTE_IN_SECONDS );
	$t->call( $alex, 'GET', '/sync' );
	$t->ok( null !== $entries->running_for( $alex ), 'a timer started a minute ago is not stopped for an old last poll' );
	NovaWork\Services\Timer::stop( $alex );

	// A teammate's poll stops a timer left running on a closed tab.
	$t->call( $sam, 'POST', '/timer/start', array( 'taskId' => $ta['id'] ) );
	$sid = $backdate( $sam, 50 * MINUTE_IN_SECONDS, 25 * MINUTE_IN_SECONDS );
	delete_transient( 'novawork_away_sweep' );
	$t->call( $alex, 'GET', '/sync' );
	$t->ok( null === $entries->running_for( $sam ), "sam's tab closed 25 minutes ago: alex's poll stops sam's timer" );
	$srow = $entries->find( $sid );
	$kept = $srow ? (int) strtotime( $srow['ended_at'] . ' UTC' ) - (int) strtotime( $srow['started_at'] . ' UTC' ) : 0;
	$t->ok( abs( $kept - 25 * MINUTE_IN_SECONDS ) <= 2, 'it keeps the 25 minutes before sam went quiet', $kept );

	// The hourly cron does the same when nobody is online.
	$t->call( $sam, 'POST', '/timer/start', array( 'taskId' => $ta['id'] ) );
	$backdate( $sam, 3 * HOUR_IN_SECONDS, 2 * HOUR_IN_SECONDS );
	NovaWork\Services\Cron::run();
	$t->ok( null === $entries->running_for( $sam ), 'the hourly job stops it when nobody is online' );

	// ------------------------------------------------------------------
	$t->section( '"Were you working?" after an automatic stop (owner, 1.2.0)' );
	delete_user_meta( $admin, NovaWork\Services\TimeEdits::PENDING_META );
	$t->call( $admin, 'POST', '/timer/start', array( 'taskId' => $ta['id'] ) );
	$rid           = $backdate( $admin, 50 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS );
	list( , $pend ) = $t->call( $admin, 'GET', '/sync' );
	$p             = $pend['pendingStop'] ?? null;
	$t->ok( $p && 'away' === $p['reason'] && $rid === $p['entryId'] && $ta['id'] === $p['taskId'], 'an automatic stop is remembered and comes with the poll', $p );
	list( , $pend_sam ) = $t->call( $sam, 'GET', '/sync' );
	$t->ok( null === ( $pend_sam['pendingStop'] ?? null ) || ( $pend_sam['pendingStop']['entryId'] ?? 0 ) !== $rid, 'only to that member' );
	$until = NovaWork\Data\Db::now_ms() - 5 * MINUTE_IN_SECONDS * 1000;
	list( $s, $rec ) = $t->call( $admin, 'POST', '/timer/recover', array( 'until' => $until ) );
	$t->ok( 200 === $s && $rid === $rec['id'] && abs( $rec['end'] - $until ) < 1500, 'Count it: the session now runs until the time chosen', $rec );
	list( , $after ) = $t->call( $admin, 'GET', '/sync' );
	$t->ok( null === $after['pendingStop'], 'asked once: the question is gone' );
	list( $s ) = $t->call( $admin, 'POST', '/timer/recover', array( 'until' => $until ) );
	$t->ok( 404 === $s, 'nothing left to put back → 404', $s );

	// Keep tracking: counted up to now and running again.
	$t->call( $admin, 'POST', '/timer/start', array( 'taskId' => $ta['id'] ) );
	$rid2 = $backdate( $admin, 40 * MINUTE_IN_SECONDS, 20 * MINUTE_IN_SECONDS );
	$t->call( $admin, 'GET', '/sync' );
	list( $s, $rec2 ) = $t->call( $admin, 'POST', '/timer/recover', array( 'until' => NovaWork\Data\Db::now_ms(), 'keep' => true ) );
	$run = $entries->running_for( $admin );
	$t->ok( 200 === $s && $run && $rid2 === (int) $run['id'], 'Count it and keep tracking: the same session runs on', $rec2 );
	NovaWork\Services\Timer::stop( $admin );

	// Don't count it.
	$t->call( $admin, 'POST', '/timer/start', array( 'taskId' => $ta['id'] ) );
	$backdate( $admin, 10 * MINUTE_IN_SECONDS, 0 );
	list( $s ) = $t->call( $admin, 'POST', '/timer/stop', array( 'reason' => 'idle' ) );
	$t->ok( 'idle' === ( NovaWork\Services\TimeEdits::pending( $admin )['reason'] ?? '' ), 'the app\'s own stops (no answer, sleep) are asked about too' );
	$t->call( $admin, 'POST', '/timer/dismiss' );
	$t->ok( null === NovaWork\Services\TimeEdits::pending( $admin ), "Don't count it: forgotten" );
	list( $s ) = $t->call( $admin, 'POST', '/timer/stop', array( 'reason' => 'nope' ) );
	$t->ok( 400 === $s, 'only idle|sleep reasons → 400', $s );

	// Never into a later session, and never kept running over it (a clean slate first).
	$wpdb->delete( $entries->table_name(), array( 'user_id' => $admin ) );
	$t->call( $admin, 'POST', '/timer/start', array( 'taskId' => $ta['id'] ) );
	$rid3 = $backdate( $admin, 60 * MINUTE_IN_SECONDS, 40 * MINUTE_IN_SECONDS );
	$t->call( $admin, 'GET', '/sync' );
	$t->call( $admin, 'POST', '/timer/start', array( 'taskId' => $t1['id'] ) );
	$next_start = (int) NovaWork\Data\Db::to_ms( (string) $entries->running_for( $admin )['started_at'] );
	list( $s, $rec3 ) = $t->call( $admin, 'POST', '/timer/recover', array( 'until' => NovaWork\Data\Db::now_ms(), 'keep' => true ) );
	$t->ok( 200 === $s && $rid3 === $rec3['id'] && $rec3['end'] <= $next_start && false === $rec3['running'], 'capped at the start of the next session, and not kept running', $rec3 );
	NovaWork\Services\Timer::stop( $admin );

	// The setting off: no question.
	$rr_set = (array) get_option( NovaWork\Settings::OPTION, array() );
	update_option( NovaWork\Settings::OPTION, array_merge( $rr_set, array( 'recover_time' => false ) ) );
	$t->call( $admin, 'POST', '/timer/start', array( 'taskId' => $ta['id'] ) );
	$backdate( $admin, 50 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS );
	list( , $off ) = $t->call( $admin, 'GET', '/sync' );
	$t->ok( null === $off['pendingStop'], 'with "Ask after an automatic stop" off, nobody is asked' );
	update_option( NovaWork\Settings::OPTION, $rr_set );

	// ------------------------------------------------------------------
	$t->section( 'Administrators fix time by hand (owner, 1.2.0)' );
	$h      = HOUR_IN_SECONDS * 1000;
	$now_ms = intdiv( NovaWork\Data\Db::now_ms(), 1000 ) * 1000; // Stored to the second.
	$span   = array(
		'userId' => $sam,
		'taskId' => $t1['id'],
		'start'  => $now_ms - 30 * $h,
		'end'    => $now_ms - 29 * $h,
	);
	list( $s ) = $t->call( $sam, 'POST', '/time-entries', $span );
	$t->ok( 403 === $s, 'a member can\'t add time → 403', $s );
	list( $s, $made ) = $t->call( $admin, 'POST', '/time-entries', $span );
	$t->ok( 201 === $s && $sam === $made['by'] && $span['end'] === $made['end'], 'an administrator adds an hour for sam', $made );
	list( $s ) = $t->call( $admin, 'POST', '/time-entries', array_merge( $span, array( 'start' => $span['start'] + 600000 ) ) );
	$t->ok( 409 === $s, 'overlapping another session of sam\'s → 409', $s );
	list( $s ) = $t->call( $admin, 'POST', '/time-entries', array_merge( $span, array( 'start' => $now_ms - $h, 'end' => $now_ms + $h ) ) );
	$t->ok( 400 === $s, 'in the future → 400', $s );
	list( $s ) = $t->call( $admin, 'POST', '/time-entries', array_merge( $span, array( 'start' => $now_ms - 20 * $h, 'end' => $now_ms - 20 * $h + 30000 ) ) );
	$t->ok( 400 === $s, 'under a minute → 400', $s );
	list( $s, $ch ) = $t->call( $admin, 'PATCH', '/time-entries/' . $made['id'], array( 'end' => $span['end'] + $h / 2, 'taskId' => $t2['id'] ) );
	$t->ok( 200 === $s && $span['end'] + $h / 2 === $ch['end'] && $t2['id'] === $ch['taskId'], 'change its end and its task', $ch );
	list( $s ) = $t->call( $sam, 'PATCH', '/time-entries/' . $made['id'], array( 'end' => $span['end'] ) );
	$t->ok( 403 === $s, 'a member can\'t change it → 403', $s );
	list( $s, $gone ) = $t->call( $admin, 'DELETE', '/time-entries/' . $made['id'] );
	$t->ok( 200 === $s && $made['id'] === $gone['deleted'] && $sam === $gone['session']['by'], 'delete it; the answer carries it for Undo', $gone );
	$t->ok( null === $entries->find( $made['id'] ), 'and it is gone' );

	// ------------------------------------------------------------------
	$t->section( 'Chat and private files' );
	list( $s ) = $t->call( $alex, 'POST', '/messages', array( 'text' => '  ' ) );
	$t->ok( 400 === $s, 'empty message → 400', $s );
	list( $s, $m1 ) = $t->call( $alex, 'POST', '/messages', array( 'text' => 'Waitlist page is up.' ) );
	$t->ok( 201 === $s && $alex === $m1['by'], 'message sent' );
	list( , $sync_sam ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $cursor ) );
	$t->ok( $sync_sam['chat']['unread'] >= 1, 'sam has unread chat' );
	list( , $sync_alex ) = $t->call( $alex, 'GET', '/sync', array( 'since' => $cursor ) );
	$t->ok( 0 === $sync_alex['chat']['unread'], "your own message isn't unread" );
	list( , $r ) = $t->call( $sam, 'POST', '/chat/read', array( 'lastId' => $m1['id'] ) );
	list( , $sync_sam ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $cursor ) );
	$unread_chat_notes = array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $sam, 100 ), fn( $n ) => 'chat' === $n['type'] && null === $n['read_at'] );
	$t->ok( 0 === $sync_sam['chat']['unread'] && ! $unread_chat_notes, 'reading chat clears the count and chat notifications' );
	list( , $r2 ) = $t->call( $sam, 'POST', '/chat/read', array( 'lastId' => 1 ) );
	$t->ok( $r2['readId'] === $r['readId'], 'the read position never moves backwards' );

	// A real image through the same checks as an upload (sideloaded: no HTTP here).
	$tmp = wp_tempnam( 'nw-test.png' );
	file_put_contents( $tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) );
	wp_set_current_user( $alex );
	$stored = Files::store( array( 'name' => 'screen shot.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) ), true );
	$t->ok( is_array( $stored ) && preg_match( '/^[a-f0-9]{32}$/', $stored['stored_name'] ), 'stored under a random name with no extension', $stored );
	$path = Files::path( $stored['stored_name'] );
	$t->ok( is_file( $path ) && 0 === strpos( $path, Files::dir() ), 'the file is in the private folder' );
	$t->ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s", '%' . $wpdb->esc_like( $stored['stored_name'] ) . '%' ) ), 'it is not in the Media Library' );
	$att_repo = new NovaWork\Data\AttachmentRepository();
	$att_id   = $att_repo->insert( array_merge( $stored, array( 'user_id' => $alex ) ) );
	list( $s ) = $t->call( $sam, 'GET', '/attachments/' . $att_id . '/file' );
	$t->ok( 404 === $s, "sam can't open alex's unsent upload", $s );
	list( $s ) = $t->call( $sam, 'DELETE', '/attachments/' . $att_id );
	$t->ok( 404 === $s, "sam can't discard alex's upload", $s );
	list( $s, $m2 ) = $t->call( $alex, 'POST', '/messages', array( 'attachmentIds' => array( $att_id ) ) );
	$t->ok( 201 === $s && 1 === count( $m2['files'] ) && 'screen shot.png' === $m2['files'][0]['name'], 'a message can carry only a file, and keeps its readable name', $m2 );
	$t->ok( str_contains( $m2['files'][0]['url'], '/novawork/v1/attachments/' . $att_id . '/file' ), 'its URL is the authenticated route, not uploads/' );
	list( $s ) = $t->call( $alex, 'POST', '/messages', array( 'attachmentIds' => array( $att_id ) ) );
	$t->ok( 400 === $s, 'a file already sent cannot be claimed again', $s );

	$php = wp_tempnam( 'evil.php' );
	file_put_contents( $php, '<?php echo 1;' );
	$bad = Files::store( array( 'name' => 'evil.php', 'type' => 'text/plain', 'tmp_name' => $php, 'error' => 0, 'size' => filesize( $php ) ), true );
	$t->ok( is_wp_error( $bad ) && 'novawork_file_type' === $bad->get_error_code(), 'a .php upload is refused' );
	$svg = wp_tempnam( 'x.svg' );
	file_put_contents( $svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );
	$bad = Files::store( array( 'name' => 'x.svg', 'type' => 'image/svg+xml', 'tmp_name' => $svg, 'error' => 0, 'size' => filesize( $svg ) ), true );
	$t->ok( is_wp_error( $bad ), 'an .svg upload is refused' );
	$big = Files::store( array( 'name' => 'big.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => 900 * MB_IN_BYTES ), true );
	$t->ok( is_wp_error( $big ) && 'novawork_file_too_big' === $big->get_error_code(), 'an oversized upload is refused' );
	@unlink( $php );
	@unlink( $svg );

	$sam_name = get_userdata( $sam )->display_name;
	list( $s, $mm ) = $t->call( $alex, 'POST', '/messages', array( 'text' => "Can you look, @{$sam_name}?", 'mentions' => array( $sam, (int) $outsider, $admin ) ) );
	$t->ok( 201 === $s && array( $sam ) === array_column( $mm['mentions'], 'id' ), 'a mention is kept only when @Name is in the text, and only for members', $mm['mentions'] ?? $mm );
	$kinds = array_column( array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $sam, 100 ), fn( $n ) => (int) $n['object_id'] === $mm['id'] && 'message' === $n['object_type'] ), 'type' );
	$t->ok( array( 'mention' ) === array_values( $kinds ), 'the mentioned member gets a mention, not a chat notification', $kinds );
	$kinds = array_column( array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $admin, 100 ), fn( $n ) => (int) $n['object_id'] === $mm['id'] && 'message' === $n['object_type'] ), 'type' );
	$t->ok( array( 'chat' ) === array_values( $kinds ), 'everyone else gets the usual chat notification', $kinds );

	// /links (owner request).
	list( , $lt ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'Linked task', 'assignees' => array( $sam ) ) );
	list( , $ld ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Linked decision', 'ownerId' => $admin ) );
	list( $s, $lm ) = $t->call( $alex, 'POST', '/messages', array(
		'text' => 'See /Linked task and /Linked decision',
		'refs' => array( array( 'type' => 'task', 'id' => $lt['id'] ), array( 'type' => 'decision', 'id' => $ld['id'] ), array( 'type' => 'project', 'id' => 999999 ), array( 'type' => 'task', 'id' => $t1['id'] ) ),
	) );
	$t->ok( 201 === $s && array( 'task:' . $lt['id'], 'decision:' . $ld['id'] ) === array_map( fn( $r ) => $r['type'] . ':' . $r['id'], $lm['refs'] ), 'a link is kept only when the item exists and "/Title" is in the text', $lm['refs'] ?? $lm );
	$link_for = fn( $uid, $type, $id ) => array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $uid, 200 ), fn( $n ) => 'link' === $n['type'] && $type === $n['object_type'] && (int) $n['object_id'] === $id );
	$t->ok( 1 === count( $link_for( $sam, 'task', $lt['id'] ) ), "the linked task's assignee is told" );
	$t->ok( 1 === count( $link_for( $admin, 'decision', $ld['id'] ) ), "the linked decision's decider is told" );
	list( , $lm2 ) = $t->call( $alex, 'POST', '/messages', array( 'text' => "@{$sam_name} again /Linked task", 'mentions' => array( $sam ), 'refs' => array( array( 'type' => 'task', 'id' => $lt['id'] ) ) ) );
	$t->ok( 1 === count( $link_for( $sam, 'task', $lt['id'] ) ), 'someone already mentioned in the message is not told twice' );
	list( $s, $lc ) = $t->call( $sam, 'POST', '/comments', array( 'objectType' => 'task', 'objectId' => $lt['id'], 'text' => "Done, @" . get_userdata( $alex )->display_name . ", see /Linked decision", 'mentions' => array( $alex ), 'refs' => array( array( 'type' => 'decision', 'id' => $ld['id'] ) ) ) );
	$t->ok( 201 === $s && array( $alex ) === array_column( $lc['mentions'], 'id' ) && 1 === count( $lc['refs'] ), 'comments carry mentions and links too', $lc );
	$kinds = array_column( array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $alex, 200 ), fn( $n ) => 'task' === $n['object_type'] && (int) $n['object_id'] === $lt['id'] ), 'type' );
	$t->ok( in_array( 'mention', $kinds, true ) && ! in_array( 'comment', $kinds, true ), 'a member mentioned in a comment gets a mention instead of a comment', $kinds );

	list( , $hist ) = $t->call( $sam, 'GET', '/messages', array( 'q' => 'waitlist' ) );
	$t->ok( 1 === count( $hist ) && $m1['id'] === $hist[0]['id'], 'chat search finds the message' );
	list( , $hist ) = $t->call( $sam, 'GET', '/messages', array( 'from' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) ) );
	$t->ok( array() === $hist, 'date filter: nothing from tomorrow' );
	list( , $hist ) = $t->call( $sam, 'GET', '/messages', array( 'from' => wp_date( 'Y-m-d' ), 'to' => wp_date( 'Y-m-d' ) ) );
	$t->ok( count( $hist ) >= 2, "date filter: today's messages" );

	// ------------------------------------------------------------------
	$t->section( 'Replies, edits and deletes (chat and comments)' );
	$notes_about = fn( $uid, $type, $id ) => array_column( array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $uid, 300 ), fn( $n ) => $type === $n['object_type'] && (int) $n['object_id'] === $id ), 'type' );
	list( , $orig ) = $t->call( $sam, 'POST', '/messages', array( 'text' => 'Original for a reply' ) );
	list( $s, $rep ) = $t->call( $alex, 'POST', '/messages', array( 'text' => "@{$sam_name} replying", 'mentions' => array( $sam ), 'replyTo' => $orig['id'] ) );
	$t->ok( 201 === $s && $orig['id'] === $rep['replyTo'] && 'Original for a reply' === ( $rep['reply']['text'] ?? '' ) && $sam === $rep['reply']['by'], 'a reply carries the message it answers', $rep );
	list( , $rep0 ) = $t->call( $alex, 'POST', '/messages', array( 'text' => 'reply to nothing', 'replyTo' => 999999 ) );
	$t->ok( 0 === $rep0['replyTo'] && null === $rep0['reply'], 'a reply to a missing message is a plain message', $rep0 );

	list( $s ) = $t->call( $sam, 'PATCH', '/messages/' . $rep['id'], array( 'text' => 'hijack' ) );
	$t->ok( 403 === $s, "you can't edit someone else's message", $s );
	list( $s ) = $t->call( $admin, 'PATCH', '/messages/' . $rep['id'], array( 'text' => 'hijack' ) );
	$t->ok( 403 === $s, "not even an administrator can edit someone else's words", $s );
	$admin_name = get_userdata( $admin )->display_name;
	list( $s, $ed ) = $t->call( $alex, 'PATCH', '/messages/' . $rep['id'], array( 'text' => "@{$sam_name} replying, and @{$admin_name}", 'mentions' => array( $sam, $admin ) ) );
	$t->ok( 200 === $s && $ed['editedAt'] > 0 && 2 === count( $ed['mentions'] ) && $orig['id'] === $ed['replyTo'], 'you can edit your own message: new text, mentions kept, marked edited', $ed );
	$t->ok( array( 'mention' ) === array_values( $notes_about( $sam, 'message', $rep['id'] ) ), 'someone already mentioned is not told again by an edit', $notes_about( $sam, 'message', $rep['id'] ) );
	$t->ok( in_array( 'mention', $notes_about( $admin, 'message', $rep['id'] ), true ), 'someone newly mentioned by an edit is told', $notes_about( $admin, 'message', $rep['id'] ) );
	list( $s ) = $t->call( $alex, 'PATCH', '/messages/' . $rep['id'], array( 'text' => '  ' ) );
	$t->ok( 400 === $s, 'a message without files cannot be edited to nothing', $s );

	update_option( NovaWork\Settings::OPTION, array_merge( (array) get_option( NovaWork\Settings::OPTION, array() ), array( 'message_edits' => 'never' ) ) );
	list( $s ) = $t->call( $alex, 'PATCH', '/messages/' . $rep['id'], array( 'text' => 'no edits allowed' ) );
	$t->ok( 403 === $s, 'message_edits = never: no edits', $s );
	list( $s ) = $t->call( $alex, 'DELETE', '/messages/' . $rep0['id'] );
	$t->ok( 403 === $s, 'message_edits = never: members cannot delete their own either', $s );
	update_option( NovaWork\Settings::OPTION, array_merge( (array) get_option( NovaWork\Settings::OPTION, array() ), array( 'message_edits' => 'window', 'edit_minutes' => 15 ) ) );
	$wpdb->update( $wpdb->prefix . 'novawork_messages', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'id' => $rep0['id'] ) );
	list( $s ) = $t->call( $alex, 'PATCH', '/messages/' . $rep0['id'], array( 'text' => 'too late' ) );
	$t->ok( 403 === $s, 'message_edits = window: an hour-old message is past a 15-minute window', $s );
	list( $s ) = $t->call( $alex, 'PATCH', '/messages/' . $rep['id'], array( 'text' => "@{$sam_name} in time" ) );
	$t->ok( 200 === $s, 'message_edits = window: a new message can still be edited', $s );
	list( , $sync ) = $t->call( $alex, 'GET', '/sync' );
	$t->ok( 'window' === $sync['settings']['messageEdits'] && 15 === $sync['settings']['editMinutes'], 'the app gets the edit rule', $sync['settings'] );
	update_option( NovaWork\Settings::OPTION, array_merge( (array) get_option( NovaWork\Settings::OPTION, array() ), array( 'message_edits' => 'always' ) ) );

	list( $s ) = $t->call( $sam, 'DELETE', '/messages/' . $rep0['id'] );
	$t->ok( 403 === $s, "you can't delete someone else's message", $s );
	list( $s ) = $t->call( $admin, 'DELETE', '/messages/' . $rep0['id'] );
	$t->ok( 200 === $s, "an administrator can delete anyone's message", $s );
	$t->call( $alex, 'PATCH', '/messages/' . $rep['id'], array( 'text' => "@{$sam_name} in time, edited again" ) );
	list( , $cur ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $sync['cursor'] ) );
	$t->ok( in_array( $rep0['id'], $cur['deleted']['messages'] ?? array(), true ) && in_array( $rep['id'], array_column( $cur['data']['messages'] ?? array(), 'id' ), true ), 'edits and deletes reach everyone through sync', $cur['deleted'] ?? $cur );
	list( $s ) = $t->call( $sam, 'DELETE', '/messages/' . $orig['id'] );
	$t->ok( 200 === $s && array() === $notes_about( $alex, 'message', $orig['id'] ), 'you can delete your own message; its notifications go with it', $s );
	list( , $after ) = $t->call( $alex, 'GET', '/messages', array( 'q' => 'in time' ) );
	$t->ok( 1 === count( $after ) && $orig['id'] === $after[0]['replyTo'] && null === $after[0]['reply'], 'a reply to a deleted message keeps its place, with nothing to quote', $after );

	list( , $c1 ) = $t->call( $sam, 'POST', '/comments', array( 'objectType' => 'task', 'objectId' => $lt['id'], 'text' => 'First comment' ) );
	list( , $c2 ) = $t->call( $alex, 'POST', '/comments', array( 'objectType' => 'task', 'objectId' => $lt['id'], 'text' => 'A reply', 'replyTo' => $c1['id'] ) );
	$t->ok( $c1['id'] === $c2['replyTo'], 'a comment can reply to a comment on the same task', $c2 );
	list( , $c3 ) = $t->call( $alex, 'POST', '/comments', array( 'objectType' => 'decision', 'objectId' => $ld['id'], 'text' => 'Elsewhere', 'replyTo' => $c1['id'] ) );
	$t->ok( 0 === $c3['replyTo'], 'but not to a comment somewhere else', $c3 );
	list( $s ) = $t->call( $sam, 'PATCH', '/comments/' . $c2['id'], array( 'text' => 'hijack' ) );
	$t->ok( 403 === $s, "you can't edit someone else's comment", $s );
	list( $s, $c2e ) = $t->call( $alex, 'PATCH', '/comments/' . $c2['id'], array( 'text' => 'A better reply' ) );
	$t->ok( 200 === $s && 'A better reply' === $c2e['text'] && $c2e['editedAt'] > 0, 'you can edit your own comment', $c2e );
	list( $s ) = $t->call( $sam, 'DELETE', '/comments/' . $c2['id'] );
	$t->ok( 403 === $s, "you can't delete someone else's comment", $s );
	list( $s ) = $t->call( $alex, 'DELETE', '/comments/' . $c2['id'] );
	$t->ok( 200 === $s && null === ( new NovaWork\Data\CommentRepository() )->find( $c2['id'] ), 'you can delete your own comment', $s );
	list( $s ) = $t->call( $admin, 'DELETE', '/comments/' . $c1['id'] );
	$t->ok( 200 === $s, "an administrator can delete anyone's comment", $s );

	// ------------------------------------------------------------------
	$t->section( 'Search' );
	list( , $found ) = $t->call( $sam, 'GET', '/search', array( 'q' => 'due soon' ) );
	$t->ok( in_array( $due['id'], array_column( $found['tasks'], 'id' ), true ), 'finds a task by title' );
	list( , $found ) = $t->call( $sam, 'GET', '/search', array( 'q' => 'refund' ) );
	$t->ok( in_array( $d1['id'], array_column( $found['decisions'], 'id' ), true ), 'finds a decision' );
	list( , $found ) = $t->call( $sam, 'GET', '/search', array( 'q' => '100%_' ) );
	$t->ok( 200 === 200 && ! $found['tasks'], 'LIKE wildcards in the query are literal' );

	// ------------------------------------------------------------------
	$t->section( 'Notifications' );
	$alex_n = ( new NovaWork\Data\NotificationRepository() )->recent_for( $alex, 100 );
	list( , $mk ) = $t->call( $sam, 'POST', '/notifications/read', array( 'ids' => array_map( 'intval', array_column( $alex_n, 'id' ) ) ) );
	$t->ok( 0 === $mk['marked'], "sam can't mark alex's notifications read" );
	list( , $mk ) = $t->call( $sam, 'POST', '/notifications/read', array( 'all' => true ) );
	$left = array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $sam, 100 ), fn( $n ) => null === $n['read_at'] );
	$t->ok( $mk['marked'] > 0 && ! $left, 'mark all as read' );

	// A message that is only a file says what was shared.
	$png  = wp_tempnam( 'nw-share.png' );
	file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) );
	wp_set_current_user( $alex );
	$share = Files::store( array( 'name' => 'plan.png', 'type' => 'image/png', 'tmp_name' => $png, 'error' => 0, 'size' => filesize( $png ) ), true );
	if ( ! is_wp_error( $share ) ) {
		$aid = ( new NovaWork\Data\AttachmentRepository() )->insert( array_merge( $share, array( 'user_id' => $alex ) ) );
		list( , $fm ) = $t->call( $alex, 'POST', '/messages', array( 'text' => '', 'attachmentIds' => array( $aid ) ) );
		$fn = array_values( array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $sam, 100 ), fn( $n ) => 'message' === $n['object_type'] && (int) $n['object_id'] === (int) $fm['id'] ) );
		$fd = $fn ? json_decode( $fn[0]['data'], true ) : array();
		$t->ok( array( 'plan.png' ) === ( $fd['files'] ?? null ) && 1 === ( $fd['count'] ?? 0 ), 'a chat notification for files only names what was shared', $fd );
	} else {
		$t->ok( false, 'store a test file', $share->get_error_message() );
	}
	@unlink( $png );

	// ------------------------------------------------------------------
	$t->section( 'Workspace activity (Notifications → Workspace)' );
	$feed = function ( $type, $id ) use ( $wpdb ) {
		return $wpdb->get_col( $wpdb->prepare( 'SELECT verb FROM %i WHERE object_type = %s AND object_id = %d ORDER BY id', $wpdb->prefix . 'novawork_activity', $type, $id ) );
	};
	list( , $ap ) = $t->call( $alex, 'POST', '/projects', array( 'name' => 'Activity project' ) );
	$t->ok( array( 'project_added' ) === $feed( 'project', $ap['id'] ), 'adding a project is in the feed' );
	list( , $at ) = $t->call( $alex, 'POST', '/tasks', array( 'title' => 'Activity task', 'projectId' => $ap['id'] ) );
	$t->ok( array( 'task_added' ) === $feed( 'task', $at['id'] ), 'adding a task is in the feed' );
	$t->call( $alex, 'PATCH', '/tasks/' . $at['id'], array( 'title' => 'Activity task renamed' ) );
	$t->ok( array( 'task_added' ) === $feed( 'task', $at['id'] ), 'an ordinary edit is not' );
	$t->call( $alex, 'PATCH', '/tasks/' . $at['id'], array( 'status' => 'done' ) );
	$t->call( $alex, 'PATCH', '/tasks/' . $at['id'], array( 'status' => 'now' ) );
	$t->call( $alex, 'PATCH', '/tasks/' . $at['id'], array( 'status' => 'done' ) );
	$t->ok( array( 'task_added', 'task_done' ) === $feed( 'task', $at['id'] ), 'finishing a task is in the feed once, even when ticked twice', $feed( 'task', $at['id'] ) );
	$t->call( $alex, 'PATCH', '/projects/' . $ap['id'], array( 'status' => 'done' ) );
	$t->ok( in_array( 'project_done', $feed( 'project', $ap['id'] ), true ), 'finishing a project is in the feed' );
	list( , $ad ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Activity decision?', 'voting' => true, 'options' => array( array( 'label' => 'Yes' ), array( 'label' => 'No' ) ) ) );
	$t->ok( array( 'decision_opened', 'vote_opened' ) === $feed( 'decision', $ad['id'] ), 'opening a decision with a vote: both in the feed', $feed( 'decision', $ad['id'] ) );
	$t->call( $sam, 'POST', '/comments', array( 'objectType' => 'decision', 'objectId' => $ad['id'], 'text' => 'Yes, I think' ) );
	$t->call( $admin, 'POST', '/decisions/' . $ad['id'] . '/decide', array( 'answer' => 'Yes' ) );
	$t->ok( array( 'decision_opened', 'vote_opened', 'commented', 'decision_made' ) === $feed( 'decision', $ad['id'] ), 'comments and the decision itself are in the feed', $feed( 'decision', $ad['id'] ) );
	list( , $snap ) = $t->call( $sam, 'GET', '/sync' );
	$made = array_values( array_filter( $snap['data']['activity'], fn( $a ) => 'decision_made' === $a['verb'] && $ad['id'] === $a['objectId'] ) );
	$t->ok( 1 === count( $made ) && $admin === $made[0]['actor'] && 'Yes' === $made[0]['data']['answer'], 'every member gets the feed with sync, who did it and what', $made );
	list( $s, $older ) = $t->call( $sam, 'GET', '/activity', array( 'beforeId' => $made[0]['id'], 'limit' => 3 ) );
	$t->ok( 200 === $s && 3 === count( $older ) && $older[0]['id'] < $made[0]['id'], 'older activity pages back by id', $older );
	list( , $inc ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $snap['cursor'] ) );
	$t->call( $alex, 'POST', '/tasks', array( 'title' => 'Activity later' ) );
	list( , $inc ) = $t->call( $sam, 'GET', '/sync', array( 'since' => $snap['cursor'] ) );
	$t->ok( in_array( 'task_added', array_column( $inc['data']['activity'] ?? array(), 'verb' ), true ), 'new activity reaches everyone through incremental sync' );
	$rows_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'novawork_activity' );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s WHERE object_type = %s AND object_id = %d', $wpdb->prefix . 'novawork_activity', gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'project', $ap['id'] ) );
	( new NovaWork\Data\ActivityRepository() )->prune( NovaWork\Settings::int( 'activity_days' ) );
	$t->ok( array() === $feed( 'project', $ap['id'] ) && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'novawork_activity' ) === $rows_before - 2, 'rows older than activity_days (30) are pruned, the rest stay' );

	list( , $gone ) = $t->call( $sam, 'POST', '/tasks', array( 'title' => 'Soon deleted', 'assignees' => array( $alex ) ) );
	$t->ok( 1 === count( array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $alex, 100 ), fn( $n ) => 'task' === $n['object_type'] && (int) $n['object_id'] === $gone['id'] ) ), 'alex is told about the assignment' );
	$t->call( $sam, 'DELETE', '/tasks/' . $gone['id'] );
	$t->ok( ! array_filter( ( new NovaWork\Data\NotificationRepository() )->recent_for( $alex, 100 ), fn( $n ) => 'task' === $n['object_type'] && (int) $n['object_id'] === $gone['id'] ), 'deleting a task removes the notifications about it' );
	$t->ok( array( 'task_added' ) === $feed( 'task', $gone['id'] ), 'the team feed keeps its row (with the title)' );

	// ------------------------------------------------------------------
	$t->section( 'Settings (wp-admin screen)' );
	list( $s ) = $t->call( $alex, 'GET', '/settings' );
	$t->ok( 403 === $s, 'a member who is not an administrator cannot read the settings', $s );
	list( $s ) = $t->call( $alex, 'POST', '/settings', array( 'values' => array( 'poll_seconds' => 9 ) ) );
	$t->ok( 403 === $s, '… or change them', $s );
	list( $s, $st ) = $t->call( $admin, 'GET', '/settings' );
	$t->ok( 200 === $s && 'path' === $st['values']['location'] && isset( $st['site']['appUrl'], $st['defaults']['idle_minutes'] ), 'an administrator gets values, defaults and site facts', $st['site'] ?? $st );
	// Fontshare (WordPress.org review): off until an administrator opts in, offered once.
	$fs = (array) get_option( NovaWork\Settings::OPTION, array() );
	unset( $fs['load_fontshare'] );
	update_option( NovaWork\Settings::OPTION, $fs );
	delete_option( NovaWork\Settings::FONTS_OFFER_OPTION );
	list( , $st ) = $t->call( $admin, 'GET', '/settings' );
	$t->ok( false === $st['values']['load_fontshare'] && false === $st['defaults']['load_fontshare'] && true === $st['site']['fontsOffer'], 'Fontshare is off by default, and the screen offers it', $st['site'] );
	$t->ok( ! in_array( NovaWork\App\Assets::FONTS, NovaWork\App\Assets::styles(), true ), 'nothing loads from Fontshare until then' );
	list( $s ) = $t->call( $alex, 'DELETE', '/settings/fonts-offer' );
	$t->ok( 403 === $s, 'a member cannot answer the offer', $s );
	list( $s, $st ) = $t->call( $admin, 'DELETE', '/settings/fonts-offer' );
	$t->ok( 200 === $s && false === $st['site']['fontsOffer'] && false === $st['values']['load_fontshare'], '"No thanks" keeps it off and ends the offer', $st['site'] ?? $st );
	delete_option( NovaWork\Settings::FONTS_OFFER_OPTION );
	list( $s, $st ) = $t->call( $admin, 'POST', '/settings', array( 'values' => array( 'load_fontshare' => true ) ) );
	$t->ok( 200 === $s && true === $st['values']['load_fontshare'] && false === $st['site']['fontsOffer'] && in_array( NovaWork\App\Assets::FONTS, NovaWork\App\Assets::styles(), true ), '"Turn them on" loads the typefaces and ends the offer', $st['site'] ?? $st );
	// NovaWork Pro button (1.1.0): administrators only; the filter can change or hide it.
	list( , $st ) = $t->call( $admin, 'GET', '/settings' );
	$t->ok( 'waitlist' === ( $st['site']['pro']['kind'] ?? '' ) && NovaWork\Admin\ProLink::WAITLIST_URL === $st['site']['pro']['url'] && '' !== $st['site']['pro']['label'], 'the settings screen gets the Pro waitlist button', $st['site']['pro'] ?? null );
	$novawork_pro = static function () {
		return array( 'label' => 'Upgrade to Pro', 'url' => 'https://example.com/upgrade', 'kind' => 'upgrade' );
	};
	add_filter( 'novawork_pro_link', $novawork_pro );
	list( , $st ) = $t->call( $admin, 'GET', '/settings' );
	$t->ok( array( 'label' => 'Upgrade to Pro', 'url' => 'https://example.com/upgrade', 'kind' => 'link' ) === $st['site']['pro'], 'an add-on can turn it into a plain "Upgrade to Pro" link', $st['site']['pro'] );
	remove_filter( 'novawork_pro_link', $novawork_pro );
	add_filter( 'novawork_pro_link', '__return_false' );
	list( , $st ) = $t->call( $admin, 'GET', '/settings' );
	$t->ok( null === $st['site']['pro'], '... or hide it', $st['site']['pro'] );
	remove_filter( 'novawork_pro_link', '__return_false' );
	list( $s, $bad ) = $t->call( $admin, 'POST', '/settings', array( 'values' => array( 'location' => 'moon', 'app_slug' => 'wp-admin', 'upload_types' => 'php, svg', 'idle_minutes' => 'soon', 'poll_seconds' => 9 ) ) );
	$errs = $bad['data']['errors'] ?? array();
	$t->ok( 400 === $s && isset( $errs['location'], $errs['app_slug'], $errs['upload_types'], $errs['idle_minutes'] ), 'wrong values come back by field', $errs );
	$t->ok( 4 === NovaWork\Settings::int( 'poll_seconds' ), 'and nothing is saved when any value is wrong', NovaWork\Settings::int( 'poll_seconds' ) );
	$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Team space', 'post_name' => 'team-space' ) );
	list( $s, $bad ) = $t->call( $admin, 'POST', '/settings', array( 'values' => array( 'app_slug' => 'team-space' ) ) );
	$t->ok( 400 === $s && isset( $bad['data']['errors']['app_slug'] ), "an address a page already uses is refused", $bad );
	list( $s, $ok ) = $t->call( $admin, 'POST', '/settings', array( 'values' => array( 'workspace_name' => '  Acme <b>HQ</b> ', 'app_slug' => 'Our Work', 'poll_seconds' => 999, 'upload_types' => '.PDF, png, png, exe', 'decision_votes' => false, 'load_fontshare' => '0' ) ) );
	$v = $ok['values'] ?? array();
	$t->ok( 200 === $s && 'Acme HQ' === $v['workspace_name'] && 'our-work' === $v['app_slug'] && 60 === $v['poll_seconds'] && array( 'pdf', 'png' ) === $v['upload_types'] && false === $v['decision_votes'] && false === $v['load_fontshare'], 'values are cleaned: text, address, clamped numbers, file types, switches off', $v );
	$t->ok( 'Acme HQ' === NovaWork\Settings::workspace_name() && str_ends_with( untrailingslashit( NovaWork\App\Route::url() ), 'our-work' ) || ! get_option( 'permalink_structure' ), 'the workspace has its name and new address', NovaWork\App\Route::url() );
	$t->ok( array( 'novawork' ) === array_slice( NovaWork\App\Route::old_slugs(), 0, 1 ) && get_option( NovaWork\App\Route::FLUSH_OPTION ), 'the old address is kept (it forwards) and rewrite rules will refresh', NovaWork\App\Route::old_slugs() );
	list( , $sync ) = $t->call( $alex, 'GET', '/sync' );
	$t->ok( 60 === $sync['settings']['pollSeconds'] && false === $sync['settings']['decisionVotes'], 'members get the new values with sync' );
	list( , $ok ) = $t->call( $admin, 'POST', '/settings', array( 'values' => array( 'location' => 'home' ) ) );
	$t->ok( trailingslashit( home_url( '/' ) ) === $ok['site']['appUrl'], 'home page mode: the workspace is the home page', $ok['site']['appUrl'] ?? $ok );
	list( , $ok ) = $t->call( $admin, 'POST', '/settings', array( 'values' => array( 'workspace_name' => '' ) ) );
	$t->ok( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) === NovaWork\Settings::workspace_name() || 'NovaWork' === NovaWork\Settings::workspace_name(), 'an empty name means the site title' );
	wp_delete_post( $page_id, true );

	// Reset emails (owner, 2026-11-22): a member's links to the NovaWork page.
	$mail = array( 'to' => 'x@example.com', 'subject' => 'Reset', 'message' => "Hi\r\n\r\n" . network_site_url( 'wp-login.php?action=rp&key=K3Y&login=alex', 'login' ) . "&wp_lang=en_US\r\n", 'headers' => '' );
	$out  = NovaWork\App\Login::reset_email( $mail, 'K3Y', 'alex', get_userdata( $alex ) );
	$t->ok( ! str_contains( $out['message'], 'wp-login.php' ) && str_contains( $out['message'], 'action=rp' ) && str_contains( $out['message'], 'key=K3Y' ) && str_contains( $out['message'], NovaWork\App\Route::login_url() ), "a member's reset email links to the workspace's page", $out['message'] );
	$sub  = wp_insert_user( array( 'user_login' => 'nw_sub_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	$out  = NovaWork\App\Login::reset_email( $mail, 'K3Y', 'someone', get_userdata( (int) $sub ) );
	$t->ok( $mail['message'] === $out['message'], "anyone else's keeps WordPress's link" );
	wp_delete_user( (int) $sub );

	// Invites (owner, 2026-11-22): no open sign-up; single-use links.
	list( $s ) = $t->call( $alex, 'POST', '/invites', array() );
	$t->ok( 403 === $s, 'only an administrator can invite', $s );
	list( $s, $inv ) = $t->call( $admin, 'POST', '/invites', array() );
	$t->ok( 201 === $s && str_contains( $inv['link'], 'action=join' ) && 'open' === $inv['invite']['status'], 'an administrator creates a link invite', $inv );
	parse_str( (string) wp_parse_url( $inv['link'], PHP_URL_QUERY ), $q );
	$token = (string) ( $q['invite'] ?? '' );
	$t->ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE token_hash = %s', $wpdb->prefix . 'novawork_invites', $token ) ), 'the table never holds the token itself, only its hash' );
	$t->ok( null !== ( new NovaWork\Data\InviteRepository() )->find_open( $token ), 'the token opens the invite' );
	list( $s ) = $t->call( $admin, 'POST', '/invites', array( 'email' => get_userdata( $sam )->user_email ) );
	$t->ok( 400 === $s, 'inviting someone who is already a member is refused', $s );
	$joined = NovaWork\Services\Invites::join_new( ( new NovaWork\Data\InviteRepository() )->find_open( $token ), 'Jo Newcomer', 'jo-' . wp_generate_password( 6, false ) . '@example.com', 'a-good-pass-123' );
	$t->ok( $joined instanceof WP_User && in_array( NovaWork\Access\Capabilities::ROLE, $joined->roles, true ) && 'Jo Newcomer' === $joined->display_name, 'a new person joins with the Member role', is_wp_error( $joined ) ? $joined->get_error_message() : $joined->roles );
	$t->ok( null === ( new NovaWork\Data\InviteRepository() )->find_open( $token ), 'the invite works once' );
	$t->ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE verb = %s AND actor_id = %d', $wpdb->prefix . 'novawork_activity', 'member_joined', $joined->ID ) ), 'the team feed says they joined' );
	list( , $inv2 ) = $t->call( $admin, 'POST', '/invites', array( 'email' => 'someone@example.com' ) );
	parse_str( (string) wp_parse_url( $inv2['link'], PHP_URL_QUERY ), $q2 );
	$mismatch = NovaWork\Services\Invites::join_new( ( new NovaWork\Data\InviteRepository() )->find_open( (string) $q2['invite'] ), 'Other', 'other@example.com', 'pw-pw-pw-1' );
	$t->ok( $mismatch instanceof WP_User && 'someone@example.com' === $mismatch->user_email, 'an invite for an email address always uses that address', is_wp_error( $mismatch ) ? $mismatch->get_error_message() : $mismatch->user_email );
	list( , $inv3 ) = $t->call( $admin, 'POST', '/invites', array() );
	parse_str( (string) wp_parse_url( $inv3['link'], PHP_URL_QUERY ), $q3 );
	$editor2 = wp_insert_user( array( 'user_login' => 'nw_ed2_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	NovaWork\Services\Invites::accept( ( new NovaWork\Data\InviteRepository() )->find_open( (string) $q3['invite'] ), get_userdata( (int) $editor2 ) );
	clean_user_cache( (int) $editor2 );
	$t->ok( user_can( (int) $editor2, NovaWork\Access\Capabilities::ACCESS ) && in_array( 'editor', get_userdata( (int) $editor2 )->roles, true ), 'someone with an account joins and keeps their role' );
	list( , $inv4 ) = $t->call( $admin, 'POST', '/invites', array() );
	parse_str( (string) wp_parse_url( $inv4['link'], PHP_URL_QUERY ), $q4 );
	$wpdb->update( $wpdb->prefix . 'novawork_invites', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $inv4['invite']['id'] ) );
	$t->ok( null === ( new NovaWork\Data\InviteRepository() )->find_open( (string) $q4['invite'] ), 'an expired invite no longer opens' );
	list( , $inv5 ) = $t->call( $admin, 'POST', '/invites', array() );
	list( $s ) = $t->call( $admin, 'DELETE', '/invites/' . $inv5['invite']['id'] );
	parse_str( (string) wp_parse_url( $inv5['link'], PHP_URL_QUERY ), $q5 );
	$t->ok( 200 === $s && null === ( new NovaWork\Data\InviteRepository() )->find_open( (string) $q5['invite'] ), 'a revoked invite no longer opens' );
	list( , $list ) = $t->call( $admin, 'GET', '/invites' );
	$t->ok( is_array( $list ) && ! array_filter( $list, fn( $r ) => isset( $r['token_hash'] ) || isset( $r['link'] ) ), 'the list never shows tokens or links' );
	wp_delete_user( (int) $editor2 );
	wp_delete_user( $joined->ID );
	wp_delete_user( $mismatch->ID );

	// Branding (owner, 2026-11-22): accent colour maths.
	$B = 'NovaWork\App\Branding';
	$t->ok( '#aabbcc' === $B::normalize( 'abc' ) && '#0d9488' === $B::normalize( 'rgb(13, 148, 136)' ) && '#dc2626' === $B::normalize( '#DC2626' ) && null === $B::normalize( 'rgb(300,0,0)' ) && null === $B::normalize( 'red' ), 'colours are read from #rgb, #rrggbb and rgb(), and anything else refused' );
	$t->ok( '' === $B::accent_css(), 'the default blue prints nothing (the prototype tokens stay exact)' );
	$ok_all = true;
	foreach ( array( '#ca8a04', '#fde047', '#1e3a8a', '#dc2626', '#ffffff', '#000000' ) as $c ) {
		$l = $B::tokens( $c, false );
		$d = $B::tokens( $c, true );
		$ok_all = $ok_all && $B::contrast( $l['accent-ink'], '#ffffff' ) >= 4.5 && $B::contrast( $d['accent-ink'], '#242321' ) >= 4.5 && $B::contrast( $l['accent-fg'], $c ) >= 2.5 && $B::contrast( $l['accent-on-soft'], $l['accent-soft'] ) >= 4.5;
	}
	$t->ok( $ok_all, 'any accent (yellow, navy, white…) keeps text readable in light and dark' );
	$t->ok( '#ffffff' === $B::tokens( '#ea580c', false )['accent-fg'] && '#1d1919' === $B::tokens( '#fde047', false )['accent-fg'], 'text on accent, auto: white on orange (3:1), dark on yellow' );
	update_option( NovaWork\Settings::OPTION, array_merge( (array) get_option( NovaWork\Settings::OPTION, array() ), array( 'accent_text' => 'dark' ) ) );
	$t->ok( '#1d1919' === $B::tokens( '#ea580c', false )['accent-fg'] && str_contains( $B::accent_css(), '--nw-accent-fg:#1d1919' ), 'text on accent can be forced dark (even on the default blue)' );
	update_option( NovaWork\Settings::OPTION, array_merge( (array) get_option( NovaWork\Settings::OPTION, array() ), array( 'accent_text' => 'white' ) ) );
	$t->ok( '#ffffff' === $B::tokens( '#fde047', false )['accent-fg'], '… or white' );
	update_option( NovaWork\Settings::OPTION, array_merge( (array) get_option( NovaWork\Settings::OPTION, array() ), array( 'accent_text' => 'auto' ) ) );
	list( $s, $bad ) = $t->call( $admin, 'POST', '/settings', array( 'values' => array( 'accent' => 'blue-ish', 'brand_icon' => 999999 ) ) );
	$t->ok( 400 === $s && isset( $bad['data']['errors']['accent'], $bad['data']['errors']['brand_icon'] ), 'a bad colour or a missing image is refused', $bad['data']['errors'] ?? $bad );
	list( , $ok ) = $t->call( $admin, 'POST', '/settings', array( 'values' => array( 'accent' => 'rgb(220, 38, 38)' ) ) );
	$t->ok( '#dc2626' === $ok['values']['accent'] && str_contains( $B::accent_css(), '--nw-accent:#dc2626' ), 'a custom accent is saved as hex and printed for the app' );

	// The "NovaWork member" checkbox on a profile (owner decision).
	$editor = wp_insert_user( array( 'user_login' => 'nw_editor_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'editor', 'display_name' => 'Ed Itor' ) );
	wp_set_current_user( $admin );
	$_POST = array( '_novawork_member_nonce' => wp_create_nonce( 'novawork_member' ), 'novawork_member' => '1' );
	NovaWork\Access\Profile::save( (int) $editor );
	clean_user_cache( (int) $editor );
	$t->ok( user_can( (int) $editor, NovaWork\Access\Capabilities::ACCESS ) && in_array( 'editor', get_userdata( (int) $editor )->roles, true ), 'ticking it makes an editor a member and keeps the role' );
	$t->ok( in_array( (int) $editor, NovaWork\Members::ids(), true ), 'they are listed as a member' );
	$_POST = array( '_novawork_member_nonce' => wp_create_nonce( 'novawork_member' ) );
	NovaWork\Access\Profile::save( (int) $editor );
	clean_user_cache( (int) $editor );
	$t->ok( ! user_can( (int) $editor, NovaWork\Access\Capabilities::ACCESS ), 'unticking it (nothing sent) removes access' );
	wp_set_current_user( $alex );
	$_POST = array( '_novawork_member_nonce' => wp_create_nonce( 'novawork_member' ), 'novawork_member' => '1' );
	NovaWork\Access\Profile::save( (int) $editor );
	clean_user_cache( (int) $editor );
	$t->ok( ! user_can( (int) $editor, NovaWork\Access\Capabilities::ACCESS ), 'only an administrator can tick it' );
	$_POST = array();
	wp_delete_user( (int) $editor );

	// ------------------------------------------------------------------
	$t->section( 'Personal data: export, erase, uninstall' );
	$ex = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$er = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	$t->ok( isset( $ex['novawork-messages'], $ex['novawork-comments'], $ex['novawork-time'], $ex['novawork-votes'], $ex['novawork-notifications'], $ex['novawork-activity'], $ex['novawork-profile'], $er['novawork'] ), 'NovaWork registers its exporters and eraser with WordPress' );
	$pd_mail = 'pd-' . wp_generate_password( 6, false ) . '@example.com';
	$pd      = wp_insert_user( array( 'user_login' => 'nw_pd_' . wp_generate_password( 6, false ), 'user_email' => $pd_mail, 'user_pass' => wp_generate_password(), 'role' => NovaWork\Access\Capabilities::ROLE, 'display_name' => 'Pat Data' ) );
	list( , $pm ) = $t->call( (int) $pd, 'POST', '/messages', array( 'text' => 'Personal hello' ) );
	list( , $pt ) = $t->call( (int) $pd, 'POST', '/tasks', array( 'title' => 'Pat task', 'assignees' => array( (int) $pd, $alex ) ) );
	$t->call( (int) $pd, 'POST', '/comments', array( 'objectType' => 'task', 'objectId' => $pt['id'], 'text' => 'Pat comment' ) );
	( new NovaWork\Data\TimeEntryRepository() )->insert( array( 'user_id' => (int) $pd, 'task_id' => $pt['id'], 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 300 ), 'ended_at' => gmdate( 'Y-m-d H:i:s', time() - 180 ) ) );
	update_option( NovaWork\Settings::OPTION, array_merge( (array) get_option( NovaWork\Settings::OPTION, array() ), array( 'decision_votes' => true ) ) ); // An earlier check turned votes off.
	list( , $pdd ) = $t->call( $alex, 'POST', '/decisions', array( 'question' => 'Pat decides?', 'ownerId' => (int) $pd, 'voting' => true, 'options' => array( array( 'label' => 'A' ), array( 'label' => 'B' ) ) ) );
	$t->call( (int) $pd, 'POST', '/decisions/' . $pdd['id'] . '/vote', array( 'option' => 'o1' ) );
	$out = array();
	foreach ( array( 'messages', 'comments', 'time', 'votes', 'activity', 'profile' ) as $k ) {
		$page   = call_user_func( $ex[ 'novawork-' . $k ]['callback'], $pd_mail, 1 );
		$out[ $k ] = $page;
	}
	$vals = fn( $page ) => array_merge( ...array_map( fn( $i ) => array_column( $i['data'], 'value' ), $page['data'] ?: array( array( 'data' => array() ) ) ) );
	$t->ok( in_array( 'Personal hello', $vals( $out['messages'] ), true ) && in_array( 'Pat comment', $vals( $out['comments'] ), true ) && in_array( 'Pat task', $vals( $out['time'] ), true ) && $out['messages']['done'], 'the export has their messages, comments and time', array_map( fn( $p ) => count( $p['data'] ), $out ) );
	$t->ok( 1 === count( $out['votes']['data'] ) && 1 === count( $out['profile']['data'] ) && count( $out['activity']['data'] ) >= 1, 'and their votes, team-feed rows and profile' );
	$empty = call_user_func( $ex['novawork-messages']['callback'], 'nobody-' . wp_generate_password( 6, false ) . '@example.com', 1 );
	$t->ok( array() === $empty['data'] && $empty['done'], 'an address with no account exports nothing' );

	$round = 0;
	do {
		$res = call_user_func( $er['novawork']['callback'], $pd_mail, ++$round );
	} while ( ! $res['done'] && $round < 20 );
	$count = fn( $table, $col ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->prefix . 'novawork_' . $table, $col, (int) $pd ) );
	$t->ok( $res['done'] && 0 === $count( 'messages', 'user_id' ) + $count( 'comments', 'user_id' ) + $count( 'time_entries', 'user_id' ) + $count( 'decision_votes', 'user_id' ) + $count( 'notifications', 'user_id' ) + $count( 'activity', 'actor_id' ) + $count( 'task_assignees', 'user_id' ), 'erasing removes their messages, comments, time, votes, notifications, feed rows and assignments', $res );
	$kept = ( new NovaWork\Data\TaskRepository() )->find( $pt['id'] );
	$dec  = ( new NovaWork\Data\DecisionRepository() )->find( $pdd['id'] );
	$t->ok( $kept && 0 === (int) $kept['created_by'] && $dec && 0 === (int) $dec['owner_id'] && $res['items_retained'] && $res['messages'], 'their task and the decision they owned stay with the team, no longer naming them', $res['messages'] );
	$t->ok( '' === get_user_meta( (int) $pd, NovaWork\Members::COLOR_META, true ), 'their NovaWork profile details are gone' );
	wp_delete_user( (int) $pd );

	$t->ok( false === NovaWork\Install\Uninstaller::site(), 'uninstall removes nothing while "delete everything" is off' );
	$t->ok( is_readable( dirname( __DIR__, 2 ) . '/uninstall.php' ) && str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ), 'WP_UNINSTALL_PLUGIN' ), 'uninstall.php exists and only runs from WordPress' );

	// ------------------------------------------------------------------
	$t->section( 'First run: sample items' );
	delete_option( \NovaWork\Services\Samples::OPTION );
	\NovaWork\Services\Samples::mark_due();
	$t->ok( 'due' === get_option( \NovaWork\Services\Samples::OPTION ), 'activation marks the samples as due' );
	\NovaWork\Services\Samples::maybe_create( $admin );
	$t->ok( 'made' === get_option( \NovaWork\Services\Samples::OPTION ) && 0 === \NovaWork\Services\Samples::count(), 'a workspace that already has work gets none' );
	\NovaWork\Services\Samples::mark_due();
	$t->ok( 'made' === get_option( \NovaWork\Services\Samples::OPTION ), 'reactivating never asks again' );
	$made = \NovaWork\Services\Samples::create( $admin );
	$t->ok( 3 === \NovaWork\Services\Samples::count(), 'an empty workspace gets three: a project, a task and a decision' );
	list( , $snap ) = $t->call( $admin, 'GET', '/sync' );
	$s_task = current( array_filter( $snap['data']['tasks'], static fn( $x ) => $x['id'] === $made['task'] ) );
	$s_proj = current( array_filter( $snap['data']['projects'], static fn( $x ) => $x['id'] === $made['project'] ) );
	$s_dec  = current( array_filter( $snap['data']['decisions'], static fn( $x ) => $x['id'] === $made['decision'] ) );
	$t->ok( $s_task && true === $s_task['sample'] && 'now' === $s_task['status'] && array( $admin ) === $s_task['assignees'] && $made['project'] === $s_task['projectId'], 'the task is in Now, in the project, assigned to whoever opened the app' );
	$t->ok( $s_proj && true === $s_proj['sample'] && $s_dec && true === $s_dec['sample'] && 'open' === $s_dec['status'], 'the app sees all three as samples' );
	$keep = ( new TaskRepository() )->insert(
		array(
			'project_id' => $made['project'],
			'title'      => 'A real task someone added to the sample project',
			'status'     => 'next',
		)
	);
	( new \NovaWork\Data\CommentRepository() )->insert(
		array(
			'object_type' => 'task',
			'object_id'   => $made['task'],
			'user_id'     => $alex,
			'body'        => 'A comment on the sample task',
		)
	);
	$t->call( $sam, 'POST', '/timer/stop' );
	$t->call( $sam, 'POST', '/timer/start', array( 'taskId' => $made['task'] ) );
	list( $s ) = $t->call( $alex, 'DELETE', '/samples' );
	$t->ok( 403 === $s, 'members cannot remove them' );
	list( $s, $gone ) = $t->call( $admin, 'DELETE', '/samples' );
	$t->ok( 200 === $s && 3 === $gone['removed'] && 0 === \NovaWork\Services\Samples::count(), 'an administrator removes all three in one step' );
	$t->ok( array() === ( new \NovaWork\Data\CommentRepository() )->for_object( 'task', $made['task'] ), 'their comments go with them' );
	$t->ok( null === $entries->running_for( $sam ), 'a timer running on the sample task stops' );
	$t->ok( 0 === (int) ( ( new TaskRepository() )->find( $keep )['project_id'] ?? -1 ), 'real work in the sample project stays, without a project' );

	// ------------------------------------------------------------------
	$t->section( 'Translations for the JS bundles' );
	$sources = \NovaWork\App\Translations::sources( 'app' );
	$t->ok( in_array( 'assets/src/js/views/board.js', $sources, true ) && in_array( 'assets/src/js/lib/format.js', $sources, true ), 'the build lists the app bundle’s sources, shared chunk included' );
	$t->ok( in_array( 'assets/src/js/settings.js', \NovaWork\App\Translations::sources( 'settings' ), true ), 'and the settings bundle’s' );
	$lang_dir = WP_LANG_DIR . '/plugins';
	wp_mkdir_p( $lang_dir );
	$jed      = static fn( string $source, array $msgs ): string => (string) wp_json_encode(
		array(
			'source'      => $source,
			'domain'      => 'messages',
			'locale_data' => array( 'messages' => array( '' => array( 'domain' => 'messages', 'lang' => 'xx_XX' ) ) + $msgs ),
		)
	);
	$lang_files = array(
		$lang_dir . '/novawork-xx_XX-' . md5( 'assets/src/js/views/board.js' ) . '.json' => $jed( 'assets/src/js/views/board.js', array( 'Board' => array( 'Xboard' ) ) ),
		$lang_dir . '/novawork-xx_XX-' . md5( 'assets/src/js/lib/format.js' ) . '.json'  => $jed( 'assets/src/js/lib/format.js', array( 'Just now' => array( 'Xnow' ) ) ),
	);
	foreach ( $lang_files as $path => $json ) {
		file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
	}
	$merged = json_decode( (string) \NovaWork\App\Translations::merge( $sources, 'xx_XX' ), true );
	$msgs   = $merged['locale_data']['messages'] ?? array();
	$t->ok( array( 'Xboard' ) === ( $msgs['Board'] ?? null ) && array( 'Xnow' ) === ( $msgs['Just now'] ?? null ) && isset( $msgs['']['lang'] ), 'one source file’s JSON per module, merged into one for the bundle' );
	$t->ok( false === \NovaWork\App\Translations::merge( $sources, 'yy_YY' ), 'nothing to merge leaves WordPress’s own lookup alone' );
	foreach ( array_keys( $lang_files ) as $path ) {
		wp_delete_file( $path );
	}

	// ------------------------------------------------------------------
	$t->section( 'Removing a member' );
	$t->call( $alex, 'PATCH', '/tasks/' . $due['id'], array( 'assignees' => array( $sam, $alex ) ) );
	$t->call( $sam, 'POST', '/timer/start', array( 'taskId' => $due['id'] ) );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sam, $alex );
	$after = $repo->present( (array) $repo->find( $due['id'] ) );
	$t->ok( array( $alex ) === $after['assignees'], 'a deleted user is unassigned; the task stays' );
	$sub_after = ( new NovaWork\Data\SubtaskRepository() )->find( $s2 );
	$t->ok( $sub_after && 0 === (int) $sub_after['assignee_id'], 'their subtasks stay, with no one assigned' );
	$t->ok( null === $entries->running_for( $sam ), "their running timer is stopped" );
	list( , $snap ) = $t->call( $alex, 'GET', '/sync' );
	$t->ok( true === $snap['full'] && ! in_array( $sam, array_column( $snap['members'], 'id' ), true ), 'they are no longer listed as a member' );
} catch ( Throwable $e ) {
	$t->fail[] = 'Exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
	WP_CLI::log( '  ✗ ' . end( $t->fail ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_set_current_user( 0 );
	foreach ( array_diff( glob( Files::dir() . '/*' ) ?: array(), $files_before ) as $leftover ) {
		wp_delete_file( $leftover );
	}
	if ( isset( $tmp ) ) {
		@unlink( $tmp );
	}
}

WP_CLI::log( sprintf( "\n%d passed, %d failed. (Rolled back: the site keeps no test data.)", $t->pass, count( $t->fail ) ) );
if ( $t->fail ) {
	WP_CLI::error( "Failures:\n - " . implode( "\n - ", $t->fail ) );
}
WP_CLI::success( 'NovaWork API tests passed.' );

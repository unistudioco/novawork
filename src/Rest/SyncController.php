<?php
/**
 * The polling endpoint.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Data\ActivityRepository;
use NovaWork\Data\ChangeLog;
use NovaWork\Data\CommentRepository;
use NovaWork\Data\Db;
use NovaWork\Data\DecisionRepository;
use NovaWork\Data\MessageRepository;
use NovaWork\Data\NotificationRepository;
use NovaWork\Data\ProjectRepository;
use NovaWork\Data\ReadState;
use NovaWork\Data\Repository;
use NovaWork\Data\TaskRepository;
use NovaWork\Data\TimeEntryRepository;
use NovaWork\Members;
use NovaWork\Services\Files;
use NovaWork\Services\Notifier;
use NovaWork\Services\Timer;
use NovaWork\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /sync?since=<cursor>
 *
 * No cursor (the app's first load), or one the log can no longer answer
 * (older than the pruned rows, or newer than the log after a reset), returns a
 * full snapshot. Its cursor may be 0 on a site with no activity yet; 0 is then
 * a valid cursor meaning "everything since the start".
 * Otherwise it returns only the objects that changed after the cursor, read
 * fresh from their tables, plus the ids of deleted ones. Members, presence,
 * the chat unread count and a fresh REST nonce come with every response, so
 * one request per poll keeps the whole app current.
 */
final class SyncController extends Controller {

	/**
	 * Most change-log rows handled per poll; "more" asks the client to poll again.
	 */
	public const PAGE = 500;

	/**
	 * Messages in a full snapshot; older ones come from GET /messages.
	 */
	public const MESSAGES = 100;

	/**
	 * Notifications in a full snapshot.
	 */
	public const NOTIFICATIONS = 100;

	/**
	 * Workspace activity in a full snapshot; older rows come from GET /activity.
	 */
	public const ACTIVITY = 50;

	/**
	 * Days of time entries in a full snapshot (covers "today" and "this week").
	 */
	public const ENTRY_DAYS = 8;

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route(
			'/sync',
			WP_REST_Server::READABLE,
			array( $this, 'sync' ),
			array(
				'since'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				// The app's last activity (server ms): see Members::mark_active().
				'active' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			)
		);
	}

	/**
	 * Change-log type => [repository, payload key].
	 *
	 * @return array<string, array{0: Repository, 1: string}>
	 */
	private function types(): array {
		return array(
			'project'      => array( new ProjectRepository(), 'projects' ),
			'task'         => array( new TaskRepository(), 'tasks' ),
			'decision'     => array( new DecisionRepository(), 'decisions' ),
			'comment'      => array( new CommentRepository(), 'comments' ),
			'message'      => array( new MessageRepository(), 'messages' ),
			'time_entry'   => array( new TimeEntryRepository(), 'timeEntries' ),
			'notification' => array( new NotificationRepository(), 'notifications' ),
			'activity'     => array( new ActivityRepository(), 'activity' ),
		);
	}

	/**
	 * One poll.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function sync( WP_REST_Request $request ): WP_REST_Response {
		$me    = self::me();
		$since = $request->get_param( 'since' );
		if ( null !== $request->get_param( 'active' ) ) {
			Members::mark_active( $me, (int) $request->get_param( 'active' ) );
		}

		// Before this poll counts as a sign of life: back after a folded laptop
		// or a closed tab, a timer left running stops where they went quiet.
		$away = Timer::stop_if_away( $me );
		Members::touch( $me );
		Timer::sweep_away();
		Notifier::check_due();

		// Read the bound first: anything written after it waits for the next poll.
		$latest = ChangeLog::latest_id();
		$full   = null === $since || (int) $since > $latest || (int) $since < ChangeLog::floor();

		$body = $full ? $this->snapshot( $me, $latest ) : $this->changes( $me, (int) $since, $latest );

		$read_id = ReadState::get( $me );
		$body   += array(
			'now'       => Db::now_ms(),
			'me'        => $me,
			// The latest activity on any of this member's devices (idle rule).
			'activeAt'  => Members::active_at( $me ),
			'canManage' => current_user_can( \NovaWork\Access\Capabilities::MANAGE ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'members'   => Members::all(),
			'agents'    => Members::agents(),
			'chat'      => array(
				'readId' => $read_id,
				'unread' => ( new MessageRepository() )->count_unread( $read_id, $me ),
			),
			'settings'  => array(
				'pollSeconds'    => Settings::int( 'poll_seconds', 2 ),
				'idleMinutes'    => Settings::int( 'idle_minutes' ),
				'idleGrace'      => Settings::int( 'idle_grace', 10 ),
				'nowLimit'       => Settings::int( 'now_limit' ),
				'doneShown'      => Settings::int( 'done_shown' ),
				'teamOrder'      => Settings::team_order(),
				'decideRule'     => Settings::decide_rule(),
				'editRule'       => Settings::edit_rule(),
				'decisionVotes'  => (bool) Settings::get( 'decision_votes' ),
				'voteOrder'      => Settings::vote_order(),
				'desktopAlerts'  => (bool) Settings::get( 'desktop_alerts' ),
				'messageEdits'   => Settings::message_edits(),
				'editMinutes'    => Settings::int( 'edit_minutes' ),
				'dueSoonDays'    => Settings::int( 'due_soon_days', 0 ),
				'weekStart'      => (int) Settings::get( 'week_start' ),
				'timezone'       => wp_timezone_string(),
				'uploadMaxBytes' => Settings::upload_max_bytes(),
				'uploadTypes'    => array_keys( Files::allowed_mimes() ),
			),
		);

		if ( $away ) {
			// The app says why the timer stopped.
			$body['timerAway'] = $away;
		}

		$response = self::respond( $body );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Everything a member needs to draw the app.
	 *
	 * @param int $me     Member.
	 * @param int $cursor Change id the snapshot is current to.
	 * @return array<string, mixed>
	 */
	private function snapshot( int $me, int $cursor ): array {
		$projects      = new ProjectRepository();
		$tasks         = new TaskRepository();
		$decisions     = new DecisionRepository();
		$comments      = new CommentRepository();
		$messages      = new MessageRepository();
		$entries       = new TimeEntryRepository();
		$notifications = new NotificationRepository();

		$from     = gmdate( 'Y-m-d H:i:s', time() - self::ENTRY_DAYS * DAY_IN_SECONDS );
		$recent   = $entries->overlapping( $from, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
		$ids      = array_column( $recent, 'id' );
		$all_runs = array_filter( $entries->running(), static fn( $row ) => ! in_array( $row['id'], $ids, true ) );

		return array(
			'cursor'  => $cursor,
			'full'    => true,
			'more'    => false,
			'data'    => array(
				'projects'      => $projects->present_many( $projects->all() ),
				'tasks'         => $tasks->present_many( $tasks->all() ),
				'decisions'     => $decisions->present_many( $decisions->all() ),
				'comments'      => $comments->present_many( $comments->all() ),
				'messages'      => $messages->present_many( $messages->page( 0, self::MESSAGES ) ),
				'timeEntries'   => $entries->present_many( array_merge( $recent, array_values( $all_runs ) ) ),
				'notifications' => $notifications->present_many( $notifications->recent_for( $me, self::NOTIFICATIONS ) ),
				'activity'      => ( new ActivityRepository() )->present_many( ( new ActivityRepository() )->page( 0, self::ACTIVITY ) ),
			),
			'deleted' => (object) array(),
		);
	}

	/**
	 * What changed after a cursor.
	 *
	 * @param int $me     Member.
	 * @param int $since  Client cursor.
	 * @param int $latest Upper bound for this poll.
	 * @return array<string, mixed>
	 */
	private function changes( int $me, int $since, int $latest ): array {
		$rows   = ChangeLog::since( $since, $latest, $me, self::PAGE );
		$more   = count( $rows ) === self::PAGE;
		$cursor = $more ? $rows[ count( $rows ) - 1 ]['id'] : $latest;

		$upserts = array();
		$deleted = array();
		foreach ( $rows as $row ) {
			$id = $row['object_id'];
			if ( 'delete' === $row['action'] ) {
				$deleted[ $row['type'] ][ $id ] = true;
				unset( $upserts[ $row['type'] ][ $id ] );
			} else {
				$upserts[ $row['type'] ][ $id ] = true;
			}
		}

		$data = array();
		$gone = array();
		foreach ( $this->types() as $type => list( $repo, $key ) ) {
			if ( ! empty( $upserts[ $type ] ) ) {
				$found = $repo->find_many( array_keys( $upserts[ $type ] ) );
				if ( 'notification' === $type ) {
					$found = array_values( array_filter( $found, static fn( $n ) => (int) $n['user_id'] === $me ) );
				}
				if ( $found ) {
					$data[ $key ] = $repo->present_many( $found );
				}
			}
			if ( ! empty( $deleted[ $type ] ) ) {
				$gone[ $key ] = array_keys( $deleted[ $type ] );
			}
		}

		return array(
			'cursor'  => $cursor,
			'full'    => false,
			'more'    => $more,
			'data'    => (object) $data,
			'deleted' => (object) $gone,
		);
	}
}

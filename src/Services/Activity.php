<?php
/**
 * Records team events for the workspace activity feed.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Services;

use NovaWork\Data\ActivityRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to NovaWork's own action hooks and writes one feed row per event
 * (owner request, Notifications → Workspace): a task added or finished, a
 * project started or finished, a decision opened or made, a vote opened, a
 * comment. Chat is not repeated here; it has its own window.
 *
 * Add-ons record their own events with Activity::record().
 */
final class Activity {

	/**
	 * Verbs the app knows how to show.
	 */
	public const VERBS = array( 'task_added', 'task_done', 'project_added', 'project_done', 'decision_opened', 'decision_made', 'vote_opened', 'commented', 'member_joined' );

	/**
	 * The same event on the same object within this many seconds is one row
	 * (a task ticked done, undone and done again).
	 */
	private const SETTLE = 10 * MINUTE_IN_SECONDS;

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_action( 'novawork_task_created', array( self::class, 'task_created' ), 10, 2 );
		add_action( 'novawork_task_updated', array( self::class, 'task_updated' ), 10, 3 );
		add_action( 'novawork_project_created', array( self::class, 'project_created' ), 10, 2 );
		add_action( 'novawork_project_updated', array( self::class, 'project_updated' ), 10, 3 );
		add_action( 'novawork_decision_created', array( self::class, 'decision_created' ), 10, 2 );
		add_action( 'novawork_decision_updated', array( self::class, 'decision_updated' ), 10, 3 );
		add_action( 'novawork_decision_decided', array( self::class, 'decision_decided' ), 10, 2 );
		add_action( 'novawork_comment_created', array( self::class, 'comment_created' ) );
	}

	/**
	 * Write one feed row.
	 *
	 * @param string               $verb        What happened (see VERBS).
	 * @param int                  $actor       Member who did it (0 for an agent).
	 * @param string               $object_type Object type.
	 * @param int                  $object_id   Object id.
	 * @param array<string, mixed> $data        What to show if the object is gone (title…).
	 * @param string               $agent       Agent key, for add-ons.
	 */
	public static function record( string $verb, int $actor, string $object_type, int $object_id, array $data = array(), string $agent = '' ): void {
		$repo = new ActivityRepository();
		$last = $repo->last_for( $verb, $object_type, $object_id );
		if ( $last && (int) $last['actor_id'] === $actor && (int) \NovaWork\Data\Db::to_ms( (string) $last['created_at'] ) > ( time() - self::SETTLE ) * 1000 ) {
			return;
		}
		$repo->insert(
			array(
				'actor_id'    => $actor,
				'agent'       => $agent,
				'verb'        => $verb,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'data'        => (string) wp_json_encode( $data ),
			)
		);
	}

	/**
	 * A task was added.
	 *
	 * @param array<string, mixed> $task  Task as the API presents it.
	 * @param int                  $actor Member.
	 */
	public static function task_created( array $task, int $actor ): void {
		self::record(
			'task_added',
			$actor,
			'task',
			(int) $task['id'],
			array(
				'title'     => (string) $task['title'],
				'projectId' => (int) ( $task['projectId'] ?? 0 ),
			)
		);
	}

	/**
	 * A task moved to Done.
	 *
	 * @param array<string, mixed> $task   Task as the API presents it.
	 * @param array<string, mixed> $before Row before the change.
	 * @param int                  $actor  Member.
	 */
	public static function task_updated( array $task, array $before, int $actor ): void {
		if ( 'done' === $task['status'] && 'done' !== $before['status'] ) {
			self::record( 'task_done', $actor, 'task', (int) $task['id'], array( 'title' => (string) $task['title'] ) );
		}
	}

	/**
	 * A project was added.
	 *
	 * @param array<string, mixed> $project Project as the API presents it.
	 * @param int                  $actor   Member.
	 */
	public static function project_created( array $project, int $actor ): void {
		self::record( 'project_added', $actor, 'project', (int) $project['id'], array( 'title' => (string) $project['name'] ) );
	}

	/**
	 * A project was marked done.
	 *
	 * @param array<string, mixed> $project Project as the API presents it.
	 * @param array<string, mixed> $before  Row before the change.
	 * @param int                  $actor   Member.
	 */
	public static function project_updated( array $project, array $before, int $actor ): void {
		if ( 'done' === $project['status'] && 'done' !== $before['status'] ) {
			self::record( 'project_done', $actor, 'project', (int) $project['id'], array( 'title' => (string) $project['name'] ) );
		}
	}

	/**
	 * A decision was opened (and maybe put to a vote).
	 *
	 * @param array<string, mixed> $decision Decision as the API presents it.
	 * @param int                  $actor    Member.
	 */
	public static function decision_created( array $decision, int $actor ): void {
		$data = array( 'title' => (string) $decision['question'] );
		self::record( 'decision_opened', $actor, 'decision', (int) $decision['id'], $data );
		if ( ! empty( $decision['voting'] ) ) {
			self::record( 'vote_opened', $actor, 'decision', (int) $decision['id'], $data );
		}
	}

	/**
	 * A decision changed: put to a vote, or decided from the editor.
	 *
	 * @param array<string, mixed> $decision Decision as the API presents it.
	 * @param array<string, mixed> $before   Row before the change.
	 * @param int                  $actor    Member.
	 */
	public static function decision_updated( array $decision, array $before, int $actor ): void {
		if ( ! empty( $decision['voting'] ) && empty( $before['voting'] ) && 'decided' !== $decision['status'] ) {
			self::record( 'vote_opened', $actor, 'decision', (int) $decision['id'], array( 'title' => (string) $decision['question'] ) );
		}
		if ( 'decided' === $decision['status'] && 'decided' !== $before['status'] ) {
			self::decision_decided( $decision, $actor );
		}
	}

	/**
	 * A decision was made.
	 *
	 * @param array<string, mixed> $decision Decision as the API presents it.
	 * @param int                  $actor    Member.
	 */
	public static function decision_decided( array $decision, int $actor ): void {
		self::record(
			'decision_made',
			$actor,
			'decision',
			(int) $decision['id'],
			array(
				'title'  => (string) $decision['question'],
				'answer' => mb_substr( (string) ( $decision['answer'] ?? '' ), 0, 140 ),
			)
		);
	}

	/**
	 * Someone commented on a task or decision.
	 *
	 * @param array<string, mixed> $comment Comment row.
	 */
	public static function comment_created( array $comment ): void {
		$type   = (string) $comment['object_type'];
		$object = 'task' === $type ? ( new \NovaWork\Data\TaskRepository() )->find( (int) $comment['object_id'] ) : ( new \NovaWork\Data\DecisionRepository() )->find( (int) $comment['object_id'] );
		if ( ! $object ) {
			return;
		}
		self::record(
			'commented',
			(int) $comment['user_id'],
			$type,
			(int) $comment['object_id'],
			array( 'title' => (string) ( $object['title'] ?? $object['question'] ?? '' ) ),
			(string) $comment['agent']
		);
	}
}

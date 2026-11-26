<?php
/**
 * Creates notifications.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Services;

use NovaWork\Access\Capabilities;
use NovaWork\Data\CommentRepository;
use NovaWork\Data\DecisionRepository;
use NovaWork\Data\NotificationRepository;
use NovaWork\Data\TaskRepository;
use NovaWork\Members;
use NovaWork\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Who hears about what:
 *
 * - assign:   a member added to a task (not when you add yourself).
 * - comment:  everyone involved in the task or decision, except the writer.
 * - chat:     every other member; a member mentioned with @Name gets a
 *             "mention" instead.
 * - due:      assignees of an open task due soon (the due_soon_days setting,
 *             3 by default) or late; once per due date.
 * - decision: who decides (everyone if no one is named; see deciders() when
 *             an agent is named) when the decide-by date is that close; once
 *             per date.
 */
final class Notifier {

	/**
	 * Transient that throttles the due-date check on polls.
	 */
	private const THROTTLE = 'novawork_due_checked';

	/**
	 * Members newly assigned to a task.
	 *
	 * @param int   $task_id  Task.
	 * @param int[] $user_ids New assignees.
	 * @param int   $actor    Who assigned them.
	 */
	public static function assigned( int $task_id, array $user_ids, int $actor ): void {
		$task = ( new TaskRepository() )->find( $task_id );
		if ( ! $task ) {
			return;
		}
		foreach ( $user_ids as $user_id ) {
			if ( $user_id === $actor ) {
				continue;
			}
			self::send(
				$user_id,
				array(
					'type'        => 'assign',
					'actor_id'    => $actor,
					'object_type' => 'task',
					'object_id'   => $task_id,
					'data'        => array( 'title' => (string) $task['title'] ),
				)
			);
		}
	}

	/**
	 * Someone was given a subtask (owner request, 2026-11-22). The
	 * notification is an "assign" about the parent task, so it opens the
	 * task; it names the subtask too.
	 *
	 * @param array<string, mixed> $subtask Subtask row.
	 * @param int                  $user_id Who was given it.
	 * @param int                  $actor   Who gave it.
	 */
	public static function subtask_assigned( array $subtask, int $user_id, int $actor ): void {
		$task = ( new TaskRepository() )->find( (int) $subtask['task_id'] );
		if ( ! $task || $user_id === $actor ) {
			return;
		}
		self::send(
			$user_id,
			array(
				'type'        => 'assign',
				'actor_id'    => $actor,
				'object_type' => 'task',
				'object_id'   => (int) $task['id'],
				'data'        => array(
					'title'   => (string) $task['title'],
					'subtask' => (string) $subtask['title'],
				),
			)
		);
	}

	/**
	 * The team is asked to vote on a decision (once per decision).
	 *
	 * @param array<string, mixed> $decision Decision row.
	 * @param int                  $actor    Who asked.
	 */
	public static function vote_requested( array $decision, int $actor ): void {
		foreach ( Members::ids() as $user_id ) {
			if ( $user_id === $actor ) {
				continue;
			}
			self::send(
				$user_id,
				array(
					'type'        => 'vote',
					'actor_id'    => $actor,
					'object_type' => 'decision',
					'object_id'   => (int) $decision['id'],
					'data'        => array( 'question' => (string) $decision['question'] ),
					'dedupe_key'  => 'vote:' . (int) $decision['id'],
				)
			);
		}
	}

	/**
	 * A new comment. Members it mentions get a "mention"; everyone else
	 * involved a "comment".
	 *
	 * @param array<string, mixed> $comment Comment row.
	 * @return int[] Members notified.
	 */
	public static function commented( array $comment ): array {
		$type   = (string) $comment['object_type'];
		$id     = (int) $comment['object_id'];
		$author = (int) $comment['user_id'];
		$people = array();
		$title  = '';

		if ( 'task' === $type ) {
			$tasks = new TaskRepository();
			$task  = $tasks->find( $id );
			if ( ! $task ) {
				return array();
			}
			$title  = (string) $task['title'];
			$people = array_merge( $tasks->assignees_of( array( $id ) )[ $id ] ?? array(), array( (int) $task['created_by'] ) );
		} elseif ( 'decision' === $type ) {
			$decision = ( new DecisionRepository() )->find( $id );
			if ( ! $decision ) {
				return array();
			}
			$title  = (string) $decision['question'];
			$people = array_merge( self::deciders( $decision, false ), array( (int) $decision['created_by'] ) );
		}

		foreach ( ( new CommentRepository() )->for_object( $type, $id ) as $earlier ) {
			$people[] = (int) $earlier['user_id'];
		}

		$mentioned = array_column( References::decode( $comment['mentions'] ?? '[]' ), 'id' );
		$told      = array();
		foreach ( array_unique( array_merge( $people, $mentioned ) ) as $user_id ) {
			if ( $user_id <= 0 || $user_id === $author ) {
				continue;
			}
			$told[] = $user_id;
			self::send(
				$user_id,
				array(
					'type'        => in_array( $user_id, $mentioned, true ) ? 'mention' : 'comment',
					'actor_id'    => $author,
					'agent'       => (string) $comment['agent'],
					'object_type' => $type,
					'object_id'   => $id,
					'data'        => array(
						'title'   => $title,
						'excerpt' => self::excerpt( (string) $comment['body'] ),
					),
				)
			);
		}
		return $told;
	}

	/**
	 * A new chat message.
	 *
	 * @param array<string, mixed> $message Message row.
	 */
	public static function chatted( array $message ): void {
		$author    = (int) $message['user_id'];
		$mentioned = array_column( \NovaWork\Data\MessageRepository::mentions( $message ), 'id' );
		// What was said, and the files shared (a message can be files only).
		$files = ( new \NovaWork\Data\AttachmentRepository() )->for_messages( array( (int) $message['id'] ) )[ (int) $message['id'] ] ?? array();
		$data  = array(
			'excerpt' => self::excerpt( (string) $message['body'] ),
			'files'   => array_slice( array_column( $files, 'name' ), 0, 3 ),
			'count'   => count( $files ),
		);
		foreach ( Members::ids() as $user_id ) {
			if ( $user_id === $author ) {
				continue;
			}
			if ( in_array( $user_id, $mentioned, true ) ) {
				// Owner request: a mention is its own notification.
				self::send(
					$user_id,
					array(
						'type'        => 'mention',
						'actor_id'    => $author,
						'agent'       => (string) $message['agent'],
						'object_type' => 'message',
						'object_id'   => (int) $message['id'],
						'data'        => $data,
					)
				);
				continue;
			}
			self::send(
				$user_id,
				array(
					'type'        => 'chat',
					'actor_id'    => $author,
					'agent'       => (string) $message['agent'],
					'object_type' => 'message',
					'object_id'   => (int) $message['id'],
					'data'        => $data,
				)
			);
		}
	}

	/**
	 * People newly @mentioned when a chat message or comment is edited (the
	 * ones already mentioned were told when it was posted).
	 *
	 * @param string               $kind     "message" or "comment".
	 * @param array<string, mixed> $row      The edited row.
	 * @param int[]                $user_ids Newly mentioned members.
	 */
	public static function mentioned( string $kind, array $row, array $user_ids ): void {
		$author = (int) $row['user_id'];
		$data   = array( 'excerpt' => self::excerpt( (string) $row['body'] ) );
		if ( 'comment' === $kind ) {
			$object = 'task' === $row['object_type'] ? ( new TaskRepository() )->find( (int) $row['object_id'] ) : ( new DecisionRepository() )->find( (int) $row['object_id'] );
			if ( ! $object ) {
				return;
			}
			$data['title'] = (string) ( $object['title'] ?? $object['question'] ?? '' );
		}
		foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $user_id ) {
			if ( $user_id <= 0 || $user_id === $author ) {
				continue;
			}
			self::send(
				$user_id,
				array(
					'type'        => 'mention',
					'actor_id'    => $author,
					'agent'       => (string) $row['agent'],
					'object_type' => 'comment' === $kind ? (string) $row['object_type'] : 'message',
					'object_id'   => 'comment' === $kind ? (int) $row['object_id'] : (int) $row['id'],
					'data'        => $data,
				)
			);
		}
	}

	/**
	 * Look for due tasks and decisions. Polls call this; it runs at most every
	 * ten minutes unless forced (cron forces it hourly).
	 *
	 * @param bool $force Skip the throttle.
	 */
	public static function check_due( bool $force = false ): void {
		if ( ! $force && get_transient( self::THROTTLE ) ) {
			return;
		}
		set_transient( self::THROTTLE, 1, 10 * MINUTE_IN_SECONDS );

		$limit = self::limit_date();
		$tasks = new TaskRepository();
		foreach ( $tasks->open_due_by( $limit ) as $task ) {
			self::task_due( $task, $tasks );
		}
		foreach ( ( new DecisionRepository() )->open_due_by( $limit ) as $decision ) {
			foreach ( self::deciders( $decision, true ) as $user_id ) {
				self::send(
					$user_id,
					array(
						'type'        => 'decision',
						'object_type' => 'decision',
						'object_id'   => (int) $decision['id'],
						'data'        => array(
							'question' => (string) $decision['question'],
							'decideBy' => (string) $decision['decide_by'],
						),
						'dedupe_key'  => 'decision:' . (int) $decision['id'] . ':' . (string) $decision['decide_by'],
					)
				);
			}
		}
	}

	/**
	 * Check one task right after it is saved, so a due date set for tomorrow
	 * does not wait for the next check.
	 *
	 * @param int $task_id Task.
	 */
	public static function check_task( int $task_id ): void {
		$tasks = new TaskRepository();
		$task  = $tasks->find( $task_id );
		if ( ! $task || 'done' === $task['status'] || empty( $task['due_date'] ) || (string) $task['due_date'] > self::limit_date() ) {
			return;
		}
		self::task_due( $task, $tasks );
	}

	/**
	 * Notify a task's assignees that it is due or late.
	 *
	 * @param array<string, mixed> $task  Task row.
	 * @param TaskRepository       $tasks Repository.
	 */
	private static function task_due( array $task, TaskRepository $tasks ): void {
		$id = (int) $task['id'];
		foreach ( $tasks->assignees_of( array( $id ) )[ $id ] ?? array() as $user_id ) {
			self::send(
				$user_id,
				array(
					'type'        => 'due',
					'object_type' => 'task',
					'object_id'   => $id,
					'data'        => array(
						'title' => (string) $task['title'],
						'due'   => (string) $task['due_date'],
					),
					'dedupe_key'  => 'due:' . $id . ':' . (string) $task['due_date'],
				)
			);
		}
	}

	/**
	 * The last due date that notifies today, in the site's timezone.
	 */
	private static function limit_date(): string {
		return wp_date( 'Y-m-d', time() + Settings::int( 'due_soon_days', 0 ) * DAY_IN_SECONDS );
	}

	/**
	 * Store one notification for a member. Every bell row is written here
	 * (add-ons reach it through NovaWork\Api::notify()).
	 *
	 * @param int                  $user_id Recipient.
	 * @param array<string, mixed> $fields  Columns; data is an array.
	 * @return int The new row's id, or 0 when nothing was written (not a
	 *             member, a repeat of a dedupe key, or a failed insert).
	 */
	public static function send( int $user_id, array $fields ): int {
		if ( ! Capabilities::is_member( $user_id ) ) {
			return 0;
		}
		$fields['user_id'] = $user_id;
		$fields['data']    = (string) wp_json_encode( $fields['data'] ?? array() );
		$repo              = new NotificationRepository();
		$id                = isset( $fields['dedupe_key'] ) ? $repo->insert_once( $fields ) : $repo->insert( $fields );
		$row               = $id ? $repo->find( $id ) : null;
		if ( ! $row ) {
			return 0;
		}

		/**
		 * Fires after a notification is written for a member (one row in their
		 * bell). Not for a repeat that a dedupe key stopped.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string, mixed> $note    The notification as the API presents it.
		 * @param int                  $user_id Who it is for.
		 */
		do_action( 'novawork_notification_created', $repo->present( $row ), $user_id );

		return $id;
	}

	/**
	 * The members who record a decision and hear about it: the person named
	 * in "Who decides"; when an agent is named (1.3.0), the person who opened
	 * it, or the administrators once that person is no longer a member (owner,
	 * 2026-11-26); when no one is named, every member, or nobody when
	 * $everyone is false.
	 *
	 * @param array<string, mixed> $decision Decision row.
	 * @param bool                 $everyone Whether "no one named" means every member.
	 * @return int[]
	 */
	public static function deciders( array $decision, bool $everyone ): array {
		$owner = (int) $decision['owner_id'];
		if ( $owner > 0 ) {
			return array( $owner );
		}
		if ( Capabilities::names_agent( $decision ) ) {
			$opener = (int) $decision['created_by'];
			if ( Capabilities::is_member( $opener ) ) {
				return array( $opener );
			}
			return array_values( array_filter( Members::ids(), static fn( int $id ): bool => user_can( $id, Capabilities::MANAGE ) ) );
		}
		return $everyone ? Members::ids() : array();
	}

	/**
	 * First 140 characters of a text, on one line.
	 *
	 * @param string $text Text.
	 */
	private static function excerpt( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		return mb_strlen( $text ) > 140 ? rtrim( mb_substr( $text, 0, 139 ) ) . '…' : $text;
	}
}

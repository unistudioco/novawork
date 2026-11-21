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

/**
 * Who hears about what:
 *
 * - assign:   a member added to a task (not when you add yourself).
 * - comment:  everyone involved in the task or decision, except the writer.
 * - chat:     every other member.
 * - due:      assignees of an open task due within 3 days, or late; once per due date.
 * - decision: who decides (everyone if no one is named) when the decide-by
 *             date is within 3 days; once per date.
 */
final class Notifier {

	/**
	 * Days ahead a due date starts to notify.
	 */
	public const DUE_DAYS = 3;

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
	 * A new comment.
	 *
	 * @param array<string, mixed> $comment Comment row.
	 */
	public static function commented( array $comment ): void {
		$type   = (string) $comment['object_type'];
		$id     = (int) $comment['object_id'];
		$author = (int) $comment['user_id'];
		$people = array();
		$title  = '';

		if ( 'task' === $type ) {
			$tasks = new TaskRepository();
			$task  = $tasks->find( $id );
			if ( ! $task ) {
				return;
			}
			$title  = (string) $task['title'];
			$people = array_merge( $tasks->assignees_of( array( $id ) )[ $id ] ?? array(), array( (int) $task['created_by'] ) );
		} elseif ( 'decision' === $type ) {
			$decision = ( new DecisionRepository() )->find( $id );
			if ( ! $decision ) {
				return;
			}
			$title  = (string) $decision['question'];
			$people = array( (int) $decision['owner_id'], (int) $decision['created_by'] );
		}

		foreach ( ( new CommentRepository() )->for_object( $type, $id ) as $earlier ) {
			$people[] = (int) $earlier['user_id'];
		}

		foreach ( array_unique( $people ) as $user_id ) {
			if ( $user_id <= 0 || $user_id === $author ) {
				continue;
			}
			self::send(
				$user_id,
				array(
					'type'        => 'comment',
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
	}

	/**
	 * A new chat message.
	 *
	 * @param array<string, mixed> $message Message row.
	 */
	public static function chatted( array $message ): void {
		$author = (int) $message['user_id'];
		foreach ( Members::ids() as $user_id ) {
			if ( $user_id === $author ) {
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
					'data'        => array( 'excerpt' => self::excerpt( (string) $message['body'] ) ),
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
		$members = Members::ids();
		foreach ( ( new DecisionRepository() )->open_due_by( $limit ) as $decision ) {
			$owner      = (int) $decision['owner_id'];
			$recipients = $owner > 0 ? array( $owner ) : $members;
			foreach ( $recipients as $user_id ) {
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
		return wp_date( 'Y-m-d', time() + self::DUE_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Store one notification for a member.
	 *
	 * @param int                  $user_id Recipient.
	 * @param array<string, mixed> $fields  Columns; data is an array.
	 */
	private static function send( int $user_id, array $fields ): void {
		if ( ! Capabilities::is_member( $user_id ) ) {
			return;
		}
		$fields['user_id'] = $user_id;
		$fields['data']    = (string) wp_json_encode( $fields['data'] ?? array() );
		$repo              = new NotificationRepository();
		if ( isset( $fields['dedupe_key'] ) ) {
			$repo->insert_once( $fields );
		} else {
			$repo->insert( $fields );
		}
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

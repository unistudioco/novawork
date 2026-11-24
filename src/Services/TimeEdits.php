<?php
/**
 * Changing time by hand: administrators' edits, and members putting back
 * time that NovaWork stopped on its own.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Services;

use NovaWork\Data\Db;
use NovaWork\Data\TaskRepository;
use NovaWork\Data\TimeEntryRepository;
use NovaWork\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Owner requests (1.2.0):
 *
 * - Administrators add, change (task, start, end) and delete sessions.
 * - "Were you working?": when NovaWork stops a timer on its own (the app
 *   went quiet, the device slept, "Still working?" went unanswered), the
 *   stop is remembered in the member's user meta. The next time they are in
 *   NovaWork they are asked, and "Count it" puts the time back, up to a
 *   moment they choose. Setting `recover_time` (on).
 *
 * Every change keeps one member's sessions from overlapping, never reaches
 * past now and keeps sessions at least a minute long (Timer::MIN_SECONDS).
 */
final class TimeEdits {

	/**
	 * User meta: the last automatic stop not yet answered.
	 */
	public const PENDING_META = 'novawork_pending_stop';

	/**
	 * How long after an automatic stop the member may still put the time back.
	 */
	public const RECOVER_WINDOW = DAY_IN_SECONDS;

	/**
	 * Why NovaWork stopped a timer on its own.
	 */
	public const REASONS = array( 'away', 'sleep', 'idle' );

	/**
	 * Check a session for a member: a real span, at least a minute, never in
	 * the future, not overlapping their other sessions.
	 *
	 * @param int      $user_id Member.
	 * @param int      $start   Start, epoch ms.
	 * @param int|null $end     End, epoch ms; null for a running session.
	 * @param int      $except  A session to leave out (the one being changed).
	 */
	public static function check_span( int $user_id, int $start, ?int $end, int $except = 0 ): ?WP_Error {
		$now = Db::now_ms();
		if ( $start > $now || ( null !== $end && $end > $now + 1000 ) ) {
			return new WP_Error( 'novawork_time_future', __( 'Time can’t be added in the future.', 'novawork' ), array( 'status' => 400 ) );
		}
		$until = $end ?? $now;
		if ( $until - $start < Timer::MIN_SECONDS * 1000 ) {
			return new WP_Error( 'novawork_time_short', __( 'A session needs to end at least a minute after it starts.', 'novawork' ), array( 'status' => 400 ) );
		}
		foreach ( ( new TimeEntryRepository() )->overlapping( Db::from_ms( $start ), Db::from_ms( $until ), $user_id ) as $row ) {
			if ( (int) $row['id'] === $except ) {
				continue;
			}
			// Sessions may touch end to start.
			$other_start = Db::to_ms( (string) $row['started_at'] );
			$other_end   = null === $row['ended_at'] ? $now : (int) Db::to_ms( (string) $row['ended_at'] );
			if ( $other_start < $until && $other_end > $start ) {
				return new WP_Error( 'novawork_time_overlap', __( 'That overlaps another session of the same person. Change or delete that one first.', 'novawork' ), array( 'status' => 409 ) );
			}
		}
		return null;
	}

	/**
	 * An administrator adds a session.
	 *
	 * @param int $user_id Member it belongs to.
	 * @param int $task_id Task.
	 * @param int $start   Start, epoch ms.
	 * @param int $end     End, epoch ms.
	 * @return array<string, mixed>|WP_Error The session, as the API presents it.
	 */
	public static function create( int $user_id, int $task_id, int $start, int $end ): array|WP_Error {
		if ( ! ( new TaskRepository() )->find( $task_id ) ) {
			return new WP_Error( 'novawork_not_found', __( 'That task no longer exists.', 'novawork' ), array( 'status' => 400 ) );
		}
		$error = self::check_span( $user_id, $start, $end );
		if ( $error ) {
			return $error;
		}
		$repo = new TimeEntryRepository();
		$id   = $repo->insert(
			array(
				'user_id'    => $user_id,
				'task_id'    => $task_id,
				'started_at' => Db::from_ms( $start ),
				'ended_at'   => Db::from_ms( $end ),
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'novawork_save_failed', __( 'The session wasn’t saved. Try again.', 'novawork' ), array( 'status' => 500 ) );
		}
		return self::saved( $id, array( $task_id ), null );
	}

	/**
	 * An administrator changes a session's task, start or end. A running
	 * session keeps running: only its task and start change.
	 *
	 * @param int                  $id     Session.
	 * @param array<string, mixed> $fields taskId, start, end (epoch ms).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function change( int $id, array $fields ): array|WP_Error {
		$repo   = new TimeEntryRepository();
		$before = $repo->find( $id );
		if ( ! $before ) {
			return new WP_Error( 'novawork_not_found', __( 'That item no longer exists.', 'novawork' ), array( 'status' => 404 ) );
		}
		$running = null === $before['ended_at'];
		$task_id = (int) ( $fields['taskId'] ?? $before['task_id'] );
		$start   = (int) ( $fields['start'] ?? Db::to_ms( (string) $before['started_at'] ) );
		$end     = $running ? null : (int) ( $fields['end'] ?? Db::to_ms( (string) $before['ended_at'] ) );
		if ( $task_id !== (int) $before['task_id'] && ! ( new TaskRepository() )->find( $task_id ) ) {
			return new WP_Error( 'novawork_not_found', __( 'That task no longer exists.', 'novawork' ), array( 'status' => 400 ) );
		}
		$error = self::check_span( (int) $before['user_id'], $start, $end, $id );
		if ( $error ) {
			return $error;
		}
		$data = array(
			'task_id'    => $task_id,
			'started_at' => Db::from_ms( $start ),
		);
		if ( null !== $end ) {
			$data['ended_at'] = Db::from_ms( $end );
		}
		$repo->update( $id, $data );
		return self::saved( $id, array( (int) $before['task_id'], $task_id ), $before );
	}

	/**
	 * An administrator deletes a session. A running one stops first.
	 *
	 * @param int $id Session.
	 * @return array<string, mixed>|WP_Error The deleted session, so Undo can put it back.
	 */
	public static function remove( int $id ): array|WP_Error {
		$repo = new TimeEntryRepository();
		$row  = $repo->find( $id );
		if ( ! $row ) {
			return new WP_Error( 'novawork_not_found', __( 'That item no longer exists.', 'novawork' ), array( 'status' => 404 ) );
		}
		$gone = $repo->present( $row );
		$repo->delete( $id );
		( new TaskRepository() )->touch( (int) $row['task_id'] );

		/**
		 * Fires after an administrator deletes a session.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, mixed> $session Session as the API presented it.
		 * @param int                  $actor   Who deleted it.
		 */
		do_action( 'novawork_time_entry_deleted', $gone, get_current_user_id() );

		return $gone;
	}

	/**
	 * After a save: refresh the tasks' totals, fire the hook, answer.
	 *
	 * @param int                       $id     Session.
	 * @param array<int, int>           $tasks  Tasks whose totals changed.
	 * @param array<string, mixed>|null $before Row before a change; null for a new session.
	 * @return array<string, mixed>
	 */
	private static function saved( int $id, array $tasks, ?array $before ): array {
		$repo  = new TimeEntryRepository();
		$tasks = array_unique( $tasks );
		foreach ( $tasks as $task_id ) {
			( new TaskRepository() )->touch( $task_id );
		}
		$session = $repo->present( (array) $repo->find( $id ) );

		/**
		 * Fires after a session is added or changed by hand (an
		 * administrator's edit, or a member putting back time after an
		 * automatic stop).
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, mixed>      $session Session as the API presents it.
		 * @param array<string, mixed>|null $before  Database row before; null for a new session.
		 * @param int                       $actor   Who changed it.
		 */
		do_action( 'novawork_time_entry_saved', $session, $before, get_current_user_id() );

		return $session;
	}

	/* ---------- "Were you working?" ---------- */

	/**
	 * Remember a stop NovaWork made on its own, to ask the member about it.
	 *
	 * @param int    $user_id  Member.
	 * @param int    $entry_id The session (0 when it was under a minute and dropped).
	 * @param int    $task_id  Its task.
	 * @param int    $start    Its start, epoch ms.
	 * @param int    $end      Where it stopped, epoch ms.
	 * @param string $reason   away, sleep or idle.
	 */
	public static function remember_stop( int $user_id, int $entry_id, int $task_id, int $start, int $end, string $reason ): void {
		if ( ! Settings::get( 'recover_time' ) || ! in_array( $reason, self::REASONS, true ) ) {
			return;
		}
		update_user_meta(
			$user_id,
			self::PENDING_META,
			array(
				'entryId'   => $entry_id,
				'taskId'    => $task_id,
				'startedAt' => $start,
				'endedAt'   => $end,
				'reason'    => $reason,
			)
		);
	}

	/**
	 * The member's unanswered automatic stop, or null.
	 *
	 * @param int $user_id Member.
	 * @return array<string, mixed>|null
	 */
	public static function pending( int $user_id ): ?array {
		$p = get_user_meta( $user_id, self::PENDING_META, true );
		if ( ! is_array( $p ) || ! isset( $p['endedAt'], $p['startedAt'], $p['taskId'] ) ) {
			return null;
		}
		if ( ! Settings::get( 'recover_time' ) || Db::now_ms() - (int) $p['endedAt'] > self::RECOVER_WINDOW * 1000 ) {
			delete_user_meta( $user_id, self::PENDING_META );
			return null;
		}
		return array(
			'entryId'   => (int) ( $p['entryId'] ?? 0 ),
			'taskId'    => (int) $p['taskId'],
			'startedAt' => (int) $p['startedAt'],
			'endedAt'   => (int) $p['endedAt'],
			'reason'    => (string) ( $p['reason'] ?? 'away' ),
		);
	}

	/**
	 * "Don't count it": forget the question.
	 *
	 * @param int $user_id Member.
	 */
	public static function dismiss( int $user_id ): void {
		delete_user_meta( $user_id, self::PENDING_META );
	}

	/**
	 * "Count it": put the time back, up to $until (never past now, never into
	 * the member's next session), and keep tracking when asked and nothing
	 * else is running.
	 *
	 * @param int  $user_id Member.
	 * @param int  $until   Count until, epoch ms.
	 * @param bool $keep    Keep tracking from there.
	 * @return array<string, mixed>|WP_Error The session.
	 */
	public static function recover( int $user_id, int $until, bool $keep ): array|WP_Error {
		$p = self::pending( $user_id );
		if ( ! $p ) {
			return new WP_Error( 'novawork_nothing_to_recover', __( 'There’s no stopped time to put back.', 'novawork' ), array( 'status' => 404 ) );
		}
		$repo    = new TimeEntryRepository();
		$now     = Db::now_ms();
		$running = $repo->running_for( $user_id );
		$until   = min( $until, $now );
		// Never into a later session: the first one that starts after this one.
		foreach ( $repo->overlapping( Db::from_ms( (int) $p['startedAt'] + 1 ), Db::from_ms( $now + 1000 ), $user_id ) as $row ) {
			$start = (int) Db::to_ms( (string) $row['started_at'] );
			if ( (int) $row['id'] !== $p['entryId'] && $start > $p['startedAt'] ) {
				$until = min( $until, $start );
				$keep  = false;
			}
		}
		if ( $running && (int) $running['id'] !== $p['entryId'] ) {
			$keep = false;
		}
		// Keeping it running only makes sense when it counts up to now.
		$keep = $keep && $now - $until < 2 * MINUTE_IN_SECONDS * 1000;
		if ( $until <= $p['endedAt'] && ! $keep ) {
			self::dismiss( $user_id );
			return new WP_Error( 'novawork_nothing_to_recover', __( 'Nothing to add: that time is already counted.', 'novawork' ), array( 'status' => 400 ) );
		}

		$entry = $p['entryId'] ? $repo->find( $p['entryId'] ) : null;
		$data  = array(
			'ended_at'     => $keep ? null : Db::from_ms( $until ),
			'running_user' => $keep ? $user_id : null,
		);
		if ( $entry ) {
			$repo->update( (int) $entry['id'], $data );
			$id = (int) $entry['id'];
		} else {
			if ( ! $keep && $until - $p['startedAt'] < Timer::MIN_SECONDS * 1000 ) {
				self::dismiss( $user_id );
				return new WP_Error( 'novawork_time_short', __( 'A session needs to end at least a minute after it starts.', 'novawork' ), array( 'status' => 400 ) );
			}
			$id = $repo->insert(
				array_merge(
					array(
						'user_id'    => $user_id,
						'task_id'    => $p['taskId'],
						'started_at' => Db::from_ms( $p['startedAt'] ),
					),
					$data
				)
			);
		}
		self::dismiss( $user_id );
		if ( $keep ) {
			// Back now: a fresh sign of life, so the server doesn't stop it again at once.
			update_user_meta( $user_id, \NovaWork\Members::SEEN_META, time() );
		}
		return self::saved( (int) $id, array( (int) $p['taskId'] ), $entry );
	}
}

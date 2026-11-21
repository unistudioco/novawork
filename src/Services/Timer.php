<?php
/**
 * The play/pause stopwatch.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Services;

use NovaWork\Data\Db;
use NovaWork\Data\TaskRepository;
use NovaWork\Data\TimeEntryRepository;
use WP_Error;

/**
 * The timer lives on the server: starting stores the start time, so a reload,
 * a second device or a closed laptop never loses or double-counts time. Each
 * member has at most one running timer (enforced by a UNIQUE index).
 */
final class Timer {

	/**
	 * Sessions shorter than this are discarded, as in the prototype.
	 */
	public const MIN_SECONDS = 60;

	/**
	 * Start tracking a task, stopping whatever was running.
	 *
	 * A task in Next or Later moves to the end of Now: tracking it means
	 * working on it now.
	 *
	 * @param int $user_id Member.
	 * @param int $task_id Task.
	 * @return array{entry: array<string, mixed>, task: array<string, mixed>, stopped: array<string, mixed>|null}|WP_Error
	 */
	public static function start( int $user_id, int $task_id ): array|WP_Error {
		$tasks = new TaskRepository();
		$task  = $tasks->find( $task_id );
		if ( ! $task ) {
			return new WP_Error( 'novawork_not_found', __( 'That task no longer exists.', 'novawork' ), array( 'status' => 404 ) );
		}

		$stopped = self::stop( $user_id );
		$entries = new TimeEntryRepository();

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$id       = $entries->insert(
			array(
				'user_id'      => $user_id,
				'task_id'      => $task_id,
				'started_at'   => Db::now(),
				'running_user' => $user_id,
			)
		);
		$wpdb->suppress_errors( $suppress );

		if ( ! $id ) {
			// Another request started a timer in the same instant; that one wins.
			$running = $entries->running_for( $user_id );
			if ( ! $running ) {
				return new WP_Error( 'novawork_timer_failed', __( 'The timer didn’t start. Try again.', 'novawork' ), array( 'status' => 500 ) );
			}
			$id = (int) $running['id'];
		}

		if ( in_array( $task['status'], array( 'next', 'later' ), true ) ) {
			$tasks->update(
				$task_id,
				array(
					'status'     => 'now',
					'sort_order' => $tasks->append_order( 'status', 'now' ),
				)
			);
		}

		$entry = (array) $entries->find( $id );

		/**
		 * Fires when a member starts tracking time.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $entry   Time entry row.
		 * @param int                  $user_id Member.
		 */
		do_action( 'novawork_timer_started', $entry, $user_id );

		return array(
			'entry'   => $entries->present( $entry ),
			'task'    => $tasks->present( (array) $tasks->find( $task_id ) ),
			'stopped' => $stopped,
		);
	}

	/**
	 * Stop a member's running timer.
	 *
	 * @param int      $user_id Member.
	 * @param int|null $end_ms  When it really ended (epoch ms), e.g. the last
	 *                          activity before an idle pause. Clamped between the
	 *                          start and now. Null means now.
	 * @return array<string, mixed>|null The saved entry, or null when nothing
	 *                                   ran or the session was too short to keep.
	 */
	public static function stop( int $user_id, ?int $end_ms = null ): ?array {
		$entries = new TimeEntryRepository();
		$running = $entries->running_for( $user_id );
		if ( ! $running ) {
			return null;
		}

		$start = (int) strtotime( (string) $running['started_at'] . ' UTC' );
		$now   = time();
		$end   = null === $end_ms ? $now : intdiv( $end_ms, 1000 );
		$end   = max( $start, min( $now, $end ) );

		if ( $end - $start < self::MIN_SECONDS ) {
			$entries->delete( (int) $running['id'] );
			return null;
		}

		$entries->update(
			(int) $running['id'],
			array(
				'ended_at'     => gmdate( 'Y-m-d H:i:s', $end ),
				'running_user' => null,
			)
		);
		$entry = (array) $entries->find( (int) $running['id'] );

		/**
		 * Fires when a member stops tracking time and the session is kept.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $entry   Time entry row.
		 * @param int                  $user_id Member.
		 */
		do_action( 'novawork_timer_stopped', $entry, $user_id );

		return $entries->present( $entry );
	}
}

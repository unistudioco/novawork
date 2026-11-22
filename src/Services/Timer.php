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
use NovaWork\Members;
use NovaWork\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The timer lives on the server: starting stores the start time, so a reload
 * or a second device never loses or double-counts time. Each member has at
 * most one running timer (enforced by a UNIQUE index).
 *
 * Owner decision (2026-11-22): a running timer needs a sign of life. The app
 * polls /sync every few seconds (about once a minute in a background tab), and
 * each poll is a heartbeat. When a member's app has been silent for longer
 * than away_after() (a folded laptop, sleep, a closed tab, no network, a flat
 * battery), their timer stops at the last heartbeat, so time away is never
 * counted. See stop_if_away() and sweep_away().
 */
final class Timer {

	/**
	 * Sessions shorter than this are discarded, as in the prototype.
	 */
	public const MIN_SECONDS = 60;

	/**
	 * Transient that limits the sweep of everyone's timers to once a minute.
	 */
	private const SWEEP_THROTTLE = 'novawork_away_sweep';

	/**
	 * Seconds without a heartbeat after which a running timer stops: the
	 * inactivity setting (idle_minutes), never under three minutes, because a
	 * background tab may poll only once a minute.
	 */
	public static function away_after(): int {
		return max( 3 * MINUTE_IN_SECONDS, Settings::int( 'idle_minutes' ) * MINUTE_IN_SECONDS );
	}

	/**
	 * Stop a member's timer if their app has been silent too long, at the
	 * last sign of life: their last poll, or the start when that is later.
	 *
	 * Call it before recording the member's current poll, or the poll itself
	 * would count as the sign of life.
	 *
	 * @param int $user_id Member.
	 * @return array{endedAt: int, awayMs: int, taskId: int}|null What was
	 *         stopped, or null when nothing ran or the member is still here.
	 */
	public static function stop_if_away( int $user_id ): ?array {
		$running = ( new TimeEntryRepository() )->running_for( $user_id );
		if ( ! $running ) {
			return null;
		}
		$start = (int) strtotime( (string) $running['started_at'] . ' UTC' );
		$seen  = (int) get_user_meta( $user_id, Members::SEEN_META, true );
		$alive = max( $start, $seen );
		$now   = time();
		if ( $now - $alive <= self::away_after() ) {
			return null;
		}
		self::stop( $user_id, $alive * 1000 );
		return array(
			'endedAt' => $alive * 1000,
			'awayMs'  => ( $now - $alive ) * 1000,
			'taskId'  => (int) $running['task_id'],
		);
	}

	/**
	 * Stop every timer whose member has gone quiet, so the team and the Time
	 * view stop counting it. Runs on polls (at most once a minute) and hourly.
	 *
	 * @param bool $force Skip the once-a-minute limit.
	 */
	public static function sweep_away( bool $force = false ): void {
		if ( ! $force && get_transient( self::SWEEP_THROTTLE ) ) {
			return;
		}
		set_transient( self::SWEEP_THROTTLE, 1, MINUTE_IN_SECONDS );
		foreach ( ( new TimeEntryRepository() )->running() as $row ) {
			self::stop_if_away( (int) $row['user_id'] );
		}
	}

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
		// The task's total time changed; let every client pick it up.
		( new TaskRepository() )->touch( (int) $running['task_id'] );

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

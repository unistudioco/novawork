<?php
/**
 * The first run's sample items.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Services;

use NovaWork\Data\DecisionRepository;
use NovaWork\Data\NotificationRepository;
use NovaWork\Data\ProjectRepository;
use NovaWork\Data\TaskRepository;
use NovaWork\Data\TimeEntryRepository;
use NovaWork\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Owner decision (2026-11-22): a fresh workspace starts with three sample
 * items that show where to start: a project, a task in it (in Now) and an
 * open decision. Administrators see a note in the app and a button in
 * Settings that removes them in one step; deleting them one by one works too.
 *
 * Activation only marks them as due. They are made the first time an
 * administrator opens the app, owned by that person, so an activation from
 * WP-CLI or a Playground blueprint (no one signed in) still gets them. They
 * are never made in a workspace that already has work in it.
 */
final class Samples {

	/**
	 * '' never asked for (a site that had NovaWork before samples existed),
	 * 'due' after activation, 'made' once handled.
	 */
	public const OPTION = 'novawork_samples';

	/**
	 * Mark the samples as due (plugin activated). A workspace that already
	 * handled them keeps its answer.
	 */
	public static function mark_due(): void {
		add_option( self::OPTION, 'due', '', false );
	}

	/**
	 * Make the samples when they are due and the workspace is empty.
	 *
	 * @param int $user_id The administrator opening the app.
	 */
	public static function maybe_create( int $user_id ): void {
		if ( 'due' !== get_option( self::OPTION ) ) {
			return;
		}
		update_option( self::OPTION, 'made', false );
		if ( self::workspace_has_work() ) {
			return;
		}
		self::create( $user_id );
	}

	/**
	 * Whether anything exists yet.
	 */
	private static function workspace_has_work(): bool {
		global $wpdb;
		foreach ( array( new ProjectRepository(), new TaskRepository(), new DecisionRepository() ) as $repo ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off check on first run.
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', $repo->table_name() ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Make the three sample items.
	 *
	 * @param int $user_id Owner.
	 * @return array{project: int, task: int, decision: int}
	 */
	public static function create( int $user_id ): array {
		$day      = DAY_IN_SECONDS;
		$projects = new ProjectRepository();
		$project  = $projects->insert(
			array(
				'name'        => __( 'Getting started', 'novawork' ),
				'goal'        => __( 'A sample project. Projects group tasks and decisions, and show on the Roadmap. Rename it, or remove the samples when you’re ready.', 'novawork' ),
				'color'       => 'blue',
				'status'      => 'active',
				'start_date'  => gmdate( 'Y-m-d' ),
				'target_date' => gmdate( 'Y-m-d', time() + 14 * $day ),
				'sort_order'  => $projects->append_order(),
				'is_sample'   => 1,
				'created_by'  => $user_id,
			)
		);
		$tasks    = new TaskRepository();
		$task     = $tasks->insert(
			array(
				'project_id' => $project,
				'title'      => __( 'Edit this task, then drag it to Done', 'novawork' ),
				'notes'      => __( 'Click a card to edit it. Press play to track time on it. Now holds three tasks per person; everything else waits in Next.', 'novawork' ),
				'status'     => 'now',
				'sort_order' => $tasks->append_order( 'status', 'now' ),
				'is_sample'  => 1,
				'created_by' => $user_id,
			)
		);
		if ( $user_id > 0 ) {
			$tasks->set_assignees( $task, array( $user_id ) );
		}
		$decisions = new DecisionRepository();
		$voting    = (bool) Settings::get( 'decision_votes' );
		$decision  = $decisions->insert(
			array(
				'project_id'   => $project,
				'question'     => __( 'Which day works for our weekly check-in?', 'novawork' ),
				'context'      => __( 'A sample decision. Write down what needs deciding, let the team weigh in, then record the answer so it’s easy to find later.', 'novawork' ),
				'owner_id'     => $user_id,
				'decide_by'    => gmdate( 'Y-m-d', time() + 7 * $day ),
				'status'       => 'open',
				'voting'       => $voting ? 1 : 0,
				'vote_options' => (string) wp_json_encode(
					$voting ? array(
						array(
							'key'   => 'o1',
							'label' => __( 'Monday', 'novawork' ),
						),
						array(
							'key'   => 'o2',
							'label' => __( 'Wednesday', 'novawork' ),
						),
						array(
							'key'   => 'o3',
							'label' => __( 'Friday', 'novawork' ),
						),
					) : array()
				),
				'answer'       => '',
				'sort_order'   => $decisions->append_order(),
				'is_sample'    => 1,
				'created_by'   => $user_id,
			)
		);
		return array(
			'project'  => $project,
			'task'     => $task,
			'decision' => $decision,
		);
	}

	/**
	 * Sample items still in the workspace.
	 *
	 * @return array{projects: list<int>, tasks: list<int>, decisions: list<int>}
	 */
	public static function remaining(): array {
		global $wpdb;
		$ids = static function ( string $table ) use ( $wpdb ): array {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small and only on demand.
			return array_values( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE is_sample = 1', $table ) ) ) );
		};
		return array(
			'projects'  => $ids( ( new ProjectRepository() )->table_name() ),
			'tasks'     => $ids( ( new TaskRepository() )->table_name() ),
			'decisions' => $ids( ( new DecisionRepository() )->table_name() ),
		);
	}

	/**
	 * How many sample items are left.
	 */
	public static function count(): int {
		return (int) array_sum( array_map( 'count', self::remaining() ) );
	}

	/**
	 * Remove every sample item, as if each were deleted in the app: comments,
	 * votes and notifications about them go too; running timers on the
	 * sample task stop; tracked time stays in the reports.
	 *
	 * @return int Items removed.
	 */
	public static function remove(): int {
		$left    = self::remaining();
		$notes   = new NotificationRepository();
		$tasks   = new TaskRepository();
		$running = ( new TimeEntryRepository() )->running();
		foreach ( $left['tasks'] as $id ) {
			foreach ( $running as $entry ) {
				if ( (int) $entry['task_id'] === $id ) {
					Timer::stop( (int) $entry['user_id'] );
				}
			}
			$tasks->delete( $id );
			$notes->delete_for_object( 'task', $id );
		}
		$decisions = new DecisionRepository();
		foreach ( $left['decisions'] as $id ) {
			$decisions->delete( $id );
			$notes->delete_for_object( 'decision', $id );
		}
		$projects = new ProjectRepository();
		foreach ( $left['projects'] as $id ) {
			$tasks->unlink_project( $id );
			$decisions->unlink_project( $id );
			$projects->delete( $id );
		}
		return (int) array_sum( array_map( 'count', $left ) );
	}
}

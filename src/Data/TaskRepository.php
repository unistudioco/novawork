<?php
/**
 * Tasks and their assignees.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables.

namespace NovaWork\Data;

/**
 * Tasks live in one of four board columns, ordered within each.
 */
final class TaskRepository extends Repository {

	public const STATUSES   = array( 'now', 'next', 'later', 'done' );
	public const PRIORITIES = array( '', 'urgent', 'high', 'medium', 'low' );

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'tasks';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'task';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'project_id' => '%d',
			'title'      => '%s',
			'notes'      => '%s',
			'status'     => '%s',
			'priority'   => '%s',
			'due_date'   => '%s',
			'sort_order' => '%d',
			'done_at'    => '%s',
			'is_sample'  => '%d',
			'created_by' => '%d',
			'created_at' => '%s',
			'updated_at' => '%s',
		);
	}

	/**
	 * Defaults for a new row.
	 *
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return array(
			'notes'    => '',
			'status'   => 'next',
			'priority' => '',
		);
	}

	/**
	 * API shape, without assignees (see present_many()).
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		return $this->present_many( array( $row ) )[0];
	}

	/**
	 * API shapes with assignees loaded in one query.
	 *
	 * @param list<array<string, mixed>> $rows Database rows.
	 * @return list<array<string, mixed>>
	 */
	public function present_many( array $rows ): array {
		$ids       = array_map( static fn( $row ) => (int) $row['id'], $rows );
		$assignees = $this->assignees_of( $ids );
		$seconds   = ( new TimeEntryRepository() )->seconds_by_task( $ids );
		$subtasks  = ( new SubtaskRepository() )->for_tasks( $ids );
		$out       = array();
		foreach ( $rows as $row ) {
			$id    = (int) $row['id'];
			$out[] = array(
				'id'        => $id,
				'title'     => (string) $row['title'],
				'notes'     => (string) $row['notes'],
				'status'    => (string) $row['status'],
				'priority'  => (string) $row['priority'],
				'due'       => Db::date_out( $row['due_date'] ),
				'projectId' => (int) $row['project_id'],
				'assignees' => $assignees[ $id ] ?? array(),
				'subtasks'  => $subtasks[ $id ] ?? array(),
				'order'     => (int) $row['sort_order'],
				'doneAt'    => Db::to_ms( $row['done_at'] ),
				'trackedMs' => ( $seconds[ $id ] ?? 0 ) * 1000,
				'sample'    => (bool) $row['is_sample'],
				'createdBy' => (int) $row['created_by'],
				'createdAt' => Db::to_ms( $row['created_at'] ),
				'updatedAt' => Db::to_ms( $row['updated_at'] ),
			);
		}
		return $out;
	}

	/**
	 * Every task, in board order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		return self::rows( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id ASC', $this->table_name() ), ARRAY_A ) );
	}

	/**
	 * Open tasks with a due date on or before a day.
	 *
	 * @param string $date Y-m-d.
	 * @return list<array<string, mixed>>
	 */
	public function open_due_by( string $date ): array {
		global $wpdb;
		return self::rows(
			$wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM %i WHERE status <> 'done' AND due_date IS NOT NULL AND due_date <= %s", $this->table_name(), $date ),
				ARRAY_A
			)
		);
	}

	/**
	 * Tasks whose title contains a phrase.
	 *
	 * @param string $phrase Search phrase.
	 * @param int    $limit  Most rows.
	 * @return list<array<string, mixed>>
	 */
	public function search( string $phrase, int $limit ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $phrase ) . '%';
		return self::rows(
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE title LIKE %s ORDER BY ( status = %s ) ASC, updated_at DESC LIMIT %d', $this->table_name(), $like, 'done', $limit ),
				ARRAY_A
			)
		);
	}

	/**
	 * Assignee ids for several tasks.
	 *
	 * @param int[] $task_ids Task ids.
	 * @return array<int, list<int>>
	 */
	public function assignees_of( array $task_ids ): array {
		global $wpdb;
		$task_ids = self::ids( $task_ids );
		if ( ! $task_ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT task_id, user_id FROM %i WHERE task_id IN (' . implode( ',', array_fill( 0, count( $task_ids ), '%d' ) ) . ') ORDER BY task_id ASC, user_id ASC',
				array_merge( array( Db::table( 'task_assignees' ) ), $task_ids )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( self::rows( $rows ) as $row ) {
			$out[ (int) $row['task_id'] ][] = (int) $row['user_id'];
		}
		return $out;
	}

	/**
	 * Replace a task's assignees.
	 *
	 * @param int   $task_id  Task id.
	 * @param int[] $user_ids New assignees.
	 * @return list<int> Members who were not assigned before.
	 */
	public function set_assignees( int $task_id, array $user_ids ): array {
		global $wpdb;
		$table    = Db::table( 'task_assignees' );
		$user_ids = self::ids( $user_ids );
		$before   = $this->assignees_of( array( $task_id ) )[ $task_id ] ?? array();

		foreach ( array_diff( $before, $user_ids ) as $gone ) {
			$wpdb->delete(
				$table,
				array(
					'task_id' => $task_id,
					'user_id' => $gone,
				),
				array( '%d', '%d' )
			);
		}
		$added = array_values( array_diff( $user_ids, $before ) );
		foreach ( $added as $new ) {
			$wpdb->insert(
				$table,
				array(
					'task_id' => $task_id,
					'user_id' => $new,
				),
				array( '%d', '%d' )
			);
		}
		if ( $added || array_diff( $before, $user_ids ) ) {
			$this->touch( $task_id );
		}
		return $added;
	}

	/**
	 * Delete a task with its assignees, subtasks and comments.
	 *
	 * @param int $id Task id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Db::table( 'task_assignees' ), array( 'task_id' => $id ), array( '%d' ) );
		( new SubtaskRepository() )->delete_for_task( $id );
		( new CommentRepository() )->delete_for( 'task', $id );
		parent::delete( $id );
	}

	/**
	 * Clear a deleted project from its tasks.
	 *
	 * @param int $project_id Project id.
	 */
	public function unlink_project( int $project_id ): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE project_id = %d', $this->table_name(), $project_id ) );
		foreach ( self::ids( (array) $ids ) as $id ) {
			$this->update( $id, array( 'project_id' => 0 ) );
		}
	}

	/**
	 * Remove a member from every task and subtask they were assigned to.
	 *
	 * @param int $user_id User id.
	 */
	public function unassign_everywhere( int $user_id ): void {
		global $wpdb;
		( new SubtaskRepository() )->unassign_everywhere( $user_id );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT task_id FROM %i WHERE user_id = %d', Db::table( 'task_assignees' ), $user_id ) );
		$wpdb->delete( Db::table( 'task_assignees' ), array( 'user_id' => $user_id ), array( '%d' ) );
		foreach ( self::ids( (array) $ids ) as $id ) {
			$this->touch( $id );
		}
	}
}

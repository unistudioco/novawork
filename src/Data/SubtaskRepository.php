<?php
/**
 * Subtasks: a task's checklist.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; sync reads must never be served stale from an object cache.

namespace NovaWork\Data;

/**
 * Owner decision (2026-11-22): subtasks are a checklist inside a task, not
 * tasks of their own. Each has a title, a done mark and at most one
 * assignee, and they are ordered by dragging.
 *
 * They travel with their task (`task.subtasks` in the API), so a change is
 * logged as a change to the task, not to the subtask: every write here
 * touches the parent task and open apps redraw it on their next poll.
 */
final class SubtaskRepository extends Repository {

	/**
	 * Most subtasks on one task: a checklist, not a project plan.
	 */
	public const MAX_PER_TASK = 50;

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'subtasks';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'subtask';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'task_id'     => '%d',
			'title'       => '%s',
			'done_at'     => '%s',
			'assignee_id' => '%d',
			'sort_order'  => '%d',
			'created_by'  => '%d',
			'created_at'  => '%s',
			'updated_at'  => '%s',
		);
	}

	/**
	 * Changes are logged on the parent task instead (see the class comment).
	 */
	protected function logged(): bool {
		return false;
	}

	/**
	 * API shape.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'title'      => (string) $row['title'],
			'done'       => null !== $row['done_at'],
			'doneAt'     => Db::to_ms( $row['done_at'] ),
			'assigneeId' => (int) $row['assignee_id'],
			'order'      => (int) $row['sort_order'],
		);
	}

	/**
	 * Insert a subtask and tell open apps its task changed.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int New row id, or 0 on failure.
	 */
	public function insert( array $data ): int {
		$id = parent::insert( $data );
		if ( $id ) {
			$this->touch_task( (int) ( $data['task_id'] ?? 0 ) );
		}
		return $id;
	}

	/**
	 * Update a subtask and tell open apps its task changed.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Column values to change.
	 */
	public function update( int $id, array $data ): void {
		parent::update( $id, $data );
		$row = $this->find( $id );
		if ( $row ) {
			$this->touch_task( (int) $row['task_id'] );
		}
	}

	/**
	 * Delete a subtask and tell open apps its task changed.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): void {
		$row = $this->find( $id );
		if ( ! $row ) {
			return;
		}
		parent::delete( $id );
		$this->touch_task( (int) $row['task_id'] );
	}

	/**
	 * Subtasks of several tasks, in checklist order, as the API presents them.
	 *
	 * @param int[] $task_ids Task ids.
	 * @return array<int, list<array<string, mixed>>>
	 */
	public function for_tasks( array $task_ids ): array {
		global $wpdb;
		$task_ids = self::ids( $task_ids );
		if ( ! $task_ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE task_id IN (' . implode( ',', array_fill( 0, count( $task_ids ), '%d' ) ) . ') ORDER BY task_id ASC, sort_order ASC, id ASC',
				array_merge( array( $this->table_name() ), $task_ids )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( self::rows( $rows ) as $row ) {
			$out[ (int) $row['task_id'] ][] = $this->present( $row );
		}
		return $out;
	}

	/**
	 * How many subtasks a task has.
	 *
	 * @param int $task_id Task id.
	 */
	public function count_for( int $task_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE task_id = %d', $this->table_name(), $task_id ) );
	}

	/**
	 * Remove every subtask of a deleted task (the task's own delete logs it).
	 *
	 * @param int $task_id Task id.
	 */
	public function delete_for_task( int $task_id ): void {
		global $wpdb;
		$wpdb->delete( $this->table_name(), array( 'task_id' => $task_id ), array( '%d' ) );
	}

	/**
	 * Take a member off every subtask (they left, or asked to be erased).
	 *
	 * @param int $user_id User id.
	 */
	public function unassign_everywhere( int $user_id ): void {
		global $wpdb;
		$tasks = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT task_id FROM %i WHERE assignee_id = %d', $this->table_name(), $user_id ) );
		$wpdb->update( $this->table_name(), array( 'assignee_id' => 0 ), array( 'assignee_id' => $user_id ), array( '%d' ), array( '%d' ) );
		foreach ( self::ids( (array) $tasks ) as $task_id ) {
			$this->touch_task( $task_id );
		}
	}

	/**
	 * Log the parent task as changed.
	 *
	 * @param int $task_id Task id.
	 */
	private function touch_task( int $task_id ): void {
		if ( $task_id > 0 ) {
			( new TaskRepository() )->touch( $task_id );
		}
	}
}

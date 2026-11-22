<?php
/**
 * Subtasks API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Access\Capabilities;
use NovaWork\Data\Db;
use NovaWork\Data\SubtaskRepository;
use NovaWork\Data\TaskRepository;
use NovaWork\Services\Notifier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /tasks/{id}/subtasks, PATCH|DELETE /tasks/{id}/subtasks/{sid},
 * POST /tasks/{id}/subtasks/{sid}/move. Every answer is the whole task, as
 * /sync presents it, so the app puts it straight into its store.
 *
 * Any member may change any task's checklist, as with the task itself.
 */
final class SubtasksController extends Controller {

	private const ONE = '/tasks/(?P<id>\d+)/subtasks/(?P<sid>\d+)';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route(
			'/tasks/(?P<id>\d+)/subtasks',
			WP_REST_Server::CREATABLE,
			array( $this, 'create' ),
			array(
				'title'      => self::line( 200, true ),
				'assigneeId' => self::ref(),
			)
		);
		$this->route(
			self::ONE,
			WP_REST_Server::EDITABLE,
			array( $this, 'update' ),
			array(
				'title'      => self::line( 200 ),
				'done'       => array( 'type' => 'boolean' ),
				'assigneeId' => self::ref(),
			)
		);
		$this->route( self::ONE, WP_REST_Server::DELETABLE, array( $this, 'delete' ) );
		$this->route( self::ONE . '/move', WP_REST_Server::CREATABLE, array( $this, 'move' ), array( 'beforeId' => self::before() ) );
	}

	/**
	 * Add a subtask at the end of the checklist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$task_id = self::id( $request );
		if ( ! ( new TaskRepository() )->find( $task_id ) ) {
			return self::not_found();
		}
		$title = trim( (string) $request->get_param( 'title' ) );
		if ( '' === $title ) {
			return new WP_Error( 'novawork_required', __( 'Say what needs to happen.', 'novawork' ), array( 'status' => 400 ) );
		}
		$repo = new SubtaskRepository();
		if ( $repo->count_for( $task_id ) >= SubtaskRepository::MAX_PER_TASK ) {
			return new WP_Error(
				'novawork_too_many',
				/* translators: %d: most subtasks on one task. */
				sprintf( __( 'A task holds up to %d subtasks. Split the work into another task.', 'novawork' ), SubtaskRepository::MAX_PER_TASK ),
				array( 'status' => 400 )
			);
		}
		$assignee = $this->assignee( $request->get_param( 'assigneeId' ) );
		if ( $assignee instanceof WP_Error ) {
			return $assignee;
		}
		$id = $repo->insert(
			array(
				'task_id'     => $task_id,
				'title'       => $title,
				'assignee_id' => $assignee,
				'sort_order'  => $repo->append_order( 'task_id', (string) $task_id ),
				'created_by'  => self::me(),
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'novawork_save_failed', __( 'The subtask wasn’t saved. Try again.', 'novawork' ), array( 'status' => 500 ) );
		}
		$row = (array) $repo->find( $id );
		if ( $assignee ) {
			Notifier::subtask_assigned( $row, $assignee, self::me() );
		}

		/**
		 * Fires after a subtask is added through the API.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $subtask Subtask as the API presents it.
		 * @param int                  $task_id Its task.
		 * @param int                  $actor   Member who added it.
		 */
		do_action( 'novawork_subtask_created', $repo->present( $row ), $task_id, self::me() );

		return self::respond( $this->task( $task_id ), 201 );
	}

	/**
	 * Rename, tick or untick, or (re)assign a subtask.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo   = new SubtaskRepository();
		$before = $this->subtask( $request, $repo );
		if ( $before instanceof WP_Error ) {
			return $before;
		}
		$id   = (int) $before['id'];
		$data = array();
		if ( $request->has_param( 'title' ) ) {
			$data['title'] = trim( (string) $request->get_param( 'title' ) );
			if ( '' === $data['title'] ) {
				return new WP_Error( 'novawork_required', __( 'Say what needs to happen.', 'novawork' ), array( 'status' => 400 ) );
			}
		}
		if ( $request->has_param( 'done' ) ) {
			$done     = (bool) $request->get_param( 'done' );
			$was_done = null !== $before['done_at'];
			// Ticking twice keeps the first time it was done.
			if ( $was_done !== $done ) {
				$data['done_at'] = $done ? Db::now() : null;
			}
		}
		if ( $request->has_param( 'assigneeId' ) ) {
			$assignee = $this->assignee( $request->get_param( 'assigneeId' ) );
			if ( $assignee instanceof WP_Error ) {
				return $assignee;
			}
			$data['assignee_id'] = $assignee;
		}
		if ( $data ) {
			$repo->update( $id, $data );
		}
		$row = (array) $repo->find( $id );
		$now = (int) $row['assignee_id'];
		if ( $now && $now !== (int) $before['assignee_id'] ) {
			Notifier::subtask_assigned( $row, $now, self::me() );
		}

		/**
		 * Fires after a subtask is changed through the API.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $subtask Subtask as the API presents it.
		 * @param array<string, mixed> $before  Database row before the change.
		 * @param int                  $actor   Member who changed it.
		 */
		do_action( 'novawork_subtask_updated', $repo->present( $row ), $before, self::me() );

		return self::respond( $this->task( (int) $row['task_id'] ) );
	}

	/**
	 * Remove a subtask.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new SubtaskRepository();
		$row  = $this->subtask( $request, $repo );
		if ( $row instanceof WP_Error ) {
			return $row;
		}
		$repo->delete( (int) $row['id'] );

		/**
		 * Fires after a subtask is removed through the API.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $row   Database row that was removed.
		 * @param int                  $actor Member who removed it.
		 */
		do_action( 'novawork_subtask_deleted', $row, self::me() );

		return self::respond( $this->task( (int) $row['task_id'] ) );
	}

	/**
	 * Drag: put a subtask before another one, or at the end.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function move( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new SubtaskRepository();
		$row  = $this->subtask( $request, $repo );
		if ( $row instanceof WP_Error ) {
			return $row;
		}
		$before = $request->get_param( 'beforeId' );
		$before = null === $before ? null : (int) $before;
		if ( null !== $before ) {
			$other = $repo->find( $before );
			if ( ! $other || (int) $other['task_id'] !== (int) $row['task_id'] ) {
				$before = null;
			}
		}
		$repo->place( (int) $row['id'], $before, 'task_id', (string) $row['task_id'] );
		return self::respond( $this->task( (int) $row['task_id'] ) );
	}

	/**
	 * The subtask a route names, checked against its task.
	 *
	 * @param WP_REST_Request   $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param SubtaskRepository $repo    Repository.
	 * @return array<string, mixed>|WP_Error
	 */
	private function subtask( WP_REST_Request $request, SubtaskRepository $repo ): array|WP_Error {
		$row     = $repo->find( (int) $request['sid'] );
		$task_id = self::id( $request );
		if ( ! $row || (int) $row['task_id'] !== $task_id ) {
			return self::not_found();
		}
		return $row;
	}

	/**
	 * A subtask's assignee: 0 for nobody, otherwise a member.
	 *
	 * @param mixed $value Sent id.
	 * @return int|WP_Error
	 */
	private function assignee( mixed $value ): int|WP_Error {
		$id = (int) $value;
		if ( $id > 0 && ! Capabilities::is_member( $id ) ) {
			return new WP_Error( 'novawork_not_member', __( 'Only members of the workspace can be assigned.', 'novawork' ), array( 'status' => 400 ) );
		}
		return max( 0, $id );
	}

	/**
	 * The whole task as the API presents it.
	 *
	 * @param int $task_id Task id.
	 * @return array<string, mixed>
	 */
	private function task( int $task_id ): array {
		$repo = new TaskRepository();
		return $repo->present( (array) $repo->find( $task_id ) );
	}
}

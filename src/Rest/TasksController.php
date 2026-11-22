<?php
/**
 * Tasks API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Data\Db;
use NovaWork\Data\ProjectRepository;
use NovaWork\Data\TaskRepository;
use NovaWork\Data\TimeEntryRepository;
use NovaWork\Services\Notifier;
use NovaWork\Services\Timer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /tasks, PATCH|DELETE /tasks/{id}, POST /tasks/{id}/move.
 * Reads come through /sync.
 */
final class TasksController extends Controller {

	private const FIELDS = array( 'title', 'notes', 'status', 'priority', 'due', 'projectId', 'assignees' );

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$fields          = array(
			'title'     => self::line( 200 ),
			'notes'     => self::text( 4000 ),
			'status'    => self::choice( TaskRepository::STATUSES ),
			'priority'  => self::choice( TaskRepository::PRIORITIES ),
			'due'       => self::date(),
			'projectId' => self::ref(),
			'assignees' => self::refs(),
		);
		$create          = $fields;
		$create['title'] = self::line( 200, true );

		$this->route( '/tasks', WP_REST_Server::CREATABLE, array( $this, 'create' ), $create );
		$this->route( '/tasks/(?P<id>\d+)', WP_REST_Server::EDITABLE, array( $this, 'update' ), $fields );
		$this->route( '/tasks/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( $this, 'delete' ) );
		$this->route(
			'/tasks/(?P<id>\d+)/move',
			WP_REST_Server::CREATABLE,
			array( $this, 'move' ),
			array(
				'status'   => self::choice( TaskRepository::STATUSES ),
				'beforeId' => self::before(),
			)
		);
	}

	/**
	 * Create a task at the end of its column.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sent = self::sent( $request, self::FIELDS );
		$data = $this->columns( $sent );
		if ( $data instanceof WP_Error ) {
			return $data;
		}
		if ( '' === ( $data['title'] ?? '' ) ) {
			return new WP_Error( 'novawork_required', __( 'Say what needs to happen.', 'novawork' ), array( 'status' => 400 ) );
		}
		$repo   = new TaskRepository();
		$status = (string) ( $data['status'] ?? 'next' );
		$id     = $repo->insert(
			array_merge(
				$data,
				array(
					'status'     => $status,
					'sort_order' => $repo->append_order( 'status', $status ),
					'done_at'    => 'done' === $status ? Db::now() : null,
					'created_by' => self::me(),
				)
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'novawork_save_failed', __( 'The task wasn’t saved. Try again.', 'novawork' ), array( 'status' => 500 ) );
		}
		$added = $repo->set_assignees( $id, self::members_only( $sent['assignees'] ?? array( self::me() ) ) );
		Notifier::assigned( $id, $added, self::me() );
		Notifier::check_task( $id );

		$task = $repo->present( (array) $repo->find( $id ) );

		/**
		 * Fires after a task is created through the API.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $task  Task as the API presents it.
		 * @param int                  $actor Member who created it.
		 */
		do_action( 'novawork_task_created', $task, self::me() );

		return self::respond( $task, 201 );
	}

	/**
	 * Change a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo   = new TaskRepository();
		$id     = self::id( $request );
		$before = $repo->find( $id );
		if ( ! $before ) {
			return self::not_found();
		}
		$sent = self::sent( $request, self::FIELDS );
		$data = $this->columns( $sent );
		if ( $data instanceof WP_Error ) {
			return $data;
		}
		if ( isset( $data['title'] ) && '' === $data['title'] ) {
			return new WP_Error( 'novawork_required', __( 'Say what needs to happen.', 'novawork' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['status'] ) && $data['status'] !== $before['status'] ) {
			$data = array_merge( $data, $this->status_change( $id, (string) $data['status'], $repo ) );
		}
		$repo->update( $id, $data );

		if ( array_key_exists( 'assignees', $sent ) ) {
			$added = $repo->set_assignees( $id, self::members_only( $sent['assignees'] ) );
			Notifier::assigned( $id, $added, self::me() );
		}
		Notifier::check_task( $id );

		$task = $repo->present( (array) $repo->find( $id ) );

		/**
		 * Fires after a task is changed through the API.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $task   Task as the API presents it.
		 * @param array<string, mixed> $before Database row before the change.
		 * @param int                  $actor  Member who changed it.
		 */
		do_action( 'novawork_task_updated', $task, $before, self::me() );

		return self::respond( $task );
	}

	/**
	 * Delete a task, its comments and the notifications about it (they would
	 * lead nowhere). Tracked time stays in the reports; the team feed keeps
	 * its rows, which carry the title.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new TaskRepository();
		$id   = self::id( $request );
		if ( ! $repo->find( $id ) ) {
			return self::not_found();
		}
		$this->stop_my_timer_on( $id );
		$repo->delete( $id );
		( new \NovaWork\Data\NotificationRepository() )->delete_for_object( 'task', $id );
		return self::respond( array( 'deleted' => $id ) );
	}

	/**
	 * Drag and drop: move a task to a column (optional) and a position.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function move( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new TaskRepository();
		$id   = self::id( $request );
		$task = $repo->find( $id );
		if ( ! $task ) {
			return self::not_found();
		}
		$status = (string) ( $request->get_param( 'status' ) ?? $task['status'] );
		if ( $status !== $task['status'] ) {
			$repo->update( $id, array_merge( array( 'status' => $status ), $this->status_change( $id, $status, $repo ) ) );
		}
		$before = $request->get_param( 'beforeId' );
		$repo->place( $id, null === $before ? null : (int) $before, 'status', $status );
		return self::respond( $repo->present( (array) $repo->find( $id ) ) );
	}

	/**
	 * What else changes when a task changes column: it goes to the end of the
	 * new column, done_at is set or cleared, and finishing a task stops your
	 * own timer on it.
	 *
	 * @param int            $id     Task.
	 * @param string         $status New status.
	 * @param TaskRepository $repo   Repository.
	 * @return array<string, mixed>
	 */
	private function status_change( int $id, string $status, TaskRepository $repo ): array {
		if ( 'done' === $status ) {
			$this->stop_my_timer_on( $id );
		}
		return array(
			'sort_order' => $repo->append_order( 'status', $status ),
			'done_at'    => 'done' === $status ? Db::now() : null,
		);
	}

	/**
	 * Stop the current member's timer if it runs on this task.
	 *
	 * @param int $task_id Task.
	 */
	private function stop_my_timer_on( int $task_id ): void {
		$running = ( new TimeEntryRepository() )->running_for( self::me() );
		if ( $running && (int) $running['task_id'] === $task_id ) {
			Timer::stop( self::me() );
		}
	}

	/**
	 * API fields to columns.
	 *
	 * @param array<string, mixed> $sent Sent fields.
	 * @return array<string, mixed>|WP_Error
	 */
	private function columns( array $sent ): array|WP_Error {
		$out = array();
		foreach ( $sent as $key => $value ) {
			switch ( $key ) {
				case 'title':
				case 'notes':
				case 'status':
				case 'priority':
					$out[ $key ] = trim( (string) $value );
					break;
				case 'due':
					$out['due_date'] = Db::date_in( $value );
					break;
				case 'projectId':
					$project = (int) $value;
					if ( $project > 0 && ! ( new ProjectRepository() )->find( $project ) ) {
						return new WP_Error( 'novawork_bad_project', __( 'That project no longer exists.', 'novawork' ), array( 'status' => 400 ) );
					}
					$out['project_id'] = $project;
					break;
			}
		}
		return $out;
	}
}

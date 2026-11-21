<?php
/**
 * Projects API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Data\Db;
use NovaWork\Data\DecisionRepository;
use NovaWork\Data\ProjectRepository;
use NovaWork\Data\TaskRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /projects, PATCH|DELETE /projects/{id}, POST /projects/{id}/move.
 * Reads come through /sync.
 */
final class ProjectsController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$fields         = array(
			'name'     => self::line( 80 ),
			'goal'     => self::text( 600 ),
			'color'    => self::color(),
			'status'   => self::choice( ProjectRepository::STATUSES ),
			'start'    => self::date(),
			'target'   => self::date(),
			'deadline' => self::date(),
		);
		$create         = $fields;
		$create['name'] = self::line( 80, true );

		$this->route( '/projects', WP_REST_Server::CREATABLE, array( $this, 'create' ), $create );
		$this->route( '/projects/(?P<id>\d+)', WP_REST_Server::EDITABLE, array( $this, 'update' ), $fields );
		$this->route( '/projects/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( $this, 'delete' ) );
		$this->route( '/projects/(?P<id>\d+)/move', WP_REST_Server::CREATABLE, array( $this, 'move' ), array( 'beforeId' => self::before() ) );
	}

	/**
	 * Create a project at the end of the list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = $this->columns( self::sent( $request, array( 'name', 'goal', 'color', 'status', 'start', 'target', 'deadline' ) ) );
		if ( '' === ( $data['name'] ?? '' ) ) {
			return new WP_Error( 'novawork_required', __( 'Give the project a name.', 'novawork' ), array( 'status' => 400 ) );
		}
		$repo = new ProjectRepository();
		$id   = $repo->insert(
			array_merge(
				array(
					'color'      => 'blue',
					'start_date' => wp_date( 'Y-m-d' ),
				),
				$data,
				array(
					'sort_order' => $repo->append_order(),
					'created_by' => self::me(),
				)
			)
		);
		return self::respond( $repo->present( (array) $repo->find( $id ) ), 201 );
	}

	/**
	 * Change a project.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new ProjectRepository();
		$id   = self::id( $request );
		if ( ! $repo->find( $id ) ) {
			return self::not_found();
		}
		$data = $this->columns( self::sent( $request, array( 'name', 'goal', 'color', 'status', 'start', 'target', 'deadline' ) ) );
		if ( isset( $data['name'] ) && '' === $data['name'] ) {
			return new WP_Error( 'novawork_required', __( 'Give the project a name.', 'novawork' ), array( 'status' => 400 ) );
		}
		$repo->update( $id, $data );
		return self::respond( $repo->present( (array) $repo->find( $id ) ) );
	}

	/**
	 * Delete a project. Its tasks and decisions stay, without a project.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new ProjectRepository();
		$id   = self::id( $request );
		if ( ! $repo->find( $id ) ) {
			return self::not_found();
		}
		( new TaskRepository() )->unlink_project( $id );
		( new DecisionRepository() )->unlink_project( $id );
		$repo->delete( $id );
		return self::respond( array( 'deleted' => $id ) );
	}

	/**
	 * Move a project before another, or to the end.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function move( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new ProjectRepository();
		$id   = self::id( $request );
		if ( ! $repo->find( $id ) ) {
			return self::not_found();
		}
		$before = $request->get_param( 'beforeId' );
		$repo->place( $id, null === $before ? null : (int) $before );
		return self::respond( $repo->present( (array) $repo->find( $id ) ) );
	}

	/**
	 * API fields to columns.
	 *
	 * @param array<string, mixed> $sent Sent fields.
	 * @return array<string, mixed>
	 */
	private function columns( array $sent ): array {
		$map = array(
			'name'     => 'name',
			'goal'     => 'goal',
			'color'    => 'color',
			'status'   => 'status',
			'start'    => 'start_date',
			'target'   => 'target_date',
			'deadline' => 'deadline',
		);
		$out = array();
		foreach ( $sent as $key => $value ) {
			$column         = $map[ $key ];
			$out[ $column ] = in_array( $key, array( 'start', 'target', 'deadline' ), true ) ? Db::date_in( $value ) : trim( (string) $value );
		}
		return $out;
	}
}

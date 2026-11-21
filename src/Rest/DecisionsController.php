<?php
/**
 * Decisions API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Access\Capabilities;
use NovaWork\Data\Db;
use NovaWork\Data\DecisionRepository;
use NovaWork\Data\ProjectRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /decisions, PATCH|DELETE /decisions/{id}, POST /decisions/{id}/decide,
 * POST /decisions/{id}/move. Reads come through /sync.
 */
final class DecisionsController extends Controller {

	private const FIELDS = array( 'question', 'context', 'projectId', 'ownerId', 'decideBy', 'answer', 'status' );

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$fields             = array(
			'question'  => self::line( 240 ),
			'context'   => self::text( 2000 ),
			'projectId' => self::ref(),
			'ownerId'   => self::ref(),
			'decideBy'  => self::date(),
			'answer'    => self::text( 2000 ),
			'status'    => self::choice( DecisionRepository::STATUSES ),
		);
		$create             = $fields;
		$create['question'] = self::line( 240, true );
		unset( $create['answer'], $create['status'] );

		$this->route( '/decisions', WP_REST_Server::CREATABLE, array( $this, 'create' ), $create );
		$this->route( '/decisions/(?P<id>\d+)', WP_REST_Server::EDITABLE, array( $this, 'update' ), $fields );
		$this->route( '/decisions/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( $this, 'delete' ) );
		$this->route( '/decisions/(?P<id>\d+)/decide', WP_REST_Server::CREATABLE, array( $this, 'decide' ), array( 'answer' => self::text( 2000, true ) ) );
		$this->route( '/decisions/(?P<id>\d+)/move', WP_REST_Server::CREATABLE, array( $this, 'move' ), array( 'beforeId' => self::before() ) );
	}

	/**
	 * Record an open question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = $this->columns( self::sent( $request, array( 'question', 'context', 'projectId', 'ownerId', 'decideBy' ) ) );
		if ( $data instanceof WP_Error ) {
			return $data;
		}
		if ( '' === ( $data['question'] ?? '' ) ) {
			return new WP_Error( 'novawork_required', __( 'Say what needs deciding.', 'novawork' ), array( 'status' => 400 ) );
		}
		$repo     = new DecisionRepository();
		$id       = $repo->insert(
			array_merge(
				$data,
				array(
					'status'     => 'open',
					'sort_order' => $repo->append_order(),
					'created_by' => self::me(),
				)
			)
		);
		$decision = $repo->present( (array) $repo->find( $id ) );

		/**
		 * Fires after a decision is opened through the API.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $decision Decision as the API presents it.
		 * @param int                  $actor    Member who opened it.
		 */
		do_action( 'novawork_decision_created', $decision, self::me() );

		return self::respond( $decision, 201 );
	}

	/**
	 * Change a decision; status "open" reopens a decided one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo   = new DecisionRepository();
		$id     = self::id( $request );
		$before = $repo->find( $id );
		if ( ! $before ) {
			return self::not_found();
		}
		$data = $this->columns( self::sent( $request, self::FIELDS ) );
		if ( $data instanceof WP_Error ) {
			return $data;
		}
		if ( isset( $data['question'] ) && '' === $data['question'] ) {
			return new WP_Error( 'novawork_required', __( 'Say what needs deciding.', 'novawork' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['status'] ) && $data['status'] !== $before['status'] ) {
			if ( 'decided' === $data['status'] ) {
				$answer = (string) ( $data['answer'] ?? $before['answer'] );
				if ( '' === trim( $answer ) ) {
					return new WP_Error( 'novawork_required', __( 'Write down what was decided.', 'novawork' ), array( 'status' => 400 ) );
				}
				$data['decided_at'] = Db::now();
				$data['decided_by'] = self::me();
			} else {
				$data['decided_at'] = null;
				$data['decided_by'] = 0;
			}
		}
		$repo->update( $id, $data );
		return self::respond( $repo->present( (array) $repo->find( $id ) ) );
	}

	/**
	 * Record the answer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function decide( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new DecisionRepository();
		$id   = self::id( $request );
		if ( ! $repo->find( $id ) ) {
			return self::not_found();
		}
		$answer = trim( (string) $request->get_param( 'answer' ) );
		if ( '' === $answer ) {
			return new WP_Error( 'novawork_required', __( 'Write down what was decided.', 'novawork' ), array( 'status' => 400 ) );
		}
		$repo->update(
			$id,
			array(
				'answer'     => $answer,
				'status'     => 'decided',
				'decided_at' => Db::now(),
				'decided_by' => self::me(),
			)
		);
		$decision = $repo->present( (array) $repo->find( $id ) );

		/**
		 * Fires after a decision is recorded.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $decision Decision as the API presents it.
		 * @param int                  $actor    Member who decided.
		 */
		do_action( 'novawork_decision_decided', $decision, self::me() );

		return self::respond( $decision );
	}

	/**
	 * Delete a decision and its comments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new DecisionRepository();
		$id   = self::id( $request );
		if ( ! $repo->find( $id ) ) {
			return self::not_found();
		}
		$repo->delete( $id );
		return self::respond( array( 'deleted' => $id ) );
	}

	/**
	 * Move a decision before another, or to the end.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function move( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new DecisionRepository();
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
	 * @return array<string, mixed>|WP_Error
	 */
	private function columns( array $sent ): array|WP_Error {
		$out = array();
		foreach ( $sent as $key => $value ) {
			switch ( $key ) {
				case 'question':
				case 'context':
				case 'answer':
				case 'status':
					$out[ $key ] = trim( (string) $value );
					break;
				case 'decideBy':
					$out['decide_by'] = Db::date_in( $value );
					break;
				case 'projectId':
					$project = (int) $value;
					if ( $project > 0 && ! ( new ProjectRepository() )->find( $project ) ) {
						return new WP_Error( 'novawork_bad_project', __( 'That project no longer exists.', 'novawork' ), array( 'status' => 400 ) );
					}
					$out['project_id'] = $project;
					break;
				case 'ownerId':
					$owner = (int) $value;
					if ( $owner > 0 && ! Capabilities::is_member( $owner ) ) {
						return new WP_Error( 'novawork_bad_member', __( 'That person isn’t a member.', 'novawork' ), array( 'status' => 400 ) );
					}
					$out['owner_id'] = $owner;
					break;
			}
		}
		return $out;
	}
}

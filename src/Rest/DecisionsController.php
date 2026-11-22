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
use NovaWork\Services\Notifier;
use NovaWork\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /decisions, PATCH|DELETE /decisions/{id}, POST /decisions/{id}/decide,
 * POST /decisions/{id}/move. Reads come through /sync.
 */
final class DecisionsController extends Controller {

	private const FIELDS = array( 'question', 'context', 'projectId', 'ownerId', 'decideBy', 'answer', 'status', 'voting', 'options' );

	/**
	 * Most options a vote can have.
	 */
	public const MAX_OPTIONS = 8;

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
			'voting'    => array( 'type' => 'boolean' ),
			'options'   => array(
				'type'     => 'array',
				'maxItems' => self::MAX_OPTIONS,
				'items'    => array(
					'type'       => 'object',
					'properties' => array(
						'key'   => array(
							'type'      => 'string',
							'maxLength' => 20,
						),
						'label' => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
					),
				),
			),
		);
		$create             = $fields;
		$create['question'] = self::line( 240, true );
		unset( $create['answer'], $create['status'] );

		$this->route( '/decisions', WP_REST_Server::CREATABLE, array( $this, 'create' ), $create );
		$this->route( '/decisions/(?P<id>\d+)', WP_REST_Server::EDITABLE, array( $this, 'update' ), $fields );
		$this->route( '/decisions/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( $this, 'delete' ) );
		$this->route( '/decisions/(?P<id>\d+)/decide', WP_REST_Server::CREATABLE, array( $this, 'decide' ), array( 'answer' => self::text( 2000, true ) ) );
		$this->route( '/decisions/(?P<id>\d+)/move', WP_REST_Server::CREATABLE, array( $this, 'move' ), array( 'beforeId' => self::before() ) );
		$this->route(
			'/decisions/(?P<id>\d+)/vote',
			WP_REST_Server::CREATABLE,
			array( $this, 'vote' ),
			array(
				'option' => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 20,
				),
			)
		);
	}

	/**
	 * Record an open question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = $this->columns( self::sent( $request, array( 'question', 'context', 'projectId', 'ownerId', 'decideBy', 'voting', 'options' ) ), null );
		if ( $data instanceof WP_Error ) {
			return $data;
		}
		if ( '' === ( $data['question'] ?? '' ) ) {
			return new WP_Error( 'novawork_required', __( 'Say what needs deciding.', 'novawork' ), array( 'status' => 400 ) );
		}
		$repo = new DecisionRepository();
		$id   = $repo->insert(
			array_merge(
				$data,
				array(
					'status'     => 'open',
					'sort_order' => $repo->append_order(),
					'created_by' => self::me(),
				)
			)
		);
		$row  = (array) $repo->find( $id );
		if ( ! empty( $row['voting'] ) ) {
			Notifier::vote_requested( $row, self::me() );
		}
		$decision = $repo->present( $row );

		/**
		 * Fires after a decision is opened through the API.
		 *
		 * @since 1.0.0
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
		$data = $this->columns( self::sent( $request, self::FIELDS ), $before );
		if ( $data instanceof WP_Error ) {
			return $data;
		}
		if ( isset( $data['question'] ) && '' === $data['question'] ) {
			return new WP_Error( 'novawork_required', __( 'Say what needs deciding.', 'novawork' ), array( 'status' => 400 ) );
		}
		$changes_outcome = ( isset( $data['status'] ) && $data['status'] !== $before['status'] )
			|| ( 'decided' === $before['status'] && isset( $data['answer'] ) && $data['answer'] !== $before['answer'] );
		if ( $changes_outcome && ! Capabilities::can_decide( $before, self::me() ) ) {
			return self::not_yours( $before );
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
		$keys = $data['__keys'] ?? null;
		unset( $data['__keys'] );
		$repo->update( $id, $data );
		if ( is_array( $keys ) ) {
			$repo->keep_votes( $id, $keys );
		}
		$row = (array) $repo->find( $id );
		if ( ! empty( $row['voting'] ) && empty( $before['voting'] ) ) {
			Notifier::vote_requested( $row, self::me() );
		}
		$decision = $repo->present( $row );

		/**
		 * Fires after a member changes a decision (including deciding or
		 * reopening it from the editor).
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $decision Decision as the API presents it.
		 * @param array<string, mixed> $before   Database row before the change.
		 * @param int                  $actor    Member who changed it.
		 */
		do_action( 'novawork_decision_updated', $decision, $before, self::me() );

		return self::respond( $decision );
	}

	/**
	 * Cast, change or withdraw your vote (option null).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function vote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new DecisionRepository();
		$id   = self::id( $request );
		$row  = $repo->find( $id );
		if ( ! $row ) {
			return self::not_found();
		}
		if ( ! Settings::get( 'decision_votes' ) || empty( $row['voting'] ) ) {
			return new WP_Error( 'novawork_no_vote', __( 'This decision isn’t open for votes.', 'novawork' ), array( 'status' => 400 ) );
		}
		if ( 'open' !== $row['status'] ) {
			return new WP_Error( 'novawork_no_vote', __( 'This decision has been made; voting is closed.', 'novawork' ), array( 'status' => 400 ) );
		}
		$key = $request->get_param( 'option' );
		if ( null !== $key && ! in_array( (string) $key, array_column( DecisionRepository::options( $row ), 'key' ), true ) ) {
			return new WP_Error( 'novawork_bad_option', __( 'That option no longer exists.', 'novawork' ), array( 'status' => 400 ) );
		}
		$repo->vote( $id, self::me(), null === $key ? null : (string) $key );
		return self::respond( $repo->present( (array) $repo->find( $id ) ) );
	}

	/**
	 * 403 for a member who may not decide this one.
	 *
	 * @param array<string, mixed> $decision Decision row.
	 */
	private static function not_yours( array $decision ): WP_Error {
		$owner = get_userdata( (int) $decision['owner_id'] );
		return new WP_Error(
			'novawork_cannot_decide',
			$owner
				/* translators: %s: name of the person who decides. */
				? sprintf( __( 'Only %s can record this decision.', 'novawork' ), $owner->display_name )
				: __( 'You can’t record this decision.', 'novawork' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Record the answer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function decide( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo   = new DecisionRepository();
		$id     = self::id( $request );
		$before = $repo->find( $id );
		if ( ! $before ) {
			return self::not_found();
		}
		if ( ! Capabilities::can_decide( $before, self::me() ) ) {
			return self::not_yours( $before );
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
		 * @since 1.0.0
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
		// Notifications about it would lead nowhere.
		( new \NovaWork\Data\NotificationRepository() )->delete_for_object( 'decision', $id );
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
	 * Clean vote options, keeping each label's key from before so votes
	 * survive an edit; new labels get new keys.
	 *
	 * @param array<int, mixed>         $sent   Options as sent.
	 * @param array<string, mixed>|null $before Current row when editing.
	 * @return array{0: list<array{key: string, label: string}>, 1: list<string>}
	 */
	private function options( array $sent, ?array $before ): array {
		$old  = $before ? DecisionRepository::options( $before ) : array();
		$byid = array_column( $old, 'label', 'key' );
		$next = 1;
		foreach ( array_keys( $byid ) as $k ) {
			$next = max( $next, (int) substr( (string) $k, 1 ) + 1 );
		}
		$out  = array();
		$seen = array();
		foreach ( $sent as $o ) {
			$label = sanitize_text_field( (string) ( is_array( $o ) ? ( $o['label'] ?? '' ) : $o ) );
			if ( '' === $label || in_array( strtolower( $label ), $seen, true ) ) {
				continue;
			}
			$seen[] = strtolower( $label );
			$key    = is_array( $o ) && isset( $o['key'], $byid[ (string) $o['key'] ] ) ? (string) $o['key'] : 'o' . ( $next++ );
			$out[]  = array(
				'key'   => $key,
				'label' => $label,
			);
			if ( count( $out ) >= self::MAX_OPTIONS ) {
				break;
			}
		}
		return array( $out, array_column( $out, 'key' ) );
	}

	/**
	 * API fields to columns.
	 *
	 * @param array<string, mixed>      $sent   Sent fields.
	 * @param array<string, mixed>|null $before Current row when editing.
	 * @return array<string, mixed>|WP_Error
	 */
	private function columns( array $sent, ?array $before ): array|WP_Error {
		$out = array();
		foreach ( $sent as $key => $value ) {
			switch ( $key ) {
				case 'voting':
					$out['voting'] = Settings::get( 'decision_votes' ) && $value ? 1 : 0;
					break;
				case 'options':
					list( $options, $keys ) = $this->options( (array) $value, $before );
					$out['vote_options']    = (string) wp_json_encode( $options );
					$out['__keys']          = $keys;
					break;
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

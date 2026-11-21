<?php
/**
 * Comments API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Data\CommentRepository;
use NovaWork\Data\DecisionRepository;
use NovaWork\Data\TaskRepository;
use NovaWork\Services\Notifier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /comments on a task or a decision. Reads come through /sync.
 */
final class CommentsController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route(
			'/comments',
			WP_REST_Server::CREATABLE,
			array( $this, 'create' ),
			array(
				'objectType' => array_merge( self::choice( CommentRepository::OBJECTS ), array( 'required' => true ) ),
				'objectId'   => array_merge( self::ref( true ), array( 'minimum' => 1 ) ),
				'text'       => self::text( 2000, true ),
			)
		);
	}

	/**
	 * Add a comment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = (string) $request->get_param( 'objectType' );
		$id   = (int) $request->get_param( 'objectId' );
		$text = trim( (string) $request->get_param( 'text' ) );
		if ( '' === $text ) {
			return new WP_Error( 'novawork_required', __( 'Write a comment first.', 'novawork' ), array( 'status' => 400 ) );
		}
		$exists = 'task' === $type ? ( new TaskRepository() )->find( $id ) : ( new DecisionRepository() )->find( $id );
		if ( ! $exists ) {
			return self::not_found();
		}

		$repo    = new CommentRepository();
		$comment = (array) $repo->find(
			$repo->insert(
				array(
					'object_type' => $type,
					'object_id'   => $id,
					'user_id'     => self::me(),
					'body'        => $text,
				)
			)
		);
		Notifier::commented( $comment );

		/**
		 * Fires after a member comments on a task or decision.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $comment Comment row.
		 */
		do_action( 'novawork_comment_created', $comment );

		return self::respond( $repo->present( $comment ), 201 );
	}
}

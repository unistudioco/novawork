<?php
/**
 * Comments API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Access\Capabilities;
use NovaWork\Data\CommentRepository;
use NovaWork\Data\DecisionRepository;
use NovaWork\Data\TaskRepository;
use NovaWork\Services\Notifier;
use NovaWork\Services\References;
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
				'mentions'   => self::refs( 20 ),
				'refs'       => self::links_schema(),
				'replyTo'    => self::ref(),
			)
		);
		$this->route(
			'/comments/(?P<id>\d+)',
			WP_REST_Server::EDITABLE,
			array( $this, 'edit' ),
			array(
				'text'     => self::text( 2000, true ),
				'mentions' => self::refs( 20 ),
				'refs'     => self::links_schema(),
			)
		);
		$this->route( '/comments/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( $this, 'remove' ) );
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

		$repo     = new CommentRepository();
		$reply_to = (int) $request->get_param( 'replyTo' );
		$replied  = $reply_to > 0 ? $repo->find( $reply_to ) : null;
		$comment  = (array) $repo->find(
			$repo->insert(
				array(
					// A reply only to a comment on the same task or decision.
					'reply_to'    => $replied && $replied['object_type'] === $type && (int) $replied['object_id'] === $id ? $reply_to : 0,
					'object_type' => $type,
					'object_id'   => $id,
					'user_id'     => self::me(),
					'body'        => $text,
					'mentions'    => (string) wp_json_encode( References::mentions( $text, $request->get_param( 'mentions' ) ?? array() ) ),
					'refs'        => (string) wp_json_encode( References::links( $text, $request->get_param( 'refs' ) ?? array() ) ),
				)
			)
		);
		$told     = Notifier::commented( $comment );
		References::notify_links( References::decode( $comment['refs'] ), self::me(), $text, $told );

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

	/**
	 * Edit your own comment (the message_edits setting decides whether and
	 * for how long).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function edit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new CommentRepository();
		$row  = $repo->find( self::id( $request ) );
		if ( ! $row ) {
			return self::not_found();
		}
		if ( ! Capabilities::can_edit_post( $row, self::me() ) ) {
			return self::forbidden( __( 'You can’t edit this comment.', 'novawork' ) );
		}
		$text = trim( (string) $request->get_param( 'text' ) );
		if ( '' === $text ) {
			return new WP_Error( 'novawork_required', __( 'A comment needs some text. To remove it, delete it.', 'novawork' ), array( 'status' => 400 ) );
		}
		$after = References::save_edit( $repo, $row, $text, $request->get_param( 'mentions' ), $request->get_param( 'refs' ) );

		/**
		 * Fires after a member edits a comment.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $after  Comment row after the edit.
		 * @param array<string, mixed> $before Comment row before it.
		 */
		do_action( 'novawork_comment_updated', $after, $row );

		return self::respond( $repo->present( $after ) );
	}

	/**
	 * Delete a comment: your own (as for editing); administrators anyone's.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new CommentRepository();
		$row  = $repo->find( self::id( $request ) );
		if ( ! $row ) {
			return self::not_found();
		}
		if ( ! Capabilities::can_delete_post( $row, self::me() ) ) {
			return self::forbidden( __( 'You can’t delete this comment.', 'novawork' ) );
		}
		$repo->delete( (int) $row['id'] );

		/**
		 * Fires after a comment is deleted.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $row Deleted comment row.
		 */
		do_action( 'novawork_comment_deleted', $row );

		return self::respond( array( 'deleted' => true ) );
	}
}

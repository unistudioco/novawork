<?php
/**
 * Comments API.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Access\Capabilities;
use NovaWork\Api;
use NovaWork\Data\CommentRepository;
use NovaWork\Services\References;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

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
				'agents'     => self::agent_keys(),
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
				'agents'   => self::agent_keys(),
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
		$comment = Api::post_comment(
			self::me(),
			(string) $request->get_param( 'objectType' ),
			(int) $request->get_param( 'objectId' ),
			(string) $request->get_param( 'text' ),
			array(
				'mentions' => $request->get_param( 'mentions' ) ?? array(),
				'agents'   => $request->get_param( 'agents' ) ?? array(),
				'refs'     => $request->get_param( 'refs' ) ?? array(),
				'replyTo'  => (int) $request->get_param( 'replyTo' ),
			)
		);
		return $comment instanceof WP_Error ? $comment : self::respond( $comment, 201 );
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
		$after = References::save_edit( $repo, $row, $text, $request->get_param( 'mentions' ), $request->get_param( 'refs' ), $request->get_param( 'agents' ) );

		/**
		 * Fires after a member edits a comment.
		 *
		 * @since 1.0.0
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
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $row Deleted comment row.
		 */
		do_action( 'novawork_comment_deleted', $row );

		return self::respond( array( 'deleted' => true ) );
	}
}

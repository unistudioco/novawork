<?php
/**
 * Team chat API: messages, attachments and read position.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Access\Capabilities;
use NovaWork\Api;
use NovaWork\Data\AttachmentRepository;
use NovaWork\Data\MessageRepository;
use NovaWork\Data\NotificationRepository;
use NovaWork\Data\ReadState;
use NovaWork\Services\Files;
use NovaWork\Services\References;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET|POST /messages, PATCH|DELETE /messages/{id}, POST /attachments,
 * GET /attachments/{id}/file, DELETE /attachments/{id}, POST /chat/read.
 */
final class ChatController extends Controller {

	/**
	 * Most files on one message, as in the prototype.
	 */
	public const MAX_FILES = 6;

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route(
			'/messages',
			WP_REST_Server::READABLE,
			array( $this, 'history' ),
			array(
				'beforeId' => self::ref(),
				'limit'    => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				),
				'q'        => self::line( 100 ),
				'from'     => self::date(),
				'to'       => self::date(),
			)
		);
		$this->route(
			'/messages',
			WP_REST_Server::CREATABLE,
			array( $this, 'send' ),
			array(
				'text'          => self::text( 2000 ),
				'attachmentIds' => self::refs( self::MAX_FILES ),
				'mentions'      => self::refs( 20 ),
				'agents'        => self::agent_keys(),
				'refs'          => self::links_schema(),
				'replyTo'       => self::ref(),
			)
		);
		$this->route(
			'/messages/(?P<id>\d+)',
			WP_REST_Server::EDITABLE,
			array( $this, 'edit' ),
			array(
				'text'     => self::text( 2000 ),
				'mentions' => self::refs( 20 ),
				'agents'   => self::agent_keys(),
				'refs'     => self::links_schema(),
			)
		);
		$this->route( '/messages/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( $this, 'remove' ) );
		$this->route( '/attachments', WP_REST_Server::CREATABLE, array( $this, 'upload' ) );
		$this->route( '/attachments/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( $this, 'discard' ) );
		$this->route( '/attachments/(?P<id>\d+)/file', WP_REST_Server::READABLE, array( $this, 'file' ) );
		$this->route( '/chat/read', WP_REST_Server::CREATABLE, array( $this, 'read' ), array( 'lastId' => self::ref( true ) ) );
	}

	/**
	 * Older messages and chat search (text, and a date range in site time).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function history( WP_REST_Request $request ): WP_REST_Response {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );
		$repo = new MessageRepository();
		$rows = $repo->page(
			(int) $request->get_param( 'beforeId' ),
			(int) $request->get_param( 'limit' ),
			trim( (string) $request->get_param( 'q' ) ),
			'' === $from ? null : self::site_day_start_utc( $from ),
			'' === $to ? null : self::site_day_start_utc( $to, 1 )
		);
		return self::respond( $repo->present_many( $rows ) );
	}

	/**
	 * Post a message, with files uploaded beforehand.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function send( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$message = Api::post_message(
			self::me(),
			(string) $request->get_param( 'text' ),
			array(
				'attachmentIds' => (array) ( $request->get_param( 'attachmentIds' ) ?? array() ),
				'mentions'      => $request->get_param( 'mentions' ) ?? array(),
				'agents'        => $request->get_param( 'agents' ) ?? array(),
				'refs'          => $request->get_param( 'refs' ) ?? array(),
				'replyTo'       => (int) $request->get_param( 'replyTo' ),
			)
		);
		return $message instanceof WP_Error ? $message : self::respond( $message, 201 );
	}

	/**
	 * Edit your own message (the message_edits setting decides whether and
	 * for how long). Only its text changes; files stay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function edit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new MessageRepository();
		$row  = $repo->find( self::id( $request ) );
		if ( ! $row ) {
			return self::not_found();
		}
		if ( ! Capabilities::can_edit_post( $row, self::me() ) ) {
			return self::forbidden( __( 'You can’t edit this message.', 'novawork' ) );
		}
		$text = trim( (string) $request->get_param( 'text' ) );
		if ( '' === $text && ! ( new AttachmentRepository() )->for_messages( array( (int) $row['id'] ) ) ) {
			return new WP_Error( 'novawork_required', __( 'A message needs some text. To remove it, delete it.', 'novawork' ), array( 'status' => 400 ) );
		}
		$after = References::save_edit( $repo, $row, $text, $request->get_param( 'mentions' ), $request->get_param( 'refs' ), $request->get_param( 'agents' ) );

		/**
		 * Fires after a member edits a chat message.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $after  Message row after the edit.
		 * @param array<string, mixed> $before Message row before it.
		 */
		do_action( 'novawork_message_updated', $after, $row );

		return self::respond( $repo->present( $after ) );
	}

	/**
	 * Delete a message, its files and the notifications about it. Your own
	 * (as for editing); administrators may delete anyone's.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new MessageRepository();
		$row  = $repo->find( self::id( $request ) );
		if ( ! $row ) {
			return self::not_found();
		}
		if ( ! Capabilities::can_delete_post( $row, self::me() ) ) {
			return self::forbidden( __( 'You can’t delete this message.', 'novawork' ) );
		}
		$repo->delete( (int) $row['id'] );
		( new NotificationRepository() )->delete_for_object( 'message', (int) $row['id'] );

		/**
		 * Fires after a chat message is deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $row Deleted message row.
		 */
		do_action( 'novawork_message_deleted', $row );

		return self::respond( array( 'deleted' => true ) );
	}

	/**
	 * Upload one file (multipart field "file"). It stays private to the
	 * uploader until a message claims it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'novawork_required', __( 'Choose a file to upload.', 'novawork' ), array( 'status' => 400 ) );
		}
		$stored = Files::store( $files['file'] );
		if ( $stored instanceof WP_Error ) {
			return $stored;
		}
		$repo = new AttachmentRepository();
		$id   = $repo->insert( array_merge( $stored, array( 'user_id' => self::me() ) ) );
		return self::respond( $repo->present( (array) $repo->find( $id ) ), 201 );
	}

	/**
	 * Remove a file the member uploaded but has not sent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function discard( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = new AttachmentRepository();
		$row  = $repo->find( self::id( $request ) );
		if ( ! $row || self::me() !== (int) $row['user_id'] || 0 !== (int) $row['object_id'] ) {
			return self::not_found();
		}
		$repo->delete( (int) $row['id'] );
		return self::respond( array( 'deleted' => (int) $row['id'] ) );
	}

	/**
	 * Serve a file to a member. Sent files are shared with the team; an unsent
	 * upload only with its uploader.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function file( WP_REST_Request $request ): WP_Error {
		$row = ( new AttachmentRepository() )->find( self::id( $request ) );
		if ( ! $row || ( 0 === (int) $row['object_id'] && self::me() !== (int) $row['user_id'] ) ) {
			return self::not_found();
		}
		Files::send( $row );
		return self::not_found();
	}

	/**
	 * The member has read chat up to a message: clears the unread count and
	 * the chat notifications.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response {
		$last = min( (int) $request->get_param( 'lastId' ), ( new MessageRepository() )->latest_id() );
		ReadState::set( self::me(), $last );
		( new NotificationRepository() )->mark_read( self::me(), null, 'chat' );
		return self::respond( array( 'readId' => ReadState::get( self::me() ) ) );
	}

	/**
	 * Midnight of a site-timezone day, as a UTC DATETIME.
	 *
	 * @param string $date       Y-m-d in the site's timezone.
	 * @param int    $add_days   Days to add (1 gives the end of the day).
	 */
	private static function site_day_start_utc( string $date, int $add_days = 0 ): string {
		$day = new \DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );
		if ( $add_days ) {
			$day = $day->modify( '+' . $add_days . ' day' );
		}
		return $day->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}

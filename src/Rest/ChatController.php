<?php
/**
 * Team chat API: messages, attachments and read position.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\Data\AttachmentRepository;
use NovaWork\Data\MessageRepository;
use NovaWork\Data\NotificationRepository;
use NovaWork\Data\ReadState;
use NovaWork\Services\Files;
use NovaWork\Services\Notifier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET|POST /messages, POST /attachments, GET /attachments/{id}/file,
 * DELETE /attachments/{id}, POST /chat/read.
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
			)
		);
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
		$text  = trim( (string) $request->get_param( 'text' ) );
		$files = (array) ( $request->get_param( 'attachmentIds' ) ?? array() );
		if ( '' === $text && ! $files ) {
			return new WP_Error( 'novawork_required', __( 'Write a message or add a file.', 'novawork' ), array( 'status' => 400 ) );
		}

		$repo = new MessageRepository();
		$id   = $repo->insert(
			array(
				'user_id' => self::me(),
				'body'    => $text,
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'novawork_save_failed', __( 'The message wasn’t sent. Try again.', 'novawork' ), array( 'status' => 500 ) );
		}
		$claimed = $files ? ( new AttachmentRepository() )->claim( array_map( 'intval', $files ), self::me(), 'message', $id ) : 0;
		if ( '' === $text && 0 === $claimed ) {
			$repo->delete( $id );
			return new WP_Error( 'novawork_bad_files', __( 'Those files are no longer available. Add them again.', 'novawork' ), array( 'status' => 400 ) );
		}
		if ( $claimed ) {
			$repo->touch( $id );
		}

		ReadState::set( self::me(), $id );
		$message = (array) $repo->find( $id );
		Notifier::chatted( $message );

		/**
		 * Fires after a member posts in team chat.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $message Message row.
		 */
		do_action( 'novawork_message_created', $message );

		return self::respond( $repo->present( $message ), 201 );
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

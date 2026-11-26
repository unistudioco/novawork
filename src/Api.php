<?php
/**
 * The write API for add-ons.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork;

use NovaWork\Access\Capabilities;
use NovaWork\Data\AttachmentRepository;
use NovaWork\Data\CommentRepository;
use NovaWork\Data\DecisionRepository;
use NovaWork\Data\MessageRepository;
use NovaWork\Data\ReadState;
use NovaWork\Data\TaskRepository;
use NovaWork\Services\Notifier;
use NovaWork\Services\References;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Post in chat, comment and notify as a member or as an agent (a non-human
 * author an add-on registers with the novawork_agents filter).
 *
 * Each call takes the same path as the REST route that does the same thing
 * (the routes call these too): the text is cleaned, mentions and /links are
 * kept only when really in the text, the row is written through its
 * repository (so open apps hear about it on their next poll), the right
 * people are notified, and the same novawork_*_created action fires.
 *
 * An author is a member's user id (int) or a registered agent's key
 * (string). Errors come back as WP_Error with an HTTP status in their data.
 *
 * @since 1.3.0
 */
final class Api {

	/**
	 * Longest message or comment, in characters.
	 */
	public const MAX_TEXT = 2000;

	/**
	 * Post in team chat.
	 *
	 * @since 1.3.0
	 *
	 * @param int|string           $author A member's user id or an agent's key.
	 * @param string               $text   Plain text; "@Name" and "/Title" as typed.
	 * @param array<string, mixed> $args   {
	 *     Optional.
	 *
	 *     @type int   $replyTo       Message this one answers.
	 *     @type int[] $mentions      Member ids mentioned with "@Name".
	 *     @type array $agents        Agent keys mentioned with "@Name".
	 *     @type array $refs          Links: [ { type: task|decision|project, id } ].
	 *     @type int[] $attachmentIds Files uploaded beforehand (members only).
	 * }
	 * @return array<string, mixed>|WP_Error The message as the API presents it.
	 */
	public static function post_message( int|string $author, string $text, array $args = array() ): array|WP_Error {
		$who = self::author( $author );
		if ( $who instanceof WP_Error ) {
			return $who;
		}
		$text = self::clean( $text );
		if ( $text instanceof WP_Error ) {
			return $text;
		}
		// Files are claimed by the member who uploaded them.
		$files = $who['user_id'] > 0 ? array_map( 'intval', (array) ( $args['attachmentIds'] ?? array() ) ) : array();
		if ( '' === $text && ! $files ) {
			return new WP_Error( 'novawork_required', __( 'Write a message or add a file.', 'novawork' ), array( 'status' => 400 ) );
		}

		$repo     = new MessageRepository();
		$reply_to = (int) ( $args['replyTo'] ?? 0 );
		$id       = $repo->insert(
			array(
				'user_id'  => $who['user_id'],
				'agent'    => $who['agent'],
				'reply_to' => $reply_to > 0 && $repo->find( $reply_to ) ? $reply_to : 0,
				'body'     => $text,
				'mentions' => (string) wp_json_encode( References::mentions( $text, $args['mentions'] ?? array(), $args['agents'] ?? array() ) ),
				'refs'     => (string) wp_json_encode( References::links( $text, $args['refs'] ?? array() ) ),
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'novawork_save_failed', __( 'The message wasn’t sent. Try again.', 'novawork' ), array( 'status' => 500 ) );
		}
		$claimed = $files ? ( new AttachmentRepository() )->claim( $files, $who['user_id'], 'message', $id ) : 0;
		if ( '' === $text && 0 === $claimed ) {
			$repo->delete( $id );
			return new WP_Error( 'novawork_bad_files', __( 'Those files are no longer available. Add them again.', 'novawork' ), array( 'status' => 400 ) );
		}
		if ( $claimed ) {
			$repo->touch( $id );
		}

		if ( $who['user_id'] > 0 ) {
			ReadState::set( $who['user_id'], $id );
		}
		$message = (array) $repo->find( $id );
		Notifier::chatted( $message );
		References::notify_links(
			References::decode( $message['refs'] ),
			$who['user_id'],
			$text,
			array_column( References::decode( $message['mentions'] ), 'id' ),
			$who['agent']
		);

		/**
		 * Fires after a message is posted in team chat, by a member or (since
		 * 1.3.0, through NovaWork\Api) an agent.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $message Message row.
		 */
		do_action( 'novawork_message_created', $message );
		References::agents_mentioned( 'message', $message );

		return $repo->present( $message );
	}

	/**
	 * Comment on a task or a decision.
	 *
	 * @since 1.3.0
	 *
	 * @param int|string           $author      A member's user id or an agent's key.
	 * @param string               $object_type "task" or "decision".
	 * @param int                  $object_id   Its id.
	 * @param string               $text        Plain text; "@Name" and "/Title" as typed.
	 * @param array<string, mixed> $args        {
	 *     Optional.
	 *
	 *     @type int   $replyTo  A comment on the same task or decision.
	 *     @type int[] $mentions Member ids mentioned with "@Name".
	 *     @type array $agents   Agent keys mentioned with "@Name".
	 *     @type array $refs     Links: [ { type: task|decision|project, id } ].
	 * }
	 * @return array<string, mixed>|WP_Error The comment as the API presents it.
	 */
	public static function post_comment( int|string $author, string $object_type, int $object_id, string $text, array $args = array() ): array|WP_Error {
		$who = self::author( $author );
		if ( $who instanceof WP_Error ) {
			return $who;
		}
		$text = self::clean( $text );
		if ( $text instanceof WP_Error ) {
			return $text;
		}
		if ( '' === $text ) {
			return new WP_Error( 'novawork_required', __( 'Write a comment first.', 'novawork' ), array( 'status' => 400 ) );
		}
		$exists = match ( $object_type ) {
			'task'     => ( new TaskRepository() )->find( $object_id ),
			'decision' => ( new DecisionRepository() )->find( $object_id ),
			default    => null,
		};
		if ( ! $exists ) {
			return new WP_Error( 'novawork_not_found', __( 'That item no longer exists.', 'novawork' ), array( 'status' => 404 ) );
		}

		$repo     = new CommentRepository();
		$reply_to = (int) ( $args['replyTo'] ?? 0 );
		$replied  = $reply_to > 0 ? $repo->find( $reply_to ) : null;
		$id       = $repo->insert(
			array(
				// A reply only to a comment on the same task or decision.
				'reply_to'    => $replied && $replied['object_type'] === $object_type && (int) $replied['object_id'] === $object_id ? $reply_to : 0,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'user_id'     => $who['user_id'],
				'agent'       => $who['agent'],
				'body'        => $text,
				'mentions'    => (string) wp_json_encode( References::mentions( $text, $args['mentions'] ?? array(), $args['agents'] ?? array() ) ),
				'refs'        => (string) wp_json_encode( References::links( $text, $args['refs'] ?? array() ) ),
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'novawork_save_failed', __( 'The comment wasn’t saved. Try again.', 'novawork' ), array( 'status' => 500 ) );
		}
		$comment = (array) $repo->find( $id );
		$told    = Notifier::commented( $comment );
		References::notify_links( References::decode( $comment['refs'] ), $who['user_id'], $text, $told, $who['agent'] );

		/**
		 * Fires after a comment on a task or decision, by a member or (since
		 * 1.3.0, through NovaWork\Api) an agent.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $comment Comment row.
		 */
		do_action( 'novawork_comment_created', $comment );
		References::agents_mentioned( 'comment', $comment );

		return $repo->present( $comment );
	}

	/**
	 * Add a notification to a member's bell. The app words NovaWork's own
	 * kinds; an add-on's own kind is worded by the add-on's script (see the
	 * nw:ready event's addNoteKind()).
	 *
	 * @since 1.3.0
	 *
	 * @param int                  $user_id Recipient; must be a member.
	 * @param string               $kind    Kind (a key: lowercase letters, digits, - and _).
	 * @param array<string, mixed> $data    Small snapshot the wording uses (titles, excerpt…).
	 * @param int|string           $author  Who it is from: a member's id, an agent's key, or 0 for no one.
	 * @param array<string, mixed> $args    {
	 *     Optional.
	 *
	 *     @type string $objectType What it is about (task, decision, project, message, or an add-on's type).
	 *     @type int    $objectId   Its id.
	 *     @type string $dedupeKey  Notify this member only once per key.
	 * }
	 * @return int The new notification's id, or 0 when nothing was written.
	 */
	public static function notify( int $user_id, string $kind, array $data = array(), int|string $author = 0, array $args = array() ): int {
		$kind = sanitize_key( $kind );
		if ( '' === $kind ) {
			return 0;
		}
		$who = 0 === $author ? array(
			'user_id' => 0,
			'agent'   => '',
		) : self::author( $author );
		if ( $who instanceof WP_Error ) {
			return 0;
		}
		$fields = array(
			'type'        => $kind,
			'actor_id'    => $who['user_id'],
			'agent'       => $who['agent'],
			'object_type' => sanitize_key( (string) ( $args['objectType'] ?? '' ) ),
			'object_id'   => max( 0, (int) ( $args['objectId'] ?? 0 ) ),
			'data'        => $data,
		);
		$dedupe = (string) ( $args['dedupeKey'] ?? '' );
		if ( '' !== $dedupe ) {
			$fields['dedupe_key'] = mb_substr( $dedupe, 0, 190 );
		}
		return Notifier::send( $user_id, $fields );
	}

	/**
	 * Resolve an author: a member's id, or a registered agent's key.
	 *
	 * @since 1.3.0
	 *
	 * @param int|string $author Author.
	 * @return array{user_id: int, agent: string}|WP_Error
	 */
	public static function author( int|string $author ): array|WP_Error {
		if ( is_int( $author ) ) {
			if ( Capabilities::is_member( $author ) ) {
				return array(
					'user_id' => $author,
					'agent'   => '',
				);
			}
		} elseif ( in_array( $author, array_column( Members::agents(), 'key' ), true ) ) {
			return array(
				'user_id' => 0,
				'agent'   => $author,
			);
		}
		return new WP_Error( 'novawork_bad_author', __( 'Only a member or a registered agent can write in NovaWork.', 'novawork' ), array( 'status' => 400 ) );
	}

	/**
	 * Plain text, trimmed, within the length limit.
	 *
	 * @param string $text Text.
	 */
	private static function clean( string $text ): string|WP_Error {
		$text = trim( sanitize_textarea_field( $text ) );
		if ( mb_strlen( $text ) > self::MAX_TEXT ) {
			return new WP_Error(
				'novawork_too_long',
				/* translators: %d: most characters allowed. */
				sprintf( __( 'Keep it under %d characters.', 'novawork' ), self::MAX_TEXT ),
				array( 'status' => 400 )
			);
		}
		return $text;
	}
}
